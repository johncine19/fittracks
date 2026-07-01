<?php
declare(strict_types=1);

function memberships_page(): void
{
    $user = require_roles(['staff', 'member']);
    if ($user['role'] === 'staff' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $start = new DateTime((string) post('start_date'));
        $duration = (int) scalar('SELECT duration_days FROM membership_plans WHERE plan_id = ?', [post('plan_id')]);
        $end = (clone $start)->modify('+' . $duration . ' days')->format('Y-m-d');
        $memberUserId = (int) post('user_id');
        $planId = (int) post('plan_id');
        db()->prepare('INSERT INTO memberships (user_id, plan_id, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)')->execute([$memberUserId, $planId, post('start_date'), $end, post('status')]);
        $planName = (string) scalar('SELECT plan_name FROM membership_plans WHERE plan_id = ?', [$planId]);
        notify_user(
            $memberUserId,
            'system',
            'Membership updated',
            'Your ' . $planName . ' membership is active from ' . date('M j, Y', strtotime((string) post('start_date'))) . ' to ' . date('M j, Y', strtotime($end)) . '.'
        );
        flash('Membership created.');
        redirect('memberships');
    }
    $members = db()->query('SELECT user_id, CONCAT(first_name, " ", last_name) AS name FROM users WHERE role = "member" AND status = "active" ORDER BY first_name')->fetchAll();
    $plans   = db()->query('SELECT * FROM membership_plans WHERE is_active = 1 ORDER BY price')->fetchAll();
    $where   = $user['role'] === 'member' ? 'WHERE m.user_id = ' . (int) $user['user_id'] : '';
    $rows    = db()->query('SELECT m.*, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name, p.plan_name, p.price FROM memberships m JOIN users u ON u.user_id = m.user_id JOIN membership_plans p ON p.plan_id = m.plan_id ' . $where . ' ORDER BY m.created_at DESC')->fetchAll();
    render_header('Memberships', $user);
    ?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Memberships</h1>
                <p>Manage member subscription plans and their validity periods.</p>
            </div>
        </div>

        <?php if ($user['role'] === 'staff'): ?>
        <div class="form-card">
            <h3>Create Membership</h3>
            <form method="post" class="form inline-form">
                <?= csrf_field() ?>
                <label>Member
                    <select name="user_id">
                        <?php foreach ($members as $member): ?>
                            <option value="<?= (int) $member['user_id'] ?>"><?= h($member['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Plan
                    <select name="plan_id">
                        <?php foreach ($plans as $plan): ?>
                            <option value="<?= (int) $plan['plan_id'] ?>"><?= h($plan['plan_name']) ?> — <?= h(money($plan['price'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Start date
                    <input name="start_date" type="date" required value="<?= h(date('Y-m-d')) ?>">
                </label>
                <label>Status
                    <select name="status">
                        <option>active</option>
                        <option>pending</option>
                        <option>expired</option>
                        <option>cancelled</option>
                    </select>
                </label>
                <label>&nbsp;<button>Create</button></label>
            </form>
        </div>
        <?php endif; ?>
        
        <?php if ($user['role'] !== 'member'): 
            $expiring = db()->query('SELECT m.end_date, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name FROM memberships m JOIN users u ON u.user_id = m.user_id WHERE m.status = "active" AND m.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY m.end_date ASC LIMIT 5')->fetchAll();
            if ($expiring):
        ?>
        <div class="flash warning">
            <strong>Upcoming Expirations (Next 7 Days):</strong>
            <ul style="margin:5px 0 0 20px;">
                <?php foreach ($expiring as $row): ?>
                    <li><?= h($row['member']) ?> - Expires on <?= h(date('M j, Y', strtotime($row['end_date']))) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; endif; ?>

        <p class="section-label">Membership records</p>
        <?php if (!$rows): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                <p>No memberships found.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <?php if ($user['role'] !== 'member'): ?><th>Member</th><?php endif; ?>
                        <th>Plan</th>
                        <th>Price</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row):
                    $initials = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
                    $statusClass = 'badge badge-' . $row['status'];
                ?>
                    <tr>
                        <?php if ($user['role'] !== 'member'): ?>
                        <td>
                            <div class="user-cell">
                                <span class="avatar small"><?= h($initials) ?></span>
                                <span><?= h($row['member']) ?></span>
                            </div>
                        </td>
                        <?php endif; ?>
                        <td><strong><?= h($row['plan_name']) ?></strong></td>
                        <td><?= h(money($row['price'])) ?></td>
                        <td><?= h(date('M j, Y', strtotime($row['start_date']))) ?></td>
                        <td><?= h(date('M j, Y', strtotime($row['end_date']))) ?></td>
                        <td><span class="<?= $statusClass ?>"><?= h($row['status']) ?></span></td>
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
