<?php
declare(strict_types=1);

/**
 * Nutrition & Automated Meal Planning Engine
 */

/**
 * Scales a `food_items` row to a target meal calorie budget using the food's ACTUAL
 * nutritional profile (calories, protein, carbs, fat) and real `serving_size` rather
 * than a hardcoded kcal/g density or forced macro ratio.
 *
 * Servings are snapped to realistic quarter-serving increments (0.5x – 2.5x).
 */
function scale_food_item_for_target(array $food, float $targetCals): array
{
    $name = trim((string) ($food['name'] ?? $food['title'] ?? 'Meal'));
    $servingSize = trim((string) ($food['serving_size'] ?? '1 serving'));
    if ($servingSize === '') {
        $servingSize = '1 serving';
    }

    $baseP = max(0.0, (float) ($food['protein_g'] ?? $food['base_protein'] ?? 0));
    $baseC = max(0.0, (float) ($food['carbs_g'] ?? $food['base_carbs'] ?? 0));
    $baseF = max(0.0, (float) ($food['fat_g'] ?? $food['base_fat'] ?? 0));
    $baseCals = (float) ($food['calories'] ?? $food['base_calories'] ?? 0);
    if ($baseCals <= 0) {
        $macroCals = ($baseP * 4.0) + ($baseC * 4.0) + ($baseF * 9.0);
        $baseCals = $macroCals > 0 ? $macroCals : 300.0;
    }

    $rawScale = $targetCals > 0 ? ($targetCals / $baseCals) : 1.0;
    $servings = max(0.5, min(2.5, round($rawScale * 4) / 4));

    $calories = (int) round($baseCals * $servings);
    $protein  = round($baseP * $servings, 1);
    $carbs    = round($baseC * $servings, 1);
    $fat      = round($baseF * $servings, 1);

    $servingsStr = rtrim(rtrim(number_format($servings, 2, '.', ''), '0'), '.');
    $grams = null;
    if (preg_match('/^(\d+(?:\.\d+)?)\s*g\b/i', $servingSize, $m)) {
        $grams = max(25, (int) round(((float) $m[1]) * $servings));
        $label = $grams . 'g of ' . $name;
    } elseif (preg_match('/^(\d+(?:\.\d+)?)\s+(serving|bowl|cup|plate|slice|piece|scoop|glass|pack|wrap|sandwich|bar|stick|egg|banana|apple)s?$/i', $servingSize, $m)) {
        $qty = ((float) $m[1]) * $servings;
        $qtyStr = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
        $unit = strtolower($m[2]);
        if (abs($qty - 1.0) > 0.001) {
            $unit = in_array($unit, ['glass', 'sandwich'], true) ? ($unit . 'es') : ($unit . 's');
        }
        $label = $qtyStr . ' ' . $unit . ' of ' . $name;
    } elseif (abs($servings - 1.0) < 0.001) {
        $label = $name . ' (' . $servingSize . ')';
    } else {
        $label = $servingsStr . '× ' . $name . ' (' . $servingSize . ')';
    }

    return [
        'servings'   => $servings,
        'food_items' => $label,
        'calories'   => $calories,
        'protein_g'  => $protein,
        'carbs_g'    => $carbs,
        'fat_g'      => $fat,
        'grams'      => $grams,
    ];
}

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
function generate_dietary_plan(int $memberUserId, ?int $trainerId = null, bool $forceRegenerate = false, ?int $gymId = null): ?int
{
    $pdo = db();

    // Resolve gymId if not explicitly passed
    if ($gymId === null || $gymId <= 0) {
        if ($trainerId !== null) {
            $gymId = (int) scalar('SELECT gym_id FROM trainer_profiles WHERE trainer_id = ? LIMIT 1', [$trainerId]);
        }
        if (!$gymId && !empty($_SESSION['current_gym_id'])) {
            $gymId = (int) $_SESSION['current_gym_id'];
        }
        if (!$gymId) {
            $gymId = (int) scalar('SELECT gym_id FROM gym_members WHERE user_id = ? LIMIT 1', [$memberUserId]);
        }
    }
    $scopedGymId = ($gymId > 0) ? $gymId : null;

    // 1. Guard: Automated / system calls ($trainerId === null) must never overwrite a trainer-curated diet plan
    if ($trainerId === null) {
        $guardSql = 'SELECT plan_id FROM dietary_plans WHERE member_user_id = ? AND trainer_id IS NOT NULL AND status = "active"';
        $guardParams = [$memberUserId];
        if ($scopedGymId !== null) {
            $guardSql .= ' AND gym_id = ?';
            $guardParams[] = $scopedGymId;
        }
        $guardSql .= ' ORDER BY plan_id DESC LIMIT 1';
        $existingPlanId = (int) scalar($guardSql, $guardParams);
        if ($existingPlanId > 0) {
            return $existingPlanId;
        }
    }

    // 2. If not forcing regeneration, return existing active plan for this gym if present
    if (!$forceRegenerate) {
        $activeSql = 'SELECT plan_id FROM dietary_plans WHERE member_user_id = ? AND status = "active"';
        $activeParams = [$memberUserId];
        if ($scopedGymId !== null) {
            $activeSql .= ' AND gym_id = ?';
            $activeParams[] = $scopedGymId;
        }
        $activeSql .= ' ORDER BY is_preferred DESC, plan_id DESC LIMIT 1';
        $existingPlanId = (int) scalar($activeSql, $activeParams);
        if ($existingPlanId > 0) {
            return $existingPlanId;
        }
    }

    // 2. Fetch member profile
    $profile = function_exists('member_profile')
        ? member_profile((int)$memberUserId)
        : $pdo->query('SELECT * FROM member_profiles WHERE user_id = ' . (int)$memberUserId)->fetch(PDO::FETCH_ASSOC);
    if (!$profile) {
        return null;
    }

    $goal = $profile['primary_goal'] ?: 'general_health';
    $expLevel = function_exists('resolve_member_experience_level')
        ? resolve_member_experience_level($profile)
        : (in_array((int)($profile['fitness_tier'] ?: 1), [1, 2], true) ? 1 : (in_array((int)($profile['fitness_tier'] ?: 1), [3, 4], true) ? 2 : 3));

    // Map goal to basic for diet rules
    $basicGoal = function_exists('map_detailed_goal_to_basic')
        ? map_detailed_goal_to_basic($goal)
        : ((stripos($goal, 'fat') !== false || stripos($goal, 'weight') !== false || stripos($goal, 'lean') !== false || stripos($goal, 'six-pack') !== false)
            ? 'fat_loss'
            : ((stripos($goal, 'muscle') !== false || stripos($goal, 'bicep') !== false || stripos($goal, 'chest') !== false || stripos($goal, 'bulk') !== false)
                ? 'muscle_gain'
                : 'general_health'));

    // 3. Calculate BMR using Mifflin-St Jeor formula (defaults: 70kg, 170cm, age 25, male if profile fields missing)
    $w = (float) ($profile['weight_kg'] ?: 70);
    $h = (float) ($profile['height_cm'] ?: 170);
    $a = (int) ($profile['age'] ?: 25);
    $bmr = 10 * $w + 6.25 * $h - 5 * $a;
    $bmr += (($profile['biological_sex'] ?? 'male') === 'female') ? -161 : 5;

    // 4. Calculate TDEE (Total Daily Energy Expenditure = BMR * activity multiplier)
    $multipliers = [
        'sedentary' => 1.2,
        'lightly_active' => 1.375,
        'moderately_active' => 1.55,
        'very_active' => 1.725,
        'extra_active' => 1.9,
    ];
    $tdee = $bmr * ($multipliers[$profile['activity_level'] ?? 'moderately_active'] ?? 1.375);

    // 5. Goal Calorie Adjustment (-500 deficit fat_loss, +300 surplus muscle_gain or +150 lean surplus if paired with six-pack, +200 strength, +150 endurance, 0 maintenance; 1200 kcal floor)
    $secondaryFocusLower = strtolower(trim((string)($profile['secondary_focus'] ?? '')));
    $hasSixPackFocus = str_contains($secondaryFocusLower, 'six-pack') || str_contains($secondaryFocusLower, 'six pack');

    $targetCals = $tdee;
    if ($basicGoal === 'fat_loss') {
        $targetCals -= 500;
    } elseif ($basicGoal === 'muscle_gain') {
        $targetCals += $hasSixPackFocus ? 150 : 300;
    } elseif ($basicGoal === 'strength') {
        $targetCals += 200;
    } elseif ($basicGoal === 'endurance') {
        $targetCals += 150;
    }
    $targetCals = max(1200, (int) round($targetCals));

    // 6. Macro Split: fetch goal + experience_level ratios from `diet_rules` (tries goal level 1 before general_health fallback)
    $dRule = $pdo->prepare('SELECT protein_pct, carbs_pct, fat_pct FROM diet_rules WHERE primary_goal = ? AND experience_level = ? LIMIT 1');
    $dRule->execute([$basicGoal, $expLevel]);
    $rule = $dRule->fetch(PDO::FETCH_ASSOC);
    if (!$rule && $expLevel !== 1) {
        $dRule->execute([$basicGoal, 1]);
        $rule = $dRule->fetch(PDO::FETCH_ASSOC);
    }
    if (!$rule) {
        $dRule->execute(['general_health', 1]);
        $rule = $dRule->fetch(PDO::FETCH_ASSOC);
    }
    $p_pct = ((int)($rule['protein_pct'] ?? 35)) / 100;
    $c_pct = ((int)($rule['carbs_pct'] ?? 35)) / 100;
    $f_pct = ((int)($rule['fat_pct'] ?? 30)) / 100;

    // Convert macro calories to daily grams (Protein = 4 kcal/g, Carbs = 4 kcal/g, Fat = 9 kcal/g)
    $p_g = (int) round(($targetCals * $p_pct) / 4);
    $c_g = (int) round(($targetCals * $c_pct) / 4);
    $f_g = (int) round(($targetCals * $f_pct) / 9);

    // 7. Archive any existing active plans FOR THIS GYM ONLY (never overwrite plans from other gyms)
    if ($scopedGymId !== null) {
        $pdo->prepare('UPDATE dietary_plans SET status = "completed" WHERE member_user_id = ? AND gym_id = ? AND status = "active"')->execute([$memberUserId, $scopedGymId]);
    } else {
        $pdo->prepare('UPDATE dietary_plans SET status = "completed" WHERE member_user_id = ? AND (gym_id IS NULL OR gym_id = 0) AND status = "active"')->execute([$memberUserId]);
    }

    // 8. Title with dietary restriction badge
    $rawRestriction = trim((string)($profile['dietary_restrictions'] ?? 'none'));
    $restriction = ($rawRestriction !== '' && strtolower($rawRestriction) !== 'none') ? strtolower($rawRestriction) : 'none';
    $restrictionLabel = ($restriction !== 'none') ? ' (' . ucwords(str_replace('-', ' ', $restriction)) . ')' : '';
    $title = 'Starter Nutrition Plan' . $restrictionLabel;

    // Check if member already has an active preferred plan from another gym
    $hasPreferred = (bool) scalar('SELECT 1 FROM dietary_plans WHERE member_user_id = ? AND is_preferred = 1 AND status = "active" LIMIT 1', [$memberUserId]);
    $isPreferred = $hasPreferred ? 0 : 1;

    $stmtInsert = $pdo->prepare('INSERT INTO dietary_plans (member_user_id, trainer_id, gym_id, title, goal, status, is_preferred) VALUES (?, ?, ?, ?, ?, "active", ?)');
    $stmtInsert->execute([$memberUserId, $trainerId, $scopedGymId, $title, $goal, $isPreferred]);
    $planId = (int) $pdo->lastInsertId();

    if ($planId <= 0) {
        return null;
    }

    if (function_exists('log_plan_change')) {
        $logUserId = $memberUserId;
        if ($trainerId !== null) {
            $tUid = (int) scalar('SELECT user_id FROM trainer_profiles WHERE trainer_id = ? LIMIT 1', [$trainerId]);
            if ($tUid > 0) $logUserId = $tUid;
        }
        log_plan_change('diet', $planId, $scopedGymId, $logUserId, 'created', 'Generated tailored nutrition plan');
    }

    // 9. Food Selection: filter active `food_items` by compatible dietary restrictions & gym scope
    //    (prioritizes gym-custom foods over global staples, randomized via RAND() for variety)
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

    // 10. Generate 7-day meal schedule:
    //     - Daily calorie split: Breakfast 25%, Lunch 35%, Dinner 30%, Snack ~10% (remainder so total === $targetCals)
    //     - Rotates through the randomized food pool per day ($d - 1 % count) for variety
    //     - Scales the chosen food's protein/carbs/fat to match the meal's calorie target ($scale = $mCals / $baseCals)
    $dist = ['Breakfast' => 0.25, 'Lunch' => 0.35, 'Dinner' => 0.30, 'Snack' => 0.10];
    $stmtMeal = $pdo->prepare('INSERT INTO dietary_plan_meals (plan_id, day_of_week, meal_type, food_items, image_url, calories, protein_g, carbs_g, fat_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

    for ($d = 1; $d <= 7; $d++) {
        $mealTargets = [
            'Breakfast' => (int) round($targetCals * 0.25),
            'Lunch'     => (int) round($targetCals * 0.35),
            'Dinner'    => (int) round($targetCals * 0.30),
        ];
        $mealTargets['Snack'] = $targetCals - array_sum($mealTargets);

        foreach ($mealTargets as $mType => $mCals) {
            $pct = $dist[$mType];
            $options = $foodsByType[$mType];
            $selectedFood = !empty($options) ? $options[($d - 1) % count($options)] : null;

            if ($selectedFood) {
                $scaled = scale_food_item_for_target($selectedFood, (float) $mCals);
                $actualCals = (int) $scaled['calories'];
                $mP = (int) round($scaled['protein_g']);
                $mC = (int) round($scaled['carbs_g']);
                $mF = (int) round($scaled['fat_g']);
                $mServings = (float) $scaled['servings'];
                $mFood = $scaled['food_items'];
                $mImg = !empty($selectedFood['image_url']) 
                    ? $selectedFood['image_url'] 
                    : (function_exists('get_diet_meal_image_url') ? get_diet_meal_image_url($selectedFood['name'], $mType) : null);
            } else {
                $actualCals = (int) $mCals;
                $mP = (int) round($p_g * $pct);
                $mC = (int) round($c_g * $pct);
                $mF = (int) round($f_g * $pct);
                $mServings = 1.00;
                $mFood = '1 serving of Balanced ' . $mType;
                $mImg = function_exists('get_diet_meal_image_url') ? get_diet_meal_image_url($mFood, $mType) : null;
            }

            $stmtMeal->execute([$planId, $d, $mType, $mFood, $mImg, $actualCals, $mP, $mC, $mF]);
            $mealId = (int)$pdo->lastInsertId();
            if ($mealId > 0) {
                try {
                    $insMealFood = $pdo->prepare('INSERT INTO meal_foods (meal_id, food_id, food_name, servings, calories, protein_g, carbs_g, fat_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $insMealFood->execute([
                        $mealId,
                        $selectedFood ? (int)$selectedFood['food_id'] : null,
                        $selectedFood ? (string)$selectedFood['name'] : $mFood,
                        $mServings,
                        $actualCals,
                        $mP,
                        $mC,
                        $mF
                    ]);
                } catch (Throwable $e) {}
            }
        }
    }

    return $planId;
}
