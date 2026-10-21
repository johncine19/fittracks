<?php
declare(strict_types=1);

function commissions_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner']);
    if ($user['role'] === 'gym_owner') {
        require_gym_feature('commissions');
    }

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
    <style>
        .admin-commission-mobile-list { display: none; }
        @media (max-width: 640px) {
            .admin-commission-table { display: none; }
            .admin-commission-mobile-list { display: grid; gap: 12px; }
            .admin-commission-card {
                padding: 16px;
                border: 1px solid var(--line);
                border-radius: 12px;
                background: var(--surface);
            }
            .admin-commission-card-head {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 12px;
                margin-bottom: 14px;
            }
            .admin-commission-trainer { margin: 0; color: var(--ink); font-size: 16px; }
            .admin-commission-amount { color: var(--lime); font-size: 18px; font-weight: 800; white-space: nowrap; }
            .admin-commission-details {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
                padding-top: 12px;
                border-top: 1px solid var(--line);
            }
            .admin-commission-label {
                display: block;
                margin-bottom: 4px;
                color: var(--muted);
                font-size: 10px;
                font-weight: 700;
                letter-spacing: .06em;
                text-transform: uppercase;
            }
            .admin-commission-value { color: var(--ink); font-size: 13px; line-height: 1.4; overflow-wrap: anywhere; }
            .admin-commission-action { width: 100%; margin-top: 14px; padding: 8px 10px !important; }
        }
    </style>
    <section class="panel">
        <div class="page-header">
            <div>
                <p style="margin: 0;">Manage pending and paid payouts to trainers.</p>
            </div>
        </div>

        <p class="section-label">All Commissions (<?= $total ?>)</p>
        <?php if (!$commissions): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <p>No commissions logged yet.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap admin-commission-table">
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
        <div class="admin-commission-mobile-list">
            <?php foreach ($commissions as $row):
                $statusClass = $row['status'] === 'paid' ? 'badge badge-active' : 'badge badge-pending';
            ?>
                <article class="admin-commission-card">
                    <div class="admin-commission-card-head">
                        <h3 class="admin-commission-trainer"><?= h($row['trainer_name']) ?></h3>
                        <span class="admin-commission-amount"><?= h(money($row['amount'])) ?></span>
                    </div>
                    <div class="admin-commission-details">
                        <div>
                            <span class="admin-commission-label">Member</span>
                            <div class="admin-commission-value"><?= h($row['member_name']) ?></div>
                        </div>
                        <div>
                            <span class="admin-commission-label">Plan</span>
                            <div class="admin-commission-value"><?= h($row['plan_name']) ?></div>
                        </div>
                        <div>
                            <span class="admin-commission-label">Receipt</span>
                            <div class="admin-commission-value" style="font-family:monospace;"><?= h($row['receipt_number']) ?></div>
                        </div>
                        <div>
                            <span class="admin-commission-label">Date</span>
                            <div class="admin-commission-value"><?= h(date('M j, Y', strtotime($row['created_at']))) ?></div>
                        </div>
                        <div>
                            <span class="admin-commission-label">Status</span>
                            <span class="<?= $statusClass ?>"><?= h(ucfirst($row['status'])) ?></span>
                        </div>
                    </div>
                    <?php if ($row['status'] === 'pending'): ?>
                        <button class="btn btn-sm admin-commission-action" onclick="markPaid(<?= $row['commission_id'] ?>)" style="background:transparent; color:var(--lime); border:1px solid var(--lime); border-radius:6px; cursor:pointer;">Mark Paid</button>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
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
