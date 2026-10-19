<?php
declare(strict_types=1);

function attendance_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner', 'staff']);
    auto_checkout_past_attendance();
    
    $currentGymId = null;
    if ($user['role'] === 'gym_owner') {
        $gid = scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
        $currentGymId = $gid ? (int)$gid : null;
    } elseif ($user['role'] === 'staff') {
        $gid = scalar('SELECT gym_id FROM gym_staff WHERE user_id = ?', [$user['user_id']]);
        $currentGymId = $gid ? (int)$gid : null;
    } else {
        if (!empty($_GET['gym_id'])) {
            $currentGymId = (int) $_GET['gym_id'];
        } else {
            $gid = scalar('SELECT gym_id FROM gyms ORDER BY gym_id ASC LIMIT 1');
            $currentGymId = $gid ? (int)$gid : null;
        }
    }

    // AJAX Hybrid Live Search Endpoint for Available Gym Equipment
    if (($_GET['action'] ?? post('action')) === 'search_equipment') {
        if (ob_get_level()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        $q = trim((string)($_GET['q'] ?? post('q') ?? ''));

        $sql = 'SELECT equipment_id, name, unit_number, category, location_area 
                FROM gym_equipment 
                WHERE gym_id = ? AND status = "available"';
        $params = [(int)$currentGymId];
        if ($q !== '') {
            $sql .= ' AND (name LIKE ? OR unit_number LIKE ? OR category LIKE ? OR location_area LIKE ?)';
            $pat = '%' . $q . '%';
            $params[] = $pat;
            $params[] = $pat;
            $params[] = $pat;
            $params[] = $pat;
        }
        $sql .= ' ORDER BY category ASC, name ASC, unit_number ASC LIMIT 100';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $eqResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dropdownItems = [];
        $dropdownItems[] = [
            'id'       => '',
            'label'    => '— None / General Floor Access —',
            'subtitle' => 'General floor entry without assigned machine'
        ];

        $grouped = [];
        foreach ($eqResults as $eq) {
            $c = !empty($eq['category']) ? $eq['category'] : 'Equipment';
            $grouped[$c][] = $eq;
        }

        foreach ($grouped as $catName => $items) {
            $dropdownItems[] = [
                'label'    => $catName . ' (' . count($items) . ')',
                'isHeader' => true
            ];
            foreach ($items as $eqItem) {
                $uNum = trim((string)($eqItem['unit_number'] ?? ''));
                $uStr = ($uNum !== '' && $uNum !== '#1' && $uNum !== '1') ? ' ' . $uNum : '';
                $loc = !empty($eqItem['location_area']) ? $eqItem['location_area'] : $catName;
                $dropdownItems[] = [
                    'id'       => (int)$eqItem['equipment_id'],
                    'label'    => $eqItem['name'] . $uStr,
                    'subtitle' => $loc,
                    'badge'    => $catName
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'count'   => count($eqResults),
            'results' => $dropdownItems
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (post('action') === 'checkout') {
            $attId = (int) post('attendance_id');
            $attInfo = db()->prepare('SELECT user_id, gym_id FROM attendance WHERE attendance_id = ?');
            $attInfo->execute([$attId]);
            $attRow = $attInfo->fetch(PDO::FETCH_ASSOC);

            $checkoutSql = ($user['role'] === 'gym_owner' && $currentGymId)
                ? 'UPDATE attendance SET check_out_time = NOW() WHERE attendance_id = ? AND (gym_id = ? OR recorded_by = ?)'
                : 'UPDATE attendance SET check_out_time = NOW() WHERE attendance_id = ?';
            $checkoutParams = ($user['role'] === 'gym_owner' && $currentGymId)
                ? [$attId, $currentGymId, $user['user_id']]
                : [$attId];
            db()->prepare($checkoutSql)->execute($checkoutParams);

            if ($attRow && !empty($attRow['user_id'])) {
                if (function_exists('release_user_equipment_on_checkout')) {
                    release_user_equipment_on_checkout((int)$attRow['user_id'], $currentGymId ?: ((int)($attRow['gym_id'] ?? 0) ?: null));
                }
            }

            audit_log($user['user_id'], 'checkout', 'attendance', (string) $attId);
            flash('Check-out recorded.');
        } else {
            $userId = (int) post('user_id');
            $scheduleId = post('schedule_id') ? (int) post('schedule_id') : null;
            $equipmentId = post('equipment_id') ? (int) post('equipment_id') : 0;
            $method = post('check_in_method') ?: 'manual';

            // Resolve gym_id for this check-in
            $gymId = $currentGymId;
            if (!$gymId && $scheduleId) {
                $gymId = (int) scalar('SELECT c.gym_id FROM class_schedules s JOIN classes c ON c.class_id = s.class_id WHERE s.schedule_id = ?', [$scheduleId]);
            }
            if (!$gymId) {
                $gymId = (int) scalar('SELECT gym_id FROM gym_members WHERE user_id = ? LIMIT 1', [$userId]);
            }
            if (!$gymId && post('gym_id')) {
                $gymId = (int) post('gym_id');
            }

            // Prevent duplicate check-ins (auto-close past days first)
            auto_checkout_past_attendance($userId);
            $dupSql = 'SELECT attendance_id FROM attendance WHERE user_id = ? AND check_out_time IS NULL AND DATE(check_in_time) = CURDATE()';
            $dupParams = [$userId];
            if ($gymId) {
                $dupSql .= ' AND (gym_id = ? OR gym_id IS NULL)';
                $dupParams[] = $gymId;
            }
            $activeCheckin = db()->prepare($dupSql);
            $activeCheckin->execute($dupParams);
            if ($activeCheckin->fetchColumn()) {
                flash('User is already checked in and must check out first.', 'error');
                redirect('attendance');
                return;
            }
            
            db()->prepare('INSERT INTO attendance (user_id, schedule_id, gym_id, check_in_time, check_in_method, recorded_by) VALUES (?, ?, ?, NOW(), ?, ?)')
                ->execute([$userId, $scheduleId, $gymId ?: null, $method, $user['user_id']]);
            $newAttId = (string) db()->lastInsertId();
            
            if ($gymId) {
                // Ensure member is affiliated with the gym
                db()->prepare('INSERT IGNORE INTO gym_members (user_id, gym_id) VALUES (?, ?)')
                    ->execute([$userId, $gymId]);
            }

            if ($scheduleId) {
                // Automatically mark their class booking as attended so they get Engagement Points
                db()->prepare('UPDATE class_bookings SET booking_status = "attended" WHERE user_id = ? AND schedule_id = ?')->execute([$userId, $scheduleId]);
            }

            // Assign equipment if selected AND belongs strictly to this gym
            $assignedEquipName = null;
            if ($equipmentId > 0 && $gymId > 0) {
                $claimStmt = db()->prepare('UPDATE gym_equipment SET status = "in_use" WHERE equipment_id = ? AND gym_id = ? AND status = "available"');
                $claimStmt->execute([$equipmentId, $gymId]);
                if ($claimStmt->rowCount() > 0) {
                    $sessStmt = db()->prepare('INSERT INTO equipment_sessions (gym_id, equipment_id, user_id, start_time, session_status) VALUES (?, ?, ?, NOW(), "active")');
                    $sessStmt->execute([$gymId, $equipmentId, $userId]);
                    $sessionId = (int) db()->lastInsertId();
                    db()->prepare('UPDATE gym_equipment SET current_session_id = ? WHERE equipment_id = ?')->execute([$sessionId, $equipmentId]);
                    $assignedEquipName = scalar('SELECT name FROM gym_equipment WHERE equipment_id = ?', [$equipmentId]);
                }
            }
            
            audit_log($user['user_id'], 'checkin', 'attendance', $newAttId, json_encode([
                'user_id' => $userId,
                'gym_id' => $gymId,
                'equipment_id' => $equipmentId ?: null,
                'method' => $method
            ]));

            if ($assignedEquipName) {
                flash('Check-in recorded and assigned to ' . $assignedEquipName . '.');
            } else {
                flash('Check-in recorded.');
            }
        }
        redirect('attendance');
    }

    // Only load equipment belonging strictly to this gym and currently available
    $availableEquipment = [];
    if ($currentGymId) {
        $eqStmt = db()->prepare('
            SELECT equipment_id, name, unit_number, category, location_area 
            FROM gym_equipment 
            WHERE gym_id = ? AND status = "available" 
            ORDER BY category ASC, name ASC, unit_number ASC
        ');
        $eqStmt->execute([$currentGymId]);
        $availableEquipment = $eqStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Paginate attendance records so the page can browse the full history instead of a fixed first 100.
    $attendancePerPage = 15;
    $attendancePage = max(1, (int)($_GET['attendance_page'] ?? 1));
    $attendanceSearch = trim((string)($_GET['attendance_search'] ?? ''));
    $attendanceFrom = trim((string)($_GET['attendance_from'] ?? ''));
    $attendanceTo = trim((string)($_GET['attendance_to'] ?? ''));
    $attendanceStatus = (string)($_GET['attendance_status'] ?? '');
    $validDate = static function (string $value): string {
        $formats = ['!m/d/y' => 'm/d/y', '!Y-m-d' => 'Y-m-d'];
        foreach ($formats as $inputFormat => $outputFormat) {
            $date = DateTimeImmutable::createFromFormat($inputFormat, $value);
            if ($date && $date->format($outputFormat) === $value) return $date->format('Y-m-d');
        }
        return '';
    };
    $attendanceFrom = $validDate($attendanceFrom);
    $attendanceTo = $validDate($attendanceTo);
    if (!in_array($attendanceStatus, ['in_gym', 'checked_out'], true)) $attendanceStatus = '';

    $attendanceWhereParts = [];
    $attendanceFilterParams = [];
    if ($user['role'] === 'gym_owner' && $currentGymId) {
        $attendanceWhereParts[] = '(a.gym_id = ? OR a.recorded_by = ?)';
        $attendanceFilterParams[] = $currentGymId;
        $attendanceFilterParams[] = (int)$user['user_id'];
    }
    if ($attendanceSearch !== '') {
        $attendanceWhereParts[] = 'CONCAT(u.first_name, " ", u.last_name) LIKE ?';
        $attendanceFilterParams[] = '%' . $attendanceSearch . '%';
    }
    if ($attendanceFrom !== '') {
        $attendanceWhereParts[] = 'a.check_in_time >= ?';
        $attendanceFilterParams[] = $attendanceFrom . ' 00:00:00';
    }
    if ($attendanceTo !== '') {
        $attendanceWhereParts[] = 'a.check_in_time < DATE_ADD(?, INTERVAL 1 DAY)';
        $attendanceFilterParams[] = $attendanceTo;
    }
    if ($attendanceStatus === 'in_gym') $attendanceWhereParts[] = 'a.check_out_time IS NULL';
    if ($attendanceStatus === 'checked_out') $attendanceWhereParts[] = 'a.check_out_time IS NOT NULL';
    $attendanceWhereSql = $attendanceWhereParts ? implode(' AND ', $attendanceWhereParts) : '1=1';
    $attendanceFilterActive = $attendanceSearch !== '' || $attendanceFrom !== '' || $attendanceTo !== '' || $attendanceStatus !== '';

    $countStmt = db()->prepare('SELECT COUNT(*) FROM attendance a JOIN users u ON u.user_id = a.user_id WHERE ' . $attendanceWhereSql);
    $countStmt->execute($attendanceFilterParams);
    $attendanceTotal = (int)$countStmt->fetchColumn();
    $attendanceTotalPages = max(1, (int)ceil($attendanceTotal / $attendancePerPage));
    $attendancePage = min($attendancePage, $attendanceTotalPages);
    $attendanceOffset = ($attendancePage - 1) * $attendancePerPage;

    if ($user['role'] === 'gym_owner' && $currentGymId) {
        $members = db()->query('
            SELECT DISTINCT u.user_id, 
                   u.first_name,
                   u.last_name,
                   u.email,
                   u.profile_picture,
                   u.role,
                   1 AS is_affiliated,
                   CONCAT(u.first_name, " ", u.last_name, " (", u.role, ")") AS name
            FROM users u 
            LEFT JOIN gym_members gm ON gm.user_id = u.user_id AND gm.gym_id = ' . (int)$currentGymId . '
            LEFT JOIN trainer_profiles tp ON tp.user_id = u.user_id AND tp.gym_id = ' . (int)$currentGymId . '
            WHERE u.role IN ("member", "trainer") 
              AND u.status = "active"
              AND (gm.gym_id = ' . (int)$currentGymId . ' OR tp.gym_id = ' . (int)$currentGymId . ')
            ORDER BY u.first_name ASC, u.last_name ASC
        ')->fetchAll();

        $schedules = db()->query('
            SELECT s.schedule_id, CONCAT(c.class_name, " - ", DATE_FORMAT(s.start_datetime, "%b %d %h:%i %p")) AS label 
            FROM class_schedules s 
            JOIN classes c ON c.class_id = s.class_id 
            WHERE c.gym_id = ' . (int)$currentGymId . ' AND s.start_datetime >= DATE_SUB(NOW(), INTERVAL 1 DAY) 
            ORDER BY s.start_datetime
        ')->fetchAll();

        $rowsStmt = db()->prepare('
            SELECT a.*, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name, u.role, c.class_name,
                   (SELECT CONCAT(ge.name, IF(ge.unit_number != "" AND ge.unit_number != "#1" AND ge.unit_number != "1", CONCAT(" (", ge.unit_number, ")"), ""))
                    FROM equipment_sessions es
                    JOIN gym_equipment ge ON ge.equipment_id = es.equipment_id
                    WHERE es.user_id = a.user_id AND es.session_status = "active" AND es.gym_id = ' . (int)$currentGymId . '
                    ORDER BY es.session_id DESC LIMIT 1) AS active_equipment
            FROM attendance a 
            JOIN users u ON u.user_id = a.user_id 
            LEFT JOIN class_schedules s ON s.schedule_id = a.schedule_id 
            LEFT JOIN classes c ON c.class_id = s.class_id 
            WHERE ' . $attendanceWhereSql . '
            ORDER BY a.check_in_time DESC 
            LIMIT ' . $attendancePerPage . ' OFFSET ' . $attendanceOffset . '
        ');
        $rowsStmt->execute($attendanceFilterParams);
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $members = db()->query('
            SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.email, u.profile_picture, u.role, 1 AS is_affiliated, CONCAT(u.first_name, " ", u.last_name, " (", u.role, ")") AS name 
            FROM users u 
            LEFT JOIN gym_members gm ON gm.user_id = u.user_id ' . ($currentGymId ? 'AND gm.gym_id = ' . (int)$currentGymId : '') . '
            LEFT JOIN trainer_profiles tp ON tp.user_id = u.user_id ' . ($currentGymId ? 'AND tp.gym_id = ' . (int)$currentGymId : '') . '
            WHERE u.role IN ("member", "trainer") AND u.status = "active" ' . ($currentGymId ? 'AND (gm.gym_id = ' . (int)$currentGymId . ' OR tp.gym_id = ' . (int)$currentGymId . ')' : '') . '
            ORDER BY u.role, u.first_name, u.last_name
        ')->fetchAll();
        $schedules = db()->query('SELECT s.schedule_id, CONCAT(c.class_name, " - ", DATE_FORMAT(s.start_datetime, "%b %d %h:%i %p")) AS label FROM class_schedules s JOIN classes c ON c.class_id = s.class_id WHERE s.start_datetime >= DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY s.start_datetime')->fetchAll();
        $rowsStmt = db()->prepare('
            SELECT a.*, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name, u.role, c.class_name,
                   (SELECT CONCAT(ge.name, IF(ge.unit_number != "" AND ge.unit_number != "#1" AND ge.unit_number != "1", CONCAT(" (", ge.unit_number, ")"), ""))
                    FROM equipment_sessions es
                    JOIN gym_equipment ge ON ge.equipment_id = es.equipment_id
                    WHERE es.user_id = a.user_id AND es.session_status = "active" ' . ($currentGymId ? 'AND es.gym_id = ' . (int)$currentGymId : '') . '
                    ORDER BY es.session_id DESC LIMIT 1) AS active_equipment
            FROM attendance a 
            JOIN users u ON u.user_id = a.user_id 
            LEFT JOIN class_schedules s ON s.schedule_id = a.schedule_id 
            LEFT JOIN classes c ON c.class_id = s.class_id 
            WHERE ' . $attendanceWhereSql . '
            ORDER BY a.check_in_time DESC 
            LIMIT ' . $attendancePerPage . ' OFFSET ' . $attendanceOffset . '
        ');
        $rowsStmt->execute($attendanceFilterParams);
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Keep users with an open check-in off the manual check-in picker to prevent duplicate entries.
    $checkedInSql = 'SELECT DISTINCT user_id FROM attendance WHERE check_out_time IS NULL AND DATE(check_in_time) = CURDATE()';
    $checkedInParams = [];
    if ($currentGymId) {
        $checkedInSql .= ' AND (gym_id = ? OR gym_id IS NULL)';
        $checkedInParams[] = $currentGymId;
    }
    $checkedInStmt = db()->prepare($checkedInSql);
    $checkedInStmt->execute($checkedInParams);
    $checkedInIds = array_fill_keys(array_map('intval', $checkedInStmt->fetchAll(PDO::FETCH_COLUMN)), true);
    $availableCheckinMembers = array_values(array_filter($members ?: [], static function ($member) use ($checkedInIds): bool {
        return !isset($checkedInIds[(int)($member['user_id'] ?? 0)]);
    }));

    $checkinMembersData = array_map(function ($m) {
        $first = trim((string)($m['first_name'] ?? ''));
        $last  = trim((string)($m['last_name'] ?? ''));
        $fullName = trim($first . ' ' . $last) ?: ($m['name'] ?? 'User');
        $ini = (!empty($first) ? strtoupper(substr($first, 0, 1)) : '') . (!empty($last) ? strtoupper(substr($last, 0, 1)) : '');
        $roleName = ucfirst((string)($m['role'] ?? 'Member'));
        $label = $fullName . ' (' . $roleName . ')';
        $email = (string)($m['email'] ?? '');
        return [
            'id'       => (int) $m['user_id'],
            'label'    => $label,
            'subtitle' => $email,
            'initials' => $ini ?: 'U',
            'avatar'   => !empty($m['profile_picture']) ? upload_url($m['profile_picture']) : null
        ];
    }, $availableCheckinMembers);

    // Prepare Equipment items for Global FitDropdown with Category Headers & Subtitles
    $checkinEquipmentData = [];
    $checkinEquipmentData[] = [
        'id'       => '',
        'label'    => '— None / General Floor Access —',
        'subtitle' => 'General floor entry without assigned machine'
    ];
    $groupedEquip = [];
    foreach ($availableEquipment as $eq) {
        $catName = !empty($eq['category']) ? $eq['category'] : 'Equipment';
        $groupedEquip[$catName][] = $eq;
    }
    foreach ($groupedEquip as $catName => $items) {
        $checkinEquipmentData[] = [
            'label'    => $catName . ' (' . count($items) . ')',
            'isHeader' => true
        ];
        foreach ($items as $eqItem) {
            $uNum = trim((string)($eqItem['unit_number'] ?? ''));
            $uStr = ($uNum !== '' && $uNum !== '#1' && $uNum !== '1') ? ' ' . $uNum : '';
            $loc = !empty($eqItem['location_area']) ? $eqItem['location_area'] : $catName;
            $checkinEquipmentData[] = [
                'id'       => (int)$eqItem['equipment_id'],
                'label'    => $eqItem['name'] . $uStr,
                'subtitle' => $loc,
                'badge'    => $catName
            ];
        }
    }

    // Prepare Session items for Global FitDropdown
    $checkinSessionsData = [
        ['id' => '', 'label' => '— General Check-in —', 'subtitle' => 'Standard floor workout']
    ];
    foreach ($schedules as $s) {
        $checkinSessionsData[] = [
            'id'       => (int)$s['schedule_id'],
            'label'    => $s['label'],
            'subtitle' => 'Scheduled Class Session'
        ];
    }

    render_header('Attendance', $user);
    ?>
    <div class="skeleton-wrapper">
        <section class="panel">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
                <div>
                    <div class="sk sk-text" style="width:280px;height:12px"></div>
                </div>
                <div class="sk sk-rect" style="width:140px;height:36px;border-radius:18px"></div>
            </div>
            <div style="margin-top:24px">
                <div class="sk sk-title" style="width:180px;margin-bottom:12px"></div>
                <?php render_skeleton_table(8, 7); ?>
            </div>
        </section>
    </div>
    <section class="panel skeleton-content sk-display-block">
        <div class="page-header">
            <div>
                <p style="margin: 0;">Record user check-ins and check-outs for gym visits and classes.</p>
            </div>
            <button onclick="recordCheckin()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold;">+ New Check-in</button>
        </div>



        <form method="get" action="index.php" class="attendance-filter-form">
            <input type="hidden" name="page" value="attendance">
            <?php if ($currentGymId): ?><input type="hidden" name="gym_id" value="<?= (int)$currentGymId ?>"><?php endif; ?>
            <label class="attendance-filter-group attendance-filter-member">
                Member
                <input type="search" name="attendance_search" value="<?= h($attendanceSearch) ?>" placeholder="Search member name" class="attendance-filter-input">
            </label>
            <label class="attendance-filter-group attendance-filter-from">
                From
                <span class="attendance-filter-date-wrap" style="position:relative;display:block;width:100%;">
                    <input type="text" name="attendance_from" data-attendance-date-text="from" value="<?= $attendanceFrom !== '' ? h(date('m/d/y', strtotime($attendanceFrom))) : '' ?>" placeholder="MM/DD/YY" inputmode="numeric" maxlength="8" pattern="\d{2}/\d{2}/\d{2}" class="attendance-filter-input" style="padding-right:40px;">
                    <svg aria-hidden="true" viewBox="0 0 24 24" width="16" height="16" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none;"><rect x="3" y="5" width="18" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16 3v4M8 3v4M3 11h18" fill="none" stroke="currentColor" stroke-width="2"/></svg>
                    <input type="date" data-attendance-date-picker="from" value="<?= h($attendanceFrom) ?>" aria-label="Choose start date" style="position:absolute;right:0;top:0;width:40px;height:100%;opacity:0;cursor:pointer;">
                </span>
            </label>
            <label class="attendance-filter-group attendance-filter-to">
                To
                <span class="attendance-filter-date-wrap" style="position:relative;display:block;width:100%;">
                    <input type="text" name="attendance_to" data-attendance-date-text="to" value="<?= $attendanceTo !== '' ? h(date('m/d/y', strtotime($attendanceTo))) : '' ?>" placeholder="MM/DD/YY" inputmode="numeric" maxlength="8" pattern="\d{2}/\d{2}/\d{2}" class="attendance-filter-input" style="padding-right:40px;">
                    <svg aria-hidden="true" viewBox="0 0 24 24" width="16" height="16" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none;"><rect x="3" y="5" width="18" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16 3v4M8 3v4M3 11h18" fill="none" stroke="currentColor" stroke-width="2"/></svg>
                    <input type="date" data-attendance-date-picker="to" value="<?= h($attendanceTo) ?>" aria-label="Choose end date" style="position:absolute;right:0;top:0;width:40px;height:100%;opacity:0;cursor:pointer;">
                </span>
            </label>
            <label class="attendance-filter-group attendance-filter-status">
                Status
                <select name="attendance_status" class="attendance-filter-select">
                    <option value="">All statuses</option>
                    <option value="in_gym" <?= $attendanceStatus === 'in_gym' ? 'selected' : '' ?>>In gym</option>
                    <option value="checked_out" <?= $attendanceStatus === 'checked_out' ? 'selected' : '' ?>>Checked out</option>
                </select>
            </label>
            <div class="attendance-filter-actions">
                <button type="submit" class="btn btn-primary">Filter</button>
                <?php if ($attendanceFilterActive): ?>
                    <a href="<?= h('index.php?' . http_build_query(array_filter(['page' => 'attendance', 'gym_id' => $currentGymId]))) ?>" class="btn btn-secondary">Clear</a>
                <?php endif; ?>
            </div>
        </form>
        <p class="section-label">Attendance history<?= $attendanceFilterActive ? ' (' . $attendanceTotal . ' matching)' : '' ?></p>
        <?php if (!$rows): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <p><?= $attendanceFilterActive ? 'No attendance records match these filters.' : 'No attendance records yet.<br>Use the form above to record a check-in.' ?></p>
            </div>
        <?php else: ?>
        <div class="table-wrap attendance-desktop-table">
            <table>
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Session</th>
                        <th>Equipment</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row):
                    $initials = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
                    $checkedOut = !empty($row['check_out_time']);
                ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <span class="avatar small"><?= h($initials) ?></span>
                                <span><?= h($row['member']) ?></span>
                            </div>
                        </td>
                        <td>
                            <span style="font-size:12px; color:var(--muted);"><?= h(ucfirst($row['role'])) ?></span>
                        </td>
                        <td>
                            <?php if ($row['class_name']): ?>
                                <?= h($row['class_name']) ?>
                            <?php elseif ($row['role'] === 'trainer'): ?>
                                <span style="color:var(--muted);font-size:12px;">Shift</span>
                            <?php else: ?>
                                <span style="color:var(--muted);font-size:12px;">Gym visit</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$checkedOut && !empty($row['active_equipment'])): ?>
                                <div style="margin-top: 5px;">
                                    <span style="background: rgba(34, 197, 94, 0.12); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.3); font-size: 11px; padding: 2px 7px; display: inline-flex; align-items: center; border-radius: 4px; font-weight: 500;">
                                        <?= h($row['active_equipment']) ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= h(date('M j, h:i A', strtotime($row['check_in_time']))) ?></td>
                        <td><?= $checkedOut ? h(date('M j, h:i A', strtotime($row['check_out_time']))) : '<span class="muted">—</span>' ?></td>
                        <td><span style="color:var(--muted);font-size:12px"><?= h($row['check_in_method']) ?></span></td>
                        <td>
                            <?php if ($checkedOut): ?>
                                <span class="badge badge-active">Checked out</span>
                            <?php else: ?>
                                <span class="badge badge-pending">In gym</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$checkedOut): ?>
                                <form method="post" class="attendance-checkout-form" data-member-name="<?= h($row['member']) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="checkout">
                                    <input type="hidden" name="attendance_id" value="<?= (int) $row['attendance_id'] ?>">
                                    <button class="btn-sm btn-ghost" style="background:var(--lime); border-color:var(--lime); color:var(--bg);">Check out</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Cards View for Attendance -->
        <div class="attendance-mobile-cards">
            <?php foreach ($rows as $row):
                $initials = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
                $checkedOut = !empty($row['check_out_time']);
            ?>
            <div class="attendance-card-item">
                <div class="attendance-card-header">
                    <div class="user-cell" style="display:flex; align-items:center; gap:10px;">
                        <span class="avatar small"><?= h($initials) ?></span>
                        <div>
                            <div class="attendance-card-name"><?= h($row['member']) ?></div>
                            <div class="attendance-card-role"><?= h(ucfirst($row['role'])) ?></div>
                        </div>
                    </div>
                    <?php if ($checkedOut): ?>
                        <span class="badge badge-active">Checked out</span>
                    <?php else: ?>
                        <span class="badge badge-pending">In gym</span>
                    <?php endif; ?>
                </div>

                <div class="attendance-card-session-row">
                    <div class="attendance-card-session">
                        <span class="session-label">Session:</span>
                        <span class="session-val">
                            <?php if ($row['class_name']): ?>
                                <?= h($row['class_name']) ?>
                            <?php elseif ($row['role'] === 'trainer'): ?>
                                Shift
                            <?php else: ?>
                                Gym visit
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="attendance-card-method">
                        <?= h($row['check_in_method']) ?>
                    </div>
                </div>

                <?php if (!$checkedOut && !empty($row['active_equipment'])): ?>
                    <div class="attendance-card-equip">
                        <span class="session-label">Equipment:</span>
                        <span style="background: rgba(34, 197, 94, 0.12); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.3); font-size: 11px; padding: 3px 8px; display: inline-flex; align-items: center; border-radius: 6px; font-weight: 600;">
                            <?= h($row['active_equipment']) ?>
                        </span>
                    </div>
                <?php endif; ?>

                <div class="attendance-card-details">
                    <div class="attendance-card-detail-item">
                        <span class="detail-label">Check-in</span>
                        <span class="detail-val"><?= h(date('M j, h:i A', strtotime($row['check_in_time']))) ?></span>
                    </div>
                    <div class="attendance-card-detail-item">
                        <span class="detail-label">Check-out</span>
                        <span class="detail-val"><?= $checkedOut ? h(date('M j, h:i A', strtotime($row['check_out_time']))) : '<span class="muted">—</span>' ?></span>
                    </div>
                </div>

                <?php if (!$checkedOut): ?>
                    <div class="attendance-card-actions">
                        <form method="post" class="attendance-checkout-form" data-member-name="<?= h($row['member']) ?>" style="margin:0; width:100%;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="checkout">
                            <input type="hidden" name="attendance_id" value="<?= (int) $row['attendance_id'] ?>">
                            <button type="submit" class="btn-sm btn-ghost" style="width:100%; justify-content:center; padding:9px 12px; font-weight:600; background:var(--lime); border-color:var(--lime); color:var(--bg);">Check out</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <!-- Pagination Bar -->
        <?php if ($attendanceTotal > 0): 
            $attendanceFilterParamsList = ['page' => 'attendance'];
            if ($currentGymId) $attendanceFilterParamsList['gym_id'] = $currentGymId;
            if ($attendanceSearch !== '') $attendanceFilterParamsList['attendance_search'] = $attendanceSearch;
            if ($attendanceFrom !== '') $attendanceFilterParamsList['attendance_from'] = $attendanceFrom;
            if ($attendanceTo !== '') $attendanceFilterParamsList['attendance_to'] = $attendanceTo;
            if ($attendanceStatus !== '') $attendanceFilterParamsList['attendance_status'] = $attendanceStatus;
            $attendanceBaseUrl = 'index.php?' . http_build_query($attendanceFilterParamsList);
        ?>
            <div class="pagination-bar">
                <div class="pagination-info">
                    Showing <strong><?= $attendanceOffset + 1 ?></strong> to <strong><?= min($attendanceOffset + $attendancePerPage, $attendanceTotal) ?></strong> of <strong><?= $attendanceTotal ?></strong> records
                </div>
                <?php render_pagination($attendancePage, $attendanceTotalPages, $attendanceBaseUrl, 'attendance_page'); ?>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </section>

    <style>
        /* ATTENDANCE FILTER BAR */
        .attendance-filter-form {
            display: grid;
            grid-template-columns: 2fr 1.2fr 1.2fr 1.3fr auto;
            gap: 12px;
            align-items: end;
            margin: 0 0 18px;
            padding: 16px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 12px;
        }

        .attendance-filter-group {
            display: grid;
            gap: 6px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .attendance-filter-input,
        .attendance-filter-select {
            width: 100%;
            box-sizing: border-box;
            padding: 9px 11px;
            color: var(--ink);
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            height: 38px;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .attendance-filter-input:focus,
        .attendance-filter-select:focus {
            outline: none;
            border-color: var(--lime);
            box-shadow: 0 0 0 2px color-mix(in srgb, var(--lime) 20%, transparent);
        }

        .attendance-filter-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .attendance-filter-actions .btn {
            padding: 9px 16px;
            height: 38px;
            box-sizing: border-box;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            white-space: nowrap;
            font-weight: 700;
        }

        @media (max-width: 860px) {
            .attendance-filter-form {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
                padding: 14px;
            }

            .attendance-filter-member {
                grid-column: span 2;
            }

            .attendance-filter-from {
                grid-column: 1;
            }

            .attendance-filter-to {
                grid-column: 2;
            }

            .attendance-filter-status {
                grid-column: 1;
            }

            .attendance-filter-actions {
                grid-column: 2;
                display: flex;
                gap: 8px;
                align-items: flex-end;
            }

            .attendance-filter-actions .btn {
                flex: 1;
                width: 100%;
            }
        }

        @media (max-width: 440px) {
            .attendance-filter-form {
                gap: 8px;
                padding: 12px;
            }

            .attendance-filter-group {
                font-size: 11px;
                gap: 4px;
            }

            .attendance-filter-input,
            .attendance-filter-select {
                padding: 7px 9px;
                font-size: 12px;
                height: 35px;
            }

            .attendance-filter-date-wrap input[type="text"] {
                padding-right: 32px !important;
            }

            .attendance-filter-date-wrap svg {
                right: 9px !important;
                width: 14px !important;
                height: 14px !important;
            }

            .attendance-filter-actions .btn {
                padding: 7px 10px;
                font-size: 12px;
                height: 35px;
            }
        }

        .attendance-mobile-cards {
            display: none;
        }

        @media (max-width: 768px) {
            .attendance-desktop-table {
                display: none !important;
            }
            .attendance-mobile-cards {
                display: flex !important;
                flex-direction: column;
                gap: 12px;
                margin-top: 10px;
                margin-bottom: 20px;
            }
        }

        .attendance-card-item {
            background: color-mix(in srgb, var(--panel-soft) 45%, transparent);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.2s ease;
        }

        [data-theme="light"] .attendance-card-item {
            background: #ffffff;
            border-color: #e2e8f0;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
        }

        .attendance-card-item:hover {
            border-color: color-mix(in srgb, var(--lime) 35%, transparent);
        }

        .attendance-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid color-mix(in srgb, var(--line) 60%, transparent);
        }

        [data-theme="light"] .attendance-card-header {
            border-bottom-color: #f1f5f9;
        }

        .attendance-card-name {
            font-weight: 700;
            color: var(--ink);
            font-size: 14.5px;
        }

        .attendance-card-role {
            font-size: 12px;
            color: var(--muted);
            margin-top: 2px;
        }

        .attendance-card-session-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }

        .attendance-card-session {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
        }

        .attendance-card-session .session-label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .attendance-card-equip {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 8px;
        }

        .attendance-card-equip .session-label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .attendance-card-session .session-val {
            color: var(--ink);
            font-weight: 600;
        }

        .attendance-card-method {
            display: inline-flex;
            align-items: center;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--muted);
            background: rgba(128, 128, 128, 0.08);
            padding: 3px 8px;
            border-radius: 6px;
        }

        .attendance-card-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            background: rgba(0, 0, 0, 0.15);
            padding: 9px 12px;
            border-radius: 8px;
            border: 1px solid color-mix(in srgb, var(--line) 50%, transparent);
        }

        [data-theme="light"] .attendance-card-details {
            background: #f8fafc;
            border-color: #f1f5f9;
        }

        .attendance-card-detail-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .attendance-card-detail-item .detail-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--muted);
        }

        .attendance-card-detail-item .detail-val {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--ink);
        }

        .attendance-card-actions {
            margin-top: 4px;
            padding-top: 8px;
            border-top: 1px solid color-mix(in srgb, var(--line) 40%, transparent);
        }

        /* Pagination Bar */
        .pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 20px;
            padding-top: 14px;
            border-top: 1px solid var(--line);
        }

        .pagination-bar .pagination {
            margin-top: 0;
        }

        .pagination-info {
            font-size: 13px;
            color: var(--muted);
        }

        .pagination-info strong {
            color: var(--ink);
            font-weight: 600;
        }

        @media (max-width: 600px) {
            .pagination-bar {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
        }
    </style>
    
    <script>
    window.checkinMembersData = <?= json_encode($checkinMembersData ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    window.checkinEquipmentData = <?= json_encode($checkinEquipmentData ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    window.checkinSessionsData = <?= json_encode($checkinSessionsData ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    document.querySelectorAll('[data-attendance-date-picker]').forEach(function(picker) {
        picker.addEventListener('change', function() {
            if (!picker.value) return;
            const dateText = document.querySelector('[data-attendance-date-text="' + picker.dataset.attendanceDatePicker + '"]');
            if (!dateText) return;
            const parts = picker.value.split('-');
            dateText.value = parts[1] + '/' + parts[2] + '/' + parts[0].slice(-2);
        });
    });

    document.addEventListener('submit', function(event) {
        const form = event.target.closest('.attendance-checkout-form');
        if (!form || form.dataset.checkoutConfirmed === 'true') return;
        event.preventDefault();

        const memberName = form.dataset.memberName || 'this member';
        if (typeof Swal === 'undefined') {
            if (window.confirm(`Check out ${memberName}?`)) form.submit();
            return;
        }

        const accentColor = getComputedStyle(document.documentElement).getPropertyValue('--lime').trim() || '#84cc16';
        Swal.fire({
            icon: 'question',
            title: 'Confirm check-out',
            text: `Check out ${memberName}?`,
            showCancelButton: true,
            confirmButtonText: 'Check out',
            cancelButtonText: 'Keep checked in',
            confirmButtonColor: accentColor,
            reverseButtons: true,
            focusCancel: true
        }).then(result => {
            if (result.isConfirmed) {
                form.dataset.checkoutConfirmed = 'true';
                form.submit();
            }
        });
    });

    function recordCheckin() {
        const checkinUsers = Array.isArray(window.checkinMembersData) ? window.checkinMembersData : [];
        const initialEquipItems = Array.isArray(window.checkinEquipmentData) ? window.checkinEquipmentData : [];
        const initialSessionItems = Array.isArray(window.checkinSessionsData) ? window.checkinSessionsData : [];
        let userDrop = null;
        let sessionDrop = null;
        let equipDrop = null;
        let equipSearchTimer = null;

        Swal.fire({
            title: 'Record Check-in',
            width: '470px',
            html: `
                <form id="recordCheckinForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 14px; margin-top: 15px; min-height: 320px; position: relative;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="checkin">
                    <input type="hidden" name="user_id" id="checkinUserId" value="">
                    
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">User *</label>
                        <div id="checkinUserWrap"></div>
                    </div>
                    
                    <div>
                        <label id="sessionLabel" style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Session</label>
                        <div id="checkinSessionWrap"></div>
                    </div>

                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Assign Equipment <span style="font-size: 11.5px; opacity: 0.8; font-weight: normal;">(Optional)</span></label>
                        <div id="checkinEquipWrap"></div>
                        <div style="font-size: 11.5px; color: var(--muted); margin-top: 5px;">Assign a machine for members whose phone was left behind.</div>
                    </div>
                    
                    <input type="hidden" name="check_in_method" value="manual">
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Check in',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            didOpen: () => {
                const userIdInput = document.getElementById('checkinUserId');

                // 1. User Selection Dropdown
                userDrop = new FitDropdown({
                    container: '#checkinUserWrap',
                    placeholder: 'Search user by name or email...',
                    searchable: true,
                    searchPlaceholder: 'Search users...',
                    allowClear: true,
                    zIndex: 90,
                    items: checkinUsers,
                    onChange: (selectedUser) => {
                        if (userIdInput) {
                            userIdInput.value = selectedUser ? selectedUser.id : '';
                        }
                    }
                });

                // 2. Session Selection Dropdown
                sessionDrop = new FitDropdown({
                    container: '#checkinSessionWrap',
                    name: 'schedule_id',
                    id: 'checkinScheduleId',
                    placeholder: '— General Check-in —',
                    searchable: false,
                    zIndex: 80,
                    items: initialSessionItems,
                    value: ''
                });

                // 3. Equipment Selection Dropdown with Hybrid Live Search
                equipDrop = new FitDropdown({
                    container: '#checkinEquipWrap',
                    name: 'equipment_id',
                    id: 'checkinEquipmentId',
                    placeholder: '— None / General Floor Access —',
                    searchable: true,
                    searchPlaceholder: 'Search machine, unit #, or zone...',
                    allowClear: true,
                    zIndex: 70,
                    items: initialEquipItems,
                    value: '',
                    onSearch: function(q, updateBadge, setResults) {
                        clearTimeout(equipSearchTimer);
                        const query = q.trim();

                        if (!query) {
                            updateBadge('');
                            setResults(initialEquipItems);
                            return;
                        }

                        // Hybrid Live Search:
                        // 1. Client-side instant filter is executed immediately by FitDropdown
                        // 2. Debounced asynchronous search queries live database across all zones
                        updateBadge('Searching...');
                        equipSearchTimer = setTimeout(() => {
                            fetch('index.php?page=attendance&action=search_equipment&q=' + encodeURIComponent(query))
                                .then(res => res.json())
                                .then(data => {
                                    if (data && data.success && Array.isArray(data.results)) {
                                        updateBadge(data.count + (data.count === 1 ? ' machine' : ' machines'));
                                        setResults(data.results);
                                    } else {
                                        updateBadge('');
                                    }
                                })
                                .catch(err => {
                                    console.warn('[FitDropdown] Equipment search error:', err);
                                    updateBadge('');
                                });
                        }, 200);
                    }
                });
            },
            preConfirm: () => {
                const userIdInput = document.getElementById('checkinUserId');
                if (!userIdInput || !userIdInput.value) {
                    Swal.showValidationMessage('Please select a user to check in');
                    return false;
                }
                document.getElementById('recordCheckinForm').submit();
            }
        });
    }
    </script>
    <?php
    render_footer();
}
