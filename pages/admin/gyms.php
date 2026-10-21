<?php
declare(strict_types=1);

function gyms_page(): void
{
    $user = require_roles(['platform_admin']);
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $gymId = (int) post('gym_id');
        $action = post('action');

        if ($action === 'suspend') {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE gyms SET status = "suspended" WHERE gym_id = ?')->execute([$gymId]);
            $pdo->prepare('UPDATE users SET status = "suspended" WHERE user_id = (SELECT owner_user_id FROM gyms WHERE gym_id = ?)')
                ->execute([$gymId]);
            $pdo->commit();
            flash('Gym suspended successfully.', 'success');
        } elseif ($action === 'reactivate') {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE gyms SET status = "approved" WHERE gym_id = ?')->execute([$gymId]);
            $pdo->prepare('UPDATE users SET status = "active" WHERE user_id = (SELECT owner_user_id FROM gyms WHERE gym_id = ?)')
                ->execute([$gymId]);
            $pdo->commit();
            flash('Gym reactivated.', 'success');
        } elseif ($action === 'delete') {
            $pdo->prepare('DELETE FROM gyms WHERE gym_id = ?')->execute([$gymId]);
            flash('Gym deleted successfully.', 'success');
        } elseif ($action === 'update_subscription') {
            $plan = trim((string) post('subscription_plan'));
            if ($plan === '' || strtolower($plan) === 'none') {
                $plan = null;
            }
            $subStatus = trim((string) post('subscription_status')) ?: 'inactive';
            $billingCycle = strtolower(trim((string) post('subscription_billing_cycle'))) === 'yearly' ? 'yearly' : 'monthly';
            $renewalDate = trim((string) post('subscription_renewal_date')) ?: null;
            $recordPayment = (post('record_payment') === '1');
            $amount = (float) post('amount');
            $paymentMethod = trim((string) post('payment_method')) ?: 'cash';

            // Guardrail: if active or trialing, ensure the renewal date is a safe future date so the gym does not immediately lapse
            if ($subStatus === 'active') {
                if (empty($renewalDate) || strtotime($renewalDate) < strtotime('today')) {
                    $renewalDate = date('Y-m-d', strtotime($billingCycle === 'yearly' ? '+1 year' : '+1 month'));
                }
            } elseif ($subStatus === 'trialing') {
                if (empty($renewalDate) || strtotime($renewalDate) < strtotime('today')) {
                    $renewalDate = date('Y-m-d', strtotime('+14 days'));
                }
            }

            $pdo->beginTransaction();

            $pdo->prepare('UPDATE gyms SET subscription_plan = ?, subscription_status = ?, subscription_renewal_date = ?, subscription_billing_cycle = ? WHERE gym_id = ?')
                ->execute([$plan, $subStatus, $renewalDate, $billingCycle, $gymId]);

            $ownerId = (int) $pdo->query('SELECT owner_user_id FROM gyms WHERE gym_id = ' . $gymId)->fetchColumn();
            $gymName = (string) $pdo->query('SELECT name FROM gyms WHERE gym_id = ' . $gymId)->fetchColumn();

            if ($recordPayment && $plan && $amount > 0) {
                $receiptNumber = 'SUB-ADM-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
                $startDate = date('Y-m-d');
                $endDate = $renewalDate ?: date('Y-m-d', strtotime($billingCycle === 'yearly' ? '+1 year' : '+1 month'));

                $pdo->prepare('
                    INSERT INTO gym_subscription_payments 
                    (gym_id, owner_user_id, plan_name, amount, billing_cycle, payment_method, status, receipt_number, payment_date, start_date, end_date)
                    VALUES (?, ?, ?, ?, ?, ?, "paid", ?, NOW(), ?, ?)
                ')->execute([
                    $gymId,
                    $ownerId,
                    $plan,
                    $amount,
                    $billingCycle,
                    $paymentMethod,
                    $receiptNumber,
                    $startDate,
                    $endDate
                ]);
            }

            $pdo->commit();

            audit_log($user['user_id'], 'update_subscription', 'gym', (string) $gymId, json_encode([
                'plan' => $plan,
                'status' => $subStatus,
                'billing_cycle' => $billingCycle,
                'renewal_date' => $renewalDate,
                'recorded_payment' => $recordPayment ? $amount : false
            ]));

            $cycleLabel = $billingCycle === 'yearly' ? 'Annual' : 'Monthly';
            notify_user(
                $ownerId,
                'system',
                'Subscription Updated',
                "Your subscription for '{$gymName}' has been updated by Platform Admin to " . ($plan ? "{$plan} Plan ({$cycleLabel}, {$subStatus})" : "No Plan") . "."
            );

            flash("Subscription for '{$gymName}' updated successfully.", 'success');
        } elseif ($action === 'update_payout') {
            $xenditSubaccountId = trim((string) post('xendit_subaccount_id'));
            $xenditSubaccountId = $xenditSubaccountId !== '' ? $xenditSubaccountId : null;
            $payoutChannel = trim((string) post('payout_channel')) ?: 'gcash';
            $payoutNumber = trim((string) post('payout_account_number')) ?: null;
            $payoutName = trim((string) post('payout_account_name')) ?: null;

            $pdo->prepare('UPDATE gyms SET xendit_subaccount_id = ?, payout_channel = ?, payout_account_number = ?, payout_account_name = ? WHERE gym_id = ?')
                ->execute([$xenditSubaccountId, $payoutChannel, $payoutNumber, $payoutName, $gymId]);

            $ownerId = (int) $pdo->query('SELECT owner_user_id FROM gyms WHERE gym_id = ' . $gymId)->fetchColumn();
            $gymName = (string) $pdo->query('SELECT name FROM gyms WHERE gym_id = ' . $gymId)->fetchColumn();

            audit_log($user['user_id'], 'update_payout_settings', 'gym', (string) $gymId, json_encode([
                'xendit_subaccount_id' => $xenditSubaccountId,
                'payout_channel' => $payoutChannel,
                'payout_account_number' => $payoutNumber,
                'payout_account_name' => $payoutName
            ]));

            notify_user(
                $ownerId,
                'system',
                'Payout Destination Updated',
                "Platform Admin updated payout destination settings for '{$gymName}' (" . strtoupper($payoutChannel) . ($payoutNumber ? " - {$payoutNumber}" : "") . ")."
            );

            flash("Payout settings for '{$gymName}' updated successfully.", 'success');
        }
        redirect('gyms');
    }

    // Auto-sync any expired subscriptions immediately so records and view stay accurate
    auto_expire_lapsed_gym_subscriptions();

    $gyms = $pdo->query('
        SELECT g.*, u.first_name, u.last_name, u.email 
        FROM gyms g 
        JOIN users u ON u.user_id = g.owner_user_id 
        WHERE g.status IN ("approved", "suspended") 
        ORDER BY g.created_at DESC
    ')->fetchAll();

    render_header('All Gyms', $user);
?>
    <style>
    /* Desktop vs Mobile Toggle */
    .gyms-desktop-table {
        display: block;
    }
    .gyms-mobile-cards {
        display: none;
    }

    /* Keep page header title inline / unstacked on all screens */
    .gyms-page-header h1,
    .gyms-page-title {
        white-space: nowrap;
        font-size: clamp(1.15rem, 5vw, 1.75rem);
        letter-spacing: -0.02em;
        line-height: 1.25;
        margin: 0 0 4px;
    }

    @media (max-width: 768px) {
        .gyms-desktop-table {
            display: none !important;
        }
        .gyms-mobile-cards {
            display: flex !important;
            flex-direction: column;
            gap: 12px;
        }
        .gyms-page-header h1,
        .gyms-page-title {
            white-space: nowrap;
            font-size: clamp(1.02rem, 4.5vw, 1.35rem);
        }
    }

    @media (max-width: 420px) {
        .gyms-page-header h1,
        .gyms-page-title {
            white-space: nowrap;
            font-size: clamp(0.9rem, 4.2vw, 1.15rem);
        }
    }

    /* Mobile Gym Card Styles */
    .gym-card-item {
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
    html[data-theme="light"] .gym-card-item,
    [data-theme="light"] .gym-card-item {
        background: #ffffff;
        border-color: #e2e8f0;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
    }
    .gym-card-item:hover {
        border-color: color-mix(in srgb, var(--lime) 30%, transparent);
    }

    .gym-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 10px;
        padding-bottom: 10px;
        border-bottom: 1px solid color-mix(in srgb, var(--line) 60%, transparent);
    }
    .gym-card-identity {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 0;
    }
    .gym-card-avatar {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        background: color-mix(in srgb, var(--lime) 15%, transparent);
        color: var(--lime);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
    }
    html[data-theme="light"] .gym-card-avatar,
    [data-theme="light"] .gym-card-avatar {
        background: rgba(132, 204, 22, 0.15);
        color: #4d7c0f;
        border-color: rgba(132, 204, 22, 0.3);
    }
    .gym-card-names {
        min-width: 0;
        overflow: hidden;
    }
    .gym-card-title {
        font-weight: 700;
        font-size: 15px;
        color: var(--ink);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .gym-card-id {
        font-size: 12px;
        color: var(--muted);
        font-family: ui-monospace, monospace;
    }
    .gym-card-badges {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 4px;
        flex-shrink: 0;
    }

    .gym-card-details {
        display: grid;
        grid-template-columns: 1fr;
        gap: 8px;
        font-size: 13px;
    }
    .gym-card-detail-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        min-width: 0;
    }
    .gym-card-detail-label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: var(--muted);
        font-size: 12px;
        flex-shrink: 0;
    }
    .gym-card-detail-label svg {
        opacity: 0.75;
    }
    .gym-card-detail-value {
        color: var(--ink);
        font-weight: 500;
        text-align: right;
        word-break: break-all;
        max-width: 65%;
    }
    .gym-card-detail-value.email-value {
        color: var(--muted);
        font-size: 12.5px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 68%;
        display: block;
        user-select: all;
    }

    .gym-card-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        padding-top: 10px;
        border-top: 1px solid color-mix(in srgb, var(--line) 60%, transparent);
    }
    .gym-card-actions .btn-sm,
    .gym-card-actions form {
        flex: 1;
        min-width: 0;
        margin: 0;
    }
    .gym-card-actions form {
        display: flex;
    }
    .gym-card-actions .btn-sm,
    .gym-card-actions form button {
        width: 100%;
        display: inline-flex;
        justify-content: center;
        align-items: center;
        gap: 5px;
        padding: 7px 10px;
        font-size: 12px;
        font-weight: 600;
        border-radius: 6px;
        text-align: center;
        white-space: nowrap;
        box-sizing: border-box;
    }
    </style>

    <section class="panel">
        <div class="page-header gyms-page-header">
            <div>
                <h1 class="gyms-page-title">All Gyms & Subscriptions</h1>
                <p>Manage registered gym facilities, accounts, and subscription tiers.</p>
            </div>
        </div>

        <?php if (!$gyms): ?>
            <div class="empty-state" style="padding: 40px 20px; text-align: center;">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M3 7v14M21 7v14M6 11h4M6 15h4M14 11h4M14 15h4M9 21v-4h6v4M3 7l9-4 9 4"/></svg>
                <p style="margin-top: 10px; color: var(--muted);">No active or suspended gyms found.</p>
            </div>
        <?php else: ?>
        <!-- Desktop / Tablet Table View (>= 769px) -->
        <div class="gyms-desktop-table table-container">
            <table>
                <thead>
                    <tr>
                        <th>Gym Name</th>
                        <th>Member Rating</th>
                        <th>Owner</th>
                        <th>Email</th>
                        <th>Facility Status</th>
                        <th>Subscription Plan</th>
                        <th>Sub Status</th>
                        <th>Renewal Date</th>
                        <th style="width: 270px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gyms as $gym): 
                        $subPlan = $gym['subscription_plan'] ?: 'None';
                        $subStatus = $gym['subscription_status'] ?: 'inactive';
                        $renewal = !empty($gym['subscription_renewal_date']) ? date('M j, Y', strtotime($gym['subscription_renewal_date'])) : '—';
                        $isRenewalPassed = !empty($gym['subscription_renewal_date']) && strtotime($gym['subscription_renewal_date']) < strtotime('today');
                        if ($isRenewalPassed && in_array(strtolower($subStatus), ['active', 'trialing'], true)) {
                            $subStatus = 'expired';
                        }
                        $planBadgeStyle = match(strtolower($subPlan)) {
                            'starter' => 'background: rgba(59, 130, 246, 0.15); color: #60a5fa;',
                            'professional' => 'background: rgba(34, 197, 94, 0.15); color: var(--lime);',
                            'business' => 'background: rgba(168, 85, 247, 0.15); color: #c084fc;',
                            default => 'background: rgba(148, 163, 184, 0.1); color: var(--muted);'
                        };
                        $statusBadgeClass = match(strtolower($subStatus)) {
                            'active' => 'badge-active',
                            'expired' => 'badge-inactive',
                            default => 'badge-pending'
                        };
                        $gStats = get_gym_rating_stats((int)$gym['gym_id']);
                    ?>
                        <tr>
                            <td><strong><?= h($gym['name']) ?></strong></td>
                            <td>
                                <a href="index.php?page=gym_profile&gym_id=<?= (int)$gym['gym_id'] ?>#sec-ratings" style="text-decoration:none; display:inline-flex; align-items:center; gap:5px;" title="View member ratings &amp; reviews">
                                    <span style="color:#f59e0b; font-weight:700; font-size:13px;">★ <?= number_format($gStats['avg_rating'], 1) ?></span>
                                    <span style="font-size:11px; color:var(--muted);">(<?= $gStats['total_reviews'] ?>)</span>
                                </a>
                            </td>
                            <td><?= h($gym['first_name'] . ' ' . $gym['last_name']) ?></td>
                            <td><?= h($gym['email']) ?></td>
                            <td>
                                <?php if ($gym['status'] === 'approved'): ?>
                                    <span class="badge badge-active">Approved</span>
                                <?php elseif ($gym['status'] === 'suspended'): ?>
                                    <span class="badge badge-inactive" style="color:var(--danger)">Suspended</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge" style="<?= $planBadgeStyle ?>; font-weight:700;">
                                    <?= h($subPlan) ?>
                                    <?php if (in_array(strtolower($subPlan), ['starter', 'professional', 'business'], true)): ?>
                                        <span style="font-size: 10px; opacity: 0.85; font-weight: 600; text-transform: uppercase;">(<?= ($gym['subscription_billing_cycle'] ?? 'monthly') === 'yearly' ? 'Yearly' : 'Monthly' ?>)</span>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $statusBadgeClass ?>">
                                    <?= h(ucfirst($subStatus)) ?>
                                </span>
                            </td>
                            <td><small style="color:var(--muted);"><?= h($renewal) ?></small></td>
                            <td>
                                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                                    <button type="button" 
                                            class="btn-sm btn-primary" 
                                            style="padding: 4px 10px; font-size: 12px; font-weight: 700;"
                                            data-gym-id="<?= (int)$gym['gym_id'] ?>"
                                            data-gym-name="<?= h($gym['name']) ?>"
                                            data-owner-name="<?= h($gym['first_name'] . ' ' . $gym['last_name']) ?>"
                                            data-plan="<?= h($gym['subscription_plan'] ?? '') ?>"
                                            data-status="<?= h($subStatus) ?>"
                                            data-renewal="<?= h($gym['subscription_renewal_date'] ?? '') ?>"
                                            data-cycle="<?= h($gym['subscription_billing_cycle'] ?? 'monthly') ?>"
                                            onclick="handleEditSubClick(this)">
                                        Edit Sub
                                    </button>

                                    <button type="button" 
                                            class="btn-sm btn-secondary" 
                                            style="padding: 4px 10px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;"
                                            data-gym-id="<?= (int)$gym['gym_id'] ?>"
                                            data-gym-name="<?= h($gym['name']) ?>"
                                            data-owner-name="<?= h($gym['first_name'] . ' ' . $gym['last_name']) ?>"
                                            data-xendit-account="<?= h((string)($gym['xendit_subaccount_id'] ?? '')) ?>"
                                            data-payout-channel="<?= h((string)($gym['payout_channel'] ?? 'gcash')) ?>"
                                            data-payout-number="<?= h((string)($gym['payout_account_number'] ?? '')) ?>"
                                            data-payout-name="<?= h((string)($gym['payout_account_name'] ?? '')) ?>"
                                            onclick="handleEditPayoutClick(this)">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                                        Payout
                                    </button>

                                    <?php if ($gym['status'] === 'approved'): ?>
                                        <form method="post" action="index.php?page=gyms" style="margin:0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="gym_id" value="<?= (int) $gym['gym_id'] ?>">
                                            <input type="hidden" name="action" value="suspend">
                                            <button type="submit" class="btn-sm btn-secondary" style="color:var(--danger); padding: 4px 8px; font-size: 12px;" data-confirm="Are you sure you want to suspend this gym? Their members will lose access.">Suspend</button>
                                        </form>
                                    <?php elseif ($gym['status'] === 'suspended'): ?>
                                        <form method="post" action="index.php?page=gyms" style="margin:0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="gym_id" value="<?= (int) $gym['gym_id'] ?>">
                                            <input type="hidden" name="action" value="reactivate">
                                            <button type="submit" class="btn-sm btn-primary" style="padding: 4px 8px; font-size: 12px;" data-confirm="Reactivate this gym?">Reactivate</button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="post" action="index.php?page=gyms" style="margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="gym_id" value="<?= (int) $gym['gym_id'] ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="btn-sm btn-secondary" style="color:var(--danger); border-color:var(--danger); padding: 4px 8px; font-size: 12px;" data-confirm="Are you sure you want to permanently delete this gym? This cannot be undone.">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Card View (<= 768px) -->
        <div class="gyms-mobile-cards">
            <?php foreach ($gyms as $gym): 
                $subPlan = $gym['subscription_plan'] ?: 'None';
                $subStatus = $gym['subscription_status'] ?: 'inactive';
                $renewal = !empty($gym['subscription_renewal_date']) ? date('M j, Y', strtotime($gym['subscription_renewal_date'])) : '—';
                $isRenewalPassed = !empty($gym['subscription_renewal_date']) && strtotime($gym['subscription_renewal_date']) < strtotime('today');
                if ($isRenewalPassed && in_array(strtolower($subStatus), ['active', 'trialing'], true)) {
                    $subStatus = 'expired';
                }
                $planBadgeStyle = match(strtolower($subPlan)) {
                    'starter' => 'background: rgba(59, 130, 246, 0.15); color: #60a5fa;',
                    'professional' => 'background: rgba(34, 197, 94, 0.15); color: var(--lime);',
                    'business' => 'background: rgba(168, 85, 247, 0.15); color: #c084fc;',
                    default => 'background: rgba(148, 163, 184, 0.1); color: var(--muted);'
                };
                $statusBadgeClass = match(strtolower($subStatus)) {
                    'active' => 'badge-active',
                    'expired' => 'badge-inactive',
                    default => 'badge-pending'
                };
                $gStats = get_gym_rating_stats((int)$gym['gym_id']);
            ?>
                <div class="gym-card-item">
                    <div class="gym-card-header">
                        <div class="gym-card-identity">
                            <div class="gym-card-avatar">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M3 7v14M21 7v14M6 11h4M6 15h4M14 11h4M14 15h4M9 21v-4h6v4M3 7l9-4 9 4"/></svg>
                            </div>
                            <div class="gym-card-names">
                                <div class="gym-card-title"><?= h($gym['name']) ?></div>
                                <div class="gym-card-id">#<?= (int) $gym['gym_id'] ?></div>
                            </div>
                        </div>
                        <div class="gym-card-badges">
                            <?php if ($gym['status'] === 'approved'): ?>
                                <span class="badge badge-active">Approved</span>
                            <?php elseif ($gym['status'] === 'suspended'): ?>
                                <span class="badge badge-inactive" style="color:var(--danger)">Suspended</span>
                            <?php endif; ?>
                            <span class="badge" style="<?= $planBadgeStyle ?>; font-weight:700;">
                                <?= h($subPlan) ?>
                                <?php if (in_array(strtolower($subPlan), ['starter', 'professional', 'business'], true)): ?>
                                    <span style="font-size: 10px; opacity: 0.85; font-weight: 600; text-transform: uppercase;">(<?= ($gym['subscription_billing_cycle'] ?? 'monthly') === 'yearly' ? 'Yearly' : 'Monthly' ?>)</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>

                    <div class="gym-card-details">
                        <div class="gym-card-detail-item">
                            <span class="gym-card-detail-label">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" style="color:#f59e0b;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                Member Rating
                            </span>
                            <span class="gym-card-detail-value">
                                <a href="index.php?page=gym_profile&gym_id=<?= (int)$gym['gym_id'] ?>#sec-ratings" style="color:#f59e0b; font-weight:700; text-decoration:none;">
                                    ★ <?= number_format($gStats['avg_rating'], 1) ?> <small style="color:var(--muted); font-weight:normal;">(<?= $gStats['total_reviews'] ?>)</small>
                                </a>
                            </span>
                        </div>

                        <div class="gym-card-detail-item">
                            <span class="gym-card-detail-label">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                Owner
                            </span>
                            <span class="gym-card-detail-value"><?= h($gym['first_name'] . ' ' . $gym['last_name']) ?></span>
                        </div>

                        <div class="gym-card-detail-item">
                            <span class="gym-card-detail-label">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                Email
                            </span>
                            <span class="gym-card-detail-value email-value" title="<?= h($gym['email']) ?>"><?= h($gym['email']) ?></span>
                        </div>

                        <div class="gym-card-detail-item">
                            <span class="gym-card-detail-label">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                Sub Status
                            </span>
                            <span class="gym-card-detail-value">
                                <span class="badge <?= $statusBadgeClass ?>"><?= h(ucfirst($subStatus)) ?></span>
                            </span>
                        </div>

                        <div class="gym-card-detail-item">
                            <span class="gym-card-detail-label">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                Renewal
                            </span>
                            <span class="gym-card-detail-value"><?= h($renewal) ?></span>
                        </div>
                    </div>

                    <div class="gym-card-actions">
                        <button type="button" 
                                class="btn-sm btn-primary" 
                                data-gym-id="<?= (int)$gym['gym_id'] ?>"
                                data-gym-name="<?= h($gym['name']) ?>"
                                data-owner-name="<?= h($gym['first_name'] . ' ' . $gym['last_name']) ?>"
                                data-plan="<?= h($gym['subscription_plan'] ?? '') ?>"
                                data-status="<?= h($subStatus) ?>"
                                data-renewal="<?= h($gym['subscription_renewal_date'] ?? '') ?>"
                                data-cycle="<?= h($gym['subscription_billing_cycle'] ?? 'monthly') ?>"
                                onclick="handleEditSubClick(this)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                            Edit Sub
                        </button>

                        <button type="button" 
                                class="btn-sm btn-secondary" 
                                data-gym-id="<?= (int)$gym['gym_id'] ?>"
                                data-gym-name="<?= h($gym['name']) ?>"
                                data-owner-name="<?= h($gym['first_name'] . ' ' . $gym['last_name']) ?>"
                                data-xendit-account="<?= h((string)($gym['xendit_subaccount_id'] ?? '')) ?>"
                                data-payout-channel="<?= h((string)($gym['payout_channel'] ?? 'gcash')) ?>"
                                data-payout-number="<?= h((string)($gym['payout_account_number'] ?? '')) ?>"
                                data-payout-name="<?= h((string)($gym['payout_account_name'] ?? '')) ?>"
                                onclick="handleEditPayoutClick(this)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                            Payout
                        </button>

                        <?php if ($gym['status'] === 'approved'): ?>
                            <form method="post" action="index.php?page=gyms" style="margin:0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="gym_id" value="<?= (int) $gym['gym_id'] ?>">
                                <input type="hidden" name="action" value="suspend">
                                <button type="submit" class="btn-sm btn-secondary" style="color:var(--danger);" data-confirm="Are you sure you want to suspend this gym? Their members will lose access.">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="10" y1="15" x2="10" y2="9"/><line x1="14" y1="15" x2="14" y2="9"/></svg>
                                    Suspend
                                </button>
                            </form>
                        <?php elseif ($gym['status'] === 'suspended'): ?>
                            <form method="post" action="index.php?page=gyms" style="margin:0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="gym_id" value="<?= (int) $gym['gym_id'] ?>">
                                <input type="hidden" name="action" value="reactivate">
                                <button type="submit" class="btn-sm btn-primary" data-confirm="Reactivate this gym?">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                    Reactivate
                                </button>
                            </form>
                        <?php endif; ?>

                        <form method="post" action="index.php?page=gyms" style="margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="gym_id" value="<?= (int) $gym['gym_id'] ?>">
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" class="btn-sm btn-secondary" style="color:var(--danger); border-color:var(--danger);" data-confirm="Are you sure you want to permanently delete this gym? This cannot be undone.">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                Delete
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <!-- Hidden Form for Edit Subscription Submission -->
    <form id="edit-sub-form" method="post" action="index.php?page=gyms" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_subscription">
        <input type="hidden" name="gym_id" id="modal-sub-gym-id" value="">
        <input type="hidden" name="subscription_plan" id="modal-sub-plan" value="">
        <input type="hidden" name="subscription_billing_cycle" id="modal-sub-cycle" value="monthly">
        <input type="hidden" name="subscription_status" id="modal-sub-status" value="">
        <input type="hidden" name="subscription_renewal_date" id="modal-sub-renewal-date" value="">
        <input type="hidden" name="record_payment" id="modal-sub-record-payment" value="0">
        <input type="hidden" name="amount" id="modal-sub-amount" value="0">
        <input type="hidden" name="payment_method" id="modal-sub-method" value="cash">
    </form>

    <!-- Hidden Form for Edit Payout Settings Submission -->
    <form id="edit-payout-form" method="post" action="index.php?page=gyms" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_payout">
        <input type="hidden" name="gym_id" id="modal-payout-gym-id" value="">
        <input type="hidden" name="xendit_subaccount_id" id="modal-payout-xendit-account" value="">
        <input type="hidden" name="payout_channel" id="modal-payout-channel" value="gcash">
        <input type="hidden" name="payout_account_number" id="modal-payout-number" value="">
        <input type="hidden" name="payout_account_name" id="modal-payout-name" value="">
    </form>

    <script>
    const platformPlans = <?= json_encode(get_platform_subscription_plans()) ?>;
    const planMonthlyPrices = {};
    const planAnnualPrices = {};
    for (const [k, p] of Object.entries(platformPlans)) {
        planMonthlyPrices[p.name] = Number(p.price) || 0;
        planAnnualPrices[p.name] = Number(p.annual_price) || (Number(p.price) * 10);
    }
    planMonthlyPrices['Free Trial'] = 0;
    planAnnualPrices['Free Trial'] = 0;

    function getFutureDate(type) {
        const d = new Date();
        if (type === 'trial' || type === '14days') {
            d.setDate(d.getDate() + 14);
        } else if (type === 'yearly' || type === 'annual') {
            d.setFullYear(d.getFullYear() + 1);
        } else {
            // Default 1 month
            d.setMonth(d.getMonth() + 1);
        }
        const yyyy = d.getFullYear();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        return `${yyyy}-${mm}-${dd}`;
    }

    function handleEditSubClick(btn) {
        if (!btn) return;
        const gymId = parseInt(btn.getAttribute('data-gym-id') || '0', 10);
        const gymName = btn.getAttribute('data-gym-name') || '';
        const ownerName = btn.getAttribute('data-owner-name') || '';
        const currentPlan = btn.getAttribute('data-plan') || '';
        const currentStatus = btn.getAttribute('data-status') || 'inactive';
        const currentRenewal = btn.getAttribute('data-renewal') || '';
        const currentCycle = btn.getAttribute('data-cycle') || 'monthly';
        openEditSubModal(gymId, gymName, ownerName, currentPlan, currentStatus, currentRenewal, currentCycle);
    }

    function openEditSubModal(gymId, gymName, ownerName, currentPlan, currentStatus, currentRenewal, currentCycle) {
        currentPlan = currentPlan || '';
        currentStatus = currentStatus || 'inactive';
        currentCycle = (currentCycle === 'yearly') ? 'yearly' : 'monthly';

        const todayStr = (new Date()).toISOString().split('T')[0];
        let defaultRenewal = currentRenewal;

        // Automatically detect next renewal date if empty OR if the existing date is already in the past
        if (!defaultRenewal || defaultRenewal < todayStr) {
            if (currentPlan === 'Free Trial' || currentStatus === 'trialing') {
                defaultRenewal = getFutureDate('trial');
            } else if (currentCycle === 'yearly') {
                defaultRenewal = getFutureDate('yearly');
            } else {
                defaultRenewal = getFutureDate('monthly');
            }
        }

        const safePlanLower = currentPlan.toLowerCase();
        const prices = (currentCycle === 'yearly') ? planAnnualPrices : planMonthlyPrices;
        const currentPrice = prices[currentPlan] !== undefined 
            ? prices[currentPlan] 
            : (platformPlans['professional'] ? (currentCycle === 'yearly' ? platformPlans['professional'].annual_price : platformPlans['professional'].price) : 999);

        let planOptions = '';
        for (const [k, p] of Object.entries(platformPlans)) {
            const isSelected = (safePlanLower === p.name.toLowerCase() || safePlanLower === k.toLowerCase());
            const popularTag = p.popular ? ' - Most Popular' : '';
            planOptions += `<option value="${p.name}" ${isSelected ? 'selected' : ''}>${p.name} Plan (${p.price_label}/mo · ${p.annual_price_label}/yr${popularTag})</option>`;
        }
        if (safePlanLower.includes('trial')) {
            planOptions = `<option value="Free Trial" selected>Free Trial (14 Days)</option>` + planOptions;
        }
        planOptions += `<option value="None" ${!currentPlan || safePlanLower === 'none' ? 'selected' : ''}>None / Unsubscribed</option>`;

        Swal.fire({
            title: 'Edit Gym Subscription',
            html: `
                <div style="text-align: left; display: flex; flex-direction: column; gap: 13px; margin-top: 10px;">
                    <div style="padding: 10px 14px; border-radius: 8px; background: color-mix(in srgb, var(--panel-soft) 50%, transparent); border: 1px solid var(--line);">
                        <div style="font-size: 14px; font-weight: 700; color: var(--ink);">${gymName}</div>
                        <div style="font-size: 12px; color: var(--muted);">Owner: ${ownerName}</div>
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <label style="flex: 1.5; display:block; font-size: 13px; color: var(--muted);">Subscription Plan *
                            <select id="swal-sub-plan" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px;" onchange="onPlanOrCycleChange()">
                                ${planOptions}
                            </select>
                        </label>

                        <label style="flex: 1; display:block; font-size: 13px; color: var(--muted);">Billing Cycle *
                            <select id="swal-sub-cycle" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px;" onchange="onPlanOrCycleChange()">
                                <option value="monthly" ${currentCycle === 'monthly' ? 'selected' : ''}>Monthly</option>
                                <option value="yearly" ${currentCycle === 'yearly' ? 'selected' : ''}>Yearly (Annual)</option>
                            </select>
                        </label>
                    </div>

                    <div style="display: flex; gap: 12px;">
                        <label style="flex: 1; display:block; font-size: 13px; color: var(--muted);">Subscription Status *
                            <select id="swal-sub-status" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px;" onchange="onStatusChange(this.value)">
                                <option value="active" ${currentStatus === 'active' ? 'selected' : ''}>Active (Paid)</option>
                                <option value="trialing" ${currentStatus === 'trialing' ? 'selected' : ''}>Trialing (Free Trial)</option>
                                <option value="free" ${currentStatus === 'free' ? 'selected' : ''}>Free Tier (Limited)</option>
                                <option value="inactive" ${currentStatus === 'inactive' ? 'selected' : ''}>Inactive</option>
                                <option value="expired" ${currentStatus === 'expired' ? 'selected' : ''}>Expired</option>
                            </select>
                        </label>

                        <label style="flex: 1; display:block; font-size: 13px; color: var(--muted);">Renewal / Expiration Date
                            <input type="date" id="swal-sub-renewal" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px;" value="${defaultRenewal}">
                        </label>
                    </div>

                    <div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center; margin-top: -6px;">
                        <span style="font-size: 11px; color: var(--muted); margin-right: auto;">Quick auto-detect:</span>
                        <button type="button" style="background: rgba(255,255,255,0.06); border: 1px solid var(--line); color: var(--muted); padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer;" onclick="calcRenewalDate('monthly')">+1 Month</button>
                        <button type="button" style="background: rgba(255,255,255,0.06); border: 1px solid var(--line); color: var(--muted); padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer;" onclick="calcRenewalDate('yearly')">+1 Year</button>
                        <button type="button" style="background: rgba(255,255,255,0.06); border: 1px solid var(--line); color: var(--muted); padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer;" onclick="calcRenewalDate('trial')">+14 Days</button>
                    </div>

                    <div style="padding: 12px; border-radius: 8px; background: rgba(34, 197, 94, 0.04); border: 1px dashed rgba(34, 197, 94, 0.25);">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 600; color: var(--ink);">
                            <input type="checkbox" id="swal-record-payment" style="accent-color: var(--lime);" onchange="togglePaymentFields(this.checked)">
                            <span>Record as Paid Subscription in Ledger</span>
                        </label>

                        <div id="swal-payment-fields" style="display: none; margin-top: 12px; flex-direction: column; gap: 10px;">
                            <div style="display: flex; gap: 10px;">
                                <label style="flex: 1; font-size: 12px; color: var(--muted);">Amount (₱)
                                     <input type="number" step="0.01" id="swal-sub-amount" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 2px;" value="${currentPrice}">
                                </label>

                                <label style="flex: 1; font-size: 12px; color: var(--muted);">Payment Method
                                    <select id="swal-sub-method" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 2px;">
                                        <option value="cash">Cash / Offline</option>
                                        <option value="gcash">GCash</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                        <option value="card">Card</option>
                                    </select>
                                </label>
                            </div>
                            <small style="color: var(--muted); font-size: 11px;">This transaction will appear in Platform Revenue and Recent Subscriptions.</small>
                        </div>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Save Subscription',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            width: '510px',
            preConfirm: () => {
                const plan = document.getElementById('swal-sub-plan').value;
                const cycle = document.getElementById('swal-sub-cycle').value;
                const status = document.getElementById('swal-sub-status').value;
                const renewal = document.getElementById('swal-sub-renewal').value;
                const recordPay = document.getElementById('swal-record-payment').checked;
                const amount = document.getElementById('swal-sub-amount').value;
                const method = document.getElementById('swal-sub-method').value;

                document.getElementById('modal-sub-gym-id').value = gymId;
                document.getElementById('modal-sub-plan').value = plan;
                document.getElementById('modal-sub-cycle').value = cycle;
                document.getElementById('modal-sub-status').value = status;
                document.getElementById('modal-sub-renewal-date').value = renewal;
                document.getElementById('modal-sub-record-payment').value = recordPay ? '1' : '0';
                document.getElementById('modal-sub-amount').value = amount || '0';
                document.getElementById('modal-sub-method').value = method;

                document.getElementById('edit-sub-form').submit();
            }
        });
    }

    function handleEditPayoutClick(btn) {
        if (!btn) return;
        const gymId = parseInt(btn.getAttribute('data-gym-id') || '0', 10);
        const gymName = btn.getAttribute('data-gym-name') || '';
        const ownerName = btn.getAttribute('data-owner-name') || '';
        const currentXenditAccount = btn.getAttribute('data-xendit-account') || '';
        const currentPayoutChannel = btn.getAttribute('data-payout-channel') || 'gcash';
        const currentPayoutNumber = btn.getAttribute('data-payout-number') || '';
        const currentPayoutName = btn.getAttribute('data-payout-name') || '';
        openEditPayoutModal(gymId, gymName, ownerName, currentXenditAccount, currentPayoutChannel, currentPayoutNumber, currentPayoutName);
    }

    function openEditPayoutModal(gymId, gymName, ownerName, currentXenditAccount, currentPayoutChannel, currentPayoutNumber, currentPayoutName) {
        currentPayoutChannel = currentPayoutChannel || 'gcash';

        const safeXenditAccount = String(currentXenditAccount || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        const safePayoutChannel = String(currentPayoutChannel || 'gcash').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        const safePayoutNumber = String(currentPayoutNumber || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        const safePayoutName = String(currentPayoutName || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

        Swal.fire({
            title: 'Payout Destination Settings',
            html: `
                <div style="text-align: left; display: flex; flex-direction: column; gap: 14px; margin-top: 10px;">
                    <div style="padding: 10px 14px; border-radius: 8px; background: color-mix(in srgb, var(--panel-soft) 50%, transparent); border: 1px solid var(--line);">
                        <div style="font-size: 14px; font-weight: 700; color: var(--ink);">${gymName}</div>
                        <div style="font-size: 12px; color: var(--muted);">Owner: ${ownerName}</div>
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <label style="flex: 1; display:block; font-size: 12px; font-weight: 600; color: var(--muted);">Payout Channel
                            <select id="swal-payout-channel" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px;">
                                <option value="gcash" ${safePayoutChannel.toLowerCase() === 'gcash' ? 'selected' : ''}>GCash</option>
                                <option value="maya" ${safePayoutChannel.toLowerCase() === 'maya' ? 'selected' : ''}>Maya</option>
                                <option value="bank_transfer" ${safePayoutChannel.toLowerCase() === 'bank_transfer' ? 'selected' : ''}>Bank Transfer</option>
                            </select>
                        </label>
                        <label style="flex: 1.5; display:block; font-size: 12px; font-weight: 600; color: var(--muted);">Payout Account / GCash #
                            <input id="swal-payout-number" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px;" value="${safePayoutNumber}" placeholder="09171234567">
                        </label>
                    </div>

                    <label style="display:block; font-size: 12px; font-weight: 600; color: var(--muted);">Payout Account Holder Full Name
                        <input id="swal-payout-name" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px;" value="${safePayoutName}" placeholder="Exact Name on GCash/Bank">
                    </label>

                    <label style="display:block; font-size: 12px; font-weight: 600; color: var(--muted);">XenPlatform Sub-Account User ID
                        <input id="swal-xendit-account" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px;" value="${safeXenditAccount}" placeholder="e.g. user_64a... (or leave blank if unlinked)">
                        <small style="display:block; margin-top: 4px; font-size: 11px; color: var(--muted); font-weight: normal;">Members can pay online via payment gateway only after this gym has a verified XenPlatform sub-account.</small>
                    </label>

                    <div style="padding: 10px 12px; border-radius: 8px; background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.2); font-size: 11.5px; color: var(--muted); line-height: 1.45;">
                        <strong style="color: #60a5fa;">Disbursement Note:</strong> These settlement details receive disbursed member dues, walk-in fees, and coach session payouts.
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Save Payout Details',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            width: '490px',
            preConfirm: () => {
                const payoutChan = document.getElementById('swal-payout-channel').value;
                const payoutNum = document.getElementById('swal-payout-number').value.trim();
                const payoutName = document.getElementById('swal-payout-name').value.trim();
                const xenditAccount = document.getElementById('swal-xendit-account').value.trim();

                document.getElementById('modal-payout-gym-id').value = gymId;
                document.getElementById('modal-payout-channel').value = payoutChan;
                document.getElementById('modal-payout-number').value = payoutNum;
                document.getElementById('modal-payout-name').value = payoutName;
                document.getElementById('modal-payout-xendit-account').value = xenditAccount;

                document.getElementById('edit-payout-form').submit();
            }
        });
    }

    function onPlanOrCycleChange() {
        const planEl = document.getElementById('swal-sub-plan');
        const cycleEl = document.getElementById('swal-sub-cycle');
        const statusEl = document.getElementById('swal-sub-status');
        const renewalEl = document.getElementById('swal-sub-renewal');
        const amountEl = document.getElementById('swal-sub-amount');

        if (!planEl || !cycleEl) return;
        const plan = planEl.value;
        const cycle = cycleEl.value;

        if (plan === 'Free Trial') {
            cycleEl.disabled = true;
            if (statusEl) statusEl.value = 'trialing';
            if (renewalEl) renewalEl.value = getFutureDate('trial');
            if (amountEl) amountEl.value = '0';
        } else if (plan === 'None' || plan === 'free') {
            cycleEl.disabled = true;
            if (statusEl) statusEl.value = 'inactive';
            if (renewalEl) renewalEl.value = '';
            if (amountEl) amountEl.value = '0';
        } else {
            cycleEl.disabled = false;
            if (statusEl && (statusEl.value === 'inactive' || statusEl.value === 'expired' || statusEl.value === 'trialing')) {
                statusEl.value = 'active';
            }
            if (renewalEl) {
                renewalEl.value = getFutureDate(cycle === 'yearly' ? 'yearly' : 'monthly');
            }
            if (amountEl) {
                const prices = (cycle === 'yearly') ? planAnnualPrices : planMonthlyPrices;
                if (prices[plan] !== undefined) {
                    amountEl.value = prices[plan];
                }
            }
        }
    }

    function onStatusChange(status) {
        const renewalEl = document.getElementById('swal-sub-renewal');
        const cycleEl = document.getElementById('swal-sub-cycle');
        const todayStr = (new Date()).toISOString().split('T')[0];

        if (status === 'active') {
            if (renewalEl && (!renewalEl.value || renewalEl.value < todayStr)) {
                const cycle = cycleEl ? cycleEl.value : 'monthly';
                renewalEl.value = getFutureDate(cycle === 'yearly' ? 'yearly' : 'monthly');
            }
        } else if (status === 'trialing') {
            if (renewalEl && (!renewalEl.value || renewalEl.value < todayStr)) {
                renewalEl.value = getFutureDate('trial');
            }
        }
    }

    function calcRenewalDate(type) {
        const renewalEl = document.getElementById('swal-sub-renewal');
        if (renewalEl) {
            renewalEl.value = getFutureDate(type);
        }
        const cycleEl = document.getElementById('swal-sub-cycle');
        if (cycleEl && (type === 'monthly' || type === 'yearly')) {
            cycleEl.value = type;
            const planEl = document.getElementById('swal-sub-plan');
            const amountEl = document.getElementById('swal-sub-amount');
            if (planEl && amountEl) {
                const prices = (type === 'yearly') ? planAnnualPrices : planMonthlyPrices;
                if (prices[planEl.value] !== undefined) {
                    amountEl.value = prices[planEl.value];
                }
            }
        }
    }

    function togglePaymentFields(show) {
        const container = document.getElementById('swal-payment-fields');
        if (container) {
            container.style.display = show ? 'flex' : 'none';
        }
    }
    </script>
<?php
    render_footer();
}
