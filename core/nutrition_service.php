<?php
declare(strict_types=1);

/**
 * Nutrition & Automated Meal Planning Engine
 */

/**

 * E.g. 'vegetarian' -> ['vegetarian', 'vegan']
 *      'pescatarian' -> ['pescatarian', 'vegetarian', 'vegan']
 *      'halal' -> ['halal', 'pescatarian', 'vegetarian', 'vegan']
 *      'vegan' -> ['vegan']
 *      'none' -> [] (meaning unrestricted / any)
 */
function get_compatible_dietary_restrictions(string $restriction): array
{
    $target = strtolower(trim($restriction));
    switch ($target) {
        case 'vegan':
            return ['vegan'];
        case 'vegetarian':
            return ['vegetarian', 'vegan'];
        case 'pescatarian':
            return ['pescatarian', 'vegetarian', 'vegan'];
        case 'halal':
            return ['halal', 'pescatarian', 'vegetarian', 'vegan'];
        case 'keto':
            return ['keto'];
        case 'gluten-free':
            return ['gluten-free'];
        case 'dairy-free':
            return ['dairy-free', 'vegan'];
        case 'nut-allergy':
            return ['nut-allergy'];
        case 'none':
        case '':
        case 'all':
        default:
            return [];
    }
}

/**
 * Automatically generates a tailored 7-day dietary plan adhering to the member's dietary restriction,
 * biometric targets (BMR/TDEE), primary goal, and gym food items library.
 */
function generate_dietary_plan(int $memberUserId, ?int $trainerId = null, bool $forceRegenerate = false): ?int
{
    $pdo = db();

    // 1. If not forcing regeneration, return existing active plan if present
    if (!$forceRegenerate) {
        $existingPlanId = (int) scalar('SELECT plan_id FROM dietary_plans WHERE member_user_id = ? AND status = "active" LIMIT 1', [$memberUserId]);
        if ($existingPlanId > 0) {
            return $existingPlanId;
        }
    }

    // 2. Fetch member profile
    $profile = $pdo->query('SELECT * FROM member_profiles WHERE user_id = ' . (int)$memberUserId)->fetch(PDO::FETCH_ASSOC);
    if (!$profile) {
        return null;
    }

    $goal = $profile['primary_goal'] ?: 'general_health';
    $tier = (int) ($profile['fitness_tier'] ?: 1);
    $expLevel = in_array($tier, [1, 2], true) ? 1 : (in_array($tier, [3, 4], true) ? 2 : 3);

    // Map goal to basic for diet rules
    $basicGoal = function_exists('map_detailed_goal_to_basic')
        ? map_detailed_goal_to_basic($goal)
        : ((stripos($goal, 'fat') !== false || stripos($goal, 'weight') !== false || stripos($goal, 'lean') !== false || stripos($goal, 'six-pack') !== false)
            ? 'fat_loss'
            : ((stripos($goal, 'muscle') !== false || stripos($goal, 'bicep') !== false || stripos($goal, 'chest') !== false || stripos($goal, 'bulk') !== false)
                ? 'muscle_gain'
                : 'general_health'));

    // 3. Calculate BMR (Mifflin-St Jeor formula)
    $w = (float) ($profile['weight_kg'] ?: 70);
    $h = (float) ($profile['height_cm'] ?: 170);
    $a = (int) ($profile['age'] ?: 25);
    $bmr = 10 * $w + 6.25 * $h - 5 * $a;
    $bmr += (($profile['biological_sex'] ?? 'male') === 'female') ? -161 : 5;

    // 4. Calculate TDEE
    $multipliers = [
        'sedentary' => 1.2,
        'lightly_active' => 1.375,
        'moderately_active' => 1.55,
        'very_active' => 1.725,
        'extra_active' => 1.9,
    ];
    $tdee = $bmr * ($multipliers[$profile['activity_level'] ?? 'moderately_active'] ?? 1.375);

    // 5. Goal Calorie Adjustment
    $targetCals = $tdee;
    if ($basicGoal === 'fat_loss') {
        $targetCals -= 500;
    } elseif ($basicGoal === 'muscle_gain') {
        $targetCals += 300;
    }
    $targetCals = max(1200, (int) round($targetCals));

    // 6. Macro Split
    $dRule = $pdo->prepare('SELECT macro_split FROM diet_rules WHERE primary_goal = ? AND experience_level = ? LIMIT 1');
    $dRule->execute([$basicGoal, $expLevel]);
    $rule = $dRule->fetch(PDO::FETCH_ASSOC);
    if (!$rule) {
        $dRule->execute(['general_health', 1]);
        $rule = $dRule->fetch(PDO::FETCH_ASSOC);
    }
    $splitStr = $rule['macro_split'] ?? '35% Protein / 35% Carbs / 30% Fat';
    preg_match('/(\d+)%\s+Protein\s*\/\s*(\d+)%\s+Carbs\s*\/\s*(\d+)%\s+Fat/i', $splitStr, $matches);
    $p_pct = (isset($matches[1]) ? (int)$matches[1] : 35) / 100;
    $c_pct = (isset($matches[2]) ? (int)$matches[2] : 35) / 100;
    $f_pct = (isset($matches[3]) ? (int)$matches[3] : 30) / 100;

    $p_g = (int) round(($targetCals * $p_pct) / 4);
    $c_g = (int) round(($targetCals * $c_pct) / 4);
    $f_g = (int) round(($targetCals * $f_pct) / 9);

    // 7. Archive any existing active plans
    $pdo->prepare('UPDATE dietary_plans SET status = "completed" WHERE member_user_id = ? AND status = "active"')->execute([$memberUserId]);

    // 8. Title with dietary restriction badge
    $rawRestriction = trim((string)($profile['dietary_restrictions'] ?? 'none'));
    $restriction = ($rawRestriction !== '' && strtolower($rawRestriction) !== 'none') ? strtolower($rawRestriction) : 'none';
    $restrictionLabel = ($restriction !== 'none') ? ' (' . ucwords(str_replace('-', ' ', $restriction)) . ')' : '';
    $title = 'Starter Nutrition Plan' . $restrictionLabel;

    $stmtInsert = $pdo->prepare('INSERT INTO dietary_plans (member_user_id, trainer_id, title, goal, status) VALUES (?, ?, ?, ?, "active")');
    $stmtInsert->execute([$memberUserId, $trainerId, $title, $goal]);
    $planId = (int) $pdo->lastInsertId();

    if ($planId <= 0) {
        return null;
    }

    // 9. Fetch foods filtered by gym scope and dietary restriction
    $memberGymId = (int) scalar('SELECT gym_id FROM gym_members WHERE user_id = ? LIMIT 1', [$memberUserId]);
    $compatibleDiets = get_compatible_dietary_restrictions($restriction);

    if (!empty($compatibleDiets)) {
        $inClause = implode(',', array_fill(0, count($compatibleDiets), '?'));
        $foodQuery = "
            SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc 
            FROM food_items 
            WHERE is_active = 1 
              AND (gym_id = ? OR gym_id IS NULL OR gym_id = 0)
              AND dietary_restriction IN ({$inClause})
            ORDER BY (gym_id IS NOT NULL AND gym_id > 0) DESC, RAND()
        ";
        $foodParams = array_merge([$memberGymId ?: null], $compatibleDiets);
    } else {
        $foodQuery = "
            SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc 
            FROM food_items 
            WHERE is_active = 1 
              AND (gym_id = ? OR gym_id IS NULL OR gym_id = 0)
            ORDER BY (gym_id IS NOT NULL AND gym_id > 0) DESC, (dietary_restriction = 'none') DESC, RAND()
        ";
        $foodParams = [$memberGymId ?: null];
    }

    $foodStmt = $pdo->prepare($foodQuery);
    $foodStmt->execute($foodParams);
    $dbFoods = $foodStmt->fetchAll(PDO::FETCH_ASSOC);

    $foodsByType = ['Breakfast' => [], 'Lunch' => [], 'Dinner' => [], 'Snack' => []];
    foreach ($dbFoods as $f) {
        $mt = ucfirst(strtolower((string)$f['meal_type']));
        if (isset($foodsByType[$mt])) {
            $foodsByType[$mt][] = $f;
        }
    }

    // Fallback for meal types with 0 matches
    foreach (['Breakfast', 'Lunch', 'Dinner', 'Snack'] as $mt) {
        if (empty($foodsByType[$mt])) {
            if (!empty($compatibleDiets)) {
                $inClause = implode(',', array_fill(0, count($compatibleDiets), '?'));
                $fallbackStmt = $pdo->prepare("
                    SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc 
                    FROM food_items 
                    WHERE is_active = 1 
                      AND meal_type = ? 
                      AND dietary_restriction IN ({$inClause}) 
                    ORDER BY RAND()
                ");
                $fallbackStmt->execute(array_merge([$mt], $compatibleDiets));
                $foodsByType[$mt] = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            if (empty($foodsByType[$mt])) {
                if (!in_array($restriction, ['vegetarian', 'vegan', 'halal'])) {
                    $fallbackStmt = $pdo->prepare("SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc FROM food_items WHERE is_active = 1 AND meal_type = ? ORDER BY RAND()");
                    $fallbackStmt->execute([$mt]);
                    $foodsByType[$mt] = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $fallbackStmt = $pdo->prepare("SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc FROM food_items WHERE is_active = 1 AND dietary_restriction IN ('vegetarian', 'vegan') ORDER BY RAND()");
                    $fallbackStmt->execute();
                    $foodsByType[$mt] = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        }
    }

    // 10. Generate 7-day meal schedule
    $dist = ['Breakfast' => 0.25, 'Lunch' => 0.35, 'Dinner' => 0.30, 'Snack' => 0.10];
    $stmtMeal = $pdo->prepare('INSERT INTO dietary_plan_meals (plan_id, day_of_week, meal_type, food_items, image_url, calories, protein_g, carbs_g, fat_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

    for ($d = 1; $d <= 7; $d++) {
        foreach ($dist as $mType => $pct) {
            $mCals = (int) round($targetCals * $pct);
            $mP = (int) round($p_g * $pct);
            $mC = (int) round($c_g * $pct);
            $mF = (int) round($f_g * $pct);
            $portionGrams = max(50, (int) round($mCals / 1.5));

            $options = $foodsByType[$mType];
            $selectedFood = !empty($options) ? $options[($d - 1) % count($options)] : null;

            if ($selectedFood) {
                $mFood = $portionGrams . "g of " . $selectedFood['name'];
                $mImg = !empty($selectedFood['image_url']) 
                    ? $selectedFood['image_url'] 
                    : (function_exists('get_diet_meal_image_url') ? get_diet_meal_image_url($selectedFood['name'], $mType) : null);
            } else {
                $mFood = $portionGrams . "g of Healthy " . $mType;
                $mImg = function_exists('get_diet_meal_image_url') ? get_diet_meal_image_url($mFood, $mType) : null;
            }

            $stmtMeal->execute([$planId, $d, $mType, $mFood, $mImg, $mCals, $mP, $mC, $mF]);
        }
    }

    return $planId;
}
