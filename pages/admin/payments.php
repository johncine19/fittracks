<?php
declare(strict_types=1);

function payments_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner', 'member']);
    $isPlatformAdmin = ($user['role'] === 'platform_admin');

    // ── AJAX: Hybrid Live Search for Members ─────────────────────────
    if (($_GET['action'] ?? post('action')) === 'search_members') {
        if (ob_get_level()) ob_clean();
        header('Content-Type: application/json');

        $gymId = 0;
        if ($user['role'] === 'gym_owner') {
            $gymId = (int) scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
        }

        $q = trim((string)($_GET['q'] ?? post('q') ?? ''));
        $sql = 'SELECT u.user_id, u.first_name, u.last_name, u.email, u.profile_picture
                FROM users u
                WHERE u.role = "member" AND u.status = "active"';
        $params = [];
        if ($gymId > 0) {
            $sql .= ' AND EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ?)';
            $params[] = $gymId;
        }
        if ($q !== '') {
            $pattern = '%' . $q . '%';
            $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, " ", u.last_name) LIKE ? OR u.email LIKE ?)';
            $params[] = $pattern;
            $params[] = $pattern;
            $params[] = $pattern;
            $params[] = $pattern;
        }
        $sql .= ' ORDER BY u.first_name ASC LIMIT 50';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $membersFound = $stmt->fetchAll();

        $results = array_map(function ($m) {
            $firstName = trim((string)($m['first_name'] ?? ''));
            $lastName = trim((string)($m['last_name'] ?? ''));
            $fullName = trim($firstName . ' ' . $lastName) ?: 'Member';
            $ini = (!empty($firstName) ? strtoupper(substr($firstName, 0, 1)) : '') . (!empty($lastName) ? strtoupper(substr($lastName, 0, 1)) : '');
            return [
                'id' => (int)$m['user_id'],
                'name' => $fullName,
                'email' => (string)($m['email'] ?? ''),
                'initials' => $ini ?: 'M',
                'avatar' => !empty($m['profile_picture']) ? (string)$m['profile_picture'] : null
            ];
        }, $membersFound);

        echo json_encode(['results' => $results]);
        exit;
    }

    // Gym owners can record membership payments (Unified Single-Step Checkout)
    if ($user['role'] === 'gym_owner' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $receipt = post('receipt_number') ?: 'RCPT-' . date('Ymd') . '-' . random_int(1000, 9999);
        $membershipId = (int) post('membership_id');
        $planId = (int) post('plan_id');
        $memberUserId = (int) post('user_id');
        $status = post('status') ?: 'paid';
        $paymentDate = post('payment_date') ?: date('Y-m-d');
        $amount = (float) post('amount');
        $paymentMethod = post('payment_method') ?: 'cash';

        $gym = db()->query('SELECT gym_id FROM gyms WHERE owner_user_id = ' . (int) $user['user_id'])->fetch();
        $gymId = $gym ? (int) $gym['gym_id'] : 0;

        // If no pre-existing membership_id, but plan_id and user_id are supplied, create the membership!
        if (!$membershipId && $planId && $memberUserId) {
            $plan = db()->query('SELECT plan_id, plan_name, price, duration_days FROM membership_plans WHERE plan_id = ' . $planId)->fetch();
            if ($plan) {
                $duration = (int) ($plan['duration_days'] ?? 30);
                $start = new DateTime($paymentDate);
                $end = (clone $start)->modify('+' . $duration . ' days')->format('Y-m-d');
                $membershipStatus = ($status === 'paid') ? 'active' : 'pending';

                // Handle existing active plan for renewal or upgrade
                $currentActive = db()->query("SELECT m.* FROM memberships m WHERE m.user_id = $memberUserId AND m.status = 'active' ORDER BY m.end_date DESC LIMIT 1")->fetch();
                if ($currentActive) {
                    if ((int)$currentActive['plan_id'] === $planId) {
                        // Queue renewal from previous end date
                        $start = new DateTime($currentActive['end_date']);
                        $end = (clone $start)->modify('+' . $duration . ' days')->format('Y-m-d');
                        if ($status !== 'paid') {
                            $membershipStatus = 'pending';
                        }
                    } else {
                        // Upgrade/switch: cancel older active plan
                        if ($status === 'paid') {
                            db()->prepare("UPDATE memberships SET status = 'cancelled' WHERE membership_id = ?")->execute([$currentActive['membership_id']]);
                        }
                    }
                }

                db()->prepare('INSERT INTO memberships (user_id, plan_id, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$memberUserId, $planId, $start->format('Y-m-d'), $end, $membershipStatus]);
                $membershipId = (int) db()->lastInsertId();

                if ($gymId) {
                    db()->prepare('INSERT IGNORE INTO gym_members (gym_id, user_id) VALUES (?, ?)')->execute([$gymId, $memberUserId]);
                }
            }
        }

        if (!$membershipId) {
            flash('Error: A member and membership plan must be selected.', 'danger');
            redirect('payments');
        }

        db()->prepare('INSERT INTO payments (membership_id, amount, payment_date, payment_method, status, receipt_number, processed_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$membershipId, $amount, $paymentDate, $paymentMethod, $status, $receipt, $user['user_id']]);

        $paymentId = (int) db()->lastInsertId();

        // If the payment is marked as paid, automatically activate the membership
        if ($status === 'paid') {
            db()->prepare('UPDATE memberships SET status = "active" WHERE membership_id = ? AND status = "pending"')->execute([$membershipId]);
            process_trainer_commission($paymentId, $amount);
        }

        $paymentInfo = query_all(
            'SELECT m.user_id, p.plan_name, u.email, u.first_name, u.last_name
             FROM memberships m
             JOIN membership_plans p ON p.plan_id = m.plan_id
             JOIN users u ON u.user_id = m.user_id
             WHERE m.membership_id = ?',
            [$membershipId]
        );
        if ($paymentInfo) {
            $info = $paymentInfo[0];
            notify_user(
                (int) $info['user_id'],
                'system',
                'Payment recorded',
                money($amount) . ' received for ' . $info['plan_name'] . '. Receipt: ' . $receipt . '.'
            );

            if ($status === 'paid' && class_exists('Emails')) {
                try {
                    Emails::sendPaymentConfirmation(
                        $info['email'],
                        $info['first_name'] . ' ' . $info['last_name'],
                        $info['plan_name']
                    );
                } catch (\Throwable $e) {}
            }
        }

        audit_log($user['user_id'], 'create', 'payment', (string) $paymentId, json_encode(['membership_id' => $membershipId, 'amount' => $amount, 'status' => $status, 'receipt' => $receipt]));
        flash('Payment recorded.');
        redirect('memberships&view=payments');
    }

    // Backward-compatibility redirect:
    // Gym owners and members now use the unified "Memberships & Payments" hub at ?page=memberships&view=payments
    if (!$isPlatformAdmin && ($_GET['action'] ?? post('action')) !== 'search_members') {
        redirect('memberships&view=payments');
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

    $memberSql = 'SELECT u.user_id, u.first_name, u.last_name, u.email, u.profile_picture, CONCAT(u.first_name, " ", u.last_name) AS name 
                  FROM users u 
                  WHERE u.role = "member" AND u.status = "active"';
    if ($user['role'] === 'gym_owner' && $gymId) {
        $memberSql .= ' AND EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ' . (int)$gymId . ')';
    }
    $memberSql .= ' ORDER BY u.first_name';
    $members = db()->query($memberSql)->fetchAll();
    $initialMembersData = array_map(function ($m) {
        $firstName = trim((string)($m['first_name'] ?? ''));
        $lastName = trim((string)($m['last_name'] ?? ''));
        $fullName = trim($firstName . ' ' . $lastName) ?: (string)($m['name'] ?? 'Member');
        $ini = (!empty($firstName) ? strtoupper(substr($firstName, 0, 1)) : '') . (!empty($lastName) ? strtoupper(substr($lastName, 0, 1)) : '');
        return [
            'id' => (int) $m['user_id'],
            'name' => $fullName,
            'email' => (string) ($m['email'] ?? ''),
            'initials' => $ini ?: 'M',
            'avatar' => !empty($m['profile_picture']) ? (string)$m['profile_picture'] : null
        ];
    }, $members ?: []);

    $plans = ($gymId ? db()->query('SELECT plan_id, plan_name, price, duration_days FROM membership_plans WHERE gym_id = ' . (int) $gymId . ' ORDER BY price ASC')->fetchAll() : []);
    if (empty($plans)) {
        $plans = db()->query('SELECT plan_id, plan_name, price, duration_days FROM membership_plans ORDER BY price ASC')->fetchAll();
    }
    $plansData = array_map(function ($p) {
        return [
            'id' => (int) $p['plan_id'],
            'name' => (string) $p['plan_name'],
            'price' => (float) $p['price'],
            'formatted_price' => function_exists('money') ? money($p['price']) : ('₱' . number_format((float) $p['price'], 2)),
            'duration' => (int) ($p['duration_days'] ?? 30)
        ];
    }, $plans ?: []);

    $memberships = db()->query('SELECT m.membership_id, m.user_id, u.first_name, u.last_name, p.plan_name, m.status, CONCAT(u.first_name, " ", u.last_name, " — ", p.plan_name, " (", m.status, ")") AS label, p.price, m.end_date FROM memberships m JOIN users u ON u.user_id = m.user_id JOIN membership_plans p ON p.plan_id = m.plan_id ' . $membershipWhere . ' ORDER BY m.created_at DESC')->fetchAll();
    $membershipsData = array_map(function ($m) {
        $first = trim((string)($m['first_name'] ?? ''));
        $last = trim((string)($m['last_name'] ?? ''));
        $name = trim($first . ' ' . $last) ?: 'Member';
        $ini = (!empty($first) ? strtoupper(substr($first, 0, 1)) : '') . (!empty($last) ? strtoupper(substr($last, 0, 1)) : '');
        return [
            'id' => (int) $m['membership_id'],
            'user_id' => (int) $m['user_id'],
            'name' => $name,
            'plan_name' => (string) ($m['plan_name'] ?? ''),
            'status' => (string) ($m['status'] ?? 'active'),
            'price' => (float) ($m['price'] ?? 0),
            'formatted_price' => function_exists('money') ? money($m['price']) : ('₱' . number_format((float) $m['price'], 2)),
            'initials' => $ini ?: 'M',
            'label' => (string) $m['label'],
            'end_date' => $m['end_date'] ? date('M j, Y', strtotime($m['end_date'])) : ''
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
    window.paymentMembersData = <?= json_encode($initialMembersData ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    window.paymentPlansData = <?= json_encode($plansData ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    window.paymentMembershipsData = <?= json_encode($membershipsData ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    function recordPayment() {
        const members = Array.isArray(window.paymentMembersData) ? window.paymentMembersData : [];
        const plans = Array.isArray(window.paymentPlansData) ? window.paymentPlansData : [];
        const memberships = Array.isArray(window.paymentMembershipsData) ? window.paymentMembershipsData : [];

        const methodOptions = [
            { id: 'cash', label: 'Cash', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>' },
            { id: 'gcash', label: 'GCash', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>' },
            { id: 'card', label: 'Card', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>' },
            { id: 'bank_transfer', label: 'Bank Transfer', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M3 10h18M5 10v11M19 10v11M9 10v11M15 10v11M12 2L2 7h20L12 2z"/></svg>' },
            { id: 'online', label: 'Online', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>' },
            { id: 'other', label: 'Other', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>' }
        ];

        const statusOptions = [
            { id: 'paid', label: 'Paid', dotClass: 'status-dot-active' },
            { id: 'pending', label: 'Pending', dotClass: 'status-dot-pending' },
            { id: 'overdue', label: 'Overdue', dotClass: 'status-dot-cancelled' },
            { id: 'refunded', label: 'Refunded', dotClass: 'status-dot-expired' }
        ];

        let memberDrop = null;
        let itemDrop = null;
        let methodDrop = null;
        let statusDrop = null;
        let searchTimer = null;

        Swal.fire({
            title: 'Record Payment',
            width: '480px',
            html: `
                <form id="recordPaymentForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 14px; margin-top: 15px; min-height: 380px; position: relative;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" id="modalPaymentUserId" value="">
                    <input type="hidden" name="membership_id" id="modalPaymentMembershipId" value="">
                    <input type="hidden" name="plan_id" id="modalPaymentPlanId" value="">
                    
                    <!-- 1. Member Selector (Hybrid Live Search) -->
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Member *</label>
                        <div id="paymentMemberWrap"></div>
                    </div>

                    <!-- 2. Payment For / Plan Selector -->
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Payment For / Plan *</label>
                        <div id="paymentItemWrap"></div>
                    </div>
                    
                    <!-- 3. Amount & Payment Date in Row -->
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
                    
                    <!-- 4. Method & Status in Row -->
                    <div style="display:flex; gap:12px;">
                        <div style="flex:1;">
                            <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Method *</label>
                            <div id="paymentMethodWrap"></div>
                        </div>

                        <div style="flex:1;">
                            <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Status *</label>
                            <div id="paymentStatusWrap"></div>
                        </div>
                    </div>
                    
                    <!-- 5. Receipt # -->
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
                const amountInput = document.getElementById('modalPaymentAmount');
                const userIdInput = document.getElementById('modalPaymentUserId');
                const membershipIdInput = document.getElementById('modalPaymentMembershipId');
                const planIdInput = document.getElementById('modalPaymentPlanId');

                function buildItemOptions(userId) {
                    if (!userId) return [];
                    const items = [];
                    // 1. Existing memberships for this user
                    const userMemberships = memberships.filter(m => String(m.user_id) === String(userId));
                    userMemberships.forEach(m => {
                        items.push({
                            id: 'm_' + m.id,
                            membershipId: m.id,
                            planId: null,
                            label: `${m.plan_name} (Existing Membership)`,
                            subtitle: `Status: ${m.status}${m.end_date ? ' • Exp: ' + m.end_date : ''}`,
                            price: m.formatted_price,
                            rawPrice: m.price,
                            icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><polyline points="17 11 19 13 23 9"></polyline></svg>'
                        });
                    });

                    // 2. All available plans for this gym
                    plans.forEach(p => {
                        items.push({
                            id: 'p_' + p.id,
                            membershipId: null,
                            planId: p.id,
                            label: `${p.name} (New Subscription)`,
                            subtitle: `${p.duration} days`,
                            price: p.formatted_price,
                            rawPrice: p.price,
                            icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="3"></rect><line x1="3" y1="10" x2="21" y2="10"></line></svg>'
                        });
                    });

                    return items;
                }

                itemDrop = new FitDropdown({
                    container: '#paymentItemWrap',
                    placeholder: 'Select a member first...',
                    zIndex: 60,
                    items: [],
                    onChange: (selectedItem) => {
                        if (selectedItem) {
                            membershipIdInput.value = selectedItem.membershipId || '';
                            planIdInput.value = selectedItem.planId || '';
                            if (selectedItem.rawPrice !== undefined) {
                                amountInput.value = Number(selectedItem.rawPrice).toFixed(2);
                            }
                        } else {
                            membershipIdInput.value = '';
                            planIdInput.value = '';
                        }
                    }
                });

                memberDrop = new FitDropdown({
                    container: '#paymentMemberWrap',
                    placeholder: 'Search member by name or email...',
                    searchable: true,
                    searchPlaceholder: 'Search members...',
                    allowClear: true,
                    zIndex: 70,
                    items: members,
                    onSearch: (q, updateBadge, setResults) => {
                        clearTimeout(searchTimer);
                        if (!q.trim()) {
                            updateBadge('');
                            setResults(members);
                            return;
                        }
                        updateBadge('Searching...');
                        searchTimer = setTimeout(() => {
                            fetch('index.php?page=payments&action=search_members&q=' + encodeURIComponent(q.trim()))
                                .then(r => r.json())
                                .then(data => {
                                    if (data && data.results && Array.isArray(data.results)) {
                                        updateBadge(data.results.length + ' found');
                                        setResults(data.results);
                                    } else {
                                        updateBadge('');
                                    }
                                })
                                .catch(() => updateBadge(''));
                        }, 200);
                    },
                    onChange: (selectedMember) => {
                        if (selectedMember) {
                            userIdInput.value = selectedMember.id;
                            const newItems = buildItemOptions(selectedMember.id);
                            itemDrop.setItems(newItems);
                            if (newItems.length > 0) {
                                itemDrop.select(newItems[0].id, true);
                            }
                        } else {
                            userIdInput.value = '';
                            membershipIdInput.value = '';
                            planIdInput.value = '';
                            itemDrop.setItems([]);
                            itemDrop.select(null);
                            amountInput.value = '';
                        }
                    }
                });

                methodDrop = new FitDropdown({
                    container: '#paymentMethodWrap',
                    name: 'payment_method',
                    value: 'cash',
                    zIndex: 50,
                    items: methodOptions
                });

                statusDrop = new FitDropdown({
                    container: '#paymentStatusWrap',
                    name: 'status',
                    value: 'paid',
                    zIndex: 40,
                    items: statusOptions
                });
            },
            preConfirm: () => {
                const form = document.getElementById('recordPaymentForm');
                const userId = document.getElementById('modalPaymentUserId').value;
                const membershipId = document.getElementById('modalPaymentMembershipId').value;
                const planId = document.getElementById('modalPaymentPlanId').value;
                const amount = form.amount.value;
                const paymentDate = form.payment_date.value;

                let valid = true;
                if (!userId) {
                    if (memberDrop) memberDrop.setError(true);
                    valid = false;
                }
                if (!membershipId && !planId) {
                    if (itemDrop) itemDrop.setError(true);
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
    </style>
    <?php
    render_footer();
}
