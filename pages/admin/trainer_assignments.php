<?php
declare(strict_types=1);

function trainer_assignments_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner']);
    if ($user['role'] === 'gym_owner') {
        require_gym_feature('trainers');
    }
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
        $allGymMembers = $members;
        $rows = db()->query('SELECT ca.*, (SELECT end_date FROM memberships WHERE user_id = ca.member_user_id AND end_date >= CURDATE() ORDER BY end_date DESC LIMIT 1) as membership_end_date, CONCAT(cu.first_name, " ", cu.last_name) AS trainer, CONCAT(mu.first_name, " ", mu.last_name) AS member, cu.first_name AS coach_fn, cu.last_name AS coach_ln, cu.profile_picture AS coach_picture, mu.first_name AS member_fn, mu.last_name AS member_ln, mu.profile_picture AS member_picture FROM trainer_assignments ca JOIN trainer_profiles cp ON cp.trainer_id = ca.trainer_id JOIN users cu ON cu.user_id = cp.user_id JOIN users mu ON mu.user_id = ca.member_user_id ORDER BY CASE ca.status WHEN "active" THEN 1 WHEN "pending_admin" THEN 2 WHEN "pending_trainer" THEN 3 WHEN "ended" THEN 4 WHEN "rejected" THEN 5 ELSE 6 END, ca.assigned_date DESC')->fetchAll();
    } else {
        $coaches = db()->query('SELECT cp.trainer_id, CONCAT(u.first_name, " ", u.last_name, " - ", COALESCE(cp.specialization, "trainer")) AS name FROM trainer_profiles cp JOIN users u ON u.user_id = cp.user_id WHERE u.status = "active" AND cp.gym_id = ' . $gymId . ' ORDER BY u.first_name')->fetchAll();
        // Members who have active plan at this gym
        $members = db()->query('SELECT DISTINCT u.user_id, CONCAT(u.first_name, " ", u.last_name, " (Has Plan)") AS name FROM users u JOIN memberships m ON m.user_id = u.user_id JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE u.role = "member" AND u.status = "active" AND m.status="active" AND m.end_date >= CURDATE() AND mp.gym_id = ' . $gymId . ' ORDER BY name')->fetchAll();
        // All members in this gym for direct diet plan access
        $allGymMembers = db()->query('
            SELECT DISTINCT u.user_id, CONCAT(u.first_name, " ", u.last_name) AS name 
            FROM users u 
            WHERE u.role = "member" AND u.status = "active" AND (
                EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ' . (int)$gymId . ') OR
                EXISTS (SELECT 1 FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = u.user_id AND mp.gym_id = ' . (int)$gymId . ')
            )
            ORDER BY name ASC
        ')->fetchAll();
        if (empty($allGymMembers)) {
            $allGymMembers = db()->query('SELECT user_id, CONCAT(first_name, " ", last_name) AS name FROM users WHERE role = "member" AND status = "active" ORDER BY name ASC')->fetchAll();
        }
        $rows = db()->query('SELECT ca.*, (SELECT end_date FROM memberships WHERE user_id = ca.member_user_id AND end_date >= CURDATE() ORDER BY end_date DESC LIMIT 1) as membership_end_date, CONCAT(cu.first_name, " ", cu.last_name) AS trainer, CONCAT(mu.first_name, " ", mu.last_name) AS member, cu.first_name AS coach_fn, cu.last_name AS coach_ln, cu.profile_picture AS coach_picture, mu.first_name AS member_fn, mu.last_name AS member_ln, mu.profile_picture AS member_picture FROM trainer_assignments ca JOIN trainer_profiles cp ON cp.trainer_id = ca.trainer_id JOIN users cu ON cu.user_id = cp.user_id JOIN users mu ON mu.user_id = ca.member_user_id WHERE cp.gym_id = ' . $gymId . ' ORDER BY CASE ca.status WHEN "active" THEN 1 WHEN "pending_admin" THEN 2 WHEN "pending_trainer" THEN 3 WHEN "ended" THEN 4 WHEN "rejected" THEN 5 ELSE 6 END, ca.assigned_date DESC')->fetchAll();
    }

    render_header('Trainer Assignments', $user);
    ?>
    <section class="panel">
        <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 16px; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 220px;">
                <h1 style="margin: 0 0 4px; font-size: 22px;">Trainer Assignments</h1>
                <p style="margin: 0; color: var(--muted); font-size: 13px;">Link coaches to members and manage active pairings.</p>
            </div>
            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: nowrap; flex-shrink: 0;">
                <button onclick="openDietPlanSelector()" class="btn btn-secondary" style="white-space: nowrap; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; padding: 0 12px; height: 36px; font-size: 12.5px; border-radius: 8px;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                    <span>Diet Plan</span>
                </button>
                <button onclick="addTrainer()" class="btn btn-secondary" style="white-space: nowrap; font-weight: 600; display: inline-flex; align-items: center; padding: 0 12px; height: 36px; font-size: 12.5px; border-radius: 8px;">+ Add Trainer</button>
                <button onclick="addAssignment()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: 700; white-space: nowrap; display: inline-flex; align-items: center; padding: 0 14px; height: 36px; font-size: 12.5px; border-radius: 8px; border: none; cursor: pointer;">+ New Assignment</button>
            </div>
        </div>

        <p class="section-label">All assignments</p>
        <?php if (!$rows): ?>
            <div class="empty-state" style="padding: 40px 20px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                <p style="margin: 10px 0 16px;">No active trainer-member assignments yet.<br>You can pair a trainer with a member, or manage member meal plans directly below.</p>
                <div style="display: flex; gap: 12px; justify-content: center; align-items: center; flex-wrap: wrap; margin-top: 8px;">
                    <button onclick="openDietPlanSelector()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: 700; padding: 10px 20px; border-radius: 8px; border: none; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 2px 10px rgba(132, 204, 22, 0.25); cursor: pointer; transition: all 0.2s ease;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                        <span>Open Member Diet Plan</span>
                    </button>
                    <a href="index.php?page=users&tab=member" class="btn btn-secondary" style="text-decoration: none; padding: 10px 18px; border-radius: 8px; display: inline-flex; align-items: center; gap: 8px; font-weight: 600; color: var(--ink); border: 1px solid var(--line); transition: all 0.2s ease;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <span>View Members List</span>
                    </a>
                </div>
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
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <?php if ($row['status'] === 'active'): ?>
                                        <a href="index.php?page=diet_builder&member_user_id=<?= (int)$row['member_user_id'] ?>&ref=trainer_assignments" class="btn-sm btn-ghost" style="text-decoration: none; display: inline-flex; align-items: center; gap: 4px; border: 1px solid var(--line); font-weight: 600;" title="Edit Member Meal Plan">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                            Diet Plan
                                        </a>
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
            width: '460px',
            html: `
                <form id="addAssignmentForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Trainer *
                        <select name="trainer_id" class="form-control" style="width: 100%; box-sizing: border-box; background-color: #1a2230; color: #ffffff; border: 1px solid #334155; padding: 10px 12px; border-radius: 8px;" required>
                            <option value="" style="background-color: #1a2230; color: #ffffff;">Select Trainer...</option>
                            <?php foreach ($coaches as $trainer): ?>
                                <option value="<?= (int) $trainer['trainer_id'] ?>" style="background-color: #1a2230; color: #ffffff;"><?= h($trainer['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Member *
                        <select name="member_user_id" class="form-control" style="width: 100%; box-sizing: border-box; background-color: #1a2230; color: #ffffff; border: 1px solid #334155; padding: 10px 12px; border-radius: 8px;" required>
                            <option value="" style="background-color: #1a2230; color: #ffffff;">Select Member...</option>
                            <?php foreach ($members as $member): ?>
                                <option value="<?= (int) $member['user_id'] ?>" style="background-color: #1a2230; color: #ffffff;"><?= h($member['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Assigned date *
                        <input type="date" name="assigned_date" class="form-control" value="<?= h(date('Y-m-d')) ?>" style="width: 100%; box-sizing: border-box; background-color: #1a2230; color: #ffffff; border: 1px solid #334155; padding: 10px 12px; border-radius: 8px;" required>
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

    <?php
    $dietMembersList = array_values(array_map(function($m) {
        $parts = preg_split('/\s+/', trim($m['name']));
        $initials = '';
        if (!empty($parts[0])) $initials .= strtoupper(substr($parts[0], 0, 1));
        if (count($parts) > 1 && !empty($parts[count($parts)-1])) {
            $initials .= strtoupper(substr($parts[count($parts)-1], 0, 1));
        }
        return [
            'id' => (int)$m['user_id'],
            'name' => $m['name'],
            'initials' => $initials ?: 'M'
        ];
    }, $allGymMembers));
    ?>

    const ftDietMembers = <?= json_encode($dietMembersList) ?>;

    function openDietPlanSelector() {
        let selectedId = null;
        let selectedName = '';

        Swal.fire({
            title: 'Member Diet Plan',
            width: '460px',
            html: `
                <div style="text-align: left; margin-top: 15px;">
                    <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 8px; font-weight: 500;">
                        Select a member to view or edit their meal plan:
                    </label>
                    <div style="position: relative; width: 100%;">
                        <div id="ftCustomSelectTrigger" style="width: 100%; box-sizing: border-box; padding: 11px 14px; border-radius: 8px; font-size: 14px; background: #1a2230; color: #ffffff; border: 1px solid #334155; display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none;">
                            <span id="ftSelectedMemberText" style="color: #94a3b8; display: flex; align-items: center; gap: 8px;">Select Member...</span>
                            <svg id="ftSelectChevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" style="transition: transform 0.2s;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </div>
                        <div id="ftCustomDropdownMenu" style="display: none; width: 100%; box-sizing: border-box; margin-top: 6px; background: #161f30; border: 1px solid #334155; border-radius: 8px; max-height: 230px; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.6); z-index: 1000;">
                            ${ftDietMembers.length > 5 ? `
                            <div style="padding: 8px; border-bottom: 1px solid #283548; background: #131b28; position: sticky; top: 0; z-index: 2;">
                                <input type="text" id="ftMemberSearch" placeholder="Search member..." style="width: 100%; box-sizing: border-box; padding: 8px 12px; background: #1a2230; border: 1px solid #334155; border-radius: 6px; color: #ffffff; font-size: 13px; outline: none;">
                            </div>
                            ` : ''}
                            <div id="ftMemberListContainer" style="padding: 4px;">
                            </div>
                        </div>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Open Diet Plan',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            didOpen: () => {
                const trigger = document.getElementById('ftCustomSelectTrigger');
                const menu = document.getElementById('ftCustomDropdownMenu');
                const chevron = document.getElementById('ftSelectChevron');
                const listContainer = document.getElementById('ftMemberListContainer');
                const searchInput = document.getElementById('ftMemberSearch');

                function renderMembers(query = '') {
                    const q = query.trim().toLowerCase();
                    const filtered = ftDietMembers.filter(m => m.name.toLowerCase().includes(q));
                    
                    if (filtered.length === 0) {
                        listContainer.innerHTML = '<div style="padding: 14px; text-align: center; color: var(--muted); font-size: 13px;">No members found</div>';
                        return;
                    }

                    listContainer.innerHTML = filtered.map(m => {
                        const isSelected = selectedId === m.id;
                        return `
                            <div class="ft-member-item" data-id="${m.id}" data-name="${encodeURIComponent(m.name)}" data-initials="${m.initials}"
                                 style="padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; border-radius: 6px; cursor: pointer; margin-bottom: 2px; transition: background 0.15s; background: ${isSelected ? 'rgba(132, 204, 22, 0.15)' : 'transparent'};">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 30px; height: 30px; border-radius: 50%; background: #223049; color: var(--lime); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; border: 1px solid rgba(132, 204, 22, 0.3);">
                                        ${m.initials}
                                    </div>
                                    <span style="color: #f1f5f9; font-size: 13.5px; font-weight: 600;">${m.name}</span>
                                </div>
                                ${isSelected ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}
                            </div>
                        `;
                    }).join('');

                    listContainer.querySelectorAll('.ft-member-item').forEach(el => {
                        el.addEventListener('mouseenter', () => {
                            if (parseInt(el.getAttribute('data-id'), 10) !== selectedId) el.style.background = '#253349';
                        });
                        el.addEventListener('mouseleave', () => {
                            if (parseInt(el.getAttribute('data-id'), 10) !== selectedId) el.style.background = 'transparent';
                        });
                        el.addEventListener('click', () => {
                            selectedId = parseInt(el.getAttribute('data-id'), 10);
                            selectedName = decodeURIComponent(el.getAttribute('data-name'));
                            const ini = el.getAttribute('data-initials');

                            const label = document.getElementById('ftSelectedMemberText');
                            if (label) {
                                label.innerHTML = `
                                    <span style="width: 22px; height: 22px; border-radius: 50%; background: #223049; color: var(--lime); display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700;">${ini}</span>
                                    <span style="color: #ffffff; font-weight: 600;">${selectedName}</span>
                                `;
                            }
                            menu.style.display = 'none';
                            chevron.style.transform = 'rotate(0deg)';
                            trigger.style.borderColor = 'var(--lime)';
                        });
                    });
                }

                renderMembers();

                trigger.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isOpen = menu.style.display === 'block';
                    menu.style.display = isOpen ? 'none' : 'block';
                    chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
                    if (!isOpen && searchInput) {
                        setTimeout(() => searchInput.focus(), 50);
                    }
                });

                if (searchInput) {
                    searchInput.addEventListener('input', (e) => {
                        renderMembers(e.target.value);
                    });
                    searchInput.addEventListener('click', (e) => e.stopPropagation());
                }

                document.addEventListener('click', function closeMenu(e) {
                    if (trigger && menu && !trigger.contains(e.target) && !menu.contains(e.target)) {
                        menu.style.display = 'none';
                        chevron.style.transform = 'rotate(0deg)';
                    }
                });
            },
            preConfirm: () => {
                if (!selectedId) {
                    Swal.showValidationMessage('Please select a member');
                    return false;
                }
                window.location.href = 'index.php?page=diet_builder&member_user_id=' + selectedId + '&ref=trainer_assignments';
            }
        });
    }
    </script>
    <?php
    render_footer();
}
