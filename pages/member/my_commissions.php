<?php
declare(strict_types=1);

function my_commissions_page(): void
{
    $user = require_roles(['trainer']);

    $page = max(1, (int)($_GET['p'] ?? 1));
    $limit = 15;
    $offset = ($page - 1) * $limit;

    $countSql = 'SELECT COUNT(*) FROM trainer_commissions WHERE trainer_id = ' . (int)$user['user_id'];
    $total = (int) scalar($countSql);
    $totalPages = (int) ceil($total / $limit);

    $commissions = db()->query(
        'SELECT c.*, 
                p.receipt_number,
                p.amount AS payment_amount,
                p.payment_date,
                mp.plan_name,
                CONCAT(mu.first_name, " ", mu.last_name) AS member_name
         FROM trainer_commissions c
         JOIN payments p ON p.payment_id = c.payment_id
         JOIN memberships m ON m.membership_id = p.membership_id
         JOIN users mu ON mu.user_id = m.user_id
         JOIN membership_plans mp ON mp.plan_id = m.plan_id
         WHERE c.trainer_id = ' . (int)$user['user_id'] . '
         ORDER BY c.created_at DESC
         LIMIT ' . $limit . ' OFFSET ' . $offset
    )->fetchAll();

    $stats = db()->query('
        SELECT 
            SUM(CASE WHEN status = "pending" THEN amount ELSE 0 END) AS pending_total,
            SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END) AS paid_total
        FROM trainer_commissions
        WHERE trainer_id = ' . (int)$user['user_id'] . '
    ')->fetch();

    render_header('My Commissions', $user);
    ?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>My Commissions</h1>
                <p>Track your earnings from member subscriptions.</p>
            </div>
        </div>

        <div style="display:flex; gap:15px; margin-bottom: 20px;">
            <div style="flex:1; background:var(--bg); padding:15px; border-radius:8px; border:1px solid var(--line);">
                <div style="color:var(--muted); font-size:13px; margin-bottom:5px;">Pending Earnings</div>
                <div style="font-size:24px; font-weight:bold; color:var(--ink);"><?= h(money($stats['pending_total'] ?? 0)) ?></div>
            </div>
            <div style="flex:1; background:var(--bg); padding:15px; border-radius:8px; border:1px solid var(--line);">
                <div style="color:var(--muted); font-size:13px; margin-bottom:5px;">Paid Earnings (All Time)</div>
                <div style="font-size:24px; font-weight:bold; color:var(--lime);"><?= h(money($stats['paid_total'] ?? 0)) ?></div>
            </div>
        </div>

        <p class="section-label">Commission History (<?= $total ?>)</p>
        <?php if (!$commissions): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <p>You haven't earned any commissions yet.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>Plan</th>
                        <th>Receipt</th>
                        <th>Commission</th>
                        <th>Date Earned</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($commissions as $row): 
                    $statusClass = $row['status'] === 'paid' ? 'badge badge-active' : 'badge badge-pending';
                ?>
                    <tr>
                        <td><strong><?= h($row['member_name']) ?></strong></td>
                        <td style="font-size:13px;color:var(--muted)"><?= h($row['plan_name']) ?></td>
                        <td style="font-size:13px;font-family:monospace;color:var(--muted)"><?= h($row['receipt_number']) ?></td>
                        <td><strong style="color:var(--lime)"><?= h(money($row['amount'])) ?></strong></td>
                        <td><?= h(date('M j, Y', strtotime($row['created_at']))) ?></td>
                        <td><span class="<?= $statusClass ?>"><?= h(ucfirst($row['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination($page, $totalPages, '?page=my_commissions'); ?>
        <?php endif; ?>
    </section>
    <?php
    render_footer();
}
