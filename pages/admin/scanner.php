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
    auto_checkout_past_attendance();

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
        $todaySql = 'SELECT COUNT(*) FROM attendance WHERE check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY';
        if ($currentGymId) {
            $todaySql .= ' AND gym_id = ?';
            $todayParams[] = $currentGymId;
        }
        $stmt = $pdo->prepare($todaySql);
        $stmt->execute($todayParams);
        $todayCheckins = (int) $stmt->fetchColumn();

        $insideParams = [];
        $insideSql = 'SELECT COUNT(*) FROM attendance WHERE check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY AND check_out_time IS NULL';
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
                WHERE a.check_in_time >= CURDATE() AND a.check_in_time < CURDATE() + INTERVAL 1 DAY';
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
                   (SELECT attendance_id FROM attendance WHERE user_id = u.user_id AND check_out_time IS NULL AND check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY ORDER BY check_in_time DESC LIMIT 1) as active_attendance_id,
                   (SELECT check_in_time FROM attendance WHERE user_id = u.user_id AND check_out_time IS NULL AND check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY ORDER BY check_in_time DESC LIMIT 1) as active_check_in_time
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

    // AJAX: Fetch compact roster for offline cache
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_offline_roster') {
        header('Content-Type: application/json');

        $rosterSql = '
            SELECT u.user_id, u.first_name, u.last_name, u.role, u.email, u.phone, u.qr_token, u.profile_picture,
                   (SELECT attendance_id FROM attendance WHERE user_id = u.user_id AND check_out_time IS NULL AND check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY ORDER BY check_in_time DESC LIMIT 1) as active_attendance_id
            FROM users u
            WHERE u.status = "active" AND u.role IN ("member", "trainer")
        ';
        $params = [];
        if ($currentGymId && $user['role'] !== 'platform_admin') {
            $rosterSql .= ' AND (
                u.user_id IN (SELECT user_id FROM gym_members WHERE gym_id = ?)
                OR u.user_id IN (SELECT user_id FROM trainer_profiles WHERE gym_id = ?)
                OR u.user_id IN (SELECT m.user_id FROM memberships m JOIN membership_plans mp ON m.plan_id = mp.plan_id WHERE mp.gym_id = ? AND m.status = "active")
            )';
            $params = [$currentGymId, $currentGymId, $currentGymId];
        }
        $rosterSql .= ' ORDER BY u.first_name ASC LIMIT 500';

        $stmt = $pdo->prepare($rosterSql);
        $stmt->execute($params);
        $members = $stmt->fetchAll();

        $gymName = $currentGymId ? scalar('SELECT name FROM gyms WHERE gym_id = ?', [$currentGymId]) : 'FitTracks Central';
        $walkInFee = $currentGymId ? (float) (scalar('SELECT walk_in_fee FROM gyms WHERE gym_id = ?', [$currentGymId]) ?: 100.0) : 100.0;

        $items = array_map(function($m) {
            return [
                'user_id' => (int) $m['user_id'],
                'name' => trim($m['first_name'] . ' ' . $m['last_name']),
                'role' => ucfirst($m['role']),
                'email' => $m['email'],
                'phone' => $m['phone'] ?: '',
                'qr_token' => $m['qr_token'] ?: '',
                'avatar_html' => render_avatar($m, 'medium'),
                'is_inside' => !empty($m['active_attendance_id'])
            ];
        }, $members);

        echo json_encode([
            'success' => true,
            'gym_id' => $currentGymId,
            'gym_name' => $gymName,
            'walk_in_fee' => $walkInFee,
            'members' => $items,
            'server_time' => date('Y-m-d H:i:s')
        ]);
        exit;
    }

    // AJAX: Sync batch of offline attendance logs
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'sync_offline_batch') {
        header('Content-Type: application/json');

        $batchJson = post('batch');
        $batch = json_decode((string) $batchJson, true);
        if (!is_array($batch) || empty($batch)) {
            echo json_encode(['success' => false, 'message' => 'Empty or invalid offline batch payload.']);
            exit;
        }

        $syncedIds = [];
        $errors = [];

        foreach ($batch as $entry) {
            $tempId = $entry['temp_id'] ?? null;
            $actionType = $entry['action'] ?? 'process_qr';
            $scannedAt = !empty($entry['scanned_at']) ? date('Y-m-d H:i:s', strtotime($entry['scanned_at'])) : date('Y-m-d H:i:s');
            $scanDate = date('Y-m-d', strtotime($scannedAt));

            $userId = null;
            $method = 'qr_code';

            if ($actionType === 'manual_checkin') {
                $userId = (int) ($entry['user_id'] ?? 0);
                $method = 'manual';
            } else {
                $qrData = $entry['qr_data'] ?? '';
                if (str_contains((string) $qrData, ':')) {
                    list($rawUid, ) = explode(':', (string) $qrData, 2);
                    $userId = (int) $rawUid;
                } else {
                    $userId = (int) ($entry['user_id'] ?? 0);
                }
                $method = 'qr_code';
            }

            if (!$userId) {
                $errors[] = "Missing user ID for temp entry #{$tempId}";
                continue;
            }

            // Check if member exists
            $mCheck = $pdo->prepare('SELECT user_id, first_name, last_name, role FROM users WHERE user_id = ?');
            $mCheck->execute([$userId]);
            $memberInfo = $mCheck->fetch();
            if (!$memberInfo) {
                $errors[] = "User #{$userId} not found for entry #{$tempId}";
                continue;
            }

            // Check for open attendance record on that specific date
            $stmt = $pdo->prepare('
                SELECT attendance_id, check_in_time 
                FROM attendance 
                WHERE user_id = ? AND check_out_time IS NULL 
                  AND check_in_time >= ? AND check_in_time < DATE_ADD(?, INTERVAL 1 DAY)
                ORDER BY check_in_time DESC LIMIT 1
            ');
            $stmt->execute([$userId, $scanDate, $scanDate]);
            $openRecord = $stmt->fetch();

            if ($openRecord) {
                // Check-out
                $pdo->prepare('UPDATE attendance SET check_out_time = ? WHERE attendance_id = ?')
                    ->execute([$scannedAt, $openRecord['attendance_id']]);
                if (function_exists('release_user_equipment_on_checkout')) {
                    release_user_equipment_on_checkout((int)$userId);
                }
                audit_log($user['user_id'], 'offline_sync_checkout', 'attendance', (string) $openRecord['attendance_id'], json_encode([
                    'user_id' => $userId,
                    'scanned_at' => $scannedAt,
                    'method' => $method
                ]));
            } else {
                // Check-in
                $stmtIns = $pdo->prepare('
                    INSERT INTO attendance (user_id, schedule_id, gym_id, check_in_time, check_in_method, recorded_by)
                    VALUES (?, NULL, ?, ?, ?, ?)
                ');
                $stmtIns->execute([$userId, $currentGymId, $scannedAt, $method, $user['user_id']]);
                $newAttId = (int) $pdo->lastInsertId();

                if ($memberInfo['role'] === 'member' && $currentGymId) {
                    $pdo->prepare('INSERT IGNORE INTO gym_members (user_id, gym_id) VALUES (?, ?)')->execute([$userId, $currentGymId]);
                }

                // If payment was recorded
                $amount = (float) ($entry['amount_paid'] ?? 0);
                if ($amount > 0 && $currentGymId) {
                    $payMethod = in_array($entry['payment_method'] ?? '', ['cash', 'gcash', 'card']) ? $entry['payment_method'] : 'cash';
                    $gName = trim($memberInfo['first_name'] . ' ' . $memberInfo['last_name']);
                    $contact = scalar('SELECT phone FROM users WHERE user_id = ?', [$userId]) ?: 'N/A';
                    $pdo->prepare('
                        INSERT INTO walk_in_transactions (gym_id, guest_name, contact_info, amount_paid, payment_method, visit_date, processed_by, converted_to_member_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ')->execute([$currentGymId, $gName, $contact, $amount, $payMethod, $scannedAt, $user['user_id'], $userId]);
                }

                audit_log($user['user_id'], 'offline_sync_checkin', 'attendance', (string) $newAttId, json_encode([
                    'user_id' => $userId,
                    'scanned_at' => $scannedAt,
                    'method' => $method
                ]));
            }

            $syncedIds[] = $tempId;
        }

        echo json_encode([
            'success' => true,
            'synced_count' => count($syncedIds),
            'synced_ids' => $syncedIds,
            'errors' => $errors,
            'stats' => $getStats(),
            'recent' => $getRecentActivity(15)
        ]);
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

        // Auto-checkout lingering attendance from past days for this user first
        auto_checkout_past_attendance($targetUserId);

        // Check for open attendance record for today
        $stmt = $pdo->prepare('SELECT attendance_id, check_in_time FROM attendance WHERE user_id = ? AND check_out_time IS NULL AND check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY ORDER BY check_in_time DESC LIMIT 1');
        $stmt->execute([$targetUserId]);
        $openRecord = $stmt->fetch();

        $actionType = 'checkin';
        $attendedClassTitle = null;
        $sessionDuration = null;

        if ($openRecord) {
            // Check-out
            $pdo->prepare('UPDATE attendance SET check_out_time = NOW() WHERE attendance_id = ?')->execute([$openRecord['attendance_id']]);
            if (function_exists('release_user_equipment_on_checkout')) {
                release_user_equipment_on_checkout((int)$targetUserId);
            }
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

            if (!$scheduleId && $member['role'] === 'trainer') {
                $trainerClass = $pdo->prepare('
                    SELECT s.schedule_id, c.class_name
                    FROM class_schedules s
                    JOIN classes c ON c.class_id = s.class_id
                    WHERE c.instructor_id = ?
                      AND s.start_datetime >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                      AND s.start_datetime <= DATE_ADD(NOW(), INTERVAL 2 HOUR)
                    ORDER BY s.start_datetime ASC LIMIT 1
                ');
                $trainerClass->execute([$targetUserId]);
                $teachingClass = $trainerClass->fetch();
                if ($teachingClass) {
                    $scheduleId = (int) $teachingClass['schedule_id'];
                    $attendedClassTitle = $teachingClass['class_name'];
                }
            }

            $stmt = $pdo->prepare('INSERT INTO attendance (user_id, schedule_id, gym_id, check_in_time, check_in_method, recorded_by) VALUES (?, ?, ?, NOW(), "manual", ?)');
            $stmt->execute([$targetUserId, $scheduleId, $currentGymId, $user['user_id']]);
            $newAttendanceId = (int) $pdo->lastInsertId();

            if ($member['role'] === 'member' && $currentGymId) {
                $pdo->prepare('INSERT IGNORE INTO gym_members (user_id, gym_id) VALUES (?, ?)')->execute([$targetUserId, $currentGymId]);
            }

            if ($scheduleId) {
                if ($member['role'] === 'member') {
                    $pdo->prepare('UPDATE class_bookings SET booking_status = "attended" WHERE user_id = ? AND schedule_id = ?')->execute([$targetUserId, $scheduleId]);
                    $message = 'Check-in recorded & Class attended for ' . $member['first_name'] . ' ' . $member['last_name'];
                } else {
                    $message = 'Instructor Check-in recorded for ' . $member['first_name'] . ' ' . $member['last_name'] . ' (' . $attendedClassTitle . ')';
                }
            } else {
                $message = 'Check-in recorded for ' . $member['first_name'] . ' ' . $member['last_name'];
            }
            audit_log($user['user_id'], 'manual_checkin', 'attendance', (string) $newAttendanceId, json_encode(['user_id' => $targetUserId, 'schedule_id' => $scheduleId]));
        }

        $planName = null;
        if ($member['role'] === 'member') {
            if ($currentGymId) {
                $planName = scalar('
                    SELECT mp.plan_name 
                    FROM memberships m 
                    JOIN membership_plans mp ON m.plan_id = mp.plan_id 
                    WHERE m.user_id = ? AND mp.gym_id = ? AND m.status = "active" AND m.end_date >= CURDATE()
                    ORDER BY m.end_date DESC LIMIT 1',
                    [$targetUserId, $currentGymId]
                );
            }
            if (!$planName) {
                $planName = scalar('
                    SELECT mp.plan_name 
                    FROM memberships m 
                    JOIN membership_plans mp ON m.plan_id = mp.plan_id 
                    WHERE m.user_id = ? AND m.status = "active" AND m.end_date >= CURDATE()
                    ORDER BY m.end_date DESC LIMIT 1',
                    [$targetUserId]
                );
            }
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
        
        // Auto-checkout lingering attendance from past days for this user first
        auto_checkout_past_attendance($userId);

        // Check for open attendance record for today
        $stmt = $pdo->prepare('SELECT attendance_id, check_in_time FROM attendance WHERE user_id = ? AND check_out_time IS NULL AND check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY ORDER BY check_in_time DESC LIMIT 1');
        $stmt->execute([$userId]);
        $openRecord = $stmt->fetch();

        $actionType = 'checkin';
        $attendedClassTitle = null;
        $sessionDuration = null;

        if ($openRecord) {
            // Check out
            $pdo->prepare('UPDATE attendance SET check_out_time = NOW() WHERE attendance_id = ?')->execute([$openRecord['attendance_id']]);
            if (function_exists('release_user_equipment_on_checkout')) {
                release_user_equipment_on_checkout((int)$userId);
            }
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

            if (!$scheduleId && $member['role'] === 'trainer') {
                $trainerClass = $pdo->prepare('
                    SELECT s.schedule_id, c.class_name
                    FROM class_schedules s
                    JOIN classes c ON c.class_id = s.class_id
                    WHERE c.instructor_id = ?
                      AND s.start_datetime >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                      AND s.start_datetime <= DATE_ADD(NOW(), INTERVAL 2 HOUR)
                    ORDER BY s.start_datetime ASC LIMIT 1
                ');
                $trainerClass->execute([$userId]);
                $teachingClass = $trainerClass->fetch();
                if ($teachingClass) {
                    $scheduleId = (int) $teachingClass['schedule_id'];
                    $attendedClassTitle = $teachingClass['class_name'];
                }
            }

            $stmt = $pdo->prepare('INSERT INTO attendance (user_id, schedule_id, gym_id, check_in_time, check_in_method, recorded_by) VALUES (?, ?, ?, NOW(), "qr_code", ?)');
            $stmt->execute([$userId, $scheduleId, $currentGymId, $user['user_id']]);
            $newAttendanceId = (int) $pdo->lastInsertId();
            
            if ($member['role'] === 'member' && $currentGymId) {
                $pdo->prepare('INSERT IGNORE INTO gym_members (user_id, gym_id) VALUES (?, ?)')
                    ->execute([$userId, $currentGymId]);
            }
            
            if ($scheduleId) {
                if ($member['role'] === 'member') {
                    $pdo->prepare('UPDATE class_bookings SET booking_status = "attended" WHERE user_id = ? AND schedule_id = ?')->execute([$userId, $scheduleId]);
                    $message = 'Check-in verified & Class Auto-Attended for ' . $member['first_name'] . ' ' . $member['last_name'];
                } else {
                    $message = 'Instructor Check-in verified for ' . $member['first_name'] . ' ' . $member['last_name'] . ' (' . $attendedClassTitle . ')';
                }
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
            if ($currentGymId) {
                $planName = scalar('
                    SELECT mp.plan_name 
                    FROM memberships m 
                    JOIN membership_plans mp ON m.plan_id = mp.plan_id 
                    WHERE m.user_id = ? AND mp.gym_id = ? AND m.status = "active" AND m.end_date >= CURDATE()
                    ORDER BY m.end_date DESC LIMIT 1',
                    [$userId, $currentGymId]
                );
            }
            if (!$planName) {
                $planName = scalar('
                    SELECT mp.plan_name 
                    FROM memberships m 
                    JOIN membership_plans mp ON m.plan_id = mp.plan_id 
                    WHERE m.user_id = ? AND m.status = "active" AND m.end_date >= CURDATE()
                    ORDER BY m.end_date DESC LIMIT 1',
                    [$userId]
                );
            }
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

    <link rel="stylesheet" href="<?= h(asset_url('css/pages/scanner.css')) ?>">

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
                <!-- Offline Connection & Queue Status HUD -->
                <div class="terminal-offline-hud" id="terminal-offline-hud">
                    <span class="net-status-badge is-online" id="net-status-badge" title="Connection status. Automatically switches to offline mode during brownouts.">
                        <span class="net-status-dot"></span>
                        <span id="net-status-label">ONLINE</span>
                    </span>
                    <button type="button" class="term-btn-icon btn-offline-sync-pill" id="btn-offline-sync" style="display: none;" onclick="triggerManualSync()" title="Click to sync offline queued scans to live server">
                        <span class="sync-spin-icon">⚡</span>
                        <span id="offline-pending-count">0 Queued</span>
                    </button>
                    <button type="button" class="term-btn-icon" id="btn-cache-roster" onclick="refreshOfflineRoster(true)" title="Local offline member cache. Click to refresh.">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                        <span id="roster-cache-text">Cache: --</span>
                    </button>
                </div>

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

    <!-- HTML5 QR Code Library (Local offline first, CDN fallback) -->
    <script src="assets/html5-qrcode.min.js"></script>
    <script>
        if (typeof Html5Qrcode === 'undefined') {
            document.write('<script src="https://unpkg.com/html5-qrcode"><\/script>');
        }
    </script>

    <script>
    window.SCANNER_CONFIG = {
        csrfToken: <?= json_encode(csrf_token()) ?>
    };
    </script>
    <script src="<?= h(asset_url('js/pages/scanner.js')) ?>"></script>

    <?php
    render_footer();
}
