<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function memberships_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner', 'member']);
    
    if ($user['role'] === 'member') {
        $isGymMember = db()->prepare('SELECT 1 FROM gym_members WHERE user_id = ?');
        $isGymMember->execute([$user['user_id']]);
        if (!$isGymMember->fetchColumn()) {
            flash('Please select a gym first to view this page.', 'warning');
            redirect('gym_selection');
        }
    }

    if ($user['role'] === 'gym_owner' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['update_status_id'])) {
            $membershipId = (int) post('update_status_id');
            $status = post('status');
            db()->prepare('UPDATE memberships SET status = ? WHERE membership_id = ?')->execute([$status, $membershipId]);
            
            // Also update payment status if it exists and status is active
            if ($status === 'active') {
                db()->prepare('UPDATE payments SET status = "paid" WHERE membership_id = ? AND status = "pending"')->execute([$membershipId]);
                
                $mInfo = db()->query('SELECT m.user_id, p.plan_name, u.email, u.first_name, u.last_name FROM memberships m JOIN membership_plans p ON p.plan_id = m.plan_id JOIN users u ON u.user_id = m.user_id WHERE m.membership_id = ' . $membershipId)->fetch();
                if ($mInfo) {
                    // Cancel any other active plans for this user since they now have a new active one
                    db()->prepare("UPDATE memberships SET status = 'cancelled' WHERE user_id = ? AND membership_id != ? AND status = 'active'")->execute([$mInfo['user_id'], $membershipId]);
                    
                    notify_user((int) $mInfo['user_id'], 'system', 'Payment Received', 'Your payment for the ' . $mInfo['plan_name'] . ' membership was successful and your plan is now active!');
                    
                    Emails::sendPaymentConfirmation(
                        $mInfo['email'],
                        $mInfo['first_name'] . ' ' . $mInfo['last_name'],
                        $mInfo['plan_name']
                    );
                }
            }
            
            audit_log($user['user_id'], 'update_status', 'membership', (string) $membershipId, json_encode(['new_status' => $status]));
            flash('Membership status updated.');
            redirect('memberships');
        }

        $start = new DateTime((string) post('start_date'));
        $duration = (int) scalar('SELECT duration_days FROM membership_plans WHERE plan_id = ?', [post('plan_id')]);
        $end = (clone $start)->modify('+' . $duration . ' days')->format('Y-m-d');
        $memberUserId = (int) post('user_id');
        $planId = (int) post('plan_id');
        $status = post('status');
        
        $plan = db()->query('SELECT plan_name, price FROM membership_plans WHERE plan_id = ' . $planId)->fetch();
        $finalPrice = (float) $plan['price'];
        
        // Handle logic for renewals and same-day upgrades
        $currentActive = db()->query("SELECT m.*, p.price as old_price FROM memberships m JOIN membership_plans p ON p.plan_id = m.plan_id WHERE m.user_id = $memberUserId AND m.status = 'active' ORDER BY m.end_date DESC LIMIT 1")->fetch();
        if ($currentActive) {
            if ((int)$currentActive['plan_id'] === $planId) {
                // Renewal: Queue it
                $start = new DateTime($currentActive['end_date']);
                $end = (clone $start)->modify('+' . $duration . ' days')->format('Y-m-d');
                $status = 'pending';
            } else {
                // Upgrade/Downgrade: Cancel old plan immediately if new one is active
                if ($status === 'active') {
                    db()->prepare("UPDATE memberships SET status = 'cancelled' WHERE membership_id = ?")->execute([$currentActive['membership_id']]);
                }
                
                // Check if they bought the previous plan today (same-day upgrade pricing)
                $oldPlanCreatedAt = date('Y-m-d', strtotime($currentActive['created_at']));
                $todayDate = date('Y-m-d');
                if ($oldPlanCreatedAt === $todayDate) {
                    $finalPrice = max(0, $finalPrice - (float)$currentActive['old_price']);
                }
            }
        }
        
        db()->prepare('INSERT INTO memberships (user_id, plan_id, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)')->execute([$memberUserId, $planId, $start->format('Y-m-d'), $end, $status]);
        $membershipId = db()->lastInsertId();
        
        $receipt = 'RCPT-' . date('Ymd') . '-' . random_int(1000, 9999);
        $paymentStatus = $status === 'active' ? 'paid' : 'pending';
        db()->prepare('INSERT INTO payments (membership_id, amount, payment_date, payment_method, status, receipt_number) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$membershipId, $finalPrice, $start->format('Y-m-d'), 'cash', $paymentStatus, $receipt]);
        $paymentId = (int) db()->lastInsertId();

        notify_user(
            $memberUserId,
            'system',
            'Membership updated',
            'Your ' . $plan['plan_name'] . ' membership is ' . post('status') . ' from ' . date('M j, Y', strtotime((string) post('start_date'))) . ' to ' . date('M j, Y', strtotime($end)) . '.'
        );
        
        if ($paymentStatus === 'paid') {
            process_trainer_commission($paymentId, (float) $finalPrice);
            notify_user(
                $memberUserId,
                'system',
                'Payment recorded',
                'PHP ' . number_format((float)$plan['price'], 2) . ' received for ' . $plan['plan_name'] . '. Receipt: ' . $receipt . '.'
            );
        }
        audit_log($user['user_id'], 'create', 'membership', (string) $membershipId, json_encode(['user_id' => $memberUserId, 'plan_id' => $planId, 'status' => $status, 'amount' => $finalPrice]));
        flash('Membership created.');
        redirect('memberships');
    }
    
    if ($user['role'] === 'member' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subscribe_plan_id'])) {
        $planId = (int) post('subscribe_plan_id');
        $paymentMethod = post('payment_method') === 'gcash' ? 'gcash' : 'cash';
        
        $stmt = db()->prepare('SELECT * FROM membership_plans WHERE plan_id = ?');
        $stmt->execute([$planId]);
        $plan = $stmt->fetch();
        if ($plan) {
            $start = new DateTime();
            $end = (clone $start)->modify('+' . $plan['duration_days'] . ' days')->format('Y-m-d');
            $finalPrice = (float) $plan['price'];
            
            $currentActive = db()->query("SELECT m.*, p.price as old_price FROM memberships m JOIN membership_plans p ON p.plan_id = m.plan_id WHERE m.user_id = {$user['user_id']} AND m.status = 'active' ORDER BY m.end_date DESC LIMIT 1")->fetch();
            
            if ($currentActive) {
                if ((int)$currentActive['plan_id'] === $planId) {
                    // Renewal: Queue it
                    $start = new DateTime($currentActive['end_date']);
                    $end = (clone $start)->modify('+' . $plan['duration_days'] . ' days')->format('Y-m-d');
                } else {
                    // Check if they bought the previous plan today (same-day upgrade pricing)
                    $oldPlanCreatedAt = date('Y-m-d', strtotime($currentActive['created_at']));
                    $todayDate = date('Y-m-d');
                    
                    if ($oldPlanCreatedAt === $todayDate) {
                        $finalPrice = max(0, $finalPrice - (float)$currentActive['old_price']);
                    }
                }
            }
            
            // GCash Placeholder Intercept
            if ($paymentMethod === 'gcash' && !isset($_POST['gcash_simulated'])) {
                ?>
                <!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>GCash Payment Simulation</title>
                    <style>
                        body { font-family: 'Inter', sans-serif; background-color: #0f1115; color: #fff; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
                        .payment-container { max-width: 400px; width: 100%; background: #16181d; border: 1px solid #ccff00; border-radius: 12px; padding: 30px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
                        .summary { background: rgba(255,255,255,0.05); padding: 15px; border-radius: 8px; margin-bottom: 25px; text-align: left; }
                        .btn-primary { width: 100%; padding: 12px; font-size: 1.1rem; background: #007DFE; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
                        .btn-primary:hover { background: #0066d6; }
                        a { color: #8892b0; text-decoration: underline; font-size: 0.9rem; }
                    </style>
                </head>
                <body>
                    <div class="payment-container">
                        <img src="https://getpaymongo.com/assets/images/paymongo-logo.svg" alt="PayMongo" style="height: 30px; margin-bottom: 20px; filter: brightness(0) invert(1);">
                        <h2 style="color: #ccff00; margin-top: 0;">GCash Payment</h2>
                        <p style="color: #8892b0; margin-bottom: 30px;">This is a simulated PayMongo checkout for demonstration purposes.</p>
                        
                        <div class="summary">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                <span style="color: #8892b0;">Plan:</span>
                                <span style="font-weight: bold;"><?= h($plan['plan_name']) ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: #8892b0;">Total Amount:</span>
                                <span style="color: #ccff00; font-weight: bold; font-size: 1.2rem;"><?= h(money($finalPrice)) ?></span>
                            </div>
                        </div>
                        
                        <form method="post" action="index.php?page=memberships">
                            <?= csrf_field() ?>
                            <input type="hidden" name="subscribe_plan_id" value="<?= $planId ?>">
                            <input type="hidden" name="payment_method" value="gcash">
                            <input type="hidden" name="gcash_simulated" value="1">
                            <button type="submit" class="btn-primary">Simulate Successful Payment</button>
                        </form>
                        <div style="margin-top: 15px;">
                            <a href="index.php?page=gym_selection">Cancel</a>
                        </div>
                    </div>
                </body>
                </html>
                <?php
                exit;
            }

            $paymentStatus = ($paymentMethod === 'gcash') ? 'paid' : 'pending';
            $membershipStatus = ($paymentMethod === 'gcash') ? 'active' : 'pending';
            
            db()->prepare('INSERT INTO memberships (user_id, plan_id, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)')
                ->execute([$user['user_id'], $planId, $start->format('Y-m-d'), $end, $membershipStatus]);
            $membershipId = (int) db()->lastInsertId();
            
            if (!empty($plan['gym_id'])) {
                db()->prepare('INSERT IGNORE INTO gym_members (user_id, gym_id) VALUES (?, ?)')
                    ->execute([$user['user_id'], $plan['gym_id']]);
            }
            
            $receipt = 'REQ-' . date('Ymd') . '-' . random_int(1000, 9999);
            if ($paymentStatus === 'paid') {
                $receipt = 'GCASH-' . date('Ymd') . '-' . random_int(100000, 999999);
            }

            db()->prepare('INSERT INTO payments (membership_id, amount, payment_date, payment_method, status, receipt_number) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$membershipId, $finalPrice, $start->format('Y-m-d'), $paymentMethod, $paymentStatus, $receipt]);
            $paymentId = (int) db()->lastInsertId();

            if ($paymentStatus === 'paid') {
                process_trainer_commission($paymentId, (float) $finalPrice);
            }
                
            $gymOwners = query_all('SELECT owner_user_id FROM gyms');
            foreach ($gymOwners as $owner) {
                notify_user((int) $owner['owner_user_id'], 'system', 'New Subscription', $user['first_name'] . ' ' . $user['last_name'] . ' requested a ' . $plan['plan_name'] . ' membership. Payment method: ' . strtoupper($paymentMethod) . '. Status: ' . strtoupper($paymentStatus) . '.');
            }
            
            if ($paymentMethod === 'gcash') {
                flash('GCash Payment Successful! You are now subscribed.', 'success');
            } else {
                flash('Subscription requested. Please proceed with payment at the front desk.');
            }
            
            // Auto redirect to dashboard if paid successfully
            if ($paymentMethod === 'gcash') {
                redirect('dashboard');
            } else {
                redirect('memberships');
            }
        }
    }

    $gymId = null;
    if ($user['role'] === 'gym_owner') {
        $gymId = (int) scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
        $where = 'WHERE m.plan_id IN (SELECT plan_id FROM membership_plans WHERE gym_id = ' . $gymId . ')';
        $plans = db()->query('SELECT * FROM membership_plans WHERE is_active = 1 AND gym_id = ' . $gymId . ' ORDER BY price')->fetchAll();
    } elseif ($user['role'] === 'member') {
        $where = 'WHERE m.user_id = ' . (int) $user['user_id'];
        $plans = db()->query('SELECT mp.*, g.name AS gym_name FROM membership_plans mp LEFT JOIN gyms g ON g.gym_id = mp.gym_id WHERE mp.is_active = 1 ORDER BY mp.price')->fetchAll();
    } else {
        $where = 'WHERE 1=0'; // Platform admin doesn't use this page
        $plans = [];
    }
    $members = db()->query('SELECT user_id, CONCAT(first_name, " ", last_name) AS name FROM users WHERE role = "member" AND status = "active" ORDER BY first_name')->fetchAll();
    $rows    = db()->query('SELECT m.*, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name, u.profile_picture, p.plan_name, p.price FROM memberships m JOIN users u ON u.user_id = m.user_id JOIN membership_plans p ON p.plan_id = m.plan_id ' . $where . ' ORDER BY m.created_at DESC')->fetchAll();
    render_header('Memberships', $user);
    ?>
    <div class="skeleton-wrapper">
        <section class="panel">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
                <div>
                    <div class="sk sk-title" style="width:180px;margin-bottom:8px"></div>
                    <div class="sk sk-text" style="width:280px;height:12px"></div>
                </div>
                <?php if ($user['role'] === 'gym_owner'): ?>
                    <div class="sk sk-rect" style="width:140px;height:36px;border-radius:18px"></div>
                <?php endif; ?>
            </div>
            
            <?php if ($user['role'] === 'member'): ?>
                <div class="sk sk-title" style="width:160px;margin-bottom:12px"></div>
                <div style="display:flex;gap:24px;margin-bottom:40px;overflow:hidden">
                    <?php for($i=0;$i<3;$i++): ?>
                        <div class="sk-card" style="width:280px;flex-shrink:0;min-height:220px">
                            <div class="sk sk-title" style="width:70%;margin-bottom:6px"></div>
                            <div class="sk sk-text short" style="height:12px;margin-bottom:16px"></div>
                            <div class="sk sk-text" style="width:50%;height:24px;margin-bottom:24px"></div>
                            <div class="sk sk-text full"></div>
                            <div class="sk sk-rect" style="height:38px;border-radius:6px;margin-top:20px"></div>
                        </div>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
            
            <div class="sk sk-title" style="width:180px;margin-bottom:12px"></div>
            <?php render_skeleton_table(6, 6); ?>
        </section>
    </div>
    <section class="panel skeleton-content sk-display-block">
        <div class="page-header">
            <div>
                <h1>Memberships</h1>
                <p>Manage member subscription plans and their validity periods.</p>
            </div>
            <?php if ($user['role'] === 'gym_owner'): ?>
                <button onclick="addMembership()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold;">+ New Membership</button>
            <?php endif; ?>
        </div>


        
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

        <?php if ($user['role'] === 'member'): 
            $activePlanId = null;
            $sameDayDiscount = 0;
            
            // Look for an active plan to check if they get a same-day upgrade discount
            $currentActive = db()->query("SELECT m.*, p.price FROM memberships m JOIN membership_plans p ON p.plan_id = m.plan_id WHERE m.user_id = {$user['user_id']} AND m.status = 'active' ORDER BY m.end_date DESC LIMIT 1")->fetch();
            if ($currentActive) {
                $activePlanId = (int)$currentActive['plan_id'];
                if (date('Y-m-d', strtotime($currentActive['created_at'])) === date('Y-m-d')) {
                    $sameDayDiscount = (float)$currentActive['price'];
                }
            }
        ?>
            <h2 style="margin-bottom: 12px;">Available Plans</h2>
            <div style="display: flex; flex-wrap: wrap; gap: 1.5rem; margin-bottom: 2.5rem;">
                <?php foreach ($plans as $plan): 
                    $isActive = $activePlanId === (int)$plan['plan_id'];
                    $displayPrice = (float)$plan['price'];
                    
                    if (!$isActive && $sameDayDiscount > 0) {
                        $displayPrice = max(0, $displayPrice - $sameDayDiscount);
                    }
                ?>
                    <div class="panel plan-card-glow" style="width: 280px; flex-shrink: 0; padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem; background: var(--surface); <?= $isActive ? 'border: 2px solid var(--lime); box-shadow: 0 0 15px rgba(204,255,0,0.1);' : '' ?>">
                        <div>
                            <h3 style="margin: 0; font-size: 1.2rem; display: flex; align-items: center; gap: 8px;">
                                <?= h($plan['plan_name']) ?>
                            </h3>
                            <p style="color: var(--muted); font-size: 0.9rem; margin-top: 4px;">
                                <?= h($plan['duration_days']) ?> Days
                                &bull; Valid only at <?= h($plan['gym_name'] ?: 'this gym') ?>
                            </p>
                        </div>
                        <div style="font-size: 1.5rem; font-weight: bold; color: var(--lime);">
                            <?php if (!$isActive && $sameDayDiscount > 0): ?>
                                <span style="text-decoration: line-through; font-size: 1rem; color: var(--muted); margin-right: 8px;"><?= h(money($plan['price'])) ?></span>
                            <?php endif; ?>
                            <?= h(money($displayPrice)) ?>
                        </div>
                        <?php if (!empty($plan['description'])): ?>
                            <p style="font-size: 0.9rem; color: var(--muted); flex: 1;"><?= nl2br(h($plan['description'])) ?></p>
                        <?php else: ?>
                            <div style="flex: 1;"></div>
                        <?php endif; ?>
                        
                        <?php if ($isActive): ?>
                            <button class="btn" style="background: transparent; color: var(--lime); font-weight: bold; width: 100%; border: 1px solid var(--lime); cursor: default; padding: 10px;" disabled>Active Plan</button>
                        <?php else: ?>
                            <button class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold; width: 100%; border: none; cursor: pointer; padding: 10px;" onclick="subscribePlan(<?= (int)$plan['plan_id'] ?>, '<?= htmlspecialchars($plan['plan_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars(money($displayPrice), ENT_QUOTES) ?>')">Subscribe</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <script>
            function subscribePlan(planId, planName, planPrice) {
                Swal.fire({
                    title: 'Subscribe to ' + planName,
                    html: `
                        <p style="margin-bottom: 15px;">You are about to subscribe to the <strong>${planName}</strong> plan for <strong>${planPrice}</strong>.</p>
                        <form id="subscribeForm" method="post" action="index.php?page=memberships" style="text-align: left; display: flex; flex-direction: column; gap: 12px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="subscribe_plan_id" value="${planId}">
                            
                            <label style="display:block; color: var(--muted); font-size: 14px;">Payment Method *
                                <select name="payment_method" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                                    <option value="cash" selected>Cash</option>
                                    <option value="gcash">GCash</option>
                                </select>
                            </label>
                            
                            <p style="font-size: 13px; color: var(--muted); margin-top: 8px;">
                                Your subscription will be created as <strong>Pending</strong>. You can finalize the payment at the front desk or via GCash.
                            </p>
                        </form>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Confirm Subscription',
                    confirmButtonColor: 'var(--lime-dark)',
                    cancelButtonColor: 'var(--line)',
                    background: 'var(--bg)',
                    color: 'var(--ink)',
                    preConfirm: () => {
                        document.getElementById('subscribeForm').submit();
                    }
                });
            }
            </script>
        <?php endif; ?>

        <h2 style="margin-bottom: 12px;">Membership records</h2>
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
                        <?php if ($user['role'] === 'gym_owner'): ?><th>Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row):
                    $statusClass = 'badge badge-' . $row['status'];
                ?>
                    <tr>
                        <?php if ($user['role'] !== 'member'): ?>
                        <td>
                            <div class="user-cell">
                                <?= render_avatar($row) ?>
                                <span><?= h($row['member']) ?></span>
                            </div>
                        </td>
                        <?php endif; ?>
                        <td><strong><?= h($row['plan_name']) ?></strong></td>
                        <td><?= h(money($row['price'])) ?></td>
                        <td><?= h(date('M j, Y', strtotime($row['start_date']))) ?></td>
                        <td><?= h(date('M j, Y', strtotime($row['end_date']))) ?></td>
                        <td><span class="<?= $statusClass ?>"><?= h($row['status']) ?></span></td>
                        <?php if ($user['role'] === 'gym_owner'): ?>
                        <td>
                            <button onclick="editStatus(<?= $row['membership_id'] ?>, '<?= h($row['status']) ?>')" class="btn btn-secondary" style="padding:4px 8px;font-size:12px;" title="Edit Status">Update</button>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
    
    <?php if ($user['role'] === 'gym_owner'): ?>
    <script>
        function editStatus(membershipId, currentStatus) {
            Swal.fire({
                title: 'Update Status',
                html: `
                    <form id="editStatusForm" method="post" style="text-align:left;display:flex;flex-direction:column;gap:12px;margin-top:15px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="update_status_id" value="${membershipId}">
                        <label style="display:block;color:var(--muted);font-size:14px;">Status
                            <select name="status" class="form-control" style="width:100%;box-sizing:border-box;">
                                <option value="pending" ${currentStatus === 'pending' ? 'selected' : ''}>Pending</option>
                                <option value="active" ${currentStatus === 'active' ? 'selected' : ''}>Active</option>
                                <option value="expired" ${currentStatus === 'expired' ? 'selected' : ''}>Expired</option>
                                <option value="cancelled" ${currentStatus === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                            </select>
                        </label>
                    </form>
                `,
                showCancelButton: true,
                confirmButtonText: 'Save',
                confirmButtonColor: 'var(--lime)',
                cancelButtonColor: '#334155',
                background: '#0f172a',
                color: 'var(--ink)',
                preConfirm: () => {
                    Swal.showLoading();
                    document.getElementById('editStatusForm').submit();
                }
            });
        }
    </script>
    <?php endif; ?>

    
    <?php if ($user['role'] === 'gym_owner'): ?>
    <script>
    function addMembership() {
        Swal.fire({
            title: 'Create Membership',
            html: `
                <form id="addMembershipForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <?= csrf_field() ?>
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Member *
                        <select name="user_id" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                            <option value="">Select Member...</option>
                            <?php foreach ($members as $member): ?>
                                <option value="<?= (int) $member['user_id'] ?>"><?= h($member['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Plan *
                        <select name="plan_id" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                            <option value="">Select Plan...</option>
                            <?php foreach ($plans as $plan): ?>
                                <option value="<?= (int) $plan['plan_id'] ?>"><?= h($plan['plan_name']) ?> — <?= h(money($plan['price'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Start date *
                            <input name="start_date" type="date" class="form-control" value="<?= h(date('Y-m-d')) ?>" style="width: 100%; box-sizing: border-box;" required>
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Status *
                            <select name="status" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                                <option value="active">Active</option>
                                <option value="pending">Pending</option>
                                <option value="expired">Expired</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </label>
                    </div>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Create',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('addMembershipForm');
                if (!form.user_id.value || !form.plan_id.value || !form.start_date.value) {
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
