<?php
declare(strict_types=1);

function attendance_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner']);
    auto_checkout_past_attendance();
    
    $currentGymId = null;
    if ($user['role'] === 'gym_owner') {
        $gid = scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
        $currentGymId = $gid ? (int)$gid : null;
    } else {
        if (!empty($_GET['gym_id'])) {
            $currentGymId = (int) $_GET['gym_id'];
        } else {
            $gid = scalar('SELECT gym_id FROM gyms ORDER BY gym_id ASC LIMIT 1');
            $currentGymId = $gid ? (int)$gid : null;
        }
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

        $rows = db()->query('
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
            WHERE (a.gym_id = ' . (int)$currentGymId . ' OR a.recorded_by = ' . (int)$user['user_id'] . ')
            ORDER BY a.check_in_time DESC 
            LIMIT 100
        ')->fetchAll();
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
        $rows = db()->query('
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
            ORDER BY a.check_in_time DESC 
            LIMIT 100
        ')->fetchAll();
    }

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
    }, $members ?: []);

    render_header('Attendance', $user);
    ?>
    <div class="skeleton-wrapper">
        <section class="panel">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
                <div>
                    <div class="sk sk-title" style="width:140px;margin-bottom:8px"></div>
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
                <h1>Attendance</h1>
                <p>Record user check-ins and check-outs for gym visits and classes.</p>
            </div>
            <button onclick="recordCheckin()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold;">+ New Check-in</button>
        </div>



        <p class="section-label">Recent attendance (last 100)</p>
        <?php if (!$rows): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <p>No attendance records yet.<br>Use the form above to record a check-in.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Session</th>
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
                            <?php if (!$checkedOut && !empty($row['active_equipment'])): ?>
                                <div style="margin-top: 5px;">
                                    <span class="badge" style="background: rgba(34, 197, 94, 0.12); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.3); font-size: 11px; padding: 2px 7px; display: inline-flex; align-items: center; gap: 4px; border-radius: 4px; font-weight: 500;">
                                        ⚡ <?= h($row['active_equipment']) ?>
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
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="checkout">
                                    <input type="hidden" name="attendance_id" value="<?= (int) $row['attendance_id'] ?>">
                                    <button class="btn-sm btn-ghost">Check out</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
    
    <script>
    window.checkinMembersData = <?= json_encode($checkinMembersData ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    function recordCheckin() {
        const checkinUsers = Array.isArray(window.checkinMembersData) ? window.checkinMembersData : [];
        let userDrop = null;

        Swal.fire({
            title: 'Record Check-in',
            width: '460px',
            html: `
                <form id="recordCheckinForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 14px; margin-top: 15px; min-height: 300px; position: relative;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="checkin">
                    <input type="hidden" name="user_id" id="checkinUserId" value="">
                    
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">User *</label>
                        <div id="checkinUserWrap"></div>
                    </div>
                    
                    <div>
                        <label id="sessionLabel" style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Session</label>
                        <select name="schedule_id" class="form-control" style="width: 100%; height: 42px; box-sizing: border-box; background: var(--panel); color: var(--ink); border: 1px solid var(--line); border-radius: 8px; padding: 0 12px; font-size: 13px; font-family: inherit; outline: none; cursor: pointer;">
                            <option value="" style="font-size: 13px;">— General Check-in —</option>
                            <?php foreach ($schedules as $schedule): ?>
                                <option value="<?= (int) $schedule['schedule_id'] ?>" style="font-size: 13px;"><?= h($schedule['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Assign Equipment <span style="font-size: 11.5px; opacity: 0.8; font-weight: normal;">(Optional)</span></label>
                        <select name="equipment_id" class="form-control" style="width: 100%; height: 42px; box-sizing: border-box; background: var(--panel); color: var(--ink); border: 1px solid var(--line); border-radius: 8px; padding: 0 12px; font-size: 13px; font-family: inherit; outline: none; cursor: pointer;">
                            <option value="">— None / General Floor Access —</option>
                            <?php if (!empty($availableEquipment)): ?>
                                <?php
                                $groupedEquip = [];
                                foreach ($availableEquipment as $eq) {
                                    $catName = !empty($eq['category']) ? $eq['category'] : 'Equipment';
                                    $groupedEquip[$catName][] = $eq;
                                }
                                foreach ($groupedEquip as $catName => $items):
                                ?>
                                    <optgroup label="<?= h($catName) ?>">
                                        <?php foreach ($items as $eqItem): 
                                            $uNum = trim((string)($eqItem['unit_number'] ?? ''));
                                            $uStr = ($uNum !== '' && $uNum !== '#1' && $uNum !== '1') ? ' ' . $uNum : '';
                                            $locStr = !empty($eqItem['location_area']) ? ' • ' . $eqItem['location_area'] : '';
                                        ?>
                                            <option value="<?= (int) $eqItem['equipment_id'] ?>"><?= h($eqItem['name'] . $uStr . $locStr) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>No equipment currently available</option>
                            <?php endif; ?>
                        </select>
                        <div style="font-size: 11.5px; color: var(--muted); margin-top: 4px;">Assign a machine for members whose phone was left behind.</div>
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
                userDrop = new FitDropdown({
                    container: '#checkinUserWrap',
                    placeholder: 'Search user by name or email...',
                    searchable: true,
                    searchPlaceholder: 'Search users...',
                    allowClear: true,
                    zIndex: 80,
                    items: checkinUsers,
                    onChange: (selectedUser) => {
                        if (userIdInput) {
                            userIdInput.value = selectedUser ? selectedUser.id : '';
                        }
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
