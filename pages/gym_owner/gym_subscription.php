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

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $selectedKey = strtolower(trim((string) post('plan_key')));
        $paymentMethod = trim((string) post('payment_method')) ?: 'gcash';

        if (!isset($plans[$selectedKey])) {
            flash('Please select a valid subscription plan.', 'danger');
            redirect('gym_subscription');
        }

        $plan = $plans[$selectedKey];
        $planName = $plan['name'];
        $amount = (float) $plan['price'];
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+1 month'));
        $receiptNumber = 'SUB-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

        try {
            $pdo->beginTransaction();

            // 1. Record subscription payment
            $stmt = $pdo->prepare('
                INSERT INTO gym_subscription_payments 
                (gym_id, owner_user_id, plan_name, amount, billing_cycle, payment_method, status, receipt_number, payment_date, start_date, end_date)
                VALUES (?, ?, ?, ?, "monthly", ?, "paid", ?, NOW(), ?, ?)
            ');
            $stmt->execute([
                $gym['gym_id'],
                $user['user_id'],
                $planName,
                $amount,
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
                json_encode(['plan' => $planName, 'amount' => $amount, 'receipt' => $receiptNumber])
            );

            notify_admins(
                'system',
                'New Subscription Payment',
                "{$gym['name']} subscribed to the {$planName} Plan (" . money($amount) . ") via " . strtoupper($paymentMethod) . "."
            );

            notify_user(
                (int)$user['user_id'],
                'system',
                'Subscription Activated',
                "Your {$planName} plan is now active until " . date('M j, Y', strtotime($endDate)) . ". Receipt: {$receiptNumber}."
            );

            flash("Your {$planName} subscription has been activated! Welcome to your gym dashboard.", 'success');
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
    ?>
    <section style="padding: 40px 20px; max-width: 1200px; margin: 0 auto;">
        <div style="text-align: center; margin-bottom: 40px;">
            <a class="brand" href="index.php" style="margin-bottom: 20px; display: inline-flex; align-items: center; gap: 10px; text-decoration: none;">
                <div style="width:36px;height:36px;background:var(--lime, #22c55e);border-radius:6px;display:flex;align-items:center;justify-content:center;color:#0b110e;font-weight:900;font-size:18px;">FT</div>
                <span style="font-weight:700;font-size:1.4rem;line-height:1;letter-spacing:-0.2px;color:var(--ink, #fff);">FitTrack</span>
            </a>
            <h1 style="font-size: 2.4rem; font-weight: 800; margin: 10px 0; color: #fff; letter-spacing: -0.5px;">
                Choose Your Subscription Plan
            </h1>
            <p style="color: var(--muted, #94a3b8); font-size: 1.1rem; max-width: 600px; margin: 0 auto; line-height: 1.5;">
                Select the plan that fits your gym operations. You can upgrade, downgrade, or renew at any time.
            </p>
        </div>

        <div class="pricing-grid">
            <?php foreach ($plans as $key => $plan): 
                $isPopular = $plan['popular'];
                $isCurrent = ($hasActiveSub && $currentPlan === $key);
            ?>
                <div class="pricing-card <?= $isPopular ? 'popular' : '' ?>">
                    <?php if ($isPopular): ?>
                        <div class="popular-badge">MOST POPULAR</div>
                    <?php endif; ?>

                    <h2 class="pricing-title <?= $isPopular ? 'popular-title' : '' ?>">
                        <?= h($plan['name']) ?>
                        <?php if ($isCurrent): ?>
                            <span class="current-badge">CURRENT</span>
                        <?php endif; ?>
                    </h2>
                    <p class="pricing-desc"><?= h($plan['desc']) ?></p>

                    <div class="pricing-price">
                        <span class="amount"><?= h($plan['price_label']) ?></span>
                        <span class="period">/mo</span>
                    </div>

                    <ul class="pricing-features">
                        <?php foreach ($plan['features'] as $feat): ?>
                            <li>
                                <span class="checkmark">✓</span>
                                <span><?= h($feat) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div style="margin-top: auto; padding-top: 24px;">
                        <button type="button" 
                                class="pricing-btn <?= $isPopular ? 'popular-btn' : 'standard-btn' ?>"
                                onclick="openPaymentModal('<?= h($key) ?>', '<?= h($plan['name']) ?>', '<?= h($plan['price_label']) ?>')">
                            <?= $isCurrent ? 'Renew Plan' : 'Get Started' ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="text-align: center; margin-top: 40px; padding-bottom: 20px;">
            <?php if ($hasActiveSub): ?>
                <a href="index.php?page=dashboard" style="color: var(--muted, #94a3b8); text-decoration: none; font-size: 14px; margin-right: 20px; transition: color 0.2s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='var(--muted)'">
                    ← Back to Dashboard
                </a>
            <?php endif; ?>
            <a href="index.php?page=logout" style="color: var(--muted, #94a3b8); text-decoration: none; font-size: 14px; transition: color 0.2s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='var(--muted)'">
                Sign Out
            </a>
        </div>
    </section>

    <!-- Modal for Payment Method Selection -->
    <div id="payment-modal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.8); backdrop-filter:blur(6px); align-items:center; justify-content:center; padding:20px;">
        <div style="background:#121815; border:1px solid rgba(255,255,255,0.1); border-radius:16px; max-width:440px; width:100%; padding:28px; box-shadow:0 20px 50px rgba(0,0,0,0.6); position:relative;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h3 style="margin:0; font-size:1.3rem; color:#fff;" id="modal-plan-title">Subscribe to Plan</h3>
                <button type="button" onclick="closePaymentModal()" style="background:none; border:none; color:var(--muted, #94a3b8); font-size:24px; cursor:pointer; line-height:1;">&times;</button>
            </div>

            <p style="color:var(--muted, #94a3b8); font-size:14px; margin-bottom:20px;">
                You are subscribing to the <strong id="modal-plan-name" style="color:#fff;"></strong> plan at <strong id="modal-plan-price" style="color:var(--lime, #22c55e);"></strong> per month.
            </p>

            <form method="post" id="sub-form">
                <?= csrf_field() ?>
                <input type="hidden" name="plan_key" id="form-plan-key" value="">

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
        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 28px;
            align-items: stretch;
        }

        .pricing-card {
            background: #0f1512;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 36px 30px;
            display: flex;
            flex-direction: column;
            position: relative;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .pricing-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.4);
        }

        .pricing-card.popular {
            border: 2px solid var(--lime, #22c55e);
            box-shadow: 0 0 35px rgba(34, 197, 94, 0.15);
        }

        .popular-badge {
            position: absolute;
            top: -14px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--lime, #22c55e);
            color: #0b110e;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.8px;
            padding: 5px 16px;
            border-radius: 20px;
            text-transform: uppercase;
        }

        .current-badge {
            display: inline-block;
            background: rgba(34, 197, 94, 0.15);
            color: var(--lime, #22c55e);
            font-size: 10px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 4px;
            margin-left: 8px;
            vertical-align: middle;
        }

        .pricing-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: #ffffff;
            margin: 0 0 10px 0;
            letter-spacing: -0.3px;
        }

        .pricing-title.popular-title {
            color: var(--lime, #22c55e);
        }

        .pricing-desc {
            color: var(--muted, #94a3b8);
            font-size: 14px;
            line-height: 1.4;
            margin: 0 0 24px 0;
            min-height: 40px;
        }

        .pricing-price {
            display: flex;
            align-items: baseline;
            margin-bottom: 28px;
            padding-bottom: 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }

        .pricing-price .amount {
            font-size: 2.8rem;
            font-weight: 900;
            color: #ffffff;
            letter-spacing: -1px;
            line-height: 1;
        }

        .pricing-price .period {
            font-size: 1rem;
            color: var(--muted, #94a3b8);
            margin-left: 4px;
            font-weight: 500;
        }

        .pricing-features {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .pricing-features li {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14.5px;
            color: #e2e8f0;
            line-height: 1.4;
        }

        .pricing-features .checkmark {
            color: var(--lime, #22c55e);
            font-weight: 900;
            font-size: 15px;
            flex-shrink: 0;
        }

        .pricing-btn {
            width: 100%;
            padding: 14px 20px;
            border-radius: 30px;
            font-size: 15px;
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
            background: var(--lime, #22c55e);
            border: none;
            color: #0b110e;
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3);
        }

        .popular-btn:hover {
            background: #1eb854;
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.4);
        }

        @media (max-width: 960px) {
            .pricing-grid {
                grid-template-columns: 1fr;
                max-width: 440px;
                margin: 0 auto;
            }
        }
    </style>

    <script>
        function openPaymentModal(key, name, price) {
            document.getElementById('form-plan-key').value = key;
            document.getElementById('modal-plan-title').textContent = 'Subscribe to ' + name;
            document.getElementById('modal-plan-name').textContent = name;
            document.getElementById('modal-plan-price').textContent = price;
            const modal = document.getElementById('payment-modal');
            modal.style.display = 'flex';
        }

        function closePaymentModal() {
            document.getElementById('payment-modal').style.display = 'none';
        }

        window.onclick = function(event) {
            const modal = document.getElementById('payment-modal');
            if (event.target === modal) {
                closePaymentModal();
            }
        };
    </script>
    <?php
    render_footer();
}
