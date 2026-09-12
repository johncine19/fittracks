<?php
declare(strict_types=1);

function equipment_api_handler(): void
{
    header('Content-Type: application/json; charset=utf-8');

    // Ensure user is authenticated
    $user = current_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
        exit;
    }

    $pdo = db();
    $action = $_REQUEST['action'] ?? 'poll';
    $gymId = get_user_gym_id($user);

    // If equipment_id is passed, automatically adopt equipment's gym for member or platform admin
    if (!empty($_REQUEST['equipment_id'])) {
        $equipGym = (int)scalar("SELECT gym_id FROM gym_equipment WHERE equipment_id = ? LIMIT 1", [(int)$_REQUEST['equipment_id']]);
        if ($equipGym > 0) {
            if (!$gymId || in_array($user['role'], ['platform_admin', 'member'], true)) {
                $gymId = $equipGym;
            }
        }
    }

    // Explicit gym_id support
    if (!empty($_REQUEST['gym_id'])) {
        $reqGymId = (int)$_REQUEST['gym_id'];
        if ($user['role'] === 'platform_admin' || $user['role'] === 'member') {
            $gymId = $reqGymId;
        } elseif (in_array($user['role'], ['gym_owner', 'admin'], true)) {
            $isOwner = (bool)scalar("SELECT 1 FROM gyms WHERE gym_id = ? AND owner_user_id = ?", [$reqGymId, $user['user_id']]);
            if ($isOwner || (isset($user['gym_id']) && (int)$user['gym_id'] === $reqGymId)) {
                $gymId = $reqGymId;
            }
        }
    }

    if (!$gymId && $user['role'] === 'platform_admin') {
        $gymId = (int)scalar('SELECT gym_id FROM gyms ORDER BY gym_id ASC LIMIT 1');
    }

    if (!$gymId) {
        echo json_encode(['success' => false, 'message' => 'No registered gym associated with your account.']);
        exit;
    }

    try {
        // Run queue expiration maintenance and equipment state reconciliation on each request
        maintenance_check_expired_queues($pdo, $gymId);
        reconcile_equipment_states($pdo, $gymId);

        switch ($action) {
            case 'poll':
                handle_poll($pdo, $gymId, $user);
                break;

            case 'start_session':
                handle_start_session($pdo, $gymId, $user);
                break;

            case 'finish_session':
                handle_finish_session($pdo, $gymId, $user);
                break;

            case 'log_progress':
                handle_log_progress($pdo, $gymId, $user);
                break;

            case 'join_queue':
                handle_join_queue($pdo, $gymId, $user);
                break;

            case 'leave_queue':
                handle_leave_queue($pdo, $gymId, $user);
                break;

            case 'claim_session':
                handle_claim_session($pdo, $gymId, $user);
                break;

            case 'admin_save':
                assert_admin($user);
                handle_admin_save($pdo, $gymId, $user);
                break;

            case 'admin_set_maintenance':
                assert_admin($user);
                handle_admin_set_maintenance($pdo, $gymId, $user);
                break;

            case 'admin_restore_available':
                assert_admin($user);
                handle_admin_restore_available($pdo, $gymId, $user);
                break;

            case 'admin_delete':
                assert_admin($user);
                handle_admin_delete($pdo, $gymId, $user);
                break;

            case 'admin_queue_details':
                assert_admin($user);
                handle_admin_queue_details($pdo, $gymId);
                break;

            case 'admin_force_finish':
                assert_admin($user);
                handle_admin_force_finish($pdo, $gymId, $user);
                break;

            case 'admin_remove_queue':
                assert_admin($user);
                handle_admin_remove_queue($pdo, $gymId, $user);
                break;

            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action.']);
                break;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

function assert_admin(array $user): void
{
    $allowed = ['gym_owner', 'admin', 'platform_admin'];
    if (!in_array($user['role'], $allowed, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. Gym owner or administrator privilege required.']);
        exit;
    }
}

/**
 * Automatically advances queues where the 2-minute claim window has expired.
 */
function maintenance_check_expired_queues(PDO $pdo, int $gymId): void
{
    $expiredStmt = $pdo->prepare("SELECT queue_id, equipment_id, user_id FROM equipment_queues WHERE gym_id = ? AND queue_status = 'notified' AND claim_deadline IS NOT NULL AND claim_deadline < NOW()");
    $expiredStmt->execute([$gymId]);
    $expiredList = $expiredStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expiredList as $exp) {
        $qId = (int)$exp['queue_id'];
        $eqId = (int)$exp['equipment_id'];
        $uId = (int)$exp['user_id'];

        // Mark this queue entry as expired
        $pdo->prepare("UPDATE equipment_queues SET queue_status = 'expired', resolved_at = NOW() WHERE queue_id = ?")->execute([$qId]);

        // Notify user their claim window expired
        $eqName = scalar('SELECT CONCAT(name, " ", unit_number) FROM gym_equipment WHERE equipment_id = ?', [$eqId]) ?: 'Equipment';
        if (function_exists('notify_user')) {
            notify_user($uId, 'system', 'Queue Claim Window Expired', "Your 2-minute window to claim {$eqName} has expired. The next person in line has been notified.", $eqId);
        }

        // Process next in line for this equipment
        process_next_in_queue($pdo, $gymId, $eqId);
    }
}

/**
 * Automatically reconciles equipment status with active sessions.
 * If equipment is marked 'in_use' but has no active session in equipment_sessions,
 * it clears current_session_id and advances any waiting queue or resets to 'available'.
 * Conversely, if an active session exists, it ensures status is 'in_use'.
 */
function reconcile_equipment_states(PDO $pdo, int $gymId): void
{
    // 1. Find all equipment marked 'in_use' that have no active session
    $staleStmt = $pdo->prepare("
        SELECT e.equipment_id, e.gym_id, e.name, e.unit_number
        FROM gym_equipment e
        WHERE e.status = 'in_use'
          AND (e.gym_id = ? OR ? = 0)
          AND NOT EXISTS (
              SELECT 1 FROM equipment_sessions s
              WHERE s.equipment_id = e.equipment_id
                AND s.session_status = 'active'
          )
    ");
    $staleStmt->execute([$gymId, $gymId]);
    $staleEquipments = $staleStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($staleEquipments as $stale) {
        $eqId = (int)$stale['equipment_id'];
        $eqGymId = (int)$stale['gym_id'];

        // Clear current_session_id
        $pdo->prepare("UPDATE gym_equipment SET current_session_id = NULL WHERE equipment_id = ?")->execute([$eqId]);

        // Process queue: if someone is waiting, notify them; otherwise set status to available
        process_next_in_queue($pdo, $eqGymId, $eqId);
    }

    // 2. Clear any current_session_id that points to an inactive session
    $pdo->prepare("
        UPDATE gym_equipment e
        LEFT JOIN equipment_sessions s ON s.session_id = e.current_session_id AND s.session_status = 'active'
        SET e.current_session_id = NULL
        WHERE (e.gym_id = ? OR ? = 0) AND e.current_session_id IS NOT NULL AND s.session_id IS NULL
    ")->execute([$gymId, $gymId]);

    // 3. Ensure any equipment with an active session is marked 'in_use'
    $pdo->prepare("
        UPDATE gym_equipment e
        JOIN equipment_sessions s ON s.equipment_id = e.equipment_id AND s.session_status = 'active'
        SET e.status = 'in_use', e.current_session_id = s.session_id
        WHERE (e.gym_id = ? OR ? = 0) AND e.status = 'available'
    ")->execute([$gymId, $gymId]);
}

/**
 * Promotes the next waiting member in line to 'notified' with a 2-minute claim deadline.
 */
function process_next_in_queue(PDO $pdo, int $gymId, int $equipmentId): void
{
    // Reorder waiting positions first
    reorder_waiting_queues($pdo, $equipmentId);

    // Find first waiting member
    $nextStmt = $pdo->prepare("SELECT queue_id, user_id, queue_position FROM equipment_queues WHERE equipment_id = ? AND queue_status = 'waiting' ORDER BY queue_position ASC, joined_at ASC LIMIT 1");
    $nextStmt->execute([$equipmentId]);
    $next = $nextStmt->fetch(PDO::FETCH_ASSOC);

    $eqStmt = $pdo->prepare("SELECT name, unit_number, status FROM gym_equipment WHERE equipment_id = ?");
    $eqStmt->execute([$equipmentId]);
    $eq = $eqStmt->fetch(PDO::FETCH_ASSOC);
    $eqName = $eq ? ($eq['name'] . ' ' . $eq['unit_number']) : 'Equipment';

    if ($next) {
        $nextQueueId = (int)$next['queue_id'];
        $nextUserId = (int)$next['user_id'];

        // Promote to 'notified' with 2-minute claim deadline
        $pdo->prepare("UPDATE equipment_queues SET queue_status = 'notified', notified_at = NOW(), claim_deadline = DATE_ADD(NOW(), INTERVAL 2 MINUTE) WHERE queue_id = ?")->execute([$nextQueueId]);

        // Update equipment status to available so it can be claimed
        $pdo->prepare("UPDATE gym_equipment SET status = 'available' WHERE equipment_id = ? AND status != 'maintenance' AND status != 'out_of_service'")->execute([$equipmentId]);

        if (function_exists('notify_user')) {
            notify_user($nextUserId, 'system', 'Equipment Available', "{$eqName} is now available! You have 2 minutes to claim and start your session.", $equipmentId);
        }
    } else {
        // No one waiting, equipment remains available
        $pdo->prepare("UPDATE gym_equipment SET status = 'available' WHERE equipment_id = ? AND status != 'maintenance' AND status != 'out_of_service'")->execute([$equipmentId]);
    }
}

function reorder_waiting_queues(PDO $pdo, int $equipmentId): void
{
    $stmt = $pdo->prepare("SELECT queue_id FROM equipment_queues WHERE equipment_id = ? AND queue_status = 'waiting' ORDER BY queue_position ASC, joined_at ASC");
    $stmt->execute([$equipmentId]);
    $items = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $pos = 1;
    $upd = $pdo->prepare("UPDATE equipment_queues SET queue_position = ? WHERE queue_id = ?");
    foreach ($items as $qid) {
        $upd->execute([$pos, $qid]);
        $pos++;
    }
}

function handle_poll(PDO $pdo, int $gymId, array $user): void
{
    $userId = (int)$user['user_id'];

    // 1. Check user's current active session
    $activeSessionStmt = $pdo->prepare("
        SELECT s.session_id, s.equipment_id, s.start_time, UNIX_TIMESTAMP(s.start_time) as start_ts,
               TIMESTAMPDIFF(SECOND, s.start_time, NOW()) as elapsed_seconds,
               e.name, e.unit_number, e.category, e.location_area, e.image_url
        FROM equipment_sessions s
        JOIN gym_equipment e ON e.equipment_id = s.equipment_id
        WHERE s.user_id = ? AND s.session_status = 'active'
        LIMIT 1
    ");
    $activeSessionStmt->execute([$userId]);
    $activeSession = $activeSessionStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // 2. Check user's active queues
    $myQueuesStmt = $pdo->prepare("
        SELECT q.queue_id, q.equipment_id, q.queue_position, q.queue_status,
               UNIX_TIMESTAMP(q.claim_deadline) as claim_deadline_ts,
               TIMESTAMPDIFF(SECOND, NOW(), q.claim_deadline) as claim_remaining_seconds,
               e.name, e.unit_number, e.category, e.location_area, e.status as equipment_status,
               (SELECT COUNT(*) FROM equipment_queues q2 WHERE q2.equipment_id = q.equipment_id AND q2.queue_status IN ('waiting', 'notified') AND q2.queue_position < q.queue_position) as members_ahead
        FROM equipment_queues q
        JOIN gym_equipment e ON e.equipment_id = q.equipment_id
        WHERE q.user_id = ? AND q.queue_status IN ('waiting', 'notified')
        ORDER BY q.joined_at ASC
    ");
    $myQueuesStmt->execute([$userId]);
    $myQueues = $myQueuesStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Equipment inventory list for this gym
    $equipStmt = $pdo->prepare("
        SELECT e.*,
               (SELECT COUNT(*) FROM equipment_queues q WHERE q.equipment_id = e.equipment_id AND q.queue_status IN ('waiting', 'notified')) as waiting_queue_count,
               (SELECT CONCAT(u.first_name, ' ', SUBSTRING(u.last_name, 1, 1), '.') FROM equipment_sessions s JOIN users u ON u.user_id = s.user_id WHERE s.equipment_id = e.equipment_id AND s.session_status = 'active' LIMIT 1) as current_user_display,
               (SELECT UNIX_TIMESTAMP(s.start_time) FROM equipment_sessions s WHERE s.equipment_id = e.equipment_id AND s.session_status = 'active' LIMIT 1) as session_start_ts,
               (SELECT s.user_id FROM equipment_sessions s WHERE s.equipment_id = e.equipment_id AND s.session_status = 'active' LIMIT 1) as current_session_user_id,
               (SELECT CONCAT(u.first_name, ' ', SUBSTRING(u.last_name, 1, 1), '.') FROM equipment_queues q JOIN users u ON u.user_id = q.user_id WHERE q.equipment_id = e.equipment_id AND q.queue_status = 'notified' AND q.claim_deadline >= NOW() LIMIT 1) as notified_user_display,
               (SELECT q.user_id FROM equipment_queues q WHERE q.equipment_id = e.equipment_id AND q.queue_status = 'notified' AND q.claim_deadline >= NOW() LIMIT 1) as notified_user_id,
               (SELECT TIMESTAMPDIFF(SECOND, NOW(), q.claim_deadline) FROM equipment_queues q WHERE q.equipment_id = e.equipment_id AND q.queue_status = 'notified' AND q.claim_deadline >= NOW() LIMIT 1) as claim_remaining_seconds
        FROM gym_equipment e
        WHERE e.gym_id = ?
        ORDER BY e.category ASC, e.name ASC, e.unit_number ASC
    ");
    $equipStmt->execute([$gymId]);
    $equipment = $equipStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'server_time' => time(),
        'active_session' => $activeSession,
        'my_queues' => $myQueues,
        'equipment' => $equipment
    ]);
}

function handle_start_session(PDO $pdo, int $gymId, array $user): void
{
    $userId = (int)$user['user_id'];
    $equipmentId = (int)($_POST['equipment_id'] ?? 0);

    if ($equipmentId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid equipment selection.']);
        return;
    }

    // Rule 8: A member cannot occupy multiple equipment sessions simultaneously
    $hasActive = scalar("SELECT session_id FROM equipment_sessions WHERE user_id = ? AND session_status = 'active' LIMIT 1", [$userId]);
    if ($hasActive) {
        echo json_encode(['success' => false, 'message' => "You already have an active equipment session. Please finish it before starting another."]);
        return;
    }

    // Check if equipment exists
    $equipStmt = $pdo->prepare("SELECT * FROM gym_equipment WHERE equipment_id = ?");
    $equipStmt->execute([$equipmentId]);
    $equip = $equipStmt->fetch(PDO::FETCH_ASSOC);

    if (!$equip) {
        echo json_encode(['success' => false, 'message' => 'Equipment not found.']);
        return;
    }

    $eqGymId = (int)$equip['gym_id'];

    if ($equip['status'] === 'maintenance' || $equip['status'] === 'out_of_service') {
        echo json_encode(['success' => false, 'message' => 'This equipment is currently under maintenance or out of service.']);
        return;
    }

    // Check if reserved for a queue member who is NOT this user
    $reservedStmt = $pdo->prepare("SELECT user_id, claim_deadline FROM equipment_queues WHERE equipment_id = ? AND queue_status = 'notified' AND claim_deadline >= NOW() LIMIT 1");
    $reservedStmt->execute([$equipmentId]);
    $reserved = $reservedStmt->fetch(PDO::FETCH_ASSOC);

    if ($reserved && (int)$reserved['user_id'] !== $userId) {
        echo json_encode([
            'success' => false,
            'message' => 'This equipment is currently reserved for the next member in queue.',
            'can_queue' => true
        ]);
        return;
    }

    // Atomic claim: prevent simultaneous claims
    $claimStmt = $pdo->prepare("UPDATE gym_equipment SET status = 'in_use' WHERE equipment_id = ? AND status = 'available'");
    $claimStmt->execute([$equipmentId]);

    if ($claimStmt->rowCount() === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'This equipment was just taken by another member.',
            'can_queue' => true
        ]);
        return;
    }

    // Insert active session
    $insertSession = $pdo->prepare("INSERT INTO equipment_sessions (gym_id, equipment_id, user_id, start_time, session_status) VALUES (?, ?, ?, NOW(), 'active')");
    $insertSession->execute([$eqGymId, $equipmentId, $userId]);
    $sessionId = (int)$pdo->lastInsertId();

    // Link session to equipment
    $pdo->prepare("UPDATE gym_equipment SET current_session_id = ? WHERE equipment_id = ?")->execute([$sessionId, $equipmentId]);

    // If user was in queue for this equipment, mark claimed
    $pdo->prepare("UPDATE equipment_queues SET queue_status = 'claimed', resolved_at = NOW() WHERE user_id = ? AND equipment_id = ? AND queue_status IN ('waiting', 'notified')")->execute([$userId, $equipmentId]);
    reorder_waiting_queues($pdo, $equipmentId);

    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'message' => 'Session started! Track your workout timer below.'
    ]);
}

function handle_finish_session(PDO $pdo, int $gymId, array $user): void
{
    $userId = (int)$user['user_id'];
    $sessionId = (int)($_POST['session_id'] ?? 0);

    if ($sessionId <= 0) {
        // Find user's active session
        $sessionId = (int)scalar("SELECT session_id FROM equipment_sessions WHERE user_id = ? AND session_status = 'active' LIMIT 1", [$userId]);
    }

    if ($sessionId <= 0) {
        echo json_encode(['success' => false, 'message' => 'No active session found to finish.']);
        return;
    }

    $sessStmt = $pdo->prepare("SELECT * FROM equipment_sessions WHERE session_id = ?");
    $sessStmt->execute([$sessionId]);
    $session = $sessStmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode(['success' => false, 'message' => 'Session not found.']);
        return;
    }

    // Must be session owner or admin
    $isAdmin = in_array($user['role'], ['gym_owner', 'admin', 'platform_admin'], true);
    if ((int)$session['user_id'] !== $userId && !$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        return;
    }

    $equipmentId = (int)$session['equipment_id'];
    $sessionGymId = (int)$session['gym_id'];
    $durationSeconds = max(1, time() - strtotime($session['start_time']));

    // Complete session
    $pdo->prepare("UPDATE equipment_sessions SET end_time = NOW(), duration_seconds = ?, session_status = 'completed' WHERE session_id = ?")
        ->execute([$durationSeconds, $sessionId]);

    $pdo->prepare("UPDATE gym_equipment SET current_session_id = NULL WHERE equipment_id = ?")->execute([$equipmentId]);

    // Process next waiting person in line or set Available
    process_next_in_queue($pdo, $sessionGymId, $equipmentId);

    echo json_encode([
        'success' => true,
        'duration_seconds' => $durationSeconds,
        'message' => 'Equipment session completed. Great workout!'
    ]);
}

function handle_log_progress(PDO $pdo, int $gymId, array $user): void
{
    $userId = (int)$user['user_id'];
    $logDate = trim($_POST['log_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate)) {
        $logDate = date('Y-m-d');
    }

    $weightKg = isset($_POST['weight_kg']) ? (float)$_POST['weight_kg'] : 0.0;
    if ($weightKg < 20 || $weightKg > 300) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid weight between 20 and 300 kg.']);
        return;
    }

    $bodyFat = (isset($_POST['body_fat_percent']) && $_POST['body_fat_percent'] !== '') ? (float)$_POST['body_fat_percent'] : null;
    if ($bodyFat !== null && ($bodyFat < 1 || $bodyFat > 70)) {
        echo json_encode(['success' => false, 'message' => 'Body fat percentage must be between 1% and 70%.']);
        return;
    }

    $waistCm = (isset($_POST['waist_cm']) && $_POST['waist_cm'] !== '') ? (float)$_POST['waist_cm'] : null;
    if ($waistCm !== null && ($waistCm < 30 || $waistCm > 250)) {
        echo json_encode(['success' => false, 'message' => 'Waist measurement must be between 30 and 250 cm.']);
        return;
    }

    $chestCm = (isset($_POST['chest_cm']) && $_POST['chest_cm'] !== '') ? (float)$_POST['chest_cm'] : null;
    if ($chestCm !== null && ($chestCm < 30 || $chestCm > 250)) {
        echo json_encode(['success' => false, 'message' => 'Chest measurement must be between 30 and 250 cm.']);
        return;
    }

    $armCm = (isset($_POST['arm_cm']) && $_POST['arm_cm'] !== '') ? (float)$_POST['arm_cm'] : null;
    if ($armCm !== null && ($armCm < 15 || $armCm > 100)) {
        echo json_encode(['success' => false, 'message' => 'Arm measurement must be between 15 and 100 cm.']);
        return;
    }

    $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;

    $existingLogId = (int)scalar('SELECT log_id FROM progress_logs WHERE user_id = ? AND log_date = ? LIMIT 1', [$userId, $logDate]);

    if ($existingLogId > 0) {
        $stmt = $pdo->prepare('UPDATE progress_logs SET weight_kg = ?, body_fat_percent = COALESCE(?, body_fat_percent), waist_cm = COALESCE(?, waist_cm), chest_cm = COALESCE(?, chest_cm), arm_cm = COALESCE(?, arm_cm), notes = COALESCE(?, notes), recorded_by = ? WHERE log_id = ?');
        $stmt->execute([$weightKg, $bodyFat, $waistCm, $chestCm, $armCm, $notes, $userId, $existingLogId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO progress_logs (user_id, log_date, weight_kg, body_fat_percent, waist_cm, chest_cm, arm_cm, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $logDate, $weightKg, $bodyFat, $waistCm, $chestCm, $armCm, $notes, $userId]);
    }

    $profileUpdate = 'UPDATE member_profiles SET weight_kg = ?';
    $profileParams = [$weightKg];
    if ($waistCm !== null) {
        $profileUpdate .= ', waist_cm = ?';
        $profileParams[] = $waistCm;
    }
    $profileUpdate .= ' WHERE user_id = ?';
    $profileParams[] = $userId;
    $pdo->prepare($profileUpdate)->execute($profileParams);

    $workoutHelper = __DIR__ . '/workouts.php';
    if (file_exists($workoutHelper)) {
        require_once $workoutHelper;
    }
    if (function_exists('can_recalculate_workout') && can_recalculate_workout($userId)) {
        if (function_exists('generate_workout_plan')) {
            generate_workout_plan($userId);
        }
        if (function_exists('notify_user')) {
            notify_user($userId, 'system', 'Workout plan updated', 'Your workout plan was refreshed after logging new progress.');
        }
    }

    if (function_exists('notify_user')) {
        notify_user($userId, 'milestone', 'Progress logged', 'Nice work — your latest measurements and workout progress were saved.');
    }

    echo json_encode([
        'success' => true,
        'weight_kg' => $weightKg,
        'waist_cm' => $waistCm,
        'chest_cm' => $chestCm,
        'arm_cm' => $armCm,
        'message' => 'Progress logged successfully!'
    ]);
}

function handle_join_queue(PDO $pdo, int $gymId, array $user): void
{
    $userId = (int)$user['user_id'];
    $equipmentId = (int)($_POST['equipment_id'] ?? 0);

    if ($equipmentId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid equipment.']);
        return;
    }

    $equipStmt = $pdo->prepare("SELECT * FROM gym_equipment WHERE equipment_id = ?");
    $equipStmt->execute([$equipmentId]);
    $equip = $equipStmt->fetch(PDO::FETCH_ASSOC);

    if (!$equip) {
        echo json_encode(['success' => false, 'message' => 'Equipment not found.']);
        return;
    }

    $eqGymId = (int)$equip['gym_id'];

    if ($equip['status'] === 'maintenance' || $equip['status'] === 'out_of_service') {
        echo json_encode(['success' => false, 'message' => 'Cannot join queue: equipment is currently unavailable.']);
        return;
    }

    // Check if member is already using this equipment
    $isUsing = scalar("SELECT session_id FROM equipment_sessions WHERE user_id = ? AND equipment_id = ? AND session_status = 'active'", [$userId, $equipmentId]);
    if ($isUsing) {
        echo json_encode(['success' => false, 'message' => 'You are currently using this equipment.']);
        return;
    }

    // Check if member is already queued for this equipment
    $alreadyQueued = scalar("SELECT queue_id FROM equipment_queues WHERE user_id = ? AND equipment_id = ? AND queue_status IN ('waiting', 'notified')", [$userId, $equipmentId]);
    if ($alreadyQueued) {
        echo json_encode(['success' => false, 'message' => 'You are already waiting in the queue for this equipment.']);
        return;
    }

    // Determine position
    $currentMax = (int)scalar("SELECT COALESCE(MAX(queue_position), 0) FROM equipment_queues WHERE equipment_id = ? AND queue_status IN ('waiting', 'notified')", [$equipmentId]);
    $newPosition = $currentMax + 1;

    $insertQueue = $pdo->prepare("INSERT INTO equipment_queues (gym_id, equipment_id, user_id, queue_position, queue_status, joined_at) VALUES (?, ?, ?, ?, 'waiting', NOW())");
    $insertQueue->execute([$eqGymId, $equipmentId, $userId, $newPosition]);
    $queueId = (int)$pdo->lastInsertId();

    $minWait = max(5, ($newPosition - 1) * 15);
    $maxWait = max(10, $newPosition * 20);
    $estimatedWait = "Approximately {$minWait}–{$maxWait} minutes";

    echo json_encode([
        'success' => true,
        'queue_id' => $queueId,
        'position' => $newPosition,
        'estimated_wait' => $estimatedWait,
        'message' => "You have been added to the queue at position #{$newPosition}."
    ]);
}

function handle_leave_queue(PDO $pdo, int $gymId, array $user): void
{
    $userId = (int)$user['user_id'];
    $queueId = (int)($_POST['queue_id'] ?? 0);
    $equipmentId = (int)($_POST['equipment_id'] ?? 0);

    if ($queueId > 0) {
        $qStmt = $pdo->prepare("SELECT * FROM equipment_queues WHERE queue_id = ?");
        $qStmt->execute([$queueId]);
    } else {
        $qStmt = $pdo->prepare("SELECT * FROM equipment_queues WHERE user_id = ? AND equipment_id = ? AND queue_status IN ('waiting', 'notified') LIMIT 1");
        $qStmt->execute([$userId, $equipmentId]);
    }
    $queueRecord = $qStmt->fetch(PDO::FETCH_ASSOC);

    if (!$queueRecord) {
        echo json_encode(['success' => false, 'message' => 'Queue entry not found.']);
        return;
    }

    $isAdmin = in_array($user['role'], ['gym_owner', 'admin', 'platform_admin'], true);
    if ((int)$queueRecord['user_id'] !== $userId && !$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        return;
    }

    $qId = (int)$queueRecord['queue_id'];
    $eqId = (int)$queueRecord['equipment_id'];
    $qGymId = (int)$queueRecord['gym_id'];
    $wasNotified = ($queueRecord['queue_status'] === 'notified');

    // Cancel this queue entry
    $pdo->prepare("UPDATE equipment_queues SET queue_status = 'cancelled', resolved_at = NOW() WHERE queue_id = ?")->execute([$qId]);

    // If this user was notified/next in line, immediately pass reservation to next person
    if ($wasNotified) {
        process_next_in_queue($pdo, $qGymId, $eqId);
    } else {
        reorder_waiting_queues($pdo, $eqId);
    }

    echo json_encode([
        'success' => true,
        'message' => 'You have left the equipment queue.'
    ]);
}

function handle_claim_session(PDO $pdo, int $gymId, array $user): void
{
    $userId = (int)$user['user_id'];
    $equipmentId = (int)($_POST['equipment_id'] ?? 0);

    // Verify user is notified for this equipment
    $qStmt = $pdo->prepare("SELECT * FROM equipment_queues WHERE user_id = ? AND equipment_id = ? AND queue_status = 'notified' AND claim_deadline >= NOW() LIMIT 1");
    $qStmt->execute([$userId, $equipmentId]);
    $queueRecord = $qStmt->fetch(PDO::FETCH_ASSOC);

    if (!$queueRecord) {
        echo json_encode(['success' => false, 'message' => 'No active claim window found for this equipment, or the 2-minute window has expired.']);
        return;
    }

    // Now call start session logic
    handle_start_session($pdo, (int)$queueRecord['gym_id'], $user);
}

// -------------------------------------------------------------
// ADMIN HANDLERS
// -------------------------------------------------------------

function handle_admin_save(PDO $pdo, int $gymId, array $user): void
{
    $equipmentId = (int)($_POST['equipment_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $category = trim((string)($_POST['category'] ?? 'Machines'));
    $location = trim((string)($_POST['location_area'] ?? 'Main Gym Floor'));
    $condition = trim((string)($_POST['equipment_condition'] ?? 'Good'));
    $description = trim((string)($_POST['description'] ?? ''));
    $imageUrl = trim((string)($_POST['image_url'] ?? ''));
    $unitNumber = trim((string)($_POST['unit_number'] ?? '#1'));
    $quantity = max(1, (int)($_POST['quantity'] ?? 1));
    $nextMaintenance = !empty($_POST['next_maintenance_date']) ? $_POST['next_maintenance_date'] : null;

    if ($name === '') {
        echo json_encode(['success' => false, 'message' => 'Equipment name is required.']);
        return;
    }

    $validCategories = ['Cardio', 'Strength', 'Free Weights', 'Machines', 'Functional Training', 'Other'];
    if (!in_array($category, $validCategories, true)) {
        $category = 'Machines';
    }

    $validConditions = ['Excellent', 'Good', 'Fair', 'Poor'];
    if (!in_array($condition, $validConditions, true)) {
        $condition = 'Good';
    }

    // Handle Image Upload via ImageKit (5MB limit, folder: /equipment)
    if (!empty($_FILES['image_file']['tmp_name'])) {
        try {
            require_once __DIR__ . '/../../core/file_handler.php';
            $uploadedUrl = FileUpload::storeEquipmentImage($_FILES['image_file'], $gymId);
            if ($uploadedUrl) {
                $imageUrl = $uploadedUrl;
            }
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            return;
        }
    }

    if ($equipmentId > 0) {
        // Edit existing equipment unit
        $stmt = $pdo->prepare("SELECT * FROM gym_equipment WHERE equipment_id = ?");
        $stmt->execute([$equipmentId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            echo json_encode(['success' => false, 'message' => 'Equipment not found.']);
            return;
        }

        $eqGymId = (int)$existing['gym_id'];
        if ($user['role'] !== 'platform_admin' && $gymId !== $eqGymId) {
            $isOwner = (bool)scalar("SELECT 1 FROM gyms WHERE gym_id = ? AND owner_user_id = ?", [$eqGymId, $user['user_id']]);
            if (!$isOwner) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized to modify equipment of this gym.']);
                return;
            }
        }

        // If no new image was uploaded and image_url is empty in POST, check if we should preserve existing or update
        if (empty($_FILES['image_file']['tmp_name']) && !isset($_POST['image_url'])) {
            $imageUrl = $existing['image_url'];
        }

        $updateStmt = $pdo->prepare("
            UPDATE gym_equipment
            SET name = ?, unit_number = ?, category = ?, location_area = ?, description = ?,
                equipment_condition = ?, image_url = ?, next_maintenance_date = ?
            WHERE equipment_id = ?
        ");
        $updateStmt->execute([$name, $unitNumber, $category, $location, $description, $condition, $imageUrl ?: null, $nextMaintenance, $equipmentId]);

        echo json_encode(['success' => true, 'message' => 'Equipment details updated successfully.', 'image_url' => $imageUrl]);
        return;
    }

    // Creating new equipment unit(s)
    if ($quantity === 1) {
        // Single unit
        $insertStmt = $pdo->prepare("
            INSERT INTO gym_equipment (gym_id, name, unit_number, category, location_area, description, equipment_condition, status, image_url, date_added, next_maintenance_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'available', ?, CURDATE(), ?)
            ON DUPLICATE KEY UPDATE description = VALUES(description), location_area = VALUES(location_area), image_url = VALUES(image_url)
        ");
        $insertStmt->execute([$gymId, $name, $unitNumber, $category, $location, $description, $condition, $imageUrl ?: null, $nextMaintenance]);
    } else {
        // Bulk unit creation (e.g. Treadmill #1, Treadmill #2, ...)
        $insertStmt = $pdo->prepare("
            INSERT IGNORE INTO gym_equipment (gym_id, name, unit_number, category, location_area, description, equipment_condition, status, image_url, date_added, next_maintenance_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'available', ?, CURDATE(), ?)
        ");

        for ($i = 1; $i <= $quantity; $i++) {
            $unitLabel = '#' . $i;
            $insertStmt->execute([$gymId, $name, $unitLabel, $category, $location, $description, $condition, $imageUrl ?: null, $nextMaintenance]);
        }
    }

    echo json_encode(['success' => true, 'message' => "Equipment registered successfully ({$quantity} unit(s)).", 'image_url' => $imageUrl]);
}

function handle_admin_set_maintenance(PDO $pdo, int $gymId, array $user): void
{
    $equipmentId = (int)($_POST['equipment_id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? 'maintenance'));
    $reason = trim((string)($_POST['reason'] ?? 'Routine maintenance'));
    $expectedReturn = !empty($_POST['expected_return_date']) ? $_POST['expected_return_date'] : null;

    if (!in_array($status, ['maintenance', 'out_of_service'], true)) {
        $status = 'maintenance';
    }

    $stmt = $pdo->prepare("SELECT * FROM gym_equipment WHERE equipment_id = ?");
    $stmt->execute([$equipmentId]);
    $eq = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$eq) {
        echo json_encode(['success' => false, 'message' => 'Equipment not found.']);
        return;
    }

    $eqGymId = (int)$eq['gym_id'];
    if ($user['role'] !== 'platform_admin' && $gymId !== $eqGymId) {
        $isOwner = (bool)scalar("SELECT 1 FROM gyms WHERE gym_id = ? AND owner_user_id = ?", [$eqGymId, $user['user_id']]);
        if (!$isOwner) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized to modify equipment of this gym.']);
            return;
        }
    }

    // 1. Update equipment status
    $pdo->prepare("
        UPDATE gym_equipment
        SET status = ?, maintenance_reason = ?, expected_return_date = ?, last_maintenance_date = CURDATE(), current_session_id = NULL
        WHERE equipment_id = ?
    ")->execute([$status, $reason, $expectedReturn, $equipmentId]);

    // 2. Log maintenance action
    $pdo->prepare("
        INSERT INTO equipment_maintenance_logs (equipment_id, gym_id, logged_by_user_id, action, reason, expected_return_date)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$equipmentId, $eqGymId, $user['user_id'], $status, $reason, $expectedReturn]);

    // 3. Complete any active session
    $pdo->prepare("
        UPDATE equipment_sessions
        SET end_time = NOW(), session_status = 'cancelled', duration_seconds = TIMESTAMPDIFF(SECOND, start_time, NOW())
        WHERE equipment_id = ? AND session_status = 'active'
    ")->execute([$equipmentId]);

    // 4. Cancel active queues and notify waiting members
    $qStmt = $pdo->prepare("SELECT queue_id, user_id FROM equipment_queues WHERE equipment_id = ? AND queue_status IN ('waiting', 'notified')");
    $qStmt->execute([$equipmentId]);
    $queuedMembers = $qStmt->fetchAll(PDO::FETCH_ASSOC);

    $pdo->prepare("UPDATE equipment_queues SET queue_status = 'cancelled', resolved_at = NOW() WHERE equipment_id = ? AND queue_status IN ('waiting', 'notified')")->execute([$equipmentId]);

    $equipName = $eq['name'] . ' ' . $eq['unit_number'];
    foreach ($queuedMembers as $qm) {
        if (function_exists('notify_user')) {
            notify_user((int)$qm['user_id'], 'system', 'Equipment Maintenance', "{$equipName} has been placed under maintenance ({$reason}). Your queue request has been cancelled.", $equipmentId);
        }
    }

    echo json_encode(['success' => true, 'message' => "{$equipName} marked as {$status}."]);
}

function handle_admin_restore_available(PDO $pdo, int $gymId, array $user): void
{
    $equipmentId = (int)($_POST['equipment_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM gym_equipment WHERE equipment_id = ?");
    $stmt->execute([$equipmentId]);
    $eq = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$eq) {
        echo json_encode(['success' => false, 'message' => 'Equipment not found.']);
        return;
    }

    $eqGymId = (int)$eq['gym_id'];
    if ($user['role'] !== 'platform_admin' && $gymId !== $eqGymId) {
        $isOwner = (bool)scalar("SELECT 1 FROM gyms WHERE gym_id = ? AND owner_user_id = ?", [$eqGymId, $user['user_id']]);
        if (!$isOwner) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized to modify equipment of this gym.']);
            return;
        }
    }

    $pdo->prepare("
        UPDATE gym_equipment
        SET status = 'available', maintenance_reason = NULL, expected_return_date = NULL
        WHERE equipment_id = ?
    ")->execute([$equipmentId]);

    // Log action
    $pdo->prepare("
        INSERT INTO equipment_maintenance_logs (equipment_id, gym_id, logged_by_user_id, action, reason)
        VALUES (?, ?, ?, 'restored_available', 'Restored to service')
    ")->execute([$equipmentId, $eqGymId, $user['user_id']]);

    echo json_encode(['success' => true, 'message' => "{$eq['name']} ({$eq['unit_number']}) is now marked Available."]);
}

function handle_admin_delete(PDO $pdo, int $gymId, array $user): void
{
    $equipmentId = (int)($_POST['equipment_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM gym_equipment WHERE equipment_id = ?");
    $stmt->execute([$equipmentId]);
    $eq = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$eq) {
        echo json_encode(['success' => false, 'message' => 'Equipment not found.']);
        return;
    }

    $eqGymId = (int)$eq['gym_id'];
    if ($user['role'] !== 'platform_admin' && $gymId !== $eqGymId) {
        $isOwner = (bool)scalar("SELECT 1 FROM gyms WHERE gym_id = ? AND owner_user_id = ?", [$eqGymId, $user['user_id']]);
        if (!$isOwner) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized to delete equipment of this gym.']);
            return;
        }
    }

    // Cancel active sessions & queues
    $pdo->prepare("UPDATE equipment_sessions SET session_status = 'cancelled', end_time = NOW() WHERE equipment_id = ? AND session_status = 'active'")->execute([$equipmentId]);
    $pdo->prepare("UPDATE equipment_queues SET queue_status = 'cancelled', resolved_at = NOW() WHERE equipment_id = ? AND queue_status IN ('waiting', 'notified')")->execute([$equipmentId]);

    // Delete equipment record
    $pdo->prepare("DELETE FROM gym_equipment WHERE equipment_id = ?")->execute([$equipmentId]);

    echo json_encode(['success' => true, 'message' => "{$eq['name']} ({$eq['unit_number']}) removed from inventory."]);
}

function handle_admin_queue_details(PDO $pdo, int $gymId): void
{
    $equipmentId = (int)($_GET['equipment_id'] ?? 0);

    // Fetch equipment details
    $eqStmt = $pdo->prepare("SELECT equipment_id, gym_id, name, unit_number, status, maintenance_reason FROM gym_equipment WHERE equipment_id = ?");
    $eqStmt->execute([$equipmentId]);
    $eq = $eqStmt->fetch(PDO::FETCH_ASSOC);

    $qStmt = $pdo->prepare("
        SELECT q.*, u.first_name, u.last_name, u.email,
               TIMESTAMPDIFF(MINUTE, q.joined_at, NOW()) as minutes_waiting,
               TIMESTAMPDIFF(SECOND, NOW(), q.claim_deadline) as claim_seconds_left
        FROM equipment_queues q
        JOIN users u ON u.user_id = q.user_id
        WHERE q.equipment_id = ? AND q.queue_status IN ('waiting', 'notified')
        ORDER BY q.queue_position ASC, q.joined_at ASC
    ");
    $qStmt->execute([$equipmentId]);
    $queue = $qStmt->fetchAll(PDO::FETCH_ASSOC);

    // Active session user info
    $sessStmt = $pdo->prepare("
        SELECT s.*, u.first_name, u.last_name, u.email,
               TIMESTAMPDIFF(SECOND, s.start_time, NOW()) as elapsed_seconds
        FROM equipment_sessions s
        JOIN users u ON u.user_id = s.user_id
        WHERE s.equipment_id = ? AND s.session_status = 'active'
        LIMIT 1
    ");
    $sessStmt->execute([$equipmentId]);
    $activeSession = $sessStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    echo json_encode([
        'success' => true,
        'equipment' => $eq,
        'active_session' => $activeSession,
        'queue' => $queue
    ]);
}

function handle_admin_force_finish(PDO $pdo, int $gymId, array $user): void
{
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $equipmentId = (int)($_POST['equipment_id'] ?? 0);

    if ($sessionId <= 0 && $equipmentId > 0) {
        $sessionId = (int)scalar("SELECT session_id FROM equipment_sessions WHERE equipment_id = ? AND gym_id = ? AND session_status = 'active' LIMIT 1", [$equipmentId, $gymId]);
    }

    if ($sessionId <= 0) {
        echo json_encode(['success' => false, 'message' => 'No active session found to terminate.']);
        return;
    }

    // Call standard finish_session
    $_POST['session_id'] = $sessionId;
    handle_finish_session($pdo, $gymId, $user);
}

function handle_admin_remove_queue(PDO $pdo, int $gymId, array $user): void
{
    $queueId = (int)($_POST['queue_id'] ?? 0);
    if ($queueId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid queue entry.']);
        return;
    }

    $_POST['queue_id'] = $queueId;
    handle_leave_queue($pdo, $gymId, $user);
}
