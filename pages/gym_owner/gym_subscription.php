<?php
declare(strict_types=1);

function gym_subscription_page(): void
{
    if (!defined('AUTH_PAGE')) define('AUTH_PAGE', true);

    $user = current_user();
    if (!$user || $user['role'] !== 'gym_owner') {
        redirect('login');
    }

    $pdo = db();
    $gym = $pdo->query('SELECT * FROM gyms WHERE owner_user_id = ' . (int)$user['user_id'])->fetch(PDO::FETCH_ASSOC);

    if (!$gym) {
        redirect('gym_onboarding');
    }
    if ($gym['status'] === 'pending') {
        redirect('gym_pending');
    }
    if ($gym['status'] === 'rejected') {
        redirect('gym_rejected');
    }

    $plans = get_platform_subscription_plans();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_csrf();
        $selectedKey = strtolower(trim((string) post('plan_key')));
        $billingCycle = strtolower(trim((string) post('billing_cycle')));
        if ($billingCycle !== 'yearly') {
            $billingCycle = 'monthly';
        }
        $paymentMethod = trim((string) post('payment_method')) ?: 'gcash';

        if (!isset($plans[$selectedKey])) {
            flash('Please select a valid subscription plan.', 'danger');
            redirect('gym_subscription');
        }

        $plan = $plans[$selectedKey];
        $planName = $plan['name'];
        if ($billingCycle === 'yearly') {
            $amount = (float)($plan['annual_price'] ?? round($plan['price'] * 10, 2));
            $startDate = date('Y-m-d');
            $endDate = date('Y-m-d', strtotime('+1 year'));
        } else {
            $amount = (float) $plan['price'];
            $startDate = date('Y-m-d');
            $endDate = date('Y-m-d', strtotime('+1 month'));
        }
        $receiptNumber = 'SUB-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

        try {
            $pdo->beginTransaction();

            // 1. Record subscription payment
            $stmt = $pdo->prepare('
                INSERT INTO gym_subscription_payments 
                (gym_id, owner_user_id, plan_name, amount, billing_cycle, payment_method, status, receipt_number, payment_date, start_date, end_date)
                VALUES (?, ?, ?, ?, ?, ?, "paid", ?, NOW(), ?, ?)
            ');
            $stmt->execute([
                $gym['gym_id'],
                $user['user_id'],
                $planName,
                $amount,
                $billingCycle,
                $paymentMethod,
                $receiptNumber,
                $startDate,
                $endDate,
            ]);

            // 2. Update gym subscription status and renewal date
            $updateStmt = $pdo->prepare('
                UPDATE gyms 
                SET subscription_plan = ?, 
                    subscription_status = "active", 
                    subscription_renewal_date = ? 
                WHERE gym_id = ?
            ');
            $updateStmt->execute([
                $planName,
                $endDate,
                $gym['gym_id'],
            ]);

            $pdo->commit();

            // 3. Notifications & Audit Log
            audit_log(
                (int)$user['user_id'],
                'subscribe_plan',
                'gym_subscription',
                (string)$gym['gym_id'],
                json_encode(['plan' => $planName, 'billing_cycle' => $billingCycle, 'amount' => $amount, 'receipt' => $receiptNumber])
            );

            notify_admins(
                'system',
                'New Subscription Payment',
                "{$gym['name']} subscribed to the {$planName} Plan (" . money($amount) . " / " . ($billingCycle === 'yearly' ? 'Yearly' : 'Monthly') . ") via " . strtoupper($paymentMethod) . "."
            );

            notify_user(
                (int)$user['user_id'],
                'system',
                'Subscription Activated',
                "Your {$planName} plan (" . ($billingCycle === 'yearly' ? 'Annual' : 'Monthly') . ") is now active until " . date('M j, Y', strtotime($endDate)) . ". Receipt: {$receiptNumber}."
            );

            flash("Your {$planName} (" . ($billingCycle === 'yearly' ? 'Annual' : 'Monthly') . ") subscription has been activated! Welcome to your gym dashboard.", 'success');
            redirect('dashboard');
            return;
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('Failed to process subscription: ' . $e->getMessage(), 'danger');
        }
    }

    render_header('Choose Subscription', $user);
    $currentPlan = strtolower((string)($gym['subscription_plan'] ?? ''));
    $hasActiveSub = ($gym['subscription_status'] === 'active' && !empty($gym['subscription_plan']));
    $trialInfo = gym_trial_info($gym);
    ?>
    <div class="subscription-viewport">
        <section class="subscription-container">
            <div class="subscription-header-block" style="text-align: center; margin-bottom: 22px;">
                <a class="brand" href="index.php" style="margin-bottom: 8px; display: inline-flex; align-items: center; gap: 8px; text-decoration: none;">
                    <div style="width:32px;height:32px;background:var(--lime, #84cc16);border-radius:6px;display:flex;align-items:center;justify-content:center;color:#0b110e;font-weight:900;font-size:16px;">FT</div>
                    <span style="font-weight:700;font-size:1.25rem;line-height:1;letter-spacing:-0.2px;color:#ffffff;">FitTrack</span>
                </a>
                <h1 style="font-size: 1.85rem; font-weight: 800; margin: 4px 0 6px; color: #ffffff; letter-spacing: -0.5px;">
                    Choose Your Subscription Plan
                </h1>
                <p style="color: rgba(226, 232, 240, 0.75); font-size: 0.95rem; max-width: 560px; margin: 0 auto; line-height: 1.45;">
                    Select the plan that fits your gym operations. You can upgrade, downgrade, or renew at any time.
                </p>
            </div>

            <?php if ($trialInfo['is_trial_active']): ?>
                <div style="background: linear-gradient(135deg, rgba(132, 204, 22, 0.15), rgba(16, 185, 129, 0.08)); border: 1px solid rgba(132, 204, 22, 0.4); border-radius: 12px; padding: 14px 20px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 14px;">
                        <div style="width: 40px; height: 40px; background: rgba(132, 204, 22, 0.2); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: var(--lime, #84cc16); font-size: 20px; flex-shrink: 0;">⏱️</div>
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <h3 style="margin: 0; color: #fff; font-size: 1.05rem; font-weight: 700;">Free Trial Active</h3>
                                <span style="background: rgba(132, 204, 22, 0.25); color: var(--lime, #84cc16); font-size: 10.5px; font-weight: 800; padding: 2px 8px; border-radius: 20px; letter-spacing: 0.5px;">
                                    <?= $trialInfo['days_left'] ?> DAY<?= $trialInfo['days_left'] === 1 ? '' : 'S' ?> REMAINING
                                </span>
                            </div>
                            <p style="margin: 3px 0 0; color: rgba(226, 232, 240, 0.75); font-size: 0.88rem; line-height: 1.35;">
                                You are exploring FitTrack with <strong>50 member capacity & 2 trainer slots</strong> until <?= !empty($trialInfo['renewal_date']) ? date('M j, Y', strtotime($trialInfo['renewal_date'])) : 'trial concludes' ?>.
                            </p>
                        </div>
                    </div>
                    <a href="index.php?page=dashboard" class="btn" style="background: rgba(255,255,255,0.08); color: #fff; border: 1px solid rgba(255,255,255,0.15); padding: 7px 16px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; text-decoration: none; white-space: nowrap;">
                        ← Back to Dashboard
                    </a>
                </div>
            <?php elseif ($trialInfo['is_free']): ?>
                <div style="background: rgba(255, 255, 255, 0.03); border: 1px solid var(--line); border-radius: 12px; padding: 14px 20px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 14px;">
                        <div style="width: 40px; height: 40px; background: rgba(255, 255, 255, 0.06); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: var(--ink); font-size: 20px; flex-shrink: 0;">⚡</div>
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <h3 style="margin: 0; color: #fff; font-size: 1.05rem; font-weight: 700;">Free Community Tier (Active)</h3>
                                <span style="background: rgba(255, 255, 255, 0.08); color: var(--muted); font-size: 10.5px; font-weight: 800; padding: 2px 8px; border-radius: 20px; letter-spacing: 0.5px;">
                                    LIMITED CAPACITY (25 MEMBERS)
                                </span>
                            </div>
                            <p style="margin: 3px 0 0; color: rgba(226, 232, 240, 0.75); font-size: 0.88rem; line-height: 1.35;">
                                Upgrade below to add trainers, schedule classes, access advanced analytics, and increase capacity.
                            </p>
                        </div>
                    </div>
                    <a href="index.php?page=dashboard" class="btn" style="background: rgba(255,255,255,0.08); color: #fff; border: 1px solid rgba(255,255,255,0.15); padding: 7px 16px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; text-decoration: none; white-space: nowrap;">
                        ← Back to Dashboard
                    </a>
                </div>
            <?php endif; ?>

            <!-- Billing Cycle Toggle (Monthly vs Annual / Yearly) -->
            <div class="billing-cycle-toggle-wrapper">
                <div class="billing-cycle-toggle" id="billingCycleToggle">
                    <button type="button" class="billing-toggle-btn active" id="btn-cycle-monthly" onclick="setBillingCycle('monthly')">
                        Monthly Billing
                    </button>
                    <button type="button" class="billing-toggle-btn" id="btn-cycle-yearly" onclick="setBillingCycle('yearly')">
                        Annual / Yearly
                        <span class="billing-badge-save">SAVE ~2 MONTHS</span>
                    </button>
                </div>
            </div>

            <div class="pricing-grid">
                <?php foreach ($plans as $key => $plan): 
                    $isPopular = $plan['popular'];
                    $isCurrent = ($hasActiveSub && $currentPlan === $key);
                ?>
                    <div class="pricing-card <?= $isPopular ? 'popular' : '' ?>">
                        <?php if ($isPopular): ?>
                            <div class="popular-badge">★ MOST POPULAR</div>
                        <?php endif; ?>

                        <h2 class="pricing-title <?= $isPopular ? 'popular-title' : '' ?>">
                            <?= h($plan['name']) ?>
                            <?php if ($isCurrent): ?>
                                <span class="current-badge">CURRENT</span>
                            <?php endif; ?>
                        </h2>
                        <p class="pricing-desc"><?= h($plan['desc']) ?></p>

                        <div class="pricing-price">
                            <span class="amount" id="price-<?= h($key) ?>"><?= h($plan['price_label']) ?></span>
                            <span class="period" id="period-<?= h($key) ?>">/mo</span>
                        </div>
                        <div class="annual-savings-note" id="savings-<?= h($key) ?>" style="display: none;">
                            Save <?= h($plan['annual_savings_label'] ?? '₱0') ?> billed annually
                        </div>

                        <?php
                            $allFeatures = $plan['features'] ?? [];
                            $initialFeatures = array_slice($allFeatures, 0, 5);
                            $extraFeatures = array_slice($allFeatures, 5);
                            $hasMore = !empty($extraFeatures);
                        ?>
                        <div class="pricing-features-container">
                            <ul class="pricing-features">
                                <?php foreach ($initialFeatures as $feat): ?>
                                    <li>
                                        <span class="checkmark">✓</span>
                                        <span><?= h($feat) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                            <?php if ($hasMore): ?>
                                <div class="pricing-features-collapsible" id="extra-features-<?= h($key) ?>" style="display: none;">
                                    <ul class="pricing-features pricing-features-extra" style="margin-top: 10px;">
                                        <?php foreach ($extraFeatures as $feat): ?>
                                            <li>
                                                <span class="checkmark">✓</span>
                                                <span><?= h($feat) ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <button type="button" class="pricing-features-toggle-btn" onclick="togglePlanFeatures('<?= h($key) ?>', this)">
                                    <span class="toggle-text">+ <?= count($extraFeatures) ?> more features</span>
                                    <svg class="toggle-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                </button>
                            <?php else: ?>
                                <div class="pricing-features-toggle-placeholder"></div>
                            <?php endif; ?>
                        </div>

                        <div style="margin-top: auto; padding-top: 24px;">
                            <button type="button" 
                                    class="pricing-btn <?= $isPopular ? 'popular-btn' : 'standard-btn' ?>"
                                    onclick="openPaymentModal('<?= h($key) ?>', '<?= h($plan['name']) ?>')">
                                <?= $isCurrent ? 'Renew Plan' : 'Get Started' ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Plan Distribution Trigger CTA Button -->
            <div class="pricing-distribution-cta" style="margin-top: 36px; text-align: center;">
                <button type="button" class="btn-plan-distribution" onclick="openPlanDistributionModal()" id="btn-open-plan-distribution">
                    <span class="btn-distribution-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>
                        </svg>
                    </span>
                    <span class="btn-distribution-text">Compare Complete Plan Distribution & Capabilities</span>
                    <svg class="btn-distribution-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </button>
            </div>

            <div style="text-align: center; margin-top: 40px; padding-bottom: 20px;">
                <?php if (($gym['status'] ?? '') === 'approved'): ?>
                    <a href="index.php?page=dashboard" style="color: #94a3b8; text-decoration: none; font-size: 14px; margin-right: 20px; transition: color 0.2s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='#94a3b8'">
                        ← Back to Dashboard
                    </a>
                <?php endif; ?>
                <a href="index.php?page=logout" style="color: #94a3b8; text-decoration: none; font-size: 14px; transition: color 0.2s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">
                    Sign Out
                </a>
            </div>
        </section>
    </div>

    <!-- ==========================================================================
       SUBSCRIPTION PLAN DISTRIBUTION MODAL
       ========================================================================== -->
    <div class="modal-backdrop" id="planDistributionModalBackdrop" onclick="handleDistributionBackdropClick(event)">
        <div class="modal-card distribution-modal-card">
            <div class="modal-header distribution-modal-header">
                <div>
                    <div class="distribution-eyebrow">
                        <span class="dot"></span>
                        Commercial SaaS Capabilities
                    </div>
                    <h3 class="modal-title">Subscription Plan Distribution</h3>
                </div>
                <button type="button" class="modal-close-btn" onclick="closePlanDistributionModal()" aria-label="Close modal">
                    &times;
                </button>
            </div>

            <div class="modal-body distribution-modal-body">
                <p class="distribution-intro">
                    Review the exact capability distribution across our commercial gym tiers. Choose the plan that aligns with your facility scale and operations.
                </p>

                <!-- Mobile Comparison Controls -->
                <div class="dist-mobile-controls">
                    <div class="dist-swipe-hint">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8L22 12L18 16"/><path d="M6 8L2 12L6 16"/><path d="M2 12H22"/></svg>
                        <span>Swipe horizontally to compare tiers</span>
                    </div>
                    <div class="dist-mobile-tabs">
                        <button type="button" class="dist-mobile-tab-btn active" onclick="jumpToDistTier(0, this)">All Plans</button>
                        <button type="button" class="dist-mobile-tab-btn" onclick="jumpToDistTier(1, this)">Starter</button>
                        <button type="button" class="dist-mobile-tab-btn" onclick="jumpToDistTier(2, this)">★ Pro</button>
                        <button type="button" class="dist-mobile-tab-btn" onclick="jumpToDistTier(3, this)">Business</button>
                    </div>
                </div>

                <div class="distribution-table-wrap" id="distTableWrap">
                    <table class="distribution-table">
                        <thead>
                            <tr>
                                <th class="col-cap">Capability / Module</th>
                                <th class="col-tier starter">
                                    <div class="tier-head">
                                        <span class="tier-name"><?= h($plans['starter']['name'] ?? 'Starter') ?></span>
                                        <span class="tier-price"><?= h($plans['starter']['price_label'] ?? '₱599') ?><small>/mo</small></span>
                                    </div>
                                </th>
                                <th class="col-tier popular">
                                    <div class="tier-head">
                                        <span class="popular-tag">★ Most Popular</span>
                                        <span class="tier-name"><?= h($plans['professional']['name'] ?? 'Professional') ?></span>
                                        <span class="tier-price"><?= h($plans['professional']['price_label'] ?? '₱999') ?><small>/mo</small></span>
                                    </div>
                                </th>
                                <th class="col-tier business">
                                    <div class="tier-head">
                                        <span class="tier-name"><?= h($plans['business']['name'] ?? 'Business') ?></span>
                                        <span class="tier-price"><?= h($plans['business']['price_label'] ?? '₱1,999') ?><small>/mo</small></span>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Category 1 -->
                            <tr class="dist-cat-row">
                                <td colspan="4">01 — Target Profile & Capacity Limits</td>
                            </tr>
                            <tr>
                                <td><strong>Target Facility Scale</strong></td>
                                <td><span class="dist-pill muted">Boutique / Solo</span></td>
                                <td class="col-popular"><span class="dist-pill lime">Growing Commercial</span></td>
                                <td><span class="dist-pill purple">Multi-Branch / Enterprise</span></td>
                            </tr>
                            <tr>
                                <td><strong>Active Member Capacity</strong></td>
                                <td><span class="dist-text-highlight">Up to 100 Members</span></td>
                                <td class="col-popular"><span class="dist-text-lime">Up to 500 Members</span></td>
                                <td><span class="dist-text-purple">Unlimited Members</span></td>
                            </tr>

                            <!-- Category 2 -->
                            <tr class="dist-cat-row">
                                <td colspan="4">02 — Core Operations & Access Control</td>
                            </tr>
                            <tr>
                                <td>Walk-In & Daily Pass Management</td>
                                <td><span class="status-access">✓ Full Access</span></td>
                                <td class="col-popular"><span class="status-access">✓ Full Access</span></td>
                                <td><span class="status-access">✓ Full Access</span></td>
                            </tr>
                            <tr>
                                <td>Dynamic QR Check-in & Attendance</td>
                                <td><span class="status-access">✓ Full Access</span></td>
                                <td class="col-popular"><span class="status-access">✓ Full Access</span></td>
                                <td><span class="status-access">✓ Full Access</span></td>
                            </tr>
                            <tr>
                                <td>Membership Plans & Online GCash Pay</td>
                                <td><span class="status-access">✓ Full Access</span></td>
                                <td class="col-popular"><span class="status-access">✓ Full Access</span></td>
                                <td><span class="status-access">✓ Full Access</span></td>
                            </tr>

                            <!-- Category 3 -->
                            <tr class="dist-cat-row">
                                <td colspan="4">03 — Staff Coaching & Training Programs</td>
                            </tr>
                            <tr>
                                <td>Trainers & Client Assignments</td>
                                <td><span class="status-locked">🔒 Locked</span></td>
                                <td class="col-popular"><span class="status-access">✓ Full Access</span></td>
                                <td><span class="status-access">✓ Full Access</span></td>
                            </tr>
                            <tr>
                                <td>Trainer Commission Tracking & Payouts</td>
                                <td><span class="status-locked">🔒 Locked</span></td>
                                <td class="col-popular"><span class="status-access">✓ Full Access</span></td>
                                <td><span class="status-access">✓ Full Access</span></td>
                            </tr>
                            <tr>
                                <td>Workout Plans & Exercise Library</td>
                                <td><span class="status-locked">🔒 Locked</span></td>
                                <td class="col-popular"><span class="status-access">✓ Full Access</span></td>
                                <td><span class="status-access">✓ Full Access</span></td>
                            </tr>

                            <!-- Category 4 -->
                            <tr class="dist-cat-row">
                                <td colspan="4">04 — Group Classes & Online Booking</td>
                            </tr>
                            <tr>
                                <td>Class Scheduling & Member Bookings</td>
                                <td><span class="dist-pill muted">Basic Schedule</span></td>
                                <td class="col-popular"><span class="status-access">✓ Full Booking + Waitlists</span></td>
                                <td><span class="status-access">✓ Multi-Branch Booking</span></td>
                            </tr>

                            <!-- Category 5 -->
                            <tr class="dist-cat-row">
                                <td colspan="4">05 — Member Retention & Automated Reminders</td>
                            </tr>
                            <tr>
                                <td>Automated Renewal Reminders</td>
                                <td><span class="dist-pill muted">7-Day Alert</span></td>
                                <td class="col-popular"><span class="dist-pill sky">Automated (30d/14d/7d/1d)</span></td>
                                <td><span class="dist-pill purple">Custom Workflows</span></td>
                            </tr>
                            <tr>
                                <td>Member Engagement & Churn Risk Alerts</td>
                                <td><span class="status-locked">🔒 Locked</span></td>
                                <td class="col-popular"><span class="status-access">✓ Churn Risk Alerts</span></td>
                                <td><span class="status-access">✓ Predictive Risk AI</span></td>
                            </tr>

                            <!-- Category 6 -->
                            <tr class="dist-cat-row">
                                <td colspan="4">06 — Financial Reports & Governance</td>
                            </tr>
                            <tr>
                                <td>Dashboard Analytics & Operational KPIs</td>
                                <td><span class="dist-pill muted">Basic KPIs</span></td>
                                <td class="col-popular"><span class="dist-pill sky">Advanced Charts & Trends</span></td>
                                <td><span class="dist-pill purple">Branch Comparisons</span></td>
                            </tr>
                            <tr>
                                <td>Financial Reports & Data CSV Export</td>
                                <td><span class="dist-pill muted">Summary Export</span></td>
                                <td class="col-popular"><span class="dist-pill sky">Advanced Trends & CSV</span></td>
                                <td><span class="dist-pill purple">Consolidated Multi-Branch</span></td>
                            </tr>
                            <tr>
                                <td>Activity & Security Audit History</td>
                                <td><span class="dist-pill muted">Basic Activity Log</span></td>
                                <td class="col-popular"><span class="dist-pill muted">Staff Action History</span></td>
                                <td><span class="status-access">✓ Full Immutable Trail</span></td>
                            </tr>
                            <tr>
                                <td>Custom App Brand Accent & Theme</td>
                                <td><span class="status-locked">🔒 Locked</span></td>
                                <td class="col-popular"><span class="status-locked">🔒 Locked</span></td>
                                <td><span class="status-access">✓ Full Access</span></td>
                            </tr>
                            <tr>
                                <td>Multi-Branch Centralized Portal</td>
                                <td><span class="status-locked">🔒 Locked</span></td>
                                <td class="col-popular"><span class="status-locked">🔒 Locked</span></td>
                                <td><span class="status-access">✓ Full Access</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="distribution-modal-footer">
                <div class="dist-footer-ctas">
                    <button type="button" class="btn btn-outline btn-sm" onclick="closePlanDistributionModal(); openPaymentModal('starter', '<?= h($plans['starter']['name'] ?? 'Starter') ?>', '<?= h($plans['starter']['price_label'] ?? '₱599') ?>')">
                        <span class="btn-text-full">Get <?= h($plans['starter']['name'] ?? 'Starter') ?> (<?= h($plans['starter']['price_label'] ?? '₱599') ?>)</span>
                        <span class="btn-text-mobile">Starter (<?= h($plans['starter']['price_label'] ?? '₱599') ?>)</span>
                    </button>
                    <button type="button" class="btn btn-lime btn-sm" onclick="closePlanDistributionModal(); openPaymentModal('professional', '<?= h($plans['professional']['name'] ?? 'Professional') ?>', '<?= h($plans['professional']['price_label'] ?? '₱999') ?>')">
                        <span class="btn-text-full">Get <?= h($plans['professional']['name'] ?? 'Professional') ?> (<?= h($plans['professional']['price_label'] ?? '₱999') ?>)</span>
                        <span class="btn-text-mobile">★ Pro (<?= h($plans['professional']['price_label'] ?? '₱999') ?>)</span>
                    </button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="closePlanDistributionModal(); openPaymentModal('business', '<?= h($plans['business']['name'] ?? 'Business') ?>', '<?= h($plans['business']['price_label'] ?? '₱1,999') ?>')">
                        <span class="btn-text-full">Get <?= h($plans['business']['name'] ?? 'Business') ?> (<?= h($plans['business']['price_label'] ?? '₱1,999') ?>)</span>
                        <span class="btn-text-mobile">Business (<?= h($plans['business']['price_label'] ?? '₱1,999') ?>)</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Payment Method Selection -->
    <div id="payment-modal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.8); backdrop-filter:blur(6px); align-items:center; justify-content:center; padding:20px;">
        <div style="background:#121815; border:1px solid rgba(255,255,255,0.1); border-radius:16px; max-width:440px; width:100%; padding:28px; box-shadow:0 20px 50px rgba(0,0,0,0.6); position:relative;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h3 style="margin:0; font-size:1.3rem; color:#fff;" id="modal-plan-title">Subscribe to Plan</h3>
                <button type="button" onclick="closePaymentModal()" style="background:none; border:none; color:var(--muted, #94a3b8); font-size:24px; cursor:pointer; line-height:1;">&times;</button>
            </div>

            <p style="color:var(--muted, #94a3b8); font-size:14px; margin-bottom:20px;">
                You are subscribing to the <strong id="modal-plan-name" style="color:#fff;"></strong> plan at <strong id="modal-plan-price" style="color:var(--lime, #22c55e);"></strong> <span id="modal-plan-period" style="color:var(--muted, #94a3b8);">per month</span>.
            </p>

            <form method="post" id="sub-form">
                <?= csrf_field() ?>
                <input type="hidden" name="plan_key" id="form-plan-key" value="">
                <input type="hidden" name="billing_cycle" id="form-billing-cycle" value="monthly">

                <label style="display:block; font-size:12px; font-weight:700; letter-spacing:0.5px; color:var(--muted, #94a3b8); margin-bottom:10px;">
                    SELECT PAYMENT METHOD
                </label>

                <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:24px;">
                    <label style="display:flex; align-items:center; gap:12px; padding:12px 16px; border-radius:8px; border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.02); cursor:pointer;">
                        <input type="radio" name="payment_method" value="gcash" checked style="accent-color:var(--lime, #22c55e);">
                        <span style="font-weight:600; color:#fff;">GCash</span>
                        <span style="margin-left:auto; font-size:12px; color:var(--muted, #94a3b8);">E-Wallet</span>
                    </label>

                    <label style="display:flex; align-items:center; gap:12px; padding:12px 16px; border-radius:8px; border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.02); cursor:pointer;">
                        <input type="radio" name="payment_method" value="card" style="accent-color:var(--lime, #22c55e);">
                        <span style="font-weight:600; color:#fff;">Debit / Credit Card</span>
                        <span style="margin-left:auto; font-size:12px; color:var(--muted, #94a3b8);">Visa / Mastercard</span>
                    </label>

                    <label style="display:flex; align-items:center; gap:12px; padding:12px 16px; border-radius:8px; border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.02); cursor:pointer;">
                        <input type="radio" name="payment_method" value="bank_transfer" style="accent-color:var(--lime, #22c55e);">
                        <span style="font-weight:600; color:#fff;">Bank Transfer</span>
                        <span style="margin-left:auto; font-size:12px; color:var(--muted, #94a3b8);">BDO / BPI / UnionBank</span>
                    </label>

                    <label style="display:flex; align-items:center; gap:12px; padding:12px 16px; border-radius:8px; border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.02); cursor:pointer;">
                        <input type="radio" name="payment_method" value="cash" style="accent-color:var(--lime, #22c55e);">
                        <span style="font-weight:600; color:#fff;">Cash / Over-the-Counter</span>
                        <span style="margin-left:auto; font-size:12px; color:var(--muted, #94a3b8);">Platform Office</span>
                    </label>
                </div>

                <div style="display:flex; gap:12px;">
                    <button type="button" onclick="closePaymentModal()" style="flex:1; padding:12px; border-radius:8px; background:rgba(255,255,255,0.05); color:#fff; border:1px solid rgba(255,255,255,0.1); font-weight:600; cursor:pointer;">
                        Cancel
                    </button>
                    <button type="submit" style="flex:2; padding:12px; border-radius:8px; background:var(--lime, #22c55e); color:#000; border:none; font-weight:700; cursor:pointer;" onclick="this.disabled=true; this.innerHTML='Activating...'; this.form.submit();">
                        Confirm & Activate
                    </button>
                </div>
            </form>
        </div>
    </div>

    <style>
        body {
            background-color: #07090d !important;
            background-image: none !important;
            overflow-x: hidden !important;
        }

        .auth-shell {
            width: 100% !important;
            max-width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            min-height: 100vh !important;
            min-height: 100dvh !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: flex-start !important;
        }

        .subscription-viewport {
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 36px 16px 60px;
            width: 100%;
            position: relative;
            overflow-x: hidden;
            box-sizing: border-box;
        }

        .subscription-container {
            max-width: 1160px;
            width: 100%;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .subscription-viewport::before {
            content: '';
            position: fixed;
            top: -5vh;
            left: -5vw;
            width: 110vw;
            height: 110vh;
            background-image: 
                radial-gradient(ellipse at 50% 50%, rgba(132, 204, 22, 0.12) 0%, transparent 65%),
                linear-gradient(180deg, rgba(7, 9, 13, 0.70) 0%, rgba(9, 12, 18, 0.80) 100%),
                url('assets/images/loginback.png?v=3');
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
            z-index: 0;
            pointer-events: none;
        }

        .billing-cycle-toggle-wrapper {
            display: flex;
            justify-content: center;
            margin-bottom: 24px;
        }

        .billing-cycle-toggle {
            display: inline-flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 9999px;
            padding: 4px;
            gap: 6px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        }

        .billing-toggle-btn {
            background: transparent;
            border: none;
            color: rgba(255, 255, 255, 0.72);
            font-size: 13px;
            font-weight: 600;
            padding: 7px 18px;
            border-radius: 9999px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .billing-toggle-btn:hover {
            color: #ffffff;
        }

        .billing-toggle-btn.active {
            background: var(--lime, #84cc16);
            color: #0b110e;
            font-weight: 800;
            box-shadow: 0 0 16px rgba(132, 204, 22, 0.4);
        }

        .billing-badge-save {
            background: rgba(0, 0, 0, 0.25);
            color: #fff;
            font-size: 9.5px;
            font-weight: 800;
            letter-spacing: 0.5px;
            padding: 2px 7px;
            border-radius: 999px;
        }

        .billing-toggle-btn.active .billing-badge-save {
            background: rgba(11, 17, 14, 0.85);
            color: var(--lime-bright, #a3e635);
        }

        .annual-savings-note {
            font-size: 11.5px;
            color: var(--lime, #84cc16);
            font-weight: 700;
            margin-top: -4px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            align-items: stretch;
        }

        .pricing-card {
            background: rgba(15, 21, 18, 0.90);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 26px 22px;
            display: flex;
            flex-direction: column;
            position: relative;
            transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.25s ease, border-color 0.25s ease;
            box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.7);
        }

        .pricing-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 25px 55px -10px rgba(0, 0, 0, 0.85);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .pricing-card.popular {
            border: 2px solid var(--lime, #84cc16);
            background: rgba(16, 25, 20, 0.94);
            box-shadow: 0 0 35px rgba(132, 204, 22, 0.18), 0 25px 55px -10px rgba(0, 0, 0, 0.85);
        }

        .pricing-card.popular:hover {
            box-shadow: 0 0 45px rgba(132, 204, 22, 0.28), 0 30px 65px -10px rgba(0, 0, 0, 0.9);
        }

        :root {
            --lime: #84cc16;
            --lime-bright: #a3e635;
        }

        .popular-badge {
            position: absolute;
            top: -13px;
            left: 50%;
            transform: translateX(-50%);
            background: #84cc16;
            color: #081202 !important;
            font-size: 10.5px;
            font-weight: 900;
            letter-spacing: 0.08em;
            padding: 4px 14px;
            border-radius: 999px;
            text-transform: uppercase;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.4), 0 0 14px rgba(132, 204, 22, 0.5);
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
            z-index: 5;
            line-height: 1.1;
        }

        .popular-tag {
            font-size: 0.68rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            background: #84cc16;
            color: #081202 !important;
            padding: 3px 8px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-bottom: 2px;
            width: fit-content;
            box-shadow: 0 0 12px rgba(132, 204, 22, 0.45);
            line-height: 1.1;
        }

        .current-badge {
            display: inline-block;
            background: rgba(132, 204, 22, 0.15);
            color: var(--lime-bright, #a3e635);
            font-size: 9.5px;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 4px;
            margin-left: 6px;
            vertical-align: middle;
        }

        .pricing-title {
            font-size: 1.45rem;
            font-weight: 800;
            color: #ffffff;
            margin: 0 0 6px 0;
            letter-spacing: -0.3px;
        }

        .pricing-title.popular-title {
            color: var(--lime-bright, #a3e635);
        }

        .pricing-desc {
            color: var(--muted, #94a3b8);
            font-size: 13px;
            line-height: 1.35;
            margin: 0 0 14px 0;
            min-height: 36px;
        }

        .pricing-price {
            display: flex;
            align-items: baseline;
            margin-bottom: 16px;
            padding-bottom: 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }

        .pricing-price .amount {
            font-size: 2.4rem;
            font-weight: 900;
            color: #ffffff;
            letter-spacing: -0.5px;
            line-height: 1;
        }

        .pricing-price .period {
            font-size: 0.95rem;
            color: var(--muted, #94a3b8);
            margin-left: 4px;
            font-weight: 500;
        }

        .pricing-features-container {
            display: flex;
            flex-direction: column;
        }

        .pricing-features {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 9px;
        }

        .pricing-features li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 13.5px;
            color: #e2e8f0;
            line-height: 1.35;
        }

        .pricing-features .checkmark {
            color: var(--lime, #84cc16);
            font-weight: 900;
            font-size: 14px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .pricing-features-toggle-btn {
            background: transparent;
            border: none;
            color: var(--lime, #84cc16);
            font-size: 0.8rem;
            font-weight: 700;
            padding: 6px 0;
            margin-top: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: color 0.15s ease;
            font-family: inherit;
            text-align: left;
        }

        .pricing-features-toggle-btn:hover {
            color: var(--lime-bright, #a3e635);
        }

        .pricing-features-toggle-btn .toggle-icon {
            transition: transform 0.25s ease;
        }

        .pricing-features-toggle-btn.is-expanded .toggle-icon {
            transform: rotate(180deg);
        }

        .pricing-features-toggle-placeholder {
            height: 26px;
        }

        .pricing-btn {
            width: 100%;
            padding: 12px 18px;
            border-radius: 30px;
            font-size: 14.5px;
            font-weight: 700;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s ease;
            text-decoration: none;
            display: block;
        }

        .standard-btn {
            background: #141a17;
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffffff;
        }

        .standard-btn:hover {
            background: #1a221f;
            border-color: rgba(255, 255, 255, 0.3);
        }

        .popular-btn {
            background: #84cc16;
            border: none;
            color: #081202;
            box-shadow: 0 4px 18px rgba(132, 204, 22, 0.35);
        }

        .popular-btn:hover {
            background: #a3e635;
            box-shadow: 0 6px 24px rgba(132, 204, 22, 0.5);
            color: #081202;
        }

        @media (max-width: 960px) {
            .pricing-grid {
                grid-template-columns: 1fr;
                max-width: 440px;
                margin: 0 auto;
            }
        }

        /* Plan Distribution Trigger CTA Button */
        .pricing-distribution-cta {
            margin-top: 36px;
            text-align: center;
        }

        .btn-plan-distribution {
            background: rgba(18, 25, 21, 0.85);
            border: 1px solid rgba(132, 204, 22, 0.4);
            color: #f1f5f9;
            font-size: 0.92rem;
            font-weight: 700;
            padding: 12px 26px;
            border-radius: 999px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 8px 24px -6px rgba(0, 0, 0, 0.5), 0 0 16px rgba(132, 204, 22, 0.08);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            transition: all 0.25s ease;
        }

        .btn-plan-distribution:hover {
            border-color: var(--lime, #84cc16);
            background: rgba(22, 32, 25, 0.95);
            color: var(--lime, #84cc16);
            transform: translateY(-2px);
            box-shadow: 0 12px 30px -6px rgba(0, 0, 0, 0.6), 0 0 20px rgba(132, 204, 22, 0.25);
        }

        .btn-distribution-icon {
            display: inline-flex;
            color: var(--lime, #84cc16);
        }

        .btn-distribution-arrow {
            transition: transform 0.2s ease;
        }

        .btn-plan-distribution:hover .btn-distribution-arrow {
            transform: translateX(4px);
        }

        /* Distribution Modal Card */
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            box-sizing: border-box;
        }

        .modal-card.distribution-modal-card {
            max-width: 960px;
            width: 95%;
            max-height: min(92vh, 860px);
            background: #0b100d;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 20px;
            box-shadow: 0 35px 80px -15px rgba(0, 0, 0, 0.9);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
        }

        .distribution-modal-header {
            background: linear-gradient(180deg, rgba(132, 204, 22, 0.06) 0%, transparent 100%), #0e1411;
            padding: 1.25rem 2rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .distribution-eyebrow {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: var(--lime, #84cc16);
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 2px;
        }

        .distribution-eyebrow .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--lime, #84cc16);
        }

        .distribution-modal-title, .modal-header .modal-title {
            font-size: 1.25rem;
            font-weight: 800;
            color: #ffffff;
            margin: 0;
        }

        .modal-close-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #94a3b8;
            font-size: 1.25rem;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .modal-close-btn:hover {
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
        }

        .distribution-modal-body {
            padding: 1.5rem 2rem;
            overflow-y: auto;
        }

        .distribution-intro {
            font-size: 0.9rem;
            color: #94a3b8;
            margin-bottom: 1.25rem;
            line-height: 1.5;
        }

        .distribution-table-wrap {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(14, 19, 16, 0.6);
            position: relative;
        }

        .distribution-table {
            width: 100%;
            min-width: 680px;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 0.88rem;
            text-align: left;
        }

        .distribution-table thead th {
            position: sticky;
            top: 0;
            z-index: 4;
        }

        .distribution-table th.col-cap,
        .distribution-table td:first-child {
            position: sticky;
            left: 0;
            z-index: 3;
            background: #0d1310;
            border-right: 1px solid rgba(255, 255, 255, 0.12);
        }

        .distribution-table thead th.col-cap {
            z-index: 6;
            background: #141c17;
        }

        .dist-cat-row td {
            position: sticky;
            left: 0;
            z-index: 2;
        }

        .dist-mobile-controls {
            display: none;
        }

        .btn-text-mobile {
            display: none;
        }

        .btn-text-full {
            display: inline;
        }

        .distribution-table th {
            padding: 14px 18px;
            background: rgba(18, 25, 21, 0.95);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            color: #ffffff;
            vertical-align: bottom;
        }

        .distribution-table .col-cap {
            width: 35%;
            font-weight: 700;
            color: #94a3b8;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .distribution-table .col-tier {
            width: 21.6%;
        }

        .distribution-table .tier-head {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .distribution-table .tier-name {
            font-weight: 800;
            font-size: 1rem;
        }

        .distribution-table .tier-price {
            font-size: 0.85rem;
            color: #94a3b8;
        }

        .distribution-table th.col-tier.popular {
            background: rgba(132, 204, 22, 0.08);
            border-left: 1px solid rgba(132, 204, 22, 0.25);
            border-right: 1px solid rgba(132, 204, 22, 0.25);
        }

        .distribution-table th.col-tier.popular .tier-name {
            color: var(--lime-bright, #a3e635);
        }

        .distribution-table td {
            padding: 12px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            color: #ffffff;
        }

        .distribution-table td.col-popular {
            background: rgba(132, 204, 22, 0.04);
            border-left: 1px solid rgba(132, 204, 22, 0.15);
            border-right: 1px solid rgba(132, 204, 22, 0.15);
        }

        .dist-cat-row td {
            background: rgba(255, 255, 255, 0.03);
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--lime, #84cc16);
            padding: 8px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .dist-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.78rem;
            font-weight: 600;
        }

        .dist-pill.muted {
            background: rgba(255, 255, 255, 0.06);
            color: #cbd5e1;
        }

        .dist-pill.lime {
            background: rgba(132, 204, 22, 0.15);
            color: #bef264;
        }

        .dist-pill.sky {
            background: rgba(56, 189, 248, 0.15);
            color: #7dd3fc;
        }

        .dist-pill.purple {
            background: rgba(168, 85, 247, 0.15);
            color: #d8b4fe;
        }

        .status-access {
            color: #4ade80;
            font-weight: 600;
            font-size: 0.82rem;
        }

        .status-locked {
            color: #f87171;
            font-size: 0.82rem;
        }

        .dist-text-highlight {
            color: #f8fafc;
            font-weight: 600;
        }

        .dist-text-lime {
            color: var(--lime, #84cc16);
            font-weight: 700;
        }

        .dist-text-purple {
            color: #c084fc;
            font-weight: 700;
        }

        .distribution-modal-footer {
            padding: 1.25rem 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: #0e1411;
            display: flex;
            justify-content: flex-end;
        }

        .dist-footer-ctas {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            width: 100%;
            justify-content: flex-end;
        }

        .dist-footer-ctas .btn {
            padding: 8px 18px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.85rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .dist-footer-ctas .btn-outline {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #ffffff;
        }

        .dist-footer-ctas .btn-outline:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.35);
        }

        .dist-footer-ctas .btn-lime {
            background: var(--lime, #84cc16);
            border: 1px solid var(--lime, #84cc16);
            color: #081202;
            box-shadow: 0 0 15px rgba(132, 204, 22, 0.3);
        }

        .dist-footer-ctas .btn-lime:hover {
            background: var(--lime-bright, #a3e635);
            border-color: var(--lime-bright, #a3e635);
            box-shadow: 0 0 20px rgba(132, 204, 22, 0.45);
        }

        @media (max-width: 768px) {
            .modal-card.distribution-modal-card {
                width: 95% !important;
                max-width: 95% !important;
                max-height: 92vh !important;
                border-radius: 16px !important;
                margin: auto;
            }

            .distribution-modal-header {
                padding: 0.85rem 1.15rem;
            }

            .distribution-modal-header .modal-title {
                font-size: 1.15rem !important;
            }

            .distribution-modal-body {
                padding: 0.85rem 1rem;
            }

            .distribution-intro {
                font-size: 0.78rem;
                margin-bottom: 0.75rem;
                line-height: 1.4;
            }

            .dist-mobile-controls {
                display: flex;
                flex-direction: column;
                gap: 8px;
                margin-bottom: 10px;
            }

            .dist-swipe-hint {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                font-size: 0.72rem;
                font-weight: 700;
                color: var(--lime, #84cc16);
                background: rgba(132, 204, 22, 0.1);
                border: 1px solid rgba(132, 204, 22, 0.25);
                padding: 4px 10px;
                border-radius: 999px;
                width: fit-content;
            }

            .dist-mobile-tabs {
                display: flex;
                gap: 4px;
                background: rgba(255, 255, 255, 0.04);
                padding: 3px;
                border-radius: 8px;
                border: 1px solid rgba(255, 255, 255, 0.08);
            }

            .dist-mobile-tab-btn {
                flex: 1;
                background: transparent;
                border: none;
                color: var(--text-dim, #94a3b8);
                font-size: 0.72rem;
                font-weight: 700;
                padding: 6px 4px;
                border-radius: 6px;
                cursor: pointer;
                transition: all 0.15s ease;
                text-align: center;
                font-family: inherit;
                white-space: nowrap;
            }

            .dist-mobile-tab-btn.active {
                background: var(--lime, #84cc16);
                color: #0b110e;
                box-shadow: 0 0 10px rgba(132, 204, 22, 0.35);
            }

            .distribution-table-wrap {
                border-radius: 10px;
                max-height: 52vh;
            }

            .distribution-table {
                min-width: 520px;
                font-size: 0.78rem;
            }

            .distribution-table th.col-cap,
            .distribution-table td:first-child {
                min-width: 140px;
                max-width: 140px;
                padding: 9px 10px !important;
                font-size: 0.76rem !important;
                line-height: 1.3;
                box-shadow: 3px 0 8px rgba(0, 0, 0, 0.6);
            }

            .distribution-table th.col-tier,
            .distribution-table td:not(:first-child) {
                min-width: 120px;
                padding: 9px 8px !important;
                text-align: center;
            }

            .distribution-table .tier-name {
                font-size: 0.88rem;
            }

            .distribution-table .tier-price {
                font-size: 0.75rem;
            }

            .distribution-table .popular-tag {
                font-size: 0.6rem;
                padding: 2px 6px;
            }

            .dist-pill {
                font-size: 0.7rem;
                padding: 2px 6px;
                line-height: 1.2;
            }

            .status-access, .status-locked {
                font-size: 0.75rem;
            }

            .dist-cat-row td {
                font-size: 0.65rem;
                padding: 6px 10px !important;
            }

            .distribution-modal-footer {
                padding: 0.75rem 1rem;
            }

            .dist-footer-ctas {
                display: grid;
                grid-template-columns: 1fr 1.25fr 1fr;
                gap: 6px;
                width: 100%;
            }

            .dist-footer-ctas .btn {
                padding: 8px 4px !important;
                font-size: 0.72rem !important;
                font-weight: 700;
                text-align: center;
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .btn-text-full {
                display: none !important;
            }

            .btn-text-mobile {
                display: inline !important;
            }
        }
    </style>

    <script>
        const subscriptionPlansData = <?= json_encode($plans, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        let activeCycle = 'monthly';

        function setBillingCycle(cycle) {
            activeCycle = (cycle === 'yearly') ? 'yearly' : 'monthly';
            const monthlyBtn = document.getElementById('btn-cycle-monthly');
            const yearlyBtn = document.getElementById('btn-cycle-yearly');
            if (monthlyBtn && yearlyBtn) {
                monthlyBtn.classList.toggle('active', activeCycle === 'monthly');
                yearlyBtn.classList.toggle('active', activeCycle === 'yearly');
            }

            for (const [key, plan] of Object.entries(subscriptionPlansData)) {
                const priceEl = document.getElementById('price-' + key);
                const periodEl = document.getElementById('period-' + key);
                const savingsEl = document.getElementById('savings-' + key);
                if (priceEl && periodEl) {
                    if (activeCycle === 'yearly') {
                        priceEl.textContent = plan.annual_price_label || ('₱' + Math.round(plan.annual_price).toLocaleString());
                        periodEl.textContent = '/yr';
                        if (savingsEl) savingsEl.style.display = 'block';
                    } else {
                        priceEl.textContent = plan.price_label;
                        periodEl.textContent = '/mo';
                        if (savingsEl) savingsEl.style.display = 'none';
                    }
                }
            }
        }

        function togglePlanFeatures(key, btn) {
            const el = document.getElementById('extra-features-' + key);
            if (!el) return;
            const isHidden = el.style.display === 'none' || el.style.display === '';
            const plan = subscriptionPlansData[key];
            const extraCount = plan && plan.features ? Math.max(0, plan.features.length - 5) : '';

            if (isHidden) {
                el.style.display = 'block';
                btn.classList.add('is-expanded');
                const textEl = btn.querySelector('.toggle-text');
                if (textEl) textEl.textContent = 'Show fewer features';
            } else {
                el.style.display = 'none';
                btn.classList.remove('is-expanded');
                const textEl = btn.querySelector('.toggle-text');
                if (textEl) textEl.textContent = '+ ' + extraCount + ' more features';
            }
        }

        function openPaymentModal(key, name) {
            const plan = subscriptionPlansData[key];
            if (!plan) return;
            const price = (activeCycle === 'yearly') 
                ? (plan.annual_price_label || ('₱' + Math.round(plan.annual_price).toLocaleString())) 
                : plan.price_label;
            const periodText = (activeCycle === 'yearly') ? 'per year (Annual)' : 'per month (Monthly)';

            document.getElementById('form-plan-key').value = key;
            document.getElementById('form-billing-cycle').value = activeCycle;
            document.getElementById('modal-plan-title').textContent = 'Subscribe to ' + name;
            document.getElementById('modal-plan-name').textContent = name;
            document.getElementById('modal-plan-price').textContent = price;
            const periodEl = document.getElementById('modal-plan-period');
            if (periodEl) periodEl.textContent = periodText;
            const modal = document.getElementById('payment-modal');
            if (modal) modal.style.display = 'flex';
        }

        function closePaymentModal() {
            document.getElementById('payment-modal').style.display = 'none';
        }

        function openPlanDistributionModal() {
            const modal = document.getElementById('planDistributionModalBackdrop');
            if (modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
        }

        function closePlanDistributionModal() {
            const modal = document.getElementById('planDistributionModalBackdrop');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
        }

        function jumpToDistTier(tierIndex, btn) {
            const wrap = document.getElementById('distTableWrap');
            if (!wrap) return;

            document.querySelectorAll('.dist-mobile-tab-btn').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');

            if (tierIndex === 0) {
                wrap.scrollTo({ left: 0, behavior: 'smooth' });
                return;
            }

            const headerCells = wrap.querySelectorAll('.distribution-table thead th');
            if (headerCells && headerCells[tierIndex]) {
                const targetTh = headerCells[tierIndex];
                const capColWidth = headerCells[0].offsetWidth || 140;
                const scrollTarget = targetTh.offsetLeft - capColWidth;
                wrap.scrollTo({ left: Math.max(0, scrollTarget), behavior: 'smooth' });
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const wrap = document.getElementById('distTableWrap');
            if (!wrap) return;

            let isScrolling = false;
            wrap.addEventListener('scroll', function() {
                if (window.innerWidth > 768 || isScrolling) return;
                const sl = wrap.scrollLeft;
                const tabs = document.querySelectorAll('.dist-mobile-tab-btn');
                if (!tabs || tabs.length < 4) return;

                if (sl < 40) {
                    tabs.forEach((t, i) => t.classList.toggle('active', i === 0));
                } else if (sl < 160) {
                    tabs.forEach((t, i) => t.classList.toggle('active', i === 1));
                } else if (sl < 280) {
                    tabs.forEach((t, i) => t.classList.toggle('active', i === 2));
                } else {
                    tabs.forEach((t, i) => t.classList.toggle('active', i === 3));
                }
            }, { passive: true });
        });

        function handleDistributionBackdropClick(event) {
            if (event.target === document.getElementById('planDistributionModalBackdrop')) {
                closePlanDistributionModal();
            }
        }

        window.onclick = function(event) {
            const paymentModal = document.getElementById('payment-modal');
            const distModal = document.getElementById('planDistributionModalBackdrop');
            if (event.target === paymentModal) {
                closePaymentModal();
            }
            if (event.target === distModal) {
                closePlanDistributionModal();
            }
        };
    </script>
    <?php
    render_footer();
}
