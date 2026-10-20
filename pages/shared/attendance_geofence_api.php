<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/attendance_geofence_service.php';

function attendance_geofence_api_handler(): void
{
    header('Content-Type: application/json; charset=utf-8');

    $user = current_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $action = $_REQUEST['action'] ?? '';
    $userId = (int)$user['user_id'];
    $gymId = isset($_REQUEST['gym_id']) ? (int)$_REQUEST['gym_id'] : null;

    switch ($action) {
        case 'geofence_exit':
            // Member stepped outside geofence while checked in -> start 20m buffer
            $result = start_attendance_exit_grace_period($userId, $gymId, 20);
            echo json_encode($result);
            break;

        case 'geofence_reenter':
            // Member returned to the gym perimeter within buffer -> restore session
            $result = cancel_attendance_exit_grace_period($userId, $gymId);
            echo json_encode($result);
            break;

        case 'get_status':
            $status = get_attendance_exit_grace_status($userId);
            echo json_encode([
                'success' => true,
                'grace_active' => $status !== null && !empty($status['is_in_grace']),
                'data' => $status
            ]);
            break;

        case 'confirm_checkout':
            // User confirmed they are finished working out
            $pdo = db();
            $stmt = $pdo->prepare('UPDATE attendance SET check_out_time = NOW(), exit_grace_expires_at = NULL WHERE user_id = ? AND check_out_time IS NULL');
            $stmt->execute([$userId]);
            if (function_exists('release_user_equipment_on_checkout')) {
                release_user_equipment_on_checkout($userId);
            }
            if (RedisClient::isConnected()) {
                RedisClient::del("ft:user_exit_grace:{$userId}");
            }
            echo json_encode(['success' => true, 'message' => 'Checked out successfully']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    exit;
}
