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
    $rows = db()->query('SELECT ca.*, CONCAT(cu.first_name, " ", cu.last_name) AS trainer, CONCAT(mu.first_name, " ", mu.last_name) AS member, SUBSTRING_INDEX(CONCAT(cu.first_name, " ", cu.last_name), " ", 1) AS coach_fn, SUBSTRING_INDEX(CONCAT(cu.first_name, " ", cu.last_name), " ", -1) AS coach_ln, SUBSTRING_INDEX(CONCAT(mu.first_name, " ", mu.last_name), " ", 1) AS member_fn, SUBSTRING_INDEX(CONCAT(mu.first_name, " ", mu.last_name), " ", -1) AS member_ln FROM trainer_assignments ca JOIN trainer_profiles cp ON cp.trainer_id = ca.trainer_id JOIN users cu ON cu.user_id = cp.user_id JOIN users mu ON mu.user_id = ca.member_user_id ORDER BY ca.assigned_date DESC')->fetchAll();

    render_header('Trainer Assignments', $user);
    ?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Trainer Assignments</h1>
                <p>Link coaches to members and manage active pairings.</p>
            </div>
        </div>

        <div class="form-card">
            <h3>New Assignment</h3>
            <form method="post" class="form inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <label>Trainer
                    <select name="trainer_id">
                        <?php foreach ($coaches as $trainer): ?>
                            <option value="<?= (int) $trainer['trainer_id'] ?>"><?= h($trainer['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Member
                    <select name="member_user_id">
                        <?php foreach ($members as $member): ?>
                            <option value="<?= (int) $member['user_id'] ?>"><?= h($member['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Assigned date
                    <input name="assigned_date" type="date" value="<?= h(date('Y-m-d')) ?>" required>
                </label>
                <label>&nbsp;<button>Assign</button></label>
            </form>
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
                    $coachInit = strtoupper(substr($row['coach_fn'], 0, 1) . substr($row['coach_ln'], 0, 1));
                    $memberInit = strtoupper(substr($row['member_fn'], 0, 1) . substr($row['member_ln'], 0, 1));
                    $statusClass = 'badge badge-' . str_replace(' ', '_', $row['status']);
                ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <span class="avatar small"><?= h($coachInit) ?></span>
                                <span><?= h($row['trainer']) ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="user-cell">
                                <span class="avatar small"><?= h($memberInit) ?></span>
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
    <?php
    render_footer();
}
