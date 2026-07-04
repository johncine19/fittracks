<?php
declare(strict_types=1);

function trainer_assignments_page(): void
{
    $user = require_roles(['admin']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (post('action') === 'end') {
            db()->prepare('UPDATE trainer_assignments SET status = "ended", ended_date = CURDATE() WHERE assignment_id = ?')->execute([post('assignment_id')]);
            flash('Trainer assignment ended.');
        } else {
            $trainerId = (int) post('trainer_id');
            $memberUserId = (int) post('member_user_id');
            db()->prepare('INSERT INTO trainer_assignments (trainer_id, member_user_id, assigned_date, status, assigned_by) VALUES (?, ?, ?, "active", ?)')->execute([$trainerId, $memberUserId, post('assigned_date'), $user['user_id']]);

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

            flash('Trainer assigned to member.');
        }
        redirect('trainer_assignments');
    }

    $coaches = db()->query('SELECT cp.trainer_id, CONCAT(u.first_name, " ", u.last_name, " - ", COALESCE(cp.specialization, "trainer")) AS name FROM trainer_profiles cp JOIN users u ON u.user_id = cp.user_id WHERE u.status = "active" ORDER BY u.first_name')->fetchAll();
    $members = db()->query('SELECT user_id, CONCAT(first_name, " ", last_name) AS name FROM users WHERE role = "member" AND status = "active" ORDER BY first_name')->fetchAll();
    $rows = db()->query('SELECT ca.*, CONCAT(cu.first_name, " ", cu.last_name) AS trainer, CONCAT(mu.first_name, " ", mu.last_name) AS member, cu.first_name AS coach_fn, cu.last_name AS coach_ln, cu.profile_picture AS coach_picture, mu.first_name AS member_fn, mu.last_name AS member_ln, mu.profile_picture AS member_picture FROM trainer_assignments ca JOIN trainer_profiles cp ON cp.trainer_id = ca.trainer_id JOIN users cu ON cu.user_id = cp.user_id JOIN users mu ON mu.user_id = ca.member_user_id ORDER BY ca.assigned_date DESC')->fetchAll();

    render_header('Trainer Assignments', $user);
    ?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Trainer Assignments</h1>
                <p>Link coaches to members and manage active pairings.</p>
            </div>
            <button onclick="addAssignment()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold;">+ New Assignment</button>
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
                        <td><?= $row['ended_date'] ? h(date('M j, Y', strtotime($row['ended_date']))) : '<span class="muted">—</span>' ?></td>
                        <td><span class="<?= $statusClass ?>"><?= h($row['status']) ?></span></td>
                        <td>
                            <?php if ($row['status'] === 'active'): ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="end">
                                    <input type="hidden" name="assignment_id" value="<?= (int) $row['assignment_id'] ?>">
                                    <button class="btn-sm btn-danger">End</button>
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
