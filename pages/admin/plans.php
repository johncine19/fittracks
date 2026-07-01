<?php
declare(strict_types=1);

function plans_page(): void
{
    $user = require_roles(['admin']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        db()->prepare('INSERT INTO membership_plans (plan_name, plan_type, duration_days, price, description, is_active) VALUES (?, ?, ?, ?, ?, ?)')->execute([post('plan_name'), post('plan_type'), post('duration_days'), post('price'), post('description'), post('is_active', 0) ? 1 : 0]);
        flash('Membership plan saved.');
        redirect('plans');
    }
    $plans = db()->query('SELECT * FROM membership_plans ORDER BY is_active DESC, price')->fetchAll();
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
                    <tr><th>Name</th><th>Type</th><th>Duration</th><th>Price</th><th>Status</th><th>Description</th></tr>
                </thead>
                <tbody>
                <?php foreach ($plans as $plan): ?>
                    <tr>
                        <td><strong><?= h($plan['plan_name']) ?></strong></td>
                        <td style="font-size:13px;color:var(--muted)"><?= h(ucfirst($plan['plan_type'])) ?></td>
                        <td style="font-size:13px"><?= (int) $plan['duration_days'] ?> days</td>
                        <td><strong style="color:var(--lime)"><?= h(money($plan['price'])) ?></strong></td>
                        <td>
                            <?php if ($plan['is_active']): ?>
                                <span class="badge badge-active">Active</span>
                            <?php else: ?>
                                <span class="badge badge-inactive">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:var(--muted);font-size:13px"><?= h($plan['description'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
    
    <script>
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
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Price (PHP) *
                        <input name="price" type="number" step="0.01" class="form-control" placeholder="0.00" style="width: 100%; box-sizing: border-box;" required>
                    </label>
                    
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
