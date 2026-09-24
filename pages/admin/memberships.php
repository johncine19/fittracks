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

        $q = trim((string)($_GET['q'] ?? post('q') ?? ''));
        $sql = 'SELECT user_id, first_name, last_name, email, profile_picture
                FROM users
                WHERE role = "member" AND status = "active"';
        $params = [];
        if ($q !== '') {
            $pattern = '%' . $q . '%';
            $sql .= ' AND (first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, " ", last_name) LIKE ? OR email LIKE ?)';
            $params = [$pattern, $pattern, $pattern, $pattern];
        }
        $sql .= ' ORDER BY first_name ASC LIMIT 50';
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
    $members = db()->query('SELECT user_id, first_name, last_name, email, profile_picture, CONCAT(first_name, " ", last_name) AS name FROM users WHERE role = "member" AND status = "active" ORDER BY first_name')->fetchAll();
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
        <?php if ($user['role'] !== 'member'): ?>
            <div class="page-header">
                <div>
                    <h1>Memberships</h1>
                    <p>Manage member subscription plans and their validity periods.</p>
                </div>
                <?php if ($user['role'] === 'gym_owner'): ?>
                    <button onclick="addMembership()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold;">+ New Membership</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

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
        <?php endif;
        endif; ?>

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

                <!-- Mobile Cards View for Gym Owner / Admin -->
                <div class="membership-mobile-cards">
                    <?php foreach ($rows as $row):
                        $statusClass = 'badge badge-' . $row['status'];
                    ?>
                        <div class="membership-card-item">
                            <div class="membership-card-header">
                                <?php if ($user['role'] !== 'member'): ?>
                                    <div class="user-cell">
                                        <?= render_avatar($row) ?>
                                        <div>
                                            <div style="font-weight: 700; color: var(--ink);"><?= h($row['member']) ?></div>
                                            <div style="font-size: 12px; color: var(--muted);"><?= h($row['plan_name']) ?></div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div>
                                        <div class="membership-card-title"><?= h($row['plan_name']) ?></div>
                                    </div>
                                <?php endif; ?>
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
                            <?php if ($user['role'] === 'gym_owner'): ?>
                                <div class="membership-card-actions">
                                    <button type="button" onclick="editStatus(<?= $row['membership_id'] ?>, '<?= h($row['status']) ?>')" class="btn btn-secondary" style="width: 100%; padding: 8px 12px; font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 6px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M12 20h9" />
                                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
                                        </svg>
                                        Update Status
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
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
                let selectedStatus = currentStatus || 'active';
                const initialOpt = statusOptions.find(o => o.id === selectedStatus) || statusOptions[0];

                Swal.fire({
                    title: 'Update Status',
                    width: '400px',
                    html: `
                    <form id="editStatusForm" method="post" style="text-align:left;display:flex;flex-direction:column;gap:12px;margin-top:15px;min-height:120px;position:relative;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="update_status_id" value="${membershipId}">
                        <label style="display:block;color:var(--muted);font-size:13.5px;font-weight:500;">Status</label>
                        <div class="wb-combobox-wrap" id="editStatusComboboxWrap" style="position:relative;width:100%;z-index:90;">
                            <input type="hidden" name="status" id="editStatusInput" value="${escapeHtml(selectedStatus)}" required>
                            <div id="editStatusTrigger" class="wb-combobox-trigger" tabindex="0" role="combobox" aria-expanded="false" aria-haspopup="listbox">
                                <div id="editStatusTriggerContent" style="display:flex;align-items:center;gap:8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <span class="status-indicator-dot ${initialOpt.dotClass}"></span>
                                    <span style="font-weight:600;color:var(--ink);font-size:13.5px;">${escapeHtml(initialOpt.label)}</span>
                                </div>
                                <svg id="editStatusChevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="transition:transform 0.2s ease;opacity:0.7;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                            </div>
                            <div id="editStatusMenu" class="wb-combobox-menu">
                                <div id="editStatusListContainer" class="wb-combobox-list" role="listbox" style="padding:4px;"></div>
                            </div>
                        </div>
                    </form>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Save',
                    confirmButtonColor: 'var(--lime-dark)',
                    cancelButtonColor: 'var(--line)',
                    background: 'var(--bg)',
                    color: 'var(--ink)',
                    didOpen: () => {
                        const trigger = document.getElementById('editStatusTrigger');
                        const menu = document.getElementById('editStatusMenu');
                        const chevron = document.getElementById('editStatusChevron');
                        const triggerContent = document.getElementById('editStatusTriggerContent');
                        const listContainer = document.getElementById('editStatusListContainer');
                        const input = document.getElementById('editStatusInput');

                        function renderOptions() {
                            listContainer.innerHTML = statusOptions.map(opt => {
                                const isSelected = selectedStatus === opt.id;
                                return `
                                    <div class="wb-combobox-item ${isSelected ? 'selected' : ''}" data-status="${opt.id}" role="option" style="padding:9px 12px;">
                                        <div style="display:flex;align-items:center;justify-content:space-between;width:100%;">
                                            <div style="display:flex;align-items:center;gap:8px;">
                                                <span class="status-indicator-dot ${opt.dotClass}"></span>
                                                <span style="font-weight:600;font-size:13.5px;color:var(--ink);">${escapeHtml(opt.label)}</span>
                                            </div>
                                            ${isSelected ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}
                                        </div>
                                    </div>
                                `;
                            }).join('');

                            listContainer.querySelectorAll('.wb-combobox-item').forEach(el => {
                                el.addEventListener('click', (e) => {
                                    e.stopPropagation();
                                    const s = el.getAttribute('data-status');
                                    selectedStatus = s;
                                    input.value = s;
                                    const opt = statusOptions.find(o => o.id === s) || statusOptions[0];
                                    triggerContent.innerHTML = `
                                        <span class="status-indicator-dot ${opt.dotClass}"></span>
                                        <span style="font-weight:600;color:var(--ink);font-size:13.5px;">${escapeHtml(opt.label)}</span>
                                    `;
                                    closeMenu();
                                    renderOptions();
                                });
                            });
                        }

                        function openMenu() {
                            menu.style.display = 'block';
                            chevron.style.transform = 'rotate(180deg)';
                            trigger.setAttribute('aria-expanded', 'true');
                        }

                        function closeMenu() {
                            menu.style.display = 'none';
                            chevron.style.transform = 'rotate(0deg)';
                            trigger.setAttribute('aria-expanded', 'false');
                        }

                        trigger.addEventListener('click', (e) => {
                            e.stopPropagation();
                            if (menu.style.display === 'block') closeMenu();
                            else openMenu();
                        });

                        document.addEventListener('click', function onDocClick(e) {
                            if (!document.getElementById('editStatusForm')) {
                                document.removeEventListener('click', onDocClick);
                                return;
                            }
                            const wrap = document.getElementById('editStatusComboboxWrap');
                            if (wrap && !wrap.contains(e.target)) closeMenu();
                        });

                        renderOptions();
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
                        <div class="wb-combobox-wrap" id="memberComboboxWrap" style="position: relative; width: 100%; z-index: 60;">
                            <input type="hidden" name="user_id" id="membershipUserId" value="" required>
                            
                            <div id="memberComboboxTrigger" class="wb-combobox-trigger" tabindex="0" role="combobox" aria-expanded="false" aria-haspopup="listbox">
                                <div id="memberTriggerContent" style="display: flex; align-items: center; gap: 8px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.6; flex-shrink: 0;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                    <span id="memberTriggerPlaceholder" style="color: var(--muted); font-size: 14px;">Select Member...</span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 6px; flex-shrink: 0;">
                                    <button type="button" id="memberClearBtn" style="display: none; background: none; border: none; padding: 2px; cursor: pointer; color: var(--muted); line-height: 1; border-radius: 50%;" title="Clear selection">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                    </button>
                                    <svg id="memberChevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="transition: transform 0.2s ease; opacity: 0.7;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                </div>
                            </div>

                            <div id="memberComboboxMenu" class="wb-combobox-menu">
                                <div class="wb-combobox-search-wrap">
                                    <div class="wb-combobox-search-box">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); opacity: 0.6; pointer-events: none;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                        <input type="text" id="memberSearchInput" class="wb-combobox-search-input" placeholder="Search members by name or email..." autocomplete="off">
                                        <span id="memberSearchBadge" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); font-size: 11px; font-weight: 600; color: var(--muted); pointer-events: none;"></span>
                                    </div>
                                </div>
                                <div id="memberListContainer" class="wb-combobox-list" role="listbox"></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Plan Selector (Custom Themed Dropdown UI) -->
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Plan *</label>
                        <div class="wb-combobox-wrap" id="planComboboxWrap" style="position: relative; width: 100%; z-index: 50;">
                            <input type="hidden" name="plan_id" id="membershipPlanId" value="" required>
                            
                            <div id="planComboboxTrigger" class="wb-combobox-trigger" tabindex="0" role="combobox" aria-expanded="false" aria-haspopup="listbox">
                                <div id="planTriggerContent" style="display: flex; align-items: center; gap: 8px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0;">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.6; flex-shrink: 0;"><rect x="3" y="4" width="18" height="16" rx="3"></rect><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                    <span id="planTriggerPlaceholder" style="color: var(--muted); font-size: 14px;">Select Plan...</span>
                                </div>
                                <svg id="planChevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="transition: transform 0.2s ease; opacity: 0.7;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                            </div>

                            <div id="planComboboxMenu" class="wb-combobox-menu">
                                <div id="planListContainer" class="wb-combobox-list" role="listbox" style="padding: 4px;"></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Dates & Status in Row -->
                    <div style="display:flex; gap:12px;">
                        <div style="flex:1;">
                            <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Start date *</label>
                            <input name="start_date" type="date" class="wb-modal-date-input" value="<?= h(date('Y-m-d')) ?>" required>
                        </div>
                        <div style="flex:1;">
                            <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Status *</label>
                            <div class="wb-combobox-wrap" id="statusComboboxWrap" style="position: relative; width: 100%; z-index: 40;">
                                <input type="hidden" name="status" id="membershipStatus" value="active" required>
                                
                                <div id="statusComboboxTrigger" class="wb-combobox-trigger" tabindex="0" role="combobox" aria-expanded="false" aria-haspopup="listbox">
                                    <div id="statusTriggerContent" style="display: flex; align-items: center; gap: 8px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <span class="status-indicator-dot status-dot-active"></span>
                                        <span style="font-weight: 600; color: var(--ink); font-size: 13.5px;">Active</span>
                                    </div>
                                    <svg id="statusChevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="transition: transform 0.2s ease; opacity: 0.7;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                </div>

                                <div id="statusComboboxMenu" class="wb-combobox-menu" style="min-width: 140px;">
                                    <div id="statusListContainer" class="wb-combobox-list" role="listbox" style="padding: 4px;"></div>
                                </div>
                            </div>
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
                        // 1. Member Selector Logic
                        const trigger = document.getElementById('memberComboboxTrigger');
                        const menu = document.getElementById('memberComboboxMenu');
                        const chevron = document.getElementById('memberChevron');
                        const triggerContent = document.getElementById('memberTriggerContent');
                        const clearBtn = document.getElementById('memberClearBtn');
                        const searchInput = document.getElementById('memberSearchInput');
                        const searchBadge = document.getElementById('memberSearchBadge');
                        const listContainer = document.getElementById('memberListContainer');
                        const userIdInput = document.getElementById('membershipUserId');

                        let pool = Array.isArray(window.membershipInitialMembers) ? [...window.membershipInitialMembers] : [];
                        let selectedMember = null;
                        let debounceTimer = null;
                        let activeIndex = -1;

                        // 2. Plan Selector Logic
                        const plans = Array.isArray(window.membershipPlansData) ? window.membershipPlansData : [];
                        const planTrigger = document.getElementById('planComboboxTrigger');
                        const planMenu = document.getElementById('planComboboxMenu');
                        const planChevron = document.getElementById('planChevron');
                        const planTriggerContent = document.getElementById('planTriggerContent');
                        const planListContainer = document.getElementById('planListContainer');
                        const planIdInput = document.getElementById('membershipPlanId');
                        let selectedPlanId = null;

                        // 3. Status Selector Logic
                        const statusOptions = [
                            { id: 'active', label: 'Active', dotClass: 'status-dot-active' },
                            { id: 'pending', label: 'Pending', dotClass: 'status-dot-pending' },
                            { id: 'expired', label: 'Expired', dotClass: 'status-dot-expired' },
                            { id: 'cancelled', label: 'Cancelled', dotClass: 'status-dot-cancelled' }
                        ];
                        const statusTrigger = document.getElementById('statusComboboxTrigger');
                        const statusMenu = document.getElementById('statusComboboxMenu');
                        const statusChevron = document.getElementById('statusChevron');
                        const statusTriggerContent = document.getElementById('statusTriggerContent');
                        const statusListContainer = document.getElementById('statusListContainer');
                        const statusInput = document.getElementById('membershipStatus');
                        let currentStatus = 'active';

                        function escapeHtml(str) {
                            if (!str) return '';
                            return String(str)
                                .replace(/&/g, '&amp;')
                                .replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;')
                                .replace(/"/g, '&quot;')
                                .replace(/'/g, '&#039;');
                        }

                        function highlightText(text, query) {
                            if (!text) return '';
                            if (!query) return escapeHtml(text);
                            const qEsc = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                            const regex = new RegExp(`(${qEsc})`, 'gi');
                            return escapeHtml(text).replace(regex, '<span class="wb-combobox-highlight">$1</span>');
                        }

                        // Member render
                        function renderList(query = '') {
                            const q = query.trim().toLowerCase();
                            const filtered = pool.filter(m => {
                                if (!q) return true;
                                const nameMatch = (m.name || '').toLowerCase().includes(q);
                                const emailMatch = (m.email || '').toLowerCase().includes(q);
                                return nameMatch || emailMatch;
                            });

                            if (filtered.length === 0) {
                                listContainer.innerHTML = `
                                    <div style="padding: 16px; text-align: center; color: var(--muted); font-size: 13px;">
                                        ${q ? `No members found matching "<strong>${escapeHtml(q)}</strong>"` : 'No members available'}
                                    </div>
                                `;
                                searchBadge.textContent = '0 found';
                                return;
                            }

                            searchBadge.textContent = `${filtered.length} found`;
                            listContainer.innerHTML = filtered.map((m, idx) => {
                                const isSelected = selectedMember && selectedMember.id === m.id;
                                return `
                                    <div class="wb-combobox-item ${isSelected ? 'selected' : ''}" 
                                         data-index="${idx}" 
                                         data-id="${m.id}" 
                                         role="option" 
                                         aria-selected="${isSelected}">
                                        <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                                            <div class="wb-combobox-avatar">
                                                ${escapeHtml(m.initials || 'M')}
                                            </div>
                                            <div style="display: flex; flex-direction: column; min-width: 0; text-align: left;">
                                                <span class="wb-combobox-item-name" style="font-weight: 600; font-size: 13.5px; line-height: 1.2;">
                                                    ${highlightText(m.name, q)}
                                                </span>
                                                ${m.email ? `
                                                    <span class="wb-combobox-item-sub" style="font-size: 11.5px; color: var(--muted); margin-top: 2px;">
                                                        ${highlightText(m.email, q)}
                                                    </span>
                                                ` : ''}
                                            </div>
                                        </div>
                                        ${isSelected ? `
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5" style="flex-shrink: 0;">
                                                <polyline points="20 6 9 17 4 12"></polyline>
                                            </svg>
                                        ` : ''}
                                    </div>
                                `;
                            }).join('');

                            listContainer.querySelectorAll('.wb-combobox-item').forEach(el => {
                                el.addEventListener('click', (e) => {
                                    e.stopPropagation();
                                    const id = parseInt(el.getAttribute('data-id'), 10);
                                    const member = pool.find(m => m.id === id);
                                    if (member) {
                                        selectMember(member);
                                        closeMemberMenu();
                                    }
                                });
                            });
                        }

                        function selectMember(member) {
                            selectedMember = member;
                            userIdInput.value = member ? member.id : '';
                            trigger.classList.remove('error');

                            if (member) {
                                triggerContent.innerHTML = `
                                    <div class="wb-combobox-avatar" style="width: 22px; height: 22px; font-size: 10px; border-width: 1px;">
                                        ${escapeHtml(member.initials || 'M')}
                                    </div>
                                    <span style="font-weight: 600; color: var(--ink); font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        ${escapeHtml(member.name)}
                                    </span>
                                `;
                                clearBtn.style.display = 'inline-block';
                            } else {
                                triggerContent.innerHTML = `
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.6; flex-shrink: 0;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                    <span id="memberTriggerPlaceholder" style="color: var(--muted); font-size: 14px;">Select Member...</span>
                                `;
                                clearBtn.style.display = 'none';
                            }
                        }

                        // Plan render
                        function renderPlans() {
                            if (plans.length === 0) {
                                planListContainer.innerHTML = '<div style="padding: 12px; text-align: center; color: var(--muted); font-size: 13px;">No plans available</div>';
                                return;
                            }
                            planListContainer.innerHTML = plans.map(p => {
                                const isSelected = selectedPlanId === p.id;
                                return `
                                    <div class="wb-combobox-item ${isSelected ? 'selected' : ''}" data-id="${p.id}" role="option" style="padding: 10px 12px;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; width: 100%; gap: 10px;">
                                            <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                                                <div class="wb-combobox-avatar" style="width: 26px; height: 26px;">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="3"></rect><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                                </div>
                                                <span style="font-weight: 600; font-size: 13.5px; color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(p.name)}</span>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                                                <span style="font-size: 12.5px; font-weight: 700; color: var(--lime);">${escapeHtml(p.formatted_price)}</span>
                                                ${isSelected ? '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}
                                            </div>
                                        </div>
                                    </div>
                                `;
                            }).join('');

                            planListContainer.querySelectorAll('.wb-combobox-item').forEach(el => {
                                el.addEventListener('click', (e) => {
                                    e.stopPropagation();
                                    const id = parseInt(el.getAttribute('data-id'), 10);
                                    selectPlan(id);
                                    closePlanMenu();
                                });
                            });
                        }

                        function selectPlan(id) {
                            selectedPlanId = id;
                            planIdInput.value = id || '';
                            planTrigger.classList.remove('error');
                            const p = plans.find(item => item.id === id);
                            if (p) {
                                planTriggerContent.innerHTML = `
                                    <div class="wb-combobox-avatar" style="width: 22px; height: 22px; border-width: 1px;">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="3"></rect><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                    </div>
                                    <span style="font-weight: 600; color: var(--ink); font-size: 13.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        ${escapeHtml(p.name)}
                                    </span>
                                    <span style="font-size: 12px; font-weight: 700; color: var(--lime); margin-left: auto; padding-right: 4px;">
                                        ${escapeHtml(p.formatted_price)}
                                    </span>
                                `;
                            } else {
                                planTriggerContent.innerHTML = `
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.6; flex-shrink: 0;"><rect x="3" y="4" width="18" height="16" rx="3"></rect><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                    <span id="planTriggerPlaceholder" style="color: var(--muted); font-size: 14px;">Select Plan...</span>
                                `;
                            }
                            renderPlans();
                        }

                        // Status render
                        function renderStatusList() {
                            statusListContainer.innerHTML = statusOptions.map(opt => {
                                const isSelected = currentStatus === opt.id;
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
                            currentStatus = s;
                            statusInput.value = s;
                            const opt = statusOptions.find(o => o.id === s) || statusOptions[0];
                            statusTriggerContent.innerHTML = `
                                <span class="status-indicator-dot ${opt.dotClass}"></span>
                                <span style="font-weight: 600; color: var(--ink); font-size: 13.5px;">${escapeHtml(opt.label)}</span>
                            `;
                            renderStatusList();
                        }

                        // Coordinated Open / Close
                        function openMemberMenu() {
                            closePlanMenu();
                            closeStatusMenu();
                            document.getElementById('memberComboboxWrap').style.zIndex = '90';
                            menu.style.display = 'block';
                            chevron.style.transform = 'rotate(180deg)';
                            trigger.setAttribute('aria-expanded', 'true');
                            setTimeout(() => searchInput.focus(), 50);
                        }
                        function closeMemberMenu() {
                            document.getElementById('memberComboboxWrap').style.zIndex = '60';
                            menu.style.display = 'none';
                            chevron.style.transform = 'rotate(0deg)';
                            trigger.setAttribute('aria-expanded', 'false');
                            activeIndex = -1;
                        }

                        function openPlanMenu() {
                            closeMemberMenu();
                            closeStatusMenu();
                            document.getElementById('planComboboxWrap').style.zIndex = '90';
                            planMenu.style.display = 'block';
                            planChevron.style.transform = 'rotate(180deg)';
                            planTrigger.setAttribute('aria-expanded', 'true');
                        }
                        function closePlanMenu() {
                            document.getElementById('planComboboxWrap').style.zIndex = '50';
                            planMenu.style.display = 'none';
                            planChevron.style.transform = 'rotate(0deg)';
                            planTrigger.setAttribute('aria-expanded', 'false');
                        }

                        function openStatusMenu() {
                            closeMemberMenu();
                            closePlanMenu();
                            document.getElementById('statusComboboxWrap').style.zIndex = '90';
                            statusMenu.style.display = 'block';
                            statusChevron.style.transform = 'rotate(180deg)';
                            statusTrigger.setAttribute('aria-expanded', 'true');
                        }
                        function closeStatusMenu() {
                            document.getElementById('statusComboboxWrap').style.zIndex = '40';
                            statusMenu.style.display = 'none';
                            statusChevron.style.transform = 'rotate(0deg)';
                            statusTrigger.setAttribute('aria-expanded', 'false');
                        }

                        // Event listeners for triggers
                        trigger.addEventListener('click', (e) => {
                            e.stopPropagation();
                            if (menu.style.display === 'block') closeMemberMenu();
                            else openMemberMenu();
                        });

                        planTrigger.addEventListener('click', (e) => {
                            e.stopPropagation();
                            if (planMenu.style.display === 'block') closePlanMenu();
                            else openPlanMenu();
                        });

                        statusTrigger.addEventListener('click', (e) => {
                            e.stopPropagation();
                            if (statusMenu.style.display === 'block') closeStatusMenu();
                            else openStatusMenu();
                        });

                        clearBtn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            selectMember(null);
                            renderList(searchInput.value);
                        });

                        // Member search input
                        searchInput.addEventListener('input', (e) => {
                            const val = e.target.value;
                            const q = val.trim().toLowerCase();
                            renderList(val);

                            if (debounceTimer) clearTimeout(debounceTimer);
                            if (q.length >= 1) {
                                searchBadge.textContent = 'Searching...';
                                debounceTimer = setTimeout(() => {
                                    fetch('index.php?page=memberships&action=search_members&q=' + encodeURIComponent(q), {
                                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                                    })
                                    .then(r => r.json())
                                    .then(data => {
                                        if (searchInput.value.trim().toLowerCase() !== q) return;
                                        const results = data.results || [];
                                        results.forEach(rm => {
                                            if (!pool.some(m => m.id === rm.id)) {
                                                pool.push(rm);
                                            }
                                        });
                                        renderList(searchInput.value);
                                    })
                                    .catch(() => {});
                                }, 200);
                            }
                        });

                        searchInput.addEventListener('click', (e) => e.stopPropagation());

                        // Outside click
                        document.addEventListener('click', function onDocClick(e) {
                            if (!document.getElementById('addMembershipForm')) {
                                document.removeEventListener('click', onDocClick);
                                return;
                            }
                            const memberWrap = document.getElementById('memberComboboxWrap');
                            const planWrap = document.getElementById('planComboboxWrap');
                            const statusWrap = document.getElementById('statusComboboxWrap');

                            if (memberWrap && !memberWrap.contains(e.target)) closeMemberMenu();
                            if (planWrap && !planWrap.contains(e.target)) closePlanMenu();
                            if (statusWrap && !statusWrap.contains(e.target)) closeStatusMenu();
                        });

                        // Initial render
                        renderList('');
                        renderPlans();
                        renderStatusList();
                    },
                    preConfirm: () => {
                        const form = document.getElementById('addMembershipForm');
                        const memberId = document.getElementById('membershipUserId').value;
                        const planId = document.getElementById('membershipPlanId').value;
                        const startDate = form.start_date.value;

                        let valid = true;
                        if (!memberId) {
                            document.getElementById('memberComboboxTrigger').classList.add('error');
                            valid = false;
                        }
                        if (!planId) {
                            document.getElementById('planComboboxTrigger').classList.add('error');
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
       Custom Combobox for Member Selection (Dark & Light Mode)
       ============================================================ */
        .swal2-popup {
            overflow: visible !important;
        }

        .swal2-popup .swal2-html-container {
            z-index: 50 !important;
            position: relative !important;
            overflow: visible !important;
        }

        .swal2-popup .swal2-actions {
            z-index: 10 !important;
            position: relative !important;
            margin-top: 18px !important;
        }

        .wb-combobox-wrap {
            position: relative;
            width: 100%;
            z-index: 60;
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
            border: 1px solid #cbd5e1;
            box-shadow: 0 16px 36px rgba(0, 0, 0, 0.16), 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .wb-combobox-search-wrap {
            padding: 10px;
            border-bottom: 1px solid #243247;
            background: #0f1724;
        }

        [data-theme="light"] .wb-combobox-search-wrap {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .wb-combobox-search-box {
            position: relative;
            display: flex;
            align-items: center;
        }

        .wb-combobox-search-input {
            width: 100%;
            box-sizing: border-box;
            background: #090f18;
            border: 1px solid #28374d;
            border-radius: 6px;
            padding: 8px 75px 8px 32px;
            color: #f8fafc;
            font-size: 13px;
            outline: none;
            transition: border-color 0.2s;
        }

        .wb-combobox-search-input:focus {
            border-color: var(--lime, #84cc16);
        }

        [data-theme="light"] .wb-combobox-search-input {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #0f172a;
        }

        [data-theme="light"] .wb-combobox-search-input::placeholder {
            color: #94a3b8;
        }

        [data-theme="light"] .wb-combobox-search-input:focus {
            border-color: var(--lime, #65a30d);
        }

        .wb-combobox-list {
            max-height: 175px;
            overflow-y: auto;
            padding: 6px;
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
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #223049;
            color: var(--lime, #84cc16);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            border: 1px solid rgba(132, 204, 22, 0.3);
            flex-shrink: 0;
        }

        [data-theme="light"] .wb-combobox-avatar {
            background: #ecfccb;
            color: #3f6212;
            border-color: #bef264;
        }

        .wb-combobox-highlight {
            background: rgba(132, 204, 22, 0.35);
            color: #ffffff;
            border-radius: 2px;
            padding: 0 2px;
        }

        [data-theme="light"] .wb-combobox-highlight {
            background: rgba(101, 163, 13, 0.25);
            color: #166534;
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

        /* Modal Date Input */
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

        .wb-modal-date-input:focus {
            border-color: var(--lime, #84cc16);
            box-shadow: 0 0 0 2px rgba(132, 204, 22, 0.2);
        }

        [data-theme="light"] .wb-modal-date-input {
            background: #ffffff !important;
            border-color: #cbd5e1 !important;
            color: #0f172a !important;
        }

        [data-theme="light"] .wb-modal-date-input:focus {
            border-color: var(--lime, #65a30d) !important;
            box-shadow: 0 0 0 2px rgba(101, 163, 13, 0.18) !important;
        }
    </style>
<?php
    render_footer();
}
