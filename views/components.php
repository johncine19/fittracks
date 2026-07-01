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
            if ($column === 'price') $value = money($value);
            echo '<td>' . h((string) $value) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    return ob_get_clean();
}

function render_member_form(string $context, ?array $user = null, ?array $profile = null): void
{
    $restrictions = db()->query('SELECT * FROM dietary_restrictions ORDER BY name')->fetchAll();
    $current = [];
    if ($profile) {
        $stmt = db()->prepare('SELECT restriction_id FROM member_dietary_restrictions WHERE profile_id = ?');
        $stmt->execute([$profile['profile_id']]);
        $current = array_map('intval', array_column($stmt->fetchAll(), 'restriction_id'));
    }
    ?>
    <form method="post" class="form grid-form">
        <?= csrf_field() ?>
        <?php if ($context !== 'profile'): ?>
            <label>First name <input name="first_name" required value="<?= h($user['first_name'] ?? '') ?>"></label>
            <label>Last name  <input name="last_name"  required value="<?= h($user['last_name']  ?? '') ?>"></label>
            <label>Email      <input type="email" name="email" required value="<?= h($user['email'] ?? '') ?>"></label>
            <label>Phone
                <input name="phone" type="tel" pattern="[0-9]{11}" maxlength="11"
                       title="Please enter exactly 11 digits" placeholder="09123456789"
                       value="<?= h($user['phone'] ?? '') ?>"
                       oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)">
            </label>
            <label>Password   <input type="password" name="password" <?= $context === 'register' ? 'required minlength="8"' : '' ?> placeholder="<?= $context === 'register' ? 'Min. 8 characters' : 'Leave blank to keep current' ?>"></label>
        <?php endif; ?>
        <label>Height (cm)
            <input name="height_cm" type="number" step="0.01" min="1"
                   <?= $context !== 'profile' ? 'required' : '' ?>
                   value="<?= h($profile['height_cm'] ?? '') ?>">
        </label>
        <label>Weight (kg)
            <input name="weight_kg" type="number" step="0.01" min="1"
                   <?= $context !== 'profile' ? 'required' : '' ?>
                   value="<?= h($profile['weight_kg'] ?? '') ?>">
        </label>
        <label>Date of birth
            <input name="date_of_birth" type="date"
                   <?= $context !== 'profile' ? 'required' : '' ?>
                   value="<?= h($profile['date_of_birth'] ?? '') ?>">
        </label>
        <label>Biological sex
            <select name="biological_sex" <?= $context !== 'profile' ? 'required' : '' ?>>
                <option value="male"   <?= selected('male',   $profile['biological_sex'] ?? null) ?>>Male</option>
                <option value="female" <?= selected('female', $profile['biological_sex'] ?? null) ?>>Female</option>
            </select>
        </label>
        <label>Activity level
            <select name="activity_level" <?= $context !== 'profile' ? 'required' : '' ?>>
                <?php foreach (['sedentary', 'lightly_active', 'moderately_active', 'very_active', 'extra_active'] as $level): ?>
                    <option value="<?= h($level) ?>" <?= selected($level, $profile['activity_level'] ?? null) ?>>
                        <?= h(ucwords(str_replace('_', ' ', $level))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Primary goal
            <select name="primary_goal" <?= $context !== 'profile' ? 'required' : '' ?>>
                <?php foreach (['fat_loss', 'muscle_gain', 'maintenance', 'general_health'] as $goal): ?>
                    <option value="<?= h($goal) ?>" <?= selected($goal, $profile['primary_goal'] ?? null) ?>>
                        <?= h(ucwords(str_replace('_', ' ', $goal))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <fieldset class="full-width">
            <legend>Dietary restrictions</legend>
            <div class="check-grid">
                <?php foreach ($restrictions as $restriction): ?>
                    <label class="check">
                        <input type="checkbox" name="restrictions[]"
                               value="<?= (int) $restriction['restriction_id'] ?>"
                               <?= checked(in_array((int) $restriction['restriction_id'], $current, true)) ?>>
                        <span><?= h($restriction['name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <button type="submit" class="full-width btn-primary" style="margin-top:10px;">Save</button>
    </form>
    <?php
}

function dashboard_stat(string $label, string $value, string $subtext, string $trend, string $icon, bool $featured = false): void
{
    echo '<article class="dash-stat ' . ($featured ? 'featured' : '') . '">';
    echo '<div class="stat-head"><span>' . h($label) . '</span><i>' . h($icon) . '</i></div>';
    echo '<strong>' . h($value) . '</strong>';
    echo '<p>' . h($subtext) . '</p>';
    echo '<em>' . h($trend) . '</em>';
    echo '</article>';
}

function render_current_diet(int $memberUserId, bool $withActions = true): void
{
    $stmt = db()->prepare('SELECT * FROM diet_plans WHERE member_user_id = ? ORDER BY generated_at DESC LIMIT 1');
    $stmt->execute([$memberUserId]);
    $plan = $stmt->fetch();
    echo '<section class="panel"><h2>Current nutrition targets</h2>';
    if (!$plan) {
        echo '<p class="muted">No diet plan generated yet. Save your profile to create one.</p></section>';
        return;
    }
    metric_cards([
        'BMR'      => $plan['bmr'],
        'TDEE'     => $plan['tdee'],
        'Calories' => $plan['calorie_target'],
        'Protein'  => $plan['protein_target_g'] . ' g',
        'Carbs'    => $plan['carbs_target_g'] . ' g',
        'Fats'     => $plan['fats_target_g'] . ' g',
        'Status'   => $plan['status'],
    ]);
    $stmt = db()->prepare('SELECT dpm.meal_type, dpm.servings, f.* FROM diet_plan_meals dpm JOIN food_items f ON f.food_id = dpm.food_id WHERE dpm.diet_plan_id = ? ORDER BY FIELD(dpm.meal_type, "breakfast", "lunch", "dinner", "snack")');
    $stmt->execute([$plan['diet_plan_id']]);
    echo render_simple_table($stmt->fetchAll(), ['meal_type', 'name', 'serving_size', 'calories', 'protein_g', 'carbs_g', 'fats_g']);
    if ($plan['trainer_notes']) {
        echo '<p><strong>Trainer notes:</strong> ' . h($plan['trainer_notes']) . '</p>';
    }
    echo '</section>';
}

function render_diet_reviews(int $coachId): void
{
    $plans = query_all('SELECT dp.*, CONCAT(u.first_name, " ", u.last_name) AS member FROM diet_plans dp JOIN users u ON u.user_id = dp.member_user_id WHERE dp.trainer_id = ? ORDER BY dp.generated_at DESC LIMIT 10', [$coachId]);
    echo '<section class="panel"><h2>Diet reviews</h2>';
    foreach ($plans as $plan) {
        echo '<form method="post" class="review-card">' . csrf_field() . '<input type="hidden" name="action" value="finalize"><input type="hidden" name="diet_plan_id" value="' . (int) $plan['diet_plan_id'] . '"><h3>' . h($plan['member']) . ' <small>' . h($plan['status']) . '</small></h3><label>Calories <input name="calorie_target" type="number" value="' . h($plan['calorie_target']) . '"></label><label>Protein <input name="protein_target_g" type="number" step="0.01" value="' . h($plan['protein_target_g']) . '"></label><label>Carbs <input name="carbs_target_g" type="number" step="0.01" value="' . h($plan['carbs_target_g']) . '"></label><label>Fats <input name="fats_target_g" type="number" step="0.01" value="' . h($plan['fats_target_g']) . '"></label><label>Notes <textarea name="trainer_notes">' . h($plan['trainer_notes']) . '</textarea></label><button>Finalize</button></form>';
    }
    if (!$plans) echo '<p class="muted">No generated diet plans to review yet.</p>';
    echo '</section>';
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
    // but staff_apply or other callers may still reference this
    $restrictions = db()->query('SELECT * FROM dietary_restrictions ORDER BY name')->fetchAll();
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
