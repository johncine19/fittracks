<?php
declare(strict_types=1);

// -----------------------------------------------------------------------
// Core diet / nutrition logic shared across multiple modules.
// Page handlers that use these functions live in their own files:
//   profile.php   → profile_page()
//   my_diet.php   → my_diet_page()
//   progress.php  → progress_page()
// -----------------------------------------------------------------------

function save_member_profile(int $userId): void
{
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO member_profiles (user_id, height_cm, weight_kg, date_of_birth, biological_sex, activity_level, primary_goal)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE height_cm = VALUES(height_cm), weight_kg = VALUES(weight_kg), date_of_birth = VALUES(date_of_birth), biological_sex = VALUES(biological_sex), activity_level = VALUES(activity_level), primary_goal = VALUES(primary_goal)');
    $stmt->execute([
        $userId,
        post('height_cm'),
        post('weight_kg'),
        post('date_of_birth'),
        post('biological_sex'),
        post('activity_level'),
        post('primary_goal'),
    ]);

    $profileId = (int) $pdo->query('SELECT profile_id FROM member_profiles WHERE user_id = ' . (int) $userId)->fetchColumn();
    $pdo->prepare('DELETE FROM member_dietary_restrictions WHERE profile_id = ?')->execute([$profileId]);
    foreach ((array) post('restrictions', []) as $restrictionId) {
        $pdo->prepare('INSERT INTO member_dietary_restrictions (profile_id, restriction_id) VALUES (?, ?)')->execute([$profileId, (int) $restrictionId]);
    }
}

function member_profile(int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM member_profiles WHERE user_id = ?');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();
    return $profile ?: null;
}

function nutrition_targets(array $profile): array
{
    $age = (int) (new DateTime($profile['date_of_birth']))->diff(new DateTime())->y;
    $bmr = 10 * (float) $profile['weight_kg'] + 6.25 * (float) $profile['height_cm'] - 5 * $age + ($profile['biological_sex'] === 'male' ? 5 : -161);
    $activity = ['sedentary' => 1.2, 'lightly_active' => 1.375, 'moderately_active' => 1.55, 'very_active' => 1.725, 'extra_active' => 1.9][$profile['activity_level']] ?? 1.2;
    $tdee = $bmr * $activity;
    $goalAdjust = ['fat_loss' => -0.15, 'muscle_gain' => 0.10, 'maintenance' => 0, 'general_health' => 0][$profile['primary_goal']] ?? 0;
    $calories = max(1200, (int) round($tdee * (1 + $goalAdjust)));
    $ratios = match ($profile['primary_goal']) {
        'fat_loss'    => ['protein' => .35, 'carbs' => .35, 'fats' => .30],
        'muscle_gain' => ['protein' => .30, 'carbs' => .45, 'fats' => .25],
        default       => ['protein' => .25, 'carbs' => .45, 'fats' => .30],
    };
    return [
        'bmr'     => round($bmr, 2),
        'tdee'    => round($tdee, 2),
        'calories' => $calories,
        'protein' => round(($calories * $ratios['protein']) / 4, 2),
        'carbs'   => round(($calories * $ratios['carbs']) / 4, 2),
        'fats'    => round(($calories * $ratios['fats']) / 9, 2),
    ];
}

function generate_diet_plan(int $memberUserId, ?int $coachId = null): int
{
    $profile = member_profile($memberUserId);
    if (!$profile) {
        throw new RuntimeException('Member profile is required before generating a diet plan.');
    }
    $targets = nutrition_targets($profile);
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO diet_plans (member_user_id, trainer_id, bmr, tdee, calorie_target, protein_target_g, carbs_target_g, fats_target_g, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "system_generated")');
    $stmt->execute([$memberUserId, $coachId, $targets['bmr'], $targets['tdee'], $targets['calories'], $targets['protein'], $targets['carbs'], $targets['fats']]);
    $planId = (int) $pdo->lastInsertId();
    suggest_meals($planId, (int) $profile['profile_id']);
    return $planId;
}

function suggest_meals(int $dietPlanId, int $profileId): void
{
    $pdo = db();
    $restrictionIds = array_column($pdo->query('SELECT restriction_id FROM member_dietary_restrictions WHERE profile_id = ' . $profileId)->fetchAll(), 'restriction_id');
    $sql = 'SELECT f.* FROM food_items f';
    $params = [];
    if ($restrictionIds) {
        $placeholders = implode(',', array_fill(0, count($restrictionIds), '?'));
        $sql .= ' WHERE NOT EXISTS (SELECT 1 FROM food_dietary_tags t WHERE t.food_id = f.food_id AND t.restriction_id NOT IN (' . $placeholders . '))';
        $params = $restrictionIds;
    }
    $sql .= ' ORDER BY protein_g DESC, calories LIMIT 12';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $foods = $stmt->fetchAll();
    $mealTypes = ['breakfast', 'lunch', 'dinner', 'snack'];
    foreach ($foods as $i => $food) {
        $pdo->prepare('INSERT INTO diet_plan_meals (diet_plan_id, food_id, meal_type, servings) VALUES (?, ?, ?, 1.00)')->execute([$dietPlanId, $food['food_id'], $mealTypes[$i % 4]]);
    }
}
