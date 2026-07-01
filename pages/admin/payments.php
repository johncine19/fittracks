<?php
declare(strict_types=1);

function payments_page(): void
{
    $user = require_roles(['admin', 'member']);
    if (can($user, ['admin']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $receipt = post('receipt_number') ?: 'RCPT-' . date('Ymd') . '-' . random_int(1000, 9999);
        $membershipId = (int) post('membership_id');
        db()->prepare('INSERT INTO payments (membership_id, amount, payment_date, payment_method, status, receipt_number, processed_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$membershipId, post('amount'), post('payment_date'), post('payment_method'), post('status'), $receipt, $user['user_id']]);

        $paymentInfo = query_all(
            'SELECT m.user_id, p.plan_name
             FROM memberships m
             JOIN membership_plans p ON p.plan_id = m.plan_id
             WHERE m.membership_id = ?',
            [$membershipId]
        );
        if ($paymentInfo) {
            $info = $paymentInfo[0];
            notify_user(
                (int) $info['user_id'],
                'system',
                'Payment recorded',
                money(post('amount')) . ' received for ' . $info['plan_name'] . '. Receipt: ' . $receipt . '.'
            );
        }

        flash('Payment recorded.');
        redirect('payments');
    }

    $membershipWhere = $user['role'] === 'member' ? 'WHERE m.user_id = ' . (int) $user['user_id'] : '';
    $memberships = db()->query('SELECT m.membership_id, CONCAT(u.first_name, " ", u.last_name, " — ", p.plan_name, " (", m.status, ")") AS label, p.price FROM memberships m JOIN users u ON u.user_id = m.user_id JOIN membership_plans p ON p.plan_id = m.plan_id ' . $membershipWhere . ' ORDER BY m.created_at DESC')->fetchAll();
    $paymentWhere = $user['role'] === 'member' ? 'WHERE m.user_id = ' . (int) $user['user_id'] : '';

    $page = max(1, (int)($_GET['p'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;

    $countSql = 'SELECT COUNT(*) FROM payments pay JOIN memberships m ON m.membership_id = pay.membership_id JOIN users u ON u.user_id = m.user_id JOIN membership_plans p ON p.plan_id = m.plan_id ' . $paymentWhere;
    $total = (int) scalar($countSql);
    $totalPages = (int) ceil($total / $limit);

    $rows = db()->query('SELECT pay.*, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name, p.plan_name FROM payments pay JOIN memberships m ON m.membership_id = pay.membership_id JOIN users u ON u.user_id = m.user_id JOIN membership_plans p ON p.plan_id = m.plan_id ' . $paymentWhere . ' ORDER BY pay.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset)->fetchAll();

    render_header('Payments', $user);
    ?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Payments</h1>
                <p>Record and track membership payment transactions.</p>
            </div>
            <?php if (can($user, ['admin'])): ?>
                <button onclick="recordPayment()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold;">+ New Payment</button>
            <?php endif; ?>
        </div>



        <p class="section-label">Payment history</p>
        <?php if (!$rows): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                <p>No payment records yet.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>Plan</th>
                        <th>Amount</th>
                        <th>Date</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Receipt</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row):
                    $initials = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
                    $statusClass = 'badge badge-' . $row['status'];
                    $methodIcons = ['cash' => '💵', 'card' => '💳', 'bank_transfer' => '🏦', 'online' => '🌐', 'other' => '—'];
                ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <span class="avatar small"><?= h($initials) ?></span>
                                <span><?= h($row['member']) ?></span>
                            </div>
                        </td>
                        <td><?= h($row['plan_name']) ?></td>
                        <td><strong><?= h(money($row['amount'])) ?></strong></td>
                        <td><?= h(date('M j, Y', strtotime($row['payment_date']))) ?></td>
                        <td><span style="color:var(--muted);font-size:12px"><?= ($methodIcons[$row['payment_method']] ?? '') . ' ' . h($row['payment_method']) ?></span></td>
                        <td><span class="<?= $statusClass ?>"><?= h($row['status']) ?></span></td>
                        <td><span style="color:var(--muted);font-size:12px;font-family:monospace"><?= h($row['receipt_number']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination($page, $totalPages, '?page=payments'); ?>
        <?php endif; ?>
    </section>
    
    <?php if (can($user, ['admin'])): ?>
    <script>
    function recordPayment() {
        Swal.fire({
            title: 'Record Payment',
            html: `
                <form id="recordPaymentForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <?= csrf_field() ?>
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Membership *
                        <select name="membership_id" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                            <option value="">Select Membership...</option>
                            <?php foreach ($memberships as $membership): ?>
                                <option value="<?= (int) $membership['membership_id'] ?>"><?= h($membership['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Amount *
                            <input name="amount" type="number" step="0.01" class="form-control" placeholder="0.00" style="width: 100%; box-sizing: border-box;" required>
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Payment date *
                            <input name="payment_date" type="date" class="form-control" value="<?= h(date('Y-m-d')) ?>" style="width: 100%; box-sizing: border-box;" required>
                        </label>
                    </div>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Method *
                            <select name="payment_method" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="online">Online</option>
                                <option value="other">Other</option>
                            </select>
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Status *
                            <select name="status" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                                <option value="paid">Paid</option>
                                <option value="pending">Pending</option>
                                <option value="overdue">Overdue</option>
                                <option value="refunded">Refunded</option>
                            </select>
                        </label>
                    </div>
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Receipt #
                        <input name="receipt_number" class="form-control" placeholder="Auto-generated" style="width: 100%; box-sizing: border-box;">
                    </label>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Save Payment',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('recordPaymentForm');
                if (!form.membership_id.value || !form.amount.value || !form.payment_date.value) {
                    Swal.showValidationMessage('Please fill all required fields');
                    return false;
                }
                form.submit();
            }
        });
    }
    </script>
    <?php endif; ?>
    <?php
    render_footer();
}
