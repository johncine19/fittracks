<?php
declare(strict_types=1);

function payments_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner', 'member']);
    if (can($user, ['platform_admin', 'gym_owner']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $receipt = post('receipt_number') ?: 'RCPT-' . date('Ymd') . '-' . random_int(1000, 9999);
        $membershipId = (int) post('membership_id');
        $status = post('status');
        db()->prepare('INSERT INTO payments (membership_id, amount, payment_date, payment_method, status, receipt_number, processed_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$membershipId, post('amount'), post('payment_date'), post('payment_method'), $status, $receipt, $user['user_id']]);

        $paymentId = (int) db()->lastInsertId();

        // If the payment is marked as paid, automatically activate the membership
        if ($status === 'paid') {
            db()->prepare('UPDATE memberships SET status = "active" WHERE membership_id = ? AND status = "pending"')->execute([$membershipId]);
            process_trainer_commission($paymentId, (float) post('amount'));
        }

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

        audit_log($user['user_id'], 'create', 'payment', (string) $paymentId, json_encode(['membership_id' => $membershipId, 'amount' => post('amount'), 'status' => $status, 'receipt' => $receipt]));
        flash('Payment recorded.');
        redirect('payments');
    }

    $membershipWhere = '';
    $paymentWhere = '';
    if ($user['role'] === 'member') {
        $membershipWhere = 'WHERE m.user_id = ' . (int) $user['user_id'];
        $paymentWhere = 'WHERE m.user_id = ' . (int) $user['user_id'];
    } elseif ($user['role'] === 'gym_owner') {
        $gym = db()->query('SELECT gym_id FROM gyms WHERE owner_user_id = ' . (int) $user['user_id'])->fetch();
        $gymId = $gym ? $gym['gym_id'] : 0;
        $membershipWhere = 'WHERE p.gym_id = ' . (int) $gymId;
        $paymentWhere = 'WHERE p.gym_id = ' . (int) $gymId;
    }

    $memberships = db()->query('SELECT m.membership_id, CONCAT(u.first_name, " ", u.last_name, " — ", p.plan_name, " (", m.status, ")") AS label, p.price FROM memberships m JOIN users u ON u.user_id = m.user_id JOIN membership_plans p ON p.plan_id = m.plan_id ' . $membershipWhere . ' ORDER BY m.created_at DESC')->fetchAll();


    $page = max(1, (int)($_GET['p'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;

    $countSql = 'SELECT COUNT(*) FROM payments pay JOIN memberships m ON m.membership_id = pay.membership_id JOIN users u ON u.user_id = m.user_id JOIN membership_plans p ON p.plan_id = m.plan_id ' . $paymentWhere;
    $total = (int) scalar($countSql);
    $totalPages = (int) ceil($total / $limit);

    $rows = db()->query('SELECT pay.*, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name, p.plan_name FROM payments pay JOIN memberships m ON m.membership_id = pay.membership_id JOIN users u ON u.user_id = m.user_id JOIN membership_plans p ON p.plan_id = m.plan_id ' . $paymentWhere . ' ORDER BY pay.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset)->fetchAll();

    render_header('Payments', $user);
    ?>
    <div class="skeleton-wrapper">
        <section class="panel">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
                <div>
                    <div class="sk sk-title" style="width:140px;margin-bottom:8px"></div>
                    <div class="sk sk-text" style="width:280px;height:12px"></div>
                </div>
                <?php if (can($user, ['platform_admin', 'gym_owner'])): ?>
                    <div class="sk sk-rect" style="width:140px;height:36px;border-radius:18px"></div>
                <?php endif; ?>
            </div>
            <div class="sk sk-text short" style="margin-bottom:12px;height:14px;width:120px"></div>
            <?php render_skeleton_table(7, 8); ?>
        </section>
    </div>
    <section class="panel skeleton-content sk-display-block">
        <div class="page-header">
            <div>
                <h1>Payments</h1>
                <p>Record and track membership payment transactions.</p>
            </div>
            <?php if (can($user, ['platform_admin', 'gym_owner'])): ?>
                <button onclick="recordPayment()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold;">+ New Payment</button>
            <?php endif; ?>
        </div>

        <?php
            $totalCollected = (float) scalar('SELECT SUM(pm.amount) FROM payments pm JOIN memberships m ON pm.membership_id = m.membership_id JOIN membership_plans p ON p.plan_id = m.plan_id ' . ($paymentWhere ? str_replace('WHERE', 'WHERE pm.status=\'paid\' AND', $paymentWhere) : 'WHERE pm.status=\'paid\''));
            $totalPending = (int) scalar('SELECT COUNT(*) FROM payments pm JOIN memberships m ON pm.membership_id = m.membership_id JOIN membership_plans p ON p.plan_id = m.plan_id ' . ($paymentWhere ? str_replace('WHERE', 'WHERE pm.status=\'pending\' AND', $paymentWhere) : 'WHERE pm.status=\'pending\''));
        ?>
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:15px; margin-bottom: 24px;">
            <div style="background:var(--bg); padding:16px; border-radius:8px; border:1px solid var(--line);">
                <div style="color:var(--muted); font-size:13px; margin-bottom:4px;">Total Collected</div>
                <div style="font-size:24px; font-weight:bold; color:var(--lime);"><?= h(money($totalCollected)) ?></div>
            </div>
            <div style="background:var(--bg); padding:16px; border-radius:8px; border:1px solid var(--line);">
                <div style="color:var(--muted); font-size:13px; margin-bottom:4px;">Pending Payments</div>
                <div style="font-size:24px; font-weight:bold; color:var(--ink);"><?= $totalPending ?></div>
            </div>
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
                    $svgStyle = 'vertical-align: text-bottom; margin-right: 4px;';
                    $methodIcons = [
                        'cash' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="'.$svgStyle.'"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>',
                        'card' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="'.$svgStyle.'"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
                        'bank_transfer' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="'.$svgStyle.'"><rect x="4" y="10" width="16" height="12" rx="2"/><path d="M12 2l8 6H4z"/></svg>',
                        'online' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="'.$svgStyle.'"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
                        'other' => '—'
                    ];
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
    
    <?php if (can($user, ['platform_admin', 'gym_owner'])): ?>
    <script>
    function recordPayment() {
        Swal.fire({
            title: 'Record Payment',
            html: `
                <form id="recordPaymentForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <?= csrf_field() ?>
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Membership *
                        <select name="membership_id" class="form-control" style="width: 100%; box-sizing: border-box;" required onchange="document.getElementById(\'modalPaymentAmount\').value = this.options[this.selectedIndex].getAttribute(\'data-price\') || \'\';">
                            <option value="" data-price="">Select Membership...</option>
                            <?php foreach ($memberships as $membership): ?>
                                <option value="<?= (int) $membership['membership_id'] ?>" data-price="<?= h((string)$membership['price']) ?>"><?= h($membership['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Amount *
                            <input id="modalPaymentAmount" name="amount" type="number" step="0.01" class="form-control" placeholder="0.00" style="width: 100%; box-sizing: border-box;" required>
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
