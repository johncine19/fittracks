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

    if ($user['role'] === 'gym_owner' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['update_status_id'])) {
            $membershipId = (int) post('update_status_id');
            $status = post('status');
            db()->prepare('UPDATE memberships SET status = ? WHERE membership_id = ?')->execute([$status, $membershipId]);

            // Also update payment status if it exists and status is active
            if ($status === 'active') {
                $pendingPayRows = db()->query('SELECT payment_id, amount FROM payments WHERE membership_id = ' . $membershipId . ' AND status = "pending"')->fetchAll();
                db()->prepare('UPDATE payments SET status = "paid" WHERE membership_id = ? AND status = "pending"')->execute([$membershipId]);
                foreach ($pendingPayRows as $ppRow) {
                    process_trainer_commission((int)$ppRow['payment_id'], (float)$ppRow['amount']);
                }

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
        } elseif (post('action') === 'confirm_payment') {
            $paymentId = (int) post('payment_id');
            $paymentMethod = post('payment_method') ?: 'cash';
            $receiptNumber = trim((string) post('receipt_number'));
            $paymentDate = post('payment_date') ?: date('Y-m-d');

            $gym = db()->query('SELECT gym_id FROM gyms WHERE owner_user_id = ' . (int) $user['user_id'])->fetch();
            $gymId = $gym ? (int) $gym['gym_id'] : 0;

            $payment = db()->query("
                SELECT pay.*, m.user_id, m.plan_id, m.membership_id, p.gym_id, p.plan_name, p.duration_days, p.price, u.email, u.first_name, u.last_name
                FROM payments pay
                JOIN memberships m ON m.membership_id = pay.membership_id
                JOIN membership_plans p ON p.plan_id = m.plan_id
                JOIN users u ON u.user_id = m.user_id
                WHERE pay.payment_id = $paymentId AND p.gym_id = $gymId
            ")->fetch();

            if (!$payment) {
                flash('Payment record not found or access denied.', 'danger');
                redirect('memberships&view=payments');
            }

            $finalReceipt = $receiptNumber ?: ($payment['receipt_number'] ?: ('RCPT-' . date('Ymd') . '-' . random_int(1000, 9999)));
            $amount = (float) $payment['amount'];
            $membershipId = (int) $payment['membership_id'];
            $memberUserId = (int) $payment['user_id'];
            $duration = (int) ($payment['duration_days'] ?? 30);

            // 1. Update payment to paid
            db()->prepare('UPDATE payments SET status = "paid", payment_method = ?, payment_date = ?, receipt_number = ?, processed_by = ? WHERE payment_id = ?')
                ->execute([$paymentMethod, $paymentDate, $finalReceipt, $user['user_id'], $paymentId]);

            // Clear any duplicate pending payments for this membership
            db()->prepare('UPDATE payments SET status = "paid" WHERE membership_id = ? AND status = "pending"')->execute([$membershipId]);

            // 2. Activate membership starting from payment date
            $start = new DateTime($paymentDate);
            $end = (clone $start)->modify('+' . $duration . ' days')->format('Y-m-d');

            // Cancel other active memberships for this member
            db()->prepare("UPDATE memberships SET status = 'cancelled' WHERE user_id = ? AND membership_id != ? AND status = 'active'")->execute([$memberUserId, $membershipId]);

            db()->prepare('UPDATE memberships SET status = "active", start_date = ?, end_date = ? WHERE membership_id = ?')
                ->execute([$start->format('Y-m-d'), $end, $membershipId]);

            if ($gymId) {
                db()->prepare('INSERT IGNORE INTO gym_members (gym_id, user_id) VALUES (?, ?)')->execute([$gymId, $memberUserId]);
            }

            // 3. Process trainer commission
            process_trainer_commission($paymentId, $amount);

            // 4. Notifications & Email
            notify_user(
                $memberUserId,
                'system',
                'Payment Confirmed',
                money($amount) . ' received for ' . $payment['plan_name'] . '. Your membership is now active! Receipt: ' . $finalReceipt . '.'
            );

            if (class_exists('Emails')) {
                try {
                    Emails::sendPaymentConfirmation(
                        $payment['email'],
                        $payment['first_name'] . ' ' . $payment['last_name'],
                        $payment['plan_name']
                    );
                } catch (\Throwable $e) {}
            }

            audit_log($user['user_id'], 'update', 'payment', (string) $paymentId, json_encode(['action' => 'confirm_payment', 'status' => 'paid', 'receipt' => $finalReceipt]));
            flash('Payment confirmed and membership activated successfully!', 'success');
            redirect('memberships&view=payments');
        } elseif (post('action') === 'record_payment') {
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

            // If no pre-existing membership_id, but plan_id and user_id are supplied:
            // First check if the member already has a pending membership for this plan!
            if (!$membershipId && $planId && $memberUserId) {
                $existingPending = db()->query("SELECT membership_id FROM memberships WHERE user_id = $memberUserId AND plan_id = $planId AND status = 'pending' ORDER BY membership_id DESC LIMIT 1")->fetch();
                if ($existingPending) {
                    $membershipId = (int) $existingPending['membership_id'];
                }
            }

            // If still no membership_id, create the membership!
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
                redirect('memberships&view=payments');
            }

            // Check if there is an existing pending payment for this membership
            $existingPendingPayment = db()->query("SELECT payment_id, receipt_number FROM payments WHERE membership_id = $membershipId AND status = 'pending' ORDER BY payment_id DESC LIMIT 1")->fetch();

            if ($existingPendingPayment) {
                $paymentId = (int) $existingPendingPayment['payment_id'];
                $finalReceipt = post('receipt_number') ? $receipt : ($existingPendingPayment['receipt_number'] ?: $receipt);
                db()->prepare('UPDATE payments SET amount = ?, payment_date = ?, payment_method = ?, status = ?, receipt_number = ?, processed_by = ? WHERE payment_id = ?')
                    ->execute([$amount, $paymentDate, $paymentMethod, $status, $finalReceipt, $user['user_id'], $paymentId]);
            } else {
                db()->prepare('INSERT INTO payments (membership_id, amount, payment_date, payment_method, status, receipt_number, processed_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$membershipId, $amount, $paymentDate, $paymentMethod, $status, $receipt, $user['user_id']]);
                $paymentId = (int) db()->lastInsertId();
            }

            // If the payment is marked as paid, automatically activate the membership and set proper valid dates
            if ($status === 'paid') {
                $mRow = db()->query("SELECT m.*, p.duration_days FROM memberships m JOIN membership_plans p ON p.plan_id = m.plan_id WHERE m.membership_id = $membershipId")->fetch();
                if ($mRow && $mRow['status'] === 'pending') {
                    $dur = (int)($mRow['duration_days'] ?? 30);
                    $mStart = new DateTime($paymentDate);
                    $mEnd = (clone $mStart)->modify('+' . $dur . ' days')->format('Y-m-d');

                    db()->prepare("UPDATE memberships SET status = 'cancelled' WHERE user_id = ? AND membership_id != ? AND status = 'active'")->execute([$mRow['user_id'], $membershipId]);

                    db()->prepare('UPDATE memberships SET status = "active", start_date = ?, end_date = ? WHERE membership_id = ?')
                        ->execute([$mStart->format('Y-m-d'), $mEnd, $membershipId]);
                } else {
                    db()->prepare('UPDATE memberships SET status = "active" WHERE membership_id = ? AND status = "pending"')->execute([$membershipId]);
                }

                // Clear any other duplicate pending payments for this membership
                db()->prepare('UPDATE payments SET status = "paid" WHERE membership_id = ? AND status = "pending"')->execute([$membershipId]);

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

        // Check if there is already a pending membership for this member and plan to update
        $existingPending = db()->query("SELECT membership_id FROM memberships WHERE user_id = $memberUserId AND plan_id = $planId AND status = 'pending' ORDER BY membership_id DESC LIMIT 1")->fetch();
        if ($existingPending) {
            $membershipId = (int) $existingPending['membership_id'];
            db()->prepare('UPDATE memberships SET start_date = ?, end_date = ?, status = ? WHERE membership_id = ?')
                ->execute([$start->format('Y-m-d'), $end, $status, $membershipId]);
        } else {
            db()->prepare('INSERT INTO memberships (user_id, plan_id, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)')->execute([$memberUserId, $planId, $start->format('Y-m-d'), $end, $status]);
            $membershipId = (int) db()->lastInsertId();
        }

        $receipt = 'RCPT-' . date('Ymd') . '-' . random_int(1000, 9999);
        $paymentStatus = $status === 'active' ? 'paid' : 'pending';

        $pendingPay = db()->query("SELECT payment_id FROM payments WHERE membership_id = $membershipId AND status = 'pending' ORDER BY payment_id DESC LIMIT 1")->fetch();
        if ($pendingPay) {
            $paymentId = (int) $pendingPay['payment_id'];
            db()->prepare('UPDATE payments SET amount = ?, payment_date = ?, payment_method = "cash", status = ?, processed_by = ? WHERE payment_id = ?')
                ->execute([$finalPrice, $start->format('Y-m-d'), $paymentStatus, $user['user_id'], $paymentId]);
        } else {
            db()->prepare('INSERT INTO payments (membership_id, amount, payment_date, payment_method, status, receipt_number, processed_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$membershipId, $finalPrice, $start->format('Y-m-d'), 'cash', $paymentStatus, $receipt, $user['user_id']]);
            $paymentId = (int) db()->lastInsertId();
        }

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
                        body {
                            font-family: 'Inter', sans-serif;
                            background-color: #0f1115;
                            color: #fff;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            height: 100vh;
                            margin: 0;
                        }

                        .payment-container {
                            max-width: 400px;
                            width: 100%;
                            background: #16181d;
                            border: 1px solid #ccff00;
                            border-radius: 12px;
                            padding: 30px;
                            text-align: center;
                            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
                        }

                        .summary {
                            background: rgba(255, 255, 255, 0.05);
                            padding: 15px;
                            border-radius: 8px;
                            margin-bottom: 25px;
                            text-align: left;
                        }

                        .btn-primary {
                            width: 100%;
                            padding: 12px;
                            font-size: 1.1rem;
                            background: #007DFE;
                            color: white;
                            border: none;
                            border-radius: 6px;
                            cursor: pointer;
                            font-weight: bold;
                        }

                        .btn-primary:hover {
                            background: #0066d6;
                        }

                        a {
                            color: #8892b0;
                            text-decoration: underline;
                            font-size: 0.9rem;
                        }
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
    $gymName = '';
    if ($user['role'] === 'gym_owner') {
        $gymId = (int) scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
        $gymName = (string) scalar('SELECT name FROM gyms WHERE gym_id = ?', [$gymId]);
        $where = 'WHERE m.plan_id IN (SELECT plan_id FROM membership_plans WHERE gym_id = ' . $gymId . ')';
        $plans = db()->query('SELECT * FROM membership_plans WHERE is_active = 1 AND gym_id = ' . $gymId . ' ORDER BY price')->fetchAll();
    } elseif ($user['role'] === 'member') {
        $where = 'WHERE m.user_id = ' . (int) $user['user_id'];
        $memberGymId = (int) scalar('SELECT gym_id FROM gym_members WHERE user_id = ? LIMIT 1', [$user['user_id']]);
        if ($memberGymId > 0) {
            $gymName = (string) scalar('SELECT name FROM gyms WHERE gym_id = ?', [$memberGymId]);
            $plans = db()->query('SELECT mp.*, g.name AS gym_name FROM membership_plans mp LEFT JOIN gyms g ON g.gym_id = mp.gym_id WHERE mp.is_active = 1 AND mp.gym_id = ' . $memberGymId . ' ORDER BY mp.price')->fetchAll();
        } else {
            $plans = [];
        }
    } else {
        $where = 'WHERE 1=0'; // Platform admin doesn't use this page
        $plans = [];
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
    }, $members);
    $plansData = array_map(function ($p) {
        return [
            'id' => (int) $p['plan_id'],
            'name' => (string) $p['plan_name'],
            'price' => (float) $p['price'],
            'formatted_price' => function_exists('money') ? money($p['price']) : ('₱' . number_format((float) $p['price'], 2)),
            'duration' => (int) ($p['duration_days'] ?? 30)
        ];
    }, $plans ?: []);
    $rows    = db()->query('SELECT m.*, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name, u.profile_picture, p.plan_name, p.price FROM memberships m JOIN users u ON u.user_id = m.user_id JOIN membership_plans p ON p.plan_id = m.plan_id ' . $where . ' ORDER BY m.created_at DESC')->fetchAll();

    $adminView = $_GET['view'] ?? 'memberships';
    if (!in_array($adminView, ['memberships', 'payments'], true)) {
        $adminView = 'memberships';
    }

    $paymentRows = [];
    $totalCollected = 0.0;
    $totalPending = 0;
    if ($user['role'] === 'gym_owner' && $gymId) {
        $paymentRows = db()->query('
            SELECT pay.*, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name, u.profile_picture, p.plan_name 
            FROM payments pay 
            JOIN memberships m ON m.membership_id = pay.membership_id 
            JOIN users u ON u.user_id = m.user_id 
            JOIN membership_plans p ON p.plan_id = m.plan_id 
            WHERE p.gym_id = ' . (int)$gymId . ' 
            ORDER BY pay.created_at DESC
        ')->fetchAll();

        $totalCollected = (float) scalar('
            SELECT COALESCE(SUM(pay.amount), 0) 
            FROM payments pay 
            JOIN memberships m ON m.membership_id = pay.membership_id 
            JOIN membership_plans p ON p.plan_id = m.plan_id 
            WHERE p.gym_id = ' . (int)$gymId . ' AND pay.status = "paid"
        ');

        $totalPending = (int) scalar('
            SELECT COUNT(*) 
            FROM payments pay 
            JOIN memberships m ON m.membership_id = pay.membership_id 
            JOIN membership_plans p ON p.plan_id = m.plan_id 
            WHERE p.gym_id = ' . (int)$gymId . ' AND pay.status = "pending"
        ');
    }

    $membershipsData = array_map(function ($m) {
        $first = trim((string)($m['first_name'] ?? ''));
        $last = trim((string)($m['last_name'] ?? ''));
        $name = trim($first . ' ' . $last) ?: 'Member';
        $ini = (!empty($first) ? strtoupper(substr($first, 0, 1)) : '') . (!empty($last) ? strtoupper(substr($last, 0, 1)) : '');
        return [
            'id' => (int) $m['membership_id'],
            'user_id' => (int) $m['user_id'],
            'plan_id' => (int) ($m['plan_id'] ?? 0),
            'name' => $name,
            'plan_name' => (string) ($m['plan_name'] ?? ''),
            'status' => (string) ($m['status'] ?? 'active'),
            'price' => (float) ($m['price'] ?? 0),
            'formatted_price' => function_exists('money') ? money($m['price']) : ('₱' . number_format((float) $m['price'], 2)),
            'initials' => $ini ?: 'M',
            'label' => $name . ' — ' . ($m['plan_name'] ?? '') . ' (' . ($m['status'] ?? 'active') . ')',
            'end_date' => !empty($m['end_date']) ? date('M j, Y', strtotime($m['end_date'])) : ''
        ];
    }, $rows ?: []);

    $expiring = [];
    if ($user['role'] !== 'member') {
        $expiring = db()->query('SELECT m.end_date, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name FROM memberships m JOIN users u ON u.user_id = m.user_id WHERE m.status = "active" AND m.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY m.end_date ASC LIMIT 5')->fetchAll();
    }

    render_header($user['role'] === 'gym_owner' ? 'Memberships & Payments' : 'Memberships', $user);
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

            <?php if ($user['role'] === 'gym_owner'): ?>
                <div style="display:flex;gap:8px;max-width:440px;margin-bottom:24px">
                    <div class="sk sk-rect" style="flex:1;height:42px;border-radius:10px"></div>
                    <div class="sk sk-rect" style="flex:1;height:42px;border-radius:10px"></div>
                </div>
            <?php endif; ?>

            <?php if ($user['role'] === 'member'): ?>
                <div style="display:flex;gap:8px;max-width:440px;margin-bottom:28px">
                    <div class="sk sk-rect" style="flex:1;height:42px;border-radius:10px"></div>
                    <div class="sk sk-rect" style="flex:1;height:42px;border-radius:10px"></div>
                </div>
                <div style="text-align:center;margin-bottom:30px">
                    <div class="sk sk-rect" style="width:120px;height:32px;border-radius:16px;margin:0 auto 16px"></div>
                    <div class="sk sk-title" style="width:340px;height:32px;margin:0 auto 12px"></div>
                    <div class="sk sk-text" style="width:480px;height:14px;margin:0 auto"></div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:28px;margin-bottom:40px">
                    <?php for ($i = 0; $i < 3; $i++): ?>
                        <div class="sk-card" style="border-radius:24px;min-height:480px;padding:36px 28px;display:flex;flex-direction:column">
                            <div class="sk sk-title" style="width:60%;height:28px;margin-bottom:12px"></div>
                            <div class="sk sk-text short" style="height:12px;margin-bottom:24px"></div>
                            <div class="sk sk-text" style="width:70%;height:42px;margin-bottom:28px"></div>
                            <div class="sk sk-text full" style="margin-bottom:14px"></div>
                            <div class="sk sk-text full" style="margin-bottom:14px"></div>
                            <div class="sk sk-text full" style="margin-bottom:14px"></div>
                            <div class="sk sk-rect" style="height:48px;border-radius:24px;margin-top:auto"></div>
                        </div>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>

            <div class="sk sk-title" style="width:180px;margin-bottom:12px"></div>
            <?php render_skeleton_table(6, 6); ?>
        </section>
    </div>
    <section class="panel skeleton-content sk-display-block">
        <?php if ($user['role'] === 'gym_owner'): ?>
            <div class="page-header" style="align-items:flex-start; margin-bottom:20px;">
                <div>
                    <h1 id="admin-page-title"><?= $adminView === 'payments' ? 'Payment History' : 'Memberships' ?></h1>
                    <p id="admin-page-desc"><?= $adminView === 'payments' ? 'Record and track membership payment transactions.' : 'Manage member subscription plans and their validity periods.' ?></p>
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button type="button" onclick="recordPayment()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold; display:inline-flex; align-items:center; gap:6px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        <span>Record Payment</span>
                    </button>
                    <button type="button" onclick="addMembership()" class="btn btn-secondary" style="font-weight:600; display:inline-flex; align-items:center; gap:6px;">
                        <span>+ New Membership</span>
                    </button>
                </div>
            </div>

            <!-- Gym Owner Top View Switcher -->
            <div class="member-membership-nav admin-membership-nav" style="margin-bottom:24px; max-width:440px;">
                <button type="button" 
                    id="admin-tab-memberships" 
                    class="member-nav-tab <?= $adminView === 'memberships' ? 'active' : '' ?>" 
                    onclick="switchAdminView('memberships')">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                        <circle cx="9" cy="7" r="4" />
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                        <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                    </svg>
                    <span class="tab-label-full">Memberships</span>
                    <span class="tab-label-compact">Plans</span>
                    <span class="tab-badge-pill"><?= count($rows) ?></span>
                </button>
                <button type="button" 
                    id="admin-tab-payments" 
                    class="member-nav-tab <?= $adminView === 'payments' ? 'active' : '' ?>" 
                    onclick="switchAdminView('payments')">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2" />
                        <line x1="1" y1="10" x2="23" y2="10" />
                    </svg>
                    <span class="tab-label-full">Payment History</span>
                    <span class="tab-label-compact">Payments</span>
                    <span class="tab-badge-pill"><?= count($paymentRows) ?></span>
                </button>
            </div>
        <?php elseif ($user['role'] !== 'member'): ?>
            <div class="page-header">
                <div>
                    <h1>Memberships</h1>
                    <p>Manage member subscription plans and their validity periods.</p>
                </div>
            </div>
            <?php if ($expiring): ?>
                <div class="flash warning">
                    <strong>Upcoming Expirations (Next 7 Days):</strong>
                    <ul style="margin:5px 0 0 20px;">
                        <?php foreach ($expiring as $row): ?>
                            <li><?= h($row['member']) ?> - Expires on <?= h(date('M j, Y', strtotime($row['end_date']))) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($user['role'] === 'member'):
            $activePlanId = null;
            $sameDayDiscount = 0;

            // Look for an active plan to check if they get a same-day upgrade discount
            $currentActive = db()->query("SELECT m.*, p.price, p.plan_name FROM memberships m JOIN membership_plans p ON p.plan_id = m.plan_id WHERE m.user_id = {$user['user_id']} AND m.status = 'active' ORDER BY m.end_date DESC LIMIT 1")->fetch();
            if ($currentActive) {
                $activePlanId = (int)$currentActive['plan_id'];
                if (date('Y-m-d', strtotime($currentActive['created_at'])) === date('Y-m-d')) {
                    $sameDayDiscount = (float)$currentActive['price'];
                }
            }

            $planCount = count($plans);
            $popularIndex = ($planCount === 3) ? 1 : ($planCount > 1 ? 1 : 0);
        ?>
            <!-- Top View Switcher -->
            <nav class="member-membership-nav" aria-label="Membership Views">
                <button type="button" class="member-nav-tab active" id="tab-btn-plans" onclick="switchMembershipView('plans')" title="Subscription Plans">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                    </svg>
                    <span>
                        <span class="tab-label-full">Subscription Plans</span>
                        <span class="tab-label-compact">Plans</span>
                    </span>
                </button>
                <button type="button" class="member-nav-tab" id="tab-btn-records" onclick="switchMembershipView('records')" title="Membership Records">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                        <polyline points="10 9 9 9 8 9"></polyline>
                    </svg>
                    <span>
                        <span class="tab-label-full">Membership Records</span>
                        <span class="tab-label-compact">Records</span>
                    </span>
                    <?php if (!empty($rows)): ?>
                        <span class="tab-badge-pill"><?= count($rows) ?></span>
                    <?php endif; ?>
                </button>
            </nav>

            <!-- PANE 1: SUBSCRIPTION PLANS -->
            <div id="view-pane-plans" class="membership-view-pane active">
                <div class="membership-plans-hero-wrap">
                    <div class="membership-hero-header">
                        <div class="membership-brand-pill">
                            <div class="membership-brand-logo">FT</div>
                            <span class="membership-brand-title"><?= h($gymName ?: 'FitTrack') ?></span>
                        </div>
                        <h1 class="membership-hero-title">Choose Your Subscription Plan</h1>
                        <p class="membership-hero-subtitle">
                            Select the plan that fits your gym journey. You can upgrade, downgrade, or renew at any time.
                        </p>
                    </div>

                    <?php if (empty($plans)): ?>
                        <div style="text-align: center; padding: 48px 20px; background: rgba(128,128,128,0.05); border-radius: 20px; border: 1px dashed var(--line); max-width: 580px; margin: 0 auto;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 16px;">
                                <rect x="2" y="5" width="20" height="14" rx="2" />
                                <line x1="2" y1="10" x2="22" y2="10" />
                            </svg>
                            <h3 style="font-size: 1.25rem; font-weight: 700; margin: 0 0 8px; color: var(--ink);">No Membership Plans Available Yet</h3>
                            <p style="color: var(--muted); font-size: 14px; margin: 0; line-height: 1.5;">
                                <?= h($gymName ?: 'This gym') ?> has not published any membership subscription plans yet. Please check back later or inquire at the front desk.
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="member-pricing-grid">
                            <?php
                            $hasCustomPopular = false;
                            foreach ($plans as $p) {
                                if (!empty($p['is_popular'])) {
                                    $hasCustomPopular = true;
                                    break;
                                }
                            }
                            foreach ($plans as $index => $plan):
                                $isActive = ($activePlanId === (int)$plan['plan_id']);
                                $isPopular = $hasCustomPopular ? !empty($plan['is_popular']) : ($index === $popularIndex);
                                $displayPrice = (float)$plan['price'];

                                if (!$isActive && $sameDayDiscount > 0) {
                                    $displayPrice = max(0, $displayPrice - $sameDayDiscount);
                                }

                                $duration = (int)($plan['duration_days'] ?? 30);
                                if ($duration >= 350) {
                                    $period = '/yr';
                                } elseif ($duration >= 80) {
                                    $period = '/quarter';
                                } elseif ($duration >= 25 && $duration <= 35) {
                                    $period = '/mo';
                                } else {
                                    $period = '/' . $duration . 'd';
                                }

                                // Subtitle descriptions
                                $desc = trim((string)($plan['description'] ?? ''));
                                if (empty($desc) || str_contains($desc, "\n")) {
                                    if ($duration >= 350) {
                                        $desc = 'Best for dedicated members seeking maximum value & perks.';
                                    } elseif ($duration >= 80) {
                                        $desc = 'Best for consistent members and coaching add-ons.';
                                    } else {
                                        $desc = 'Best for flexible, month-to-month gym access.';
                                    }
                                }

                                $features = get_membership_plan_features($plan);
                                $formattedPrice = '₱' . number_format($displayPrice, (fmod($displayPrice, 1.0) == 0.0 ? 0 : 2));
                                $formattedOrigPrice = '₱' . number_format((float)$plan['price'], (fmod((float)$plan['price'], 1.0) == 0.0 ? 0 : 2));
                            ?>
                                <div class="member-pricing-card <?= $isPopular ? 'popular' : '' ?>">
                                    <?php if ($isPopular): ?>
                                        <div class="member-popular-badge">MOST POPULAR</div>
                                    <?php endif; ?>

                                    <h2 class="member-pricing-title <?= $isPopular ? 'popular-title' : '' ?>">
                                        <?= h($plan['plan_name']) ?>
                                    </h2>

                                    <p class="member-pricing-desc"><?= h($desc) ?></p>

                                    <div class="member-pricing-price">
                                        <?php if (!$isActive && $sameDayDiscount > 0): ?>
                                            <span class="discount-strike"><?= h($formattedOrigPrice) ?></span>
                                        <?php endif; ?>
                                        <span class="amount"><?= h($formattedPrice) ?></span>
                                        <span class="period"><?= h($period) ?></span>
                                    </div>

                                    <ul class="member-pricing-features">
                                        <?php foreach ($features as $feat): ?>
                                            <li>
                                                <span class="member-checkmark">✓</span>
                                                <span><?= h($feat) ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>

                                    <div style="margin-top: auto; padding-top: 28px;">
                                        <?php if ($isActive): ?>
                                            <button type="button"
                                                class="member-pricing-btn member-popular-btn btn-subscribe-plan"
                                                data-plan-id="<?= (int)$plan['plan_id'] ?>"
                                                data-plan-name="<?= h($plan['plan_name']) ?>"
                                                data-plan-price="<?= h($formattedPrice) ?>"
                                                data-is-current="1">
                                                Renew Plan
                                            </button>
                                        <?php else: ?>
                                            <button type="button"
                                                class="member-pricing-btn <?= $isPopular ? 'member-popular-btn' : 'member-standard-btn' ?> btn-subscribe-plan"
                                                data-plan-id="<?= (int)$plan['plan_id'] ?>"
                                                data-plan-name="<?= h($plan['plan_name']) ?>"
                                                data-plan-price="<?= h($formattedPrice) ?>"
                                                data-is-current="0">
                                                Get Started
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- PANE 2: MEMBERSHIP RECORDS -->
            <div id="view-pane-records" class="membership-view-pane" style="display: none;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
                    <div>
                        <h2 style="margin: 0 0 4px; font-size: 1.35rem; font-weight: 700;">Membership Records</h2>
                        <p style="margin: 0; color: var(--muted); font-size: 0.9rem;">Your active subscriptions, past history, and renewal terms.</p>
                    </div>
                </div>
                <?php if (!$rows): ?>
                    <div class="empty-state">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="5" width="20" height="14" rx="2" />
                            <line x1="2" y1="10" x2="22" y2="10" />
                        </svg>
                        <p>No memberships found.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap membership-desktop-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Plan</th>
                                    <th>Price</th>
                                    <th>Start</th>
                                    <th>End</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row):
                                    $statusClass = 'badge badge-' . $row['status'];
                                ?>
                                    <tr>
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

                    <!-- Mobile Cards View for Member Records -->
                    <div class="membership-mobile-cards">
                        <?php foreach ($rows as $row):
                            $statusClass = 'badge badge-' . $row['status'];
                        ?>
                            <div class="membership-card-item">
                                <div class="membership-card-header">
                                    <div>
                                        <div class="membership-card-title"><?= h($row['plan_name']) ?></div>
                                        <div class="membership-card-price"><?= h(money($row['price'])) ?></div>
                                    </div>
                                    <span class="<?= $statusClass ?>"><?= h($row['status']) ?></span>
                                </div>
                                <div class="membership-card-details">
                                    <div class="membership-card-detail-item">
                                        <span class="detail-label">Start Date</span>
                                        <span class="detail-val"><?= h(date('M j, Y', strtotime($row['start_date']))) ?></span>
                                    </div>
                                    <div class="membership-card-detail-item">
                                        <span class="detail-label">End Date</span>
                                        <span class="detail-val"><?= h(date('M j, Y', strtotime($row['end_date']))) ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <script>
                function switchMembershipView(viewName) {
                    const plansBtn = document.getElementById('tab-btn-plans');
                    const recordsBtn = document.getElementById('tab-btn-records');
                    const plansPane = document.getElementById('view-pane-plans');
                    const recordsPane = document.getElementById('view-pane-records');

                    if (viewName === 'records') {
                        if (plansBtn) plansBtn.classList.remove('active');
                        if (recordsBtn) recordsBtn.classList.add('active');
                        if (plansPane) plansPane.style.display = 'none';
                        if (recordsPane) recordsPane.style.display = 'block';
                        try {
                            history.replaceState(null, null, '#records');
                        } catch (e) {}
                    } else {
                        if (plansBtn) plansBtn.classList.add('active');
                        if (recordsBtn) recordsBtn.classList.remove('active');
                        if (plansPane) plansPane.style.display = 'block';
                        if (recordsPane) recordsPane.style.display = 'none';
                        try {
                            history.replaceState(null, null, '#plans');
                        } catch (e) {}
                    }
                }

                if (window.location.hash === '#records') {
                    switchMembershipView('records');
                }

                document.addEventListener('click', function(e) {
                    const btn = e.target.closest('.btn-subscribe-plan');
                    if (btn) {
                        subscribePlan(
                            parseInt(btn.getAttribute('data-plan-id'), 10),
                            btn.getAttribute('data-plan-name') || '',
                            btn.getAttribute('data-plan-price') || '',
                            btn.getAttribute('data-is-current') === '1'
                        );
                    }
                });

                function subscribePlan(planId, planName, planPrice, isCurrent) {
                    const actionWord = isCurrent ? 'Renew' : 'Subscribe to';
                    const btnWord = isCurrent ? 'Confirm & Renew' : 'Confirm Subscription';
                    const safeName = typeof escapeHtml === 'function' ? escapeHtml(planName) : planName;
                    const safePrice = typeof escapeHtml === 'function' ? escapeHtml(planPrice) : planPrice;

                    Swal.fire({
                        title: `<span style="font-size:1.35rem;font-weight:800;letter-spacing:-0.3px;">${actionWord} ${safeName}</span>`,
                        html: `
                        <p style="margin: 0 0 18px 0; font-size: 14px; color: var(--muted); line-height: 1.5;">
                            You are selecting the <strong style="color:var(--ink);">${safeName}</strong> plan for <strong style="color:var(--lime, #22c55e); font-size: 16px;">${safePrice}</strong>.
                        </p>
                        <form id="subscribeForm" method="post" action="index.php?page=memberships" style="text-align: left; display: flex; flex-direction: column; gap: 12px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="subscribe_plan_id" value="${planId}">
                            
                            <label style="display:block; font-size:11.5px; font-weight:700; letter-spacing:0.5px; color:var(--muted); text-transform:uppercase;">
                                Select Payment Method
                            </label>
                            
                            <div style="display:flex; flex-direction:column; gap:10px;">
                                <label style="display:flex; align-items:center; gap:12px; padding:12px 14px; border-radius:10px; border:1px solid var(--line); background:rgba(128,128,128,0.05); cursor:pointer;">
                                    <input type="radio" name="payment_method" value="gcash" checked style="accent-color:var(--lime, #22c55e); width:18px; height:18px;">
                                    <div style="flex:1;">
                                        <div style="font-weight:700; font-size:14px; color:var(--ink);">GCash</div>
                                        <div style="font-size:12px; color:var(--muted);">Instant activation via e-wallet</div>
                                    </div>
                                    <span style="font-size:11px; font-weight:700; background:rgba(34,197,94,0.15); color:var(--lime,#22c55e); padding:3px 8px; border-radius:6px;">Instant</span>
                                </label>
                                
                                <label style="display:flex; align-items:center; gap:12px; padding:12px 14px; border-radius:10px; border:1px solid var(--line); background:rgba(128,128,128,0.05); cursor:pointer;">
                                    <input type="radio" name="payment_method" value="cash" style="accent-color:var(--lime, #22c55e); width:18px; height:18px;">
                                    <div style="flex:1;">
                                        <div style="font-weight:700; font-size:14px; color:var(--ink);">Cash / Over-the-Counter</div>
                                        <div style="font-size:12px; color:var(--muted);">Pay at the gym reception desk</div>
                                    </div>
                                    <span style="font-size:11px; font-weight:700; background:rgba(148,163,184,0.15); color:var(--muted); padding:3px 8px; border-radius:6px;">Front Desk</span>
                                </label>
                            </div>
                            
                            <div style="font-size: 12.5px; color: var(--muted); margin-top: 4px; line-height: 1.4; padding: 10px 12px; background: rgba(128,128,128,0.06); border-radius: 8px;">
                                ${isCurrent 
                                    ? 'Your membership validity will be automatically extended from your current expiration date.' 
                                    : 'Your subscription will be recorded and activated upon payment confirmation.'}
                            </div>
                        </form>
                    `,
                        showCancelButton: true,
                        confirmButtonText: btnWord,
                        confirmButtonColor: 'var(--lime-dark, #22c55e)',
                        cancelButtonColor: 'var(--line)',
                        background: 'var(--bg)',
                        color: 'var(--ink)',
                        preConfirm: () => {
                            const form = document.getElementById('subscribeForm');
                            if (form) {
                                form.submit();
                            }
                        }
                    });
                }
            </script>
        <?php else: ?>
            <?php if ($user['role'] === 'gym_owner'): ?>
                <!-- PANE 1: MEMBERSHIPS -->
                <div id="admin-pane-memberships" class="membership-view-pane" style="display: <?= $adminView === 'memberships' ? 'block' : 'none' ?>;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <div>
                            <h2 style="margin: 0 0 4px; font-size: 1.3rem; font-weight: 700;">Membership Records</h2>
                            <p style="margin: 0; color: var(--muted); font-size: 0.88rem;">Current and past subscriptions assigned to your members.</p>
                        </div>
                    </div>

                    <?php if ($expiring): ?>
                        <div class="flash warning" style="margin-bottom: 16px;">
                            <strong>Upcoming Expirations (Next 7 Days):</strong>
                            <ul style="margin:5px 0 0 20px;">
                                <?php foreach ($expiring as $row): ?>
                                    <li><?= h($row['member']) ?> - Expires on <?= h(date('M j, Y', strtotime($row['end_date']))) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!$rows): ?>
                        <div class="empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="2" y="5" width="20" height="14" rx="2" />
                                <line x1="2" y1="10" x2="22" y2="10" />
                            </svg>
                            <p>No memberships found.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap membership-desktop-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Member</th>
                                        <th>Plan</th>
                                        <th>Price</th>
                                        <th>Start</th>
                                        <th>End</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $row):
                                        $statusClass = 'badge badge-' . $row['status'];
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="user-cell">
                                                    <?= render_avatar($row) ?>
                                                    <span><?= h($row['member']) ?></span>
                                                </div>
                                            </td>
                                            <td><strong><?= h($row['plan_name']) ?></strong></td>
                                            <td><?= h(money($row['price'])) ?></td>
                                            <td><?= h(date('M j, Y', strtotime($row['start_date']))) ?></td>
                                            <td><?= h(date('M j, Y', strtotime($row['end_date']))) ?></td>
                                            <td><span class="<?= $statusClass ?>"><?= h($row['status']) ?></span></td>
                                            <td>
                                                <button onclick="editStatus(<?= $row['membership_id'] ?>, '<?= h($row['status']) ?>')" class="btn btn-secondary" style="padding:4px 8px;font-size:12px;" title="Edit Status">Update</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mobile Cards View for Memberships -->
                        <div class="membership-mobile-cards">
                            <?php foreach ($rows as $row):
                                $statusClass = 'badge badge-' . $row['status'];
                            ?>
                                <div class="membership-card-item">
                                    <div class="membership-card-header">
                                        <div class="user-cell">
                                            <?= render_avatar($row) ?>
                                            <div>
                                                <div style="font-weight: 700; color: var(--ink);"><?= h($row['member']) ?></div>
                                                <div style="font-size: 12px; color: var(--muted);"><?= h($row['plan_name']) ?></div>
                                            </div>
                                        </div>
                                        <span class="<?= $statusClass ?>"><?= h($row['status']) ?></span>
                                    </div>
                                    <div class="membership-card-details">
                                        <div class="membership-card-detail-item">
                                            <span class="detail-label">Price</span>
                                            <span class="detail-val" style="color: var(--lime); font-weight: 700;"><?= h(money($row['price'])) ?></span>
                                        </div>
                                        <div class="membership-card-detail-item">
                                            <span class="detail-label">Validity</span>
                                            <span class="detail-val"><?= h(date('M j', strtotime($row['start_date']))) ?> – <?= h(date('M j, Y', strtotime($row['end_date']))) ?></span>
                                        </div>
                                    </div>
                                    <div class="membership-card-actions">
                                        <button type="button" onclick="editStatus(<?= $row['membership_id'] ?>, '<?= h($row['status']) ?>')" class="btn btn-secondary" style="width: 100%; padding: 8px 12px; font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 6px;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M12 20h9" />
                                                <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
                                            </svg>
                                            Update Status
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- PANE 2: PAYMENT HISTORY -->
                <div id="admin-pane-payments" class="membership-view-pane" style="display: <?= $adminView === 'payments' ? 'block' : 'none' ?>;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <div>
                            <h2 style="margin: 0 0 4px; font-size: 1.3rem; font-weight: 700;">Payment History</h2>
                            <p style="margin: 0; color: var(--muted); font-size: 0.88rem;">All collected revenues, pending balances, and transaction receipts.</p>
                        </div>
                    </div>

                    <!-- Same-Row Compact Stat Cards -->
                    <div class="payments-stats-row">
                        <div class="payment-stat-card">
                            <div class="payment-stat-label">Total Collected</div>
                            <div class="payment-stat-value" style="color:var(--lime);"><?= h(money($totalCollected)) ?></div>
                        </div>
                        <div class="payment-stat-card">
                            <div class="payment-stat-label">Pending Payments</div>
                            <div class="payment-stat-value" style="color:var(--ink);"><?= (int) $totalPending ?></div>
                        </div>
                    </div>

                    <?php if (!$paymentRows): ?>
                        <div class="empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                                <line x1="1" y1="10" x2="23" y2="10"/>
                            </svg>
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
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($paymentRows as $prow):
                                        $statusClass = 'badge badge-' . $prow['status'];
                                        $svgStyle = 'vertical-align: text-bottom; margin-right: 4px;';
                                        $methodIcons = [
                                            'cash' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="'.$svgStyle.'"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>',
                                            'gcash' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="'.$svgStyle.'"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
                                            'card' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="'.$svgStyle.'"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
                                            'bank_transfer' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="'.$svgStyle.'"><rect x="4" y="10" width="16" height="12" rx="2"/><path d="M12 2l8 6H4z"/></svg>',
                                            'online' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="'.$svgStyle.'"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
                                            'other' => '—'
                                        ];
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="user-cell">
                                                    <?= render_avatar($prow) ?>
                                                    <span><?= h($prow['member']) ?></span>
                                                </div>
                                            </td>
                                            <td><?= h($prow['plan_name']) ?></td>
                                            <td><strong><?= h(money($prow['amount'])) ?></strong></td>
                                            <td><?= h(date('M j, Y', strtotime($prow['payment_date']))) ?></td>
                                            <td><span style="color:var(--muted);font-size:12px"><?= ($methodIcons[$prow['payment_method']] ?? '') . ' ' . h(ucfirst($prow['payment_method'])) ?></span></td>
                                            <td><span class="<?= $statusClass ?>"><?= h(ucfirst($prow['status'])) ?></span></td>
                                            <td><span style="color:var(--muted);font-size:12px;font-family:monospace"><?= h($prow['receipt_number']) ?></span></td>
                                            <td>
                                                <?php if ($prow['status'] === 'pending'): ?>
                                                    <button type="button" 
                                                            onclick="confirmPayment(<?= (int)$prow['payment_id'] ?>, '<?= h(addslashes($prow['member'])) ?>', '<?= h(addslashes($prow['plan_name'])) ?>', <?= (float)$prow['amount'] ?>, '<?= h($prow['payment_method']) ?>', '<?= h(addslashes($prow['receipt_number'])) ?>')" 
                                                            class="btn" 
                                                            style="background: var(--lime); color: var(--bg); font-weight: 700; font-size: 11.5px; padding: 5px 12px; border-radius: 6px; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; transition: transform 0.15s, opacity 0.15s; border: none;"
                                                            onmouseover="this.style.opacity='0.9'; this.style.transform='translateY(-1px)';"
                                                            onmouseout="this.style.opacity='1'; this.style.transform='translateY(0)';"
                                                            title="Confirm payment and activate subscription">
                                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                        <span>Confirm</span>
                                                    </button>
                                                <?php else: ?>
                                                    <span style="color:var(--muted); font-size:12px; display:inline-flex; align-items:center; gap:4px;">
                                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                                                        Paid
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mobile Cards View for Payments -->
                        <div class="payments-mobile-cards">
                            <?php foreach ($paymentRows as $prow):
                                $statusClass = 'badge badge-' . $prow['status'];
                                $svgStyle = 'vertical-align: text-bottom; margin-right: 4px;';
                                $methodIcons = [
                                    'cash' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="'.$svgStyle.'"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>',
                                    'gcash' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="'.$svgStyle.'"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
                                    'card' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="'.$svgStyle.'"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
                                    'bank_transfer' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="'.$svgStyle.'"><rect x="4" y="10" width="16" height="12" rx="2"/><path d="M12 2l8 6H4z"/></svg>',
                                    'online' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="'.$svgStyle.'"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
                                    'other' => '—'
                                ];
                                $methodIcon = $methodIcons[$prow['payment_method']] ?? '';
                            ?>
                                <div class="payment-card-item">
                                    <div class="payment-card-header">
                                        <div class="user-cell">
                                            <?= render_avatar($prow) ?>
                                            <div>
                                                <div style="font-weight: 700; color: var(--ink);"><?= h($prow['member']) ?></div>
                                                <div style="font-size: 12px; color: var(--muted);"><?= h($prow['plan_name']) ?></div>
                                            </div>
                                        </div>
                                        <span class="<?= $statusClass ?>"><?= h(ucfirst($prow['status'])) ?></span>
                                    </div>
                                    <div class="payment-card-amount-row">
                                        <span class="payment-card-amount"><?= h(money($prow['amount'])) ?></span>
                                        <span class="payment-card-method"><?= $methodIcon ?> <?= ucfirst(h($prow['payment_method'])) ?></span>
                                    </div>
                                    <div class="payment-card-details">
                                        <div class="payment-card-detail-item">
                                            <span class="detail-label">Payment Date</span>
                                            <span class="detail-val"><?= h(date('M j, Y', strtotime($prow['payment_date']))) ?></span>
                                        </div>
                                        <div class="payment-card-detail-item">
                                            <span class="detail-label">Receipt #</span>
                                            <span class="detail-val receipt-pill"><?= h($prow['receipt_number']) ?></span>
                                        </div>
                                    </div>
                                    <?php if ($prow['status'] === 'pending'): ?>
                                        <div class="payment-card-actions" style="margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--line);">
                                            <button type="button" 
                                                    onclick="confirmPayment(<?= (int)$prow['payment_id'] ?>, '<?= h(addslashes($prow['member'])) ?>', '<?= h(addslashes($prow['plan_name'])) ?>', <?= (float)$prow['amount'] ?>, '<?= h($prow['payment_method']) ?>', '<?= h(addslashes($prow['receipt_number'])) ?>')" 
                                                    class="btn" 
                                                    style="width: 100%; background: var(--lime); color: var(--bg); font-weight: 700; font-size: 13px; padding: 8px 12px; display: flex; align-items: center; justify-content: center; gap: 6px; border-radius: 8px; border: none; cursor: pointer;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                Confirm Payment
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <!-- Platform Admin fallback -->
                <h2 style="margin-bottom: 12px;">Membership records</h2>
                <?php if (!$rows): ?>
                    <div class="empty-state">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="5" width="20" height="14" rx="2" />
                            <line x1="2" y1="10" x2="22" y2="10" />
                        </svg>
                        <p>No memberships found.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap membership-desktop-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Member</th>
                                    <th>Plan</th>
                                    <th>Price</th>
                                    <th>Start</th>
                                    <th>End</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row):
                                    $statusClass = 'badge badge-' . $row['status'];
                                ?>
                                    <tr>
                                        <td>
                                            <div class="user-cell">
                                                <?= render_avatar($row) ?>
                                                <span><?= h($row['member']) ?></span>
                                            </div>
                                        </td>
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
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ($user['role'] === 'gym_owner'): ?>
        <script>
            function editStatus(membershipId, currentStatus) {
                const statusOptions = [
                    { id: 'active', label: 'Active', dotClass: 'status-dot-active' },
                    { id: 'pending', label: 'Pending', dotClass: 'status-dot-pending' },
                    { id: 'expired', label: 'Expired', dotClass: 'status-dot-expired' },
                    { id: 'cancelled', label: 'Cancelled', dotClass: 'status-dot-cancelled' }
                ];

                Swal.fire({
                    title: 'Update Status',
                    width: '400px',
                    html: `
                    <form id="editStatusForm" method="post" style="text-align:left;display:flex;flex-direction:column;gap:12px;margin-top:15px;min-height:120px;position:relative;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="update_status_id" value="${membershipId}">
                        <label style="display:block;color:var(--muted);font-size:13.5px;font-weight:500;">Status</label>
                        <div id="editStatusDropdownWrap"></div>
                    </form>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Save',
                    confirmButtonColor: 'var(--lime-dark)',
                    cancelButtonColor: 'var(--line)',
                    background: 'var(--bg)',
                    color: 'var(--ink)',
                    didOpen: () => {
                        new FitDropdown({
                            container: '#editStatusDropdownWrap',
                            name: 'status',
                            id: 'editStatusInput',
                            value: currentStatus || 'active',
                            zIndex: 90,
                            items: statusOptions
                        });
                    },
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
            window.membershipInitialMembers = <?= json_encode($initialMembersData ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            window.membershipPlansData = <?= json_encode($plansData ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

            function addMembership() {
                Swal.fire({
                    title: 'Create Membership',
                    width: '460px',
                    html: `
                <form id="addMembershipForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 14px; margin-top: 15px; min-height: 285px; position: relative;">
                    <?= csrf_field() ?>
                    
                    <!-- Member Selector (Hybrid Live Search) -->
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Member *</label>
                        <div id="memberComboboxWrap"></div>
                    </div>
                    
                    <!-- Plan Selector (Custom Themed Dropdown UI) -->
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Plan *</label>
                        <div id="planComboboxWrap"></div>
                    </div>
                    
                    <!-- Dates & Status in Row -->
                    <div style="display:flex; gap:12px;">
                        <div style="flex:1;">
                            <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Start date *</label>
                            <input name="start_date" type="date" class="wb-modal-date-input" value="<?= h(date('Y-m-d')) ?>" required>
                        </div>
                        <div style="flex:1;">
                            <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Status *</label>
                            <div id="statusComboboxWrap"></div>
                        </div>
                    </div>
                </form>
            `,
                    showCancelButton: true,
                    confirmButtonText: 'Create',
                    confirmButtonColor: 'var(--lime-dark)',
                    cancelButtonColor: 'var(--line)',
                    background: 'var(--bg)',
                    color: 'var(--ink)',
                    didOpen: () => {
                        let memberSearchTimer = null;

                        const memberDrop = new FitDropdown({
                            container: '#memberComboboxWrap',
                            name: 'user_id',
                            id: 'membershipUserId',
                            placeholder: 'Select Member...',
                            searchable: true,
                            searchPlaceholder: 'Search members by name or email...',
                            allowClear: true,
                            zIndex: 60,
                            items: window.membershipInitialMembers || [],
                            onSearch: (q, updateBadge, setResults) => {
                                clearTimeout(memberSearchTimer);
                                if (!q.trim()) {
                                    updateBadge('');
                                    setResults(window.membershipInitialMembers || []);
                                    return;
                                }
                                updateBadge('Searching...');
                                memberSearchTimer = setTimeout(() => {
                                    fetch('index.php?page=memberships&action=search_members&q=' + encodeURIComponent(q.trim()))
                                        .then(r => r.json())
                                        .then(data => {
                                            if (data && data.success && Array.isArray(data.members)) {
                                                updateBadge(data.members.length + ' found');
                                                setResults(data.members);
                                            } else {
                                                updateBadge('');
                                            }
                                        })
                                        .catch(() => updateBadge(''));
                                }, 200);
                            }
                        });

                        const planDrop = new FitDropdown({
                            container: '#planComboboxWrap',
                            name: 'plan_id',
                            id: 'membershipPlanId',
                            placeholder: 'Select Plan...',
                            searchable: false,
                            zIndex: 50,
                            items: (window.membershipPlansData || []).map(p => ({
                                id: p.id,
                                label: p.name,
                                price: p.formatted_price || ('₱' + parseFloat(p.price || 0).toFixed(2)),
                                icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="3"></rect><line x1="3" y1="10" x2="21" y2="10"></line></svg>'
                            }))
                        });

                        const statusDrop = new FitDropdown({
                            container: '#statusComboboxWrap',
                            name: 'status',
                            id: 'membershipStatus',
                            value: 'active',
                            searchable: false,
                            zIndex: 40,
                            items: [
                                { id: 'active', label: 'Active', dotClass: 'status-dot-active' },
                                { id: 'pending', label: 'Pending', dotClass: 'status-dot-pending' },
                                { id: 'expired', label: 'Expired', dotClass: 'status-dot-expired' },
                                { id: 'cancelled', label: 'Cancelled', dotClass: 'status-dot-cancelled' }
                            ]
                        });

                        window._currentMembershipDrops = { memberDrop, planDrop, statusDrop };
                    },
                    preConfirm: () => {
                        const form = document.getElementById('addMembershipForm');
                        const drops = window._currentMembershipDrops;
                        const memberId = drops ? drops.memberDrop.getValue() : '';
                        const planId = drops ? drops.planDrop.getValue() : '';
                        const startDate = form.start_date.value;

                        let valid = true;
                        if (!memberId) {
                            if (drops) drops.memberDrop.setError(true);
                            valid = false;
                        }
                        if (!planId) {
                            if (drops) drops.planDrop.setError(true);
                            valid = false;
                        }
                        if (!valid || !startDate) {
                            Swal.showValidationMessage('Please fill all required fields');
                            return false;
                        }
                        form.submit();
                    }
                });
            }
            window.addMembership = addMembership;

            function switchAdminView(view) {
                const tabMemberships = document.getElementById('admin-tab-memberships');
                const tabPayments = document.getElementById('admin-tab-payments');
                const paneMemberships = document.getElementById('admin-pane-memberships');
                const panePayments = document.getElementById('admin-pane-payments');
                const titleEl = document.getElementById('admin-page-title');
                const descEl = document.getElementById('admin-page-desc');

                if (view === 'payments') {
                    if (tabMemberships) tabMemberships.classList.remove('active');
                    if (tabPayments) tabPayments.classList.add('active');
                    if (paneMemberships) paneMemberships.style.display = 'none';
                    if (panePayments) panePayments.style.display = 'block';
                    if (titleEl) titleEl.textContent = 'Payment History';
                    if (descEl) descEl.textContent = 'Record and track membership payment transactions.';
                    try {
                        history.replaceState(null, '', 'index.php?page=memberships&view=payments');
                    } catch (e) {}
                } else {
                    if (tabMemberships) tabMemberships.classList.add('active');
                    if (tabPayments) tabPayments.classList.remove('active');
                    if (paneMemberships) paneMemberships.style.display = 'block';
                    if (panePayments) panePayments.style.display = 'none';
                    if (titleEl) titleEl.textContent = 'Memberships';
                    if (descEl) descEl.textContent = 'Manage member subscription plans and their validity periods.';
                    try {
                        history.replaceState(null, '', 'index.php?page=memberships&view=memberships');
                    } catch (e) {}
                }
            }
            window.switchAdminView = switchAdminView;

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
                        <form id="recordPaymentForm" method="post" action="index.php?page=memberships&view=payments" style="text-align: left; display: flex; flex-direction: column; gap: 14px; margin-top: 15px; min-height: 380px; position: relative;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="record_payment">
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
                            
                            <!-- 5. Optional Receipt Reference -->
                            <div>
                                <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Receipt Number (Optional)</label>
                                <input name="receipt_number" class="wb-modal-input" placeholder="Auto-generated if left blank">
                            </div>
                        </form>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Record Payment',
                    confirmButtonColor: 'var(--lime-dark)',
                    cancelButtonColor: 'var(--line)',
                    background: 'var(--bg)',
                    color: 'var(--ink)',
                    didOpen: () => {
                        const userIdInput = document.getElementById('modalPaymentUserId');
                        const membershipIdInput = document.getElementById('modalPaymentMembershipId');
                        const planIdInput = document.getElementById('modalPaymentPlanId');
                        const amountInput = document.getElementById('modalPaymentAmount');

                        function buildItemOptions(userId) {
                            if (!userId) return [];
                            const items = [];
                            // 1. Existing memberships for this user
                            const userMemberships = memberships.filter(m => String(m.user_id) === String(userId));
                            // Sort so pending memberships appear at the very top
                            userMemberships.sort((a, b) => {
                                if (a.status === 'pending' && b.status !== 'pending') return -1;
                                if (a.status !== 'pending' && b.status === 'pending') return 1;
                                return 0;
                            });

                            userMemberships.forEach(m => {
                                const isPending = (m.status === 'pending');
                                items.push({
                                    id: 'm_' + m.id,
                                    membershipId: m.id,
                                    planId: m.plan_id || null,
                                    label: isPending ? `⚡ ${m.plan_name} (Pending Request - Awaiting Payment)` : `${m.plan_name} (Existing Membership)`,
                                    subtitle: isPending ? `Status: PENDING • Action: Confirm & Activate` : `Status: ${m.status}${m.end_date ? ' • Exp: ' + m.end_date : ''}`,
                                    price: m.formatted_price,
                                    rawPrice: m.price,
                                    icon: isPending 
                                        ? '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>'
                                        : '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><polyline points="17 11 19 13 23 9"></polyline></svg>'
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
                                    fetch('index.php?page=memberships&action=search_members&q=' + encodeURIComponent(q.trim()))
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

            function confirmPayment(paymentId, memberName, planName, amount, currentMethod, receiptNumber) {
                const formattedAmount = '₱' + Number(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                const methodOptions = [
                    { id: 'cash', label: 'Cash', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>' },
                    { id: 'gcash', label: 'GCash', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>' },
                    { id: 'card', label: 'Card', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>' },
                    { id: 'bank_transfer', label: 'Bank Transfer', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M3 10h18M5 10v11M19 10v11M9 10v11M15 10v11M12 2L2 7h20L12 2z"/></svg>' },
                    { id: 'online', label: 'Online', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>' },
                    { id: 'other', label: 'Other', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>' }
                ];

                const safeMember = typeof escapeHtml === 'function' ? escapeHtml(memberName) : memberName;
                const safePlan = typeof escapeHtml === 'function' ? escapeHtml(planName) : planName;
                const safeReceipt = typeof escapeHtml === 'function' ? escapeHtml(receiptNumber || '') : (receiptNumber || '');

                Swal.fire({
                    title: 'Confirm Payment',
                    width: '450px',
                    html: `
                        <form id="confirmPaymentForm" method="post" action="index.php?page=memberships&view=payments" style="text-align: left; display: flex; flex-direction: column; gap: 14px; margin-top: 15px; position: relative;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="confirm_payment">
                            <input type="hidden" name="payment_id" value="${paymentId}">

                            <div style="background: rgba(128,128,128,0.07); border: 1px solid var(--line); border-radius: 10px; padding: 14px;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                    <span style="color: var(--muted); font-size: 13px;">Member:</span>
                                    <strong style="color: var(--ink); font-size: 13.5px;">${safeMember}</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                    <span style="color: var(--muted); font-size: 13px;">Plan:</span>
                                    <strong style="color: var(--ink); font-size: 13.5px;">${safePlan}</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: baseline; border-top: 1px dashed var(--line); padding-top: 8px; margin-top: 4px;">
                                    <span style="color: var(--muted); font-size: 13px; font-weight: 600;">Amount Due:</span>
                                    <strong style="color: var(--lime); font-size: 19px;">${formattedAmount}</strong>
                                </div>
                            </div>

                            <div>
                                <label style="display:block; color: var(--muted); font-size: 13px; margin-bottom: 6px; font-weight: 500;">Payment Method *</label>
                                <div id="confirmMethodWrap"></div>
                            </div>

                            <div style="display:flex; gap:12px;">
                                <div style="flex:1;">
                                    <label style="display:block; color: var(--muted); font-size: 13px; margin-bottom: 6px; font-weight: 500;">Payment Date *</label>
                                    <input name="payment_date" type="date" class="wb-modal-date-input" value="<?= h(date('Y-m-d')) ?>" required>
                                </div>
                                <div style="flex:1;">
                                    <label style="display:block; color: var(--muted); font-size: 13px; margin-bottom: 6px; font-weight: 500;">Receipt Number</label>
                                    <input name="receipt_number" class="wb-modal-input" value="${safeReceipt}" placeholder="Auto if blank">
                                </div>
                            </div>

                            <div style="font-size: 12px; color: var(--muted); line-height: 1.4; padding: 10px 12px; background: rgba(34,197,94,0.08); border-radius: 8px; border: 1px solid rgba(34,197,94,0.25);">
                                <span style="color: var(--lime); font-weight: 700;">✓ Instant Activation:</span> Confirming will mark this payment as <strong>PAID</strong> and immediately activate the member's subscription without creating any duplicate records.
                            </div>
                        </form>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Confirm & Activate',
                    confirmButtonColor: 'var(--lime-dark)',
                    cancelButtonColor: 'var(--line)',
                    background: 'var(--bg)',
                    color: 'var(--ink)',
                    didOpen: () => {
                        new FitDropdown({
                            container: '#confirmMethodWrap',
                            name: 'payment_method',
                            value: currentMethod || 'cash',
                            zIndex: 60,
                            items: methodOptions
                        });
                    },
                    preConfirm: () => {
                        const form = document.getElementById('confirmPaymentForm');
                        if (form) {
                            form.submit();
                        }
                    }
                });
            }
            window.confirmPayment = confirmPayment;
        </script>
    <?php endif; ?>

    <style>
        /* ============================================================
       Membership Top View Switcher & Subscription Pricing Aesthetic
       ============================================================ */
        .member-membership-nav {
            display: flex;
            gap: 8px;
            margin-bottom: 28px;
            background: rgba(16, 19, 27, 0.85);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 6px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.18);
            max-width: 480px;
        }

        [data-theme="light"] .member-membership-nav {
            background: rgba(255, 255, 255, 0.96);
            border-color: #cbd5e1;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
        }

        .member-nav-tab {
            flex: 1 1 auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 18px;
            border-radius: 10px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--muted);
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            white-space: nowrap;
            text-decoration: none;
        }

        .member-nav-tab svg {
            flex-shrink: 0;
            transition: stroke 0.2s ease, transform 0.2s ease;
        }

        .member-nav-tab:hover {
            color: var(--ink);
            background: color-mix(in srgb, var(--ink) 6%, transparent);
        }

        .member-nav-tab.active {
            color: var(--lime);
            background: color-mix(in srgb, var(--lime) 14%, var(--panel-soft));
            border-color: color-mix(in srgb, var(--lime) 40%, transparent);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .member-nav-tab.active svg {
            stroke: var(--lime);
            transform: scale(1.08);
        }

        .tab-badge-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 18px;
            height: 18px;
            padding: 0 6px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 800;
            background: rgba(255, 255, 255, 0.08);
            color: var(--muted);
        }

        .member-nav-tab.active .tab-badge-pill {
            background: var(--lime);
            color: #0b110e;
        }

        .tab-label-compact {
            display: none;
        }

        @media (max-width: 640px) {
            .member-membership-nav {
                max-width: 100%;
                padding: 5px;
                gap: 6px;
            }

            .member-nav-tab {
                padding: 10px 14px;
                font-size: 13px;
            }
        }

        @media (max-width: 440px) {
            .tab-label-full {
                display: none;
            }

            .tab-label-compact {
                display: inline;
            }

            .member-nav-tab {
                padding: 8px 10px;
                font-size: 12.5px;
                gap: 6px;
            }

            .member-nav-tab svg {
                width: 15px;
                height: 15px;
            }

            .tab-badge-pill {
                height: 17px;
                min-width: 17px;
                font-size: 10px;
                padding: 0 5px;
            }
        }

        /* Cleaned Hero Wrapper (Background photo removed per user request) */
        .membership-plans-hero-wrap {
            position: relative;
            margin: 0 0 28px 0;
            padding: 8px 0 24px 0;
            background: transparent !important;
            background-image: none !important;
            border: none !important;
            box-shadow: none !important;
            overflow: visible;
        }

        [data-theme="light"] .membership-plans-hero-wrap {
            background: transparent !important;
            background-image: none !important;
            border: none !important;
            box-shadow: none !important;
        }

        .membership-hero-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .membership-brand-pill {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            margin-bottom: 16px;
        }

        .membership-brand-logo {
            width: 38px;
            height: 38px;
            background: var(--lime, #84cc16);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #0b110e;
            font-weight: 900;
            font-size: 19px;
            box-shadow: 0 0 20px rgba(132, 204, 22, 0.35);
        }

        .membership-brand-title {
            font-weight: 800;
            font-size: 1.5rem;
            line-height: 1;
            letter-spacing: -0.3px;
            color: var(--ink);
        }

        [data-theme="light"] .membership-brand-title {
            color: #0f172a;
        }

        .membership-hero-title {
            font-size: 2.2rem;
            font-weight: 800;
            margin: 8px 0 12px;
            color: var(--ink);
            letter-spacing: -0.5px;
            line-height: 1.2;
        }

        [data-theme="light"] .membership-hero-title {
            color: #0f172a;
        }

        .membership-hero-subtitle {
            color: var(--muted);
            font-size: 1.05rem;
            max-width: 620px;
            margin: 0 auto;
            line-height: 1.5;
        }

        [data-theme="light"] .membership-hero-subtitle {
            color: #64748b;
        }

        .member-pricing-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 28px;
            align-items: stretch;
            max-width: 1200px;
            margin: 0 auto;
            padding-top: 16px;
        }

        .member-pricing-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 24px;
            padding: 38px 30px;
            display: flex;
            flex-direction: column;
            position: relative;
            transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.25s ease, border-color 0.25s ease;
            box-shadow: 0 14px 36px -10px rgba(0, 0, 0, 0.5);
        }

        .member-pricing-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 22px 50px -10px rgba(0, 0, 0, 0.7);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .member-pricing-card.popular {
            border: 2px solid var(--lime, #84cc16);
            background: color-mix(in srgb, var(--lime) 5%, var(--surface));
            box-shadow: 0 0 35px rgba(132, 204, 22, 0.18), 0 20px 45px -10px rgba(0, 0, 0, 0.6);
        }

        .member-pricing-card.popular:hover {
            box-shadow: 0 0 45px rgba(132, 204, 22, 0.28), 0 25px 55px -10px rgba(0, 0, 0, 0.75);
        }

        [data-theme="light"] .member-pricing-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 8px 24px -5px rgba(0, 0, 0, 0.05);
        }

        [data-theme="light"] .member-pricing-card:hover {
            border-color: #cbd5e1;
        }

        [data-theme="light"] .member-pricing-card.popular {
            background: #fcfdf9;
            border: 2px solid var(--lime, #22c55e);
            box-shadow: 0 0 35px rgba(34, 197, 94, 0.15), 0 12px 30px -5px rgba(0, 0, 0, 0.08);
        }

        [data-theme="light"] .member-pricing-card.popular:hover {
            box-shadow: 0 0 45px rgba(34, 197, 94, 0.25), 0 20px 45px -8px rgba(0, 0, 0, 0.12);
        }

        .member-popular-badge {
            position: absolute;
            top: -14px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--lime, #84cc16);
            color: #0b110e;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.8px;
            padding: 5px 16px;
            border-radius: 20px;
            text-transform: uppercase;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            white-space: nowrap;
        }

        .member-current-badge {
            display: inline-block;
            background: rgba(34, 197, 94, 0.15);
            color: var(--lime, #22c55e);
            border: 1px solid rgba(34, 197, 94, 0.3);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.5px;
            padding: 3px 8px;
            border-radius: 5px;
            margin-left: 8px;
            vertical-align: middle;
        }

        .member-pricing-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: #ffffff;
            margin: 0 0 8px 0;
            letter-spacing: -0.3px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
        }

        .member-pricing-title.popular-title {
            color: var(--lime, #84cc16);
        }

        [data-theme="light"] .member-pricing-title {
            color: #0f172a;
        }

        [data-theme="light"] .member-pricing-title.popular-title {
            color: #16a34a;
        }

        .member-pricing-desc {
            color: var(--muted, #94a3b8);
            font-size: 14px;
            line-height: 1.45;
            margin: 0 0 22px 0;
            min-height: 42px;
        }

        [data-theme="light"] .member-pricing-desc {
            color: #64748b;
        }

        .member-pricing-price {
            display: flex;
            align-items: baseline;
            margin-bottom: 26px;
            padding-bottom: 22px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        [data-theme="light"] .member-pricing-price {
            border-bottom: 1px solid #f1f5f9;
        }

        .member-pricing-price .amount {
            font-size: 2.8rem;
            font-weight: 900;
            color: #ffffff;
            letter-spacing: -1px;
            line-height: 1;
        }

        [data-theme="light"] .member-pricing-price .amount {
            color: #0f172a;
        }

        .member-pricing-price .period {
            font-size: 1rem;
            color: var(--muted, #94a3b8);
            margin-left: 5px;
            font-weight: 500;
        }

        [data-theme="light"] .member-pricing-price .period {
            color: #64748b;
        }

        .member-pricing-price .discount-strike {
            text-decoration: line-through;
            font-size: 1.15rem;
            color: var(--muted, #94a3b8);
            margin-right: 10px;
            font-weight: 600;
        }

        .member-pricing-features {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 13px;
            flex: 1;
        }

        .member-pricing-features li {
            display: flex;
            align-items: flex-start;
            gap: 11px;
            font-size: 14px;
            color: #e2e8f0;
            line-height: 1.4;
        }

        [data-theme="light"] .member-pricing-features li {
            color: #334155;
        }

        .member-checkmark {
            color: var(--lime, #84cc16);
            font-weight: 900;
            font-size: 15px;
            line-height: 1.2;
            flex-shrink: 0;
        }

        [data-theme="light"] .member-checkmark {
            color: #16a34a;
        }

        .member-pricing-btn {
            width: 100%;
            padding: 14px 20px;
            border-radius: 30px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s ease;
            display: block;
            box-sizing: border-box;
        }

        .member-standard-btn {
            background: #141a17;
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffffff;
        }

        .member-standard-btn:hover {
            background: #1a221f;
            border-color: rgba(255, 255, 255, 0.3);
            transform: translateY(-1px);
        }

        [data-theme="light"] .member-standard-btn {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #0f172a;
        }

        [data-theme="light"] .member-standard-btn:hover {
            background: #e2e8f0;
            border-color: #94a3b8;
        }

        .member-popular-btn {
            background: var(--lime, #84cc16);
            border: none;
            color: #0b110e;
            box-shadow: 0 4px 18px rgba(132, 204, 22, 0.35);
        }

        .member-popular-btn:hover {
            background: #73b711;
            box-shadow: 0 6px 24px rgba(132, 204, 22, 0.45);
            transform: translateY(-1px);
        }

        /* ============================================================
       Multi-Device Responsiveness (Desktop, Laptop, Tablet, Mobile)
       ============================================================ */
        @media (max-width: 1199px) {
            .member-pricing-grid {
                gap: 20px;
                max-width: 1060px;
            }

            .member-pricing-card {
                padding: 32px 22px;
                border-radius: 20px;
            }

            .member-pricing-title {
                font-size: 1.45rem;
            }

            .member-pricing-price .amount {
                font-size: 2.5rem;
            }
        }

        @media (max-width: 991px) {
            .member-pricing-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 20px;
                max-width: 780px;
            }

            .member-pricing-card:last-child:nth-child(2n + 1) {
                grid-column: 1 / -1;
                max-width: 440px;
                width: 100%;
                margin: 0 auto;
            }

            .membership-hero-title {
                font-size: 1.95rem;
            }

            .membership-hero-subtitle {
                font-size: 0.98rem;
            }

            .membership-plans-hero-wrap {
                padding: 8px 0 20px;
            }
        }

        @media (max-width: 680px) {
            .member-pricing-grid {
                grid-template-columns: 1fr;
                max-width: 420px;
                gap: 26px;
            }

            .member-pricing-card:last-child:nth-child(2n + 1) {
                grid-column: auto;
                max-width: 100%;
            }

            .member-pricing-card.popular {
                transform: none !important;
            }

            .member-pricing-card:hover,
            .member-pricing-card.popular:hover {
                transform: translateY(-3px);
            }

            .membership-hero-header {
                margin-bottom: 28px;
            }

            .membership-hero-title {
                font-size: 1.7rem;
            }

            .membership-plans-hero-wrap {
                padding: 4px 0 16px;
            }
        }

        @media (max-width: 480px) {
            .membership-plans-hero-wrap {
                padding: 0 0 16px;
            }

            .membership-brand-pill {
                margin-bottom: 12px;
            }

            .membership-brand-logo {
                width: 32px;
                height: 32px;
                font-size: 16px;
                border-radius: 6px;
            }

            .membership-brand-title {
                font-size: 1.25rem;
            }

            .membership-hero-title {
                font-size: 1.45rem;
                margin: 6px 0 10px;
            }

            .membership-hero-subtitle {
                font-size: 0.88rem;
                line-height: 1.45;
            }

            .member-pricing-grid {
                max-width: 100%;
                gap: 22px;
                padding-top: 14px;
            }

            .member-pricing-card {
                padding: 26px 18px;
                border-radius: 18px;
            }

            .member-pricing-title {
                font-size: 1.3rem;
            }

            .member-pricing-desc {
                font-size: 13px;
                min-height: auto;
                margin-bottom: 16px;
            }

            .member-pricing-price {
                margin-bottom: 18px;
                padding-bottom: 16px;
            }

            .member-pricing-price .amount {
                font-size: 2.15rem;
            }

            .member-pricing-price .period {
                font-size: 0.9rem;
            }

            .member-pricing-price .discount-strike {
                font-size: 1rem;
            }

            .member-pricing-features {
                gap: 10px;
            }

            .member-pricing-features li {
                font-size: 13px;
                gap: 9px;
            }

            .member-pricing-btn {
                padding: 12px 18px;
                font-size: 14px;
            }

            .member-popular-badge {
                font-size: 10px;
                padding: 4px 12px;
                top: -12px;
            }

            .membership-view-pane h2 {
                font-size: 1.2rem !important;
            }

            .table-wrap {
                border-radius: 10px;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin-top: 8px;
            }

            .table-wrap table {
                font-size: 13px;
            }

            .table-wrap th,
            .table-wrap td {
                padding: 10px 10px;
                white-space: nowrap;
            }
        }

        /* ============================================================
       Responsive Mobile Cards for Membership Records
       ============================================================ */
        .membership-mobile-cards {
            display: none;
        }

        @media (max-width: 768px) {
            .membership-desktop-table {
                display: none !important;
            }

            .membership-mobile-cards {
                display: flex !important;
                flex-direction: column;
                gap: 12px;
                margin-top: 10px;
            }
        }

        .membership-card-item {
            background: color-mix(in srgb, var(--panel-soft) 45%, transparent);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.2s ease;
        }

        [data-theme="light"] .membership-card-item {
            background: #ffffff;
            border-color: #e2e8f0;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
        }

        .membership-card-item:hover {
            border-color: color-mix(in srgb, var(--lime) 35%, transparent);
        }

        .membership-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid color-mix(in srgb, var(--line) 60%, transparent);
        }

        [data-theme="light"] .membership-card-header {
            border-bottom-color: #f1f5f9;
        }

        .membership-card-title {
            font-weight: 700;
            font-size: 15px;
            color: var(--ink);
        }

        .membership-card-price {
            font-weight: 700;
            font-size: 14px;
            color: var(--lime);
            margin-top: 2px;
        }

        [data-theme="light"] .membership-card-price {
            color: #15803d;
        }

        .membership-card-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .membership-card-detail-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .membership-card-detail-item .detail-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--muted);
        }

        .membership-card-detail-item .detail-val {
            font-size: 13px;
            font-weight: 600;
            color: var(--ink);
        }

        .membership-card-actions {
            padding-top: 6px;
            border-top: 1px solid color-mix(in srgb, var(--line) 40%, transparent);
        }

        /* ============================================================
           Admin Payment History & Compact Stats (Same-Row)
           ============================================================ */
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

        </style>
<?php
    render_footer();
}
