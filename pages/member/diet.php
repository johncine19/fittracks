<?php
declare(strict_types=1);

if (!function_exists('get_swap_meal_catalog')) {
function get_swap_meal_catalog(?PDO $pdo = null, ?int $gymId = null): array
{
    if (!$pdo) {
        $pdo = db();
    }

    $query = "
        SELECT food_id, gym_id, name, meal_type, dietary_restriction, serving_size, 
               calories, protein_g, carbs_g, fat_g, image_url, recipe_desc 
        FROM food_items 
        WHERE is_active = 1 
          AND (gym_id = ? OR gym_id IS NULL)
        ORDER BY (gym_id IS NOT NULL) DESC, name ASC
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$gymId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Global fallback if no items found
    if (empty($items)) {
        $fallbackStmt = $pdo->query("
            SELECT food_id, gym_id, name, meal_type, dietary_restriction, serving_size, 
                   calories, protein_g, carbs_g, fat_g, image_url, recipe_desc 
            FROM food_items 
            WHERE is_active = 1 AND gym_id IS NULL
            ORDER BY name ASC
        ");
        $items = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $catalog = [
        'Breakfast' => [],
        'Lunch' => [],
        'Dinner' => [],
        'Snack' => []
    ];

    foreach ($items as $item) {
        $mt = ucfirst(strtolower((string) $item['meal_type']));
        if (!isset($catalog[$mt])) {
            $catalog[$mt] = [];
        }

        $rest = strtolower(trim((string) ($item['dietary_restriction'] ?? 'none')));
        if ($rest === '') {
            $rest = 'none';
        }

        $tags = [];
        if ($rest !== 'none') {
            $tags[] = ucwords(str_replace('-', ' ', $rest));
        }
        if ((float) $item['protein_g'] >= 25) {
            $tags[] = 'High Protein';
        }
        if ((float) $item['carbs_g'] <= 15 && $rest === 'keto') {
            $tags[] = 'Low Carb';
        }
        if (empty($tags)) {
            $tags[] = 'Balanced';
        }

        $restrictions = ['none'];
        if ($rest !== 'none') {
            $restrictions[] = $rest;
        }

        $baseCals = max(50, (float) $item['calories']);
        $proPct = round(((float) $item['protein_g'] * 4) / $baseCals, 2);
        $carbsPct = round(((float) $item['carbs_g'] * 4) / $baseCals, 2);
        $fatPct = max(0.05, round(1 - ($proPct + $carbsPct), 2));

        $catalog[$mt][] = [
            'id' => 'db_' . $item['food_id'],
            'food_id' => (int) $item['food_id'],
            'title' => (string) $item['name'],
            'tags' => $tags,
            'restrictions' => $restrictions,
            'dietary_restriction' => $rest,
            'base_calories' => (int) $item['calories'],
            'base_protein' => (float) $item['protein_g'],
            'base_carbs' => (float) $item['carbs_g'],
            'base_fat' => (float) $item['fat_g'],
            'pro_pct' => $proPct,
            'carbs_pct' => $carbsPct,
            'fat_pct' => $fatPct,
            'desc' => !empty($item['recipe_desc']) ? (string) $item['recipe_desc'] : (string) $item['name'],
            'image_url' => $item['image_url'] ?? null,
            'serving_size' => $item['serving_size'] ?? '',
        ];
    }

    return $catalog;
}
}

// ----------------------------------------------------
// Helper: Smart Food Image Resolver (Global)
// ----------------------------------------------------
if (!function_exists('get_meal_photo_url')) {
    function get_meal_photo_url(?string $customUrl, string $foodItems, string $mealType): string {
        $cUrl = trim((string) $customUrl);
        if (!empty($cUrl)) {
            return $cUrl;
        }

        $lower = strtolower($foodItems);

        // Tier 1: Specific dishes & prepared recipes (highest priority)
        $specificDishes = [
            // Apples & fruit snacks
            'apple slice'   => 'https://images.unsplash.com/photo-1560806887-1e4cd0b6cbd6?auto=format&fit=crop&w=600&q=80',
            'apple'         => 'https://images.unsplash.com/photo-1560806887-1e4cd0b6cbd6?auto=format&fit=crop&w=600&q=80',
            
            // Green bean / sitaw dishes & Tofu
            'adobong sitaw' => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?auto=format&fit=crop&w=600&q=80',
            'sitaw'         => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?auto=format&fit=crop&w=600&q=80',
            'green bean'    => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?auto=format&fit=crop&w=600&q=80',
            'tofu scramble' => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80',
            
            // Soups & Stews
            'tinola'        => 'https://images.unsplash.com/photo-1547592180-85f173990554?auto=format&fit=crop&w=600&q=80', // Ginger chicken broth with greens
            'sinigang'      => 'https://images.unsplash.com/photo-1559847844-5315695dadae?auto=format&fit=crop&w=600&q=80', // Sour tamarind soup with shrimp/fish/veggies
            'munggo'        => 'https://images.unsplash.com/photo-1547592180-85f173990554?auto=format&fit=crop&w=600&q=80', // Hearty mung bean stew
            'laing'         => 'https://images.unsplash.com/photo-1455619452474-d2be8b1e70cd?auto=format&fit=crop&w=600&q=80', // Taro leaves in rich coconut milk
            'bicol express' => 'https://images.unsplash.com/photo-1455619452474-d2be8b1e70cd?auto=format&fit=crop&w=600&q=80',
            'bicol'         => 'https://images.unsplash.com/photo-1455619452474-d2be8b1e70cd?auto=format&fit=crop&w=600&q=80',
            'gising'        => 'https://images.unsplash.com/photo-1455619452474-d2be8b1e70cd?auto=format&fit=crop&w=600&q=80',
            'pinakbet'      => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?auto=format&fit=crop&w=600&q=80', // Filipino vegetable medley
            'pakbet'        => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?auto=format&fit=crop&w=600&q=80',
            
            // Porridges & Breakfast bowls
            'arroz caldo'   => 'https://images.unsplash.com/photo-1563379091339-03b21ab4a4f8?auto=format&fit=crop&w=600&q=80', // Warm chicken rice porridge with egg
            'lugaw'         => 'https://images.unsplash.com/photo-1563379091339-03b21ab4a4f8?auto=format&fit=crop&w=600&q=80',
            'congee'        => 'https://images.unsplash.com/photo-1563379091339-03b21ab4a4f8?auto=format&fit=crop&w=600&q=80',
            'champorado'    => 'https://images.unsplash.com/photo-1584776296944-ab6fb57b0bdd?auto=format&fit=crop&w=600&q=80', // Dark rich chocolate porridge bowl
            'pancake'       => 'https://images.unsplash.com/photo-1565299585323-38d6b0865b47?auto=format&fit=crop&w=600&q=80', // Stack of fluffy pancakes with fruit
            'tortang talong'=> 'https://images.unsplash.com/photo-1582169296194-e4d644c48063?auto=format&fit=crop&w=600&q=80', // Filipino eggplant omelet
            'omelet'        => 'https://images.unsplash.com/photo-1510693206972-df098062cb71?auto=format&fit=crop&w=600&q=80',
            
            // Silog Meals
            'tapsilog'      => 'https://images.unsplash.com/photo-1525351484163-7529414344d8?auto=format&fit=crop&w=600&q=80',
            'bangsilog'     => 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?auto=format&fit=crop&w=600&q=80',
            'tinapasilog'   => 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?auto=format&fit=crop&w=600&q=80',
            'longsilog'     => 'https://images.unsplash.com/photo-1525351484163-7529414344d8?auto=format&fit=crop&w=600&q=80',
            'longganisa'    => 'https://images.unsplash.com/photo-1525351484163-7529414344d8?auto=format&fit=crop&w=600&q=80',
            'silog'         => 'https://images.unsplash.com/photo-1525351484163-7529414344d8?auto=format&fit=crop&w=600&q=80',
            
            // Grilled, Roasted & Braised Meats
            'lechon'        => 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=600&q=80', // Crispy roast pork belly
            'letchon'       => 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=600&q=80',
            'crispy pata'   => 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=600&q=80',
            'bagnet'        => 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=600&q=80',
            'inasal'        => 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?auto=format&fit=crop&w=600&q=80', // Filipino flame-grilled BBQ chicken
            'skewer'        => 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?auto=format&fit=crop&w=600&q=80',
            'ribeye'        => 'https://images.unsplash.com/photo-1558030006-450675393462?auto=format&fit=crop&w=600&q=80', // Seared steak with asparagus
            'steak'         => 'https://images.unsplash.com/photo-1558030006-450675393462?auto=format&fit=crop&w=600&q=80',
            'bistek'        => 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?auto=format&fit=crop&w=600&q=80', // Sliced beef with onions
            'picadillo'     => 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?auto=format&fit=crop&w=600&q=80',
            'adobo'         => 'https://images.unsplash.com/photo-1598515214211-89d3c73ae83b?auto=format&fit=crop&w=600&q=80', // Braised chicken in garlic soy glaze
            'liempo'        => 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=600&q=80',
            'chicharon'     => 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=600&q=80',
            
            // Seafood dishes
            'salmon teriyaki'=> 'https://images.unsplash.com/photo-1467003909585-2f8a72700288?auto=format&fit=crop&w=600&q=80',
            'salmon'        => 'https://images.unsplash.com/photo-1467003909585-2f8a72700288?auto=format&fit=crop&w=600&q=80', // Pan-seared salmon fillet
            'tuna belly'    => 'https://images.unsplash.com/photo-1501595091296-3aa970afb3ff?auto=format&fit=crop&w=600&q=80',
            'tuna'          => 'https://images.unsplash.com/photo-1501595091296-3aa970afb3ff?auto=format&fit=crop&w=600&q=80',
            'bangus'        => 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?auto=format&fit=crop&w=600&q=80', // Grilled whole milkfish
            'tinapa'        => 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?auto=format&fit=crop&w=600&q=80',
            
            // Healthy Snacks & Fitness Staples
            'greek yogurt'  => 'https://images.unsplash.com/photo-1488477181946-6428a0291777?auto=format&fit=crop&w=600&q=80', // Greek yogurt with blueberries
            'yogurt'        => 'https://images.unsplash.com/photo-1488477181946-6428a0291777?auto=format&fit=crop&w=600&q=80',
            'edamame'       => 'https://images.unsplash.com/photo-1556801712-76c8eb07bbc9?auto=format&fit=crop&w=600&q=80', // Bright green steamed edamame pods
            'hard-boiled egg'=> 'https://images.unsplash.com/photo-1587486913049-53fc88980cfc?auto=format&fit=crop&w=600&q=80', // Sliced boiled eggs
            'boiled egg'    => 'https://images.unsplash.com/photo-1587486913049-53fc88980cfc?auto=format&fit=crop&w=600&q=80',
            'cottage cheese'=> 'https://images.unsplash.com/photo-1550258987-190a2d41a8ba?auto=format&fit=crop&w=600&q=80', // Fresh cottage cheese with pineapple
            'pineapple'     => 'https://images.unsplash.com/photo-1550258987-190a2d41a8ba?auto=format&fit=crop&w=600&q=80',
            'protein shake' => 'https://images.unsplash.com/photo-1553530666-ba11a7da3888?auto=format&fit=crop&w=600&q=80', // Shaker smoothie
            'whey'          => 'https://images.unsplash.com/photo-1553530666-ba11a7da3888?auto=format&fit=crop&w=600&q=80',
            'smoothie'      => 'https://images.unsplash.com/photo-1553530666-ba11a7da3888?auto=format&fit=crop&w=600&q=80',
            'shake'         => 'https://images.unsplash.com/photo-1553530666-ba11a7da3888?auto=format&fit=crop&w=600&q=80',
            'kamote'        => 'https://images.unsplash.com/photo-1596040033229-a9821ebd058d?auto=format&fit=crop&w=600&q=80', // Sweet potato
            'sweet potato'  => 'https://images.unsplash.com/photo-1596040033229-a9821ebd058d?auto=format&fit=crop&w=600&q=80',
            'saba'          => 'https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?auto=format&fit=crop&w=600&q=80', // Banana
            'banana'        => 'https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?auto=format&fit=crop&w=600&q=80',
        ];

        uksort($specificDishes, fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($specificDishes as $keyword => $url) {
            if (strpos($lower, $keyword) !== false) {
                return $url;
            }
        }

        // Tier 2: General ingredient keywords (checked only if no specific dish matched)
        $generalIngredients = [
            'fried chicken' => 'https://images.unsplash.com/photo-1626082927389-6cd097cdc6ec?auto=format&fit=crop&w=600&q=80',
            'chicken'       => 'https://images.unsplash.com/photo-1598515214211-89d3c73ae83b?auto=format&fit=crop&w=600&q=80',
            'pork'          => 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=600&q=80',
            'beef'          => 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?auto=format&fit=crop&w=600&q=80',
            'shrimp'        => 'https://images.unsplash.com/photo-1559847844-5315695dadae?auto=format&fit=crop&w=600&q=80',
            'hipon'         => 'https://images.unsplash.com/photo-1559847844-5315695dadae?auto=format&fit=crop&w=600&q=80',
            'fish'          => 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?auto=format&fit=crop&w=600&q=80',
            'talong'        => 'https://images.unsplash.com/photo-1582169296194-e4d644c48063?auto=format&fit=crop&w=600&q=80',
            'eggplant'      => 'https://images.unsplash.com/photo-1582169296194-e4d644c48063?auto=format&fit=crop&w=600&q=80',
            'egg'           => 'https://images.unsplash.com/photo-1525351484163-7529414344d8?auto=format&fit=crop&w=600&q=80',
            'tofu'          => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80',
            'oat'           => 'https://images.unsplash.com/photo-1584776296944-ab6fb57b0bdd?auto=format&fit=crop&w=600&q=80',
            'salad'         => 'https://images.unsplash.com/photo-1512621776951-a57141f2eefd?auto=format&fit=crop&w=600&q=80',
            'peanut'        => 'https://images.unsplash.com/photo-1508061253366-f7da158b6d46?auto=format&fit=crop&w=600&q=80',
            'almond'        => 'https://images.unsplash.com/photo-1508061253366-f7da158b6d46?auto=format&fit=crop&w=600&q=80',
        ];

        uksort($generalIngredients, fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($generalIngredients as $keyword => $url) {
            if (strpos($lower, $keyword) !== false) {
                return $url;
            }
        }

        $typeMap = [
            'Breakfast' => 'https://images.unsplash.com/photo-1533089860892-a7c6f0a88666?auto=format&fit=crop&w=600&q=80',
            'Lunch'     => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80',
            'Dinner'    => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?auto=format&fit=crop&w=600&q=80',
            'Snack'     => 'https://images.unsplash.com/photo-1490818387583-1baba5e638af?auto=format&fit=crop&w=600&q=80',
        ];

        $normType = ucfirst(strtolower(trim($mealType)));
        return $typeMap[$normType] ?? $typeMap['Lunch'];
    }
}

function diet_page(): void
{
    $user = require_roles(['member']);
    $pdo = db();
    $userId = (int) $user['user_id'];

    $action = (string) (post('action') ?: ($_GET['action'] ?? ''));

    // Automatically acknowledge/mark diet plan notifications as read when member opens diet page
    if (empty($action)) {
        try {
            $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0 AND (title LIKE "%Diet Plan%" OR message LIKE "%dietary plan%")')->execute([$userId]);
        } catch (Throwable) {}
    }

    // ----------------------------------------------------
    // AJAX: Get Meal Swap Alternatives
    // ----------------------------------------------------
    if ($action === 'get_swap_options') {
        $mealType = (string) (post('meal_type') ?: ($_GET['meal_type'] ?? 'Lunch'));
        $targetCals = max(100, (int) (post('calories') ?: ($_GET['calories'] ?? 400)));

        $profile = $pdo->query('SELECT dietary_restrictions, primary_goal FROM member_profiles WHERE user_id = ' . $userId)->fetch();
        $userRest = (string) ($profile['dietary_restrictions'] ?? 'none');

        $gymId = get_user_gym_id($user);
        $catalog = get_swap_meal_catalog($pdo, $gymId);
        $candidates = $catalog[$mealType] ?? ($catalog['Lunch'] ?? []);

        if (empty($candidates)) {
            $candidates = array_merge(...array_values($catalog));
        }

        $options = [];
        foreach ($candidates as $rec) {
            $baseCals = (int) ($rec['base_calories'] ?? 0);
            if ($baseCals > 0) {
                $scale = $targetCals / $baseCals;
                $p_g = (float) round($rec['base_protein'] * $scale, 1);
                $c_g = (float) round($rec['base_carbs'] * $scale, 1);
                $f_g = (float) round($rec['base_fat'] * $scale, 1);
            } else {
                $p_g = (float) round(($targetCals * ($rec['pro_pct'] ?? 0.30)) / 4, 1);
                $c_g = (float) round(($targetCals * ($rec['carbs_pct'] ?? 0.40)) / 4, 1);
                $f_g = (float) round(($targetCals * ($rec['fat_pct'] ?? 0.30)) / 9, 1);
            }
            $grams = (int) round($targetCals / 1.5);
            $foodStr = "{$grams}g of {$rec['title']}";

            $foodRest = $rec['dietary_restriction'] ?? 'none';
            $compat = function_exists('get_compatible_dietary_restrictions') ? get_compatible_dietary_restrictions($userRest) : [];
            if ($userRest === 'none' || empty($compat)) {
                $isMatched = true;
            } elseif (in_array($foodRest, $compat, true) || in_array($userRest, $rec['restrictions'] ?? [], true)) {
                $isMatched = true;
            } else {
                $isMatched = false;
            }

            $options[] = [
                'id' => $rec['id'],
                'food_id' => $rec['food_id'] ?? null,
                'title' => $rec['title'],
                'food_items' => $foodStr,
                'calories' => $targetCals,
                'protein_g' => $p_g,
                'carbs_g' => $c_g,
                'fat_g' => $f_g,
                'grams' => $grams,
                'tags' => $rec['tags'] ?? [],
                'desc' => $rec['desc'] ?? '',
                'image_url' => $rec['image_url'] ?? null,
                'is_diet_match' => $isMatched,
            ];
        }

        // Sort: matching user restriction first
        usort($options, function ($a, $b) {
            if ($a['is_diet_match'] === $b['is_diet_match']) return 0;
            return $a['is_diet_match'] ? -1 : 1;
        });

        if (ob_get_level()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'meal_type' => $mealType,
            'user_restriction' => $userRest,
            'target_calories' => $targetCals,
            'options' => $options
        ]);
        exit;
    }

    // ----------------------------------------------------
    // AJAX: Swap Planned Meal
    // ----------------------------------------------------
    if ($action === 'swap_meal' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $mealId = (int) post('meal_id');
        $foodItems = trim((string) post('food_items'));
        $calories = max(0, (int) post('calories'));
        $protein = max(0, (float) post('protein_g'));
        $carbs = max(0, (float) post('carbs_g'));
        $fat = max(0, (float) post('fat_g'));

        // Check ownership: ensure this meal belongs to an active plan for $userId
        $stmtCheck = $pdo->prepare(
            'SELECT dpm.meal_id, dpm.plan_id, dpm.day_of_week, dpm.meal_type, dpm.image_url 
             FROM dietary_plan_meals dpm
             JOIN dietary_plans dp ON dp.plan_id = dpm.plan_id
             WHERE dpm.meal_id = ? AND dp.member_user_id = ?'
        );
        $stmtCheck->execute([$mealId, $userId]);
        $mealRow = $stmtCheck->fetch();

        if (!$mealRow) {
            if (ob_get_level()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Meal not found or unauthorized.']);
            exit;
        }

        if (empty($foodItems)) {
            if (ob_get_level()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Meal description cannot be empty.']);
            exit;
        }

        $stmtUpdate = $pdo->prepare('UPDATE dietary_plan_meals SET food_items = ?, calories = ?, protein_g = ?, carbs_g = ?, fat_g = ? WHERE meal_id = ?');
        $stmtUpdate->execute([$foodItems, $calories, $protein, $carbs, $fat, $mealId]);

        // Recalculate day totals
        $dayNum = (int) $mealRow['day_of_week'];
        $planId = (int) $mealRow['plan_id'];
        $dayTotals = $pdo->query("SELECT SUM(calories) as cals, SUM(protein_g) as pro, SUM(carbs_g) as carbs, SUM(fat_g) as fat FROM dietary_plan_meals WHERE plan_id = {$planId} AND day_of_week = {$dayNum}")->fetch();

        $isToday = ($dayNum === (int) date('N'));
        $todayTargets = null;
        if ($isToday) {
            $todayTargets = [
                'target_cals' => (int) ($dayTotals['cals'] ?? 0),
                'target_pro' => (float) ($dayTotals['pro'] ?? 0),
                'target_carbs' => (float) ($dayTotals['carbs'] ?? 0),
                'target_fat' => (float) ($dayTotals['fat'] ?? 0),
            ];
        }

        $resolvedUrl = get_meal_photo_url($mealRow['image_url'], $foodItems, $mealRow['meal_type']);

        if (ob_get_level()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'meal_id' => $mealId,
            'day_of_week' => $dayNum,
            'meal_type' => $mealRow['meal_type'],
            'food_items' => $foodItems,
            'calories' => $calories,
            'protein_g' => $protein,
            'carbs_g' => $carbs,
            'fat_g' => $fat,
            'is_today' => $isToday,
            'today_targets' => $todayTargets,
            'resolved_url' => $resolvedUrl,
            'day_totals' => [
                'calories' => (int) ($dayTotals['cals'] ?? 0),
                'protein_g' => (float) ($dayTotals['pro'] ?? 0),
                'carbs_g' => (float) ($dayTotals['carbs'] ?? 0),
                'fat_g' => (float) ($dayTotals['fat'] ?? 0),
            ]
        ]);
        exit;
    }

    // ----------------------------------------------------
    // AJAX: Update or Reset Meal Photo (ImageKit or Direct URL)
    // ----------------------------------------------------
    if ($action === 'update_meal_photo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $mealId = (int) post('meal_id');
        $photoType = post('photo_type'); // 'upload', 'url', 'reset'
        $customUrl = trim((string) post('photo_url'));

        // Check ownership: ensure this meal belongs to an active plan for $userId
        $stmtCheck = $pdo->prepare(
            'SELECT dpm.meal_id, dpm.plan_id, dpm.day_of_week, dpm.meal_type, dpm.food_items, dpm.image_url 
             FROM dietary_plan_meals dpm
             JOIN dietary_plans dp ON dp.plan_id = dpm.plan_id
             WHERE dpm.meal_id = ? AND dp.member_user_id = ?'
        );
        $stmtCheck->execute([$mealId, $userId]);
        $mealRow = $stmtCheck->fetch();

        if (!$mealRow) {
            if (ob_get_level()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Meal not found or unauthorized.']);
            exit;
        }

        $newImageUrl = null;

        if ($photoType === 'reset') {
            // Revert back to auto-matched smart food image
            $newImageUrl = null;
        } elseif ($photoType === 'upload' && !empty($_FILES['photo_file']['tmp_name'])) {
            try {
                require_once __DIR__ . '/../../core/file_handler.php';
                // Upload to ImageKit CDN (/meals folder) with local fallback
                $newImageUrl = FileUpload::storeMealImage($_FILES['photo_file']);
            } catch (Throwable $e) {
                if (ob_get_level()) ob_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
        } elseif ($photoType === 'url') {
            if (empty($customUrl) || !filter_var($customUrl, FILTER_VALIDATE_URL)) {
                if (ob_get_level()) ob_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Please provide a valid image URL.']);
                exit;
            }
            $newImageUrl = $customUrl;
        } else {
            if (ob_get_level()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid photo submission.']);
            exit;
        }

        $stmtUpdate = $pdo->prepare('UPDATE dietary_plan_meals SET image_url = ? WHERE meal_id = ?');
        $stmtUpdate->execute([$newImageUrl, $mealId]);

        $resolvedUrl = get_meal_photo_url($newImageUrl, $mealRow['food_items'], $mealRow['meal_type']);

        if (ob_get_level()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'meal_id' => $mealId,
            'image_url' => $newImageUrl,
            'resolved_url' => $resolvedUrl,
            'is_custom' => !empty($newImageUrl),
            'storage_provider' => (!empty($newImageUrl) && str_contains($newImageUrl, 'imagekit.io')) ? 'ImageKit CDN' : 'Custom/Local'
        ]);
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($action === 'generate_plan') {
            $profile = $pdo->query('SELECT * FROM member_profiles WHERE user_id = ' . $userId)->fetch();
            if (!$profile || !(float)$profile['weight_kg'] || !(float)$profile['height_cm'] || !(int)$profile['age']) {
                flash('Please ensure your height, weight, and age are set in your profile before generating a plan.', 'danger');
                redirect('profile'); // Assuming 'profile' is the page to edit this
            }
            
            $goal = $profile['primary_goal'] ?: 'general_health';
            $tier = $profile['fitness_tier'] ?: 1;
            $expLevel = in_array($tier, [1,2]) ? 1 : (in_array($tier, [3,4]) ? 2 : 3);
            
            // 1. Calculate BMR (Mifflin-St Jeor)
            $w = (float) $profile['weight_kg'];
            $h = (float) $profile['height_cm'];
            $a = (int) $profile['age'];
            $bmr = 10 * $w + 6.25 * $h - 5 * $a;
            $bmr += ($profile['biological_sex'] === 'female') ? -161 : 5;
            
            // 2. TDEE
            $multipliers = ['sedentary'=>1.2, 'lightly_active'=>1.375, 'moderately_active'=>1.55, 'very_active'=>1.725, 'extra_active'=>1.9];
            $tdee = $bmr * ($multipliers[$profile['activity_level']] ?? 1.2);
            
            // 3. Goal Adjustment
            $targetCals = $tdee;
            if ($goal === 'fat_loss') $targetCals -= 500;
            if ($goal === 'muscle_gain') $targetCals += 300;
            if ($goal === 'casual') $targetCals = $tdee;
            $targetCals = max(1200, round($targetCals));
            
            // 4. Macro Split (use prepared statements to prevent SQL injection)
            $stmtRule = $pdo->prepare('SELECT macro_split FROM diet_rules WHERE primary_goal = ? AND experience_level = ?');
            $stmtRule->execute([$goal, $expLevel]);
            $rule = $stmtRule->fetch();
            if (!$rule) {
                $stmtRule = $pdo->prepare('SELECT macro_split FROM diet_rules WHERE primary_goal = ?');
                $stmtRule->execute(['general_health']);
                $rule = $stmtRule->fetch();
            }
            $splitStr = $rule['macro_split'] ?? '35% Protein / 35% Carbs / 30% Fat';
            preg_match('/(\d+)%\s+Protein\s*\/\s*(\d+)%\s+Carbs\s*\/\s*(\d+)%\s+Fat/i', $splitStr, $matches);
            $p_pct = (isset($matches[1]) ? (int)$matches[1] : 35) / 100;
            $c_pct = (isset($matches[2]) ? (int)$matches[2] : 35) / 100;
            $f_pct = (isset($matches[3]) ? (int)$matches[3] : 30) / 100;
            
            $p_g = round(($targetCals * $p_pct) / 4);
            $c_g = round(($targetCals * $c_pct) / 4);
            $f_g = round(($targetCals * $f_pct) / 9);
            
            // 5. Create Plan
            $pdo->prepare('UPDATE dietary_plans SET status = "completed" WHERE member_user_id = ? AND status = "active"')->execute([$userId]);
            
            $title = 'Auto-Generated Diet Plan';
            $stmtInsert = $pdo->prepare('INSERT INTO dietary_plans (member_user_id, trainer_id, title, goal, status) VALUES (?, NULL, ?, ?, "active")');
            $stmtInsert->execute([$userId, $title, $goal]);
            $planId = (int) $pdo->lastInsertId();
            
            // 6. Generate Meals from Database
            $restriction = (string) ($profile['dietary_restrictions'] ?? 'none');
            if (empty($restriction)) {
                $restriction = 'none';
            }

            $gymId = get_user_gym_id($user);
            $compatibleDiets = function_exists('get_compatible_dietary_restrictions')
                ? get_compatible_dietary_restrictions($restriction)
                : ($restriction !== 'none' ? [$restriction] : []);

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
                $foodParams = array_merge([$gymId], $compatibleDiets);
            } else {
                $foodQuery = "
                    SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc 
                    FROM food_items 
                    WHERE is_active = 1 
                      AND (gym_id = ? OR gym_id IS NULL OR gym_id = 0)
                    ORDER BY (gym_id IS NOT NULL AND gym_id > 0) DESC, (dietary_restriction = 'none') DESC, RAND()
                ";
                $foodParams = [$gymId];
            }

            $foodStmt = $pdo->prepare($foodQuery);
            $foodStmt->execute($foodParams);
            $dbFoods = $foodStmt->fetchAll(PDO::FETCH_ASSOC);

            $foodsByType = ['Breakfast' => [], 'Lunch' => [], 'Dinner' => [], 'Snack' => []];
            foreach ($dbFoods as $f) {
                $mt = ucfirst(strtolower((string) $f['meal_type']));
                if (isset($foodsByType[$mt])) {
                    $foodsByType[$mt][] = $f;
                }
            }

            // Fallback for any meal type that has no matches
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
                            $fallbackStmt = $pdo->prepare("
                                SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc 
                                FROM food_items 
                                WHERE is_active = 1 AND meal_type = ? 
                                ORDER BY RAND()
                            ");
                            $fallbackStmt->execute([$mt]);
                            $foodsByType[$mt] = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
                        } else {
                            $fallbackStmt = $pdo->prepare("
                                SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc 
                                FROM food_items 
                                WHERE is_active = 1 AND dietary_restriction IN ('vegetarian', 'vegan') 
                                ORDER BY RAND()
                            ");
                            $fallbackStmt->execute();
                            $foodsByType[$mt] = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
                        }
                    }
                }
            }

            $dist = ['Breakfast' => 0.25, 'Lunch' => 0.35, 'Dinner' => 0.30, 'Snack' => 0.10];
            $stmt = $pdo->prepare('INSERT INTO dietary_plan_meals (plan_id, day_of_week, meal_type, food_items, image_url, calories, protein_g, carbs_g, fat_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

            for ($d = 1; $d <= 7; $d++) {
                foreach ($dist as $mType => $pct) {
                    $options = $foodsByType[$mType];
                    $selectedFood = !empty($options) ? $options[($d - 1) % count($options)] : null;

                    if ($selectedFood) {
                        $mFood = $selectedFood['name'];
                        $mImg = $selectedFood['image_url'] ?? null;
                        $mCals = (int) $selectedFood['calories'];
                        $mP = (float) $selectedFood['protein_g'];
                        $mC = (float) $selectedFood['carbs_g'];
                        $mF = (float) $selectedFood['fat_g'];
                    } else {
                        $mCals = round($targetCals * $pct);
                        $mP = round($p_g * $pct);
                        $mC = round($c_g * $pct);
                        $mF = round($f_g * $pct);
                        $mFood = "Healthy " . $mType;
                        $mImg = null;
                    }

                    $stmt->execute([$planId, $d, $mType, $mFood, $mImg, $mCals, $mP, $mC, $mF]);
                }
            }
            
            flash("Dietary plan generated automatically! Target: {$targetCals}kcal", 'success');
            redirect('diet');
        }
    }
    
    // Fetch active diet plan
    $plan = $pdo->prepare('SELECT dp.*, u.first_name as t_first, u.last_name as t_last, u.role as t_role FROM dietary_plans dp LEFT JOIN trainer_profiles tp ON tp.trainer_id = dp.trainer_id LEFT JOIN users u ON u.user_id = tp.user_id WHERE dp.member_user_id = ? AND dp.status = "active" ORDER BY dp.plan_id DESC LIMIT 1');
    $plan->execute([$userId]);
    $activePlan = $plan->fetch();

    // Auto-generate their tailored dietary plan if they don't have one active yet
    if (!$activePlan) {
        $autoPlanId = generate_dietary_plan($userId);
        if ($autoPlanId) {
            $plan->execute([$userId]);
            $activePlan = $plan->fetch();
        }
    }

    $memberProfile = $pdo->query('SELECT dietary_restrictions, primary_goal FROM member_profiles WHERE user_id = ' . $userId)->fetch();
    $userDietaryRestriction = (string) ($memberProfile['dietary_restrictions'] ?? 'none');

    render_header('My Diet Plan', $user);
    
    if (!$activePlan) {
        $dietLabel = ($userDietaryRestriction !== 'none') ? ucwords(str_replace('-', ' ', $userDietaryRestriction)) : 'Standard';
        $goalLabel = !empty($memberProfile['primary_goal']) ? ucwords(str_replace('_', ' ', $memberProfile['primary_goal'])) : '';
        echo '<div class="panel" style="text-align: center; padding: 50px 20px;">
                <div style="font-size: 40px; margin-bottom: 12px;">🥗</div>
                <h2 style="color: var(--ink); margin-bottom: 10px; font-size: 22px; font-weight: 700;">No Active Diet Plan</h2>
                <p style="margin-bottom: 16px; color: var(--muted); font-size: 14px;">You currently do not have an active diet plan.</p>
                <div style="display: inline-flex; align-items: center; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; justify-content: center;">
                    <span style="display: inline-flex; align-items: center; gap: 6px; background: ' . ($userDietaryRestriction !== 'none' ? 'rgba(52, 211, 153, 0.12)' : 'var(--panel-soft)') . '; color: ' . ($userDietaryRestriction !== 'none' ? '#34d399' : 'var(--ink)') . '; border: 1px solid ' . ($userDietaryRestriction !== 'none' ? 'rgba(52, 211, 153, 0.3)' : 'var(--line)') . '; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600;">
                        <span>🥗 Dietary Preference:</span>
                        <strong>' . h($dietLabel) . '</strong>
                    </span>' .
                    ($goalLabel ? '<span style="display: inline-flex; align-items: center; gap: 6px; background: color-mix(in srgb, var(--lime) 12%, transparent); color: var(--ink); border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent); padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600;"><span>🎯 Target Goal:</span><strong style="color:var(--lime);">' . h($goalLabel) . '</strong></span>' : '') . '
                </div>
                <div>
                    <form method="post" style="display:inline-block;">
                        ' . csrf_field() . '
                        <input type="hidden" name="action" value="generate_plan">
                        <button type="submit" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold; font-size: 16px; padding: 12px 24px; border-radius: 8px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                            Generate Diet Plan
                        </button>
                    </form>
                </div>
              </div>';
        render_footer();
        return;
    }
    
    $planId = (int) $activePlan['plan_id'];

    if ($activePlan['trainer_id']) {
        $fullName = trim(($activePlan['t_first'] ?? '') . ' ' . ($activePlan['t_last'] ?? ''));
        if (($activePlan['t_role'] ?? '') === 'gym_owner') {
            $trainerName = h($fullName ?: 'Gym Owner') . ' <span style="font-size:11px; opacity:0.8; font-weight:500;">(Gym Owner)</span>';
        } else {
            $trainerName = h($fullName ?: 'Trainer');
        }
    } else {
        $trainerName = 'Auto-generated';
    }
    
    $mealsRaw = $pdo->query('SELECT * FROM dietary_plan_meals WHERE plan_id = ' . $planId . ' ORDER BY day_of_week ASC, FIELD(meal_type, "Breakfast", "Lunch", "Dinner", "Snack")')->fetchAll();
    
    $mealsByDay = [];
    for ($i = 1; $i <= 7; $i++) {
        $mealsByDay[$i] = [];
    }
    foreach ($mealsRaw as $m) {
        $mealsByDay[(int)$m['day_of_week']][] = $m;
    }
    
    $daysMap = [1=>'Monday', 2=>'Tuesday', 3=>'Wednesday', 4=>'Thursday', 5=>'Friday', 6=>'Saturday', 7=>'Sunday'];
    $shortDaysMap = [1=>'Mon', 2=>'Tue', 3=>'Wed', 4=>'Thu', 5=>'Fri', 6=>'Sat', 7=>'Sun'];

    $dayTotals = [];
    foreach ($daysMap as $dNum => $dName) {
        $totCals = 0;
        foreach ($mealsByDay[$dNum] as $mItem) {
            $totCals += (int)($mItem['calories'] ?? 0);
        }
        $dayTotals[$dNum] = [
            'cals' => $totCals,
            'count' => count($mealsByDay[$dNum])
        ];
    }
?>
<link rel="stylesheet" href="<?= h(asset_url('css/pages/diet.css')) ?>">
<!-- Contextual Action Strip (Goal, Assigned By, and Plan Actions) -->
<div class="diet-action-strip">
    <div class="diet-strip-meta">
        <span class="diet-goal-pill">
            <span style="width: 7px; height: 7px; border-radius: 50%; background: var(--lime); display: inline-block; flex-shrink: 0;"></span>
            <span style="color: var(--muted); font-weight: 500;">Goal:</span>
            <strong class="goal-text" style="color: var(--ink); font-weight: 700;"><?= h(ucwords(str_replace('_', ' ', $activePlan['goal']))) ?></strong>
        </span>

        <?php if ($userDietaryRestriction !== 'none'): ?>
            <span class="diet-restriction-pill" style="display: inline-flex; align-items: center; gap: 6px; background: rgba(52, 211, 153, 0.12); color: #34d399; border: 1px solid rgba(52, 211, 153, 0.3); padding: 5px 12px; border-radius: 20px; font-size: 13px; font-weight: 600;">
                <span style="font-size: 13px;">🥗</span>
                <span style="color: var(--muted); font-weight: 500;">Diet:</span>
                <strong style="color: #34d399; font-weight: 700; text-transform: capitalize;"><?= h(ucwords(str_replace('-', ' ', $userDietaryRestriction))) ?></strong>
            </span>
        <?php else: ?>
            <span class="diet-restriction-pill" style="display: inline-flex; align-items: center; gap: 6px; background: var(--panel-soft); color: var(--ink); border: 1px solid var(--line); padding: 5px 12px; border-radius: 20px; font-size: 13px; font-weight: 600;">
                <span style="font-size: 13px;">🥗</span>
                <span style="color: var(--muted); font-weight: 500;">Diet:</span>
                <strong style="color: var(--ink); font-weight: 700;">Standard</strong>
            </span>
        <?php endif; ?>

        <span class="diet-assigned-pill">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span style="color: var(--muted); font-weight: 500;">Assigned by:</span>
            <strong class="trainer-text" style="color: var(--ink); font-weight: 600;"><?= $trainerName ?></strong>
        </span>
    </div>
    
    <!-- Generate a new plan overriding the current one -->
    <form id="regenerate-plan-form" class="diet-action-form" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="generate_plan">
        <button type="button" onclick="confirmRegeneratePlan()" class="btn btn-diet-action">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.92-10.26l5.67-5.67"/></svg>
            <span>Regenerate Plan</span>
        </button>
    </form>
</div>



<?php
// Calculate today's targets
$todayNum = (int) date('N');
$targetMacros = $pdo->query("SELECT SUM(calories) as cals, SUM(protein_g) as protein, SUM(carbs_g) as carbs, SUM(fat_g) as fat FROM dietary_plan_meals WHERE plan_id = {$planId} AND day_of_week = {$todayNum}")->fetch();
$targetCals = (int)($targetMacros['cals'] ?? 0);
$targetPro = (int)($targetMacros['protein'] ?? 0);
$targetCarbs = (int)($targetMacros['carbs'] ?? 0);
$targetFat = (int)($targetMacros['fat'] ?? 0);

// Fetch logged macros for today
$loggedMacros = $pdo->query("SELECT * FROM daily_macros WHERE user_id = {$userId} AND log_date = CURDATE()")->fetch();
$loggedCals = $loggedMacros ? (int)$loggedMacros['calories'] : 0;
$loggedPro = $loggedMacros ? (int)$loggedMacros['protein_g'] : 0;
$loggedCarbs = $loggedMacros ? (int)$loggedMacros['carbs_g'] : 0;
$loggedFat = $loggedMacros ? (int)$loggedMacros['fat_g'] : 0;

// Fetch logged meal slots for today (guard rail to prevent duplicate logs)
$loggedMealsTodayRaw = $pdo->query("SELECT meal_type, meal_id, logged_at FROM member_meal_logs WHERE user_id = {$userId} AND log_date = CURDATE()")->fetchAll();
$loggedMealsTodayMap = [];
foreach ($loggedMealsTodayRaw as $lm) {
    $norm = ucfirst(strtolower(trim($lm['meal_type'])));
    if (strpos(strtolower($norm), 'snack') !== false) {
        $norm = 'Snack';
    }
    $loggedMealsTodayMap[$norm] = [
        'time' => date('g:i A', strtotime($lm['logged_at'])),
        'meal_id' => (int) $lm['meal_id']
    ];
}
?>
<script>
window.DIET_CONFIG = {
    csrfToken: <?= json_encode(csrf_token()) ?>,
    loggedMealsToday: <?= json_encode($loggedMealsTodayMap ?? []) ?>,
    currentDayNum: <?= (int)($todayNum ?? 1) ?>
};
window.FT_LOGGED_MEALS_TODAY = window.DIET_CONFIG.loggedMealsToday;
window.FT_CURRENT_DAY_NUM = window.DIET_CONFIG.currentDayNum;
</script>
<?php

$calcMacroStatus = function(int $logged, int $target, string $type): array {
    if ($target <= 0) {
        return ['text' => '0%', 'color' => 'var(--muted)', 'pct' => 0, 'barBg' => "var(--macro-{$type})"];
    }
    $diff = $logged - $target;
    $pct = (int) round(($logged / $target) * 100);

    if ($diff > 0) {
        if ($type === 'pro') {
            return [
                'text'  => "+{$diff}g Over",
                'color' => '#22c55e',
                'pct'   => 100,
                'barBg' => '#22c55e'
            ];
        } elseif ($type === 'cals') {
            return [
                'text'  => "+{$diff} kcal Over",
                'color' => '#f59e0b',
                'pct'   => 100,
                'barBg' => '#f59e0b'
            ];
        } elseif ($type === 'carbs') {
            return [
                'text'  => "+{$diff}g Over",
                'color' => '#f59e0b',
                'pct'   => 100,
                'barBg' => '#f59e0b'
            ];
        } else { // fat
            return [
                'text'  => "+{$diff}g Over",
                'color' => '#f43f5e',
                'pct'   => 100,
                'barBg' => '#f43f5e'
            ];
        }
    } elseif ($diff === 0) {
        return [
            'text'  => '100% ✓ Met',
            'color' => '#22c55e',
            'pct'   => 100,
            'barBg' => '#22c55e'
        ];
    } else {
        return [
            'text'  => "{$pct}%",
            'color' => 'var(--muted)',
            'pct'   => min(100, $pct),
            'barBg' => "var(--macro-{$type})"
        ];
    }
};

$stCals  = $calcMacroStatus($loggedCals, $targetCals, 'cals');
$stPro   = $calcMacroStatus($loggedPro, $targetPro, 'pro');
$stCarbs = $calcMacroStatus($loggedCarbs, $targetCarbs, 'carbs');
$stFat   = $calcMacroStatus($loggedFat, $targetFat, 'fat');
?>

<!-- Top View Switcher Navigation: Tracker vs Meal Plan -->
<div class="diet-main-nav-wrap">
    <div class="diet-main-nav">
        <button type="button" class="diet-nav-pill active" id="btn-view-tracker" onclick="switchDietView('tracker')">
            <div class="diet-nav-pill-title-row">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="20" x2="18" y2="10"></line>
                    <line x1="12" y1="20" x2="12" y2="4"></line>
                    <line x1="6" y1="20" x2="6" y2="14"></line>
                </svg>
                <span class="diet-nav-title-full">Today's Macro Tracker</span>
                <span class="diet-nav-title-short">Macro Tracker</span>
            </div>
            <span class="diet-nav-badge tracker-badge"><?= $loggedCals ?> / <?= $targetCals ?> kcal</span>
        </button>
        <button type="button" class="diet-nav-pill" id="btn-view-plan" onclick="switchDietView('plan')">
            <div class="diet-nav-pill-title-row">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
                <span class="diet-nav-title-full">Weekly Meal Plan</span>
                <span class="diet-nav-title-short">Meal Plan</span>
            </div>
            <span class="diet-nav-badge plan-badge">7-Day Schedule</span>
        </button>
    </div>
</div>

<!-- ==================================================== -->
<!-- VIEW 1: TODAY'S MACRO TRACKER                        -->
<!-- ==================================================== -->
<div id="view-macro-tracker" class="diet-view-panel">
    <div class="macro-tracker-card skeleton-content animate-fade-in">
    <div class="macro-card-header">
        <div>
            <h2 class="macro-card-title">
                <span>Today's Macro Tracker</span>
            </h2>
            <p class="macro-card-subtitle">Track your daily calorie and macronutrient targets.</p>
        </div>
        <div>
            <button type="button" id="btn-auto-calc-cals" class="macro-aux-btn">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                <span>Auto-calc Calories (4P + 4C + 9F)</span>
            </button>
        </div>
    </div>

    <!-- 4 Macro Progress Cards Grid -->
    <div class="macro-stat-cards-grid">
        <!-- Calories -->
        <div class="macro-stat-card card-cals">
            <div class="macro-stat-head">
                <span class="macro-stat-label label-cals">
                    <span class="macro-stat-dot dot-cals"></span>
                    Calories
                </span>
                <span id="macro-pct-cals" class="macro-stat-pct" style="color: <?= $stCals['color'] ?>;"><?= $stCals['text'] ?></span>
            </div>
            <div class="macro-stat-numbers">
                <span id="macro-logged-cals" class="val-main" style="color: var(--macro-cals);"><?= $loggedCals ?></span>
                <span class="val-sub">/ <span id="macro-target-cals"><?= $targetCals ?></span> kcal</span>
            </div>
            <div class="macro-progress-track">
                <div id="macro-progress-bar-cals" style="height: 100%; width: <?= $stCals['pct'] ?>%; background: <?= $stCals['barBg'] ?>; transition: width 0.7s ease, background 0.3s ease;"></div>
            </div>
        </div>

        <!-- Protein -->
        <div class="macro-stat-card card-pro">
            <div class="macro-stat-head">
                <span class="macro-stat-label label-pro">
                    <span class="macro-stat-dot dot-pro"></span>
                    Protein
                </span>
                <span id="macro-pct-pro" class="macro-stat-pct" style="color: <?= $stPro['color'] ?>;"><?= $stPro['text'] ?></span>
            </div>
            <div class="macro-stat-numbers">
                <span id="macro-logged-pro" class="val-main" style="color: var(--macro-pro);"><?= $loggedPro ?></span>
                <span class="val-sub">/ <span id="macro-target-pro"><?= $targetPro ?></span> g</span>
            </div>
            <div class="macro-progress-track">
                <div id="macro-progress-bar-pro" style="height: 100%; width: <?= $stPro['pct'] ?>%; background: <?= $stPro['barBg'] ?>; transition: width 0.7s ease, background 0.3s ease;"></div>
            </div>
        </div>

        <!-- Carbs -->
        <div class="macro-stat-card card-carbs">
            <div class="macro-stat-head">
                <span class="macro-stat-label label-carbs">
                    <span class="macro-stat-dot dot-carbs"></span>
                    Carbs
                </span>
                <span id="macro-pct-carbs" class="macro-stat-pct" style="color: <?= $stCarbs['color'] ?>;"><?= $stCarbs['text'] ?></span>
            </div>
            <div class="macro-stat-numbers">
                <span id="macro-logged-carbs" class="val-main" style="color: var(--macro-carbs);"><?= $loggedCarbs ?></span>
                <span class="val-sub">/ <span id="macro-target-carbs"><?= $targetCarbs ?></span> g</span>
            </div>
            <div class="macro-progress-track">
                <div id="macro-progress-bar-carbs" style="height: 100%; width: <?= $stCarbs['pct'] ?>%; background: <?= $stCarbs['barBg'] ?>; transition: width 0.7s ease, background 0.3s ease;"></div>
            </div>
        </div>

        <!-- Fat -->
        <div class="macro-stat-card card-fat">
            <div class="macro-stat-head">
                <span class="macro-stat-label label-fat">
                    <span class="macro-stat-dot dot-fat"></span>
                    Fat
                </span>
                <span id="macro-pct-fat" class="macro-stat-pct" style="color: <?= $stFat['color'] ?>;"><?= $stFat['text'] ?></span>
            </div>
            <div class="macro-stat-numbers">
                <span id="macro-logged-fat" class="val-main" style="color: var(--macro-fat);"><?= $loggedFat ?></span>
                <span class="val-sub">/ <span id="macro-target-fat"><?= $targetFat ?></span> g</span>
            </div>
            <div class="macro-progress-track">
                <div id="macro-progress-bar-fat" style="height: 100%; width: <?= $stFat['pct'] ?>%; background: <?= $stFat['barBg'] ?>; transition: width 0.7s ease, background 0.3s ease;"></div>
            </div>
        </div>
    </div>

    <!-- Macro Log Form Controls -->
    <div class="macro-controls-bar">
        <div class="macro-mode-tabs">
            <button type="button" id="tab-mode-add" class="macro-tab-btn active" onclick="setMacroLogMode('add')">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <span>Add Meal / Intake</span>
            </button>
            <button type="button" id="tab-mode-set" class="macro-tab-btn" onclick="setMacroLogMode('set')">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                <span>Edit Total Directly</span>
            </button>
        </div>
        <div class="macro-reset-wrap">
            <button type="button" id="btn-reset-macros" class="macro-reset-btn">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                <span>Reset Today</span>
            </button>
        </div>
    </div>

    <!-- Smart Meal Assistant (CalorieNinjas & Open Food Facts) -->
    <div class="smart-meal-box" id="smart-meal-assistant">
        <div class="smart-meal-header">
            <div class="smart-meal-title">
                <div class="smart-title-group">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--macro-pro)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                    </svg>
                    <span>Smart Meal Assistant</span>
                </div>
                <span class="smart-api-badge">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                    <span>AI + Grocery DB</span>
                </span>
            </div>
            <div class="smart-meal-tabs">
                <button type="button" class="smart-tab-btn active" id="tab-smart-ninja" onclick="switchSmartTab('ninja')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                    <span>Natural Text</span>
                </button>
                <button type="button" class="smart-tab-btn" id="tab-smart-off" onclick="switchSmartTab('off')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <span>Grocery Search</span>
                </button>
            </div>
        </div>

        <!-- Panel 1: Natural Meal Entry (CalorieNinjas) -->
        <div class="smart-meal-panel active" id="panel-smart-ninja">
            <div class="smart-panel-desc">
                Type your meal naturally with portions to auto-calculate and populate calories & macros:
            </div>
            <div class="smart-meal-input-wrap">
                <input type="text" id="smart-ninja-input" class="smart-meal-textarea" placeholder="e.g., 2 eggs, 1 slice wheat bread, and 1 glass milk..." autocomplete="off">
                <div class="smart-meal-btn-group">
                    <button type="button" id="btn-clear-smart-ninja" class="smart-meal-clear-btn" onclick="clearSmartMealAssistant()" title="Clear search and input fields">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        <span>Clear</span>
                    </button>
                    <button type="button" id="btn-analyze-ninja" class="smart-meal-action-btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <span id="btn-analyze-ninja-txt">Analyze & Fill</span>
                    </button>
                </div>
            </div>
            <!-- Quick Suggestion Pills (SVG icons, zero emojis) -->
            <div class="smart-quick-pills">
                <span class="quick-pill-label">Quick meals:</span>
                <button type="button" class="smart-pill" onclick="setQuickMealText('2 boiled eggs and 1 slice whole wheat bread')">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/></svg>
                    <span>2 Eggs & Toast</span>
                </button>
                <button type="button" class="smart-pill" onclick="setQuickMealText('150g grilled chicken breast and 1 cup white rice')">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2a5 5 0 0 1 5 5c0 2.5-1.5 4.5-3.5 5.5l1.5 6.5-3-1-3 1 1.5-6.5C8.5 11.5 7 9.5 7 7a5 5 0 0 1 5-5z"/></svg>
                    <span>150g Chicken & Rice</span>
                </button>
                <button type="button" class="smart-pill" onclick="setQuickMealText('1 scoop whey protein and 1 cup milk')">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 2h12v3l-2 15a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2L6 5V2z"/><line x1="6" y1="7" x2="18" y2="7"/></svg>
                    <span>Whey & Milk</span>
                </button>
                <button type="button" class="smart-pill" onclick="setQuickMealText('1 can tuna and 1 medium banana')">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 4.24 4.24"/><path d="m14.83 9.17 4.24-4.24"/><path d="m14.83 14.83 4.24 4.24"/><path d="m9.17 14.83-4.24 4.24"/></svg>
                    <span>Tuna & Banana</span>
                </button>
            </div>

            <!-- CalorieNinjas Breakdown Container -->
            <div id="ninja-breakdown-wrap" style="display: none;" class="smart-breakdown-card">
                <div class="smart-breakdown-header">
                    <span class="smart-breakdown-title">Meal Ingredients Breakdown</span>
                    <span class="smart-breakdown-badge">✓ Applied to inputs below</span>
                </div>
                <div id="ninja-breakdown-items" class="smart-breakdown-list"></div>
                <div class="smart-breakdown-totals-bar">
                    <div id="ninja-breakdown-totals" class="smart-totals-grid"></div>
                    <span class="smart-breakdown-hint">Click "+ Add to Log" below to record this meal.</span>
                </div>
            </div>
        </div>

        <!-- Panel 2: Food & Brand Search (Open Food Facts) -->
        <div class="smart-meal-panel" id="panel-smart-off">
            <div class="smart-panel-desc">
                Search 3M+ grocery items, snacks, and products worldwide (Open Food Facts):
            </div>
            <div class="off-search-wrap">
                <div class="smart-meal-input-wrap off-search-main">
                    <input type="text" id="smart-off-input" class="smart-meal-textarea" placeholder="Search product or brand (e.g., Greek yogurt, Rolled oats)..." autocomplete="off">
                    <div class="smart-meal-btn-group">
                        <button type="button" id="btn-clear-smart-off" class="smart-meal-clear-btn" onclick="clearSmartMealAssistant()" title="Clear search and input fields">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                            <span>Clear</span>
                        </button>
                        <button type="button" id="btn-search-off" class="smart-meal-action-btn off-search-btn">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <span id="btn-search-off-txt">Search</span>
                        </button>
                    </div>
                </div>
                <div class="off-portion-pill">
                    <span class="off-portion-label">Portion:</span>
                    <button type="button" class="off-stepper-btn" onclick="adjustOffServing(-10)" title="Decrease 10g" aria-label="Decrease portion">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    </button>
                    <input type="number" id="off-serving-input" value="100" min="5" max="2000" step="5" class="off-serving-input" inputmode="numeric">
                    <button type="button" class="off-stepper-btn" onclick="adjustOffServing(10)" title="Increase 10g" aria-label="Increase portion">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    </button>
                    <span class="off-portion-unit">grams</span>
                </div>
            </div>

            <!-- Open Food Facts Results Container -->
            <div id="off-results-container" style="display: none; margin-top: 10px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <span style="font-size: 11.5px; color: var(--muted); font-weight: 600;" id="off-results-count">Select a food item to apply:</span>
                    <button type="button" onclick="document.getElementById('off-results-container').style.display='none'" style="background: transparent; border: none; color: var(--muted); cursor: pointer; font-size: 11px; text-decoration: underline;">Hide results</button>
                </div>
                <div class="off-search-results" id="off-results-grid"></div>
            </div>
        </div>
    </div>

    <!-- Macro Log Form -->
    <form id="macro-log-form" action="index.php?page=log_macros" method="post" class="macro-log-form-grid">
        <?= csrf_field() ?>
        <input type="hidden" id="macro-input-mode" name="mode" value="add">
        <div class="macro-form-col">
            <label id="macro-lbl-cals" class="macro-input-lbl label-cals">
                <span class="macro-stat-dot dot-cals"></span>
                <span id="lbl-text-cals">+ Add Calories</span>
            </label>
            <input id="macro-input-cals" class="macro-field field-cals" type="number" min="0" name="calories" value="" required placeholder="+0" inputmode="numeric">
        </div>
        <div class="macro-form-col">
            <label id="macro-lbl-pro" class="macro-input-lbl label-pro">
                <span class="macro-stat-dot dot-pro"></span>
                <span id="lbl-text-pro">+ Add Protein (g)</span>
            </label>
            <input id="macro-input-pro" class="macro-field field-pro" type="number" min="0" name="protein_g" value="" placeholder="+0" inputmode="decimal" step="0.1">
        </div>
        <div class="macro-form-col">
            <label id="macro-lbl-carbs" class="macro-input-lbl label-carbs">
                <span class="macro-stat-dot dot-carbs"></span>
                <span id="lbl-text-carbs">+ Add Carbs (g)</span>
            </label>
            <input id="macro-input-carbs" class="macro-field field-carbs" type="number" min="0" name="carbs_g" value="" placeholder="+0" inputmode="decimal" step="0.1">
        </div>
        <div class="macro-form-col">
            <label id="macro-lbl-fat" class="macro-input-lbl label-fat">
                <span class="macro-stat-dot dot-fat"></span>
                <span id="lbl-text-fat">+ Add Fat (g)</span>
            </label>
            <input id="macro-input-fat" class="macro-field field-fat" type="number" min="0" name="fat_g" value="" placeholder="+0" inputmode="decimal" step="0.1">
        </div>
        <div class="macro-form-col macro-submit-col">
            <button id="macro-save-btn" type="submit" class="macro-save-btn">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                <span id="btn-save-text">+ Add to Log</span>
            </button>
        </div>
    </form>


<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"></script>

    </div>
</div> <!-- Close #view-macro-tracker -->

<!-- ==================================================== -->
<!-- VIEW 2: WEEKLY MEAL PLAN                             -->
<!-- ==================================================== -->
<div id="view-meal-plan" class="diet-view-panel" style="display: none;">
    <div style="background: var(--surface); border-radius: 12px; border: 1px solid var(--line); overflow: hidden; box-shadow: var(--macro-card-glow);">
        <!-- Tabs Header -->
        <div class="diet-days-nav-wrapper">
            <div class="diet-days-grid">
                <?php foreach ($daysMap as $dayNum => $dayName): 
                    $isToday = ($dayNum === $todayNum);
                    $dTotals = $dayTotals[$dayNum] ?? ['cals' => 0, 'count' => 0];
                ?>
                    <button type="button" 
                            id="diet-day-btn-<?= $dayNum ?>" 
                            class="diet-day-tab <?= $isToday ? 'active is-today-tab' : '' ?>" 
                            onclick="switchDietTab(<?= $dayNum ?>)">
                        <div class="diet-day-label-group">
                            <span class="diet-day-full"><?= $dayName ?></span>
                            <span class="diet-day-short"><?= $shortDaysMap[$dayNum] ?></span>
                            <?php if ($isToday): ?>
                                <span class="diet-day-today-tag">Today</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($dTotals['cals'] > 0): ?>
                            <span class="diet-day-cals"><?= number_format($dTotals['cals']) ?> kcal</span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Tab Contents -->
        <div style="padding: 24px;">
            <?php foreach ($daysMap as $dayNum => $dayName): ?>
                <div id="diet-tab-<?= $dayNum ?>" class="diet-tab-content" style="display: <?= $dayNum === $todayNum ? 'block' : 'none' ?>; animation: fadeIn 0.3s ease;">
                    <div class="diet-plan-day-header">
                        <div class="day-header-left">
                            <h3 class="day-header-title"><?= $dayName ?>'s Plan</h3>
                            <?php if ($dayNum === $todayNum): ?>
                                <span class="diet-today-chip">
                                    <span class="chip-dot"></span> Today
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="day-header-meta">
                            <span class="day-meta-pill">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/></svg>
                                <strong><?= count($mealsByDay[$dayNum]) ?></strong> Meals
                            </span>
                            <span class="day-meta-pill kcal-pill">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
                                <strong><?= number_format($dayTotals[$dayNum]['cals'] ?? 0) ?></strong> kcal
                            </span>
                        </div>
                    </div>
                
                <?php if (empty($mealsByDay[$dayNum])): ?>
                    <div style="padding: 40px; text-align: center; color: var(--muted); background: var(--bg); border-radius: 8px; border: 1px dashed var(--line);">
                        <p style="margin:0; font-style: italic;">No meals specified for this day.</p>
                    </div>
                <?php else: ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr)); gap: 20px;">
                        <?php 
                        $dayCals = 0; $dayPro = 0; $dayCarbs = 0; $dayFat = 0;
                        $isToday = ($dayNum === $todayNum);
                        foreach ($mealsByDay[$dayNum] as $meal): 
                            $dayCals += $meal['calories'];
                            $dayPro += $meal['protein_g'];
                            $dayCarbs += $meal['carbs_g'];
                            $dayFat += $meal['fat_g'];

                            $mealTypeRaw = $meal['meal_type'];
                            $mealTypeNorm = ucfirst(strtolower(trim($mealTypeRaw)));
                            if (strpos(strtolower($mealTypeNorm), 'snack') !== false) {
                                $mealTypeNorm = 'Snack';
                            }
                            $isLoggedToday = $isToday && isset($loggedMealsTodayMap[$mealTypeNorm]);
                            $loggedTimeStr = $isLoggedToday ? $loggedMealsTodayMap[$mealTypeNorm]['time'] : '';
                            $mealPhotoUrl = get_meal_photo_url($meal['image_url'] ?? null, $meal['food_items'], $meal['meal_type']);
                        ?>
                            <div class="meal-card" id="meal-card-<?= $meal['meal_id'] ?>"
                                 data-meal-id="<?= $meal['meal_id'] ?>"
                                 data-day="<?= $dayNum ?>"
                                 data-type="<?= h($meal['meal_type']) ?>"
                                 data-cals="<?= $meal['calories'] ?>"
                                 data-pro="<?= $meal['protein_g'] ?>"
                                 data-carbs="<?= $meal['carbs_g'] ?>"
                                 data-fat="<?= $meal['fat_g'] ?>"
                                 data-food="<?= htmlspecialchars($meal['food_items'], ENT_QUOTES, 'UTF-8') ?>"
                                 style="background: var(--bg); padding: 0; border-radius: 12px; border: 1px solid var(--line); position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between;">
                                
                                <!-- Meal Photo Banner (ImageKit CDN or Auto-Matched) -->
                                <div class="meal-photo-banner">
                                    <img id="meal-img-<?= $meal['meal_id'] ?>" 
                                         src="<?= h($mealPhotoUrl) ?>" 
                                         alt="<?= h($meal['food_items']) ?>" 
                                         class="meal-banner-img" 
                                         loading="lazy"
                                         onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80';">
                                    <div class="meal-banner-gradient"></div>
                                    
                                    <!-- Floating Badges on Top of Photo -->
                                    <div class="meal-banner-top">
                                        <span class="meal-banner-type"><?= h($meal['meal_type']) ?></span>
                                    </div>

                                    <div class="meal-banner-bottom">
                                        <span id="meal-cals-badge-<?= $meal['meal_id'] ?>" class="meal-banner-cals">
                                            <?= $meal['calories'] ?> kcal
                                        </span>
                                        <span id="meal-check-<?= $meal['meal_id'] ?>" class="meal-banner-logged-tag" style="<?= ($isToday && $isLoggedToday) ? 'display:inline-flex;' : 'display:none;' ?>" title="Logged for today">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                            <span>Logged</span>
                                        </span>
                                    </div>
                                </div>

                                <!-- Inner Card Content -->
                                <div style="padding: 16px 18px 18px; display: flex; flex-direction: column; flex: 1; justify-content: space-between;">
                                    <div>
                                        <!-- Dynamic Meal Card State / Pill -->
                                        <div class="meal-status-pill-wrap" style="margin-bottom: 12px;">
                                            <?php if ($isToday && $isLoggedToday): ?>
                                                <span id="meal-status-pill-<?= $meal['meal_id'] ?>" class="meal-status-pill pill-logged">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                    <span class="status-txt">Logged today at <?= h($loggedTimeStr) ?></span>
                                                </span>
                                            <?php elseif ($isToday): ?>
                                                <span id="meal-status-pill-<?= $meal['meal_id'] ?>" class="meal-status-pill pill-unlogged">
                                                    <span class="meal-status-dot"></span>
                                                    <span class="status-txt">No meal logged yet</span>
                                                </span>
                                            <?php else: ?>
                                                <span id="meal-status-pill-<?= $meal['meal_id'] ?>" class="meal-status-pill pill-scheduled">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                                    <span class="status-txt"><?= h($dayName) ?> schedule (View only)</span>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div id="meal-food-<?= $meal['meal_id'] ?>" class="meal-food-text" style="color: var(--ink); font-size: 0.95rem; font-weight: 600; margin-bottom: 14px; line-height: 1.45; min-height: 44px;">
                                            <?= nl2br(h($meal['food_items'])) ?>
                                        </div>
                                        
                                        <div style="display: flex; gap: 10px; font-size: 0.85rem; color: var(--ink); background: color-mix(in srgb, var(--surface) 50%, var(--bg)); padding: 10px; border-radius: 8px; justify-content: space-between; margin-bottom: 14px;">
                                            <div style="text-align: center; flex: 1;"><strong style="display:block; color:var(--muted); font-size:10px; text-transform:uppercase;">Protein</strong> <span id="meal-pro-<?= $meal['meal_id'] ?>"><?= $meal['protein_g'] ?></span>g</div>
                                            <div style="width:1px; background:var(--line);"></div>
                                            <div style="text-align: center; flex: 1;"><strong style="display:block; color:var(--muted); font-size:10px; text-transform:uppercase;">Carbs</strong> <span id="meal-carbs-<?= $meal['meal_id'] ?>"><?= $meal['carbs_g'] ?></span>g</div>
                                            <div style="width:1px; background:var(--line);"></div>
                                            <div style="text-align: center; flex: 1;"><strong style="display:block; color:var(--muted); font-size:10px; text-transform:uppercase;">Fat</strong> <span id="meal-fat-<?= $meal['meal_id'] ?>"><?= $meal['fat_g'] ?></span>g</div>
                                        </div>
                                    </div>

                                    <!-- Actions toolbar: Log Meal, Inspect Ingredients, Swap Meal -->
                                    <div class="meal-card-actions">
                                        <?php if ($isToday && $isLoggedToday): ?>
                                            <button type="button" class="meal-action-btn btn-log-meal logged" onclick="handleAlreadyLoggedClick('<?= h($meal['meal_type']) ?>', '<?= h($loggedTimeStr) ?>')" title="Already logged for today">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                <span>Logged ✓</span>
                                            </button>
                                        <?php elseif ($isToday): ?>
                                            <button type="button" class="meal-action-btn btn-log-meal" id="btn-log-meal-<?= $meal['meal_id'] ?>" onclick="quickLogPlannedMeal(<?= $meal['meal_id'] ?>)" title="Quick-log this meal into today's macros">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                                                <span>Log Meal</span>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="meal-action-btn btn-view-only" onclick="handleViewOnlyClick('<?= h($dayName) ?>')" title="Scheduled for <?= h($dayName) ?>">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                                                <span>View Only</span>
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="meal-action-btn btn-inspect-meal" onclick="inspectPlannedMeal(<?= $meal['meal_id'] ?>)" title="Inspect itemized ingredients & micronutrients">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                            <span>Ingredients</span>
                                        </button>
                                        <button type="button" class="meal-action-btn btn-swap-meal" onclick="openSwapMealModal(<?= $meal['meal_id'] ?>)" title="Swap with alternative healthy recipes">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 3h5v5"/><path d="M4 20L21 3"/><path d="M21 16v5h-5"/><path d="M15 15l6 6"/><path d="M4 4l5 5"/></svg>
                                            <span>Swap</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Daily Totals -->
                    <div style="margin-top: 24px; padding: 20px; background: rgba(163, 230, 53, 0.05); border: 1px solid rgba(163, 230, 53, 0.2); border-radius: 12px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="background: var(--lime); color: var(--bg); width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
                            </div>
                            <div>
                                <div style="font-size: 0.85rem; color: var(--muted); text-transform: uppercase; letter-spacing: 1px;">Daily Total Calories</div>
                                <div style="font-size: 1.4rem; font-weight: bold; color: var(--lime);"><span id="day-total-cals-<?= $dayNum ?>"><?= $dayCals ?></span> kcal</div>
                            </div>
                        </div>
                        <div style="display: flex; gap: 24px; font-size: 1rem;">
                            <div style="display: flex; flex-direction: column; align-items: flex-end;">
                                <span style="font-size: 0.8rem; color: var(--muted); text-transform: uppercase;">Protein</span>
                                <strong><span id="day-total-pro-<?= $dayNum ?>"><?= $dayPro ?></span>g</strong>
                            </div>
                            <div style="display: flex; flex-direction: column; align-items: flex-end;">
                                <span style="font-size: 0.8rem; color: var(--muted); text-transform: uppercase;">Carbs</span>
                                <strong><span id="day-total-carbs-<?= $dayNum ?>"><?= $dayCarbs ?></span>g</strong>
                            </div>
                            <div style="display: flex; flex-direction: column; align-items: flex-end;">
                                <span style="font-size: 0.8rem; color: var(--muted); text-transform: uppercase;">Fat</span>
                                <strong><span id="day-total-fat-<?= $dayNum ?>"><?= $dayFat ?></span>g</strong>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</div> <!-- Close #view-meal-plan -->

<!-- ==================================================== -->
<!-- MODAL: INGREDIENT & MICRONUTRIENT INSPECTION         -->
<!-- ==================================================== -->
<div id="modal-inspect-meal" class="ft-modal-overlay" style="display:none;" onclick="if(event.target===this)closeInspectModal()">
    <div class="ft-modal-box ft-modal-wide">
        <div class="ft-modal-header">
            <div>
                <h3 class="ft-modal-title" id="inspect-modal-title">Meal Ingredient Breakdown</h3>
                <p class="ft-modal-subtitle" id="inspect-modal-subtitle">Select the ingredients you ate to calculate and estimate exact calories & macros</p>
            </div>
            <button type="button" class="ft-modal-close" onclick="closeInspectModal()" aria-label="Close modal">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>

        <div class="ft-modal-body">
            <!-- Planned Meal Header Bar with Live Recalculated Macros -->
            <div class="inspect-meal-summary">
                <div>
                    <div class="inspect-meal-name" id="inspect-meal-name">Loading meal...</div>
                    <div style="font-size: 11px; color: var(--muted); margin-top: 3px;" id="inspect-meal-status-hint">Calculated based on selected ingredients below</div>
                </div>
                <div class="inspect-macro-chips">
                    <span class="inspect-chip chip-cals" id="inspect-chip-cals">0 kcal</span>
                    <span class="inspect-chip chip-pro" id="inspect-chip-pro">0g P</span>
                    <span class="inspect-chip chip-carbs" id="inspect-chip-carbs">0g C</span>
                    <span class="inspect-chip chip-fat" id="inspect-chip-fat">0g F</span>
                </div>
            </div>

            <!-- Loading spinner -->
            <div id="inspect-loading" style="display:none; padding:35px 20px; text-align:center;">
                <div class="ft-spinner"></div>
                <p style="margin-top:14px; color:var(--muted); font-size:13px;">Analyzing ingredient proportions & micronutrients...</p>
            </div>

            <!-- Content Body -->
            <div id="inspect-content" style="display:none;">
                <div class="inspect-header-controls">
                    <div class="inspect-header-text">
                        <div class="inspect-header-heading">Select Consumed Ingredients</div>
                        <div class="inspect-header-sub">Ticking/unticking ingredients live-estimates your actual calories & macros</div>
                    </div>
                    <div class="inspect-select-actions">
                        <button type="button" class="btn-cancel inspect-bulk-btn" onclick="toggleAllInspectedIngredients(true)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            <span>Select All</span>
                        </button>
                        <button type="button" class="btn-cancel inspect-bulk-btn" onclick="toggleAllInspectedIngredients(false)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                            <span>Deselect All</span>
                        </button>
                    </div>
                </div>
                <div class="inspect-items-wrap" id="inspect-items-container"></div>

                <!-- See More Ingredients Toggle -->
                <div id="inspect-see-more-wrap" style="display:none; margin-top: 6px; margin-bottom: 8px; text-align: center;">
                    <button type="button" id="btn-inspect-toggle-more" class="btn-inspect-see-more" onclick="toggleInspectSeeMore()" aria-expanded="false">
                        <span id="inspect-toggle-more-text">See More Ingredients</span>
                        <svg class="inspect-toggle-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </button>
                </div>
                
                <!-- Micronutrient Highlights Banner -->
                <div id="inspect-micro-banner" class="inspect-micro-banner" style="display:none; margin-top: 14px;"></div>

                <!-- Health Benefits & Notes -->
                <div id="inspect-recipe-notes" style="display:none; margin-top: 14px; padding: 12px 14px; border-radius: 8px; background: rgba(199,255,34,0.04); border-left: 3px solid var(--lime);">
                    <div style="font-size: 11px; font-weight: 700; color: var(--lime); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; display: inline-flex; align-items: center; gap: 5px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <span>Why This Food is Good for You</span>
                    </div>
                    <div id="inspect-recipe-notes-text" style="font-size: 12.5px; color: var(--ink); line-height: 1.45;"></div>
                </div>
            </div>

            <!-- Fallback/Notice -->
            <div id="inspect-fallback" style="display:none; padding:22px; text-align:center; background:var(--bg); border-radius:10px; border:1px dashed var(--line); margin:15px 0;">
                <p style="margin:0 0 6px 0; color:var(--ink); font-weight:700; font-size: 14px;" id="inspect-fallback-title">Planned Meal Targets</p>
                <p style="margin:0; color:var(--muted); font-size:12.5px;" id="inspect-fallback-msg">Detailed ingredient breakdown is being mapped from your assigned nutrition plan.</p>
            </div>
        </div>

        <!-- Modal Actions Footer -->
        <div class="ft-modal-footer inspect-modal-footer">
            <button type="button" class="btn-cancel btn-inspect-footer-close" onclick="closeInspectModal()">Close</button>
            <button type="button" id="btn-inspect-quick-log" class="btn-primary-action btn-inspect-footer-log" onclick="quickLogFromInspection()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                <span>+ Log Selected Ingredients</span>
            </button>
        </div>
    </div>
</div>

<!-- ==================================================== -->
<!-- MODAL: SWAP MEAL                                     -->
<!-- ==================================================== -->
<div id="modal-swap-meal" class="ft-modal-overlay" style="display:none;" onclick="if(event.target===this)closeSwapModal()">
    <div class="ft-modal-box ft-modal-wide">
        <div class="ft-modal-header">
            <div>
                <h3 class="ft-modal-title" id="swap-modal-title">Swap Meal</h3>
                <p class="ft-modal-subtitle" id="swap-modal-subtitle">Choose a healthy alternative tailored to your dietary goals</p>
            </div>
            <button type="button" class="ft-modal-close" onclick="closeSwapModal()" aria-label="Close modal">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>

        <div class="ft-modal-body">
            <!-- Current Meal Reference Banner -->
            <div class="swap-current-ref">
                <span class="swap-current-badge">CURRENT SELECTION</span>
                <div class="swap-current-food" id="swap-current-food">Loading current meal...</div>
                <div class="swap-current-macros" id="swap-current-macros"></div>
            </div>

            <!-- Tab Controls: Curated Options vs Custom Search -->
            <div class="swap-tabs-bar">
                <button type="button" id="tab-swap-curated" class="swap-tab-btn active" onclick="switchSwapTab('curated')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                    <span>Healthy Recipes</span>
                </button>
                <button type="button" id="tab-swap-custom" class="swap-tab-btn" onclick="switchSwapTab('custom')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <span>Custom Meal Search</span>
                </button>
            </div>

            <!-- Curated Recipes Panel -->
            <div id="swap-panel-curated" class="swap-panel">
                <!-- Filter Pills -->
                <div class="swap-filters-row">
                    <button type="button" class="swap-filter-pill active" onclick="filterSwapRecipes('all', this)">All Alternatives</button>
                    <button type="button" class="swap-filter-pill" onclick="filterSwapRecipes('diet', this)" id="pill-filter-diet">Matched to My Diet</button>
                    <button type="button" class="swap-filter-pill" onclick="filterSwapRecipes('high-protein', this)">High Protein</button>
                </div>

                <!-- Loading spinner -->
                <div id="swap-loading" style="display:none; padding:35px 20px; text-align:center;">
                    <div class="ft-spinner"></div>
                    <p style="margin-top:14px; color:var(--muted); font-size:13px;">Finding balanced recipe alternatives...</p>
                </div>

                <!-- Recipe Grid -->
                <div id="swap-recipes-grid" class="swap-recipes-grid"></div>
            </div>

            <!-- Custom Search Panel -->
            <div id="swap-panel-custom" class="swap-panel" style="display:none;">
                <p style="margin:0 0 12px 0; font-size:13px; color:var(--muted); line-height:1.5;">
                    Type your custom meal or ingredients. Our nutrition engine calculates exact calories and macros on the fly:
                </p>
                <div class="swap-custom-input-row">
                    <input type="text" id="swap-custom-input" class="swap-custom-field" placeholder="e.g. 200g grilled salmon, 1 cup quinoa, steamed broccoli" autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault();calculateCustomSwap();}">
                    <button type="button" id="btn-calc-custom-swap" class="btn-calc-custom" onclick="calculateCustomSwap()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                        <span id="btn-calc-custom-text">Calculate</span>
                    </button>
                </div>

                <div id="swap-custom-loading" style="display:none; padding:20px; text-align:center;">
                    <div class="ft-spinner" style="width:20px; height:20px;"></div>
                    <span style="font-size:12px; color:var(--muted); display:inline-block; margin-top:8px;">Calculating nutrition breakdown...</span>
                </div>

                <div id="swap-custom-result" style="display:none; margin-top:14px;" class="swap-custom-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:6px;">
                        <strong style="color:var(--ink); font-size:13.5px;" id="swap-custom-name">Custom Meal</strong>
                        <span id="swap-custom-cals" style="color:var(--lime); font-weight:800; font-size:14px;">0 kcal</span>
                    </div>
                    <div id="swap-custom-macros-row" style="display:flex; gap:10px; font-size:12px; color:var(--muted); margin-bottom:14px; background:var(--bg); padding:8px 12px; border-radius:6px; border:1px solid var(--line);"></div>
                    <button type="button" id="btn-apply-custom-swap" class="btn-primary-action" style="width:100%; justify-content:center;" onclick="applyCustomSwap()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <span>Confirm & Swap to this Meal</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="ft-modal-footer">
            <button type="button" class="btn-cancel" onclick="closeSwapModal()">Cancel</button>
        </div>
    </div>
</div>





<script src="<?= h(asset_url('js/pages/diet.js')) ?>"></script>

<?php
    render_footer();
}
