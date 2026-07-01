<?php
declare(strict_types=1);

function attendance_page(): void
{
    $user = require_roles(['admin']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (post('action') === 'checkout') {
            db()->prepare('UPDATE attendance SET check_out_time = NOW() WHERE attendance_id = ?')->execute([post('attendance_id')]);
            flash('Check-out recorded.');
        } else {
            db()->prepare('INSERT INTO attendance (user_id, schedule_id, check_in_time, check_in_method, recorded_by) VALUES (?, ?, NOW(), ?, ?)')->execute([post('user_id'), post('schedule_id') ?: null, post('check_in_method'), $user['user_id']]);
            flash('Check-in recorded.');
        }
        redirect('attendance');
    }
    $members  = db()->query('SELECT user_id, CONCAT(first_name, " ", last_name) AS name FROM users WHERE role = "member" AND status = "active" ORDER BY first_name')->fetchAll();
    $schedules = db()->query('SELECT s.schedule_id, CONCAT(c.class_name, " - ", DATE_FORMAT(s.start_datetime, "%b %d %h:%i %p")) AS label FROM class_schedules s JOIN classes c ON c.class_id = s.class_id WHERE s.start_datetime >= DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY s.start_datetime')->fetchAll();
    $rows     = db()->query('SELECT a.*, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name, c.class_name FROM attendance a JOIN users u ON u.user_id = a.user_id LEFT JOIN class_schedules s ON s.schedule_id = a.schedule_id LEFT JOIN classes c ON c.class_id = s.class_id ORDER BY a.check_in_time DESC LIMIT 100')->fetchAll();

    render_header('Attendance', $user);
    ?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Attendance</h1>
                <p>Record member check-ins and check-outs for gym visits and classes.</p>
            </div>
        </div>

        <div class="form-card">
            <h3>Record Check-in</h3>
            <form method="post" class="form inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="checkin">
                <label>Member
                    <select name="user_id">
                        <?php foreach ($members as $member): ?>
                            <option value="<?= (int) $member['user_id'] ?>"><?= h($member['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Session
                    <select name="schedule_id">
                        <option value="">— Gym visit (no class) —</option>
                        <?php foreach ($schedules as $schedule): ?>
                            <option value="<?= (int) $schedule['schedule_id'] ?>"><?= h($schedule['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Method
                    <select name="check_in_method">
                        <option>manual</option>
                        <option>qr_code</option>
                        <option>rfid</option>
                    </select>
                </label>
                <label>&nbsp;<button>Check in</button></label>
            </form>
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
                        <th>Member</th>
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
                        <td><?= h($row['class_name'] ?? 'Gym visit') ?></td>
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
    <?php
    render_footer();
}
