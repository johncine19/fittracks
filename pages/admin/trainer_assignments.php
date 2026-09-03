<?php
declare(strict_types=1);

function trainer_assignments_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner']);
    $gymId = null;
    if ($user['role'] === 'gym_owner') {
        $gymId = (int) scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (post('action') === 'create_trainer') {
            $email = trim((string) post('email'));
            if (scalar('SELECT user_id FROM users WHERE email = ?', [$email])) {
                flash('A user with that email already exists.', 'danger');
            } else {
                $plainPassword = (string) post('password');
                $firstName = mb_convert_case(trim((string) post('first_name')), MB_CASE_TITLE, 'UTF-8');
                $lastName  = mb_convert_case(trim((string) post('last_name')), MB_CASE_TITLE, 'UTF-8');

                db()->prepare('INSERT INTO users (role, first_name, last_name, email, password_hash, status, email_verified_at) VALUES ("trainer", ?, ?, ?, ?, "active", NOW())')
                    ->execute([$firstName, $lastName, $email, password_hash($plainPassword, PASSWORD_DEFAULT)]);
                $newUserId = (int) db()->lastInsertId();
                db()->prepare('INSERT INTO trainer_profiles (user_id, specialization, gym_id) VALUES (?, ?, ?)')
                    ->execute([$newUserId, post('specialization'), $gymId]);
                flash('Trainer created successfully.', 'success');
            }
        } elseif (post('action') === 'end') {
            db()->prepare('UPDATE trainer_assignments SET status = "ended", ended_date = CURDATE() WHERE assignment_id = ?')->execute([post('assignment_id')]);
            audit_log($user['user_id'], 'end', 'trainer_assignment', (string) post('assignment_id'));
            flash('Trainer assignment ended.');
        } elseif (post('action') === 'forward') {
            db()->prepare('UPDATE trainer_assignments SET status = "pending_trainer" WHERE assignment_id = ?')->execute([post('assignment_id')]);
            $stmt = db()->prepare('SELECT member_user_id, trainer_id FROM trainer_assignments WHERE assignment_id = ?');
            $stmt->execute([post('assignment_id')]);
            $assignment = $stmt->fetch();
            if ($assignment) {
                $trainerUserId = scalar('SELECT user_id FROM trainer_profiles WHERE trainer_id = ?', [$assignment['trainer_id']]);
                $memberName = scalar('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE user_id = ?', [$assignment['member_user_id']]);
                if ($trainerUserId && $memberName) {
                    notify_user((int)$trainerUserId, 'system', 'New Appointment Request', $memberName . ' has requested an appointment with you.');
                }
            }
            audit_log($user['user_id'], 'forward', 'trainer_assignment', (string) post('assignment_id'));
            flash('Request forwarded to trainer.');
        } elseif (post('action') === 'reject_admin') {
            db()->prepare('UPDATE trainer_assignments SET status = "rejected", rejection_reason = "Rejected by admin" WHERE assignment_id = ?')->execute([post('assignment_id')]);
            $stmt = db()->prepare('SELECT member_user_id FROM trainer_assignments WHERE assignment_id = ?');
            $stmt->execute([post('assignment_id')]);
            $assignment = $stmt->fetch();
            if ($assignment) {
                notify_user((int)$assignment['member_user_id'], 'system', 'Appointment Request Rejected', 'Your trainer appointment request was rejected by the admin.');
            }
            audit_log($user['user_id'], 'reject', 'trainer_assignment', (string) post('assignment_id'));
            flash('Request rejected.');
        } else {
            $trainerId = (int) post('trainer_id');
            $memberUserId = (int) post('member_user_id');
            $assignedDate = post('assigned_date');
            
            $hasActivePlan = (bool) scalar("SELECT 1 FROM memberships WHERE user_id = ? AND status = 'active' AND end_date >= CURDATE()", [$memberUserId]);
            $endedDate = $hasActivePlan ? null : $assignedDate;

            db()->prepare('INSERT INTO trainer_assignments (trainer_id, member_user_id, assigned_date, ended_date, status, assigned_by) VALUES (?, ?, ?, ?, "active", ?)')->execute([$trainerId, $memberUserId, $assignedDate, $endedDate, $user['user_id']]);
            
            grant_retroactive_commission($memberUserId);

            $names = query_all(
                'SELECT CONCAT(tu.first_name, " ", tu.last_name) AS trainer_name, tu.user_id AS trainer_user_id,
                        CONCAT(mu.first_name, " ", mu.last_name) AS member_name
                 FROM trainer_profiles tp
                 JOIN users tu ON tu.user_id = tp.user_id
                 JOIN users mu ON mu.user_id = ?
                 WHERE tp.trainer_id = ?',
                 [$memberUserId, $trainerId]
            );
            if ($names) {
                $pair = $names[0];
                notify_user($memberUserId, 'system', 'Trainer assigned', 'You have been assigned to ' . $pair['trainer_name'] . '.');
                notify_user((int) $pair['trainer_user_id'], 'system', 'New client assigned', $pair['member_name'] . ' has been assigned to you.');
            }

            audit_log($user['user_id'], 'create', 'trainer_assignment', (string) db()->lastInsertId(), json_encode(['trainer_id' => $trainerId, 'member_user_id' => $memberUserId]));
            flash('Trainer assigned to member.');
        }
        redirect('trainer_assignments');
    }

    // Automatically end trainer assignments if the member's active membership has expired
    db()->query('UPDATE trainer_assignments ca
                 JOIN memberships m ON m.user_id = ca.member_user_id
                 SET ca.status = "ended", ca.ended_date = m.end_date
                 WHERE ca.status = "active" AND m.end_date < CURDATE()');

    if ($user['role'] === 'platform_admin') {
        $coaches = db()->query('SELECT cp.trainer_id, CONCAT(u.first_name, " ", u.last_name, " - ", COALESCE(cp.specialization, "trainer")) AS name FROM trainer_profiles cp JOIN users u ON u.user_id = cp.user_id WHERE u.status = "active" ORDER BY u.first_name')->fetchAll();
        $members = db()->query('SELECT u.user_id, CONCAT(u.first_name, " ", u.last_name, IF(EXISTS(SELECT 1 FROM memberships m WHERE m.user_id = u.user_id AND m.status = "active" AND m.end_date >= CURDATE()), " (Has Plan)", " (No Plan - 1 Day)")) AS name FROM users u WHERE u.role = "member" AND u.status = "active" ORDER BY u.first_name')->fetchAll();
        $rows = db()->query('SELECT ca.*, (SELECT end_date FROM memberships WHERE user_id = ca.member_user_id AND end_date >= CURDATE() ORDER BY end_date DESC LIMIT 1) as membership_end_date, CONCAT(cu.first_name, " ", cu.last_name) AS trainer, CONCAT(mu.first_name, " ", mu.last_name) AS member, cu.first_name AS coach_fn, cu.last_name AS coach_ln, cu.profile_picture AS coach_picture, mu.first_name AS member_fn, mu.last_name AS member_ln, mu.profile_picture AS member_picture FROM trainer_assignments ca JOIN trainer_profiles cp ON cp.trainer_id = ca.trainer_id JOIN users cu ON cu.user_id = cp.user_id JOIN users mu ON mu.user_id = ca.member_user_id ORDER BY CASE ca.status WHEN "active" THEN 1 WHEN "pending_admin" THEN 2 WHEN "pending_trainer" THEN 3 WHEN "ended" THEN 4 WHEN "rejected" THEN 5 ELSE 6 END, ca.assigned_date DESC')->fetchAll();
    } else {
        $coaches = db()->query('SELECT cp.trainer_id, CONCAT(u.first_name, " ", u.last_name, " - ", COALESCE(cp.specialization, "trainer")) AS name FROM trainer_profiles cp JOIN users u ON u.user_id = cp.user_id WHERE u.status = "active" AND cp.gym_id = ' . $gymId . ' ORDER BY u.first_name')->fetchAll();
        // Members who have active plan at this gym
        $members = db()->query('SELECT DISTINCT u.user_id, CONCAT(u.first_name, " ", u.last_name, " (Has Plan)") AS name FROM users u JOIN memberships m ON m.user_id = u.user_id JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE u.role = "member" AND u.status = "active" AND m.status="active" AND m.end_date >= CURDATE() AND mp.gym_id = ' . $gymId . ' ORDER BY name')->fetchAll();
        $rows = db()->query('SELECT ca.*, (SELECT end_date FROM memberships WHERE user_id = ca.member_user_id AND end_date >= CURDATE() ORDER BY end_date DESC LIMIT 1) as membership_end_date, CONCAT(cu.first_name, " ", cu.last_name) AS trainer, CONCAT(mu.first_name, " ", mu.last_name) AS member, cu.first_name AS coach_fn, cu.last_name AS coach_ln, cu.profile_picture AS coach_picture, mu.first_name AS member_fn, mu.last_name AS member_ln, mu.profile_picture AS member_picture FROM trainer_assignments ca JOIN trainer_profiles cp ON cp.trainer_id = ca.trainer_id JOIN users cu ON cu.user_id = cp.user_id JOIN users mu ON mu.user_id = ca.member_user_id WHERE cp.gym_id = ' . $gymId . ' ORDER BY CASE ca.status WHEN "active" THEN 1 WHEN "pending_admin" THEN 2 WHEN "pending_trainer" THEN 3 WHEN "ended" THEN 4 WHEN "rejected" THEN 5 ELSE 6 END, ca.assigned_date DESC')->fetchAll();
    }

    render_header('Trainer Assignments', $user);
    ?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Trainer Assignments</h1>
                <p>Link coaches to members and manage active pairings.</p>
            </div>
            <div style="display:flex; gap: 8px;">
                <button onclick="addTrainer()" class="btn btn-secondary" style="font-weight: bold;">+ Add Trainer</button>
                <button onclick="addAssignment()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold;">+ New Assignment</button>
            </div>
        </div>

        <p class="section-label">All assignments</p>
        <?php if (!$rows): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                <p>No trainer assignments yet.<br>Use the form above to pair a trainer with a member.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Trainer</th>
                        <th>Member</th>
                        <th>Assigned</th>
                        <th>Ended</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row):
                    $statusClass = 'badge badge-' . str_replace(' ', '_', $row['status']);
                    $coachData = ['first_name' => $row['coach_fn'], 'last_name' => $row['coach_ln'], 'profile_picture' => $row['coach_picture']];
                    $memberData = ['first_name' => $row['member_fn'], 'last_name' => $row['member_ln'], 'profile_picture' => $row['member_picture']];
                ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <?= render_avatar($coachData) ?>
                                <span><?= h($row['trainer']) ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="user-cell">
                                <?= render_avatar($memberData) ?>
                                <span><?= h($row['member']) ?></span>
                            </div>
                        </td>
                        <td><?= h(date('M j, Y', strtotime($row['assigned_date']))) ?></td>
                        <td>
                            <?php if ($row['ended_date']): ?>
                                <?= h(date('M j, Y', strtotime($row['ended_date']))) ?>
                            <?php elseif ($row['status'] === 'active' && $row['membership_end_date']): ?>
                                <span style="color:var(--muted); font-size: 11px;">Expires<br><?= h(date('M j, Y', strtotime($row['membership_end_date']))) ?></span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="<?= $statusClass ?>"><?= h($row['status']) ?></span>
                            <?php if ($row['status'] === 'rejected' && $row['rejection_reason']): ?>
                                <div style="font-size: 11px; color: var(--muted); margin-top: 4px;">Reason: <?= h($row['rejection_reason']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display: flex; gap: 8px;">
                                <?php if ($row['status'] === 'active'): ?>
                                    <?php
                                        $confirmTitle = "End Assignment?";
                                        $confirmHtml = "Are you sure you want to end this trainer assignment?";
                                        $btnText = "Yes, end it";
                                        
                                        if ($row['membership_end_date'] && strtotime($row['membership_end_date']) > time()) {
                                            $exp = date('M j, Y', strtotime($row['membership_end_date']));
                                            $confirmHtml = "This assignment is officially scheduled to end on <strong>$exp</strong>.<br><br>Are you sure you want to end it early?";
                                            $btnText = "Yes, end it early";
                                        }
                                    ?>
                                    <form method="post" onsubmit="event.preventDefault(); Swal.fire({title: '<?= $confirmTitle ?>', html: '<?= addslashes($confirmHtml) ?>', icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444', confirmButtonText: '<?= $btnText ?>'}).then((result) => { if (result.isConfirmed) { this.submit(); } });">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="end">
                                        <input type="hidden" name="assignment_id" value="<?= (int) $row['assignment_id'] ?>">
                                        <button class="btn-sm btn-danger">End</button>
                                    </form>
                                <?php elseif ($row['status'] === 'pending_admin'): ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="forward">
                                        <input type="hidden" name="assignment_id" value="<?= (int) $row['assignment_id'] ?>">
                                        <button class="btn-sm" style="background: var(--lime); color: var(--bg);">Forward</button>
                                    </form>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reject_admin">
                                        <input type="hidden" name="assignment_id" value="<?= (int) $row['assignment_id'] ?>">
                                        <button class="btn-sm btn-danger">Reject</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
    
    <script>
    function addTrainer() {
        Swal.fire({
            title: 'Add Trainer',
            html: `
                <form id="addTrainerForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_trainer">
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">First Name *
                        <input name="first_name" class="form-control" style="width: 100%; box-sizing: border-box; text-transform: capitalize;" autocapitalize="words" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())" required>
                    </label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Last Name *
                        <input name="last_name" class="form-control" style="width: 100%; box-sizing: border-box; text-transform: capitalize;" autocapitalize="words" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())" required>
                    </label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Email *
                        <input type="email" name="email" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                    </label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Password *
                        <input type="password" name="password" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                    </label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Specialization *
                        <input name="specialization" class="form-control" placeholder="e.g. Strength & Conditioning" style="width: 100%; box-sizing: border-box;" required>
                    </label>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Create Trainer',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('addTrainerForm');
                if (!form.first_name.value || !form.last_name.value || !form.email.value || !form.password.value || !form.specialization.value) {
                    Swal.showValidationMessage('Please fill all required fields');
                    return false;
                }
                form.submit();
            }
        });
    }

    function addAssignment() {
        Swal.fire({
            title: 'New Assignment',
            html: `
                <form id="addAssignmentForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Trainer *
                        <select name="trainer_id" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                            <option value="">Select Trainer...</option>
                            <?php foreach ($coaches as $trainer): ?>
                                <option value="<?= (int) $trainer['trainer_id'] ?>"><?= h($trainer['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Member *
                        <select name="member_user_id" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                            <option value="">Select Member...</option>
                            <?php foreach ($members as $member): ?>
                                <option value="<?= (int) $member['user_id'] ?>"><?= h($member['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Assigned date *
                        <input type="date" name="assigned_date" class="form-control" value="<?= h(date('Y-m-d')) ?>" style="width: 100%; box-sizing: border-box;" required>
                    </label>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Assign',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('addAssignmentForm');
                if (!form.trainer_id.value || !form.member_user_id.value || !form.assigned_date.value) {
                    Swal.showValidationMessage('Please fill all required fields');
                    return false;
                }
                form.submit();
            }
        });
    }
    </script>
    <?php
    render_footer();
}
