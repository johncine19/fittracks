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
                <div style="text-align:center;margin-bottom:30px">
                    <div class="sk sk-rect" style="width:120px;height:32px;border-radius:16px;margin:0 auto 16px"></div>
                    <div class="sk sk-title" style="width:340px;height:32px;margin:0 auto 12px"></div>
                    <div class="sk sk-text" style="width:480px;height:14px;margin:0 auto"></div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:28px;margin-bottom:40px">
                    <?php for($i=0;$i<3;$i++): ?>
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
        <?php endif; endif; ?>

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
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 16px;"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
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
                            } elseif ($duration >= 80 && $duration <= 100) {
                                $period = '/quarter';
                            } elseif ($duration === 30 || $duration === 31) {
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
                                    <?php if ($isActive): ?>
                                        <span class="member-current-badge">CURRENT</span>
                                    <?php endif; ?>
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
                                                class="member-pricing-btn member-popular-btn"
                                                onclick="subscribePlan(<?= (int)$plan['plan_id'] ?>, '<?= htmlspecialchars($plan['plan_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($formattedPrice, ENT_QUOTES) ?>', true)">
                                            Renew Plan
                                        </button>
                                    <?php else: ?>
                                        <button type="button" 
                                                class="member-pricing-btn <?= $isPopular ? 'member-popular-btn' : 'member-standard-btn' ?>"
                                                onclick="subscribePlan(<?= (int)$plan['plan_id'] ?>, '<?= htmlspecialchars($plan['plan_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($formattedPrice, ENT_QUOTES) ?>', false)">
                                            Get Started
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <script>
            function subscribePlan(planId, planName, planPrice, isCurrent) {
                const actionWord = isCurrent ? 'Renew' : 'Subscribe to';
                const btnWord = isCurrent ? 'Confirm & Renew' : 'Confirm Subscription';
                
                Swal.fire({
                    title: `<span style="font-size:1.35rem;font-weight:800;letter-spacing:-0.3px;">${actionWord} ${planName}</span>`,
                    html: `
                        <p style="margin: 0 0 18px 0; font-size: 14px; color: var(--muted); line-height: 1.5;">
                            You are selecting the <strong style="color:var(--ink);">${planName}</strong> plan for <strong style="color:var(--lime, #22c55e); font-size: 16px;">${planPrice}</strong>.
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
                cancelButtonColor: 'var(--line)',
                background: 'var(--panel)',
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

    <style>
    /* ============================================================
       Membership 3-Card Subscription Pricing Aesthetic
       ============================================================ */
    .membership-plans-hero-wrap {
        position: relative;
        margin: -10px -10px 40px -10px;
        padding: 48px 24px 56px;
        border-radius: 28px;
        background-color: #07090d;
        background-image: 
            radial-gradient(ellipse at 50% 30%, rgba(132, 204, 22, 0.14) 0%, transparent 65%),
            linear-gradient(180deg, rgba(7, 9, 13, 0.82) 0%, rgba(9, 12, 18, 0.94) 100%),
            url('assets/images/loginback.png?v=3');
        background-size: cover;
        background-position: center center;
        border: 1px solid rgba(255, 255, 255, 0.08);
        box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.8);
        overflow: hidden;
    }

    [data-theme="light"] .membership-plans-hero-wrap {
        background-color: #f8fafc;
        background-image: 
            radial-gradient(ellipse at 50% 20%, rgba(34, 197, 94, 0.08) 0%, transparent 70%),
            linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        border: 1px solid #e2e8f0;
        box-shadow: 0 15px 40px -10px rgba(0, 0, 0, 0.05);
    }

    .membership-hero-header {
        text-align: center;
        margin-bottom: 44px;
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
        color: #ffffff;
    }

    [data-theme="light"] .membership-brand-title {
        color: #0f172a;
    }

    .membership-hero-title {
        font-size: 2.5rem;
        font-weight: 800;
        margin: 8px 0 14px;
        color: #ffffff;
        letter-spacing: -0.6px;
        line-height: 1.2;
    }

    [data-theme="light"] .membership-hero-title {
        color: #0f172a;
    }

    .membership-hero-subtitle {
        color: rgba(226, 232, 240, 0.75);
        font-size: 1.1rem;
        max-width: 620px;
        margin: 0 auto;
        line-height: 1.55;
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
    }

    .member-pricing-card {
        background: rgba(15, 21, 18, 0.88);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 24px;
        padding: 38px 30px;
        display: flex;
        flex-direction: column;
        position: relative;
        transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.25s ease, border-color 0.25s ease;
        box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.7);
    }

    .member-pricing-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 25px 55px -10px rgba(0, 0, 0, 0.85);
        border-color: rgba(255, 255, 255, 0.2);
    }

    .member-pricing-card.popular {
        border: 2px solid var(--lime, #84cc16);
        background: rgba(16, 25, 20, 0.92);
        box-shadow: 0 0 35px rgba(132, 204, 22, 0.18), 0 25px 55px -10px rgba(0, 0, 0, 0.85);
    }

    .member-pricing-card.popular:hover {
        box-shadow: 0 0 45px rgba(132, 204, 22, 0.28), 0 30px 65px -10px rgba(0, 0, 0, 0.9);
    }

    [data-theme="light"] .member-pricing-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.06);
    }

    [data-theme="light"] .member-pricing-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.12);
        border-color: #cbd5e1;
    }

    [data-theme="light"] .member-pricing-card.popular {
        border: 2px solid var(--lime, #22c55e);
        background: #ffffff;
        box-shadow: 0 0 35px rgba(34, 197, 94, 0.15), 0 15px 35px -5px rgba(0, 0, 0, 0.08);
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

    @media (max-width: 960px) {
        .member-pricing-grid {
            grid-template-columns: 1fr;
            max-width: 440px;
        }
        .membership-hero-title {
            font-size: 1.9rem;
        }
        .membership-plans-hero-wrap {
            padding: 36px 16px 40px;
        }
    }
    </style>
    <?php
    render_footer();
}
