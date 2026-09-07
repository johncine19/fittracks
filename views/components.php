<?php
declare(strict_types=1);

function render_simple_table(array $rows, array $columns): string
{
    ob_start();
    echo '<div class="table-wrap"><table><thead><tr>';
    foreach ($columns as $column) {
        echo '<th>' . h(ucwords(str_replace('_', ' ', $column))) . '</th>';
    }
    echo '</tr></thead><tbody>';
    if (!$rows) {
        table_empty(count($columns), 'No records yet.');
    }
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($columns as $column) {
            $value = $row[$column] ?? '';
            if ($column === 'price' || $column === 'revenue' || $column === 'amount') $value = money($value);
            if (in_array($column, ['action', 'actions', 'progress', 'adherence', 'feedback', 'start_date', 'end_date'])) {
                echo '<td>' . (string) $value . '</td>';
            } else {
                echo '<td>' . h((string) $value) . '</td>';
            }
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    return ob_get_clean();
}

function render_member_form(string $context, ?array $user = null, ?array $profile = null): void
{
    ?>
    <style>
        .pm-tab-bar {
            display: flex;
            background: var(--panel-soft);
            padding: 4px;
            border-radius: 10px;
            gap: 4px;
            margin-bottom: 18px;
            border: 1px solid var(--line);
        }
        .pm-tab-btn {
            flex: 1;
            padding: 8px 10px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--muted);
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.18s ease;
        }
        .pm-tab-btn:hover {
            color: var(--ink);
        }
        .pm-tab-btn.active {
            background: var(--panel);
            color: var(--ink);
            border-color: var(--line);
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .pm-pane {
            display: none;
            animation: pmFadeIn 0.2s ease;
        }
        .pm-pane.active {
            display: block;
        }
        @keyframes pmFadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .pm-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 14px;
        }
        .pm-grid .full-span {
            grid-column: 1 / -1;
        }
        .pm-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
        }
        .pm-field input, .pm-field select {
            width: 100%;
            box-sizing: border-box;
            background: var(--panel);
            border: 1.5px solid var(--line);
            color: var(--ink);
            border-radius: 8px;
            padding: 9px 12px;
            font-size: 13.5px;
            font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .pm-field input:focus, .pm-field select:focus {
            border-color: var(--lime);
            outline: none;
            box-shadow: 0 0 0 3px rgba(199, 255, 34, 0.18);
        }
        .pm-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding-top: 14px;
            border-top: 1px solid var(--line);
            gap: 10px;
        }
        @media (max-width: 520px) {
            .pm-grid { grid-template-columns: 1fr; }
            .pm-tab-btn span.tab-title { display: none; }
        }
    </style>

    <form method="post" class="pm-form" onsubmit="const btn = this.querySelector('button[type=submit]'); btn.disabled = true; btn.innerHTML = '<span class=\'loader\' style=\'width:14px;height:14px;border:2px solid var(--bg);border-bottom-color:transparent;border-radius:50%;display:inline-block;box-sizing:border-box;animation:rotation 1s linear infinite;margin-right:6px;vertical-align:-2px;\'></span> Saving...';">
        <?= csrf_field() ?>

        <!-- Segmented Tab Header -->
        <div class="pm-tab-bar" role="tablist">
            <button type="button" class="pm-tab-btn active" id="tabBtn_<?= h($context) ?>_body" onclick="switchProfileTab_<?= h($context) ?>('body')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.3 15.3a2.4 2.4 0 0 1 0 3.4l-2.6 2.6a2.4 2.4 0 0 1-3.4 0L2.7 8.7a2.41 2.41 0 0 1 0-3.4l2.6-2.6a2.41 2.41 0 0 1 3.4 0Z"/><path d="m14.5 12.5 2-2"/><path d="m11.5 9.5 2-2"/><path d="m8.5 6.5 2-2"/><path d="m17.5 15.5 2-2"/></svg>
                <span class="tab-title">Body Stats</span>
            </button>
            <button type="button" class="pm-tab-btn" id="tabBtn_<?= h($context) ?>_goal" onclick="switchProfileTab_<?= h($context) ?>('goal')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                <span class="tab-title">Goal & Targets</span>
            </button>
            <button type="button" class="pm-tab-btn" id="tabBtn_<?= h($context) ?>_lifestyle" onclick="switchProfileTab_<?= h($context) ?>('lifestyle')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                <span class="tab-title">Lifestyle</span>
            </button>
        </div>

        <!-- TAB 1: BODY STATS -->
        <div class="pm-pane active" id="tabPane_<?= h($context) ?>_body">
            <div class="pm-grid">
                <?php if ($context !== 'profile'): ?>
                    <label class="pm-field">First name <input name="first_name" required value="<?= h($user['first_name'] ?? '') ?>"></label>
                    <label class="pm-field">Last name  <input name="last_name"  required value="<?= h($user['last_name']  ?? '') ?>"></label>
                    <label class="pm-field">Email      <input type="email" name="email" required value="<?= h($user['email'] ?? '') ?>"></label>
                    <label class="pm-field">Phone
                        <input name="phone" type="tel" pattern="[0-9]{11}" maxlength="11"
                               title="Please enter exactly 11 digits" placeholder="09123456789"
                               value="<?= h($user['phone'] ?? '') ?>"
                               oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)">
                    </label>
                    <label class="pm-field full-span">Password   <input type="password" name="password" <?= $context === 'register' ? 'required minlength="8"' : '' ?> placeholder="<?= $context === 'register' ? 'Min. 8 characters' : 'Leave blank to keep current' ?>"></label>
                <?php endif; ?>

                <label class="pm-field">Height (cm)
                    <input name="height_cm" type="number" step="0.01" min="1"
                           <?= $context !== 'profile' ? 'required' : '' ?>
                           value="<?= h($profile['height_cm'] ?? '') ?>">
                </label>
                <label class="pm-field">Weight (kg)
                    <input name="weight_kg" type="number" step="0.01" min="1"
                           <?= $context !== 'profile' ? 'required' : '' ?>
                           value="<?= h($profile['weight_kg'] ?? '') ?>">
                </label>
                <label class="pm-field">Age
                    <input name="age" type="number" min="16" max="120"
                           class="<?= isset($errors['age']) ? 'input-error' : '' ?>"
                           value="<?= h($profile['age'] ?? '') ?>">
                </label>
                <label class="pm-field">Biological sex
                    <select name="biological_sex" onchange="document.getElementById('hipContainer_<?= h($context) ?>').style.display = this.value === 'female' ? 'flex' : 'none';" <?= $context !== 'profile' ? 'required' : '' ?>>
                        <option value="male"   <?= selected('male',   $profile['biological_sex'] ?? null) ?>>Male</option>
                        <option value="female" <?= selected('female', $profile['biological_sex'] ?? null) ?>>Female</option>
                    </select>
                </label>
                <label class="pm-field">Neck (cm)
                    <input name="neck_cm" type="number" step="0.01" min="1"
                           value="<?= h($profile['neck_cm'] ?? '') ?>">
                </label>
                <label class="pm-field">Waist (cm)
                    <input name="waist_cm" type="number" step="0.01" min="1"
                           value="<?= h($profile['waist_cm'] ?? '') ?>">
                </label>
                <label class="pm-field full-span" id="hipContainer_<?= h($context) ?>" style="display: <?= ($profile['biological_sex'] ?? 'male') === 'female' ? 'flex' : 'none' ?>;">Hip (cm)
                    <input name="hip_cm" type="number" step="0.01" min="1"
                           value="<?= h($profile['hip_cm'] ?? '') ?>">
                </label>
            </div>
        </div>

        <!-- TAB 2: GOAL & TARGETS -->
        <div class="pm-pane" id="tabPane_<?= h($context) ?>_goal">
            <div class="pm-grid">
                <label class="pm-field full-span">Primary goal
                    <select name="primary_goal" id="primaryGoalSelect_<?= h($context) ?>" onchange="updateTargetMetrics_<?= h($context) ?>(this.value)" <?= $context !== 'profile' ? 'required' : '' ?>>
                        <optgroup label="Aesthetic & Muscle Building">
                            <option value="Building a visible six-pack" <?= selected("Building a visible six-pack", $profile['primary_goal'] ?? null) ?>>Building a visible six-pack</option>
                            <option value="Growing larger biceps and arms" <?= selected("Growing larger biceps and arms", $profile['primary_goal'] ?? null) ?>>Growing larger biceps and arms</option>
                            <option value="Developing a wide chest" <?= selected("Developing a wide chest", $profile['primary_goal'] ?? null) ?>>Developing a wide chest</option>
                            <option value="Sculpting a V-tapered back" <?= selected("Sculpting a V-tapered back", $profile['primary_goal'] ?? null) ?>>Sculpting a V-tapered back</option>
                            <option value="Shaping the lower body" <?= selected("Shaping the lower body", $profile['primary_goal'] ?? null) ?>>Shaping the lower body</option>
                        </optgroup>
                        <optgroup label="Athletic & Performance">
                            <option value="Increasing maximum strength" <?= selected("Increasing maximum strength", $profile['primary_goal'] ?? null) ?>>Increasing maximum strength</option>
                            <option value="Boosting explosive power" <?= selected("Boosting explosive power", $profile['primary_goal'] ?? null) ?>>Boosting explosive power</option>
                            <option value="Enhancing physical endurance" <?= selected("Enhancing physical endurance", $profile['primary_goal'] ?? null) ?>>Enhancing physical endurance</option>
                            <option value="Improving body flexibility" <?= selected("Improving body flexibility", $profile['primary_goal'] ?? null) ?>>Improving body flexibility</option>
                        </optgroup>
                        <optgroup label="Body Composition">
                            <option value="Losing excess body fat" <?= selected("Losing excess body fat", $profile['primary_goal'] ?? null) ?>>Losing excess body fat</option>
                            <option value="Gaining lean body mass" <?= selected("Gaining lean body mass", $profile['primary_goal'] ?? null) ?>>Gaining lean body mass</option>
                            <option value="Reaching body recomposition" <?= selected("Reaching body recomposition", $profile['primary_goal'] ?? null) ?>>Reaching body recomposition</option>
                        </optgroup>
                        <optgroup label="General">
                            <option value="fat_loss" <?= selected("fat_loss", $profile['primary_goal'] ?? null) ?>>Fat Loss</option>
                            <option value="muscle_gain" <?= selected("muscle_gain", $profile['primary_goal'] ?? null) ?>>Muscle Gain</option>
                            <option value="maintenance" <?= selected("maintenance", $profile['primary_goal'] ?? null) ?>>Maintenance</option>
                            <option value="general_health" <?= selected("general_health", $profile['primary_goal'] ?? null) ?>>General Health</option>
                        </optgroup>
                    </select>
                </label>

                <!-- Arm Target -->
                <label class="pm-field" id="targetArmField_<?= h($context) ?>" style="display: none;">
                    Target Arm Size (cm) <span class="muted">(Peak Flexed)</span>
                    <input type="number" step="0.1" name="target_arm_cm" value="<?= h((string)($profile['target_arm_cm'] ?? '')) ?>" placeholder="e.g. 38.0">
                </label>

                <!-- Chest Target -->
                <label class="pm-field" id="targetChestField_<?= h($context) ?>" style="display: none;">
                    Target Chest Size (cm) <span class="muted">(Fullest Point)</span>
                    <input type="number" step="0.1" name="target_chest_cm" value="<?= h((string)($profile['target_chest_cm'] ?? '')) ?>" placeholder="e.g. 105.0">
                </label>

                <!-- Waist Target -->
                <label class="pm-field" id="targetWaistField_<?= h($context) ?>" style="display: none;">
                    Target Waist Size (cm) <span class="muted">(At Navel)</span>
                    <input type="number" step="0.1" name="target_waist_cm" value="<?= h((string)($profile['target_waist_cm'] ?? '')) ?>" placeholder="e.g. 78.0">
                </label>

                <!-- Target Weight (kg) -->
                <label class="pm-field" id="targetWeightField_<?= h($context) ?>">
                    Target Weight (kg) <span class="muted">(Optional)</span>
                    <input type="number" step="0.1" name="target_weight_kg" value="<?= h((string)($profile['target_weight_kg'] ?? '')) ?>" placeholder="e.g. 75.0">
                </label>

                <!-- Target Body Fat (%) -->
                <label class="pm-field" id="targetBodyFatField_<?= h($context) ?>">
                    Target Body Fat (%) <span class="muted">(Optional)</span>
                    <input type="number" step="0.1" name="target_body_fat_percent" value="<?= h((string)($profile['target_body_fat_percent'] ?? '')) ?>" placeholder="e.g. 15.0">
                </label>

                <!-- Contextual Fitness Guidance Tip -->
                <div class="full-span" id="targetGoalTip_<?= h($context) ?>" style="margin-top: 4px; padding: 10px 14px; border-radius: 8px; font-size: 12px; line-height: 1.4; display: flex; align-items: center; gap: 10px; background: var(--panel-soft); border: 1px solid var(--line); color: var(--ink);">
                    <span id="targetGoalTipIcon_<?= h($context) ?>" style="display: flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 6px; background: rgba(199,255,34,0.12); color: var(--lime); flex-shrink: 0;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                    </span>
                    <span id="targetGoalTipText_<?= h($context) ?>">Set target metrics to track your fitness milestones over time.</span>
                </div>
            </div>
        </div>

        <!-- TAB 3: LIFESTYLE & DIET -->
        <div class="pm-pane" id="tabPane_<?= h($context) ?>_lifestyle">
            <div class="pm-grid">
                <label class="pm-field">Activity level
                    <select name="activity_level" <?= $context !== 'profile' ? 'required' : '' ?>>
                        <?php foreach (['sedentary', 'lightly_active', 'moderately_active', 'very_active', 'extra_active'] as $level): ?>
                            <option value="<?= h($level) ?>" <?= selected($level, $profile['activity_level'] ?? null) ?>>
                                <?= h(ucwords(str_replace('_', ' ', $level))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="pm-field">Dietary Restrictions
                    <select name="dietary_restrictions" required>
                        <?php foreach (['none', 'vegetarian', 'vegan', 'pescatarian', 'halal', 'gluten-free', 'keto', 'paleo', 'nut-allergy', 'dairy-free'] as $diet): ?>
                            <option value="<?= h($diet) ?>" <?= selected($diet, $profile['dietary_restrictions'] ?? 'none') ?>>
                                <?= h(ucwords(str_replace('-', ' ', $diet))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="pm-field full-span">Experience level
                    <select name="experience_level" <?= $context !== 'profile' ? 'required' : '' ?>>
                        <option value="1" <?= selected('1', isset($profile['fitness_tier']) ? (string)(in_array((int)$profile['fitness_tier'], [1,2]) ? 1 : (in_array((int)$profile['fitness_tier'], [3,4]) ? 2 : 3)) : null) ?>>Starter</option>
                        <option value="2" <?= selected('2', isset($profile['fitness_tier']) ? (string)(in_array((int)$profile['fitness_tier'], [1,2]) ? 1 : (in_array((int)$profile['fitness_tier'], [3,4]) ? 2 : 3)) : null) ?>>Intermediate</option>
                        <option value="3" <?= selected('3', isset($profile['fitness_tier']) ? (string)(in_array((int)$profile['fitness_tier'], [1,2]) ? 1 : (in_array((int)$profile['fitness_tier'], [3,4]) ? 2 : 3)) : null) ?>>Advanced</option>
                    </select>
                </label>
            </div>
        </div>

        <!-- Persistent Action Footer -->
        <div class="pm-footer">
            <div style="display: flex; gap: 8px;">
                <button type="button" class="btn btn-secondary" id="pmPrevBtn_<?= h($context) ?>" onclick="navProfileTab_<?= h($context) ?>(-1)" style="display: none; padding: 7px 14px; font-size: 13px;">
                    ← Back
                </button>
                <button type="button" class="btn btn-secondary" id="pmNextBtn_<?= h($context) ?>" onclick="navProfileTab_<?= h($context) ?>(1)" style="padding: 7px 14px; font-size: 13px;">
                    Next Step →
                </button>
            </div>
            <button type="submit" class="btn btn-primary" style="padding: 7px 20px; font-size: 13px; font-weight: 600;">
                Save Profile
            </button>
        </div>
    </form>

    <script>
    const PM_TABS_<?= h($context) ?> = ['body', 'goal', 'lifestyle'];
    let currentPmTabIndex_<?= h($context) ?> = 0;

    function switchProfileTab_<?= h($context) ?>(tabName) {
        currentPmTabIndex_<?= h($context) ?> = PM_TABS_<?= h($context) ?>.indexOf(tabName);
        if (currentPmTabIndex_<?= h($context) ?> === -1) currentPmTabIndex_<?= h($context) ?> = 0;

        PM_TABS_<?= h($context) ?>.forEach(t => {
            const btn = document.getElementById('tabBtn_<?= h($context) ?>_' + t);
            const pane = document.getElementById('tabPane_<?= h($context) ?>_' + t);
            if (btn) btn.classList.toggle('active', t === tabName);
            if (pane) pane.classList.toggle('active', t === tabName);
        });

        const prevBtn = document.getElementById('pmPrevBtn_<?= h($context) ?>');
        const nextBtn = document.getElementById('pmNextBtn_<?= h($context) ?>');
        if (prevBtn) prevBtn.style.display = currentPmTabIndex_<?= h($context) ?> > 0 ? 'inline-block' : 'none';
        if (nextBtn) nextBtn.style.display = currentPmTabIndex_<?= h($context) ?> < PM_TABS_<?= h($context) ?>.length - 1 ? 'inline-block' : 'none';
    }

    function navProfileTab_<?= h($context) ?>(dir) {
        const nextIdx = currentPmTabIndex_<?= h($context) ?> + dir;
        if (nextIdx >= 0 && nextIdx < PM_TABS_<?= h($context) ?>.length) {
            switchProfileTab_<?= h($context) ?>(PM_TABS_<?= h($context) ?>[nextIdx]);
        }
    }

    function updateTargetMetrics_<?= h($context) ?>(goal) {
        const armF = document.getElementById('targetArmField_<?= h($context) ?>');
        const chestF = document.getElementById('targetChestField_<?= h($context) ?>');
        const waistF = document.getElementById('targetWaistField_<?= h($context) ?>');
        const weightF = document.getElementById('targetWeightField_<?= h($context) ?>');
        const bfF = document.getElementById('targetBodyFatField_<?= h($context) ?>');
        const tip = document.getElementById('targetGoalTip_<?= h($context) ?>');
        const tipIcon = document.getElementById('targetGoalTipIcon_<?= h($context) ?>');
        const tipText = document.getElementById('targetGoalTipText_<?= h($context) ?>');

        if (!armF) return;

        // Reset visibility
        armF.style.display = 'none';
        chestF.style.display = 'none';
        waistF.style.display = 'none';
        weightF.style.display = 'flex';
        bfF.style.display = 'flex';

        if (goal === 'Growing larger biceps and arms') {
            armF.style.display = 'flex';
            bfF.style.display = 'none';
            tipIcon.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6.5 6.5 11 11"/><path d="m21 21-1-1"/><path d="m3 3 1 1"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/><path d="m3 10 7-7"/><path d="m14 21 7-7"/></svg>';
            tipText.innerHTML = '<strong>Target Arm Circumference</strong> is the gold standard metric for bicep & tricep hypertrophy. Measure flexed around the peak of the arm.';
        } else if (goal === 'Developing a wide chest') {
            chestF.style.display = 'flex';
            bfF.style.display = 'none';
            tipIcon.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>';
            tipText.innerHTML = '<strong>Target Chest Circumference</strong> measures pectoral hypertrophy. Measure horizontally across the fullest point of the chest.';
        } else if (goal === 'Building a visible six-pack' || goal === 'Losing excess body fat' || goal === 'fat_loss') {
            waistF.style.display = 'flex';
            tipIcon.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>';
            tipText.innerHTML = '<strong>Waist Circumference & Body Fat %</strong> directly track abdominal definition and visceral fat loss around the midsection.';
        } else if (goal === 'Gaining lean body mass' || goal === 'muscle_gain') {
            armF.style.display = 'flex';
            tipIcon.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6.5 6.5 11 11"/><path d="m21 21-1-1"/><path d="m3 3 1 1"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/><path d="m3 10 7-7"/><path d="m14 21 7-7"/></svg>';
            tipText.innerHTML = 'For lean mass gain, track your scale weight alongside arm and muscle circumference to ensure lean hypertrophy.';
        } else {
            tipIcon.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>';
            tipText.innerHTML = 'Set target weight or body fat % to monitor your body transformation over time in your Progress dashboard.';
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const selectEl = document.getElementById('primaryGoalSelect_<?= h($context) ?>');
        if (selectEl) {
            updateTargetMetrics_<?= h($context) ?>(selectEl.value);
        }
    });
    setTimeout(function() {
        const selectEl = document.getElementById('primaryGoalSelect_<?= h($context) ?>');
        if (selectEl) {
            updateTargetMetrics_<?= h($context) ?>(selectEl.value);
        }
    }, 80);
    </script>
    <?php
}

function dashboard_stat(string $label, string $value, string $subtext, string $trend, string $icon, bool $featured = false): void
{
    $isDown = str_contains($trend, '▼');
    echo '<article class="dash-stat ' . ($featured ? 'featured' : '') . '">';
    echo '<div class="stat-head"><span>' . h($label) . '</span><i>' . $icon . '</i></div>';
    echo '<strong>' . h($value) . '</strong>';
    echo '<p>' . h($subtext) . '</p>';
    echo '<em' . ($isDown ? ' class="trend-down"' : '') . '>' . h($trend) . '</em>';
    echo '</article>';
}

function render_current_workout(int $memberUserId, bool $dashboardMode = false, ?int $forcePlanId = null): void
{
    if ($forcePlanId) {
        $stmt = db()->prepare('SELECT * FROM training_plans WHERE plan_id = ?');
        $stmt->execute([$forcePlanId]);
        $plan = $stmt->fetch();
        if ($plan) {
            $memberUserId = (int) $plan['member_user_id'];
        }
    } else {
        $stmt = db()->prepare(
            'SELECT * FROM training_plans
             WHERE member_user_id = ? AND status = "active"
             ORDER BY plan_id DESC LIMIT 1'
        );
        $stmt->execute([$memberUserId]);
        $plan = $stmt->fetch();
    }

    echo '<section class="panel"><h2>Your workout plan</h2>';
    if (!$plan) {
        echo '<p class="muted">No workout plan generated yet. Save your physical profile to create one.</p></section>';
        return;
    }

    if (!$dashboardMode) {
        $goalVal = ucwords(str_replace('_', ' ', (string) $plan['goal']));
        $statusVal = ucfirst((string) $plan['status']);
        $startedVal = date('M j, Y', strtotime((string) $plan['start_date']));
        $daysCount = workout_day_count((int) $plan['plan_id']);
        
        $statusClass = strtolower($statusVal) === 'active' ? 'status-active' : 'status-draft';
        ?>
        <div class="workout-header-grid">
            <div class="workout-header-card workout-goal-card">
                <div class="card-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                </div>
                <div class="card-info">
                    <span class="card-label">Primary Goal</span>
                    <strong class="card-value"><?= h($goalVal) ?></strong>
                </div>
            </div>
            
            <div class="workout-header-card workout-status-card">
                <div class="card-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
                <div class="card-info">
                    <span class="card-label">Plan Status</span>
                    <strong class="card-value"><span class="badge-pill <?= $statusClass ?>"><?= h($statusVal) ?></span></strong>
                </div>
            </div>

            <div class="workout-header-card workout-started-card">
                <div class="card-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <div class="card-info">
                    <span class="card-label">Started Date</span>
                    <strong class="card-value"><?= h($startedVal) ?></strong>
                </div>
            </div>

            <div class="workout-header-card workout-days-card">
                <div class="card-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4v16M10 4v16M6 12h4M14 4v16M18 4v16M14 12h4"/></svg>
                </div>
                <div class="card-info">
                    <span class="card-label">Training Schedule</span>
                    <strong class="card-value"><?= $daysCount ?> Days / Week</strong>
                </div>
            </div>
        </div>
        
        <style>
            .workout-header-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr));
                gap: 16px;
                margin-top: 16px;
                margin-bottom: 24px;
            }
            .workout-header-card {
                background: linear-gradient(135deg, rgba(255, 255, 255, 0.03) 0%, rgba(255, 255, 255, 0.01) 100%);
                border: 1px solid var(--line);
                border-radius: 12px;
                padding: 20px;
                display: flex;
                align-items: center;
                gap: 16px;
                box-shadow: var(--shadow);
                backdrop-filter: blur(12px);
                -webkit-backdrop-filter: blur(12px);
                transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.25s ease, box-shadow 0.25s ease;
                position: relative;
                overflow: hidden;
            }
            .workout-header-card::before {
                content: '';
                position: absolute;
                top: 0; left: 0; right: 0; bottom: 0;
                background: radial-gradient(circle at 10% 20%, rgba(199, 255, 34, 0.05) 0%, transparent 50%);
                opacity: 0;
                transition: opacity 0.3s ease;
                pointer-events: none;
            }
            .workout-header-card:hover {
                transform: translateY(-4px);
                border-color: color-mix(in srgb, var(--lime) 30%, var(--line));
                box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2), 0 0 20px color-mix(in srgb, var(--lime) 5%, transparent);
            }
            .workout-header-card:hover::before {
                opacity: 1;
            }
            .card-icon {
                width: 46px;
                height: 46px;
                border-radius: 10px;
                background: rgba(255, 255, 255, 0.03);
                border: 1px solid var(--line);
                display: flex;
                align-items: center;
                justify-content: center;
                color: var(--lime);
                flex-shrink: 0;
                transition: background 0.3s ease, color 0.3s ease, border-color 0.3s ease;
            }
            .workout-header-card:hover .card-icon {
                background: color-mix(in srgb, var(--lime) 12%, transparent);
                color: var(--lime);
                border-color: color-mix(in srgb, var(--lime) 30%, transparent);
            }
            .card-info {
                display: flex;
                flex-direction: column;
                gap: 4px;
                min-width: 0;
                width: 100%;
            }
            .card-label {
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                color: var(--muted);
            }
            .card-value {
                font-size: 17px;
                font-weight: 800;
                color: var(--ink);
                line-height: 1.35;
                white-space: normal;
                word-wrap: break-word;
            }
            .badge-pill {
                display: inline-flex;
                align-items: center;
                padding: 2px 8px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }
            .status-active {
                background: rgba(45, 240, 165, 0.15);
                color: #2df0a5;
                border: 1px solid rgba(45, 240, 165, 0.25);
            }
            .status-draft {
                background: rgba(255, 149, 72, 0.15);
                color: var(--orange);
                border: 1px solid rgba(255, 149, 72, 0.25);
            }
        </style>
        <?php
    }

    $stmt = db()->prepare(
        'SELECT tpe.exercise_id, tpe.day_of_week, tpe.sequence_order, tpe.sets, tpe.reps, tpe.rest_seconds,
                e.name, e.category, e.muscle_group
         FROM training_plan_exercises tpe
         JOIN exercises e ON e.exercise_id = tpe.exercise_id
         WHERE tpe.plan_id = ?
         ORDER BY tpe.day_of_week, tpe.sequence_order'
    );
    $stmt->execute([(int) $plan['plan_id']]);
    $rows = $stmt->fetchAll();

    $grouped = [];
    foreach ($rows as $row) {
        $day = workout_day_name((int) $row['day_of_week']);
        $grouped[$day][] = $row;
    }

    if ($dashboardMode) {
        $todayNum = (int) date('N');
        $todayName = workout_day_name($todayNum);
        
        echo '<h3 style="margin:1.25rem 0 0.5rem; color: var(--lime);">Today: ' . h($todayName) . '</h3>';
        
        if (isset($grouped[$todayName])) {
            $exercises = $grouped[$todayName];
            $tableRows = [];

            $stmt = db()->prepare('SELECT exercise_id FROM exercise_completions WHERE user_id = ? AND plan_id = ? AND completed_date = ?');
            $stmt->execute([$memberUserId, $plan['plan_id'], date('Y-m-d')]);
            $completedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($exercises as $ex) {
                if (in_array($ex['exercise_id'], $completedIds)) continue;

                $tableRows[] = [
                    'name'         => $ex['name'],
                    'category'     => $ex['category'],
                    'muscle_group' => $ex['muscle_group'],
                    'sets'         => $ex['sets'],
                    'reps'         => $ex['reps'],
                    'rest_seconds' => $ex['rest_seconds'] . ' s',
                    'action'       => '<button type="button" class="btn btn-primary" style="padding: 4px 10px; font-size: 12px; background: var(--lime); color: var(--bg);" onclick="completeExercise(' . $plan['plan_id'] . ', ' . $ex['exercise_id'] . ')">Complete</button>'
                ];
            }

            if ($tableRows) {
                echo render_simple_table($tableRows, ['name', 'category', 'muscle_group', 'sets', 'reps', 'rest_seconds', 'action']);
            } else if (count($exercises) > 0) {
                echo '<div style="text-align:center; padding: 2rem; background: rgba(199,255,34,0.1); border-radius: 12px; border: 1px solid rgba(199,255,34,0.3); margin-bottom: 2rem;"><h3 style="color: var(--lime); margin: 0 0 8px;">🎉 All Done!</h3><p style="margin:0; color: var(--muted);">Great job! You\'ve crushed all your exercises for today!</p></div>';
            } else {
                echo '<p class="muted" style="margin-bottom: 2rem;">No workout scheduled for today. Enjoy your rest day!</p>';
            }
        } else {
            echo '<p class="muted" style="margin-bottom: 2rem;">No workout scheduled for today. Enjoy your rest day!</p>';
        }

        echo '<p style="margin-top:1.5rem;"><a href="index.php?page=my_workout" class="btn btn-primary" style="display:inline-block; padding:8px 16px; text-decoration: none; border-radius: 6px;">View full workout plan &rarr;</a></p>';
    } else {
        $daysArray = [
            'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3,
            'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6, 'Sunday' => 7
        ];
        
        $startOfWeek = date('Y-m-d', strtotime('monday this week'));
        $endOfWeek = date('Y-m-d', strtotime('sunday this week'));
        $stmt = db()->prepare('SELECT exercise_id, completed_date FROM exercise_completions WHERE user_id = ? AND plan_id = ? AND completed_date >= ? AND completed_date <= ?');
        $stmt->execute([$memberUserId, $plan['plan_id'], $startOfWeek, $endOfWeek]);
        $completionsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $completions = [];
        foreach ($completionsRaw as $c) {
            $completions[$c['completed_date']][] = $c['exercise_id'];
        }

        echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr)); gap: 20px; margin-top: 2rem;">';
        foreach ($daysArray as $dayName => $dayNum) {
            $exercises = $grouped[$dayName] ?? [];
            $dateForThisDay = date('Y-m-d', strtotime($dayName . ' this week'));
            $completedExIds = $completions[$dateForThisDay] ?? [];
            
            echo '<div class="panel" style="display: flex; flex-direction: column;">';
            echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid var(--line);">';
            echo '<h3 style="margin: 0; color: var(--lime);">' . h($dayName) . '</h3>';
            echo '</div>';
            
            echo '<div style="flex: 1; display: flex; flex-direction: column; gap: 10px; min-height: 50px;">';
            if (empty($exercises)) {
                echo '<div class="empty-state" style="text-align: center; color: var(--muted); padding: 20px 0; font-size: 13px; font-style: italic;">Rest day. No exercises assigned.</div>';
            } else {
                foreach ($exercises as $ex) {
                    $isCompleted = in_array($ex['exercise_id'], $completedExIds);
                    echo '<div style="background: color-mix(in srgb, var(--bg) 50%, transparent); border: 1px solid var(--line); border-radius: 6px; padding: 10px;">';
                    
                    if ($isCompleted) {
                        echo '<div style="display: flex; justify-content: space-between; align-items: flex-start;">';
                        echo '<div style="font-weight: bold; font-size: 14px; color: var(--ink); text-decoration: line-through; opacity: 0.7;">' . h($ex['name']) . '</div>';
                        echo '<svg viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="3" style="width: 18px; height: 18px; flex-shrink: 0;"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                        echo '</div>';
                    } else {
                        echo '<div style="font-weight: bold; font-size: 14px; color: var(--ink);">' . h($ex['name']) . '</div>';
                    }
                    
                    echo '<div style="font-size: 12px; color: var(--muted); margin-top: 4px;' . ($isCompleted ? ' opacity: 0.7;' : '') . '">';
                    echo $ex['sets'] . ' sets &times; ' . h($ex['reps']);
                    echo '<span style="margin: 0 5px;">|</span>';
                    echo 'Rest: ' . $ex['rest_seconds'] . 's';
                    echo '</div>';
                    echo '</div>';
                }
            }
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';

        if (!$rows) {
            echo '<p class="muted">No exercises assigned to this plan yet.</p>';
        }
    }

    if ($dashboardMode) {
        $csrfToken = csrf_token();
        echo <<<HTML
        <script>
        function completeExercise(planId, exerciseId) {
            Swal.fire({
                title: 'Completed already?',
                text: "Are you sure you want to mark this exercise as finished?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: 'var(--lime-dark)',
                cancelButtonColor: 'var(--line)',
                confirmButtonText: 'Yes, I crushed it!',
                cancelButtonText: 'No',
                background: 'var(--bg)',
                color: 'var(--ink)'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('index.php?page=complete_exercise', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'plan_id=' + planId + '&exercise_id=' + exerciseId + '&csrf_token=' + encodeURIComponent('{$csrfToken}')
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            if (data.tier_upgraded) {
                                Swal.fire({
                                    title: 'Level Up!',
                                    text: 'You have been promoted to ' + data.tier_upgraded.new_tier_name + '!',
                                    icon: 'success',
                                    background: 'var(--bg)',
                                    color: 'var(--ink)'
                                }).then(() => {
                                    window.location.reload();
                                });
                            } else {
                                window.location.reload();
                            }
                        } else {
                            Swal.fire('Error', data.message, 'error');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        Swal.fire('Error', 'Failed to complete exercise.', 'error');
                    });
                }
            });
        }
        </script>
HTML;
    }

    echo '</section>';
}

function render_exercise_recommendations(int $userId, bool $compact = false): void
{
    $recs = get_exercise_recommendations($userId);
    $profile = member_profile($userId);

    echo '<section class="panel exercise-recs">';
    echo '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;">';
    echo '<h2>Recommended Exercises</h2>';
    if ($profile) {
        $goalLabel = ucwords(str_replace('_', ' ', $profile['primary_goal']));
        echo '<span class="badge badge-accent" style="font-size:12px;">' . h($goalLabel) . '</span>';
    }
    echo '</div>';

    if (!$recs) {
        echo '<p class="muted">Complete your physical profile to get personalised exercise recommendations.</p>';
        echo '</section>';
        return;
    }

    echo '<p class="muted" style="font-size:13px;margin-bottom:1rem;">Based on your profile, activity level, and fitness goal.</p>';

    if ($compact) {
        // Compact card grid for dashboard
        echo '<div class="rec-grid">';
        foreach (array_slice($recs, 0, 4) as $rec) {
            $ex = $rec['exercise'];
            $priorityClass = $rec['priority'] === 'high' ? 'rec-high' : '';
            echo '<div class="rec-card ' . $priorityClass . '">';
            echo '<div class="rec-card-head">';
            echo '<strong>' . h($ex['name']) . '</strong>';
            echo '<span class="badge badge-cat badge-' . h($rec['category']) . '">' . h(ucfirst($rec['category'])) . '</span>';
            echo '</div>';
            echo '<p class="rec-muscle">' . h(ucfirst($ex['muscle_group'])) . '</p>';
            echo '<div class="rec-params">';
            echo '<span>' . (int) $rec['sets'] . ' sets</span>';
            echo '<span>' . h($rec['reps']) . '</span>';
            if ($rec['rest_seconds'] > 0) {
                echo '<span>' . (int) $rec['rest_seconds'] . 's rest</span>';
            }
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '<p style="margin-top:0.75rem;"><a href="index.php?page=my_workout">View all recommendations →</a></p>';
    } else {
        // Full table view for workout page
        echo '<div class="table-wrap"><table>';
        echo '<thead><tr><th>Exercise</th><th>Category</th><th>Muscle Group</th><th>Sets</th><th>Reps</th><th>Rest</th><th>Why Recommended</th></tr></thead>';
        echo '<tbody>';
        foreach ($recs as $rec) {
            $ex = $rec['exercise'];
            $priorityClass = $rec['priority'] === 'high' ? 'style="border-left:3px solid var(--accent);"' : '';
            echo '<tr ' . $priorityClass . '>';
            echo '<td><strong>' . h($ex['name']) . '</strong></td>';
            echo '<td><span class="badge badge-cat badge-' . h($rec['category']) . '">' . h(ucfirst($rec['category'])) . '</span></td>';
            echo '<td>' . h(ucfirst($ex['muscle_group'])) . '</td>';
            echo '<td>' . (int) $rec['sets'] . '</td>';
            echo '<td>' . h($rec['reps']) . '</td>';
            echo '<td>' . ($rec['rest_seconds'] > 0 ? (int) $rec['rest_seconds'] . 's' : '—') . '</td>';
            echo '<td class="rec-reason">' . h($rec['recommendation']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    echo '</section>';
    ?>
    <style>
        .exercise-recs .badge-accent {
            background: var(--accent, #7c5cfc);
            color: #fff;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 600;
        }
        .badge-cat {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .badge-strength { background: rgba(99,102,241,0.15); color: #6366f1; }
        .badge-cardio   { background: rgba(239,68,68,0.15);  color: #ef4444; }
        .badge-core     { background: rgba(34,197,94,0.15);  color: #22c55e; }

        .rec-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 220px), 1fr));
            gap: 0.75rem;
        }
        .rec-card {
            background: var(--panel-soft);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 1rem;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        .rec-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(199, 255, 34, 0.12);
            border-color: rgba(199, 255, 34, 0.3);
        }
        .rec-card.rec-high {
            border-left: 3px solid var(--accent, #7c5cfc);
            background: linear-gradient(90deg, rgba(124,92,252,0.05) 0%, transparent 100%);
        }
        .rec-card-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.4rem;
        }
        .rec-card-head strong { font-size: 14px; }
        .rec-muscle {
            font-size: 12px;
            color: var(--muted);
            margin: 0 0 0.6rem;
        }
        .rec-params {
            display: flex;
            gap: 0.75rem;
            font-size: 12px;
            color: var(--muted);
        }
        .rec-params span {
            background: var(--surface-1, rgba(255,255,255,0.05));
            padding: 2px 8px;
            border-radius: 6px;
        }
        .rec-reason {
            font-size: 12px;
            color: var(--muted);
            max-width: 320px;
            line-height: 1.4;
        }
    </style>
    <?php
}


function workout_day_name(int $dayOfWeek): string
{
    return ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$dayOfWeek] ?? 'Day ' . $dayOfWeek;
}

function workout_day_count(int $planId): int
{
    return (int) scalar(
        'SELECT COUNT(DISTINCT day_of_week) FROM training_plan_exercises WHERE plan_id = ?',
        [$planId]
    );
}

function render_notification_bell(array $user, string $currentPage): void
{
    $userId = (int) $user['user_id'];
    $unread = unread_notification_count($userId);
    $items  = get_notifications($userId, 8);
    ?>
    <div class="notif-wrap" id="notif-wrap">
        <button type="button" class="notif-bell" id="notif-toggle" aria-label="Notifications" aria-expanded="false">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <?php if ($unread > 0): ?>
                <span id="notif-badge" class="notif-badge"><?= $unread > 9 ? '9+' : (int) $unread ?></span>
            <?php else: ?>
                <span id="notif-badge" class="notif-badge" style="display:none;"></span>
            <?php endif; ?>
        </button>
        <div class="notif-dropdown" id="notif-dropdown" hidden>
            <div class="notif-dropdown-head">
                <div style="display:flex;align-items:center;gap:8px;">
                    <strong>Notifications</strong>
                    <?php if ($unread > 0): ?>
                        <span id="notif-bell-new-badge" style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:10px;background:rgba(199,255,34,0.15);color:var(--lime);"><?= (int) $unread ?> new</span>
                    <?php else: ?>
                        <span id="notif-bell-new-badge" style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:10px;background:rgba(199,255,34,0.15);color:var(--lime);display:none;"></span>
                    <?php endif; ?>
                </div>
                <form method="post" action="index.php?page=notification_action" class="notif-mark-all" id="notif-mark-all-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="notification_action" value="mark_all_read">
                    <input type="hidden" name="return_page" value="<?= h($currentPage) ?>">
                    <button type="button" id="notif-mark-all-btn" <?= $unread > 0 ? '' : 'disabled class="is-disabled"' ?>>
                        <?= $unread > 0 ? 'Mark all as read' : 'All read' ?>
                    </button>
                </form>
            </div>
            <?php if (!$items): ?>
                <p class="notif-empty">No notifications yet.</p>
            <?php else: ?>
                <ul class="notif-menu">
                    <?php foreach ($items as $item):
                        $hasLink = in_array($item['type'], ['coach_message', 'class_reminder', 'renewal_reminder', 'milestone'], true);
                        $clickUrl = $hasLink ? 'index.php?page=notification_click&nid=' . (int) $item['notification_id'] : null;
                    ?>
                        <li class="<?= $item['is_read'] ? '' : 'unread' ?>">
                            <div class="notif-menu-meta">
                                <span class="notif-type notif-type-<?= h($item['type']) ?>"><?= h(notification_type_label($item['type'])) ?></span>
                                <time><?= h(notification_time_ago($item['created_at'])) ?></time>
                            </div>
                            <?php if ($clickUrl): ?>
                                <a href="<?= h($clickUrl) ?>" class="notif-menu-link">
                                    <strong><?= h($item['title']) ?></strong>
                                    <p><?= h($item['message']) ?></p>
                                </a>
                            <?php else: ?>
                                <strong><?= h($item['title']) ?></strong>
                                <p><?= h($item['message']) ?></p>
                            <?php endif; ?>
                            <div class="notif-item-footer">
                                <?php if (!$item['is_read']): ?>
                                    <button type="button" class="notif-mark-read-btn"
                                        data-notif-id="<?= (int) $item['notification_id'] ?>"
                                        data-csrf="<?= h(csrf_token()) ?>">
                                        Mark read
                                    </button>
                                <?php else: ?>
                                    <span class="notif-read-status">✓ Read</span>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
    <a class="notif-view-all" href="index.php?page=notifications">View all notifications</a>
        </div>
    </div>
    <script>
    (function() {
        const bellBadge    = document.getElementById('notif-badge');
        const newBadge     = document.getElementById('notif-bell-new-badge');
        const markAllBtn   = document.getElementById('notif-mark-all-btn');
        const markAllForm  = document.getElementById('notif-mark-all-form');
        const csrfToken    = markAllForm ? markAllForm.querySelector('[name="csrf_token"]').value : '';

        function updateBadges(unreadCount) {
            // Main bell badge (header button)
            if (bellBadge) {
                if (unreadCount > 0) {
                    bellBadge.textContent = unreadCount > 9 ? '9+' : unreadCount;
                    bellBadge.style.display = '';
                } else {
                    bellBadge.style.display = 'none';
                }
            }
            // Dropdown header "X new" badge
            if (newBadge) {
                if (unreadCount > 0) {
                    newBadge.textContent = unreadCount + ' new';
                    newBadge.style.display = '';
                } else {
                    newBadge.style.display = 'none';
                }
            }
            // Mark-all button state
            if (markAllBtn) {
                markAllBtn.disabled = unreadCount === 0;
                markAllBtn.textContent = unreadCount > 0 ? 'Mark all as read' : 'All read';
                markAllBtn.classList.toggle('is-disabled', unreadCount === 0);
            }
        }

        async function ajaxNotifAction(payload) {
            try {
                const body = new URLSearchParams(payload);
                const res = await fetch('index.php?page=notifications', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest',
                               'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                });
                return await res.json();
            } catch (_) { return null; }
        }

        // ── Mark single notification read ───────────────────────────
        document.querySelectorAll('.notif-mark-read-btn[data-notif-id]').forEach(function(btn) {
            btn.addEventListener('click', async function() {
                const notifId = btn.dataset.notifId;
                const csrf    = btn.dataset.csrf;
                const li = btn.closest('li');
                btn.disabled = true;
                btn.textContent = '...';
                const data = await ajaxNotifAction({
                    notification_action: 'mark_read',
                    notification_id: notifId,
                    csrf_token: csrf
                });
                if (data && data.success) {
                    if (li) li.classList.remove('unread');
                    const footer = btn.closest('.notif-item-footer');
                    if (footer) {
                        const readSpan = document.createElement('span');
                        readSpan.className = 'notif-read-status';
                        readSpan.textContent = '✓ Read';
                        footer.replaceChild(readSpan, btn);
                    }
                    updateBadges(data.unread_count);
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Mark read';
                }
            });
        });

        // ── Mark all read ──────────────────────────────────────────
        if (markAllBtn) {
            markAllBtn.addEventListener('click', async function() {
                if (markAllBtn.disabled) return;
                markAllBtn.disabled = true;
                markAllBtn.textContent = '...';
                const data = await ajaxNotifAction({
                    notification_action: 'mark_all_read',
                    csrf_token: csrfToken
                });
                if (data && data.success) {
                    document.querySelectorAll('.notif-menu li.unread').forEach(function(li) {
                        li.classList.remove('unread');
                        const btn2 = li.querySelector('.notif-mark-read-btn');
                        if (btn2) {
                            const footer = btn2.closest('.notif-item-footer');
                            if (footer) {
                                const s = document.createElement('span');
                                s.className = 'notif-read-status';
                                s.textContent = '✓ Read';
                                footer.replaceChild(s, btn2);
                            }
                        }
                    });
                    updateBadges(0);
                } else {
                    markAllBtn.disabled = false;
                    markAllBtn.textContent = 'Mark all as read';
                }
            });
        }

        // ── 30-second badge polling ─────────────────────────────────
        setInterval(async function() {
            if (document.hidden) return;
            const data = await ajaxNotifAction({
                notification_action: 'fetch_notifications',
                csrf_token: csrfToken
            });
            if (data && typeof data.unread === 'number') {
                updateBadges(data.unread);
            }
        }, 30000);
    })();
    </script>
    <?php
}

function render_pagination(int $page, int $totalPages, string $baseUrl, string $paramName = 'p'): void
{
    if ($totalPages <= 1) return;

    $sep = str_contains($baseUrl, '?') ? '&' : '?';

    echo '<div class="pagination">';

    // Previous
    if ($page > 1) {
        echo '<a href="' . h($baseUrl . $sep . $paramName . '=' . ($page - 1)) . '" class="page-link">← Prev</a>';
    } else {
        echo '<span class="page-link disabled">← Prev</span>';
    }

    // Page numbers with ellipsis
    $start = max(1, $page - 2);
    $end   = min($totalPages, $page + 2);

    if ($start > 1) {
        echo '<a href="' . h($baseUrl . $sep . $paramName . '=1') . '" class="page-link">1</a>';
        if ($start > 2) echo '<span class="page-ellipsis">…</span>';
    }
    for ($i = $start; $i <= $end; $i++) {
        if ($i === $page) {
            echo '<span class="page-current">' . $i . '</span>';
        } else {
            echo '<a href="' . h($baseUrl . $sep . $paramName . '=' . $i) . '" class="page-link">' . $i . '</a>';
        }
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) echo '<span class="page-ellipsis">…</span>';
        echo '<a href="' . h($baseUrl . $sep . $paramName . '=' . $totalPages) . '" class="page-link">' . $totalPages . '</a>';
    }

    // Next
    if ($page < $totalPages) {
        echo '<a href="' . h($baseUrl . $sep . $paramName . '=' . ($page + 1)) . '" class="page-link">Next →</a>';
    } else {
        echo '<span class="page-link disabled">Next →</span>';
    }

    echo '</div>';
}

function render_registration_form(): void
{
    // Kept for backward compatibility — register.php now renders inline
    // but other callers may still reference this
    ?>
    <div class="auth-card">
        <div class="auth-card-header">
            <h1 class="auth-title">FITTRACKS</h1>
            <p class="auth-subtitle">Create your account</p>
        </div>
        <form method="post" class="auth-form" novalidate>
            <?= csrf_field() ?>
            <div class="auth-form-row">
                <div class="auth-field">
                    <label>FIRST NAME</label>
                    <div class="auth-input-group">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <input name="first_name" required placeholder="First name"
                               oninvalid="this.setCustomValidity('Please enter your first name.')"
                               oninput="this.setCustomValidity('')">
                    </div>
                </div>
                <div class="auth-field">
                    <label>LAST NAME</label>
                    <div class="auth-input-group">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <input name="last_name" required placeholder="Last name"
                               oninvalid="this.setCustomValidity('Please enter your last name.')"
                               oninput="this.setCustomValidity('')">
                    </div>
                </div>
            </div>

            <div class="auth-field">
                <label>EMAIL ADDRESS</label>
                <div class="auth-input-group">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    <input type="email" name="email" required placeholder="Enter your email"
                           oninvalid="this.setCustomValidity('Please enter a valid email address.')"
                           oninput="this.setCustomValidity('')">
                </div>
            </div>

            <div class="auth-field">
                <label>PHONE <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></label>
                <div class="auth-input-group">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                    <input name="phone" type="tel" maxlength="11" placeholder="09xxxxxxxxx"
                           oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)">
                </div>
            </div>

            <div class="auth-field">
                <label>PASSWORD</label>
                <div class="auth-input-group">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <input type="password" name="password" required minlength="8" placeholder="Min. 8 characters"
                           oninput="this.setCustomValidity(this.value.length < 8 ? 'Password must be at least 8 characters.' : '')"
                           oninvalid="this.setCustomValidity(this.value.length < 8 ? 'Password must be at least 8 characters.' : 'Please enter a password.')">
                </div>
            </div>

            <button type="submit" class="auth-submit-btn">CREATE ACCOUNT <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg></button>

            <div class="auth-form-footer">
                Already have an account? <a href="index.php?page=login">Sign in</a>
            </div>
        </form>
        <div class="corner corner-tl"></div>
        <div class="corner corner-tr"></div>
        <div class="corner corner-bl"></div>
        <div class="corner corner-br"></div>
    </div>
    <?php
}

// ═══════════════════════════════════════════════════════════════
// SKELETON LOADING HELPERS
// ═══════════════════════════════════════════════════════════════

/**
 * Renders skeleton stat cards (dashboard KPI cards)
 */
function render_skeleton_stats(int $count = 4): void
{
    echo '<div class="skeleton-wrapper"><section class="dash-grid stats-row">';
    for ($i = 0; $i < $count; $i++) {
        echo '<div class="sk-card sk-rect stat sk">';
        echo '<div class="sk sk-text short" style="margin-bottom:18px"></div>';
        echo '<div class="sk sk-title" style="height:32px;width:40%;margin-bottom:8px"></div>';
        echo '<div class="sk sk-text medium" style="height:12px"></div>';
        echo '<div class="sk sk-text short" style="height:12px;margin-top:12px"></div>';
        echo '</div>';
    }
    echo '</section></div>';
}

/**
 * Renders a skeleton table with header bar + rows
 */
function render_skeleton_table(int $cols = 6, int $rows = 8): void
{
    echo '<div class="skeleton-wrapper">';
    // Header bar
    echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding:8px 0">';
    echo '<div class="sk sk-title" style="width:120px;margin:0"></div>';
    echo '<div class="sk sk-text short" style="width:60px;margin:0;height:12px"></div>';
    echo '</div>';
    // Table header
    echo '<div class="sk-table-row" style="border-bottom:2px solid var(--line)">';
    for ($c = 0; $c < $cols; $c++) {
        $cls = $c === 0 ? 'wide' : ($c === $cols - 1 ? 'narrow' : '');
        echo '<div class="sk sk-cell ' . $cls . '"></div>';
    }
    echo '</div>';
    // Table rows
    for ($r = 0; $r < $rows; $r++) {
        echo '<div class="sk-table-row">';
        for ($c = 0; $c < $cols; $c++) {
            $cls = $c === 0 ? 'wide' : ($c === $cols - 1 ? 'narrow' : '');
            echo '<div class="sk sk-cell ' . $cls . '"></div>';
        }
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Renders skeleton list items with avatar + text lines
 */
function render_skeleton_list(int $rows = 5, string $title = ''): void
{
    echo '<div class="skeleton-wrapper">';
    if ($title) {
        echo '<div class="sk sk-title" style="width:160px;margin-bottom:14px"></div>';
    }
    echo '<div class="list-stack" style="gap:10px">';
    for ($i = 0; $i < $rows; $i++) {
        echo '<div class="sk-list-item">';
        echo '<div class="sk sk-circle"></div>';
        echo '<div class="sk-list-item-lines">';
        echo '<div class="sk sk-text medium" style="margin:0"></div>';
        echo '<div class="sk sk-text short" style="margin:0;height:11px"></div>';
        echo '</div>';
        echo '<div class="sk sk-text" style="width:60px;margin:0;height:11px"></div>';
        echo '</div>';
    }
    echo '</div></div>';
}

/**
 * Renders a skeleton chart placeholder
 */
function render_skeleton_chart(): void
{
    echo '<div class="skeleton-wrapper">';
    echo '<div class="sk-card" style="min-height:308px">';
    echo '<div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:18px">';
    echo '<div><div class="sk sk-title" style="width:140px;margin-bottom:6px"></div>';
    echo '<div class="sk sk-text short" style="height:11px"></div></div>';
    echo '<div class="sk sk-text" style="width:50px;height:24px;border-radius:999px;margin:0"></div>';
    echo '</div>';
    echo '<div class="sk sk-rect chart"></div>';
    echo '</div></div>';
}

/**
 * Renders skeleton cards in a grid
 */
function render_skeleton_cards(int $count = 6): void
{
    echo '<div class="skeleton-wrapper"><div class="sk-card-grid">';
    for ($i = 0; $i < $count; $i++) {
        echo '<div class="sk-card">';
        echo '<div style="display:flex;justify-content:space-between;margin-bottom:14px">';
        echo '<div class="sk sk-text" style="width:60px;height:20px;margin:0;border-radius:99px"></div>';
        echo '</div>';
        echo '<div class="sk sk-title" style="width:70%;margin-bottom:14px"></div>';
        echo '<div class="sk sk-text full" style="height:12px"></div>';
        echo '<div class="sk sk-text medium" style="height:12px"></div>';
        echo '<div class="sk sk-text full" style="height:12px;margin-top:14px"></div>';
        echo '<div style="margin-top:16px"><div class="sk sk-rect" style="height:38px;border-radius:8px"></div></div>';
        echo '</div>';
    }
    echo '</div></div>';
}

/**
 * Renders a chat skeleton (sidebar + messages)
 */
function render_skeleton_chat(): void
{
    echo '<div class="skeleton-wrapper">';
    echo '<section class="panel wide" style="padding:0;display:flex;height:calc(100vh - 120px);min-height:500px;overflow:hidden;border:1px solid var(--line)">';
    // Sidebar
    echo '<div style="width:300px;border-right:1px solid var(--line);display:flex;flex-direction:column">';
    echo '<div style="padding:20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center">';
    echo '<div class="sk sk-title" style="width:100px;margin:0"></div>';
    echo '<div class="sk sk-circle" style="width:24px;height:24px"></div>';
    echo '</div>';
    for ($i = 0; $i < 5; $i++) {
        echo '<div style="display:flex;gap:12px;padding:15px 20px;border-bottom:1px solid var(--line);align-items:center">';
        echo '<div class="sk sk-circle"></div>';
        echo '<div style="flex:1"><div class="sk sk-text medium" style="margin:0 0 6px"></div>';
        echo '<div class="sk sk-text short" style="height:11px;margin:0"></div></div>';
        echo '</div>';
    }
    echo '</div>';
    // Chat area
    echo '<div style="flex:1;display:flex;flex-direction:column">';
    echo '<div style="padding:15px 20px;border-bottom:1px solid var(--line);display:flex;gap:12px;align-items:center">';
    echo '<div class="sk sk-circle"></div>';
    echo '<div><div class="sk sk-text" style="width:120px;margin:0 0 4px"></div>';
    echo '<div class="sk sk-text" style="width:60px;height:11px;margin:0"></div></div>';
    echo '</div>';
    echo '<div style="flex:1;padding:20px;display:flex;flex-direction:column;gap:16px">';
    $bubbles = [
        ['left', '180px', '36px'],
        ['right', '220px', '48px'],
        ['left', '260px', '36px'],
        ['right', '140px', '36px'],
        ['left', '200px', '48px'],
        ['right', '180px', '36px'],
    ];
    foreach ($bubbles as $b) {
        echo '<div class="sk sk-chat-bubble ' . $b[0] . '" style="width:' . $b[1] . ';height:' . $b[2] . '"></div>';
    }
    echo '</div>';
    echo '<div style="padding:15px 20px;border-top:1px solid var(--line)">';
    echo '<div class="sk sk-rect" style="height:44px;border-radius:22px"></div>';
    echo '</div>';
    echo '</div>';
    echo '</section></div>';
}

/**
 * Renders skeleton notification items
 */
function render_skeleton_notifications(int $count = 5): void
{
    echo '<div class="skeleton-wrapper"><div class="notif-list">';
    for ($i = 0; $i < $count; $i++) {
        echo '<div class="sk-notif-item">';
        echo '<div style="display:flex;justify-content:space-between;margin-bottom:10px">';
        echo '<div class="sk sk-text" style="width:70px;height:20px;margin:0;border-radius:99px"></div>';
        echo '<div class="sk sk-text" style="width:50px;height:12px;margin:0"></div>';
        echo '</div>';
        echo '<div class="sk sk-text medium" style="height:15px;margin-bottom:8px"></div>';
        echo '<div class="sk sk-text full" style="height:12px"></div>';
        echo '</div>';
    }
    echo '</div></div>';
}

/**
 * Renders skeleton for profile page
 */
function render_skeleton_profile(): void
{
    echo '<div class="skeleton-wrapper"><div class="sk-card" style="text-align:center;padding:32px">';
    echo '<div class="sk sk-circle lg" style="margin:0 auto 16px"></div>';
    echo '<div class="sk sk-title" style="width:40%;margin:0 auto 8px"></div>';
    echo '<div class="sk sk-text short" style="margin:0 auto 20px"></div>';
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:400px;margin:0 auto">';
    for ($i = 0; $i < 4; $i++) {
        echo '<div class="sk sk-rect" style="height:42px;border-radius:7px"></div>';
    }
    echo '</div></div></div>';
}

/**
 * Renders a skeleton banner (welcome section)
 */
function render_skeleton_banner(): void
{
    echo '<div class="skeleton-wrapper">';
    echo '<div class="sk sk-rect banner" style="margin-bottom:24px"></div>';
    echo '</div>';
}

