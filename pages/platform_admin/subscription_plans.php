<?php
declare(strict_types=1);

function platform_subscription_plans_page(): void
{
    $user = require_roles(['platform_admin']);
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $planKey = trim((string) post('plan_key'));
        $name = trim((string) post('name'));
        $price = (float) post('price');
        $desc = trim((string) post('description'));
        $features = trim((string) post('features'));
        $isPopular = post('is_popular') ? 1 : 0;

        if (!$name || $price < 0 || !$desc) {
            flash('Plan name, price, and description are required.', 'danger');
            redirect('platform_plans');
        }

        // If this plan is set as popular, unset others if needed
        if ($isPopular) {
            $pdo->exec('UPDATE platform_subscription_plans SET is_popular = 0');
        }

        $stmt = $pdo->prepare('
            UPDATE platform_subscription_plans 
            SET name = ?, price = ?, description = ?, features = ?, is_popular = ? 
            WHERE plan_key = ?
        ');
        $stmt->execute([$name, $price, $desc, $features, $isPopular, $planKey]);

        audit_log($user['user_id'], 'update_plan_pricing', 'platform_subscription_plans', $planKey, json_encode([
            'name' => $name,
            'price' => $price,
            'description' => $desc,
            'is_popular' => $isPopular
        ]));

        flash("Plan '{$name}' updated successfully.", 'success');
        redirect('platform_plans');
    }

    $plans = $pdo->query('SELECT * FROM platform_subscription_plans ORDER BY price ASC')->fetchAll(PDO::FETCH_ASSOC);

    render_header('Subscription Plans', $user);
?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Subscription Plans & Pricing</h1>
                <p>Edit pricing, descriptions, and feature bullet points offered to gym owners.</p>
            </div>
            <a href="index.php?page=gym_subscription" target="_blank" class="btn btn-secondary" style="font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                Preview Gym Owner View
            </a>
        </div>

        <div class="plans-editor-grid">
            <?php foreach ($plans as $plan): 
                $isPop = (bool)$plan['is_popular'];
            ?>
                <div class="plan-edit-card <?= $isPop ? 'popular-card' : '' ?>">
                    <div class="card-header">
                        <div>
                            <span class="plan-key-badge"><?= strtoupper(h($plan['plan_key'])) ?> TIER</span>
                            <h2 style="margin: 4px 0 0; font-size: 1.4rem; color: #fff;"><?= h($plan['name']) ?></h2>
                        </div>
                        <?php if ($isPop): ?>
                            <span class="badge" style="background: var(--lime); color: #0b110e; font-weight: 800; font-size: 11px;">MOST POPULAR</span>
                        <?php endif; ?>
                    </div>

                    <form method="post" action="index.php?page=platform_plans" style="display: flex; flex-direction: column; gap: 16px; margin-top: 18px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="plan_key" value="<?= h($plan['plan_key']) ?>">

                        <label style="display:block; font-size: 13px; color: var(--muted); font-weight: 600;">
                            Display Name *
                            <input type="text" name="name" class="form-control" value="<?= h($plan['name']) ?>" required style="margin-top: 5px; width: 100%; box-sizing: border-box;">
                        </label>

                        <label style="display:block; font-size: 13px; color: var(--muted); font-weight: 600;">
                            Monthly Price (₱) *
                            <div style="position: relative; margin-top: 5px;">
                                <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--lime); font-weight: 800; font-size: 16px;">₱</span>
                                <input type="number" step="0.01" name="price" class="form-control" value="<?= h((string)$plan['price']) ?>" required style="padding-left: 32px; width: 100%; box-sizing: border-box; font-weight: 700;">
                            </div>
                        </label>

                        <label style="display:block; font-size: 13px; color: var(--muted); font-weight: 600;">
                            Short Description / Subtitle *
                            <input type="text" name="description" class="form-control" value="<?= h($plan['description']) ?>" required style="margin-top: 5px; width: 100%; box-sizing: border-box;">
                        </label>

                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 10px 12px; border-radius: 8px; background: rgba(255,255,255,0.02); border: 1px solid var(--line);">
                            <input type="checkbox" name="is_popular" value="1" <?= $isPop ? 'checked' : '' ?> style="accent-color: var(--lime);">
                            <span style="font-size: 13px; color: #fff; font-weight: 600;">Highlight as "Most Popular" Plan</span>
                        </label>

                        <label style="display:block; font-size: 13px; color: var(--muted); font-weight: 600;">
                            Feature Bullet Points
                            <span style="display: block; font-size: 11px; color: var(--muted); font-weight: 400; margin-top: 2px;">Enter one feature per line. Checkmarks (✓) will be auto-rendered.</span>
                            <textarea name="features" rows="6" class="form-control" style="margin-top: 6px; width: 100%; box-sizing: border-box; resize: vertical; line-height: 1.5; font-family: inherit;"><?= h($plan['features']) ?></textarea>
                        </label>

                        <div style="margin-top: 8px;">
                            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-weight: 700;">
                                Save <?= h($plan['name']) ?> Changes
                            </button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <style>
        .plans-editor-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-top: 20px;
        }

        .plan-edit-card {
            background: #0f1512;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            transition: border-color 0.2s;
        }

        .plan-edit-card.popular-card {
            border: 2px solid var(--lime);
            box-shadow: 0 0 25px rgba(34, 197, 94, 0.1);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }

        .plan-key-badge {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.8px;
            color: var(--muted);
        }

        @media (max-width: 1024px) {
            .plans-editor-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
<?php
    render_footer();
}
