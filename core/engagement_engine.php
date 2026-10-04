<?php
declare(strict_types=1);

/**
 * Calculates a Member Engagement Score (0-100) based on:
 * - Attendance frequency (last 30 days) - 40%
 * - Class participation (last 30 days) - 30%
 * - Consistency (days active / total days) - 20%
 * - Fitness progress updates (last 60 days) - 10%
 * - Daily Completed Workout (last 30 days) - 10%
 */
/**
 * Converts the aggregated activity metrics into the engagement score.
 * Kept separate from database access so the scoring rules can be unit tested.
 *
 * @param array{attendance?:int,classes?:int,consistency?:int,workouts?:int,progress?:int} $weights
 */
function engagement_score_from_metrics(
    int $attendanceCount,
    int $classCount,
    int $activeWeeks,
    int $workoutDays,
    bool $hasProgress,
    array $weights = []
): int {
    $wAttendance = (int) ($weights['attendance'] ?? 40);
    $wClasses = (int) ($weights['classes'] ?? 20);
    $wConsistency = (int) ($weights['consistency'] ?? 20);
    $wWorkouts = (int) ($weights['workouts'] ?? 10);
    $wProgress = (int) ($weights['progress'] ?? 10);

    $attendanceScore = (int) round(min($wAttendance, ($attendanceCount / 7) * $wAttendance));
    $classScore = (int) round(min($wClasses, ($classCount / 4) * $wClasses));
    $consistencyScore = (int) round(min($wConsistency, ($activeWeeks / 4) * $wConsistency));
    $workoutScore = (int) round(min($wWorkouts, ($workoutDays / 8) * $wWorkouts));
    $progressScore = $hasProgress ? $wProgress : 0;

    return $attendanceScore + $classScore + $consistencyScore + $workoutScore + $progressScore;
}

/**
 * Calculates and updates Member Engagement Scores in bulk for a list of user IDs.
 * Uses grouped aggregate queries to eliminate per-user database round-trips.
 *
 * @param int[] $userIds
 * @return array<int, int> Map of user_id => engagement_score
 */
function calculate_engagement_scores_batch(array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($id) => $id > 0)));
    if (empty($userIds)) {
        return [];
    }

    $pdo = db();

    $wAttendance = (int) get_setting('engagement_weight_attendance', '40');
    $wClasses = (int) get_setting('engagement_weight_classes', '20');
    $wConsistency = (int) get_setting('engagement_weight_consistency', '20');
    $wWorkouts = (int) get_setting('engagement_weight_workouts', '10');
    $wProgress = (int) get_setting('engagement_weight_progress', '10');

    $inPlaceholders = implode(',', array_fill(0, count($userIds), '?'));

    // 1. Attendance frequency & consistency (last 30 days)
    $attStmt = $pdo->prepare("
        SELECT user_id, 
               COUNT(*) as attendance_count, 
               COUNT(DISTINCT WEEK(check_in_time)) as active_weeks
        FROM attendance
        WHERE user_id IN ($inPlaceholders) AND check_in_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY user_id
    ");
    $attStmt->execute($userIds);
    $attData = [];
    foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $attData[(int)$r['user_id']] = [
            'attendance_count' => (int)$r['attendance_count'],
            'active_weeks' => (int)$r['active_weeks']
        ];
    }

    // 2. Class participation (last 30 days)
    $classStmt = $pdo->prepare("
        SELECT user_id, COUNT(*) as class_count
        FROM class_bookings
        WHERE user_id IN ($inPlaceholders) 
          AND booking_status = 'attended' 
          AND booked_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY user_id
    ");
    $classStmt->execute($userIds);
    $classData = [];
    foreach ($classStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $classData[(int)$r['user_id']] = (int)$r['class_count'];
    }

    // 3. Completed workouts (last 30 days)
    $workoutStmt = $pdo->prepare("
        SELECT user_id, COUNT(DISTINCT completed_date) as workout_days
        FROM exercise_completions
        WHERE user_id IN ($inPlaceholders) 
          AND completed_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY user_id
    ");
    $workoutStmt->execute($userIds);
    $workoutData = [];
    foreach ($workoutStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $workoutData[(int)$r['user_id']] = (int)$r['workout_days'];
    }

    // 4. Progress updates (last 60 days)
    $progStmt = $pdo->prepare("
        SELECT user_id, COUNT(*) as progress_count
        FROM progress_logs
        WHERE user_id IN ($inPlaceholders) 
          AND log_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
        GROUP BY user_id
    ");
    $progStmt->execute($userIds);
    $progressData = [];
    foreach ($progStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $progressData[(int)$r['user_id']] = (int)$r['progress_count'];
    }

    // 5. Badge check: Iron Lifter (>= 50 completed exercises all-time)
    $ironStmt = $pdo->prepare("
        SELECT user_id
        FROM exercise_completions
        WHERE user_id IN ($inPlaceholders)
        GROUP BY user_id
        HAVING COUNT(*) >= 50
    ");
    $ironStmt->execute($userIds);
    $ironLifters = array_fill_keys(array_map('intval', $ironStmt->fetchAll(PDO::FETCH_COLUMN)), true);

    // 6. Badge check: Early Bird (Checked in before 7:00 AM all-time)
    $earlyStmt = $pdo->prepare("
        SELECT DISTINCT user_id
        FROM attendance
        WHERE user_id IN ($inPlaceholders) AND TIME(check_in_time) < '07:00:00'
    ");
    $earlyStmt->execute($userIds);
    $earlyBirds = array_fill_keys(array_map('intval', $earlyStmt->fetchAll(PDO::FETCH_COLUMN)), true);

    // Compute scores and identify badge eligibility
    $scores = [];
    $badgesToInsert = [];

    foreach ($userIds as $userId) {
        $attCount = (int) ($attData[$userId]['attendance_count'] ?? 0);
        $actWeeks = (int) ($attData[$userId]['active_weeks'] ?? 0);
        $clsCount = (int) ($classData[$userId] ?? 0);
        $wktDays  = (int) ($workoutData[$userId] ?? 0);
        $prgCount = (int) ($progressData[$userId] ?? 0);

        $totalScore = engagement_score_from_metrics(
            $attCount,
            $clsCount,
            $actWeeks,
            $wktDays,
            $prgCount > 0,
            [
                'attendance' => $wAttendance,
                'classes' => $wClasses,
                'consistency' => $wConsistency,
                'workouts' => $wWorkouts,
                'progress' => $wProgress,
            ]
        );
        $scores[$userId] = $totalScore;

        if ($totalScore >= 100) {
            $badgesToInsert[] = [$userId, 'century_club'];
        }
        if (isset($ironLifters[$userId])) {
            $badgesToInsert[] = [$userId, 'iron_lifter'];
        }
        if (isset($earlyBirds[$userId])) {
            $badgesToInsert[] = [$userId, 'early_bird'];
        }
    }

    // Persist engagement scores in users table with a single bulk UPDATE
    if (!empty($scores)) {
        $caseClauses = [];
        $updateParams = [];
        foreach ($scores as $userId => $score) {
            $caseClauses[] = 'WHEN ? THEN ?';
            $updateParams[] = (int) $userId;
            $updateParams[] = (int) $score;
        }
        $inPlaceholders = implode(',', array_fill(0, count($scores), '?'));
        foreach (array_keys($scores) as $userId) {
            $updateParams[] = (int) $userId;
        }

        $bulkUpdateSql = 'UPDATE users SET engagement_score = CASE user_id ' 
            . implode(' ', $caseClauses) 
            . ' END, engagement_computed_at = NOW() WHERE user_id IN (' . $inPlaceholders . ')';
        $pdo->prepare($bulkUpdateSql)->execute($updateParams);
    }

    // Persist badges with a single multi-row INSERT IGNORE
    if (!empty($badgesToInsert)) {
        $rowPlaceholders = [];
        $badgeParams = [];
        foreach ($badgesToInsert as $b) {
            $rowPlaceholders[] = '(?, ?)';
            $badgeParams[] = (int) $b[0];
            $badgeParams[] = (string) $b[1];
        }
        $bulkBadgeSql = 'INSERT IGNORE INTO member_badges (user_id, badge_type) VALUES ' . implode(', ', $rowPlaceholders);
        $pdo->prepare($bulkBadgeSql)->execute($badgeParams);
    }

    return $scores;
}

function calculate_engagement_score(int $userId): int
{
    $scores = calculate_engagement_scores_batch([$userId]);
    return (int) ($scores[$userId] ?? 0);
}

/**
 * Checks and awards badges to a user based on their engagement metrics.
 */
function check_and_award_badges(int $userId, int $score): void
{
    $pdo = db();
    
    // Check Century Club (Score >= 100)
    if ($score >= 100) {
        $pdo->prepare('INSERT IGNORE INTO member_badges (user_id, badge_type) VALUES (?, ?)')
            ->execute([$userId, 'century_club']);
    }

    // Check Iron Lifter (>= 50 completed exercises)
    $completedCount = (int) scalar('SELECT COUNT(*) FROM exercise_completions WHERE user_id = ?', [$userId]);
    if ($completedCount >= 50) {
        $pdo->prepare('INSERT IGNORE INTO member_badges (user_id, badge_type) VALUES (?, ?)')
            ->execute([$userId, 'iron_lifter']);
    }

    // Check Early Bird (Checked in before 7:00 AM)
    $earlyCheckins = (int) scalar('SELECT COUNT(*) FROM attendance WHERE user_id = ? AND TIME(check_in_time) < "07:00:00"', [$userId]);
    if ($earlyCheckins >= 1) {
        $pdo->prepare('INSERT IGNORE INTO member_badges (user_id, badge_type) VALUES (?, ?)')
            ->execute([$userId, 'early_bird']);
    }
}

/**
 * Returns structured missions data showing each engagement task,
 * current progress, target, earned points, and completion status.
 */
function get_engagement_missions(int $userId): array
{
    $wAttendance = (int) get_setting('engagement_weight_attendance', '40');
    $wClasses = (int) get_setting('engagement_weight_classes', '20');
    $wConsistency = (int) get_setting('engagement_weight_consistency', '20');
    $wWorkouts = (int) get_setting('engagement_weight_workouts', '10');
    $wProgress = (int) get_setting('engagement_weight_progress', '10');

    // Attendance
    $attendanceCount = (int) scalar('SELECT COUNT(*) FROM attendance WHERE user_id = ? AND check_in_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$userId]);
    $attendanceTarget = 7;
    $attendanceCurrent = min($attendanceCount, $attendanceTarget);
    $attendanceEarned = (int) round(min($wAttendance, ($attendanceCount / $attendanceTarget) * $wAttendance));

    // Classes
    $classCount = (int) scalar('SELECT COUNT(*) FROM class_bookings WHERE user_id = ? AND booking_status = "attended" AND booked_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$userId]);
    $classTarget = 4;
    $classCurrent = min($classCount, $classTarget);
    $classEarned = (int) round(min($wClasses, ($classCount / $classTarget) * $wClasses));

    // Consistency
    $activeWeeks = (int) scalar('SELECT COUNT(DISTINCT WEEK(check_in_time)) FROM attendance WHERE user_id = ? AND check_in_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$userId]);
    $consistencyTarget = 4;
    $consistencyCurrent = min($activeWeeks, $consistencyTarget);
    $consistencyEarned = (int) round(min($wConsistency, ($activeWeeks / $consistencyTarget) * $wConsistency));

    // Workouts
    $workoutDays = (int) scalar('SELECT COUNT(DISTINCT completed_date) FROM exercise_completions WHERE user_id = ? AND completed_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$userId]);
    $workoutTarget = 8;
    $workoutCurrent = min($workoutDays, $workoutTarget);
    $workoutEarned = (int) round(min($wWorkouts, ($workoutDays / $workoutTarget) * $wWorkouts));

    // Progress
    $progressCount = (int) scalar('SELECT COUNT(*) FROM progress_logs WHERE user_id = ? AND log_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)', [$userId]);
    $progressTarget = 1;
    $progressCurrent = min($progressCount, $progressTarget);
    $progressEarned = $progressCount > 0 ? $wProgress : 0;

    return [
        [
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4v16"/><path d="M10 4v16"/><path d="M6 12h4"/><path d="M14 4v16"/><path d="M18 4v16"/><path d="M14 12h4"/></svg>',
            'title' => 'Check in to the gym',
            'description' => 'Visit the gym 7 times this month',
            'current' => $attendanceCurrent,
            'target' => $attendanceTarget,
            'maxPoints' => $wAttendance,
            'earnedPoints' => $attendanceEarned,
            'completed' => $attendanceCurrent >= $attendanceTarget,
        ],
        [
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'title' => 'Attend group classes',
            'description' => 'Join 4 classes this month',
            'current' => $classCurrent,
            'target' => $classTarget,
            'maxPoints' => $wClasses,
            'earnedPoints' => $classEarned,
            'completed' => $classCurrent >= $classTarget,
        ],
        [
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
            'title' => 'Keep your weekly streak',
            'description' => 'Be active in 4 different weeks',
            'current' => $consistencyCurrent,
            'target' => $consistencyTarget,
            'maxPoints' => $wConsistency,
            'earnedPoints' => $consistencyEarned,
            'completed' => $consistencyCurrent >= $consistencyTarget,
        ],
        [
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
            'title' => 'Complete daily workouts',
            'description' => 'Finish workouts on 8 different days',
            'current' => $workoutCurrent,
            'target' => $workoutTarget,
            'maxPoints' => $wWorkouts,
            'earnedPoints' => $workoutEarned,
            'completed' => $workoutCurrent >= $workoutTarget,
        ],
        [
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
            'title' => 'Log your progress',
            'description' => 'Record your progress at least once (60 days)',
            'current' => $progressCurrent,
            'target' => $progressTarget,
            'maxPoints' => $wProgress,
            'earnedPoints' => $progressEarned,
            'completed' => $progressCurrent >= $progressTarget,
        ],
    ];
}

function get_cached_engagement_score(int $userId): int
{
    $row = db()->query(
        'SELECT engagement_score FROM users WHERE user_id = ' . (int)$userId
    )->fetch();
    
    return (int) ($row['engagement_score'] ?? 0);
}

function recompute_engagement_job(array $payload): void
{
    calculate_engagement_score((int) $payload['user_id']);
}

/**
 * Retrieves the active engagement sweep lock metadata.
 */
function get_engagement_sweep_lock(): ?array
{
    $redis = function_exists('redis') ? redis() : null;
    if ($redis !== null) {
        try {
            $val = $redis->get('lock:engagement_sweep');
            if ($val !== null) {
                return json_decode($val, true);
            }
            return null;
        } catch (Throwable) {}
    }

    $lockFile = sys_get_temp_dir() . '/fittracks_engagement_sweep.lock';
    if (file_exists($lockFile)) {
        $fp = @fopen($lockFile, 'r');
        if ($fp) {
            @flock($fp, LOCK_SH);
            $content = stream_get_contents($fp);
            @flock($fp, LOCK_UN);
            fclose($fp);
            $data = !empty($content) ? json_decode($content, true) : null;
            if (is_array($data)) {
                return $data;
            }
        }
    }
    return null;
}

/**
 * Atomically acquires the engagement sweep lock for a new run.
 * Uses Redis SET NX EX + atomic takeover Lua script, or flock() on file fallback.
 */
function acquire_engagement_sweep_lock(string $runId, int $ttl = 3600): bool
{
    $lockData = [
        'run_id' => $runId,
        'started_at' => time(),
        'updated_at' => time(),
        'last_user_id' => 0,
    ];
    $encoded = json_encode($lockData);

    $redis = function_exists('redis') ? redis() : null;
    if ($redis !== null) {
        try {
            // 1. Atomic SET ... EX ... NX
            $res = $redis->set('lock:engagement_sweep', $encoded, 'EX', $ttl, 'NX');
            if ($res) {
                return true;
            }

            // 2. Atomic stale-lock takeover via Lua (avoids read-then-write race between concurrent workers)
            $takeoverLua = '
                local val = redis.call("get", KEYS[1])
                if val then
                    local data = cjson.decode(val)
                    local updated_at = tonumber(data["updated_at"] or 0)
                    local now = tonumber(ARGV[2])
                    if (now - updated_at) >= 1800 then
                        redis.call("setex", KEYS[1], tonumber(ARGV[3]), ARGV[1])
                        return 1
                    end
                end
                return 0
            ';
            $taken = (int) $redis->eval($takeoverLua, 1, 'lock:engagement_sweep', $encoded, time(), $ttl);
            return $taken === 1;
        } catch (Throwable $e) {
            error_log('Redis lock acquire error, falling back to file flock: ' . $e->getMessage());
        }
    }

    // File fallback: protect the whole read/check/write cycle with flock
    $lockFile = sys_get_temp_dir() . '/fittracks_engagement_sweep.lock';
    $fp = @fopen($lockFile, 'c+');
    if (!$fp) {
        return false;
    }

    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false; // Another worker currently holds the file lock
    }

    $content = stream_get_contents($fp);
    $existing = !empty($content) ? json_decode($content, true) : null;
    if ($existing && !empty($existing['run_id']) && (time() - (int)($existing['updated_at'] ?? 0)) < 1800) {
        // Active unexpired run exists
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    // Write new lock atomically under flock
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $encoded);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

/**
 * Updates the active engagement sweep lock progress for the current run atomically.
 */
function update_engagement_sweep_lock(string $runId, int $lastUserId, int $ttl = 3600): void
{
    $redis = function_exists('redis') ? redis() : null;
    if ($redis !== null) {
        try {
            $updateLua = '
                local val = redis.call("get", KEYS[1])
                if val then
                    local data = cjson.decode(val)
                    if data and data["run_id"] == ARGV[1] then
                        data["updated_at"] = tonumber(ARGV[2])
                        data["last_user_id"] = tonumber(ARGV[3])
                        redis.call("setex", KEYS[1], tonumber(ARGV[4]), cjson.encode(data))
                        return 1
                    end
                end
                return 0
            ';
            $redis->eval($updateLua, 1, 'lock:engagement_sweep', $runId, time(), $lastUserId, $ttl);
            return;
        } catch (Throwable) {}
    }

    $lockFile = sys_get_temp_dir() . '/fittracks_engagement_sweep.lock';
    $fp = @fopen($lockFile, 'c+');
    if ($fp && @flock($fp, LOCK_EX)) {
        $content = stream_get_contents($fp);
        $data = !empty($content) ? json_decode($content, true) : null;
        if (is_array($data) && ($data['run_id'] ?? '') === $runId) {
            $data['updated_at'] = time();
            $data['last_user_id'] = $lastUserId;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Releases the engagement sweep lock once all members are processed,
 * confirming via atomic Lua/flock that the current run ID still owns the lock.
 */
function release_engagement_sweep_lock(string $runId): void
{
    $redis = function_exists('redis') ? redis() : null;
    if ($redis !== null) {
        try {
            $releaseLua = '
                local val = redis.call("get", KEYS[1])
                if val then
                    local data = cjson.decode(val)
                    if data and data["run_id"] == ARGV[1] then
                        return redis.call("del", KEYS[1])
                    end
                end
                return 0
            ';
            $redis->eval($releaseLua, 1, 'lock:engagement_sweep', $runId);
            return;
        } catch (Throwable) {}
    }

    $lockFile = sys_get_temp_dir() . '/fittracks_engagement_sweep.lock';
    if (!file_exists($lockFile)) {
        return;
    }

    $fp = @fopen($lockFile, 'c+');
    if ($fp && @flock($fp, LOCK_EX)) {
        $content = stream_get_contents($fp);
        $data = !empty($content) ? json_decode($content, true) : null;
        if (is_array($data) && ($data['run_id'] ?? '') === $runId) {
            // Truncate contents to release lock; leave file in place to avoid inode race
            ftruncate($fp, 0);
            rewind($fp);
            fflush($fp);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function recompute_all_engagement_scores_batch(array $payload = []): void
{
    $pdo = db();
    $incomingRunId = (string) ($payload['run_id'] ?? '');
    $lastUserId = (int) ($payload['last_user_id'] ?? 0);
    // Bounded batch size (25) to easily complete well within worker time slices
    $batchSize = 25; 

    // Initial batch of a sweep: acquire lock atomically
    if ($lastUserId === 0) {
        $runId = bin2hex(random_bytes(8));
        if (!acquire_engagement_sweep_lock($runId, 3600)) {
            error_log("Engagement sweep already in progress. Skipping duplicate initialization.");
            return;
        }
    } else {
        // Continuation batch: verify this job belongs to the active run
        $activeLock = get_engagement_sweep_lock();
        if ($activeLock && !empty($activeLock['run_id']) && !empty($incomingRunId) && $activeLock['run_id'] !== $incomingRunId) {
            error_log("Engagement batch continuation job with superseded run ID {$incomingRunId} skipped (active run: {$activeLock['run_id']}).");
            return;
        }
        $runId = !empty($incomingRunId) ? $incomingRunId : ($activeLock['run_id'] ?? bin2hex(random_bytes(8)));
    }

    $stmt = $pdo->prepare("
        SELECT user_id 
        FROM users 
        WHERE role = 'member' AND status = 'active' AND user_id > ?
        ORDER BY user_id ASC 
        LIMIT ?
    ");
    $stmt->bindValue(1, $lastUserId, PDO::PARAM_INT);
    $stmt->bindValue(2, $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    $userIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if (empty($userIds)) {
        // All active members processed; release lock confirming ownership
        release_engagement_sweep_lock($runId);
        return;
    }

    calculate_engagement_scores_batch($userIds);

    $lastProcessedId = end($userIds);

    // If batch was full, enqueue next chunk with cursor to continue smoothly
    if (count($userIds) === $batchSize) {
        update_engagement_sweep_lock($runId, $lastProcessedId, 3600);
        Queue::push('recompute_all_engagement_scores_batch', [
            'run_id' => $runId,
            'last_user_id' => $lastProcessedId
        ]);
    } else {
        // Final partial batch completed; release lock confirming ownership
        release_engagement_sweep_lock($runId);
    }
}

function get_engagement_category(int $score): string
{
    $high = (int) get_setting('engagement_threshold_high', '75');
    $moderate = (int) get_setting('engagement_threshold_moderate', '40');
    if ($score >= $high) return 'Highly Engaged';
    if ($score >= $moderate) return 'Moderately Engaged';
    return 'At-Risk';
}

function get_inactive_members(int $limit = 5, ?int $gymId = null): array
{
    $pdo = db();
    
    $gymJoin = '';
    $gymWhere = '';
    if ($gymId !== null) {
        $gymJoin = 'LEFT JOIN gym_members gm ON gm.user_id = u.user_id 
                    LEFT JOIN memberships m ON m.user_id = u.user_id AND m.status = "active" 
                    LEFT JOIN membership_plans mp ON mp.plan_id = m.plan_id';
        $gymWhere = 'AND (gm.gym_id = ' . (int)$gymId . ' OR mp.gym_id = ' . (int)$gymId . ')';
    }

    $users = query_all(
        'SELECT u.user_id, u.first_name, u.last_name, u.email, u.profile_picture, u.created_at, u.engagement_score,
                MAX(a.check_in_time) as last_checkin, 
                COALESCE(DATEDIFF(CURDATE(), MAX(a.check_in_time)), DATEDIFF(CURDATE(), u.created_at)) as days_inactive
         FROM users u
         LEFT JOIN attendance a ON u.user_id = a.user_id
         ' . $gymJoin . '
         WHERE u.role = "member" AND u.status = "active" ' . $gymWhere . '
         GROUP BY u.user_id'
    );

    $atRisk = [];
    foreach ($users as $u) {
        if ((int)$u['days_inactive'] < 1) {
            continue; // Skip members who checked in today (0 days inactive)
        }
        $score = (int) ($u['engagement_score'] ?? 0);
        if (get_engagement_category($score) === 'At-Risk') {
            $atRisk[] = $u;
        }
    }

    usort($atRisk, fn($a, $b) => ($b['days_inactive'] ?? 9999) <=> ($a['days_inactive'] ?? 9999));
    return array_slice($atRisk, 0, $limit);
}

function send_at_risk_notification_job(array $payload): void
{
    $userId = (int) ($payload['user_id'] ?? 0);
    $customMsg = trim((string) ($payload['custom_message'] ?? ''));
    if ($userId <= 0) {
        return;
    }

    $inAppMessage = !empty($customMsg)
        ? $customMsg
        : 'It\'s been a few days since your last activity. Check out this week\'s classes or your new workout plan to get back on track!';

    notify_user($userId, 'system', 'We miss you at the gym! ', $inAppMessage);

    // Send email reminder if user has a valid active email
    try {
        $stmt = db()->prepare('SELECT email, first_name FROM users WHERE user_id = ? AND status = "active"');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && !empty($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            $firstName = !empty($user['first_name']) ? (string) $user['first_name'] : 'Member';
            Emails::sendInactiveReminder((string) $user['email'], $firstName, !empty($customMsg) ? $customMsg : null);
        }
    } catch (Throwable $e) {
        error_log("Failed to queue inactive reminder email for user #{$userId}: " . $e->getMessage());
    }
}

function process_automated_at_risk_notifications(): void
{
    $pdo = db();
    
    // Load platform defaults
    $globalThreshold = (int) get_setting('at_risk_inactivity_days', '3');
    $globalCooldown = (int) get_setting('at_risk_notification_cooldown', '14');
    
    // Load gym-level overrides
    $gymOverrides = [];
    try {
        $gymRows = $pdo->query('SELECT gym_id, inactivity_threshold_days, inactivity_cooldown_days, auto_inactivity_alerts FROM gyms')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($gymRows as $g) {
            $gymOverrides[(int)$g['gym_id']] = $g;
        }
    } catch (Throwable $e) {}

    // Fetch members with their primary gym affiliation and inactivity duration
    $atRiskMembers = query_all(
        'SELECT u.user_id, u.first_name, u.last_name, u.email, u.created_at, u.engagement_score,
                COALESCE(gm.gym_id, mp.gym_id) as gym_id,
                MAX(a.check_in_time) as last_checkin, 
                COALESCE(DATEDIFF(CURDATE(), MAX(a.check_in_time)), DATEDIFF(CURDATE(), u.created_at)) as days_inactive
         FROM users u
         LEFT JOIN gym_members gm ON gm.user_id = u.user_id
         LEFT JOIN memberships m ON m.user_id = u.user_id AND m.status = "active"
         LEFT JOIN membership_plans mp ON mp.plan_id = m.plan_id
         LEFT JOIN attendance a ON u.user_id = a.user_id
         WHERE u.role = "member" AND u.status = "active"
         GROUP BY u.user_id'
    );

    foreach ($atRiskMembers as $member) {
        $userId = (int) $member['user_id'];
        $daysInactive = (int) ($member['days_inactive'] ?? 0);
        if ($daysInactive < 1) {
            continue;
        }

        $gymId = !empty($member['gym_id']) ? (int) $member['gym_id'] : null;
        $gymConfig = $gymId && isset($gymOverrides[$gymId]) ? $gymOverrides[$gymId] : null;

        // Check if gym disabled automated alerts
        if ($gymConfig && isset($gymConfig['auto_inactivity_alerts']) && (int)$gymConfig['auto_inactivity_alerts'] === 0) {
            continue;
        }

        // Determine effective threshold and cooldown (Gym override > Platform default)
        $effectiveThreshold = ($gymConfig && !empty($gymConfig['inactivity_threshold_days']))
            ? (int) $gymConfig['inactivity_threshold_days']
            : $globalThreshold;

        if ($daysInactive < $effectiveThreshold) {
            continue;
        }

        $effectiveCooldown = ($gymConfig && !empty($gymConfig['inactivity_cooldown_days']))
            ? (int) $gymConfig['inactivity_cooldown_days']
            : $globalCooldown;

        // Check if user already received reminder within their effective cooldown window
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications 
                               WHERE user_id = ? 
                                 AND type = 'system' 
                                 AND title = 'We miss you at the gym!' 
                                 AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$userId, $effectiveCooldown]);
        $recentCount = (int) $stmt->fetchColumn();

        if ($recentCount === 0) {
            Queue::push('send_at_risk_notification_job', ['user_id' => $userId]);
        }
    }
}
