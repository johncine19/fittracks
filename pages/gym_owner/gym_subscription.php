<?php
declare(strict_types=1);

function gym_subscription_page(): void
{
    if (!defined('AUTH_PAGE')) define('AUTH_PAGE', true);

    $user = current_user();
    if (!$user) {
        $_SESSION['return_to'] = 'gym_subscription';
        redirect('login');
    }
    if ($user['role'] !== 'gym_owner') {
        redirect('dashboard');
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

    if (function_exists('auto_expire_lapsed_gym_subscriptions')) {
        auto_expire_lapsed_gym_subscriptions((int)$gym['gym_id']);
        // Refresh gym state after auto-expiry check
        $gym = $pdo->query('SELECT * FROM gyms WHERE owner_user_id = ' . (int)$user['user_id'])->fetch(PDO::FETCH_ASSOC);
    }

    $plans = get_platform_subscription_plans();
    $currentPlan = strtolower((string)($gym['subscription_plan'] ?? ''));
    $gymRenewalDate = !empty($gym['subscription_renewal_date']) ? (string)$gym['subscription_renewal_date'] : null;
    $isGymExpired = !empty($gymRenewalDate) && strtotime($gymRenewalDate) < strtotime('today');
    $hasActiveSub = ($gym['subscription_status'] === 'active' && !empty($gym['subscription_plan']) && !$isGymExpired);
    $gymDaysLeft = null;
    if ($hasActiveSub && $gymRenewalDate) {
        $gymDaysLeft = (int)ceil((strtotime($gymRenewalDate) - strtotime('today')) / 86400);
    }

    $planRank = [];
    $pRankIndex = 1;
    foreach ($plans as $pk => $pVal) {
        $planRank[$pk] = $pRankIndex++;
    }
    $currentPlanRank = $planRank[$currentPlan] ?? 0;

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_csrf();
        $selectedKey = strtolower(trim((string) post('plan_key')));
        $billingCycle = strtolower(trim((string) post('billing_cycle')));
        if ($billingCycle !== 'yearly') {
            $billingCycle = 'monthly';
        }
        if (!isset($plans[$selectedKey])) {
            flash('Please select a valid subscription plan.', 'danger');
            redirect('gym_subscription');
        }

        $plan = $plans[$selectedKey];
        $planName = $plan['name'];

        // Guardrail: Disallow early renewal of the same plan if > 7 days remain
        if ($hasActiveSub && $currentPlan === $selectedKey && $gymDaysLeft !== null && $gymDaysLeft > 7) {
            flash("Your {$planName} subscription is active with {$gymDaysLeft} days remaining. Renewals open 7 days before your renewal date (" . date('M j, Y', strtotime($gymRenewalDate)) . ").", 'warning');
            redirect('gym_subscription');
        }

        // Two-Way Guardrail: Disallow downgrade while active plan has > 7 days remaining
        $selectedPlanRank = $planRank[$selectedKey] ?? 0;
        if ($hasActiveSub && $currentPlanRank > 0 && $selectedPlanRank < $currentPlanRank && $gymDaysLeft !== null && $gymDaysLeft > 7) {
            $curName = $plans[$currentPlan]['name'] ?? 'current tier';
            flash("You currently have an active {$curName} subscription with {$gymDaysLeft} days remaining. Downgrading to {$planName} is only available within 7 days of your renewal date (" . date('M j, Y', strtotime($gymRenewalDate)) . ") so you don't forfeit your {$curName} features early.", 'warning');
            redirect('gym_subscription');
        }

        $amount = $billingCycle === 'yearly'
            ? (float)($plan['annual_price'] ?? round($plan['price'] * 10, 2))
            : (float)$plan['price'];
        $paymentRowId = null;

        try {
            if ((string)app_env('XENDIT_SECRET_KEY') === '') {
                throw new RuntimeException('Xendit is not configured.');
            }

            // Guardrail: Cancel any older pending payments for this gym to prevent checkout spam / stacking
            $pdo->prepare('UPDATE gym_subscription_payments SET status = "failed" WHERE gym_id = ? AND status = "pending"')
                ->execute([(int)$gym['gym_id']]);

            $referenceId = 'FTSUB-' . strtoupper(bin2hex(random_bytes(16)));
            $periodEnd = $billingCycle === 'yearly' ? '+1 year' : '+1 month';
            $stmt = $pdo->prepare('
                INSERT INTO gym_subscription_payments 
                (gym_id, owner_user_id, plan_name, amount, billing_cycle, payment_method, status, receipt_number, start_date, end_date, xendit_reference_id)
                VALUES (?, ?, ?, ?, ?, "online", "pending", NULL, ?, ?, ?)
            ');
            $stmt->execute([
                $gym['gym_id'],
                $user['user_id'],
                $planName,
                $amount,
                $billingCycle,
                date('Y-m-d'),
                date('Y-m-d', strtotime($periodEnd)),
                $referenceId,
            ]);
            $paymentRowId = (int)$pdo->lastInsertId();

            $checkout = xendit_create_checkout_session([
                'xendit_reference_id' => $referenceId,
                'amount' => $amount,
                'plan_name' => $planName,
                'billing_cycle' => $billingCycle,
            ], $user, (string)$gym['name']);
            $pdo->prepare('UPDATE gym_subscription_payments SET xendit_session_id = ? WHERE id = ? AND status = "pending"')
                ->execute([$checkout['payment_session_id'], $paymentRowId]);

            header('Location: ' . $checkout['payment_link_url'], true, 303);
            exit;
        } catch (Throwable $e) {
            if ($paymentRowId !== null) {
                $pdo->prepare('UPDATE gym_subscription_payments SET status = "failed" WHERE id = ? AND status = "pending"')
                    ->execute([$paymentRowId]);
            }
            error_log('Could not start gym subscription checkout: ' . $e->getMessage());
            $errorMsg = $e->getMessage();
            if ($errorMsg === 'Xendit is not configured.') {
                flash('Payment gateway is not configured yet (missing XENDIT_SECRET_KEY in server environment). Please contact system administrator.', 'danger');
            } else {
                flash('We could not start secure checkout: ' . htmlspecialchars($errorMsg) . '. Please try again or contact support.', 'danger');
            }
        }
    }

    if (isset($_GET['checkout'], $_GET['ref']) && in_array($_GET['checkout'], ['returned', 'cancelled'], true)) {
        $statusStmt = $pdo->prepare('SELECT status FROM gym_subscription_payments WHERE xendit_reference_id = ? AND gym_id = ? AND owner_user_id = ? LIMIT 1');
        $statusStmt->execute([(string)$_GET['ref'], (int)$gym['gym_id'], (int)$user['user_id']]);
        $checkoutStatus = $statusStmt->fetchColumn();
        if ($checkoutStatus === 'paid') {
            flash('Payment confirmed. Your subscription is active.', 'success');
        } elseif ($checkoutStatus === 'pending') {
            flash('Checkout returned. We are waiting for Xendit to confirm your payment; this page will not activate the plan until confirmation arrives.', 'info');
        } elseif ($_GET['checkout'] === 'cancelled') {
            flash('Checkout was cancelled. Your plan was not changed.', 'warning');
        }
    }

    render_header('Choose Subscription', $user);
    $currentPlan = strtolower((string)($gym['subscription_plan'] ?? ''));
    $isGymExpired = !empty($gym['subscription_renewal_date']) && strtotime((string)$gym['subscription_renewal_date']) < strtotime('today');
    $hasActiveSub = ($gym['subscription_status'] === 'active' && !empty($gym['subscription_plan']) && !$isGymExpired);
    $trialInfo = gym_trial_info($gym);
    ?>
    <link rel="stylesheet" href="<?= h(asset_url('css/pages/gym_subscription.css')) ?>">
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
                    $planTierRank = $planRank[$key] ?? 0;
                    $isDowngrade = ($hasActiveSub && $currentPlanRank > 0 && $planTierRank < $currentPlanRank);
                    $isUpgrade = ($hasActiveSub && $currentPlanRank > 0 && $planTierRank > $currentPlanRank);
                    $isRenewalLocked = ($isCurrent && $gymDaysLeft !== null && $gymDaysLeft > 7);
                    $isDowngradeLocked = ($isDowngrade && $gymDaysLeft !== null && $gymDaysLeft > 7);
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
                            <?php if ($isRenewalLocked): ?>
                                <button type="button" 
                                        class="pricing-btn current-locked-btn"
                                        disabled
                                        title="Renewals open 7 days before expiration"
                                        style="background: rgba(255,255,255,0.06); color: rgba(226,232,240,0.5); border: 1px solid rgba(255,255,255,0.1); cursor: not-allowed; width: 100%; box-shadow: none;">
                                    Active (<?= $gymDaysLeft ?> days left)
                                </button>
                                <div style="font-size: 0.78rem; color: rgba(226, 232, 240, 0.55); margin-top: 6px; text-align: center;">
                                    Renewals open on <?= date('M j, Y', strtotime('-7 days', strtotime($gymRenewalDate))) ?>
                                </div>
                            <?php elseif ($isDowngradeLocked): ?>
                                <button type="button" 
                                        class="pricing-btn current-locked-btn"
                                        disabled
                                        title="Downgrades open 7 days before expiration"
                                        style="background: rgba(255,255,255,0.06); color: rgba(226,232,240,0.5); border: 1px solid rgba(255,255,255,0.1); cursor: not-allowed; width: 100%; box-shadow: none;">
                                    Active on <?= h($plans[$currentPlan]['name'] ?? 'Higher Tier') ?>
                                </button>
                                <div style="font-size: 0.78rem; color: rgba(226, 232, 240, 0.55); margin-top: 6px; text-align: center;">
                                    Downgrades open on <?= date('M j, Y', strtotime('-7 days', strtotime($gymRenewalDate))) ?>
                                </div>
                            <?php else: ?>
                                <button type="button" 
                                        class="pricing-btn <?= $isPopular ? 'popular-btn' : 'standard-btn' ?>"
                                        onclick="openPaymentModal('<?= h($key) ?>', '<?= h($plan['name']) ?>')">
                                    <?php if ($isCurrent): ?>
                                        Renew Plan <?= $gymDaysLeft !== null ? '(' . max(0, $gymDaysLeft) . 'd left)' : '' ?>
                                    <?php elseif ($isUpgrade): ?>
                                        Upgrade to <?= h($plan['name']) ?>
                                    <?php elseif ($isDowngrade): ?>
                                        Switch to <?= h($plan['name']) ?>
                                    <?php elseif ($hasActiveSub): ?>
                                        Switch to <?= h($plan['name']) ?>
                                    <?php else: ?>
                                        Get Started
                                    <?php endif; ?>
                                </button>
                            <?php endif; ?>
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
                                <td><span class="dist-pill purple">High-Volume Gym</span></td>
                            </tr>
                            <tr>
                                <td><strong>Active Member Capacity</strong></td>
                                <td><span class="dist-text-highlight">Up to 100 Members</span></td>
                                <td class="col-popular"><span class="dist-text-lime">Up to 500 Members</span></td>
                                <td><span class="dist-text-purple">Unlimited Members</span></td>
                            </tr>
                            <tr>
                                <td><strong>Trainer Capacity</strong></td>
                                <td><span class="status-locked">Locked</span></td>
                                <td class="col-popular"><span class="dist-text-lime">Unlimited Trainers</span></td>
                                <td><span class="dist-text-purple">Unlimited Trainers</span></td>
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
                                <td><span class="status-access">✓ Full Booking + Waitlists</span></td>
                            </tr>

                            <!-- Category 5 -->
                            <tr class="dist-cat-row">
                                <td colspan="4">05 — Member Retention & Automated Reminders</td>
                            </tr>
                            <tr>
                                <td>Automated Renewal Reminders</td>
                                <td><span class="dist-pill muted">7-Day Alert</span></td>
                                <td class="col-popular"><span class="dist-pill sky">Automated (30d/14d/7d/1d)</span></td>
                                <td><span class="dist-pill purple">Automated (30d/14d/7d/1d)</span></td>
                            </tr>
                            <tr>
                                <td>Member Engagement Scores & Inactivity Alerts</td>
                                <td><span class="status-locked">🔒 Locked</span></td>
                                <td class="col-popular"><span class="status-access">✓ Engagement Scores & Inactivity Alerts</span></td>
                                <td><span class="status-access">✓ Engagement Scores & Inactivity Alerts</span></td>
                            </tr>

                            <!-- Category 6 -->
                            <tr class="dist-cat-row">
                                <td colspan="4">06 — Financial Reports & Governance</td>
                            </tr>
                            <tr>
                                <td>Dashboard Analytics & Operational KPIs</td>
                                <td><span class="dist-pill muted">Basic KPIs</span></td>
                                <td class="col-popular"><span class="dist-pill sky">Advanced Charts & Trends</span></td>
                                <td><span class="dist-pill purple">Advanced Charts & Trends</span></td>
                            </tr>
                            <tr>
                                <td>Financial Reports & Data CSV Export</td>
                                <td><span class="dist-pill muted">Summary Export</span></td>
                                <td class="col-popular"><span class="dist-pill sky">Advanced Trends & CSV</span></td>
                                <td><span class="dist-pill purple">Advanced Trends & CSV</span></td>
                            </tr>
                            <tr>
                                <td>End-of-Day Summary Emails</td>
                                <td><span class="status-locked">Locked</span></td>
                                <td class="col-popular"><span class="status-locked">Locked</span></td>
                                <td><span class="status-access">✓ Available</span></td>
                            </tr>
                            <tr>
                                <td>Custom Gym Brand Color</td>
                                <td><span class="status-locked">🔒 Locked</span></td>
                                <td class="col-popular"><span class="status-locked">🔒 Locked</span></td>
                                <td><span class="status-access">✓ Full Access</span></td>
                            </tr>
                            <tr>
                                <td>Staff Financial Visibility Controls</td>
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
    <div id="payment-modal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(4,7,12,0.85); backdrop-filter:blur(8px); align-items:center; justify-content:center; padding:20px;">
        <div style="background:#0d1117; border:1px solid rgba(255,255,255,0.14); border-radius:18px; max-width:460px; width:100%; padding:28px 26px; box-shadow:0 25px 60px rgba(0,0,0,0.85), 0 0 40px rgba(132,204,22,0.06); position:relative; color:#f1f5f9;">
            <!-- Header -->
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:14px; border-bottom:1px solid rgba(255,255,255,0.08);">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:10px; background:rgba(132,204,22,0.12); color:#84cc16;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </span>
                    <h3 style="margin:0; font-size:1.25rem; font-weight:800; color:#ffffff; letter-spacing:-0.02em;" id="modal-plan-title">Subscribe to Plan</h3>
                </div>
                <button type="button" onclick="closePaymentModal()" style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:#cbd5e1; width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center; font-size:18px; cursor:pointer; line-height:1; transition:all 0.15s ease;" onmouseover="this.style.color='#fff'; this.style.background='rgba(255,255,255,0.12)'" onmouseout="this.style.color='#cbd5e1'; this.style.background='rgba(255,255,255,0.06)'">&times;</button>
            </div>

            <!-- Plan Summary Card -->
            <div style="background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.09); border-radius:14px; padding:16px 18px; margin-bottom:18px; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.08em; color:#94a3b8; font-weight:700; margin-bottom:4px;">Selected Plan</div>
                    <div style="font-size:1.15rem; font-weight:800; color:#ffffff;" id="modal-plan-name">Plan</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:1.35rem; font-weight:900; color:#84cc16; letter-spacing:-0.02em;" id="modal-plan-price">₱0</div>
                    <div style="font-size:12px; font-weight:600; color:#94a3b8;" id="modal-plan-period">per month</div>
                </div>
            </div>

            <!-- Upgrade Notice Warning Banner (Shown only during upgrade from active plan) -->
            <div id="modal-upgrade-warning" style="display:none; margin:0 0 18px; padding:12px 14px; border-radius:12px; background:rgba(234,179,8,0.12); border:1px solid rgba(234,179,8,0.3); color:#fef08a; font-size:12.5px; line-height:1.45;">
                <div style="font-weight:700; display:flex; align-items:center; gap:6px; margin-bottom:4px; color:#fde047;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <span>Plan Upgrade Notice</span>
                </div>
                <span id="modal-upgrade-warning-text"></span>
            </div>

            <form method="post" id="sub-form">
                <?= csrf_field() ?>
                <input type="hidden" name="plan_key" id="form-plan-key" value="">
                <input type="hidden" name="billing_cycle" id="form-billing-cycle" value="monthly">

                <!-- Secure Checkout Notice Box -->
                <div style="margin:0 0 24px; padding:14px 16px; border-radius:12px; background:rgba(15,23,42,0.65); border:1px solid rgba(255,255,255,0.08); display:flex; gap:12px; align-items:flex-start;">
                    <span style="color:#84cc16; flex-shrink:0; margin-top:2px;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </span>
                    <div style="color:#cbd5e1; font-size:13px; line-height:1.5;">
                        <span style="color:#ffffff; font-weight:700; display:block; margin-bottom:2px;">Redirect to Secure Checkout</span>
                        You will be directed to Xendit to choose your preferred payment method (GCash, Maya, Cards, or Bank Transfer). Your subscription activates immediately upon payment.
                    </div>
                </div>

                <!-- Action Buttons -->
                <div style="display:flex; gap:12px;">
                    <button type="button" onclick="closePaymentModal()" style="flex:1; padding:12px 16px; border-radius:10px; background:rgba(255,255,255,0.06); color:#f1f5f9; border:1px solid rgba(255,255,255,0.12); font-weight:700; font-size:14px; cursor:pointer; transition:all 0.15s ease;" onmouseover="this.style.background='rgba(255,255,255,0.12)'" onmouseout="this.style.background='rgba(255,255,255,0.06)'">
                        Cancel
                    </button>
                    <button type="submit" style="flex:2; padding:12px 18px; border-radius:10px; background:#84cc16; color:#081202; border:none; font-weight:800; font-size:14px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:8px; box-shadow:0 4px 16px rgba(132,204,22,0.35); transition:all 0.15s ease;" onmouseover="this.style.background='#a3e635'; this.style.transform='translateY(-1px)'" onmouseout="this.style.background='#84cc16'; this.style.transform='none'">
                        <span>Continue to Xendit</span>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php
    $lockedPlansInfo = [];
    foreach ($plans as $pk => $pData) {
        $pRank = $planRank[$pk] ?? 0;
        if ($hasActiveSub && $gymDaysLeft !== null && $gymDaysLeft > 7) {
            if ($pk === $currentPlan) {
                $lockedPlansInfo[$pk] = [
                    'type' => 'renewal',
                    'title' => 'Plan Active',
                    'reason' => "Your {$pData['name']} plan is currently active with {$gymDaysLeft} days remaining. Renewals open 7 days prior to expiry (" . date('M j, Y', strtotime('-7 days', strtotime($gymRenewalDate))) . ")."
                ];
            } elseif ($pRank < $currentPlanRank) {
                $curName = $plans[$currentPlan]['name'] ?? 'current tier';
                $lockedPlansInfo[$pk] = [
                    'type' => 'downgrade',
                    'title' => 'Downgrade Locked',
                    'reason' => "You currently have an active {$curName} subscription with {$gymDaysLeft} days remaining. Downgrades to {$pData['name']} open 7 days before your renewal date (" . date('M j, Y', strtotime('-7 days', strtotime($gymRenewalDate))) . ") so you don't lose {$curName} capabilities early."
                ];
            }
        }
    }
    ?>
    <script>
        window.subscriptionPlansData = <?= json_encode($plans, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        window.currentPlanKey = <?= json_encode($currentPlan) ?>;
        window.planRanks = <?= json_encode($planRank) ?>;
        window.hasActiveSub = <?= $hasActiveSub ? 'true' : 'false' ?>;
        window.isCurrentPlanLocked = <?= ($hasActiveSub && $gymDaysLeft !== null && $gymDaysLeft > 7) ? 'true' : 'false' ?>;
        window.currentPlanDaysLeft = <?= json_encode($gymDaysLeft) ?>;
        window.currentPlanRenewalDate = <?= json_encode($gymRenewalDate) ?>;
        window.lockedPlans = <?= json_encode($lockedPlansInfo, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    </script>
    <script src="<?= h(asset_url('js/pages/gym_subscription.js')) ?>"></script>
    <?php
    render_footer();
}
