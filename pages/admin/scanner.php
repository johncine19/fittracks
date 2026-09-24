<?php
declare(strict_types=1);

function scanner_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner', 'trainer']);
    
    $currentGymId = null;
    if ($user['role'] === 'gym_owner') {
        $currentGymId = scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
    } elseif ($user['role'] === 'trainer') {
        $currentGymId = scalar('SELECT gym_id FROM trainer_profiles WHERE user_id = ?', [$user['user_id']]);
    }

    if (!$currentGymId && $user['role'] !== 'platform_admin') {
        flash('You are not associated with any gym. Please contact an administrator.', 'danger');
        redirect('dashboard');
    }

    $pdo = db();

    // Helper: format duration in human readable string
    $formatDuration = function (?string $checkInTime, ?string $checkOutTime): string {
        if (!$checkInTime) return '';
        $start = new DateTime($checkInTime);
        $end = $checkOutTime ? new DateTime($checkOutTime) : new DateTime();
        $diff = $start->diff($end);
        $parts = [];
        if ($diff->h > 0) $parts[] = $diff->h . ' hr' . ($diff->h > 1 ? 's' : '');
        $parts[] = max(1, $diff->i) . ' min' . ($diff->i > 1 ? 's' : '');
        return implode(' ', $parts);
    };

    // Helper: fetch current live stats
    $getStats = function () use ($pdo, $currentGymId) {
        $todayParams = [];
        $todaySql = 'SELECT COUNT(*) FROM attendance WHERE DATE(check_in_time) = CURDATE()';
        if ($currentGymId) {
            $todaySql .= ' AND gym_id = ?';
            $todayParams[] = $currentGymId;
        }
        $stmt = $pdo->prepare($todaySql);
        $stmt->execute($todayParams);
        $todayCheckins = (int) $stmt->fetchColumn();

        $insideParams = [];
        $insideSql = 'SELECT COUNT(*) FROM attendance WHERE DATE(check_in_time) = CURDATE() AND check_out_time IS NULL';
        if ($currentGymId) {
            $insideSql .= ' AND gym_id = ?';
            $insideParams[] = $currentGymId;
        }
        $stmt2 = $pdo->prepare($insideSql);
        $stmt2->execute($insideParams);
        $currentlyInside = (int) $stmt2->fetchColumn();

        return [
            'today_checkins' => $todayCheckins,
            'currently_inside' => $currentlyInside
        ];
    };

    // Helper: fetch recent attendance activity list
    $getRecentActivity = function (int $limit = 10) use ($pdo, $currentGymId, $formatDuration) {
        $sql = 'SELECT a.attendance_id, a.user_id, a.gym_id, a.check_in_time, a.check_out_time, a.check_in_method,
                       u.first_name, u.last_name, u.role, u.profile_picture,
                       c.class_name
                FROM attendance a
                JOIN users u ON u.user_id = a.user_id
                LEFT JOIN class_schedules s ON s.schedule_id = a.schedule_id
                LEFT JOIN classes c ON c.class_id = s.class_id
                WHERE DATE(a.check_in_time) = CURDATE()';
        $params = [];
        if ($currentGymId) {
            $sql .= ' AND a.gym_id = ?';
            $params[] = $currentGymId;
        }
        $sql .= ' ORDER BY COALESCE(a.check_out_time, a.check_in_time) DESC LIMIT ' . (int)$limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map(function ($r) use ($formatDuration) {
            $isCheckOut = !empty($r['check_out_time']);
            $eventTime = $isCheckOut ? $r['check_out_time'] : $r['check_in_time'];
            $timestamp = (new DateTime($eventTime))->format('h:i A');
            return [
                'attendance_id' => (int) $r['attendance_id'],
                'user_id' => (int) $r['user_id'],
                'name' => trim($r['first_name'] . ' ' . $r['last_name']),
                'role' => ucfirst($r['role']),
                'avatar_html' => render_avatar($r, 'small'),
                'is_checkout' => $isCheckOut,
                'status_label' => $isCheckOut ? 'OUT' : 'IN',
                'time_formatted' => $timestamp,
                'method' => $r['check_in_method'] === 'qr_code' ? 'QR Scan' : 'Manual',
                'class_name' => $r['class_name'] ?? null,
                'duration' => $isCheckOut ? $formatDuration($r['check_in_time'], $r['check_out_time']) : null
            ];
        }, $rows);
    };

    // AJAX: Live activity polling
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_activity') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'stats' => $getStats(),
            'recent' => $getRecentActivity(15)
        ]);
        exit;
    }

    // AJAX: Search members for manual check-in
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'search_members') {
        header('Content-Type: application/json');
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) < 1) {
            echo json_encode(['success' => true, 'members' => []]);
            exit;
        }

        $searchSql = '
            SELECT u.user_id, u.first_name, u.last_name, u.role, u.email, u.phone, u.profile_picture,
                   (SELECT attendance_id FROM attendance WHERE user_id = u.user_id AND check_out_time IS NULL ORDER BY check_in_time DESC LIMIT 1) as active_attendance_id,
                   (SELECT check_in_time FROM attendance WHERE user_id = u.user_id AND check_out_time IS NULL ORDER BY check_in_time DESC LIMIT 1) as active_check_in_time
            FROM users u
            WHERE u.status = "active" AND u.role IN ("member", "trainer")
              AND (u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, " ", u.last_name) LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)
            LIMIT 15
        ';
        $term = '%' . $q . '%';
        $stmt = $pdo->prepare($searchSql);
        $stmt->execute([$term, $term, $term, $term, $term]);
        $matches = $stmt->fetchAll();

        $results = array_map(function ($m) use ($formatDuration) {
            $isInside = !empty($m['active_attendance_id']);
            return [
                'user_id' => (int) $m['user_id'],
                'name' => trim($m['first_name'] . ' ' . $m['last_name']),
                'role' => ucfirst($m['role']),
                'email' => $m['email'],
                'phone' => $m['phone'] ?: 'N/A',
                'avatar_html' => render_avatar($m, 'small'),
                'is_inside' => $isInside,
                'active_attendance_id' => $m['active_attendance_id'],
                'duration' => $isInside ? $formatDuration($m['active_check_in_time'], null) : null
            ];
        }, $matches);

        echo json_encode(['success' => true, 'members' => $results]);
        exit;
    }

    // AJAX: Manual check-in / check-out
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'manual_checkin') {
        header('Content-Type: application/json');
        $targetUserId = (int) post('user_id');
        if (!$targetUserId) {
            echo json_encode(['success' => false, 'message' => 'Invalid user ID.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT user_id, first_name, last_name, role, profile_picture FROM users WHERE user_id = ? AND status = "active"');
        $stmt->execute([$targetUserId]);
        $member = $stmt->fetch();
        if (!$member) {
            echo json_encode(['success' => false, 'message' => 'Active member not found.']);
            exit;
        }

        // Check for open attendance record
        $stmt = $pdo->prepare('SELECT attendance_id, check_in_time FROM attendance WHERE user_id = ? AND check_out_time IS NULL ORDER BY check_in_time DESC LIMIT 1');
        $stmt->execute([$targetUserId]);
        $openRecord = $stmt->fetch();

        $actionType = 'checkin';
        $attendedClassTitle = null;
        $sessionDuration = null;

        if ($openRecord) {
            // Check-out
            $pdo->prepare('UPDATE attendance SET check_out_time = NOW() WHERE attendance_id = ?')->execute([$openRecord['attendance_id']]);
            audit_log($user['user_id'], 'manual_checkout', 'attendance', (string) $openRecord['attendance_id'], json_encode(['user_id' => $targetUserId]));
            $actionType = 'checkout';
            $sessionDuration = $formatDuration($openRecord['check_in_time'], date('Y-m-d H:i:s'));
            $message = 'Check-out recorded for ' . $member['first_name'] . ' ' . $member['last_name'];
        } else {
            // Check-in
            $stmtClass = $pdo->prepare('
                SELECT b.schedule_id, c.class_name
                FROM class_bookings b
                JOIN class_schedules s ON b.schedule_id = s.schedule_id
                JOIN classes c ON c.class_id = s.class_id
                WHERE b.user_id = ? AND b.booking_status = "booked"
                  AND s.start_datetime >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                  AND s.start_datetime <= DATE_ADD(NOW(), INTERVAL 1 HOUR)
                ORDER BY s.start_datetime ASC LIMIT 1
            ');
            $stmtClass->execute([$targetUserId]);
            $bookedClass = $stmtClass->fetch();
            $scheduleId = $bookedClass ? $bookedClass['schedule_id'] : null;
            $attendedClassTitle = $bookedClass ? $bookedClass['class_name'] : null;

            $stmt = $pdo->prepare('INSERT INTO attendance (user_id, schedule_id, gym_id, check_in_time, check_in_method, recorded_by) VALUES (?, ?, ?, NOW(), "manual", ?)');
            $stmt->execute([$targetUserId, $scheduleId, $currentGymId, $user['user_id']]);
            $newAttendanceId = (int) $pdo->lastInsertId();

            if ($member['role'] === 'member' && $currentGymId) {
                $pdo->prepare('INSERT IGNORE INTO gym_members (user_id, gym_id) VALUES (?, ?)')->execute([$targetUserId, $currentGymId]);
            }

            if ($scheduleId) {
                $pdo->prepare('UPDATE class_bookings SET booking_status = "attended" WHERE user_id = ? AND schedule_id = ?')->execute([$targetUserId, $scheduleId]);
                $message = 'Check-in recorded & Class attended for ' . $member['first_name'] . ' ' . $member['last_name'];
            } else {
                $message = 'Check-in recorded for ' . $member['first_name'] . ' ' . $member['last_name'];
            }
            audit_log($user['user_id'], 'manual_checkin', 'attendance', (string) $newAttendanceId, json_encode(['user_id' => $targetUserId, 'schedule_id' => $scheduleId]));
        }

        $planName = null;
        if ($member['role'] === 'member') {
            $planName = scalar('
                SELECT mp.plan_name 
                FROM memberships m 
                JOIN membership_plans mp ON m.plan_id = mp.plan_id 
                WHERE m.user_id = ? AND m.status = "active" AND m.end_date >= CURDATE()
                ORDER BY m.end_date DESC LIMIT 1',
                [$targetUserId]
            );
        }

        echo json_encode([
            'success' => true,
            'action_type' => $actionType,
            'message' => $message,
            'member' => [
                'user_id' => $targetUserId,
                'name' => trim($member['first_name'] . ' ' . $member['last_name']),
                'role' => ucfirst($member['role']),
                'avatar_html' => render_avatar($member, 'medium'),
                'plan_name' => $planName ?: ($member['role'] === 'trainer' ? 'Certified Trainer' : 'General Walk-in'),
                'class_name' => $attendedClassTitle,
                'duration' => $sessionDuration
            ],
            'time' => date('h:i:s A'),
            'stats' => $getStats(),
            'recent' => $getRecentActivity(15)
        ]);
        exit;
    }

    // Handle AJAX check-in request from QR Scanner
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'process_qr') {
        header('Content-Type: application/json');
        
        $qrData = post('qr_data');
        if (!$qrData || !str_contains((string) $qrData, ':')) {
            echo json_encode(['success' => false, 'message' => 'Invalid QR Code format. Please rescan.']);
            exit;
        }
        
        list($userId, $token) = explode(':', (string) $qrData, 2);
        
        $stmt = $pdo->prepare('SELECT user_id, first_name, last_name, role, profile_picture, qr_token, qr_expires_at FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $member = $stmt->fetch();
        
        if (!$member || $member['qr_token'] !== $token) {
            echo json_encode(['success' => false, 'message' => 'Invalid or expired QR token. Please ask member to generate a fresh QR code.']);
            exit;
        }
        
        if (new DateTime() > new DateTime($member['qr_expires_at'])) {
            echo json_encode(['success' => false, 'message' => 'QR code has expired. Dynamic tokens refresh every few minutes.']);
            exit;
        }
        
        // Check for open attendance record
        $stmt = $pdo->prepare('SELECT attendance_id, check_in_time FROM attendance WHERE user_id = ? AND check_out_time IS NULL ORDER BY check_in_time DESC LIMIT 1');
        $stmt->execute([$userId]);
        $openRecord = $stmt->fetch();

        $actionType = 'checkin';
        $attendedClassTitle = null;
        $sessionDuration = null;

        if ($openRecord) {
            // Check out
            $pdo->prepare('UPDATE attendance SET check_out_time = NOW() WHERE attendance_id = ?')->execute([$openRecord['attendance_id']]);
            audit_log($user['user_id'], 'qr_checkout', 'attendance', (string) $openRecord['attendance_id'], json_encode(['user_id' => $userId]));
            $actionType = 'checkout';
            $sessionDuration = $formatDuration($openRecord['check_in_time'], date('Y-m-d H:i:s'));
            $message = 'Check-out successful for ' . $member['first_name'] . ' ' . $member['last_name'];
        } else {
            // Check in
            if ($member['role'] === 'member') {
                $membership = $pdo->prepare('
                    SELECT m.membership_id, mp.gym_id, mp.plan_id
                    FROM memberships m
                    JOIN membership_plans mp ON m.plan_id = mp.plan_id
                    WHERE m.user_id = ? AND m.status = "active" AND m.end_date >= CURDATE()
                ');
                $membership->execute([$userId]);
                $activePlans = $membership->fetchAll();

                $hasValidPlan = false;
                if ($user['role'] === 'platform_admin') {
                    // Platform admin can scan anywhere
                    $hasValidPlan = count($activePlans) > 0;
                } else {
                    foreach ($activePlans as $plan) {
                        if ((int)$plan['gym_id'] === (int)$currentGymId) {
                            $hasValidPlan = true;
                            break;
                        }
                    }
                }

                if (!$hasValidPlan && !isset($_POST['amount_paid'])) {
                    $detectedFee = $currentGymId 
                        ? (float) (scalar('SELECT walk_in_fee FROM gyms WHERE gym_id = ?', [$currentGymId]) ?: 100.0)
                        : 100.0;

                    echo json_encode([
                        'success' => false,
                        'requires_payment' => true,
                        'walk_in_fee' => $detectedFee,
                        'qr_data' => $qrData,
                        'member_name' => $member['first_name'] . ' ' . $member['last_name'],
                        'message' => 'No active membership covers this gym. Please collect walk-in payment to proceed.'
                    ]);
                    exit;
                }
            }
            
            // Check if member has a booked class starting soon (within +/- 1 hour)
            $classBooking = $pdo->prepare('
                SELECT b.schedule_id, c.class_name
                FROM class_bookings b
                JOIN class_schedules s ON b.schedule_id = s.schedule_id
                JOIN classes c ON c.class_id = s.class_id
                WHERE b.user_id = ? AND b.booking_status = "booked"
                  AND s.start_datetime >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                  AND s.start_datetime <= DATE_ADD(NOW(), INTERVAL 1 HOUR)
                ORDER BY s.start_datetime ASC LIMIT 1
            ');
            $classBooking->execute([$userId]);
            $bookedClass = $classBooking->fetch();
            $scheduleId = $bookedClass ? $bookedClass['schedule_id'] : null;
            $attendedClassTitle = $bookedClass ? $bookedClass['class_name'] : null;

            $stmt = $pdo->prepare('INSERT INTO attendance (user_id, schedule_id, gym_id, check_in_time, check_in_method, recorded_by) VALUES (?, ?, ?, NOW(), "qr_code", ?)');
            $stmt->execute([$userId, $scheduleId, $currentGymId, $user['user_id']]);
            $newAttendanceId = (int) $pdo->lastInsertId();
            
            if ($member['role'] === 'member' && $currentGymId) {
                $pdo->prepare('INSERT IGNORE INTO gym_members (user_id, gym_id) VALUES (?, ?)')
                    ->execute([$userId, $currentGymId]);
            }
            
            if ($scheduleId) {
                $pdo->prepare('UPDATE class_bookings SET booking_status = "attended" WHERE user_id = ? AND schedule_id = ?')->execute([$userId, $scheduleId]);
                $message = 'Check-in verified & Class Auto-Attended for ' . $member['first_name'] . ' ' . $member['last_name'];
            } else {
                $message = 'Check-in verified for ' . $member['first_name'] . ' ' . $member['last_name'];
            }
            
            $amount = (float) (post('amount_paid') ?: 0);
            if ($amount > 0) {
                $guestName = $member['first_name'] . ' ' . $member['last_name'];
                $contactInfo = scalar('SELECT phone FROM users WHERE user_id = ?', [$userId]) ?: 'N/A';
                $pdo->prepare('INSERT INTO walk_in_transactions (gym_id, guest_name, contact_info, amount_paid, payment_method, visit_date, processed_by, converted_to_member_id) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)')
                    ->execute([$currentGymId, $guestName, $contactInfo, $amount, post('payment_method') ?: 'cash', $user['user_id'], $userId]);
            }
            audit_log($user['user_id'], 'qr_checkin', 'attendance', (string) $newAttendanceId, json_encode(['user_id' => $userId, 'schedule_id' => $scheduleId]));
        }
        
        // Invalidate token after single use
        $pdo->prepare('UPDATE users SET qr_token = NULL, qr_expires_at = NULL WHERE user_id = ?')->execute([$userId]);

        $planName = null;
        if ($member['role'] === 'member') {
            $planName = scalar('
                SELECT mp.plan_name 
                FROM memberships m 
                JOIN membership_plans mp ON m.plan_id = mp.plan_id 
                WHERE m.user_id = ? AND m.status = "active" AND m.end_date >= CURDATE()
                ORDER BY m.end_date DESC LIMIT 1',
                [$userId]
            );
        }
        
        echo json_encode([
            'success' => true,
            'action_type' => $actionType,
            'message' => $message,
            'member' => [
                'user_id' => $userId,
                'name' => trim($member['first_name'] . ' ' . $member['last_name']),
                'role' => ucfirst($member['role']),
                'avatar_html' => render_avatar($member, 'medium'),
                'plan_name' => $planName ?: ($member['role'] === 'trainer' ? 'Certified Trainer' : 'Walk-in / Guest Pass'),
                'class_name' => $attendedClassTitle,
                'duration' => $sessionDuration
            ],
            'time' => date('h:i:s A'),
            'stats' => $getStats(),
            'recent' => $getRecentActivity(15)
        ]);
        exit;
    }

    $initialStats = $getStats();
    $initialActivity = $getRecentActivity(15);
    $currentGymWalkInFee = $currentGymId 
        ? (float) (scalar('SELECT walk_in_fee FROM gyms WHERE gym_id = ?', [$currentGymId]) ?: 100.0)
        : 100.0;

    render_header('QR Scanner', $user);
    ?>

    <style>
    /* -------------------------------------------------------------
       FitTracks High-Tech Scanner Terminal Theme & Styles
       ------------------------------------------------------------- */
    .scanner-page-wrap {
        max-width: 1400px;
        margin: 0 auto;
        padding-bottom: 40px;
    }

    /* Terminal Header Bar */
    .terminal-top-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 16px;
        padding: 16px 24px;
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 16px;
        margin-bottom: 24px;
        backdrop-filter: blur(12px);
    }
    .terminal-title-group h1 {
        font-size: 1.5rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        margin: 0 0 4px 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .terminal-title-group p {
        margin: 0;
        color: var(--muted);
        font-size: 0.88rem;
    }
    .terminal-hud-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        background: color-mix(in srgb, var(--lime) 12%, transparent);
        border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
        color: var(--lime);
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }
    .pulse-dot {
        width: 8px;
        height: 8px;
        background: var(--lime);
        border-radius: 50%;
        box-shadow: 0 0 10px var(--lime);
        animation: pulseAnimation 1.8s infinite;
    }
    @keyframes pulseAnimation {
        0% { transform: scale(0.9); opacity: 0.7; box-shadow: 0 0 0 0 color-mix(in srgb, var(--lime) 70%, transparent); }
        70% { transform: scale(1.1); opacity: 1; box-shadow: 0 0 0 8px transparent; }
        100% { transform: scale(0.9); opacity: 0.7; box-shadow: 0 0 0 0 transparent; }
    }
    .terminal-clock-box {
        display: flex;
        align-items: center;
        gap: 12px;
        font-variant-numeric: tabular-nums;
    }
    .terminal-digital-time {
        font-size: 1.25rem;
        font-weight: 800;
        color: var(--ink);
        letter-spacing: 0.04em;
    }
    .terminal-digital-date {
        font-size: 0.8rem;
        color: var(--muted);
        font-weight: 500;
    }
    .terminal-header-actions {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .term-btn-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        height: 38px;
        padding: 0 14px;
        border-radius: 10px;
        background: var(--panel-soft);
        color: var(--ink);
        border: 1px solid var(--line);
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .term-btn-icon:hover {
        background: color-mix(in srgb, var(--lime) 15%, transparent);
        border-color: var(--lime);
        color: var(--lime);
        transform: translateY(-1px);
    }
    .term-btn-icon.active {
        background: var(--lime);
        color: #05070a !important;
        border-color: var(--lime);
    }

    /* Sound Toggle Alignment */
    .btn-sound-toggle,
    .term-btn-icon.btn-sound-toggle {
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 7px !important;
    }
    .sound-toggle-icon {
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        line-height: 1 !important;
        flex-shrink: 0 !important;
    }
    .sound-toggle-icon svg {
        display: block !important;
        width: 15px !important;
        height: 15px !important;
    }
    .sound-toggle-text {
        display: inline-flex !important;
        align-items: center !important;
        line-height: 1 !important;
    }

    /* Main Grid Layout */
    .terminal-grid {
        display: grid;
        grid-template-columns: 1.15fr 0.85fr;
        gap: 24px;
        align-items: start;
    }
    @media (max-width: 1024px) {
        .terminal-grid {
            grid-template-columns: 1fr;
        }
    }

    /* Left Card: Optical Scanner Terminal */
    .scanner-terminal-card {
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 20px;
        padding: 24px;
        backdrop-filter: blur(14px);
        position: relative;
        overflow: hidden;
    }
    .scanner-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }
    .scanner-camera-select-wrap {
        display: flex;
        align-items: center;
        gap: 8px;
        flex: 1;
        min-width: 200px;
    }
    .scanner-select {
        flex: 1;
        height: 38px;
        background: var(--panel-soft);
        border: 1px solid var(--line);
        color: var(--ink);
        border-radius: 10px;
        padding: 0 12px;
        font-size: 0.85rem;
        outline: none;
        cursor: pointer;
        transition: border-color 0.2s;
    }
    .scanner-select:focus {
        border-color: var(--lime);
    }
    .scanner-toolbar-tools {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    /* Viewfinder Container */
    .viewfinder-wrapper {
        position: relative;
        width: 100%;
        max-width: 540px;
        margin: 0 auto;
        aspect-ratio: 1 / 1;
        border-radius: 24px;
        overflow: hidden;
        background: #030508;
        border: 2px solid color-mix(in srgb, var(--lime) 25%, transparent);
        box-shadow: 0 16px 40px rgba(0, 0, 0, 0.45), inset 0 0 40px rgba(0, 0, 0, 0.6);
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }
    .viewfinder-wrapper.scanning {
        border-color: color-mix(in srgb, var(--lime) 75%, transparent);
        box-shadow: 0 0 35px color-mix(in srgb, var(--lime) 20%, transparent), inset 0 0 20px rgba(0,0,0,0.8);
    }
    .viewfinder-wrapper.flash-success {
        border-color: #22c55e !important;
        box-shadow: 0 0 45px rgba(34, 197, 94, 0.6) !important;
    }
    .viewfinder-wrapper.flash-error {
        border-color: var(--danger) !important;
        box-shadow: 0 0 45px color-mix(in srgb, var(--danger) 50%, transparent) !important;
    }

    /* Underneath HTML5-QRCODE Reader element */
    #reader-video-container {
        width: 100% !important;
        height: 100% !important;
        position: absolute;
        inset: 0;
        z-index: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #030508;
    }
    #reader-video-container video {
        width: 100% !important;
        height: 100% !important;
        object-fit: cover !important;
        border-radius: 22px;
    }
    /* Hide unstyled html5-qrcode injected items */
    #reader-video-container > div:not(video) {
        border: none !important;
    }

    /* Futuristic HUD Reticle Overlay */
    .viewfinder-overlay {
        position: absolute;
        inset: 0;
        z-index: 10;
        pointer-events: none;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .reticle-box {
        position: relative;
        width: 72%;
        height: 72%;
        border-radius: 18px;
    }
    /* Reticle 4-Corner Brackets */
    .reticle-corner {
        position: absolute;
        width: 32px;
        height: 32px;
        border-color: var(--lime);
        border-style: solid;
        border-width: 0;
        filter: drop-shadow(0 0 8px var(--lime));
        transition: all 0.3s ease;
    }
    .reticle-corner.top-left {
        top: -2px; left: -2px;
        border-top-width: 4px;
        border-left-width: 4px;
        border-top-left-radius: 14px;
    }
    .reticle-corner.top-right {
        top: -2px; right: -2px;
        border-top-width: 4px;
        border-right-width: 4px;
        border-top-right-radius: 14px;
    }
    .reticle-corner.bottom-left {
        bottom: -2px; left: -2px;
        border-bottom-width: 4px;
        border-left-width: 4px;
        border-bottom-left-radius: 14px;
    }
    .reticle-corner.bottom-right {
        bottom: -2px; right: -2px;
        border-bottom-width: 4px;
        border-right-width: 4px;
        border-bottom-right-radius: 14px;
    }

    /* Center Crosshair / QR Watermark */
    .reticle-center-guide {
        position: absolute;
        inset: 0;
        margin: auto;
        width: 60px;
        height: 60px;
        opacity: 0.22;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--lime);
    }

    /* Laser Scan Beam Animation */
    .scanner-laser {
        position: absolute;
        top: 0;
        left: 5%;
        width: 90%;
        height: 3px;
        background: linear-gradient(90deg, transparent 0%, var(--lime) 50%, transparent 100%);
        box-shadow: 0 0 14px 2px var(--lime), 0 0 28px var(--lime);
        border-radius: 999px;
        opacity: 0;
        display: none;
    }
    .viewfinder-wrapper.scanning .scanner-laser {
        display: block;
        opacity: 1;
        animation: laserScan 2.4s ease-in-out infinite alternate;
    }
    @keyframes laserScan {
        0% { top: 6%; opacity: 0.8; }
        50% { opacity: 1; filter: drop-shadow(0 0 10px var(--lime)); }
        100% { top: 92%; opacity: 0.8; }
    }

    /* Camera Live Badge on Viewfinder Top */
    .viewfinder-badge-top {
        position: absolute;
        top: 16px;
        left: 16px;
        z-index: 12;
        display: flex;
        align-items: center;
        gap: 6px;
        background: rgba(8, 12, 18, 0.75);
        backdrop-filter: blur(8px);
        border: 1px solid rgba(255, 255, 255, 0.12);
        padding: 5px 12px;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
        color: #f8fafc;
        letter-spacing: 0.05em;
        pointer-events: none;
    }
    .viewfinder-badge-top .badge-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--muted);
    }
    .viewfinder-badge-top.is-active .badge-dot {
        background: #22c55e;
        box-shadow: 0 0 8px #22c55e;
    }

    /* Pre-Launch / Standby Screen */
    .viewfinder-standby-screen {
        position: absolute;
        inset: 0;
        z-index: 15;
        background: radial-gradient(circle at center, rgba(19, 26, 38, 0.98) 0%, rgba(8, 11, 16, 0.99) 100%);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 24px;
        text-align: center;
        backdrop-filter: blur(10px);
        transition: opacity 0.3s ease;
    }
    .standby-icon-box {
        width: 76px;
        height: 76px;
        border-radius: 22px;
        background: color-mix(in srgb, var(--lime) 12%, transparent);
        border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
        color: var(--lime);
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 16px;
        box-shadow: 0 10px 24px color-mix(in srgb, var(--lime) 10%, transparent);
        animation: floatIcon 3.5s ease-in-out infinite alternate;
    }
    @keyframes floatIcon {
        0% { transform: translateY(0); }
        100% { transform: translateY(-6px); }
    }
    .standby-title {
        font-size: 1.2rem;
        font-weight: 800;
        color: #f8fafc;
        margin-bottom: 6px;
    }
    .standby-desc {
        font-size: 0.84rem;
        color: #94a3b8;
        max-width: 320px;
        line-height: 1.45;
        margin-bottom: 20px;
    }
    .btn-start-camera {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 12px 28px;
        background: var(--lime);
        color: #06090e !important;
        font-weight: 800;
        font-size: 0.95rem;
        border-radius: 12px;
        border: none;
        cursor: pointer;
        box-shadow: 0 6px 20px color-mix(in srgb, var(--lime) 35%, transparent);
        transition: all 0.2s ease;
    }
    .btn-start-camera:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 28px color-mix(in srgb, var(--lime) 50%, transparent);
    }
    .standby-file-drop {
        margin-top: 14px;
        font-size: 0.8rem;
        color: var(--muted);
    }
    .standby-file-link {
        color: var(--lime);
        text-decoration: underline;
        cursor: pointer;
        font-weight: 600;
    }

    /* Scanner Bottom Controls */
    .scanner-bottom-actions {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-top: 20px;
        flex-wrap: wrap;
    }
    .scanner-guide-text {
        font-size: 0.82rem;
        color: var(--muted);
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .btn-stop-scanner-style {
        color: var(--danger) !important;
        border-color: color-mix(in srgb, var(--danger) 35%, transparent) !important;
    }
    .btn-stop-scanner-style:hover {
        background: color-mix(in srgb, var(--danger) 15%, transparent) !important;
        border-color: var(--danger) !important;
        color: var(--danger) !important;
        transform: translateY(-1px);
    }
    [data-theme="light"] .btn-stop-scanner-style {
        color: #dc2626 !important;
        border-color: #fca5a5 !important;
        background: #fff5f5 !important;
    }
    [data-theme="light"] .btn-stop-scanner-style:hover {
        background: #fee2e2 !important;
        border-color: #ef4444 !important;
    }

    /* -------------------------------------------------------------
       Right Column: Live Verification HUD & Reception Stream
       ------------------------------------------------------------- */
    .terminal-sidebar-card {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    /* Top Live Stats Counters */
    .terminal-stats-row {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 14px;
    }
    .term-stat-box {
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 16px;
        padding: 16px;
        display: flex;
        align-items: center;
        gap: 14px;
        backdrop-filter: blur(10px);
        transition: transform 0.2s;
    }
    .term-stat-box:hover {
        transform: translateY(-2px);
    }
    .term-stat-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }
    .term-stat-icon.green {
        background: color-mix(in srgb, var(--lime) 15%, transparent);
        color: var(--lime);
        border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
    }
    .term-stat-icon.blue {
        background: rgba(56, 189, 248, 0.15);
        color: #38bdf8;
        border: 1px solid rgba(56, 189, 248, 0.3);
    }
    .term-stat-num {
        font-size: 1.55rem;
        font-weight: 800;
        color: var(--ink);
        line-height: 1.1;
        font-variant-numeric: tabular-nums;
    }
    .term-stat-label {
        font-size: 0.76rem;
        color: var(--muted);
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.04em;
    }

    /* Live Member Verification HUD Card */
    .verification-card {
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 20px;
        padding: 22px;
        backdrop-filter: blur(14px);
        min-height: 200px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        position: relative;
        overflow: hidden;
        transition: all 0.3s ease;
    }
    .verification-card.state-success-in {
        border-color: #22c55e;
        box-shadow: 0 12px 36px rgba(34, 197, 94, 0.15);
        background: radial-gradient(circle at top right, rgba(34, 197, 94, 0.08) 0%, var(--panel) 70%);
    }
    .verification-card.state-success-out {
        border-color: #38bdf8;
        box-shadow: 0 12px 36px rgba(56, 189, 248, 0.15);
        background: radial-gradient(circle at top right, rgba(56, 189, 248, 0.08) 0%, var(--panel) 70%);
    }
    .verification-card.state-error {
        border-color: var(--danger);
        box-shadow: 0 12px 36px color-mix(in srgb, var(--danger) 18%, transparent);
        background: radial-gradient(circle at top right, color-mix(in srgb, var(--danger) 10%, transparent) 0%, var(--panel) 70%);
        animation: shakeError 0.4s ease;
    }
    @keyframes shakeError {
        0%, 100% { transform: translateX(0); }
        20%, 60% { transform: translateX(-6px); }
        40%, 80% { transform: translateX(6px); }
    }

    /* Verification Status Pill */
    .veri-status-tag {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 800;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        margin-bottom: 14px;
        width: fit-content;
    }
    .veri-status-tag.tag-idle {
        background: var(--panel-soft);
        color: var(--muted);
        border: 1px solid var(--line);
    }
    .veri-status-tag.tag-in {
        background: #22c55e;
        color: #041b0e;
    }
    .veri-status-tag.tag-out {
        background: #38bdf8;
        color: #032030;
    }
    .veri-status-tag.tag-error {
        background: var(--danger);
        color: #fff;
    }

    /* Verification Member Info Layout */
    .veri-member-row {
        display: flex;
        align-items: center;
        gap: 16px;
    }
    .veri-avatar-wrap {
        position: relative;
        flex-shrink: 0;
    }
    .veri-avatar-wrap .avatar {
        width: 58px !important;
        height: 58px !important;
        border-radius: 16px;
        font-size: 1.25rem;
        font-weight: 700;
        box-shadow: 0 4px 14px rgba(0,0,0,0.3);
    }
    .veri-member-meta {
        flex: 1;
        min-width: 0;
    }
    .veri-member-name {
        font-size: 1.25rem;
        font-weight: 800;
        color: var(--ink);
        margin: 0 0 4px 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .veri-badges-row {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 4px;
    }
    .veri-chip {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 600;
        background: var(--panel-soft);
        border: 1px solid var(--line);
        color: var(--muted);
    }
    .veri-chip.highlight {
        background: color-mix(in srgb, var(--lime) 15%, transparent);
        color: var(--lime);
        border-color: color-mix(in srgb, var(--lime) 30%, transparent);
    }

    /* Reset countdown progress bar */
    .veri-progress-bar {
        position: absolute;
        bottom: 0;
        left: 0;
        height: 3px;
        background: var(--lime);
        width: 0%;
        transition: width linear;
    }

    /* Today's Live Attendance Stream */
    .activity-feed-card {
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 20px;
        padding: 20px;
        backdrop-filter: blur(14px);
    }
    .activity-feed-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 14px;
        flex-wrap: wrap;
        gap: 10px;
    }
    .activity-feed-title {
        font-size: 1rem;
        font-weight: 800;
        color: var(--ink);
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 0;
    }
    .activity-feed-filter {
        display: flex;
        gap: 4px;
        background: var(--panel-soft);
        padding: 3px;
        border-radius: 8px;
    }
    .filter-tab {
        border: none;
        background: transparent;
        color: var(--muted);
        font-size: 0.75rem;
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.2s;
    }
    .filter-tab.active {
        background: var(--panel);
        color: var(--ink);
        font-weight: 700;
        box-shadow: 0 2px 6px rgba(0,0,0,0.15);
    }
    .activity-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
        max-height: 320px;
        overflow-y: auto;
        padding-right: 4px;
    }
    .activity-list::-webkit-scrollbar {
        width: 5px;
    }
    .activity-list::-webkit-scrollbar-thumb {
        background: var(--line);
        border-radius: 999px;
    }
    .activity-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 10px 12px;
        background: var(--panel-soft);
        border: 1px solid var(--line);
        border-radius: 12px;
        transition: all 0.2s ease;
    }
    .activity-item:hover {
        border-color: color-mix(in srgb, var(--lime) 40%, transparent);
        transform: translateX(2px);
    }
    .activity-left {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
    }
    .activity-name-meta {
        min-width: 0;
    }
    .activity-name {
        font-size: 0.88rem;
        font-weight: 700;
        color: var(--ink);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        margin: 0;
    }
    .activity-sub {
        font-size: 0.72rem;
        color: var(--muted);
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .activity-tag {
        font-size: 0.68rem;
        font-weight: 800;
        padding: 2px 7px;
        border-radius: 6px;
        letter-spacing: 0.04em;
    }
    .activity-tag.in {
        background: rgba(34, 197, 94, 0.15);
        color: #22c55e;
        border: 1px solid rgba(34, 197, 94, 0.3);
    }
    .activity-tag.out {
        background: rgba(56, 189, 248, 0.15);
        color: #38bdf8;
        border: 1px solid rgba(56, 189, 248, 0.3);
    }
    .activity-empty {
        text-align: center;
        padding: 30px 10px;
        color: var(--muted);
        font-size: 0.85rem;
    }

    /* -------------------------------------------------------------
       Manual Check-In Modal / Drawer
       ------------------------------------------------------------- */
    .manual-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.7);
        backdrop-filter: blur(8px);
        z-index: 1000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
        animation: fadeIn 0.2s ease;
    }
    .manual-modal-overlay.open {
        display: flex;
    }
    .manual-modal-card {
        background: var(--bg);
        border: 1px solid var(--line);
        border-radius: 20px;
        width: 100%;
        max-width: 580px;
        max-height: 85vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6);
        animation: scaleIn 0.25s ease;
    }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes scaleIn { from { transform: scale(0.94); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    
    .manual-modal-header {
        padding: 20px 24px;
        border-bottom: 1px solid var(--line);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .manual-modal-header h3 {
        margin: 0;
        font-size: 1.2rem;
        font-weight: 800;
        color: var(--ink);
    }
    .manual-search-box {
        padding: 16px 24px;
        border-bottom: 1px solid var(--line);
        position: relative;
    }
    .manual-search-input {
        width: 100%;
        height: 44px;
        background: var(--panel-soft);
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 0 16px 0 42px;
        color: var(--ink);
        font-size: 0.92rem;
        outline: none;
        transition: border-color 0.2s;
    }
    .manual-search-input:focus {
        border-color: var(--lime);
    }
    .manual-search-icon {
        position: absolute;
        left: 36px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--muted);
        pointer-events: none;
    }
    .manual-results-list {
        padding: 12px 24px 24px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 8px;
        flex: 1;
    }
    .manual-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 12px 14px;
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 12px;
        transition: all 0.2s;
    }
    .manual-item:hover {
        border-color: var(--lime);
    }
    .btn-manual-action {
        padding: 8px 16px;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 700;
        border: none;
        cursor: pointer;
        transition: all 0.2s;
    }
    .btn-manual-action.checkin {
        background: #22c55e;
        color: #041b0e;
    }
    .btn-manual-action.checkin:hover {
        background: #16a34a;
    }
    .btn-manual-action.checkout {
        background: #38bdf8;
        color: #032030;
    }
    .btn-manual-action.checkout:hover {
        background: #0284c7;
    }

    /* Kiosk Fullscreen Mode */
    body.scanner-kiosk-mode .app-frame {
        grid-template-columns: 1fr !important;
        display: block !important;
        width: 100% !important;
    }
    body.scanner-kiosk-mode .sidebar,
    body.scanner-kiosk-mode .sidebar-overlay,
    body.scanner-kiosk-mode .topbar {
        display: none !important;
    }
    body.scanner-kiosk-mode .main-area {
        padding: 16px !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
    }
    body.scanner-kiosk-mode .scanner-page-wrap {
        max-width: 100% !important;
        padding: 8px 16px 40px !important;
    }

    /* =========================================================
       Light Mode Theme Adaptations for Scanner Terminal
       ========================================================= */
    [data-theme="light"] .scanner-page-wrap {
        color: #1e293b;
    }
    [data-theme="light"] .terminal-digital-time {
        color: #0f172a;
    }
    [data-theme="light"] .terminal-digital-date {
        color: #64748b;
    }
    [data-theme="light"] .terminal-hud-pill {
        background: #f0fdf4 !important;
        border-color: #bbf7d0 !important;
        color: #15803d !important;
    }
    [data-theme="light"] .pulse-dot {
        background: #16a34a !important;
        box-shadow: 0 0 10px #16a34a !important;
    }

    /* Terminal Action Buttons in Light Mode */
    [data-theme="light"] .term-btn-icon {
        background: #ffffff;
        color: #1e293b;
        border: 1px solid #cbd5e1;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
    }
    [data-theme="light"] .term-btn-icon:hover {
        background: #f0fdf4;
        border-color: #86efac;
        color: #15803d;
        box-shadow: 0 4px 12px rgba(22, 163, 74, 0.12);
    }
    [data-theme="light"] .term-btn-icon.active {
        background: #16a34a !important;
        color: #ffffff !important;
        border-color: #16a34a !important;
    }

    /* Cards & Containers */
    [data-theme="light"] .scanner-terminal-card,
    [data-theme="light"] .verification-card,
    [data-theme="light"] .activity-feed-card,
    [data-theme="light"] .term-stat-box {
        background: #ffffff !important;
        border-color: #cbd5e1 !important;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05) !important;
    }

    /* Viewfinder & Standby Screen in Light Mode */
    [data-theme="light"] .viewfinder-wrapper {
        background: #f8fafc !important;
        border: 2px solid #cbd5e1 !important;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.06), inset 0 0 30px rgba(0, 0, 0, 0.02) !important;
    }
    [data-theme="light"] .viewfinder-wrapper.scanning {
        border-color: #16a34a !important;
        box-shadow: 0 0 30px rgba(22, 163, 74, 0.25) !important;
    }
    [data-theme="light"] .viewfinder-standby-screen {
        background: radial-gradient(circle at center, #ffffff 0%, #f1f5f9 100%) !important;
    }
    [data-theme="light"] .standby-icon-box {
        background: #f0fdf4 !important;
        border-color: #bbf7d0 !important;
        color: #16a34a !important;
        box-shadow: 0 10px 24px rgba(22, 163, 74, 0.14) !important;
    }
    [data-theme="light"] .standby-title {
        color: #0f172a !important;
    }
    [data-theme="light"] .standby-desc {
        color: #475569 !important;
    }
    [data-theme="light"] .btn-start-camera {
        background: #16a34a !important;
        color: #ffffff !important;
        box-shadow: 0 6px 20px rgba(22, 163, 74, 0.35) !important;
    }
    [data-theme="light"] .btn-start-camera:hover {
        background: #15803d !important;
        box-shadow: 0 10px 28px rgba(22, 163, 74, 0.45) !important;
    }
    [data-theme="light"] .standby-file-drop {
        color: #64748b !important;
    }
    [data-theme="light"] .standby-file-link {
        color: #15803d !important;
        font-weight: 700;
    }
    [data-theme="light"] .viewfinder-badge-top {
        background: rgba(255, 255, 255, 0.92) !important;
        border-color: #cbd5e1 !important;
        color: #0f172a !important;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08) !important;
    }

    /* Reticle & Laser on Light Mode */
    [data-theme="light"] .reticle-corner {
        border-color: #22c55e !important;
        filter: drop-shadow(0 0 8px #22c55e) !important;
    }
    [data-theme="light"] .reticle-center-guide {
        color: #16a34a !important;
    }
    [data-theme="light"] .scanner-laser {
        background: linear-gradient(90deg, transparent 0%, #22c55e 50%, transparent 100%) !important;
        box-shadow: 0 0 14px 2px #22c55e, 0 0 28px #22c55e !important;
    }

    /* Select Dropdowns */
    [data-theme="light"] .scanner-select {
        background: #f8fafc !important;
        border-color: #cbd5e1 !important;
        color: #0f172a !important;
    }
    [data-theme="light"] .scanner-select option {
        background: #ffffff !important;
        color: #0f172a !important;
    }

    /* Stat Boxes */
    [data-theme="light"] .term-stat-num {
        color: #0f172a !important;
    }
    [data-theme="light"] .term-stat-label {
        color: #64748b !important;
    }
    [data-theme="light"] .term-stat-icon.green {
        background: #f0fdf4 !important;
        color: #16a34a !important;
        border-color: #bbf7d0 !important;
    }
    [data-theme="light"] .term-stat-icon.blue {
        background: #f0f9ff !important;
        color: #0284c7 !important;
        border-color: #bae6fd !important;
    }

    /* Verification Card In Light Mode */
    [data-theme="light"] .veri-member-name {
        color: #0f172a !important;
    }
    [data-theme="light"] .veri-chip {
        background: #f1f5f9 !important;
        border-color: #cbd5e1 !important;
        color: #475569 !important;
    }
    [data-theme="light"] .veri-chip.highlight {
        background: #f0fdf4 !important;
        border-color: #bbf7d0 !important;
        color: #15803d !important;
    }
    [data-theme="light"] .veri-status-tag.tag-idle {
        background: #f1f5f9 !important;
        color: #64748b !important;
        border-color: #cbd5e1 !important;
    }

    /* Activity Stream */
    [data-theme="light"] .activity-feed-title {
        color: #0f172a !important;
    }
    [data-theme="light"] .activity-feed-filter {
        background: #f1f5f9 !important;
    }
    [data-theme="light"] .filter-tab {
        color: #64748b !important;
    }
    [data-theme="light"] .filter-tab.active {
        background: #ffffff !important;
        color: #0f172a !important;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08) !important;
    }
    [data-theme="light"] .activity-item {
        background: #f8fafc !important;
        border-color: #e2e8f0 !important;
    }
    [data-theme="light"] .activity-item:hover {
        background: #ffffff !important;
        border-color: #86efac !important;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.05) !important;
    }
    [data-theme="light"] .activity-name {
        color: #0f172a !important;
    }
    [data-theme="light"] .activity-sub {
        color: #64748b !important;
    }

    /* Manual Entry Modal In Light Mode */
    [data-theme="light"] .manual-modal-overlay {
        background: rgba(15, 23, 42, 0.45) !important;
    }
    [data-theme="light"] .manual-modal-card {
        background: #ffffff !important;
        border-color: #cbd5e1 !important;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15) !important;
    }
    [data-theme="light"] .manual-modal-header {
        border-bottom-color: #e2e8f0 !important;
    }
    [data-theme="light"] .manual-modal-header h3 {
        color: #0f172a !important;
    }
    [data-theme="light"] .manual-search-box {
        border-bottom-color: #e2e8f0 !important;
    }
    [data-theme="light"] .manual-search-input {
        background: #f8fafc !important;
        border-color: #cbd5e1 !important;
        color: #0f172a !important;
    }
    [data-theme="light"] .manual-item {
        background: #f8fafc !important;
        border-color: #e2e8f0 !important;
    }
    [data-theme="light"] .manual-item:hover {
        background: #ffffff !important;
        border-color: #16a34a !important;
    }
    [data-theme="light"] .manual-item-name {
        color: #0f172a !important;
    }

    /* Walk-in SweetAlert Dialog */
    .swal-method-btn {
        background: #151a24;
        color: #f8fafc;
        border: 1px solid rgba(255, 255, 255, 0.14);
        padding: 12px 6px;
        border-radius: 12px;
        font-weight: 700;
        cursor: pointer;
        font-size: 0.85rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        transition: all 0.2s ease;
    }
    .swal-method-btn:hover {
        background: #1c2230;
        border-color: rgba(255, 255, 255, 0.25);
        transform: translateY(-1px);
    }
    .swal-method-btn.active {
        border-color: var(--lime) !important;
        background: color-mix(in srgb, var(--lime) 15%, #151a24) !important;
        color: var(--lime) !important;
        box-shadow: 0 0 16px color-mix(in srgb, var(--lime) 20%, transparent);
    }
    .swal-icon-wrap {
        height: 26px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: inherit;
    }
    .swal-icon-wrap svg {
        display: block;
    }

    /* Walk-in SweetAlert Dialog In Light Mode */
    [data-theme="light"] .swal-method-btn {
        background: #f8fafc !important;
        color: #1e293b !important;
        border: 1px solid #cbd5e1 !important;
    }
    [data-theme="light"] .swal-method-btn:hover {
        background: #f1f5f9 !important;
        border-color: #94a3b8 !important;
        transform: translateY(-1px);
    }
    [data-theme="light"] .swal-method-btn.active {
        background: #f0fdf4 !important;
        border-color: #16a34a !important;
        color: #15803d !important;
        box-shadow: 0 4px 14px rgba(22, 163, 74, 0.16) !important;
    }
    [data-theme="light"] #swal-amount {
        background: #f8fafc !important;
        color: #0f172a !important;
        border-color: #cbd5e1 !important;
    }
    </style>

    <div class="scanner-page-wrap">
        <!-- Top Terminal Bar -->
        <div class="terminal-top-bar">
            <div class="terminal-title-group">
                <h1>
                    <span>Reception QR Terminal</span>
                    <span class="terminal-hud-pill">
                        <span class="pulse-dot"></span>
                        <span>Scanner Active</span>
                    </span>
                </h1>
                <p>High-speed optical check-in & member verification station</p>
            </div>

            <!-- Digital Clock HUD -->
            <div class="terminal-clock-box">
                <div>
                    <div class="terminal-digital-time" id="terminal-digital-time">--:--:-- --</div>
                    <div class="terminal-digital-date" id="terminal-digital-date">Loading date...</div>
                </div>
            </div>

            <!-- Terminal Actions -->
            <div class="terminal-header-actions">
                <a href="index.php?page=gym_profile" class="term-btn-icon" title="Standard walk-in fee. Click to customize in Gym Profile" style="text-decoration: none;">
                    <span style="color: var(--lime); font-weight: 800;">₱<?= number_format($currentGymWalkInFee, 2) ?></span>
                    <span style="color: var(--muted); font-size: 0.78rem;">Walk-in Rate</span>
                </a>
                <button type="button" class="term-btn-icon btn-sound-toggle" id="term-sound-btn" title="Toggle audio chimes">
                    <span class="sound-toggle-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
                    </span>
                    <span class="sound-toggle-text">Sound</span>
                </button>
                <button type="button" class="term-btn-icon" id="term-manual-btn" onclick="openManualModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <span>Manual Entry</span>
                </button>
                <button type="button" class="term-btn-icon" id="term-kiosk-btn" onclick="toggleKioskMode()" title="Toggle full-screen kiosk mode">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
                    <span>Kiosk</span>
                </button>
            </div>
        </div>

        <!-- Main Terminal Grid -->
        <div class="terminal-grid">
            <!-- Left Column: Optical Camera Station -->
            <div class="scanner-terminal-card">
                <!-- Toolbar controls -->
                <div class="scanner-toolbar">
                    <div class="scanner-camera-select-wrap">
                        <select id="camera-select" class="scanner-select" title="Select camera video input">
                            <option value="">Detecting cameras...</option>
                        </select>
                    </div>
                    <div class="scanner-toolbar-tools">
                        <button type="button" class="term-btn-icon" id="btn-flip-cam" title="Switch between front and rear cameras" style="display: none;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                            <span>Flip</span>
                        </button>
                        <button type="button" class="term-btn-icon" id="btn-toggle-torch" title="Turn flashlight on/off" style="display: none;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                            <span>Torch</span>
                        </button>
                        <label class="term-btn-icon" title="Upload QR image file" style="cursor: pointer; margin: 0;">
                            <input type="file" id="qr-file-input" accept="image/*" style="display: none;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            <span>Image</span>
                        </label>
                    </div>
                </div>

                <!-- Viewfinder Viewport -->
                <div class="viewfinder-wrapper" id="viewfinder-wrap">
                    <!-- HTML5 QR Code low-level container -->
                    <div id="reader-video-container"></div>

                    <!-- Cyber Reticle Overlay -->
                    <div class="viewfinder-overlay">
                        <div class="reticle-box">
                            <div class="reticle-corner top-left"></div>
                            <div class="reticle-corner top-right"></div>
                            <div class="reticle-corner bottom-left"></div>
                            <div class="reticle-corner bottom-right"></div>
                            
                            <!-- Laser scanline -->
                            <div class="scanner-laser" id="scanner-laser"></div>

                            <!-- Center guide watermark -->
                            <div class="reticle-center-guide">
                                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="7" height="7"></rect>
                                    <rect x="14" y="3" width="7" height="7"></rect>
                                    <rect x="14" y="14" width="7" height="7"></rect>
                                    <rect x="3" y="14" width="7" height="7"></rect>
                                </svg>
                            </div>
                        </div>
                    </div>

                    <!-- Top status badge -->
                    <div class="viewfinder-badge-top" id="viewfinder-badge-top">
                        <span class="badge-dot"></span>
                        <span id="viewfinder-badge-text">STANDBY</span>
                    </div>

                    <!-- Pre-launch / Standby Overlay -->
                    <div class="viewfinder-standby-screen" id="standby-screen">
                        <div class="standby-icon-box">
                            <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                                <circle cx="12" cy="13" r="4"></circle>
                            </svg>
                        </div>
                        <div class="standby-title">Camera Scanner Ready</div>
                        <div class="standby-desc">Click below to activate the optical scanner and grant browser camera access.</div>
                        <button type="button" class="btn-start-camera" id="btn-start-camera">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                            <span>Activate Camera Scanner</span>
                        </button>
                        <div class="standby-file-drop">
                            Or <span class="standby-file-link" onclick="document.getElementById('qr-file-input').click()">select a QR image file</span>
                        </div>
                    </div>
                </div>

                <!-- Bottom Helper & Pause/Resume -->
                <div class="scanner-bottom-actions">
                    <div class="scanner-guide-text">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        <span>Position member QR code inside the brackets. Tokens auto-verify.</span>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="term-btn-icon" id="btn-pause-scanner" style="display: none;" title="Pause or resume live scanning">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
                            <span>Pause</span>
                        </button>
                        <button type="button" class="term-btn-icon btn-stop-scanner-style" id="btn-stop-scanner" style="display: none;" title="Turn off camera and return to standby">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>
                            <span>Stop</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Right Column: Verification HUD & Activity Feed -->
            <div class="terminal-sidebar-card">
                <!-- Occupancy Quick Stats -->
                <div class="terminal-stats-row">
                    <div class="term-stat-box">
                        <div class="term-stat-icon green">
                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>
                        </div>
                        <div>
                            <div class="term-stat-num" id="stat-today-checkins"><?= (int) $initialStats['today_checkins'] ?></div>
                            <div class="term-stat-label">Checked-in Today</div>
                        </div>
                    </div>
                    <div class="term-stat-box">
                        <div class="term-stat-icon blue">
                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
                        </div>
                        <div>
                            <div class="term-stat-num" id="stat-currently-inside"><?= (int) $initialStats['currently_inside'] ?></div>
                            <div class="term-stat-label">Currently Inside</div>
                        </div>
                    </div>
                </div>

                <!-- Member Verification Card -->
                <div class="verification-card" id="verification-card">
                    <!-- Idle State -->
                    <div id="veri-state-idle">
                        <div class="veri-status-tag tag-idle">
                            <span>Ready to Scan</span>
                        </div>
                        <div class="veri-member-row">
                            <div class="veri-avatar-wrap">
                                <div class="avatar medium" style="background: var(--panel-soft); display:flex; align-items:center; justify-content:center; color: var(--muted); border: 2px dashed var(--line);">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                </div>
                            </div>
                            <div class="veri-member-meta">
                                <div class="veri-member-name" style="color: var(--muted);">Waiting for QR code...</div>
                                <div style="font-size: 0.8rem; color: var(--muted); line-height: 1.4;">Hold member's FitTrack QR code steady in front of the camera lens.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Processing State -->
                    <div id="veri-state-processing" style="display: none;">
                        <div class="veri-status-tag tag-idle" style="background: color-mix(in srgb, var(--lime) 15%, transparent); color: var(--lime); border-color: var(--lime);">
                            <span>Verifying Credentials...</span>
                        </div>
                        <div class="veri-member-row">
                            <div class="veri-avatar-wrap">
                                <div class="avatar medium" style="background: var(--panel-soft); display:flex; align-items:center; justify-content:center;">
                                    <div class="pulse-dot"></div>
                                </div>
                            </div>
                            <div class="veri-member-meta">
                                <div class="veri-member-name">Processing token...</div>
                                <div style="font-size: 0.8rem; color: var(--muted);">Validating membership status with server database.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Result State (Dynamic) -->
                    <div id="veri-state-result" style="display: none;">
                        <div class="veri-status-tag" id="veri-tag">
                            <span id="veri-tag-text">CHECK-IN CONFIRMED</span>
                        </div>
                        <div class="veri-member-row">
                            <div class="veri-avatar-wrap" id="veri-avatar-wrap">
                                <!-- avatar injected -->
                            </div>
                            <div class="veri-member-meta">
                                <h3 class="veri-member-name" id="veri-member-name">Member Name</h3>
                                <div class="veri-badges-row">
                                    <span class="veri-chip" id="veri-role-badge">Member</span>
                                    <span class="veri-chip highlight" id="veri-plan-badge">Plan Name</span>
                                    <span class="veri-chip" id="veri-time-badge">00:00 AM</span>
                                </div>
                                <div id="veri-extra-info" style="margin-top: 6px; font-size: 0.8rem; color: var(--muted);"></div>
                            </div>
                        </div>
                        <div class="veri-progress-bar" id="veri-progress-bar"></div>
                    </div>

                    <!-- Error State (Dynamic) -->
                    <div id="veri-state-error" style="display: none;">
                        <div class="veri-status-tag tag-error">
                            <span>Scan Error</span>
                        </div>
                        <div class="veri-member-row">
                            <div class="veri-avatar-wrap">
                                <div class="avatar medium" style="background: color-mix(in srgb, var(--danger) 20%, transparent); color: var(--danger); display:flex; align-items:center; justify-content:center;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </div>
                            </div>
                            <div class="veri-member-meta">
                                <div class="veri-member-name" style="color: var(--danger);" id="veri-error-title">Invalid QR Code</div>
                                <div style="font-size: 0.82rem; color: var(--muted);" id="veri-error-msg">Token could not be verified.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Today's Live Attendance Feed -->
                <div class="activity-feed-card">
                    <div class="activity-feed-header">
                        <h3 class="activity-feed-title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span>Today's Attendance Feed</span>
                        </h3>
                        <div class="activity-feed-filter">
                            <button type="button" class="filter-tab active" data-filter="all" onclick="filterActivity('all', this)">All</button>
                            <button type="button" class="filter-tab" data-filter="in" onclick="filterActivity('in', this)">Inside</button>
                            <button type="button" class="filter-tab" data-filter="out" onclick="filterActivity('out', this)">Checkouts</button>
                        </div>
                    </div>

                    <div class="activity-list" id="activity-list">
                        <?php if (empty($initialActivity)): ?>
                            <div class="activity-empty" id="activity-empty">No attendance scans recorded today yet.</div>
                        <?php else: ?>
                            <?php foreach ($initialActivity as $act): ?>
                                <div class="activity-item" data-status="<?= $act['is_checkout'] ? 'out' : 'in' ?>">
                                    <div class="activity-left">
                                        <?= $act['avatar_html'] ?>
                                        <div class="activity-name-meta">
                                            <p class="activity-name"><?= h($act['name']) ?></p>
                                            <div class="activity-sub">
                                                <span><?= h($act['time_formatted']) ?></span>
                                                <span>•</span>
                                                <span><?= h($act['method']) ?></span>
                                                <?php if (!empty($act['duration'])): ?>
                                                    <span>• <?= h($act['duration']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <span class="activity-tag <?= $act['is_checkout'] ? 'out' : 'in' ?>">
                                        <?= $act['is_checkout'] ? 'CHECK-OUT' : 'CHECK-IN' ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Manual Check-In Modal Dialog -->
    <div class="manual-modal-overlay" id="manual-modal" onclick="if(event.target === this) closeManualModal()">
        <div class="manual-modal-card">
            <div class="manual-modal-header">
                <h3>Manual Member Entry</h3>
                <button type="button" class="term-btn-icon" style="padding: 0 10px; height: 32px;" onclick="closeManualModal()">✕</button>
            </div>
            <div class="manual-search-box">
                <span class="manual-search-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </span>
                <input type="text" id="manual-search-input" class="manual-search-input" placeholder="Type member name, email or phone number..." autocomplete="off">
            </div>
            <div class="manual-results-list" id="manual-results-list">
                <div style="text-align: center; color: var(--muted); padding: 30px 10px; font-size: 0.88rem;">
                    Type to find members registered to this gym.
                </div>
            </div>
        </div>
    </div>

    <!-- HTML5 QR Code Library -->
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

    <script>
    (function() {
        'use strict';

        const csrfToken = <?= json_encode(csrf_token()) ?>;
        let html5QrCode = null;
        let isScannerRunning = false;
        let isScannerPaused = false;
        let isProcessing = false;
        let currentCameraId = null;
        let availableCameras = [];
        let isTorchOn = false;
        let resetTimer = null;
        const processedTokensInSession = new Set();

        // Elements
        const viewfinderWrap = document.getElementById('viewfinder-wrap');
        const standbyScreen = document.getElementById('standby-screen');
        const btnStartCamera = document.getElementById('btn-start-camera');
        const cameraSelect = document.getElementById('camera-select');
        const btnFlipCam = document.getElementById('btn-flip-cam');
        const btnToggleTorch = document.getElementById('btn-toggle-torch');
        const btnPauseScanner = document.getElementById('btn-pause-scanner');
        const btnStopScanner = document.getElementById('btn-stop-scanner');
        const qrFileInput = document.getElementById('qr-file-input');
        const topBadge = document.getElementById('viewfinder-badge-top');
        const topBadgeText = document.getElementById('viewfinder-badge-text');

        // Verification HUD Elements
        const veriCard = document.getElementById('verification-card');
        const stateIdle = document.getElementById('veri-state-idle');
        const stateProcessing = document.getElementById('veri-state-processing');
        const stateResult = document.getElementById('veri-state-result');
        const stateError = document.getElementById('veri-state-error');
        const veriTag = document.getElementById('veri-tag');
        const veriTagText = document.getElementById('veri-tag-text');
        const veriAvatarWrap = document.getElementById('veri-avatar-wrap');
        const veriMemberName = document.getElementById('veri-member-name');
        const veriRoleBadge = document.getElementById('veri-role-badge');
        const veriPlanBadge = document.getElementById('veri-plan-badge');
        const veriTimeBadge = document.getElementById('veri-time-badge');
        const veriExtraInfo = document.getElementById('veri-extra-info');
        const veriProgressBar = document.getElementById('veri-progress-bar');
        const veriErrorTitle = document.getElementById('veri-error-title');
        const veriErrorMsg = document.getElementById('veri-error-msg');

        // Stats Elements
        const statTodayCheckins = document.getElementById('stat-today-checkins');
        const statCurrentlyInside = document.getElementById('stat-currently-inside');
        const activityList = document.getElementById('activity-list');

        // Clock Update
        function updateTerminalClock() {
            const now = new Date();
            const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
            const dateStr = now.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' });
            
            const timeEl = document.getElementById('terminal-digital-time');
            const dateEl = document.getElementById('terminal-digital-date');
            if (timeEl) timeEl.textContent = timeStr;
            if (dateEl) dateEl.textContent = dateStr;
        }
        setInterval(updateTerminalClock, 1000);
        updateTerminalClock();

        // Audio & Haptic Feedback
        function playChime(type) {
            try {
                if (window.FitTrackAudio && typeof window.FitTrackAudio.play === 'function') {
                    window.FitTrackAudio.play(type === 'error' ? 'warning' : 'success');
                }
            } catch (e) {}

            if (navigator.vibrate) {
                try {
                    if (type === 'error') {
                        navigator.vibrate([150, 80, 150]);
                    } else {
                        navigator.vibrate([80, 40, 80]);
                    }
                } catch (e) {}
            }
        }

        // Initialize Low-Level Html5QrCode instance
        function initScannerEngine() {
            if (!html5QrCode) {
                html5QrCode = new Html5Qrcode("reader-video-container", {
                    experimentalFeatures: {
                        useBarCodeDetectorIfSupported: true
                    },
                    verbose: false
                });
            }
        }

        // Camera permissions & start
        async function startCameraScanner(preferredCameraId = null) {
            initScannerEngine();
            setHudState('processing');

            try {
                // Discover cameras if not yet cached
                if (availableCameras.length === 0) {
                    availableCameras = await Html5Qrcode.getCameras();
                    populateCameraSelect(availableCameras);
                }

                if (!availableCameras || availableCameras.length === 0) {
                    showErrorResult('No Camera Detected', 'Please connect a webcam or enable camera permissions.');
                    return;
                }

                let cameraIdToUse = preferredCameraId;
                if (!cameraIdToUse) {
                    const savedCamera = localStorage.getItem('fittracks_scanner_cam');
                    const foundSaved = availableCameras.find(c => c.id === savedCamera);
                    if (foundSaved) {
                        cameraIdToUse = foundSaved.id;
                    } else {
                        // 1. Prefer rear/environment camera (phones/tablets)
                        const rear = availableCameras.find(c => /back|rear|environment/i.test(c.label));
                        // 2. Prefer real physical webcam over virtual drivers (OBS, ManyCam, DroidCam)
                        const physical = availableCameras.find(c => !/virtual|obs|manycam|droidcam|splitcam|ndi/i.test(c.label));
                        cameraIdToUse = rear ? rear.id : (physical ? physical.id : availableCameras[0].id);
                    }
                }

                currentCameraId = cameraIdToUse;
                cameraSelect.value = currentCameraId;

                // Stop active stream before restarting
                if (isScannerRunning) {
                    await html5QrCode.stop();
                    isScannerRunning = false;
                }

                // Dynamic responsive qrbox
                const config = {
                    fps: 15,
                    qrbox: (viewfinderWidth, viewfinderHeight) => {
                        const minEdge = Math.min(viewfinderWidth, viewfinderHeight);
                        const boxSize = Math.floor(minEdge * 0.76);
                        return { width: boxSize, height: boxSize };
                    },
                    aspectRatio: 1.0
                };

                try {
                    await html5QrCode.start(
                        cameraIdToUse,
                        config,
                        onQrCodeScanned,
                        onQrScanFailure
                    );
                } catch (startErr) {
                    // If the camera failed and it's a virtual camera (like OBS), try auto-failing over to a physical camera
                    const isVirtual = availableCameras.some(c => c.id === cameraIdToUse && /virtual|obs|manycam|droidcam/i.test(c.label));
                    const physicalFallback = availableCameras.find(c => c.id !== cameraIdToUse && !/virtual|obs|manycam|droidcam/i.test(c.label));
                    if (isVirtual && physicalFallback) {
                        console.warn("Virtual camera failed, auto-failing over to physical webcam:", physicalFallback.label);
                        cameraIdToUse = physicalFallback.id;
                        currentCameraId = cameraIdToUse;
                        cameraSelect.value = currentCameraId;
                        localStorage.setItem('fittracks_scanner_cam', currentCameraId);
                        await html5QrCode.start(
                            cameraIdToUse,
                            config,
                            onQrCodeScanned,
                            onQrScanFailure
                        );
                    } else {
                        throw startErr;
                    }
                }

                isScannerRunning = true;
                isScannerPaused = false;
                standbyScreen.style.display = 'none';
                viewfinderWrap.classList.add('scanning');
                btnPauseScanner.style.display = 'inline-flex';
                btnPauseScanner.querySelector('span').textContent = 'Pause';
                btnStopScanner.style.display = 'inline-flex';
                topBadge.classList.add('is-active');
                topBadgeText.textContent = 'LIVE SCANNING';

                if (availableCameras.length > 1) {
                    btnFlipCam.style.display = 'inline-flex';
                }

                // Check torch support
                checkTorchSupport();
                setHudState('idle');

            } catch (err) {
                console.error("Camera start error:", err);
                viewfinderWrap.classList.remove('scanning');
                standbyScreen.style.display = 'flex';
                topBadge.classList.remove('is-active');
                topBadgeText.textContent = 'OFFLINE';
                btnPauseScanner.style.display = 'none';
                btnStopScanner.style.display = 'none';

                const errStr = (typeof err === 'string' ? err : (err?.message || err?.name || String(err))).toLowerCase();
                let errTitle = 'Camera Error';
                let errMsg = 'Could not start camera stream.';

                if (errStr.includes('notfound') || errStr.includes('devicesnotfound')) {
                    errTitle = 'No Camera Detected';
                    errMsg = 'No webcam or optical sensor found on this system.';
                } else if (errStr.includes('notreadable') || errStr.includes('could not start video source') || errStr.includes('track') || errStr.includes('in use') || errStr.includes('source')) {
                    errTitle = 'Camera Unavailable';
                    errMsg = 'The selected camera (e.g. OBS Virtual Camera) is inactive or in use by another app. Please select your physical webcam from the dropdown above.';
                } else if (errStr.includes('notallowed') || errStr.includes('permission') || errStr.includes('denied')) {
                    errTitle = 'Camera Access Required';
                    errMsg = 'Camera permission was denied. Please allow camera access in your browser.';
                } else {
                    errMsg = (err && err.message) ? err.message : 'Camera failed to activate. Please choose another camera from the dropdown.';
                }
                showErrorResult(errTitle, errMsg);
            }
        }

        function populateCameraSelect(cameras) {
            cameraSelect.innerHTML = '';
            cameras.forEach((cam, idx) => {
                const opt = document.createElement('option');
                opt.value = cam.id;
                opt.textContent = cam.label || `Camera ${idx + 1}`;
                cameraSelect.appendChild(opt);
            });
        }

        async function checkTorchSupport() {
            try {
                const track = html5QrCode.getRunningTrackCameraCapabilities();
                if (track && typeof track.torchFeature === 'function' && track.torchFeature().isSupported()) {
                    btnToggleTorch.style.display = 'inline-flex';
                } else {
                    btnToggleTorch.style.display = 'none';
                }
            } catch (e) {
                btnToggleTorch.style.display = 'none';
            }
        }

        // Toggle Flashlight / Torch
        async function toggleTorch() {
            if (!isScannerRunning) return;
            try {
                isTorchOn = !isTorchOn;
                await html5QrCode.applyVideoConstraints({
                    advanced: [{ torch: isTorchOn }]
                });
                btnToggleTorch.classList.toggle('active', isTorchOn);
            } catch (err) {
                console.warn("Torch not supported:", err);
                btnToggleTorch.style.display = 'none';
            }
        }

        // QR Code Successfully Read
        function onQrCodeScanned(decodedText, decodedResult) {
            if (isProcessing) return;

            // Prevent rapid repeated scans of the exact same token in a single session
            if (processedTokensInSession.has(decodedText)) {
                return;
            }

            processAttendanceData(decodedText, 'qr_code');
        }

        function onQrScanFailure(error) {
            // Ignore frame-by-frame read attempts
        }

        // Process Attendance with Server
        function processAttendanceData(qrString, method = 'qr_code') {
            isProcessing = true;
            if (method !== 'image_file') {
                processedTokensInSession.add(qrString);
            }

            // Audio & Visual Trigger
            viewfinderWrap.classList.add('flash-success');
            setTimeout(() => viewfinderWrap.classList.remove('flash-success'), 600);

            setHudState('processing');

            fetch('index.php?page=scanner', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=process_qr&qr_data=' + encodeURIComponent(qrString) + '&csrf_token=' + encodeURIComponent(csrfToken)
            })
            .then(res => res.json())
            .then(data => {
                if (data.requires_payment) {
                    playChime('error');
                    viewfinderWrap.classList.add('flash-error');
                    setTimeout(() => viewfinderWrap.classList.remove('flash-error'), 800);

                    // Prompt Walk-In Payment Modal with Auto-Detected Gym Walk-in Fee
                    const defaultFee = data.walk_in_fee ? parseFloat(data.walk_in_fee).toFixed(2) : '100.00';
                    Swal.fire({
                        title: 'Guest Walk-in Payment Required',
                        html: `
                            <div style="text-align: left; margin: 6px 0;">
                                <div style="background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.25); border-radius: 12px; padding: 12px 14px; margin-bottom: 16px;">
                                    <div style="color: var(--danger); font-weight: 700; font-size: 0.9rem; margin-bottom: 3px;">${data.message}</div>
                                    <div style="font-size: 0.82rem; color: var(--muted);">Member: <strong style="color: var(--ink);">${data.member_name || 'Visitor'}</strong></div>
                                </div>
                                
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                    <label style="font-size: 0.78rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em;">Amount to Collect</label>
                                    <span style="font-size: 0.76rem; color: var(--lime); font-weight: 700;">Rate Detected: ₱${defaultFee}</span>
                                </div>
                                <div style="position: relative; margin-bottom: 16px;">
                                    <span style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); font-weight: 800; color: var(--lime); font-size: 16px;">₱</span>
                                    <input type="number" id="swal-amount" class="swal2-input swal-amount-input" placeholder="0.00" step="0.01" min="0" value="${defaultFee}" style="width: 100% !important; margin: 0 !important; padding-left: 36px !important; height: 46px !important; border-radius: 12px !important; box-sizing: border-box !important; font-size: 1.15rem !important; font-weight: 800 !important;">
                                </div>

                                <label style="display: block; font-size: 0.78rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 8px;">
                                    Payment Method
                                </label>
                                <div class="swal-pay-methods" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 10px;">
                                    <button type="button" class="swal-method-btn active" data-method="cash" onclick="window.setSwalMethod('cash', this)">
                                        <span class="swal-icon-wrap" style="font-weight: 900; font-size: 1.45rem; line-height: 1;">₱</span>
                                        <span>Cash</span>
                                    </button>
                                    <button type="button" class="swal-method-btn" data-method="gcash" onclick="window.setSwalMethod('gcash', this)">
                                        <span class="swal-icon-wrap">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="2"></rect><line x1="10" y1="18" x2="14" y2="18"></line></svg>
                                        </span>
                                        <span>GCash</span>
                                    </button>
                                    <button type="button" class="swal-method-btn" data-method="card" onclick="window.setSwalMethod('card', this)">
                                        <span class="swal-icon-wrap">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>
                                        </span>
                                        <span>Card</span>
                                    </button>
                                </div>
                                <input type="hidden" id="swal-method" value="cash">
                            </div>
                        `,
                        background: 'var(--panel)',
                        color: 'var(--ink)',
                        confirmButtonColor: 'var(--lime)',
                        cancelButtonColor: 'color-mix(in srgb, var(--ink) 12%, transparent)',
                        showCancelButton: true,
                        confirmButtonText: '<span style="color:#05080c; font-weight:700;">Record Payment & Check-in</span>',
                        cancelButtonText: '<span style="color:var(--ink);">Cancel</span>',
                        didOpen: () => {
                            window.setSwalMethod = function(m, btn) {
                                const hidden = document.getElementById('swal-method');
                                if (hidden) hidden.value = m;
                                document.querySelectorAll('.swal-method-btn').forEach(b => {
                                    b.classList.toggle('active', b.getAttribute('data-method') === m);
                                });
                            };
                        },
                        preConfirm: () => {
                            const amt = document.getElementById('swal-amount').value;
                            const meth = document.getElementById('swal-method').value;
                            return { amount: amt || 0, method: meth };
                        }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            fetch('index.php?page=scanner', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'action=process_qr&qr_data=' + encodeURIComponent(qrString) + '&amount_paid=' + encodeURIComponent(result.value.amount) + '&payment_method=' + encodeURIComponent(result.value.method) + '&csrf_token=' + encodeURIComponent(csrfToken)
                            })
                            .then(r => r.json())
                            .then(data2 => {
                                if (data2.success) {
                                    handleSuccessResponse(data2);
                                } else {
                                    showErrorResult('Payment / Check-in Failed', data2.message);
                                }
                            });
                        } else {
                            isProcessing = false;
                            processedTokensInSession.delete(qrString);
                            setHudState('idle');
                        }
                    });
                    return;
                }

                if (data.success) {
                    handleSuccessResponse(data);
                } else {
                    showErrorResult('Scan Verification Failed', data.message);
                    setTimeout(() => {
                        processedTokensInSession.delete(qrString);
                    }, 4000);
                }
            })
            .catch(err => {
                console.error(err);
                showErrorResult('Network Error', 'Could not reach server to verify attendance.');
                isProcessing = false;
                processedTokensInSession.delete(qrString);
            });
        }

        // Handle Success Verification Response
        function handleSuccessResponse(data) {
            playChime('success');

            const isCheckIn = data.action_type === 'checkin';
            const m = data.member;

            veriCard.className = 'verification-card ' + (isCheckIn ? 'state-success-in' : 'state-success-out');
            veriTag.className = 'veri-status-tag ' + (isCheckIn ? 'tag-in' : 'tag-out');
            veriTagText.textContent = isCheckIn ? 'CHECK-IN CONFIRMED' : 'CHECK-OUT RECORDED';

            veriAvatarWrap.innerHTML = m.avatar_html || '';
            veriMemberName.textContent = m.name || 'Member';
            veriRoleBadge.textContent = m.role || 'Member';
            veriPlanBadge.textContent = m.plan_name || 'Active Member';
            veriTimeBadge.textContent = data.time || 'Just now';

            let extraHtml = '';
            if (m.class_name) {
                extraHtml += `<span style="color: var(--lime); font-weight:700;">★ Class Booked:</span> ${m.class_name} (Attended)`;
            } else if (!isCheckIn && m.duration) {
                extraHtml += `<span style="color: #38bdf8; font-weight:700;">Session Duration:</span> ${m.duration}`;
            }
            veriExtraInfo.innerHTML = extraHtml;

            setHudState('result');

            // Update stats
            if (data.stats) {
                if (statTodayCheckins) statTodayCheckins.textContent = data.stats.today_checkins;
                if (statCurrentlyInside) statCurrentlyInside.textContent = data.stats.currently_inside;
            }

            // Prepend to Today's Feed
            prependActivityFeed({
                avatar_html: m.avatar_html,
                name: m.name,
                time_formatted: data.time || 'Just now',
                method: 'QR Scan',
                is_checkout: !isCheckIn,
                duration: m.duration || null
            });

            // Start smooth progress bar countdown for reset (4 seconds)
            veriProgressBar.style.transition = 'none';
            veriProgressBar.style.width = '100%';
            setTimeout(() => {
                veriProgressBar.style.transition = 'width 4s linear';
                veriProgressBar.style.width = '0%';
            }, 50);

            clearTimeout(resetTimer);
            resetTimer = setTimeout(() => {
                isProcessing = false;
                setHudState('idle');
            }, 4000);
        }

        // Show Error Result Card
        function showErrorResult(title, message) {
            playChime('error');
            viewfinderWrap.classList.add('flash-error');
            setTimeout(() => viewfinderWrap.classList.remove('flash-error'), 800);

            veriCard.className = 'verification-card state-error';
            veriErrorTitle.textContent = title;
            veriErrorMsg.textContent = message;
            setHudState('error');

            clearTimeout(resetTimer);
            resetTimer = setTimeout(() => {
                isProcessing = false;
                setHudState('idle');
            }, 4500);
        }

        function setHudState(state) {
            stateIdle.style.display = state === 'idle' ? 'block' : 'none';
            stateProcessing.style.display = state === 'processing' ? 'block' : 'none';
            stateResult.style.display = state === 'result' ? 'block' : 'none';
            stateError.style.display = state === 'error' ? 'block' : 'none';

            if (state === 'idle') {
                veriCard.className = 'verification-card';
            }
        }

        function prependActivityFeed(item) {
            const emptyMsg = document.getElementById('activity-empty');
            if (emptyMsg) emptyMsg.remove();

            const row = document.createElement('div');
            row.className = 'activity-item';
            row.setAttribute('data-status', item.is_checkout ? 'out' : 'in');
            row.style.animation = 'fadeIn 0.3s ease';

            const safeName = typeof escapeHtml === 'function' ? escapeHtml(item.name) : item.name;
            const safeTime = typeof escapeHtml === 'function' ? escapeHtml(item.time_formatted) : item.time_formatted;
            const safeMethod = typeof escapeHtml === 'function' ? escapeHtml(item.method) : item.method;
            const safeDuration = item.duration && typeof escapeHtml === 'function' ? escapeHtml(item.duration) : item.duration;

            let subText = `<span>${safeTime}</span><span>•</span><span>${safeMethod}</span>`;
            if (safeDuration) {
                subText += `<span>• ${safeDuration}</span>`;
            }

            row.innerHTML = `
                <div class="activity-left">
                    ${item.avatar_html}
                    <div class="activity-name-meta">
                        <p class="activity-name">${safeName}</p>
                        <div class="activity-sub">${subText}</div>
                    </div>
                </div>
                <span class="activity-tag ${item.is_checkout ? 'out' : 'in'}">
                    ${item.is_checkout ? 'CHECK-OUT' : 'CHECK-IN'}
                </span>
            `;

            activityList.insertBefore(row, activityList.firstChild);
        }

        // Dedicated offscreen instance for file scans (does not interfere with active camera stream)
        let fileScannerInstance = null;
        function getFileScanner() {
            if (!fileScannerInstance) {
                let dummy = document.getElementById('qr-file-dummy-container');
                if (!dummy) {
                    dummy = document.createElement('div');
                    dummy.id = 'qr-file-dummy-container';
                    dummy.style.display = 'none';
                    document.body.appendChild(dummy);
                }
                fileScannerInstance = new Html5Qrcode('qr-file-dummy-container');
            }
            return fileScannerInstance;
        }

        // Scan from Image File
        qrFileInput.addEventListener('change', function(e) {
            const file = e.target.files && e.target.files[0];
            if (!file) return;

            setHudState('processing');

            getFileScanner().scanFile(file, false)
                .then(decodedText => {
                    processAttendanceData(decodedText, 'image_file');
                    qrFileInput.value = '';
                })
                .catch(err => {
                    console.error("File QR decode error:", err);
                    showErrorResult('No QR Code Found', 'Could not detect a valid FitTrack QR code in this image.');
                    qrFileInput.value = '';
                });
        });

        // Camera Select Dropdown Change
        cameraSelect.addEventListener('change', function() {
            const selectedId = this.value;
            if (selectedId) {
                localStorage.setItem('fittracks_scanner_cam', selectedId);
                startCameraScanner(selectedId);
            }
        });

        // Flip Camera Button
        btnFlipCam.addEventListener('click', function() {
            if (availableCameras.length < 2) return;
            const curIndex = availableCameras.findIndex(c => c.id === currentCameraId);
            const nextIndex = (curIndex + 1) % availableCameras.length;
            const nextCam = availableCameras[nextIndex];
            localStorage.setItem('fittracks_scanner_cam', nextCam.id);
            startCameraScanner(nextCam.id);
        });

        // Torch toggle
        btnToggleTorch.addEventListener('click', toggleTorch);

        // Start button on standby screen
        btnStartCamera.addEventListener('click', function() {
            startCameraScanner();
        });

        // Pause / Resume Scanner
        btnPauseScanner.addEventListener('click', function() {
            if (!html5QrCode || !isScannerRunning) return;

            if (isScannerPaused) {
                html5QrCode.resume();
                isScannerPaused = false;
                viewfinderWrap.classList.add('scanning');
                this.querySelector('span').textContent = 'Pause';
                topBadgeText.textContent = 'LIVE SCANNING';
            } else {
                html5QrCode.pause();
                isScannerPaused = true;
                viewfinderWrap.classList.remove('scanning');
                this.querySelector('span').textContent = 'Resume';
                topBadgeText.textContent = 'PAUSED';
            }
        });

        // Stop Camera Scanner Completely (Returns to Standby Screen)
        async function stopCameraScanner() {
            if (!html5QrCode) return;
            try {
                if (isScannerRunning) {
                    await html5QrCode.stop();
                }
            } catch (e) {
                console.warn("Camera stop error:", e);
            }
            isScannerRunning = false;
            isScannerPaused = false;
            viewfinderWrap.classList.remove('scanning');
            standbyScreen.style.display = 'flex';
            topBadge.classList.remove('is-active');
            topBadgeText.textContent = 'STANDBY';
            btnPauseScanner.style.display = 'none';
            btnStopScanner.style.display = 'none';
            btnFlipCam.style.display = 'none';
            btnToggleTorch.style.display = 'none';
            if (isTorchOn) {
                isTorchOn = false;
                btnToggleTorch.classList.remove('active');
            }
            setHudState('idle');
        }

        btnStopScanner.addEventListener('click', stopCameraScanner);

        // Drag & Drop image files onto the viewfinder
        viewfinderWrap.addEventListener('dragover', (e) => {
            e.preventDefault();
            viewfinderWrap.style.borderColor = 'var(--lime)';
        });
        viewfinderWrap.addEventListener('dragleave', () => {
            viewfinderWrap.style.borderColor = '';
        });
        viewfinderWrap.addEventListener('drop', (e) => {
            e.preventDefault();
            viewfinderWrap.style.borderColor = '';
            if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                const file = e.dataTransfer.files[0];
                setHudState('processing');
                getFileScanner().scanFile(file, false)
                    .then(decodedText => processAttendanceData(decodedText, 'image_file'))
                    .catch(() => showErrorResult('No QR Code Found', 'Dropped image did not contain a readable QR code.'));
            }
        });

        // Activity Feed Filter
        window.filterActivity = function(filter, btn) {
            document.querySelectorAll('.activity-feed-filter .filter-tab').forEach(t => t.classList.remove('active'));
            if (btn) btn.classList.add('active');

            document.querySelectorAll('#activity-list .activity-item').forEach(item => {
                const status = item.getAttribute('data-status');
                if (filter === 'all') {
                    item.style.display = 'flex';
                } else if (filter === status) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        };

        // Kiosk Mode Fullscreen Toggle
        window.toggleKioskMode = function() {
            const isKiosk = document.body.classList.toggle('scanner-kiosk-mode');
            const kioskBtn = document.getElementById('term-kiosk-btn');
            if (kioskBtn) {
                kioskBtn.classList.toggle('active', isKiosk);
                const span = kioskBtn.querySelector('span');
                if (span) span.textContent = isKiosk ? 'Exit Kiosk' : 'Kiosk';
            }
            setTimeout(() => {
                window.dispatchEvent(new Event('resize'));
            }, 80);

            if (isKiosk && document.fullscreenEnabled && !document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(() => {});
            } else if (!isKiosk && document.fullscreenElement) {
                document.exitFullscreen().catch(() => {});
            }
        };

        document.addEventListener('fullscreenchange', function() {
            if (!document.fullscreenElement && document.body.classList.contains('scanner-kiosk-mode')) {
                document.body.classList.remove('scanner-kiosk-mode');
                const kioskBtn = document.getElementById('term-kiosk-btn');
                if (kioskBtn) {
                    kioskBtn.classList.remove('active');
                    const span = kioskBtn.querySelector('span');
                    if (span) span.textContent = 'Kiosk';
                }
                setTimeout(() => window.dispatchEvent(new Event('resize')), 80);
            }
        });

        // Manual Entry Modal
        let searchDebounceTimer = null;
        const manualModal = document.getElementById('manual-modal');
        const manualSearchInput = document.getElementById('manual-search-input');
        const manualResultsList = document.getElementById('manual-results-list');

        window.openManualModal = function() {
            manualModal.classList.add('open');
            manualSearchInput.value = '';
            manualSearchInput.focus();
            fetchMemberSearch('');
        };

        window.closeManualModal = function() {
            manualModal.classList.remove('open');
        };

        manualSearchInput.addEventListener('input', function() {
            clearTimeout(searchDebounceTimer);
            const val = this.value.trim();
            searchDebounceTimer = setTimeout(() => {
                fetchMemberSearch(val);
            }, 250);
        });

        function fetchMemberSearch(query) {
            manualResultsList.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--muted);">Searching members...</div>';
            
            fetch('index.php?page=scanner&action=search_members&q=' + encodeURIComponent(query))
                .then(r => r.json())
                .then(data => {
                    if (!data.success || !data.members || data.members.length === 0) {
                        manualResultsList.innerHTML = '<div style="text-align:center; padding: 25px; color: var(--muted);">No matching members found.</div>';
                        return;
                    }

                    manualResultsList.innerHTML = '';
                    data.members.forEach(m => {
                        const row = document.createElement('div');
                        row.className = 'manual-item';
                        const safeName = typeof escapeHtml === 'function' ? escapeHtml(m.name) : m.name;
                        const safeRole = typeof escapeHtml === 'function' ? escapeHtml(m.role) : m.role;
                        const safePhone = typeof escapeHtml === 'function' ? escapeHtml(m.phone) : m.phone;
                        const safeDuration = m.duration && typeof escapeHtml === 'function' ? escapeHtml(m.duration) : m.duration;
                        const insideBadge = m.is_inside ? `<span style="display:inline-block; margin-top:2px; font-size:0.7rem; font-weight:700; color:#38bdf8;">Currently Inside (${safeDuration || 'Active'})</span>` : '';

                        row.innerHTML = `
                            <div style="display:flex; align-items:center; gap:12px; min-width:0;">
                                ${m.avatar_html}
                                <div style="min-width:0;">
                                    <p style="margin:0; font-weight:700; color:var(--ink); font-size:0.9rem;">${safeName}</p>
                                    <div style="font-size:0.75rem; color:var(--muted);">${safeRole} • ${safePhone}</div>
                                    ${insideBadge}
                                </div>
                            </div>
                            <div>
                                <button type="button" class="btn-manual-action ${m.is_inside ? 'checkout' : 'checkin'}" onclick="submitManualAttendance(${parseInt(m.user_id, 10)})">
                                    ${m.is_inside ? 'Check-Out' : 'Check-In'}
                                </button>
                            </div>
                        `;
                        manualResultsList.appendChild(row);
                    });
                })
                .catch(() => {
                    manualResultsList.innerHTML = '<div style="text-align:center; padding: 25px; color: var(--danger);">Failed to search members.</div>';
                });
        }

        window.submitManualAttendance = function(userId) {
            fetch('index.php?page=scanner', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=manual_checkin&user_id=' + encodeURIComponent(userId) + '&csrf_token=' + encodeURIComponent(csrfToken)
            })
            .then(r => r.json())
            .then(data => {
                closeManualModal();
                if (data.success) {
                    handleSuccessResponse(data);
                } else {
                    showErrorResult('Manual Attendance Failed', data.message);
                }
            })
            .catch(err => {
                closeManualModal();
                showErrorResult('Error', 'Could not record manual attendance.');
            });
        };

        // Auto-check for cameras and prompt on page load
        Html5Qrcode.getCameras().then(devices => {
            if (devices && devices.length > 0) {
                availableCameras = devices;
                populateCameraSelect(devices);
            }
        }).catch(() => {});

    })();
    </script>

    <?php
    render_footer();
}
