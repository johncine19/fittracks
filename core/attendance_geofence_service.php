<?php
declare(strict_types=1);

require_once __DIR__ . '/redis_client.php';

/**
 * Attendance Geofence Exit Grace Period Service
 * 
 * Manages the temporary exit buffer (default 20 minutes) when a checked-in
 * member leaves the gym's perimeter (e.g. stepping outside to grab something or take a call).
 * Uses a Hybrid Redis in-memory TTL cache + MySQL database persistence model.
 */

/**
 * Start a 20-minute grace period when a checked-in member exits the gym geofence.
 */
function start_attendance_exit_grace_period(int $userId, ?int $gymId = null, int $graceMinutes = 20): array
{
    $pdo = db();
    $graceSeconds = max(60, $graceMinutes * 60);

    // Find current active attendance
    $sql = 'SELECT attendance_id, gym_id, check_in_time, exit_grace_expires_at, exit_detected_at 
            FROM attendance 
            WHERE user_id = ? AND check_out_time IS NULL 
            ORDER BY attendance_id DESC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attendance) {
        return [
            'success' => false,
            'message' => 'No active checked-in attendance session found for this user.'
        ];
    }

    $attendanceId = (int)$attendance['attendance_id'];
    $actualGymId = (int)($attendance['gym_id'] ?: ($gymId ?? 0));

    // If already in grace period, return existing countdown
    if (!empty($attendance['exit_grace_expires_at'])) {
        $expiresTs = strtotime((string)$attendance['exit_grace_expires_at']);
        $remaining = max(0, $expiresTs - time());
        if ($remaining > 0) {
            return [
                'success' => true,
                'status' => 'grace_active',
                'attendance_id' => $attendanceId,
                'gym_id' => $actualGymId,
                'exit_detected_at' => $attendance['exit_detected_at'],
                'expires_at' => $attendance['exit_grace_expires_at'],
                'remaining_seconds' => $remaining,
                'grace_minutes' => $graceMinutes
            ];
        }
    }

    $exitTime = date('Y-m-d H:i:s');
    $expiresAt = date('Y-m-d H:i:s', time() + $graceSeconds);

    // 1. Update MySQL database
    $upStmt = $pdo->prepare('UPDATE attendance SET exit_detected_at = ?, exit_grace_expires_at = ? WHERE attendance_id = ?');
    $upStmt->execute([$exitTime, $expiresAt, $attendanceId]);

    // 2. Set high-speed Redis TTL key if Redis is available
    $redisKey = "ft:exit_grace:{$attendanceId}";
    $redisUserKey = "ft:user_exit_grace:{$userId}";
    $cached = false;

    if (RedisClient::isConnected()) {
        $payload = json_encode([
            'attendance_id' => $attendanceId,
            'user_id' => $userId,
            'gym_id' => $actualGymId,
            'exit_detected_at' => $exitTime,
            'expires_at' => $expiresAt,
        ]);
        RedisClient::setex($redisKey, $graceSeconds, (string)$payload);
        RedisClient::setex($redisUserKey, $graceSeconds, (string)$attendanceId);
        $cached = true;
    }

    return [
        'success' => true,
        'status' => 'grace_started',
        'attendance_id' => $attendanceId,
        'gym_id' => $actualGymId,
        'exit_detected_at' => $exitTime,
        'expires_at' => $expiresAt,
        'remaining_seconds' => $graceSeconds,
        'grace_minutes' => $graceMinutes,
        'redis_cached' => $cached
    ];
}

/**
 * Cancel the grace period when a member returns to the gym within the buffer.
 */
function cancel_attendance_exit_grace_period(int $userId, ?int $gymId = null): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT attendance_id, gym_id, exit_grace_expires_at FROM attendance WHERE user_id = ? AND check_out_time IS NULL ORDER BY attendance_id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attendance) {
        return [
            'success' => false,
            'message' => 'No active attendance found to restore.'
        ];
    }

    $attendanceId = (int)$attendance['attendance_id'];

    // 1. Clear database grace markers
    $pdo->prepare('UPDATE attendance SET exit_grace_expires_at = NULL, exit_detected_at = NULL WHERE attendance_id = ?')->execute([$attendanceId]);

    // 2. Clear Redis cache keys
    if (RedisClient::isConnected()) {
        RedisClient::del("ft:exit_grace:{$attendanceId}", "ft:user_exit_grace:{$userId}");
    }

    return [
        'success' => true,
        'status' => 'grace_cancelled',
        'attendance_id' => $attendanceId,
        'message' => 'Welcome back! Your check-in session continues uninterrupted.'
    ];
}

/**
 * Get current exit grace period status for an active user.
 */
function get_attendance_exit_grace_status(int $userId): ?array
{
    // Try Redis first for sub-millisecond response
    if (RedisClient::isConnected()) {
        $attIdStr = RedisClient::get("ft:user_exit_grace:{$userId}");
        if ($attIdStr !== null) {
            $attId = (int)$attIdStr;
            $ttl = RedisClient::ttl("ft:exit_grace:{$attId}");
            $raw = RedisClient::get("ft:exit_grace:{$attId}");
            if ($raw && $ttl > 0) {
                $data = json_decode($raw, true) ?: [];
                $data['remaining_seconds'] = $ttl;
                $data['is_in_grace'] = true;
                return $data;
            }
        }
    }

    // Database lookup
    $pdo = db();
    $stmt = $pdo->prepare('SELECT attendance_id, gym_id, exit_detected_at, exit_grace_expires_at FROM attendance WHERE user_id = ? AND check_out_time IS NULL ORDER BY attendance_id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $att = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$att || empty($att['exit_grace_expires_at'])) {
        return null;
    }

    $expiresTs = strtotime((string)$att['exit_grace_expires_at']);
    $remaining = max(0, $expiresTs - time());

    return [
        'attendance_id' => (int)$att['attendance_id'],
        'gym_id' => (int)$att['gym_id'],
        'exit_detected_at' => $att['exit_detected_at'],
        'expires_at' => $att['exit_grace_expires_at'],
        'remaining_seconds' => $remaining,
        'is_in_grace' => $remaining > 0
    ];
}

/**
 * Sweep and close visits whose 20-minute grace period has passed without re-entry.
 * Sets check_out_time = exit_detected_at.
 */
function process_expired_geofence_sessions(): int
{
    $pdo = db();
    try {
        $stmt = $pdo->prepare("
            UPDATE attendance 
            SET check_out_time = COALESCE(exit_detected_at, NOW()),
                exit_grace_expires_at = NULL
            WHERE check_out_time IS NULL 
              AND exit_grace_expires_at IS NOT NULL 
              AND exit_grace_expires_at <= NOW()
        ");
        $stmt->execute();
        $closedCount = $stmt->rowCount();

        return $closedCount;
    } catch (Throwable $e) {
        error_log('process_expired_geofence_sessions error: ' . $e->getMessage());
        return 0;
    }
}
