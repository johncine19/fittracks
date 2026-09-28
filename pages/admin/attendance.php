<?php
declare(strict_types=1);

function attendance_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner']);
    auto_checkout_past_attendance();
    
    $currentGymId = null;
    if ($user['role'] === 'gym_owner') {
        $currentGymId = (int) scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (post('action') === 'checkout') {
            $checkoutSql = ($user['role'] === 'gym_owner' && $currentGymId)
                ? 'UPDATE attendance SET check_out_time = NOW() WHERE attendance_id = ? AND (gym_id = ? OR recorded_by = ?)'
                : 'UPDATE attendance SET check_out_time = NOW() WHERE attendance_id = ?';
            $checkoutParams = ($user['role'] === 'gym_owner' && $currentGymId)
                ? [post('attendance_id'), $currentGymId, $user['user_id']]
                : [post('attendance_id')];
            db()->prepare($checkoutSql)->execute($checkoutParams);
            audit_log($user['user_id'], 'checkout', 'attendance', (string) post('attendance_id'));
            flash('Check-out recorded.');
        } else {
            $userId = (int) post('user_id');
            $scheduleId = post('schedule_id') ? (int) post('schedule_id') : null;
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
            
            if ($gymId) {
                // Ensure member is affiliated with the gym
                db()->prepare('INSERT IGNORE INTO gym_members (user_id, gym_id) VALUES (?, ?)')
                    ->execute([$userId, $gymId]);
            }

            if ($scheduleId) {
                // Automatically mark their class booking as attended so they get Engagement Points
                db()->prepare('UPDATE class_bookings SET booking_status = "attended" WHERE user_id = ? AND schedule_id = ?')->execute([$userId, $scheduleId]);
            }
            
            audit_log($user['user_id'], 'checkin', 'attendance', (string) db()->lastInsertId(), json_encode(['user_id' => $userId, 'gym_id' => $gymId, 'method' => $method]));
            flash('Check-in recorded.');
        }
        redirect('attendance');
    }

    if ($user['role'] === 'gym_owner' && $currentGymId) {
        $members = db()->query('
            SELECT DISTINCT u.user_id, 
                   u.first_name,
                   u.last_name,
                   u.email,
                   u.profile_picture,
                   u.role,
                   IF(gm.gym_id IS NOT NULL, 1, 0) AS is_affiliated,
                   CONCAT(u.first_name, " ", u.last_name, " (", u.role, ")", IF(gm.gym_id IS NOT NULL, " ★", "")) AS name,
                   IF(gm.gym_id = ' . (int)$currentGymId . ' OR tp.gym_id = ' . (int)$currentGymId . ', 0, 1) as sort_prio
            FROM users u 
            LEFT JOIN gym_members gm ON gm.user_id = u.user_id AND gm.gym_id = ' . (int)$currentGymId . '
            LEFT JOIN trainer_profiles tp ON tp.user_id = u.user_id AND tp.gym_id = ' . (int)$currentGymId . '
            WHERE u.role IN ("member", "trainer") AND u.status = "active"
            ORDER BY sort_prio ASC, u.first_name ASC
        ')->fetchAll();

        $schedules = db()->query('
            SELECT s.schedule_id, CONCAT(c.class_name, " - ", DATE_FORMAT(s.start_datetime, "%b %d %h:%i %p")) AS label 
            FROM class_schedules s 
            JOIN classes c ON c.class_id = s.class_id 
            WHERE c.gym_id = ' . (int)$currentGymId . ' AND s.start_datetime >= DATE_SUB(NOW(), INTERVAL 1 DAY) 
            ORDER BY s.start_datetime
        ')->fetchAll();

        $rows = db()->query('
            SELECT a.*, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name, u.role, c.class_name 
            FROM attendance a 
            JOIN users u ON u.user_id = a.user_id 
            LEFT JOIN class_schedules s ON s.schedule_id = a.schedule_id 
            LEFT JOIN classes c ON c.class_id = s.class_id 
            WHERE (a.gym_id = ' . (int)$currentGymId . ' OR a.recorded_by = ' . (int)$user['user_id'] . ')
            ORDER BY a.check_in_time DESC 
            LIMIT 100
        ')->fetchAll();
    } else {
        $members = db()->query('SELECT user_id, first_name, last_name, email, profile_picture, role, 0 AS is_affiliated, CONCAT(first_name, " ", last_name, " (", role, ")") AS name FROM users WHERE role IN ("member", "trainer") AND status = "active" ORDER BY role, first_name')->fetchAll();
        $schedules = db()->query('SELECT s.schedule_id, CONCAT(c.class_name, " - ", DATE_FORMAT(s.start_datetime, "%b %d %h:%i %p")) AS label FROM class_schedules s JOIN classes c ON c.class_id = s.class_id WHERE s.start_datetime >= DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY s.start_datetime')->fetchAll();
        $rows = db()->query('SELECT a.*, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name, u.role, c.class_name FROM attendance a JOIN users u ON u.user_id = a.user_id LEFT JOIN class_schedules s ON s.schedule_id = a.schedule_id LEFT JOIN classes c ON c.class_id = s.class_id ORDER BY a.check_in_time DESC LIMIT 100')->fetchAll();
    }

    $checkinMembersData = array_map(function ($m) {
        $first = trim((string)($m['first_name'] ?? ''));
        $last  = trim((string)($m['last_name'] ?? ''));
        $fullName = trim($first . ' ' . $last) ?: ($m['name'] ?? 'User');
        $ini = (!empty($first) ? strtoupper(substr($first, 0, 1)) : '') . (!empty($last) ? strtoupper(substr($last, 0, 1)) : '');
        $roleName = ucfirst((string)($m['role'] ?? 'Member'));
        $star = !empty($m['is_affiliated']) ? ' ★' : '';
        $label = $fullName . ' (' . $roleName . ')' . $star;
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
                <form id="recordCheckinForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 14px; margin-top: 15px; min-height: 220px; position: relative;">
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
