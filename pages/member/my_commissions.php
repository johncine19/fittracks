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
    <style>
        .commission-mobile-list { display: none; }
        @media (max-width: 640px) {
            .commission-history-table { display: none; }
            .commission-mobile-list { display: grid; gap: 12px; }
            .commission-mobile-card {
                padding: 16px;
                border: 1px solid var(--line);
                border-radius: 12px;
                background: var(--surface);
            }
            .commission-mobile-card-head {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 12px;
                margin-bottom: 14px;
            }
            .commission-mobile-member { margin: 0; color: var(--ink); font-size: 16px; }
            .commission-mobile-amount { color: var(--lime); font-size: 18px; font-weight: 800; white-space: nowrap; }
            .commission-mobile-details {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
                padding-top: 12px;
                border-top: 1px solid var(--line);
            }
            .commission-mobile-label {
                display: block;
                margin-bottom: 4px;
                color: var(--muted);
                font-size: 10px;
                font-weight: 700;
                letter-spacing: .06em;
                text-transform: uppercase;
            }
            .commission-mobile-value { color: var(--ink); font-size: 13px; line-height: 1.4; overflow-wrap: anywhere; }
        }
    </style>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>My Commissions</h1>
                <p>Track your earnings from member subscriptions.</p>
            </div>
        </div>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr)); gap:15px; margin-bottom: 20px;">
            <div style="background:var(--bg); padding:15px; border-radius:8px; border:1px solid var(--line);">
                <div style="color:var(--muted); font-size:13px; margin-bottom:5px;">Pending Earnings</div>
                <div style="font-size:24px; font-weight:bold; color:var(--ink);"><?= h(money($stats['pending_total'] ?? 0)) ?></div>
            </div>
            <div style="background:var(--bg); padding:15px; border-radius:8px; border:1px solid var(--line);">
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
        <div class="table-wrap commission-history-table">
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
        <div class="commission-mobile-list">
            <?php foreach ($commissions as $row):
                $statusClass = $row['status'] === 'paid' ? 'badge badge-active' : 'badge badge-pending';
            ?>
                <article class="commission-mobile-card">
                    <div class="commission-mobile-card-head">
                        <h3 class="commission-mobile-member"><?= h($row['member_name']) ?></h3>
                        <span class="commission-mobile-amount"><?= h(money($row['amount'])) ?></span>
                    </div>
                    <div class="commission-mobile-details">
                        <div>
                            <span class="commission-mobile-label">Plan</span>
                            <div class="commission-mobile-value"><?= h($row['plan_name']) ?></div>
                        </div>
                        <div>
                            <span class="commission-mobile-label">Receipt</span>
                            <div class="commission-mobile-value" style="font-family:monospace;"><?= h($row['receipt_number']) ?></div>
                        </div>
                        <div>
                            <span class="commission-mobile-label">Date earned</span>
                            <div class="commission-mobile-value"><?= h(date('M j, Y', strtotime($row['created_at']))) ?></div>
                        </div>
                        <div>
                            <span class="commission-mobile-label">Status</span>
                            <span class="<?= $statusClass ?>"><?= h(ucfirst($row['status'])) ?></span>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php render_pagination($page, $totalPages, '?page=my_commissions'); ?>
        <?php endif; ?>
    </section>
    <?php
    render_footer();
}
