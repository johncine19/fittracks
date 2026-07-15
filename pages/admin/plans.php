<?php
declare(strict_types=1);

function plans_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner']);
    $pdo = db();
    $isPlatformAdmin = $user['role'] === 'platform_admin';
    $gymId = null;
    if (!$isPlatformAdmin) {
        $gymId = (int) scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action', 'add');
        if ($action === 'delete') {
            $pdo->prepare('DELETE FROM membership_plans WHERE plan_id = ? AND (gym_id = ? OR ? = 1)')->execute([post('id'), $gymId, $isPlatformAdmin ? 1 : 0]);
            audit_log($user['user_id'], 'delete', 'plan', (string) post('id'));
            flash('Membership plan deleted.');
        } elseif ($action === 'edit') {
            $planScope = $isPlatformAdmin ? post('plan_scope', 'shared') : 'local';
            $price = (float) post('price');
            if ($planScope === 'shared' && $price < 1500) {
                flash('Shared plans must have a premium price of at least ₱1,500.00.', 'danger');
                redirect('plans');
            }

            $pdo->prepare('UPDATE membership_plans SET plan_name = ?, plan_type = ?, duration_days = ?, price = ?, description = ?, is_active = ?, commission_rate = ?, plan_scope = ? WHERE plan_id = ? AND (gym_id = ? OR ? = 1)')->execute([post('plan_name'), post('plan_type'), post('duration_days'), $price, post('description'), post('is_active', 0) ? 1 : 0, post('commission_rate', 5.0), $planScope, post('id'), $gymId, $isPlatformAdmin ? 1 : 0]);
            
            // Recalculate pending commissions for this plan based on new price and rate
            $newRate = (float)post('commission_rate', 5.0);
            $newPrice = $price;
            $planId = (int)post('id');
            $pdo->prepare("
                UPDATE trainer_commissions tc
                JOIN payments p ON p.payment_id = tc.payment_id
                JOIN memberships m ON m.membership_id = p.membership_id
                SET tc.amount = ? * (? / 100)
                WHERE m.plan_id = ? AND tc.status = 'pending'
            ")->execute([$newPrice, $newRate, $planId]);

            audit_log($user['user_id'], 'edit', 'plan', (string) post('id'), json_encode(['plan_name' => post('plan_name'), 'price' => $price]));
            flash('Membership plan updated.');
        } elseif ($action === 'opt_in') {
            if (!$isPlatformAdmin) {
                $planId = (int)post('id');
                
                // Get details of the shared plan we want to opt in to
                $sharedPlanQuery = $pdo->prepare('SELECT plan_type, price, plan_name FROM membership_plans WHERE plan_id = ?');
                $sharedPlanQuery->execute([$planId]);
                $sp = $sharedPlanQuery->fetch();
                
                if ($sp) {
                    // Profit protection check: Find if this gym has any active local plan of the same type priced higher
                    $conflictQuery = $pdo->prepare('
                        SELECT plan_name, price 
                        FROM membership_plans 
                        WHERE gym_id = ? AND plan_type = ? AND plan_scope = "local" AND is_active = 1 AND price > ?
                        LIMIT 1
                    ');
                    $conflictQuery->execute([$gymId, $sp['plan_type'], $sp['price']]);
                    $conflict = $conflictQuery->fetch();
                    
                    if ($conflict) {
                        flash(sprintf(
                            'Opt-in blocked: The shared plan "%s" is priced at %s, which is lower than your active local equivalent plan "%s" (%s). Opting in would cause you to lose profit.',
                            $sp['plan_name'], money((float)$sp['price']), $conflict['plan_name'], money((float)$conflict['price'])
                        ), 'danger');
                        redirect('plans');
                    }
                }
                
                $pdo->prepare('INSERT IGNORE INTO shared_plan_gyms (plan_id, gym_id, status) VALUES (?, ?, "pending")')->execute([$planId, $gymId]);
                flash('Opt-in request sent. Awaiting platform admin approval.', 'success');
            }
        } elseif ($action === 'approve_opt_in' && $isPlatformAdmin) {
            $spgId = (int)post('spg_id');
            $pdo->prepare('UPDATE shared_plan_gyms SET status = "approved" WHERE spg_id = ?')->execute([$spgId]);
            flash('Opt-in approved.', 'success');
        } elseif ($action === 'reject_opt_in' && $isPlatformAdmin) {
            $spgId = (int)post('spg_id');
            $pdo->prepare('UPDATE shared_plan_gyms SET status = "rejected" WHERE spg_id = ?')->execute([$spgId]);
            flash('Opt-in rejected.', 'success');
        } else {
            $planScope = $isPlatformAdmin ? post('plan_scope', 'shared') : 'local';
            $price = (float) post('price');
            if ($planScope === 'shared' && $price < 1500) {
                flash('Shared plans must have a premium price of at least ₱1,500.00.', 'danger');
                redirect('plans');
            }

            $pdo->prepare('INSERT INTO membership_plans (gym_id, plan_scope, plan_name, plan_type, duration_days, price, description, is_active, commission_rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$gymId, $planScope, post('plan_name'), post('plan_type'), post('duration_days'), $price, post('description'), post('is_active', 0) ? 1 : 0, post('commission_rate', 5.0)]);
            audit_log($user['user_id'], 'create', 'plan', (string) $pdo->lastInsertId(), json_encode(['plan_name' => post('plan_name'), 'price' => $price]));
            flash('Membership plan saved.');
        }
        redirect('plans');
    }
    
    if ($isPlatformAdmin) {
        $plans = $pdo->query('SELECT * FROM membership_plans WHERE gym_id IS NULL OR plan_scope = "shared" ORDER BY gym_id IS NULL DESC, is_active DESC, price')->fetchAll();
        $optIns = $pdo->query('SELECT spg.*, p.plan_name, g.name AS gym_name FROM shared_plan_gyms spg JOIN membership_plans p ON p.plan_id = spg.plan_id JOIN gyms g ON g.gym_id = spg.gym_id WHERE spg.status = "pending"')->fetchAll();
    } else {
        $plans = $pdo->query('SELECT * FROM membership_plans WHERE gym_id = ' . $gymId . ' ORDER BY is_active DESC, price')->fetchAll();
        
        $sharedPlans = $pdo->query('
            SELECT p.*, g.name as creator_gym_name, COALESCE(spg.status, "not_opted") as opt_status 
            FROM membership_plans p 
            LEFT JOIN gyms g ON g.gym_id = p.gym_id 
            LEFT JOIN shared_plan_gyms spg ON spg.plan_id = p.plan_id AND spg.gym_id = ' . $gymId . ' 
            WHERE p.plan_scope = "shared" AND (p.gym_id IS NULL OR p.gym_id != ' . $gymId . ') AND p.is_active = 1
        ')->fetchAll();
    }
    render_header('Plans', $user);
    ?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Membership Plans</h1>
                <p>Define the subscription tiers available to members.</p>
            </div>
            <button onclick="addPlan()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold;">+ New Plan</button>
        </div>



        <p class="section-label">Plans (<?= count($plans) ?>)</p>
        <?php if (!$plans): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                <p>No membership plans created yet.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Name</th><th>Scope</th><th>Type</th><th>Duration</th><th>Price</th><th>Comm. Rate</th><th>Status</th><th>Description</th><th style="text-align:right">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($plans as $plan): ?>
                    <tr>
                        <td><strong><?= h($plan['plan_name']) ?></strong></td>
                        <td><span class="badge" style="background:var(--panel-soft);color:var(--muted)"><?= h(ucfirst($plan['plan_scope'])) ?></span></td>
                        <td style="font-size:13px;color:var(--muted)"><?= h(ucfirst($plan['plan_type'])) ?></td>
                        <td style="font-size:13px"><?= (int) $plan['duration_days'] ?> days</td>
                        <td><strong style="color:var(--lime)"><?= h(money($plan['price'])) ?></strong></td>
                        <td><?= (float) $plan['commission_rate'] ?>%</td>
                        <td>
                            <?php if ($plan['is_active']): ?>
                                <span class="badge badge-active">Active</span>
                            <?php else: ?>
                                <span class="badge badge-inactive">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:var(--muted);font-size:13px"><?= h($plan['description'] ?: '—') ?></td>
                        <td style="text-align:right">
                            <button class="btn btn-sm" onclick='editPlan(<?= htmlspecialchars(json_encode($plan), ENT_QUOTES, "UTF-8") ?>)' style="padding:4px 8px; font-size:12px; background:transparent; color:#3b82f6; border:1px solid #3b82f6; border-radius:4px; cursor:pointer;">Edit</button>
                            <button class="btn btn-sm" onclick='deletePlan(<?= $plan['plan_id'] ?>)' style="padding:4px 8px; font-size:12px; background:#ef4444; color:#fff; border:none; border-radius:4px; cursor:pointer; margin-left:4px;">Delete</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <?php if (!$isPlatformAdmin): ?>
    <section class="panel" style="margin-top: 24px;">
        <div class="page-header">
            <div>
                <h2>Shared Plans</h2>
                <p>Opt-in to accept plans created by other gyms or the platform.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Name</th><th>Creator</th><th>Price</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php if (!$sharedPlans): ?>
                        <tr><td colspan="5" class="muted">No shared plans available.</td></tr>
                    <?php else: foreach ($sharedPlans as $sp): ?>
                        <tr>
                            <td><strong><?= h($sp['plan_name']) ?></strong></td>
                            <td><?= h($sp['creator_gym_name'] ?: 'Platform') ?></td>
                            <td><strong style="color:var(--lime)"><?= h(money($sp['price'])) ?></strong></td>
                            <td>
                                <?php if ($sp['opt_status'] === 'approved'): ?>
                                    <span class="badge badge-active">Approved</span>
                                <?php elseif ($sp['opt_status'] === 'pending'): ?>
                                    <span class="badge" style="background:rgba(245, 158, 11, 0.15); color: #f59e0b;">Pending</span>
                                <?php elseif ($sp['opt_status'] === 'rejected'): ?>
                                    <span class="badge badge-inactive">Rejected</span>
                                <?php else: ?>
                                    <span class="badge" style="background:var(--panel-soft); color:var(--muted);">Not Opted</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sp['opt_status'] === 'not_opted' || $sp['opt_status'] === 'rejected'): ?>
                                    <form method="post" action="index.php?page=plans" style="margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="opt_in">
                                        <input type="hidden" name="id" value="<?= (int) $sp['plan_id'] ?>">
                                        <button type="submit" class="btn-sm btn-primary">Opt In</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($isPlatformAdmin && !empty($optIns)): ?>
    <section class="panel" style="margin-top: 24px;">
        <div class="page-header">
            <div>
                <h2>Pending Opt-in Requests</h2>
                <p>Approve gyms that want to accept shared plans.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Gym</th><th>Plan</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($optIns as $opt): ?>
                        <tr>
                            <td><strong><?= h($opt['gym_name']) ?></strong></td>
                            <td><?= h($opt['plan_name']) ?></td>
                            <td>
                                <div style="display: flex; gap: 8px;">
                                    <form method="post" action="index.php?page=plans" style="margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="spg_id" value="<?= (int) $opt['spg_id'] ?>">
                                        <input type="hidden" name="action" value="approve_opt_in">
                                        <button type="submit" class="btn-sm btn-primary">Approve</button>
                                    </form>
                                    <form method="post" action="index.php?page=plans" style="margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="spg_id" value="<?= (int) $opt['spg_id'] ?>">
                                        <input type="hidden" name="action" value="reject_opt_in">
                                        <button type="submit" class="btn-sm btn-secondary" style="color:var(--danger)">Reject</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
    
    <script>

    function editPlan(plan) {
        Swal.fire({
            title: 'Edit Plan',
            html: `
                <form id="editPlanForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" value="${plan.plan_id}">
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Plan name *
                        <input name="plan_name" class="form-control" value="${plan.plan_name}" style="width: 100%; box-sizing: border-box;" required>
                    </label>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Scope *
                            <select name="plan_scope" class="form-control" style="width: 100%; box-sizing: border-box;" required <?= !$isPlatformAdmin ? 'disabled' : '' ?>>
                                <option value="local" ${plan.plan_scope === 'local' ? 'selected' : ''}>Local</option>
                                <option value="shared" ${plan.plan_scope === 'shared' ? 'selected' : ''}>Shared</option>
                            </select>
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Type *
                            <select name="plan_type" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                                <option value="monthly" ${plan.plan_type === 'monthly' ? 'selected' : ''}>Monthly</option>
                                <option value="quarterly" ${plan.plan_type === 'quarterly' ? 'selected' : ''}>Quarterly</option>
                                <option value="annual" ${plan.plan_type === 'annual' ? 'selected' : ''}>Annual</option>
                                <option value="custom" ${plan.plan_type === 'custom' ? 'selected' : ''}>Custom</option>
                            </select>
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Duration (days) *
                            <input name="duration_days" type="number" class="form-control" value="${plan.duration_days}" style="width: 100%; box-sizing: border-box;" required>
                        </label>
                    </div>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Price (PHP) *
                            <input name="price" type="number" step="0.01" class="form-control" value="${plan.price}" style="width: 100%; box-sizing: border-box;" required>
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Commission Rate (%) *
                            <input name="commission_rate" type="number" step="0.01" class="form-control" value="${plan.commission_rate}" style="width: 100%; box-sizing: border-box;" required>
                        </label>
                    </div>
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Description
                        <input name="description" class="form-control" value="${plan.description || ''}" style="width: 100%; box-sizing: border-box;">
                    </label>
                    
                    <label class="check" style="display:flex; align-items:center; gap:8px; color: var(--muted); font-size: 14px;">
                        <input type="checkbox" name="is_active" value="1" ${plan.is_active == 1 ? 'checked' : ''}> Active
                    </label>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Save Changes',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('editPlanForm');
                if (!form.plan_name.value || !form.duration_days.value || !form.price.value) {
                    Swal.showValidationMessage('Please fill all required fields');
                    return false;
                }
                form.submit();
            }
        });
    }

    function deletePlan(id) {
        Swal.fire({
            title: 'Delete Plan?',
            text: "This action cannot be undone.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, delete',
            confirmButtonColor: '#ef4444',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    function addPlan() {
        Swal.fire({
            title: 'Add Plan',
            html: `
                <form id="addPlanForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <?= csrf_field() ?>
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Plan name *
                        <input name="plan_name" class="form-control" placeholder="e.g. Monthly Basic" style="width: 100%; box-sizing: border-box;" required>
                    </label>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Scope *
                            <select name="plan_scope" class="form-control" style="width: 100%; box-sizing: border-box;" required <?= !$isPlatformAdmin ? 'disabled' : '' ?>>
                                <option value="local">Local</option>
                                <option value="shared" <?= $isPlatformAdmin ? 'selected' : '' ?>>Shared</option>
                            </select>
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Type *
                            <select name="plan_type" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="annual">Annual</option>
                                <option value="custom">Custom</option>
                            </select>
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Duration (days) *
                            <input name="duration_days" type="number" class="form-control" placeholder="30" style="width: 100%; box-sizing: border-box;" required>
                        </label>
                    </div>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Price (PHP) *
                            <input name="price" type="number" step="0.01" class="form-control" placeholder="0.00" style="width: 100%; box-sizing: border-box;" required>
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Commission Rate (%) *
                            <input name="commission_rate" type="number" step="0.01" class="form-control" placeholder="5.00" value="5.00" style="width: 100%; box-sizing: border-box;" required>
                        </label>
                    </div>
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Description
                        <input name="description" class="form-control" placeholder="Optional description" style="width: 100%; box-sizing: border-box;">
                    </label>
                    
                    <label class="check" style="display:flex; align-items:center; gap:8px; color: var(--muted); font-size: 14px;">
                        <input type="checkbox" name="is_active" value="1" checked> Active
                    </label>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Add Plan',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('addPlanForm');
                if (!form.plan_name.value || !form.duration_days.value || !form.price.value) {
                    Swal.showValidationMessage('Please fill all required fields');
                    return false;
                }
                form.submit();
            }
        });
    }
    </script>
    <?php
    render_footer();
}
