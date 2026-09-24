<?php
declare(strict_types=1);

function payments_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner', 'member']);
    $isPlatformAdmin = ($user['role'] === 'platform_admin');

    // Gym owners can record membership payments
    if ($user['role'] === 'gym_owner' && $_SERVER['REQUEST_METHOD'] === 'POST') {
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

    $page = max(1, (int)($_GET['p'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;

    if ($isPlatformAdmin) {
        // Platform Admin: Manage and view Gym Owner Platform Subscriptions
        $totalCollected = (float) scalar('SELECT COALESCE(SUM(amount), 0) FROM gym_subscription_payments WHERE status = "paid"');
        $activeSubs = (int) scalar('SELECT COUNT(*) FROM gyms WHERE status = "approved" AND subscription_status = "active"');

        $countSql = 'SELECT COUNT(*) FROM gym_subscription_payments';
        $total = (int) scalar($countSql);
        $totalPages = (int) ceil($total / $limit);

        $rows = db()->query('
            SELECT sp.*, g.name AS gym_name, u.first_name, u.last_name, u.email
            FROM gym_subscription_payments sp
            JOIN gyms g ON g.gym_id = sp.gym_id
            JOIN users u ON u.user_id = sp.owner_user_id
            ORDER BY sp.payment_date DESC
            LIMIT ' . $limit . ' OFFSET ' . $offset
        )->fetchAll();

        render_header('Platform Subscriptions', $user);
        ?>
        <div class="skeleton-wrapper">
            <section class="panel">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
                    <div>
                        <div class="sk sk-title" style="width:140px;margin-bottom:8px"></div>
                        <div class="sk sk-text" style="width:280px;height:12px"></div>
                    </div>
                </div>
                <div class="payments-stats-row" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:20px;max-width:480px;">
                    <div class="sk sk-rect" style="height:58px;border-radius:8px"></div>
                    <div class="sk sk-rect" style="height:58px;border-radius:8px"></div>
                </div>
                <div class="sk sk-text short" style="margin-bottom:12px;height:14px;width:120px"></div>
                <?php render_skeleton_table(7, 8); ?>
            </section>
        </div>
        <section class="panel skeleton-content sk-display-block">
            <div class="page-header">
                <div>
                    <h1>Platform Subscriptions</h1>
                    <p>Track all platform subscription payments collected from registered gym owners.</p>
                </div>
            </div>

            <div class="payments-stats-row">
                <div class="payment-stat-card">
                    <div class="payment-stat-label">Total Subscriptions Collected</div>
                    <div class="payment-stat-value" style="color:var(--lime);"><?= h(money($totalCollected)) ?></div>
                </div>
                <div class="payment-stat-card">
                    <div class="payment-stat-label">Active Subscriptions</div>
                    <div class="payment-stat-value" style="color:var(--ink);"><?= $activeSubs ?> Gyms</div>
                </div>
            </div>

            <p class="section-label">Subscription Transactions</p>
            <?php if (!$rows): ?>
                <div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    <p>No subscription payment records yet.</p>
                </div>
            <?php else: ?>
            <div class="table-wrap payments-desktop-table">
                <table>
                    <thead>
                        <tr>
                            <th>Gym / Facility</th>
                            <th>Gym Owner</th>
                            <th>Plan</th>
                            <th>Amount</th>
                            <th>Payment Date</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $initials = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
                        $statusClass = 'badge badge-' . ($row['status'] === 'paid' ? 'paid' : 'pending');
                    ?>
                        <tr>
                            <td>
                                <strong><?= h($row['gym_name']) ?></strong>
                            </td>
                            <td>
                                <div class="user-cell">
                                    <span class="avatar small"><?= h($initials) ?></span>
                                    <span><?= h($row['first_name'] . ' ' . $row['last_name']) ?></span>
                                </div>
                            </td>
                            <td><span class="badge" style="background: rgba(34,197,94,0.1); color: var(--lime);"><?= h($row['plan_name']) ?></span></td>
                            <td><strong><?= h(money((float)$row['amount'])) ?></strong></td>
                            <td><?= h(date('M j, Y', strtotime($row['payment_date']))) ?></td>
                            <td><span style="color:var(--muted);font-size:12px"><?= strtoupper(h($row['payment_method'])) ?></span></td>
                            <td><span class="<?= $statusClass ?>"><?= h(ucfirst($row['status'])) ?></span></td>
                            <td><span style="color:var(--muted);font-size:12px;font-family:monospace"><?= h($row['receipt_number']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Cards View for Platform Subscriptions -->
            <div class="payments-mobile-cards">
                <?php foreach ($rows as $row):
                    $initials = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
                    $statusClass = 'badge badge-' . ($row['status'] === 'paid' ? 'paid' : 'pending');
                ?>
                <div class="payment-card-item">
                    <div class="payment-card-header">
                        <div class="user-cell">
                            <span class="avatar small"><?= h($initials) ?></span>
                            <div>
                                <div style="font-weight: 700; color: var(--ink);"><?= h($row['gym_name']) ?></div>
                                <div style="font-size: 12px; color: var(--muted);"><?= h($row['first_name'] . ' ' . $row['last_name']) ?></div>
                            </div>
                        </div>
                        <span class="<?= $statusClass ?>"><?= h(ucfirst($row['status'])) ?></span>
                    </div>
                    
                    <div class="payment-card-amount-row">
                        <div>
                            <span class="payment-card-amount"><?= h(money((float)$row['amount'])) ?></span>
                            <span class="badge" style="background: rgba(34,197,94,0.1); color: var(--lime); margin-left: 6px; font-size: 11px;"><?= h($row['plan_name']) ?></span>
                        </div>
                        <span class="payment-card-method">
                            <?= strtoupper(h($row['payment_method'])) ?>
                        </span>
                    </div>

                    <div class="payment-card-details">
                        <div class="payment-card-detail-item">
                            <span class="detail-label">Payment Date</span>
                            <span class="detail-val"><?= h(date('M j, Y', strtotime($row['payment_date']))) ?></span>
                        </div>
                        <div class="payment-card-detail-item">
                            <span class="detail-label">Receipt #</span>
                            <span class="detail-val receipt-pill"><?= h($row['receipt_number']) ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php render_pagination($page, $totalPages, '?page=payments'); ?>
            <?php endif; ?>
        </section>
        <?php
        render_footer();
        return;
    }

    // Gym Owner / Member Flow: Membership payments for specific gym
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

    $memberships = db()->query('SELECT m.membership_id, u.first_name, u.last_name, p.plan_name, m.status, CONCAT(u.first_name, " ", u.last_name, " — ", p.plan_name, " (", m.status, ")") AS label, p.price FROM memberships m JOIN users u ON u.user_id = m.user_id JOIN membership_plans p ON p.plan_id = m.plan_id ' . $membershipWhere . ' ORDER BY m.created_at DESC')->fetchAll();
    $membershipsData = array_map(function ($m) {
        $first = trim((string)($m['first_name'] ?? ''));
        $last = trim((string)($m['last_name'] ?? ''));
        $name = trim($first . ' ' . $last) ?: 'Member';
        $ini = (!empty($first) ? strtoupper(substr($first, 0, 1)) : '') . (!empty($last) ? strtoupper(substr($last, 0, 1)) : '');
        return [
            'id' => (int) $m['membership_id'],
            'name' => $name,
            'plan_name' => (string) ($m['plan_name'] ?? ''),
            'status' => (string) ($m['status'] ?? 'active'),
            'price' => (float) ($m['price'] ?? 0),
            'formatted_price' => function_exists('money') ? money($m['price']) : ('₱' . number_format((float) $m['price'], 2)),
            'initials' => $ini ?: 'M',
            'label' => (string) $m['label']
        ];
    }, $memberships ?: []);

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
                <?php if ($user['role'] === 'gym_owner'): ?>
                    <div class="sk sk-rect" style="width:140px;height:36px;border-radius:18px"></div>
                <?php endif; ?>
            </div>
            <div class="payments-stats-row" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:20px;max-width:480px;">
                <div class="sk sk-rect" style="height:58px;border-radius:8px"></div>
                <div class="sk sk-rect" style="height:58px;border-radius:8px"></div>
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
            <?php if ($user['role'] === 'gym_owner'): ?>
                <button onclick="recordPayment()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold;">+ New Payment</button>
            <?php endif; ?>
        </div>

        <?php
            $totalCollected = (float) scalar('SELECT SUM(pm.amount) FROM payments pm JOIN memberships m ON pm.membership_id = m.membership_id JOIN membership_plans p ON p.plan_id = m.plan_id ' . ($paymentWhere ? str_replace('WHERE', 'WHERE pm.status=\'paid\' AND', $paymentWhere) : 'WHERE pm.status=\'paid\''));
            $totalPending = (int) scalar('SELECT COUNT(*) FROM payments pm JOIN memberships m ON pm.membership_id = m.membership_id JOIN membership_plans p ON p.plan_id = m.plan_id ' . ($paymentWhere ? str_replace('WHERE', 'WHERE pm.status=\'pending\' AND', $paymentWhere) : 'WHERE pm.status=\'pending\''));
        ?>
        <div class="payments-stats-row">
            <div class="payment-stat-card">
                <div class="payment-stat-label">Total Collected</div>
                <div class="payment-stat-value" style="color:var(--lime);"><?= h(money($totalCollected)) ?></div>
            </div>
            <div class="payment-stat-card">
                <div class="payment-stat-label">Pending Payments</div>
                <div class="payment-stat-value" style="color:var(--ink);"><?= $totalPending ?></div>
            </div>
        </div>

        <p class="section-label">Payment history</p>
        <?php if (!$rows): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                <p>No payment records yet.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap payments-desktop-table">
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

        <!-- Mobile Cards View for Payment History -->
        <div class="payments-mobile-cards">
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
                $methodIcon = $methodIcons[$row['payment_method']] ?? '';
            ?>
            <div class="payment-card-item">
                <div class="payment-card-header">
                    <div class="user-cell">
                        <span class="avatar small"><?= h($initials) ?></span>
                        <div>
                            <div style="font-weight: 700; color: var(--ink);"><?= h($row['member']) ?></div>
                            <div style="font-size: 12px; color: var(--muted);"><?= h($row['plan_name']) ?></div>
                        </div>
                    </div>
                    <span class="<?= $statusClass ?>"><?= h(ucfirst($row['status'])) ?></span>
                </div>
                
                <div class="payment-card-amount-row">
                    <span class="payment-card-amount"><?= h(money($row['amount'])) ?></span>
                    <span class="payment-card-method">
                        <?= $methodIcon ?> <?= ucfirst(h($row['payment_method'])) ?>
                    </span>
                </div>

                <div class="payment-card-details">
                    <div class="payment-card-detail-item">
                        <span class="detail-label">Payment Date</span>
                        <span class="detail-val"><?= h(date('M j, Y', strtotime($row['payment_date']))) ?></span>
                    </div>
                    <div class="payment-card-detail-item">
                        <span class="detail-label">Receipt #</span>
                        <span class="detail-val receipt-pill"><?= h($row['receipt_number']) ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php render_pagination($page, $totalPages, '?page=payments'); ?>
        <?php endif; ?>
    </section>
    
    <?php if ($user['role'] === 'gym_owner'): ?>
    <script>
    window.paymentMembershipsData = <?= json_encode($membershipsData ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    function recordPayment() {
        const memberships = Array.isArray(window.paymentMembershipsData) ? window.paymentMembershipsData : [];
        const methodOptions = [
            { id: 'cash', label: 'Cash', svg: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>' },
            { id: 'card', label: 'Card', svg: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>' },
            { id: 'bank_transfer', label: 'Bank Transfer', svg: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M3 10h18M5 10v11M19 10v11M9 10v11M15 10v11M12 2L2 7h20L12 2z"/></svg>' },
            { id: 'online', label: 'Online', svg: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>' },
            { id: 'other', label: 'Other', svg: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>' }
        ];
        const statusOptions = [
            { id: 'paid', label: 'Paid', dotClass: 'status-dot-active' },
            { id: 'pending', label: 'Pending', dotClass: 'status-dot-pending' },
            { id: 'overdue', label: 'Overdue', dotClass: 'status-dot-cancelled' },
            { id: 'refunded', label: 'Refunded', dotClass: 'status-dot-expired' }
        ];

        let selectedMembershipId = null;
        let selectedMethod = 'cash';
        let selectedStatus = 'paid';

        Swal.fire({
            title: 'Record Payment',
            width: '460px',
            html: `
                <form id="recordPaymentForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 14px; margin-top: 15px; min-height: 330px; position: relative;">
                    <?= csrf_field() ?>
                    
                    <!-- Membership Dropdown -->
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Membership *</label>
                        <div class="wb-combobox-wrap" id="paymentMembershipWrap" style="position: relative; width: 100%; z-index: 60;">
                            <input type="hidden" name="membership_id" id="paymentMembershipId" value="" required>
                            
                            <div id="paymentMembershipTrigger" class="wb-combobox-trigger" tabindex="0" role="combobox" aria-expanded="false" aria-haspopup="listbox">
                                <div id="paymentMembershipTriggerContent" style="display: flex; align-items: center; gap: 8px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0;">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.6; flex-shrink: 0;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                    <span id="paymentMembershipPlaceholder" style="color: var(--muted); font-size: 14px;">Select Membership...</span>
                                </div>
                                <svg id="paymentMembershipChevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="transition: transform 0.2s ease; opacity: 0.7;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                            </div>

                            <div id="paymentMembershipMenu" class="wb-combobox-menu">
                                <div id="paymentMembershipListContainer" class="wb-combobox-list" role="listbox" style="padding: 4px;"></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Amount & Payment Date in Row -->
                    <div style="display:flex; gap:12px;">
                        <div style="flex:1;">
                            <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Amount *</label>
                            <input id="modalPaymentAmount" name="amount" type="number" step="0.01" class="wb-modal-input" placeholder="0.00" required>
                        </div>
                        <div style="flex:1;">
                            <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Payment date *</label>
                            <input name="payment_date" type="date" class="wb-modal-date-input" value="<?= h(date('Y-m-d')) ?>" required>
                        </div>
                    </div>
                    
                    <!-- Method & Status in Row -->
                    <div style="display:flex; gap:12px;">
                        <div style="flex:1;">
                            <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Method *</label>
                            <div class="wb-combobox-wrap" id="paymentMethodWrap" style="position: relative; width: 100%; z-index: 50;">
                                <input type="hidden" name="payment_method" id="paymentMethodInput" value="cash" required>
                                
                                <div id="paymentMethodTrigger" class="wb-combobox-trigger" tabindex="0" role="combobox" aria-expanded="false" aria-haspopup="listbox">
                                    <div id="paymentMethodTriggerContent" style="display: flex; align-items: center; gap: 8px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.8; flex-shrink: 0;"><rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle><path d="M6 12h.01M18 12h.01"></path></svg>
                                        <span style="font-weight: 600; color: var(--ink); font-size: 13.5px;">Cash</span>
                                    </div>
                                    <svg id="paymentMethodChevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="transition: transform 0.2s ease; opacity: 0.7;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                </div>

                                <div id="paymentMethodMenu" class="wb-combobox-menu">
                                    <div id="paymentMethodListContainer" class="wb-combobox-list" role="listbox" style="padding: 4px;"></div>
                                </div>
                            </div>
                        </div>

                        <div style="flex:1;">
                            <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Status *</label>
                            <div class="wb-combobox-wrap" id="paymentStatusWrap" style="position: relative; width: 100%; z-index: 40;">
                                <input type="hidden" name="status" id="paymentStatusInput" value="paid" required>
                                
                                <div id="paymentStatusTrigger" class="wb-combobox-trigger" tabindex="0" role="combobox" aria-expanded="false" aria-haspopup="listbox">
                                    <div id="paymentStatusTriggerContent" style="display: flex; align-items: center; gap: 8px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <span class="status-indicator-dot status-dot-active"></span>
                                        <span style="font-weight: 600; color: var(--ink); font-size: 13.5px;">Paid</span>
                                    </div>
                                    <svg id="paymentStatusChevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="transition: transform 0.2s ease; opacity: 0.7;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                </div>

                                <div id="paymentStatusMenu" class="wb-combobox-menu">
                                    <div id="paymentStatusListContainer" class="wb-combobox-list" role="listbox" style="padding: 4px;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Receipt # -->
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Receipt #</label>
                        <input name="receipt_number" class="wb-modal-input" placeholder="Auto-generated">
                    </div>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Save Payment',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            didOpen: () => {
                const membershipTrigger = document.getElementById('paymentMembershipTrigger');
                const membershipMenu = document.getElementById('paymentMembershipMenu');
                const membershipChevron = document.getElementById('paymentMembershipChevron');
                const membershipTriggerContent = document.getElementById('paymentMembershipTriggerContent');
                const membershipListContainer = document.getElementById('paymentMembershipListContainer');
                const membershipIdInput = document.getElementById('paymentMembershipId');
                const amountInput = document.getElementById('modalPaymentAmount');

                const methodTrigger = document.getElementById('paymentMethodTrigger');
                const methodMenu = document.getElementById('paymentMethodMenu');
                const methodChevron = document.getElementById('paymentMethodChevron');
                const methodTriggerContent = document.getElementById('paymentMethodTriggerContent');
                const methodListContainer = document.getElementById('paymentMethodListContainer');
                const methodInput = document.getElementById('paymentMethodInput');

                const statusTrigger = document.getElementById('paymentStatusTrigger');
                const statusMenu = document.getElementById('paymentStatusMenu');
                const statusChevron = document.getElementById('paymentStatusChevron');
                const statusTriggerContent = document.getElementById('paymentStatusTriggerContent');
                const statusListContainer = document.getElementById('paymentStatusListContainer');
                const statusInput = document.getElementById('paymentStatusInput');

                function escapeHtml(str) {
                    if (typeof window.escapeHtml === 'function') return window.escapeHtml(str);
                    const d = document.createElement('div');
                    d.textContent = str;
                    return d.innerHTML;
                }

                // Render Membership List
                function renderMemberships() {
                    if (memberships.length === 0) {
                        membershipListContainer.innerHTML = '<div style="padding: 12px; text-align: center; color: var(--muted); font-size: 13px;">No active memberships</div>';
                        return;
                    }
                    membershipListContainer.innerHTML = memberships.map(m => {
                        const isSelected = selectedMembershipId === m.id;
                        return `
                            <div class="wb-combobox-item ${isSelected ? 'selected' : ''}" data-id="${m.id}" role="option" style="padding: 9px 12px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; width: 100%; gap: 10px;">
                                    <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                                        <div class="wb-combobox-avatar" style="width: 26px; height: 26px; font-size: 10px;">
                                            ${escapeHtml(m.initials)}
                                        </div>
                                        <div style="display: flex; flex-direction: column; min-width: 0; text-align: left;">
                                            <span style="font-weight: 600; font-size: 13px; color: var(--ink); line-height: 1.2; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                ${escapeHtml(m.name)}
                                            </span>
                                            <span style="font-size: 11.5px; color: var(--muted); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                ${escapeHtml(m.plan_name)} &bull; ${escapeHtml(m.status)}
                                            </span>
                                        </div>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                                        <span style="font-size: 12px; font-weight: 700; color: var(--lime);">${escapeHtml(m.formatted_price)}</span>
                                        ${isSelected ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}
                                    </div>
                                </div>
                            </div>
                        `;
                    }).join('');

                    membershipListContainer.querySelectorAll('.wb-combobox-item').forEach(el => {
                        el.addEventListener('click', (e) => {
                            e.stopPropagation();
                            const id = parseInt(el.getAttribute('data-id'), 10);
                            selectMembership(id);
                            closeMembershipMenu();
                        });
                    });
                }

                function selectMembership(id) {
                    selectedMembershipId = id;
                    membershipIdInput.value = id || '';
                    membershipTrigger.classList.remove('error');
                    const m = memberships.find(item => item.id === id);
                    if (m) {
                        amountInput.value = (m.price > 0) ? m.price.toFixed(2) : '';
                        membershipTriggerContent.innerHTML = `
                            <div class="wb-combobox-avatar" style="width: 22px; height: 22px; font-size: 9.5px; border-width: 1px;">
                                ${escapeHtml(m.initials)}
                            </div>
                            <span style="font-weight: 600; color: var(--ink); font-size: 13.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                ${escapeHtml(m.name)}
                            </span>
                            <span style="font-size: 11.5px; color: var(--muted); margin-left: 2px;">
                                (${escapeHtml(m.plan_name)})
                            </span>
                            <span style="font-size: 12px; font-weight: 700; color: var(--lime); margin-left: auto; padding-right: 4px;">
                                ${escapeHtml(m.formatted_price)}
                            </span>
                        `;
                    } else {
                        membershipTriggerContent.innerHTML = `
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.6; flex-shrink: 0;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            <span id="paymentMembershipPlaceholder" style="color: var(--muted); font-size: 14px;">Select Membership...</span>
                        `;
                    }
                    renderMemberships();
                }

                // Render Method List
                function renderMethods() {
                    methodListContainer.innerHTML = methodOptions.map(opt => {
                        const isSelected = selectedMethod === opt.id;
                        return `
                            <div class="wb-combobox-item ${isSelected ? 'selected' : ''}" data-method="${opt.id}" role="option" style="padding: 9px 12px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span style="opacity: 0.8; display: flex; align-items: center;">${opt.svg}</span>
                                        <span style="font-weight: 600; font-size: 13.5px; color: var(--ink);">${escapeHtml(opt.label)}</span>
                                    </div>
                                    ${isSelected ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}
                                </div>
                            </div>
                        `;
                    }).join('');

                    methodListContainer.querySelectorAll('.wb-combobox-item').forEach(el => {
                        el.addEventListener('click', (e) => {
                            e.stopPropagation();
                            const m = el.getAttribute('data-method');
                            selectMethod(m);
                            closeMethodMenu();
                        });
                    });
                }

                function selectMethod(m) {
                    selectedMethod = m;
                    methodInput.value = m;
                    const opt = methodOptions.find(o => o.id === m) || methodOptions[0];
                    methodTriggerContent.innerHTML = `
                        <span style="opacity: 0.8; display: flex; align-items: center;">${opt.svg}</span>
                        <span style="font-weight: 600; color: var(--ink); font-size: 13.5px;">${escapeHtml(opt.label)}</span>
                    `;
                    renderMethods();
                }

                // Render Status List
                function renderStatuses() {
                    statusListContainer.innerHTML = statusOptions.map(opt => {
                        const isSelected = selectedStatus === opt.id;
                        return `
                            <div class="wb-combobox-item ${isSelected ? 'selected' : ''}" data-status="${opt.id}" role="option" style="padding: 9px 12px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span class="status-indicator-dot ${opt.dotClass}"></span>
                                        <span style="font-weight: 600; font-size: 13.5px; color: var(--ink);">${escapeHtml(opt.label)}</span>
                                    </div>
                                    ${isSelected ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}
                                </div>
                            </div>
                        `;
                    }).join('');

                    statusListContainer.querySelectorAll('.wb-combobox-item').forEach(el => {
                        el.addEventListener('click', (e) => {
                            e.stopPropagation();
                            const s = el.getAttribute('data-status');
                            selectStatus(s);
                            closeStatusMenu();
                        });
                    });
                }

                function selectStatus(s) {
                    selectedStatus = s;
                    statusInput.value = s;
                    const opt = statusOptions.find(o => o.id === s) || statusOptions[0];
                    statusTriggerContent.innerHTML = `
                        <span class="status-indicator-dot ${opt.dotClass}"></span>
                        <span style="font-weight: 600; color: var(--ink); font-size: 13.5px;">${escapeHtml(opt.label)}</span>
                    `;
                    renderStatuses();
                }

                // Coordinated Open/Close
                function openMembershipMenu() {
                    closeMethodMenu();
                    closeStatusMenu();
                    document.getElementById('paymentMembershipWrap').style.zIndex = '90';
                    membershipMenu.style.display = 'block';
                    membershipChevron.style.transform = 'rotate(180deg)';
                    membershipTrigger.setAttribute('aria-expanded', 'true');
                }
                function closeMembershipMenu() {
                    document.getElementById('paymentMembershipWrap').style.zIndex = '60';
                    membershipMenu.style.display = 'none';
                    membershipChevron.style.transform = 'rotate(0deg)';
                    membershipTrigger.setAttribute('aria-expanded', 'false');
                }

                function openMethodMenu() {
                    closeMembershipMenu();
                    closeStatusMenu();
                    document.getElementById('paymentMethodWrap').style.zIndex = '90';
                    methodMenu.style.display = 'block';
                    methodChevron.style.transform = 'rotate(180deg)';
                    methodTrigger.setAttribute('aria-expanded', 'true');
                }
                function closeMethodMenu() {
                    document.getElementById('paymentMethodWrap').style.zIndex = '50';
                    methodMenu.style.display = 'none';
                    methodChevron.style.transform = 'rotate(0deg)';
                    methodTrigger.setAttribute('aria-expanded', 'false');
                }

                function openStatusMenu() {
                    closeMembershipMenu();
                    closeMethodMenu();
                    document.getElementById('paymentStatusWrap').style.zIndex = '90';
                    statusMenu.style.display = 'block';
                    statusChevron.style.transform = 'rotate(180deg)';
                    statusTrigger.setAttribute('aria-expanded', 'true');
                }
                function closeStatusMenu() {
                    document.getElementById('paymentStatusWrap').style.zIndex = '40';
                    statusMenu.style.display = 'none';
                    statusChevron.style.transform = 'rotate(0deg)';
                    statusTrigger.setAttribute('aria-expanded', 'false');
                }

                membershipTrigger.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (membershipMenu.style.display === 'block') closeMembershipMenu();
                    else openMembershipMenu();
                });

                methodTrigger.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (methodMenu.style.display === 'block') closeMethodMenu();
                    else openMethodMenu();
                });

                statusTrigger.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (statusMenu.style.display === 'block') closeStatusMenu();
                    else openStatusMenu();
                });

                document.addEventListener('click', function onDocClick(e) {
                    if (!document.getElementById('recordPaymentForm')) {
                        document.removeEventListener('click', onDocClick);
                        return;
                    }
                    const mWrap = document.getElementById('paymentMembershipWrap');
                    const metWrap = document.getElementById('paymentMethodWrap');
                    const sWrap = document.getElementById('paymentStatusWrap');

                    if (mWrap && !mWrap.contains(e.target)) closeMembershipMenu();
                    if (metWrap && !metWrap.contains(e.target)) closeMethodMenu();
                    if (sWrap && !sWrap.contains(e.target)) closeStatusMenu();
                });

                renderMemberships();
                renderMethods();
                renderStatuses();
            },
            preConfirm: () => {
                const form = document.getElementById('recordPaymentForm');
                const membershipId = document.getElementById('paymentMembershipId').value;
                const amount = form.amount.value;
                const paymentDate = form.payment_date.value;

                let valid = true;
                if (!membershipId) {
                    document.getElementById('paymentMembershipTrigger').classList.add('error');
                    valid = false;
                }
                if (!valid || !amount || !paymentDate) {
                    Swal.showValidationMessage('Please fill all required fields');
                    return false;
                }
                form.submit();
            }
        });
    }
    window.recordPayment = recordPayment;
    </script>
    <?php endif; ?>

    <style>
    /* ============================================================
       Responsive Mobile Cards for Payments & Subscriptions
       ============================================================ */
    .payments-mobile-cards {
        display: none;
    }

    @media (max-width: 768px) {
        .payments-desktop-table {
            display: none !important;
        }
        .payments-mobile-cards {
            display: flex !important;
            flex-direction: column;
            gap: 12px;
            margin-top: 10px;
            margin-bottom: 20px;
        }
    }

    .payment-card-item {
        background: color-mix(in srgb, var(--panel-soft) 45%, transparent);
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 14px 16px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        transition: all 0.2s ease;
    }
    [data-theme="light"] .payment-card-item {
        background: #ffffff;
        border-color: #e2e8f0;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
    }
    .payment-card-item:hover {
        border-color: color-mix(in srgb, var(--lime) 35%, transparent);
    }

    .payment-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 10px;
        padding-bottom: 10px;
        border-bottom: 1px solid color-mix(in srgb, var(--line) 60%, transparent);
    }
    [data-theme="light"] .payment-card-header {
        border-bottom-color: #f1f5f9;
    }

    .payment-card-amount-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }
    .payment-card-amount {
        font-size: 1.15rem;
        font-weight: 800;
        color: var(--lime);
    }
    [data-theme="light"] .payment-card-amount {
        color: #15803d;
    }

    .payment-card-method {
        display: inline-flex;
        align-items: center;
        font-size: 12px;
        color: var(--muted);
        background: rgba(128, 128, 128, 0.08);
        padding: 3px 8px;
        border-radius: 6px;
    }

    .payment-card-details {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    .payment-card-detail-item {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    .payment-card-detail-item .detail-label {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--muted);
    }
    .payment-card-detail-item .detail-val {
        font-size: 13px;
        font-weight: 500;
        color: var(--ink);
    }
    .receipt-pill {
        font-family: monospace;
        font-size: 11.5px !important;
        color: var(--muted) !important;
    }

    /* Compact 2-column Stat Cards */
    .payments-stats-row {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 20px;
        max-width: 480px;
    }
    .payment-stat-card {
        background: var(--bg);
        padding: 10px 14px;
        border-radius: 8px;
        border: 1px solid var(--line);
        display: flex;
        flex-direction: column;
        justify-content: center;
        min-width: 0;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        transition: border-color 0.15s ease;
    }
    .payment-stat-label {
        color: var(--muted);
        font-size: 11.5px;
        font-weight: 600;
        margin-bottom: 3px;
        text-transform: uppercase;
        letter-spacing: 0.35px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .payment-stat-value {
        font-size: 18px;
        font-weight: 700;
        line-height: 1.25;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    [data-theme="light"] .payment-stat-card {
        background: #ffffff;
        border-color: #cbd5e1;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
    }

    /* ============================================================
       SweetAlert Modal Custom Combobox & Dropdown Design System
       ============================================================ */
    .swal2-popup {
        overflow: visible !important;
    }
    .swal2-html-container {
        overflow: visible !important;
        position: relative !important;
        z-index: 30 !important;
    }
    .swal2-popup .swal2-actions {
        z-index: 10 !important;
        position: relative !important;
        margin-top: 18px !important;
    }

    .wb-combobox-wrap {
        position: relative;
        width: 100%;
    }
    .wb-combobox-trigger {
        width: 100%;
        box-sizing: border-box;
        background: #141d2b;
        border: 1px solid #334155;
        border-radius: 8px;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        cursor: pointer;
        user-select: none;
        transition: all 0.2s ease;
        outline: none;
        color: #f8fafc;
        height: 42px;
    }
    .wb-combobox-trigger:hover,
    .wb-combobox-trigger:focus {
        border-color: var(--lime, #84cc16);
        box-shadow: 0 0 0 2px rgba(132, 204, 22, 0.2);
    }
    .wb-combobox-trigger.error {
        border-color: #ef4444 !important;
        box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.25) !important;
    }
    [data-theme="light"] .wb-combobox-trigger {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        color: #0f172a;
    }
    [data-theme="light"] .wb-combobox-trigger:hover,
    [data-theme="light"] .wb-combobox-trigger:focus {
        border-color: var(--lime, #65a30d);
        box-shadow: 0 0 0 2px rgba(101, 163, 13, 0.18);
    }

    .wb-combobox-menu {
        display: none;
        position: absolute;
        top: calc(100% + 5px);
        left: 0;
        right: 0;
        background: #141d2c;
        border: 1px solid #2e3d52;
        border-radius: 10px;
        box-shadow: 0 16px 36px rgba(0, 0, 0, 0.65), 0 4px 12px rgba(0, 0, 0, 0.4);
        z-index: 99999;
        overflow: hidden;
        text-align: left;
    }
    [data-theme="light"] .wb-combobox-menu {
        background: #ffffff;
        border-color: #cbd5e1;
        box-shadow: 0 16px 36px rgba(0, 0, 0, 0.12), 0 4px 12px rgba(0, 0, 0, 0.08);
    }

    .wb-combobox-list {
        max-height: 180px;
        overflow-y: auto;
        padding: 4px;
        scrollbar-width: thin;
        scrollbar-color: #334155 #141d2c;
    }
    .wb-combobox-list::-webkit-scrollbar {
        width: 6px;
    }
    .wb-combobox-list::-webkit-scrollbar-track {
        background: #141d2c;
    }
    .wb-combobox-list::-webkit-scrollbar-thumb {
        background: #334155;
        border-radius: 3px;
    }
    [data-theme="light"] .wb-combobox-list {
        scrollbar-color: #cbd5e1 #ffffff;
    }
    [data-theme="light"] .wb-combobox-list::-webkit-scrollbar-track {
        background: #f8fafc;
    }
    [data-theme="light"] .wb-combobox-list::-webkit-scrollbar-thumb {
        background: #cbd5e1;
    }

    .wb-combobox-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 8px 10px;
        border-radius: 6px;
        cursor: pointer;
        transition: background 0.15s ease;
        user-select: none;
        margin-bottom: 2px;
        color: #f8fafc;
    }
    .wb-combobox-item:hover,
    .wb-combobox-item.active {
        background: #1e2a3c;
    }
    .wb-combobox-item.selected {
        background: rgba(132, 204, 22, 0.16);
    }
    [data-theme="light"] .wb-combobox-item {
        color: #0f172a;
    }
    [data-theme="light"] .wb-combobox-item:hover,
    [data-theme="light"] .wb-combobox-item.active {
        background: #f1f5f9;
    }
    [data-theme="light"] .wb-combobox-item.selected {
        background: rgba(101, 163, 13, 0.12);
    }

    .wb-combobox-avatar {
        width: 26px;
        height: 26px;
        border-radius: 50%;
        background: #223049;
        color: var(--lime, #84cc16);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        font-weight: 700;
        border: 1px solid rgba(132, 204, 22, 0.3);
        flex-shrink: 0;
    }
    [data-theme="light"] .wb-combobox-avatar {
        background: #ecfccb;
        color: #3f6212;
        border-color: #bef264;
    }

    /* Modal Inputs */
    .wb-modal-input,
    .wb-modal-date-input {
        width: 100%;
        box-sizing: border-box;
        height: 42px;
        border-radius: 8px;
        border: 1px solid #334155;
        background: #141d2b;
        color: #f8fafc;
        padding: 10px 14px;
        font-size: 13.5px;
        outline: none;
        transition: all 0.2s ease;
        font-family: inherit;
    }
    .wb-modal-input:focus,
    .wb-modal-date-input:focus {
        border-color: var(--lime, #84cc16);
        box-shadow: 0 0 0 2px rgba(132, 204, 22, 0.2);
    }
    [data-theme="light"] .wb-modal-input,
    [data-theme="light"] .wb-modal-date-input {
        background: #ffffff !important;
        border-color: #cbd5e1 !important;
        color: #0f172a !important;
    }
    [data-theme="light"] .wb-modal-input:focus,
    [data-theme="light"] .wb-modal-date-input:focus {
        border-color: var(--lime, #65a30d) !important;
        box-shadow: 0 0 0 2px rgba(101, 163, 13, 0.18) !important;
    }

    /* Status Indicator Dots */
    .status-indicator-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        display: inline-block;
        flex-shrink: 0;
    }
    .status-dot-active {
        background: #84cc16;
        box-shadow: 0 0 6px rgba(132, 204, 22, 0.6);
    }
    .status-dot-pending {
        background: #f59e0b;
        box-shadow: 0 0 6px rgba(245, 158, 11, 0.6);
    }
    .status-dot-expired {
        background: #64748b;
    }
    .status-dot-cancelled {
        background: #ef4444;
        box-shadow: 0 0 6px rgba(239, 68, 68, 0.6);
    }
    [data-theme="light"] .status-dot-active {
        background: #16a34a;
        box-shadow: 0 0 6px rgba(22, 163, 74, 0.35);
    }
    [data-theme="light"] .status-dot-pending {
        background: #d97706;
        box-shadow: 0 0 6px rgba(217, 119, 6, 0.35);
    }
    [data-theme="light"] .status-dot-cancelled {
        background: #dc2626;
        box-shadow: 0 0 6px rgba(220, 38, 38, 0.35);
    }
    </style>
    <?php
    render_footer();
}
