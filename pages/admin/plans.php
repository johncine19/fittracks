<?php
declare(strict_types=1);

function plans_page(): void
{
    $user = require_roles(['gym_owner']);
    $pdo = db();
    
    $gymId = (int) scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action', 'add');
        if ($action === 'delete') {
            $pdo->prepare('DELETE FROM membership_plans WHERE plan_id = ? AND gym_id = ?')->execute([post('id'), $gymId]);
            audit_log($user['user_id'], 'delete', 'plan', (string) post('id'));
            flash('Membership plan deleted.');
        } elseif ($action === 'edit') {
            $price = (float) post('price');
            $isPopular = post('is_popular') ? 1 : 0;
            if ($isPopular) {
                $pdo->prepare('UPDATE membership_plans SET is_popular = 0 WHERE gym_id = ?')->execute([$gymId]);
            }
            $pdo->prepare('UPDATE membership_plans SET plan_name = ?, plan_type = ?, duration_days = ?, price = ?, description = ?, is_active = ?, commission_rate = ?, is_popular = ? WHERE plan_id = ? AND gym_id = ?')->execute([
                post('plan_name'),
                post('plan_type'),
                post('duration_days'),
                $price,
                post('description'),
                post('is_active', 0) ? 1 : 0,
                post('commission_rate', 5.0),
                $isPopular,
                post('id'),
                $gymId
            ]);
            
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

            audit_log($user['user_id'], 'edit', 'plan', (string) post('id'), json_encode(['plan_name' => post('plan_name'), 'price' => $price, 'is_popular' => $isPopular]));
            flash('Membership plan updated.');
        } else {
            $price = (float) post('price');
            $isPopular = post('is_popular') ? 1 : 0;
            if ($isPopular) {
                $pdo->prepare('UPDATE membership_plans SET is_popular = 0 WHERE gym_id = ?')->execute([$gymId]);
            }
            $pdo->prepare('INSERT INTO membership_plans (gym_id, plan_name, plan_type, duration_days, price, description, is_active, commission_rate, is_popular) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$gymId, post('plan_name'), post('plan_type'), post('duration_days'), $price, post('description'), post('is_active', 0) ? 1 : 0, post('commission_rate', 5.0), $isPopular]);
            audit_log($user['user_id'], 'create', 'plan', (string) $pdo->lastInsertId(), json_encode(['plan_name' => post('plan_name'), 'price' => $price, 'is_popular' => $isPopular]));
            flash('Membership plan saved.');
        }
        redirect('plans');
    }
    
    $plans = $pdo->query('SELECT * FROM membership_plans WHERE gym_id = ' . $gymId . ' ORDER BY is_active DESC, price')->fetchAll();
    
    render_header('Plans', $user);
    ?>
    <section class="panel">
        <div class="page-header" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom: 24px;">
            <div>
                <h1 style="margin:0 0 6px 0;">Membership Plans & Pricing</h1>
                <p style="margin:0; color:var(--muted);">Edit pricing, descriptions, and feature bullet points offered to gym members.</p>
            </div>
            <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                <a href="index.php?page=memberships" target="_blank" class="btn btn-secondary plan-preview-link">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    Preview Member View
                </a>
                <button onclick="addPlan()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold;">+ New Plan</button>
            </div>
        </div>

        <?php if (!$plans): ?>
            <div class="empty-state" style="padding: 48px 20px; text-align: center;">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--muted); margin-bottom:16px;"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                <h3 style="margin: 0 0 8px 0; color: var(--ink);">No membership plans created yet</h3>
                <p style="color: var(--muted); margin: 0 0 20px 0;">Create your first subscription tier to let members sign up and pay.</p>
                <button onclick="addPlan()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold;">+ Create First Plan</button>
            </div>
        <?php else: ?>
            <div class="plans-editor-grid">
                <?php 
                $hasCustomPopular = false;
                foreach ($plans as $p) {
                    if (!empty($p['is_popular'])) {
                        $hasCustomPopular = true;
                        break;
                    }
                }
                foreach ($plans as $index => $plan): 
                    $isPop = $hasCustomPopular ? !empty($plan['is_popular']) : ($index === 1 || $plan['plan_type'] === 'quarterly');
                    $tierLabel = strtoupper($plan['plan_type'] ?: 'CUSTOM') . ' TIER';
                ?>
                    <div class="plan-edit-card <?= $isPop ? 'popular-card' : '' ?>">
                        <div class="card-header">
                            <div>
                                <span class="plan-key-badge"><?= h($tierLabel) ?></span>
                                <h2 class="plan-title"><?= h($plan['plan_name']) ?></h2>
                            </div>
                            <?php if ($isPop): ?>
                                <span class="popular-badge"><span class="badge-dot">●</span> MOST POPULAR</span>
                            <?php endif; ?>
                        </div>

                        <form method="post" action="index.php?page=plans" class="plan-edit-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="id" value="<?= (int)$plan['plan_id'] ?>">
                            <input type="hidden" name="plan_type" value="<?= h($plan['plan_type']) ?>">

                            <label class="plan-label">
                                Display Name *
                                <input type="text" name="plan_name" class="form-control plan-input" value="<?= h($plan['plan_name']) ?>" required>
                            </label>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <label class="plan-label">
                                    Price (PHP) *
                                    <div class="price-input-wrap">
                                        <span class="currency-symbol">₱</span>
                                        <input type="number" step="0.01" name="price" class="form-control plan-input price-input" value="<?= h((string)$plan['price']) ?>" required>
                                    </div>
                                </label>

                                <label class="plan-label">
                                    Duration (Days) *
                                    <input type="number" name="duration_days" class="form-control plan-input" value="<?= (int)$plan['duration_days'] ?>" required>
                                </label>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <label class="plan-label">
                                    Commission Rate (%)
                                    <input type="number" step="0.01" name="commission_rate" class="form-control plan-input" value="<?= h((string)$plan['commission_rate']) ?>" required>
                                </label>

                                <label class="plan-label" style="display: flex; flex-direction: column; justify-content: flex-end;">
                                    <span style="display: block; margin-bottom: 6px;">Status</span>
                                    <label class="popular-checkbox-label" style="padding: 9px 12px; margin: 0; min-height: 42px; box-sizing: border-box;">
                                        <input type="checkbox" name="is_active" value="1" <?= $plan['is_active'] ? 'checked' : '' ?> class="popular-checkbox">
                                        <span class="popular-checkbox-text">Active Plan</span>
                                    </label>
                                </label>
                            </div>

                            <label class="popular-checkbox-label">
                                <input type="checkbox" name="is_popular" value="1" <?= $isPop ? 'checked' : '' ?> class="popular-checkbox plan-popular-toggle">
                                <span class="popular-checkbox-text">Highlight as "Most Popular" Plan</span>
                            </label>

                            <label class="plan-label">
                                Feature Bullet Points
                                <span class="field-hint">Enter one feature per line. Checkmarks (✓) will be auto-rendered.</span>
                                <textarea name="description" rows="6" class="form-control plan-input plan-textarea" placeholder="Full Gym Access&#10;Group Class Bookings&#10;Locker Room Access"><?= h($plan['description'] ?? '') ?></textarea>
                            </label>

                            <div style="display: flex; gap: 10px; align-items: center; margin-top: 8px;">
                                <button type="submit" class="btn btn-primary plan-save-btn" style="flex: 1;">
                                    Save <?= h($plan['plan_name']) ?> Changes
                                </button>
                                <button type="button" onclick="deletePlan(<?= (int)$plan['plan_id'] ?>)" title="Delete Plan" class="plan-delete-btn" style="padding: 12px 14px; background: rgba(239, 68, 68, 0.12); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.25); border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: background 0.2s;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 24px;
            margin-top: 10px;
        }

        @media (min-width: 1080px) {
            .plans-editor-grid {
                grid-template-columns: repeat(3, 1fr);
            }
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
            border: 2px solid var(--lime, #84cc16);
            box-shadow: 0 0 25px rgba(132, 204, 22, 0.15);
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
            background: var(--lime, #84cc16);
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
            color: var(--lime, #84cc16);
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
            background: var(--lime, #84cc16);
            color: #0b110e;
            border: none;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
        }

        .plan-save-btn:hover {
            background: #73b711;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(132, 204, 22, 0.35);
        }

        .plan-save-btn:active {
            transform: translateY(0);
        }

        .plan-delete-btn:hover {
            background: rgba(239, 68, 68, 0.22) !important;
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
            border: 2px solid var(--lime, #22c55e);
            box-shadow: 0 10px 25px -3px rgba(34, 197, 94, 0.18), 0 4px 6px -2px rgba(34, 197, 94, 0.05);
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
        }

        [data-theme="light"] .plan-input {
            background: #ffffff;
            border-color: #cbd5e1;
            color: #0f172a;
        }

        [data-theme="light"] .plan-input:focus {
            background: #ffffff;
            border-color: var(--lime);
        }

        [data-theme="light"] .popular-checkbox-label {
            background: #f8fafc;
            border-color: #e2e8f0;
        }

        [data-theme="light"] .popular-checkbox-label:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        [data-theme="light"] .popular-checkbox-text {
            color: #0f172a;
        }

        [data-theme="light"] .plan-label {
            color: #475569;
        }
    </style>

    <script>
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
            title: 'Add New Plan',
            html: `
                <form id="addPlanForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <?= csrf_field() ?>
                    
                    <label style="display:block; color: var(--muted); font-size: 13.5px; font-weight: 600;">Plan name *
                        <input name="plan_name" class="form-control" placeholder="e.g. Monthly Basic" style="width: 100%; box-sizing: border-box; margin-top: 5px;" required>
                    </label>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13.5px; font-weight: 600;">Type *
                            <select name="plan_type" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 5px;" required>
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="annual">Annual</option>
                                <option value="custom">Custom</option>
                            </select>
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13.5px; font-weight: 600;">Duration (days) *
                            <input name="duration_days" type="number" class="form-control" placeholder="30" style="width: 100%; box-sizing: border-box; margin-top: 5px;" required>
                        </label>
                    </div>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13.5px; font-weight: 600;">Price (PHP) *
                            <input name="price" type="number" step="0.01" class="form-control" placeholder="0.00" style="width: 100%; box-sizing: border-box; margin-top: 5px;" required>
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13.5px; font-weight: 600;">Commission Rate (%) *
                            <input name="commission_rate" type="number" step="0.01" class="form-control" placeholder="5.00" value="5.00" style="width: 100%; box-sizing: border-box; margin-top: 5px;" required>
                        </label>
                    </div>
                    
                    <label style="display:block; color: var(--muted); font-size: 13.5px; font-weight: 600;">
                        Feature Bullet Points
                        <span style="font-size:11.5px; color:var(--muted); font-weight:normal; display:block; margin-top:2px;">(Enter one feature per line. Checkmarks ✓ will be auto-rendered)</span>
                        <textarea name="description" class="form-control" rows="5" style="width: 100%; box-sizing: border-box; resize: vertical; margin-top: 5px; font-family: inherit; font-size: 13px; line-height: 1.45;" placeholder="Full Gym Access&#10;Standard Class Bookings&#10;Locker Room Access"></textarea>
                    </label>
                    
                    <div style="display:flex; flex-direction:column; gap:8px; margin-top: 4px;">
                        <label class="check" style="display:flex; align-items:center; gap:8px; color: var(--ink); font-size: 13.5px; font-weight: 600; cursor: pointer;">
                            <input type="checkbox" name="is_popular" value="1" style="accent-color: var(--lime); width: 16px; height: 16px;"> Highlight as "Most Popular" Plan
                        </label>
                        <label class="check" style="display:flex; align-items:center; gap:8px; color: var(--ink); font-size: 13.5px; font-weight: 600; cursor: pointer;">
                            <input type="checkbox" name="is_active" value="1" checked style="accent-color: var(--lime); width: 16px; height: 16px;"> Active Plan
                        </label>
                    </div>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Add Plan',
            confirmButtonColor: 'var(--lime-dark, #84cc16)',
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

    // Dynamic UI feedback when checking "Highlight as Most Popular Plan"
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.plan-popular-toggle').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    // Uncheck all other cards' popular toggles
                    document.querySelectorAll('.plan-popular-toggle').forEach(other => {
                        if (other !== this) {
                            other.checked = false;
                            const otherCard = other.closest('.plan-edit-card');
                            if (otherCard) {
                                otherCard.classList.remove('popular-card');
                                const badge = otherCard.querySelector('.popular-badge');
                                if (badge) badge.style.display = 'none';
                            }
                        }
                    });
                    const card = this.closest('.plan-edit-card');
                    if (card) {
                        card.classList.add('popular-card');
                        let badge = card.querySelector('.popular-badge');
                        if (badge) {
                            badge.style.display = 'inline-flex';
                        } else {
                            const header = card.querySelector('.card-header');
                            if (header) {
                                const newBadge = document.createElement('span');
                                newBadge.className = 'popular-badge';
                                newBadge.innerHTML = '<span class="badge-dot">●</span> MOST POPULAR';
                                header.appendChild(newBadge);
                            }
                        }
                    }
                } else {
                    const card = this.closest('.plan-edit-card');
                    if (card) {
                        card.classList.remove('popular-card');
                        const badge = card.querySelector('.popular-badge');
                        if (badge) badge.style.display = 'none';
                    }
                }
            });
        });
    });
    </script>
    <?php
    render_footer();
}
