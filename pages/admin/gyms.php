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
            $renewalDate = trim((string) post('subscription_renewal_date')) ?: null;
            $recordPayment = (post('record_payment') === '1');
            $amount = (float) post('amount');
            $paymentMethod = trim((string) post('payment_method')) ?: 'cash';

            $pdo->beginTransaction();

            $pdo->prepare('UPDATE gyms SET subscription_plan = ?, subscription_status = ?, subscription_renewal_date = ? WHERE gym_id = ?')
                ->execute([$plan, $subStatus, $renewalDate, $gymId]);

            $ownerId = (int) $pdo->query('SELECT owner_user_id FROM gyms WHERE gym_id = ' . $gymId)->fetchColumn();
            $gymName = (string) $pdo->query('SELECT name FROM gyms WHERE gym_id = ' . $gymId)->fetchColumn();

            if ($recordPayment && $plan && $amount > 0) {
                $receiptNumber = 'SUB-ADM-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
                $startDate = date('Y-m-d');
                $endDate = $renewalDate ?: date('Y-m-d', strtotime('+1 month'));

                $pdo->prepare('
                    INSERT INTO gym_subscription_payments 
                    (gym_id, owner_user_id, plan_name, amount, billing_cycle, payment_method, status, receipt_number, payment_date, start_date, end_date)
                    VALUES (?, ?, ?, ?, "monthly", ?, "paid", ?, NOW(), ?, ?)
                ')->execute([
                    $gymId,
                    $ownerId,
                    $plan,
                    $amount,
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
                'renewal_date' => $renewalDate,
                'recorded_payment' => $recordPayment ? $amount : false
            ]));

            notify_user(
                $ownerId,
                'system',
                'Subscription Updated',
                "Your subscription for '{$gymName}' has been updated by Platform Admin to " . ($plan ? "{$plan} Plan ({$subStatus})" : "No Plan") . "."
            );

            flash("Subscription for '{$gymName}' updated successfully.", 'success');
        }
        redirect('gyms');
    }

    $gyms = $pdo->query('
        SELECT g.*, u.first_name, u.last_name, u.email 
        FROM gyms g 
        JOIN users u ON u.user_id = g.owner_user_id 
        WHERE g.status IN ("approved", "suspended") 
        ORDER BY g.created_at DESC
    ')->fetchAll();

    render_header('All Gyms', $user);
?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>All Gyms & Subscriptions</h1>
                <p>Manage registered gym facilities, accounts, and subscription tiers.</p>
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Gym Name</th>
                        <th>Owner</th>
                        <th>Email</th>
                        <th>Facility Status</th>
                        <th>Subscription Plan</th>
                        <th>Sub Status</th>
                        <th>Renewal Date</th>
                        <th style="width: 220px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$gyms): ?>
                        <tr><td colspan="8" class="text-center">No active or suspended gyms found.</td></tr>
                    <?php else: foreach ($gyms as $gym): 
                        $subPlan = $gym['subscription_plan'] ?: 'None';
                        $subStatus = $gym['subscription_status'] ?: 'inactive';
                        $renewal = !empty($gym['subscription_renewal_date']) ? date('M j, Y', strtotime($gym['subscription_renewal_date'])) : '—';
                        $planBadgeStyle = match(strtolower($subPlan)) {
                            'starter' => 'background: rgba(59, 130, 246, 0.15); color: #60a5fa;',
                            'professional' => 'background: rgba(34, 197, 94, 0.15); color: var(--lime);',
                            'business' => 'background: rgba(168, 85, 247, 0.15); color: #c084fc;',
                            default => 'background: rgba(148, 163, 184, 0.1); color: var(--muted);'
                        };
                        $statusBadgeClass = match($subStatus) {
                            'active' => 'badge-active',
                            'expired' => 'badge-inactive',
                            default => 'badge-pending'
                        };
                    ?>
                        <tr>
                            <td><strong><?= h($gym['name']) ?></strong></td>
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
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $statusBadgeClass ?>">
                                    <?= h(ucfirst($subStatus)) ?>
                                </span>
                            </td>
                            <td><small style="color:var(--muted);"><?= h($renewal) ?></small></td>
                            <td>
                                <div style="display: flex; gap: 6px; align-items: center;">
                                    <button type="button" 
                                            class="btn-sm btn-primary" 
                                            style="padding: 4px 10px; font-size: 12px; font-weight: 700;"
                                            onclick="openEditSubModal(
                                                <?= (int)$gym['gym_id'] ?>, 
                                                '<?= h(addslashes($gym['name'])) ?>', 
                                                '<?= h(addslashes($gym['first_name'] . ' ' . $gym['last_name'])) ?>', 
                                                '<?= h($gym['subscription_plan'] ?? '') ?>', 
                                                '<?= h($gym['subscription_status'] ?? 'inactive') ?>', 
                                                '<?= h($gym['subscription_renewal_date'] ?? '') ?>'
                                            )">
                                        Edit Sub
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
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Hidden Form for Edit Subscription Submission -->
    <form id="edit-sub-form" method="post" action="index.php?page=gyms" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_subscription">
        <input type="hidden" name="gym_id" id="modal-sub-gym-id" value="">
        <input type="hidden" name="subscription_plan" id="modal-sub-plan" value="">
        <input type="hidden" name="subscription_status" id="modal-sub-status" value="">
        <input type="hidden" name="subscription_renewal_date" id="modal-sub-renewal-date" value="">
        <input type="hidden" name="record_payment" id="modal-sub-record-payment" value="0">
        <input type="hidden" name="amount" id="modal-sub-amount" value="0">
        <input type="hidden" name="payment_method" id="modal-sub-method" value="cash">
    </form>

    <script>
    const platformPlans = <?= json_encode(get_platform_subscription_plans()) ?>;
    const planPrices = {};
    for (const [k, p] of Object.entries(platformPlans)) {
        planPrices[p.name] = p.price;
    }

    function openEditSubModal(gymId, gymName, ownerName, currentPlan, currentStatus, currentRenewal) {
        // Default renewal date to 1 month from now if empty
        let defaultRenewal = currentRenewal;
        if (!defaultRenewal) {
            const d = new Date();
            d.setMonth(d.getMonth() + 1);
            defaultRenewal = d.toISOString().split('T')[0];
        }

        const currentPrice = planPrices[currentPlan] || (platformPlans['professional'] ? platformPlans['professional'].price : 999);

        let planOptions = '';
        for (const [k, p] of Object.entries(platformPlans)) {
            const isSelected = (currentPlan.toLowerCase() === p.name.toLowerCase() || currentPlan.toLowerCase() === k.toLowerCase());
            planOptions += `<option value="${p.name}" ${isSelected ? 'selected' : ''}>${p.name} Plan (${p.price_label} / mo${p.popular ? ' - Most Popular' : ''})</option>`;
        }
        planOptions += `<option value="None" ${!currentPlan || currentPlan.toLowerCase() === 'none' ? 'selected' : ''}>None / Unsubscribed</option>`;

        Swal.fire({
            title: 'Edit Gym Subscription',
            html: `
                <div style="text-align: left; display: flex; flex-direction: column; gap: 14px; margin-top: 10px;">
                    <div style="padding: 10px 14px; border-radius: 8px; background: rgba(255,255,255,0.03); border: 1px solid var(--line);">
                        <div style="font-size: 14px; font-weight: 700; color: #fff;">${gymName}</div>
                        <div style="font-size: 12px; color: var(--muted);">Owner: ${ownerName}</div>
                    </div>

                    <label style="display:block; font-size: 13px; color: var(--muted);">Subscription Plan *
                        <select id="swal-sub-plan" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px;" onchange="onPlanChange(this.value)">
                            ${planOptions}
                        </select>
                    </label>

                    <div style="display: flex; gap: 12px;">
                        <label style="flex: 1; display:block; font-size: 13px; color: var(--muted);">Subscription Status *
                            <select id="swal-sub-status" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px;">
                                <option value="active" ${currentStatus === 'active' ? 'selected' : ''}>Active</option>
                                <option value="inactive" ${currentStatus === 'inactive' ? 'selected' : ''}>Inactive</option>
                                <option value="expired" ${currentStatus === 'expired' ? 'selected' : ''}>Expired</option>
                            </select>
                        </label>

                        <label style="flex: 1; display:block; font-size: 13px; color: var(--muted);">Renewal / Expiration Date
                            <input type="date" id="swal-sub-renewal" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px;" value="${defaultRenewal}">
                        </label>
                    </div>

                    <div style="padding: 12px; border-radius: 8px; background: rgba(34, 197, 94, 0.04); border: 1px dashed rgba(34, 197, 94, 0.25);">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 600; color: #fff;">
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
            width: '480px',
            preConfirm: () => {
                const plan = document.getElementById('swal-sub-plan').value;
                const status = document.getElementById('swal-sub-status').value;
                const renewal = document.getElementById('swal-sub-renewal').value;
                const recordPay = document.getElementById('swal-record-payment').checked;
                const amount = document.getElementById('swal-sub-amount').value;
                const method = document.getElementById('swal-sub-method').value;

                document.getElementById('modal-sub-gym-id').value = gymId;
                document.getElementById('modal-sub-plan').value = plan;
                document.getElementById('modal-sub-status').value = status;
                document.getElementById('modal-sub-renewal-date').value = renewal;
                document.getElementById('modal-sub-record-payment').value = recordPay ? '1' : '0';
                document.getElementById('modal-sub-amount').value = amount || '0';
                document.getElementById('modal-sub-method').value = method;

                document.getElementById('edit-sub-form').submit();
            }
        });
    }

    function onPlanChange(plan) {
        const amountInput = document.getElementById('swal-sub-amount');
        if (amountInput && planPrices[plan] !== undefined) {
            amountInput.value = planPrices[plan];
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
