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
        </div>

        <div class="form-card">
            <h3>Add Plan</h3>
            <form method="post" class="form inline-form">
                <?= csrf_field() ?>
                <label>Plan name <input name="plan_name" placeholder="e.g. Monthly Basic" required></label>
                <label>Type
                    <select name="plan_type">
                        <option>monthly</option>
                        <option>quarterly</option>
                        <option>annual</option>
                        <option>custom</option>
                    </select>
                </label>
                <label>Duration (days) <input name="duration_days" type="number" placeholder="30" required></label>
                <label>Price (PHP) <input name="price" type="number" step="0.01" placeholder="0.00" required></label>
                <label>Description <input name="description" placeholder="Optional description"></label>
                <label class="check" style="align-self:end;padding-bottom:10px">
                    <input type="checkbox" name="is_active" value="1" checked> Active
                </label>
                <label>&nbsp;<button>Add plan</button></label>
            </form>
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
    <?php
    render_footer();
}
