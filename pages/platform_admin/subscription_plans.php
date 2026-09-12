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
            <a href="index.php?page=gym_subscription" target="_blank" class="btn btn-secondary plan-preview-link">
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
                            <h2 class="plan-title"><?= h($plan['name']) ?></h2>
                        </div>
                        <?php if ($isPop): ?>
                            <span class="popular-badge"><span class="badge-dot">●</span> MOST POPULAR</span>
                        <?php endif; ?>
                    </div>

                    <form method="post" action="index.php?page=platform_plans" class="plan-edit-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="plan_key" value="<?= h($plan['plan_key']) ?>">

                        <label class="plan-label">
                            Display Name *
                            <input type="text" name="name" class="form-control plan-input" value="<?= h($plan['name']) ?>" required>
                        </label>

                        <label class="plan-label">
                            Monthly Price (₱) *
                            <div class="price-input-wrap">
                                <span class="currency-symbol">₱</span>
                                <input type="number" step="0.01" name="price" class="form-control plan-input price-input" value="<?= h((string)$plan['price']) ?>" required>
                            </div>
                        </label>

                        <label class="plan-label">
                            Short Description / Subtitle *
                            <input type="text" name="description" class="form-control plan-input" value="<?= h($plan['description']) ?>" required>
                        </label>

                        <label class="popular-checkbox-label">
                            <input type="checkbox" name="is_popular" value="1" <?= $isPop ? 'checked' : '' ?> class="popular-checkbox">
                            <span class="popular-checkbox-text">Highlight as "Most Popular" Plan</span>
                        </label>

                        <label class="plan-label">
                            Feature Bullet Points
                            <span class="field-hint">Enter one feature per line. Checkmarks (✓) will be auto-rendered.</span>
                            <textarea name="features" rows="6" class="form-control plan-input plan-textarea"><?= h($plan['features']) ?></textarea>
                        </label>

                        <div style="margin-top: 8px;">
                            <button type="submit" class="btn btn-primary plan-save-btn">
                                Save <?= h($plan['name']) ?> Changes
                            </button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <style>
        .plan-preview-link {
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

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
            transition: border-color 0.2s, box-shadow 0.2s, background-color 0.2s;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
        }

        .plan-edit-card.popular-card {
            border: 2px solid var(--lime);
            box-shadow: 0 0 25px rgba(34, 197, 94, 0.12);
            background: #111a15;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            min-height: 52px;
        }

        .plan-key-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.8px;
            color: var(--muted);
            background: rgba(255, 255, 255, 0.06);
            padding: 2px 7px;
            border-radius: 4px;
        }

        .plan-title {
            margin: 6px 0 0;
            font-size: 1.45rem;
            font-weight: 800;
            color: #f8fafc;
            line-height: 1.2;
        }

        .popular-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--lime);
            color: #0b110e;
            font-weight: 800;
            font-size: 11px;
            letter-spacing: 0.4px;
            padding: 4px 10px;
            border-radius: 999px;
            white-space: nowrap;
        }

        .badge-dot {
            font-size: 8px;
            line-height: 1;
        }

        .plan-edit-form {
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-top: 18px;
        }

        .plan-label {
            display: block;
            font-size: 13px;
            color: var(--muted);
            font-weight: 600;
        }

        .field-hint {
            display: block;
            font-size: 11px;
            color: var(--muted);
            font-weight: 400;
            margin-top: 2px;
        }

        .plan-input {
            margin-top: 5px;
            width: 100%;
            box-sizing: border-box;
            background: rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #f8fafc;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s, background-color 0.2s;
        }

        .plan-input:focus {
            outline: none;
            border-color: var(--lime);
            background: rgba(0, 0, 0, 0.5);
            box-shadow: 0 0 0 3px rgba(199, 255, 34, 0.15);
        }

        .price-input-wrap {
            position: relative;
            margin-top: 5px;
        }

        .currency-symbol {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--lime);
            font-weight: 800;
            font-size: 16px;
            pointer-events: none;
        }

        .price-input {
            margin-top: 0 !important;
            padding-left: 32px !important;
            font-weight: 700;
        }

        .popular-checkbox-label {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            padding: 11px 13px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--line);
            transition: background 0.2s, border-color 0.2s;
        }

        .popular-checkbox-label:hover {
            background: rgba(255, 255, 255, 0.06);
            border-color: rgba(255, 255, 255, 0.16);
        }

        .popular-checkbox {
            accent-color: var(--lime);
            width: 17px;
            height: 17px;
            cursor: pointer;
            margin: 0;
        }

        .popular-checkbox-text {
            font-size: 13px;
            color: #f8fafc;
            font-weight: 600;
        }

        .plan-textarea {
            resize: vertical;
            line-height: 1.55;
            font-family: inherit;
        }

        .plan-save-btn {
            width: 100%;
            padding: 12px;
            font-weight: 700;
            border-radius: 8px;
            font-size: 14px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .plan-save-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .plan-save-btn:active {
            transform: translateY(0);
        }

        /* ========================================================
           LIGHT MODE OVERRIDES ([data-theme="light"])
           ======================================================== */
        [data-theme="light"] .plan-edit-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.05), 0 2px 6px -1px rgba(15, 23, 42, 0.03);
        }

        [data-theme="light"] .plan-edit-card.popular-card {
            background: #ffffff;
            border: 2px solid var(--lime);
            box-shadow: 0 10px 25px -3px rgba(101, 163, 13, 0.18), 0 4px 6px -2px rgba(101, 163, 13, 0.05);
        }

        [data-theme="light"] .card-header {
            border-bottom: 1px solid #e2e8f0;
        }

        [data-theme="light"] .plan-key-badge {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        [data-theme="light"] .plan-title {
            color: #0f172a;
        }

        [data-theme="light"] .popular-badge {
            background: #ecfccb;
            color: #365314;
            border: 1px solid #a3e635;
        }

        [data-theme="light"] .plan-label {
            color: #475569;
        }

        [data-theme="light"] .field-hint {
            color: #64748b;
        }

        [data-theme="light"] .plan-input {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            color: #0f172a;
        }

        [data-theme="light"] .plan-input:focus {
            background: #ffffff;
            border-color: var(--lime);
            box-shadow: 0 0 0 3px rgba(101, 163, 13, 0.16);
        }

        [data-theme="light"] .popular-checkbox-label {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        [data-theme="light"] .popular-checkbox-label:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
        }

        [data-theme="light"] .popular-checkbox-text {
            color: #0f172a;
        }

        [data-theme="light"] .plan-save-btn {
            background: var(--lime);
            color: #ffffff !important;
        }

        [data-theme="light"] .plan-save-btn:hover {
            background: var(--lime-dark);
            box-shadow: 0 4px 14px rgba(101, 163, 13, 0.35);
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

