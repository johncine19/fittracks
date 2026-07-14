<?php
declare(strict_types=1);

function commissions_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
        if ($action === 'mark_paid') {
            $commissionId = (int) post('commission_id');
            db()->prepare("UPDATE trainer_commissions SET status = 'paid' WHERE commission_id = ? AND status = 'pending'")
                ->execute([$commissionId]);
            audit_log($user['user_id'], 'mark_paid', 'commission', (string) $commissionId);
            flash('Commission marked as paid.');
            redirect('commissions');
        }
    }

    $page = max(1, (int)($_GET['p'] ?? 1));
    $limit = 15;
    $offset = ($page - 1) * $limit;

    $countSql = 'SELECT COUNT(*) FROM trainer_commissions';
    $total = (int) scalar($countSql);
    $totalPages = (int) ceil($total / $limit);

    $commissions = db()->query(
        'SELECT c.*, 
                CONCAT(u.first_name, " ", u.last_name) AS trainer_name,
                p.receipt_number,
                p.amount AS payment_amount,
                p.payment_date,
                mp.plan_name,
                CONCAT(mu.first_name, " ", mu.last_name) AS member_name
         FROM trainer_commissions c
         JOIN users u ON u.user_id = c.trainer_id
         JOIN payments p ON p.payment_id = c.payment_id
         JOIN memberships m ON m.membership_id = p.membership_id
         JOIN users mu ON mu.user_id = m.user_id
         JOIN membership_plans mp ON mp.plan_id = m.plan_id
         ORDER BY c.created_at DESC
         LIMIT ' . $limit . ' OFFSET ' . $offset
    )->fetchAll();

    render_header('Trainer Commissions', $user);
    ?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Trainer Commissions</h1>
                <p>Manage pending and paid payouts to trainers.</p>
            </div>
        </div>

        <p class="section-label">All Commissions (<?= $total ?>)</p>
        <?php if (!$commissions): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <p>No commissions logged yet.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Trainer</th>
                        <th>Member</th>
                        <th>Plan</th>
                        <th>Receipt</th>
                        <th>Commission</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th style="text-align:right">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($commissions as $row): 
                    $statusClass = $row['status'] === 'paid' ? 'badge badge-active' : 'badge badge-pending';
                ?>
                    <tr>
                        <td><strong><?= h($row['trainer_name']) ?></strong></td>
                        <td><?= h($row['member_name']) ?></td>
                        <td style="font-size:13px;color:var(--muted)"><?= h($row['plan_name']) ?></td>
                        <td style="font-size:13px;font-family:monospace;color:var(--muted)"><?= h($row['receipt_number']) ?></td>
                        <td><strong style="color:var(--lime)"><?= h(money($row['amount'])) ?></strong></td>
                        <td><?= h(date('M j, Y', strtotime($row['created_at']))) ?></td>
                        <td><span class="<?= $statusClass ?>"><?= h(ucfirst($row['status'])) ?></span></td>
                        <td style="text-align:right">
                            <?php if ($row['status'] === 'pending'): ?>
                                <button class="btn btn-sm" onclick="markPaid(<?= $row['commission_id'] ?>)" style="padding:4px 8px; font-size:12px; background:transparent; color:var(--lime); border:1px solid var(--lime); border-radius:4px; cursor:pointer;">Mark Paid</button>
                            <?php else: ?>
                                <span style="font-size:13px;color:var(--muted)">Settled</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination($page, $totalPages, '?page=commissions'); ?>
        <?php endif; ?>
    </section>

    <script>
    function markPaid(id) {
        Swal.fire({
            title: 'Mark as Paid?',
            text: "This will settle the commission for the trainer.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, mark paid',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_paid">
                    <input type="hidden" name="commission_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    }
    </script>
    <?php
    render_footer();
}
