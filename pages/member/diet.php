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
          AND verification_status = 'verified'
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
            WHERE is_active = 1 
              AND verification_status = 'verified'
              AND gym_id IS NULL
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
    if ($action === 'swap_meal') {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                if (ob_get_level()) ob_clean();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
                exit;
            }

            $mealId = (int) post('meal_id');
            $foodId = (int) post('food_id');
            $foodItems = trim((string) post('food_items'));
            $calories = max(0, (int) round((float) post('calories')));
            $protein = max(0, (int) round((float) post('protein_g')));
            $carbs = max(0, (int) round((float) post('carbs_g')));
            $fat = max(0, (int) round((float) post('fat_g')));

            if ($mealId <= 0) {
                if (ob_get_level()) ob_clean();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => 'Invalid meal identifier.']);
                exit;
            }

            // Check ownership: ensure this meal belongs to an active plan for $userId
            $stmtCheck = $pdo->prepare(
                'SELECT dpm.meal_id, dpm.plan_id, dpm.day_of_week, dpm.meal_type, dpm.image_url 
                 FROM dietary_plan_meals dpm
                 JOIN dietary_plans dp ON dp.plan_id = dpm.plan_id
                 WHERE dpm.meal_id = ? AND dp.member_user_id = ?'
            );
            $stmtCheck->execute([$mealId, $userId]);
            $mealRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$mealRow) {
                if (ob_get_level()) ob_clean();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => 'Meal not found or unauthorized.']);
                exit;
            }

            if ((int) $mealRow['day_of_week'] < (int) date('N')) {
                if (ob_get_level()) ob_clean();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => 'Cannot swap meals for past days.']);
                exit;
            }
            if (empty($foodItems)) {
                if (ob_get_level()) ob_clean();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => 'Meal description cannot be empty.']);
                exit;
            }

            // Guard: Unverified food items cannot be added to a member's plan
            if ($foodId > 0) {
                $checkFood = $pdo->prepare("SELECT verification_status FROM food_items WHERE food_id = ? AND is_active = 1 LIMIT 1");
                $checkFood->execute([$foodId]);
                $fVerif = $checkFood->fetchColumn();
                if ($fVerif && $fVerif !== 'verified') {
                    if (ob_get_level()) ob_clean();
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => 'This food item is unverified and cannot be added to your plan.']);
                    exit;
                }
            } else {
                $checkFood = $pdo->prepare("SELECT verification_status FROM food_items WHERE name = ? AND is_active = 1 LIMIT 1");
                $checkFood->execute([$foodItems]);
                $fVerif = $checkFood->fetchColumn();
                if ($fVerif && $fVerif !== 'verified') {
                    if (ob_get_level()) ob_clean();
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => 'This food item is unverified and cannot be added to your plan.']);
                    exit;
                }
            }

            $stmtUpdate = $pdo->prepare('UPDATE dietary_plan_meals SET food_items = ?, calories = ?, protein_g = ?, carbs_g = ?, fat_g = ? WHERE meal_id = ?');
            $stmtUpdate->execute([$foodItems, $calories, $protein, $carbs, $fat, $mealId]);

            // Recalculate day totals
            $dayNum = (int) $mealRow['day_of_week'];
            $planId = (int) $mealRow['plan_id'];
            $stmtTotals = $pdo->prepare("SELECT SUM(calories) as cals, SUM(protein_g) as pro, SUM(carbs_g) as carbs, SUM(fat_g) as fat FROM dietary_plan_meals WHERE plan_id = ? AND day_of_week = ?");
            $stmtTotals->execute([$planId, $dayNum]);
            $dayTotals = $stmtTotals->fetch(PDO::FETCH_ASSOC);

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
            header('Content-Type: application/json; charset=utf-8');
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
        } catch (Throwable $e) {
            if (ob_get_level()) ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Database error during meal swap: ' . $e->getMessage()
            ]);
            exit;
        }
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
            if (!is_member_self_guided($userId)) {
                flash('Your meal plan is managed and assigned by your coach/trainer. Please contact your coach for diet adjustments.', 'warning');
                redirect('diet');
            }

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
            
            // Resolve gym for current context
            $gymId = get_user_gym_id($user);
            $scopedGymId = ($gymId && $gymId > 0) ? (int)$gymId : null;

            // 5. Create Plan - only complete prior active plan for THIS gym (never overwrite other gyms' plans)
            if ($scopedGymId !== null) {
                $pdo->prepare('UPDATE dietary_plans SET status = "completed" WHERE member_user_id = ? AND gym_id = ? AND status = "active"')->execute([$userId, $scopedGymId]);
            } else {
                $pdo->prepare('UPDATE dietary_plans SET status = "completed" WHERE member_user_id = ? AND (gym_id IS NULL OR gym_id = 0) AND status = "active"')->execute([$userId]);
            }
            
            $hasActivePreferred = (bool) scalar('SELECT 1 FROM dietary_plans WHERE member_user_id = ? AND is_preferred = 1 AND status = "active" LIMIT 1', [$userId]);
            $isPreferred = $hasActivePreferred ? 0 : 1;

            $title = 'Auto-Generated Diet Plan';
            $stmtInsert = $pdo->prepare('INSERT INTO dietary_plans (member_user_id, trainer_id, gym_id, title, goal, status, is_preferred) VALUES (?, NULL, ?, ?, ?, "active", ?)');
            $stmtInsert->execute([$userId, $scopedGymId, $title, $goal, $isPreferred]);
            $planId = (int) $pdo->lastInsertId();

            if (function_exists('log_plan_change')) {
                log_plan_change('diet', $planId, $scopedGymId, $userId, 'created', 'Auto-generated diet plan');
            }
            
            // 6. Generate Meals from Database
            $restriction = (string) ($profile['dietary_restrictions'] ?? 'none');
            if (empty($restriction)) {
                $restriction = 'none';
            }
            $compatibleDiets = function_exists('get_compatible_dietary_restrictions')
                ? get_compatible_dietary_restrictions($restriction)
                : ($restriction !== 'none' ? [$restriction] : []);

            if (!empty($compatibleDiets)) {
                $inClause = implode(',', array_fill(0, count($compatibleDiets), '?'));
                $foodQuery = "
                    SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc 
                    FROM food_items 
                    WHERE is_active = 1 
                      AND verification_status = 'verified'
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
                      AND verification_status = 'verified'
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
                              AND verification_status = 'verified'
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
                                WHERE is_active = 1 
                                  AND verification_status = 'verified'
                                  AND meal_type = ? 
                                ORDER BY RAND()
                            ");
                            $fallbackStmt->execute([$mt]);
                            $foodsByType[$mt] = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
                        } else {
                            $fallbackStmt = $pdo->prepare("
                                SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc 
                                FROM food_items 
                                WHERE is_active = 1 
                                  AND verification_status = 'verified'
                                  AND dietary_restriction IN ('vegetarian', 'vegan') 
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

                    $stmt->execute([$planId, $d, $mType, $mFood, $mImg, (int) round((float) $mCals), (int) round((float) $mP), (int) round((float) $mC), (int) round((float) $mF)]);
                }
            }
            
            flash("Dietary plan generated automatically! Target: {$targetCals}kcal", 'success');
            redirect('diet');
        }

        if (isset($_POST['set_preferred_plan'])) {
            $prefId = (int)$_POST['set_preferred_plan'];
            if ($prefId > 0 && function_exists('set_preferred_dietary_plan')) {
                set_preferred_dietary_plan($userId, $prefId);
                flash('Preferred nutrition plan updated successfully.', 'success');
                redirect('diet&plan_id=' . $prefId);
            }
        }
    }
    
    // Fetch active diet plans (supports multi-gym / multi-trainer)
    $planStmt = $pdo->prepare('
        SELECT dp.*, u.first_name as t_first, u.last_name as t_last, u.role as t_role,
               COALESCE(dp.gym_id, tp.gym_id) as gym_id, g.name as gym_name 
        FROM dietary_plans dp 
        LEFT JOIN trainer_profiles tp ON tp.trainer_id = dp.trainer_id 
        LEFT JOIN users u ON u.user_id = tp.user_id 
        LEFT JOIN gyms g ON g.gym_id = COALESCE(dp.gym_id, tp.gym_id)
        WHERE dp.member_user_id = ? AND dp.status = "active" 
        ORDER BY dp.is_preferred DESC, dp.plan_id DESC
    ');
    $planStmt->execute([$userId]);
    $activePlans = $planStmt->fetchAll();

    // Only include plans with a gym owner or trainer; do not include auto-generated plans
    $coachOrGymPlans = array_values(array_filter($activePlans, function($p) {
        return !empty($p['trainer_id']) || !empty($p['t_first']) || !empty($p['t_last']);
    }));

    if (!empty($coachOrGymPlans)) {
        $activePlans = $coachOrGymPlans;
        // Mark any orphaned auto-generated plans as completed so they do not linger
        try {
            $pdo->prepare('UPDATE dietary_plans SET status = "completed" WHERE member_user_id = ? AND trainer_id IS NULL AND status = "active"')
                ->execute([$userId]);
        } catch (Throwable $e) {}
    }

    $selectedPlanId = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
    $currentGym = get_user_gym($user);
    $currentGymId = $currentGym ? (int)($currentGym['gym_id'] ?? 0) : 0;

    $activePlan = null;
    if ($selectedPlanId > 0) {
        foreach ($activePlans as $ap) {
            if ((int)$ap['plan_id'] === $selectedPlanId) {
                $activePlan = $ap;
                break;
            }
        }
    }
    // If not explicitly selected, prioritize marked preferred plan
    if (!$activePlan) {
        foreach ($activePlans as $ap) {
            if (!empty($ap['is_preferred'])) {
                $activePlan = $ap;
                break;
            }
        }
    }
    // Then prioritize current gym's plan
    if (!$activePlan && $currentGymId > 0) {
        foreach ($activePlans as $ap) {
            if ((int)($ap['gym_id'] ?? 0) === $currentGymId) {
                $activePlan = $ap;
                break;
            }
        }
    }
    // Fallback to first active plan
    if (!$activePlan && !empty($activePlans)) {
        $activePlan = $activePlans[0];
    }

    // Auto-generate their tailored dietary plan if they don't have one active yet
    if (!$activePlan) {
        $autoPlanId = generate_dietary_plan($userId, null, false, $currentGymId > 0 ? $currentGymId : null);
        if ($autoPlanId) {
            $planStmt->execute([$userId]);
            $activePlans = $planStmt->fetchAll();
            $activePlan = $activePlans[0] ?? null;
        }
    }

    $memberProfile = $pdo->query('SELECT dietary_restrictions, primary_goal, secondary_focus FROM member_profiles WHERE user_id = ' . $userId)->fetch();
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

    $hasCoach = !empty($activePlan['trainer_id']);
    $coachFullName = trim(($activePlan['t_first'] ?? '') . ' ' . ($activePlan['t_last'] ?? ''));
    $isGymOwner = (($activePlan['t_role'] ?? '') === 'gym_owner');
    $coachDisplayName = $coachFullName ?: ($isGymOwner ? 'Gym Owner' : 'Coach');
    $trainerName = $coachDisplayName;
    
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

// Fetch past meal logs for this member
$stmtPastMealLogs = $pdo->prepare(
    "SELECT mml.*, dpm.image_url, dpm.meal_type as plan_meal_type
     FROM member_meal_logs mml
     LEFT JOIN dietary_plan_meals dpm ON dpm.meal_id = mml.meal_id
     WHERE mml.user_id = ?
     ORDER BY mml.log_date DESC, mml.logged_at DESC"
);
$stmtPastMealLogs->execute([$userId]);
$pastMealLogsRaw = $stmtPastMealLogs->fetchAll();

// Fetch daily macro records for this member (to get accurate daily aggregates)
$stmtDailyMacrosHistory = $pdo->prepare(
    "SELECT * FROM daily_macros WHERE user_id = ? ORDER BY log_date DESC"
);
$stmtDailyMacrosHistory->execute([$userId]);
$dailyMacrosHistory = $stmtDailyMacrosHistory->fetchAll();

$dailyMacrosHistoryMap = [];
foreach ($dailyMacrosHistory as $dmh) {
    $dailyMacrosHistoryMap[$dmh['log_date']] = $dmh;
}

// Group meal logs by date
$pastLogsByDate = [];
foreach ($pastMealLogsRaw as $log) {
    $d = $log['log_date'];
    if (!isset($pastLogsByDate[$d])) {
        $pastLogsByDate[$d] = [
            'date' => $d,
            'meals' => [],
            'cals' => 0,
            'pro' => 0.0,
            'carbs' => 0.0,
            'fat' => 0.0
        ];
    }
    $pastLogsByDate[$d]['meals'][] = $log;
    $pastLogsByDate[$d]['cals'] += (int) $log['calories'];
    $pastLogsByDate[$d]['pro'] += (float) $log['protein_g'];
    $pastLogsByDate[$d]['carbs'] += (float) $log['carbs_g'];
    $pastLogsByDate[$d]['fat'] += (float) $log['fat_g'];
}

// Ensure any daily_macros entry with calories > 0 is included even if itemized slots were not recorded
foreach ($dailyMacrosHistoryMap as $d => $dm) {
    if ((int)$dm['calories'] > 0) {
        if (!isset($pastLogsByDate[$d])) {
            $pastLogsByDate[$d] = [
                'date' => $d,
                'meals' => [],
                'cals' => (int) $dm['calories'],
                'pro' => (float) $dm['protein_g'],
                'carbs' => (float) $dm['carbs_g'],
                'fat' => (float) $dm['fat_g']
            ];
        } else {
            if ((int)$dm['calories'] > $pastLogsByDate[$d]['cals']) {
                $pastLogsByDate[$d]['cals'] = (int)$dm['calories'];
                $pastLogsByDate[$d]['pro'] = max($pastLogsByDate[$d]['pro'], (float)$dm['protein_g']);
                $pastLogsByDate[$d]['carbs'] = max($pastLogsByDate[$d]['carbs'], (float)$dm['carbs_g']);
                $pastLogsByDate[$d]['fat'] = max($pastLogsByDate[$d]['fat'], (float)$dm['fat_g']);
            }
        }
    }
}

krsort($pastLogsByDate);

$totalLoggedDaysCount = count($pastLogsByDate);
$totalMealsLoggedCount = count($pastMealLogsRaw);
$avgDailyCals = $totalLoggedDaysCount > 0 ? (int) round(array_sum(array_column($pastLogsByDate, 'cals')) / $totalLoggedDaysCount) : 0;

$isDietSelfGuided = is_member_self_guided($userId) && empty($activePlan['trainer_id']);

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
<script>
window.DIET_CONFIG = {
    csrfToken: <?= json_encode(csrf_token()) ?>,
    loggedMealsToday: <?= json_encode($loggedMealsTodayMap ?? []) ?>,
    currentDayNum: <?= (int)($todayNum ?? 1) ?>
};
window.FT_LOGGED_MEALS_TODAY = window.DIET_CONFIG.loggedMealsToday;
window.FT_CURRENT_DAY_NUM = window.DIET_CONFIG.currentDayNum;
</script>

<!-- Regenerate Plan Form (Hidden, triggered via JS modal) -->
<?php if ($isDietSelfGuided): ?>
    <form id="regenerate-plan-form" class="diet-action-form" method="post" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="generate_plan">
    </form>
<?php endif; ?>

<!-- ==================================================== -->
<!-- UNIFIED BORDERLESS TOP BAR: PLANS + META + TABS      -->
<!-- ==================================================== -->
<div class="diet-bento-topbar">
    <div class="diet-topbar-left">
        <!-- Active Plan Dropdown / Popover -->
        <?php if (count($activePlans) > 1): ?>
            <div class="diet-plan-popover-wrap" id="diet-plan-popover-wrap">
                <button type="button" 
                        class="diet-plan-popover-btn" 
                        id="diet-plan-popover-trigger" 
                        aria-expanded="false" 
                        aria-haspopup="true"
                        onclick="toggleDietPlanPopover(event)">
                    <span class="diet-plan-pill-icon">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2a10 10 0 1 0 10 10H12V2z"/><path d="M12 12L2.1 12.5"/><path d="M12 12l4.5 8"/></svg>
                    </span>
                    <span class="diet-plan-btn-content">
                        <span class="diet-plan-btn-prefix">Active Plan:</span>
                        <strong class="diet-plan-btn-name"><?= h(!empty($activePlan['gym_name']) ? $activePlan['gym_name'] : 'Nutrition Plan') ?></strong>
                    </span>
                    <svg class="diet-plan-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </button>

                <!-- Floating Popover Menu (Dark-Themed & High Contrast) -->
                <div class="diet-plan-dropdown-menu" id="diet-plan-dropdown-menu" role="menu">
                    <div class="diet-dropdown-header">
                        <span>Switch Nutrition Plan</span>
                        <span class="diet-dropdown-count"><?= count($activePlans) ?> Active</span>
                    </div>
                    <div class="diet-dropdown-items">
                        <?php foreach ($activePlans as $ap): 
                            $isSelected = ((int)$ap['plan_id'] === (int)($activePlan['plan_id'] ?? 0));
                            $gymTitle = !empty($ap['gym_name']) ? $ap['gym_name'] : 'Nutrition Plan';
                            $coachSub = (!empty($ap['t_first']) || !empty($ap['t_last'])) ? ('Coach ' . trim($ap['t_first'] . ' ' . $ap['t_last'])) : 'Auto-generated';
                        ?>
                            <a href="index.php?page=diet&plan_id=<?= (int)$ap['plan_id'] ?>" 
                               class="diet-dropdown-item <?= $isSelected ? 'is-active-item' : '' ?>"
                               role="menuitem">
                                <div class="diet-dropdown-item-icon">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2a10 10 0 1 0 10 10H12V2z"/><path d="M12 12L2.1 12.5"/><path d="M12 12l4.5 8"/></svg>
                                </div>
                                <div class="diet-dropdown-item-info">
                                    <div class="diet-item-title"><?= h($gymTitle) ?></div>
                                    <div class="diet-item-sub"><?= h($coachSub) ?></div>
                                </div>
                                <?php if ($isSelected): ?>
                                    <span class="diet-item-check" title="Currently viewing">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                    </span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="diet-plan-single-pill">
                <span class="diet-plan-pill-icon">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2a10 10 0 1 0 10 10H12V2z"/><path d="M12 12L2.1 12.5"/><path d="M12 12l4.5 8"/></svg>
                </span>
                <span class="diet-plan-btn-content">
                    <span class="diet-plan-btn-prefix">Active Plan:</span>
                    <strong class="diet-plan-btn-name"><?= h(!empty($activePlan['gym_name']) ? $activePlan['gym_name'] : 'Nutrition Plan') ?></strong>
                </span>
            </div>
        <?php endif; ?>

        <!-- Inline Metadata Pills -->
        <div class="diet-topbar-meta">
            <span class="diet-meta-chip diet-chip-desktop" title="Primary Fitness Goal">
                <span class="diet-chip-dot dot-lime"></span>
                <span><?= h(ucwords(str_replace('_', ' ', $activePlan['goal']))) ?></span>
            </span>

            <?php if (!empty($memberProfile['secondary_focus'])): ?>
            <span class="diet-meta-chip diet-chip-desktop" title="Secondary Focus">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <span><?= h(ucwords(str_replace('_', ' ', $memberProfile['secondary_focus']))) ?></span>
            </span>
            <?php endif; ?>

            <span class="diet-meta-chip diet-chip-desktop <?= $userDietaryRestriction !== 'none' ? 'is-diet-highlight' : '' ?>" title="Dietary Protocol">
                <span style="font-size: 12px;">🥗</span>
                <span><?= h(ucwords(str_replace('-', ' ', $userDietaryRestriction === 'none' ? 'Standard' : $userDietaryRestriction))) ?></span>
            </span>

            <?php if ($hasCoach): ?>
                <span class="diet-meta-chip diet-chip-desktop is-locked" title="Plan managed by your coach. Contact <?= h($coachDisplayName) ?> to make adjustments.">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <span>Coach <?= h($coachDisplayName) ?></span>
                    <?php if ($isGymOwner): ?>
                        <span class="diet-chip-role-tag">Owner</span>
                    <?php endif; ?>
                </span>
            <?php else: ?>
                <span class="diet-meta-chip diet-chip-desktop" title="Nutritional Guidance: Self-Guided">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                    <span>Self-Guided</span>
                </span>
                <?php if ($isDietSelfGuided): ?>
                    <button type="button" onclick="confirmRegeneratePlan()" class="diet-meta-btn" title="Regenerate Plan">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.92-10.26l5.67-5.67"/></svg>
                        <span>Regenerate Plan</span>
                    </button>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Mobile Info Trigger Button (Sits on same baseline on mobile) -->
            <button type="button" 
                    class="diet-meta-info-btn" 
                    id="btn-diet-plan-info" 
                    onclick="openDietPlanInfoModal()" 
                    title="View plan goal, diet protocol &amp; coach guidance" 
                    aria-label="View plan details">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="16" x2="12" y2="12"></line>
                    <line x1="12" y1="8" x2="12.01" y2="8"></line>
                </svg>
                <span>Info</span>
            </button>
        </div>
    </div>

    <!-- Segmented Tracker / Weekly Plan Navigation Tabs -->
    <div class="diet-topbar-right">
        <div class="diet-segmented-nav">
            <button type="button" class="diet-segmented-btn active" id="btn-view-tracker" onclick="switchDietView('tracker')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3">
                    <line x1="18" y1="20" x2="18" y2="10"></line>
                    <line x1="12" y1="20" x2="12" y2="4"></line>
                    <line x1="6" y1="20" x2="6" y2="14"></line>
                </svg>
                <span class="nav-btn-lbl nav-lbl-full">Today's Tracker</span>
                <span class="nav-btn-lbl nav-lbl-short">Tracker</span>
                <span class="diet-nav-badge tracker-badge"><?= $loggedCals ?> / <?= $targetCals ?> kcal</span>
            </button>
            <button type="button" class="diet-segmented-btn" id="btn-view-plan" onclick="switchDietView('plan')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
                <span class="nav-btn-lbl nav-lbl-full">Weekly Plan</span>
                <span class="nav-btn-lbl nav-lbl-short">Weekly</span>
                <span class="diet-nav-badge plan-badge">7-Day</span>
            </button>
            <button type="button" class="diet-segmented-btn" id="btn-view-history" onclick="switchDietView('history')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
                <span class="nav-btn-lbl nav-lbl-full">Past Logs</span>
                <span class="nav-btn-lbl nav-lbl-short">Logs</span>
                <span class="diet-nav-badge history-badge"><?= $totalLoggedDaysCount ?> Days</span>
            </button>
        </div>
    </div>
</div>

<!-- ==================================================== -->
<!-- VIEW 1: TODAY'S MACRO TRACKER (BENTO GRID)           -->
<!-- ==================================================== -->
<div id="view-macro-tracker" class="diet-view-panel">
    <div class="diet-bento-grid animate-fade-in">

        <!-- ================================================ -->
        <!-- BENTO TILE 1: DAILY STATUS (~40% Left Tile)      -->
        <!-- ================================================ -->
        <div class="diet-bento-tile tile-daily-status">
            <div class="tile-header">
                <div>
                    <h3 class="tile-title">Daily Status</h3>
                    <span class="tile-subtitle">Calories &amp; Macronutrients</span>
                </div>
                <div class="tile-header-actions">
                    <button type="button" class="tile-mini-btn" onclick="switchDietView('history')" title="View past meal logs and macro history">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span>History</span>
                    </button>
                    <button type="button" id="btn-auto-calc-cals" class="tile-mini-btn" title="Auto-calc calories: 4P + 4C + 9F">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                        <span>Auto-calc</span>
                    </button>
                    <button type="button" id="btn-reset-macros" class="tile-mini-btn btn-danger" title="Reset Today's Logged Macros">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        <span>Reset</span>
                    </button>
                </div>
            </div>

            <!-- Hero Calorie Metric Card -->
            <div class="hero-calories-card">
                <?php $remainingCals = $targetCals - $loggedCals; ?>
                <div class="hero-cals-top">
                    <div class="hero-cals-lead">
                        <span class="macro-stat-dot dot-cals"></span>
                        <span class="hero-cals-label">Daily Calories</span>
                    </div>
                    <span id="macro-pct-cals" class="hero-pct-badge" style="color: <?= $stCals['color'] ?>;">
                        <?= $stCals['text'] ?>
                    </span>
                </div>

                <div class="hero-cals-display-wrap">
                    <div class="hero-cals-display">
                        <div class="hero-cals-num-row">
                            <span id="hero-cals-remaining-val" class="hero-cals-big-num"><?= abs($remainingCals) ?></span>
                            <span id="hero-cals-remaining-lbl" class="hero-cals-unit-text"><?= $remainingCals >= 0 ? 'kcal remaining' : 'kcal over target' ?></span>
                        </div>
                        <div class="hero-cals-sub-row">
                            <span id="macro-logged-cals" class="hero-val-logged"><?= $loggedCals ?></span>
                            <span class="hero-val-target">/ <span id="macro-target-cals"><?= $targetCals ?></span> kcal target</span>
                        </div>
                    </div>

                    <?php 
                        $ringRawPct = min(100, max(0, (float)$stCals['pct']));
                        $ringOffset = round(175.93 * (1 - ($ringRawPct / 100)), 2);
                    ?>
                    <div class="hero-ring-wrap" title="<?= $stCals['text'] ?> of daily target">
                        <svg class="hero-radial-ring" width="70" height="70" viewBox="0 0 70 70">
                            <circle class="radial-ring-track" cx="35" cy="35" r="28" stroke-width="6" fill="none" />
                            <circle id="hero-radial-ring-bar" class="radial-ring-fill" cx="35" cy="35" r="28" stroke-width="6" fill="none" 
                                    stroke-linecap="round"
                                    style="stroke-dasharray: 175.93; stroke-dashoffset: <?= $ringOffset ?>; stroke: <?= $stCals['color'] ?>;" />
                        </svg>
                        <div class="hero-ring-inner">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--macro-cals);">
                                <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>
                            </svg>
                        </div>
                    </div>
                </div>

                <div class="hero-cals-progress-track">
                    <div id="macro-progress-bar-cals" style="height: 100%; width: <?= $stCals['pct'] ?>%; background: <?= $stCals['barBg'] ?>; transition: width 0.7s ease, background 0.3s ease;"></div>
                </div>
            </div>

            <!-- Stacked Macronutrient Rows (Protein, Carbs, Fat) -->
            <div class="bento-macros-stack">
                <!-- Protein Row -->
                <div class="bento-macro-row">
                    <div class="bento-macro-head">
                        <div class="bento-macro-label">
                            <span class="macro-stat-dot dot-pro"></span>
                            <span class="bento-macro-name">Protein</span>
                        </div>
                        <div class="bento-macro-stats">
                            <span class="bento-macro-nums">
                                <strong id="macro-logged-pro"><?= $loggedPro ?></strong>
                                <span class="bento-macro-sep">/</span>
                                <span id="macro-target-pro"><?= $targetPro ?></span>g
                            </span>
                            <span id="macro-pct-pro" class="bento-macro-pct" style="color: <?= $stPro['color'] ?>;"><?= $stPro['text'] ?></span>
                        </div>
                    </div>
                    <div class="bento-macro-track">
                        <div id="macro-progress-bar-pro" style="height: 100%; width: <?= $stPro['pct'] ?>%; background: <?= $stPro['barBg'] ?>; transition: width 0.7s ease, background 0.3s ease;"></div>
                    </div>
                </div>

                <!-- Carbs Row -->
                <div class="bento-macro-row">
                    <div class="bento-macro-head">
                        <div class="bento-macro-label">
                            <span class="macro-stat-dot dot-carbs"></span>
                            <span class="bento-macro-name">Carbohydrates</span>
                        </div>
                        <div class="bento-macro-stats">
                            <span class="bento-macro-nums">
                                <strong id="macro-logged-carbs"><?= $loggedCarbs ?></strong>
                                <span class="bento-macro-sep">/</span>
                                <span id="macro-target-carbs"><?= $targetCarbs ?></span>g
                            </span>
                            <span id="macro-pct-carbs" class="bento-macro-pct" style="color: <?= $stCarbs['color'] ?>;"><?= $stCarbs['text'] ?></span>
                        </div>
                    </div>
                    <div class="bento-macro-track">
                        <div id="macro-progress-bar-carbs" style="height: 100%; width: <?= $stCarbs['pct'] ?>%; background: <?= $stCarbs['barBg'] ?>; transition: width 0.7s ease, background 0.3s ease;"></div>
                    </div>
                </div>

                <!-- Fat Row -->
                <div class="bento-macro-row">
                    <div class="bento-macro-head">
                        <div class="bento-macro-label">
                            <span class="macro-stat-dot dot-fat"></span>
                            <span class="bento-macro-name">Dietary Fats</span>
                        </div>
                        <div class="bento-macro-stats">
                            <span class="bento-macro-nums">
                                <strong id="macro-logged-fat"><?= $loggedFat ?></strong>
                                <span class="bento-macro-sep">/</span>
                                <span id="macro-target-fat"><?= $targetFat ?></span>g
                            </span>
                            <span id="macro-pct-fat" class="bento-macro-pct" style="color: <?= $stFat['color'] ?>;"><?= $stFat['text'] ?></span>
                        </div>
                    </div>
                    <div class="bento-macro-track">
                        <div id="macro-progress-bar-fat" style="height: 100%; width: <?= $stFat['pct'] ?>%; background: <?= $stFat['barBg'] ?>; transition: width 0.7s ease, background 0.3s ease;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile Trigger Bar for Live Macro Logger (Mobile only, hidden on desktop) -->
        <div class="mobile-logger-trigger-wrap">
            <div class="mobile-logger-trigger-bar" id="mobile-logger-trigger-bar">
                <button type="button" 
                        id="btn-mobile-toggle-logger" 
                        class="mobile-logger-trigger-main-btn" 
                        onclick="toggleMobileMealLogger()" 
                        aria-expanded="false" 
                        aria-controls="bento-tile-logger">
                    <div class="mobile-logger-trigger-left">
                        <div class="mobile-logger-icon-glow" id="mobile-logger-icon-glow">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                            </svg>
                        </div>
                        <div class="mobile-logger-title-wrap">
                            <span class="mobile-logger-main-title">Live Macro Logger</span>
                            <span class="mobile-logger-sub-title" id="mobile-logger-sub-title">AI Assistant &amp; Natural Language</span>
                        </div>
                    </div>
                    <span id="mobile-logger-btn-label" class="mobile-logger-badge-btn">+ Log Food</span>
                </button>

                <button type="button" 
                        id="btn-mobile-logger-dropdown" 
                        class="mobile-logger-dropdown-trigger" 
                        onclick="toggleMobileLoggerDropdown(event)" 
                        title="Choose Logging Method"
                        aria-label="Choose Logging Method">
                    <svg class="mobile-logger-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </button>
            </div>

            <!-- Dropdown Menu for Mobile + Log Food -->
            <div id="mobile-logger-dropdown-menu" class="mobile-logger-dropdown-menu" style="display: none;">
                <button type="button" class="mobile-logger-menu-item" onclick="openLoggerWithMode('ai')">
                    <div class="menu-item-icon-ai">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                    </div>
                    <div class="menu-item-text">
                        <strong>AI Quick Log</strong>
                        <span>Type what you ate in natural language</span>
                    </div>
                </button>
                <button type="button" class="mobile-logger-menu-item" onclick="openLoggerWithMode('manual')">
                    <div class="menu-item-icon-manual">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    </div>
                    <div class="menu-item-text">
                        <strong>Custom / Manual Log</strong>
                        <span>Enter food name, calories &amp; macros directly</span>
                    </div>
                </button>
            </div>
        </div>

        <!-- ================================================ -->
        <!-- BENTO TILE 2: LIVE MACRO LOGGER (Right Tile)     -->
        <!-- ================================================ -->
        <div class="diet-bento-tile tile-meal-logger" id="bento-tile-logger">
            <div class="tile-header">
                <div class="tile-title-group">
                    <h3 class="tile-title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                        </svg>
                        <span>Live Macro Logger</span>
                    </h3>
                    <span class="smart-api-badge" id="logger-badge-mode">
                        <svg id="logger-badge-mode-icon" width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        <span id="logger-badge-mode-text">AI Assistant</span>
                    </span>
                </div>

                <div class="tile-header-controls">
                    <!-- Macro Log Mode (Add vs Set) -->
                    <div class="macro-mode-tabs" title="Choose whether to add to today's totals or directly set them">
                        <button type="button" id="tab-mode-add" class="macro-tab-btn active" onclick="setMacroLogMode('add')">+ Add</button>
                        <button type="button" id="tab-mode-set" class="macro-tab-btn" onclick="setMacroLogMode('set')">Direct</button>
                    </div>
                </div>
            </div>

            <!-- Mode Switch Tabs: AI Assistant vs Manual Entry -->
            <div class="logger-nav-tabs" role="tablist" aria-label="Logging Mode">
                <button type="button" id="tab-logger-ai" class="logger-tab-pill active" onclick="switchLoggerTab('ai')" role="tab" aria-selected="true">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                    </svg>
                    <span>AI Assistant</span>
                </button>
                <button type="button" id="tab-logger-manual" class="logger-tab-pill" onclick="switchLoggerTab('manual')" role="tab" aria-selected="false">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                    </svg>
                    <span>Manual Entry</span>
                </button>
            </div>

            <!-- Assistant Body -->
            <div class="meal-logger-body">
                <!-- PANEL A: AI ASSISTANT -->
                <div id="panel-logger-ai" class="logger-tab-panel active">
                    <div class="smart-meal-input-wrap">
                        <input type="text" id="smart-ninja-input" class="smart-meal-textarea" placeholder="Type what you ate (e.g., 2 eggs, 1 slice wheat toast, 1 glass milk)..." autocomplete="off">
                        <div class="smart-meal-btn-group">
                            <button type="button" id="btn-clear-smart-ninja" class="smart-meal-clear-btn" onclick="clearSmartMealAssistant()" title="Clear">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                <span>Clear</span>
                            </button>
                            <!-- Photo scan button (hidden for now) -->
                            <button type="button" id="btn-scan-meal-photo" class="smart-meal-scan-btn" onclick="triggerMealPhotoScan()" title="Scan Nutrition Label, Menu, or Recipe" style="display:none;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                <span id="btn-scan-meal-photo-txt">Scan Label / Menu</span>
                            </button>
                            <input type="file" id="meal-photo-file-input" accept="image/*" style="display:none;" onchange="handleMealPhotoSelected(event)">
                            <button type="button" id="btn-analyze-ninja" class="smart-meal-action-btn">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                <span id="btn-analyze-ninja-txt">Analyze &amp; Fill</span>
                            </button>
                        </div>
                    </div>

                    <!-- Guidance tip for photo scanning (hidden for now) -->
                    <div class="smart-photo-guide-chip" style="display:none;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        <span><strong>Photo Scan:</strong> Reads <strong>printed text</strong> (nutrition labels, menus, receipts, or recipe cards). <em>Does not analyze actual food on a plate.</em></span>
                    </div>

                    <!-- Quick Suggestion Pills -->
                    <div class="smart-quick-pills">
                        <span class="quick-pill-label">Quick:</span>
                        <button type="button" class="smart-pill" onclick="setQuickMealText('2 boiled eggs and 1 slice whole wheat bread')">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/></svg>
                            <span>2 Eggs &amp; Toast</span>
                        </button>
                        <button type="button" class="smart-pill" onclick="setQuickMealText('150g grilled chicken breast and 1 cup white rice')">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2a5 5 0 0 1 5 5c0 2.5-1.5 4.5-3.5 5.5l1.5 6.5-3-1-3 1 1.5-6.5C8.5 11.5 7 9.5 7 7a5 5 0 0 1 5-5z"/></svg>
                            <span>150g Chicken &amp; Rice</span>
                        </button>
                        <button type="button" class="smart-pill" onclick="setQuickMealText('1 scoop whey protein and 1 cup milk')">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 2h12v3l-2 15a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2L6 5V2z"/><line x1="6" y1="7" x2="18" y2="7"/></svg>
                            <span>Whey &amp; Milk</span>
                        </button>
                        <button type="button" class="smart-pill" onclick="setQuickMealText('1 can tuna and 1 medium banana')">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 4.24 4.24"/><path d="m14.83 9.17 4.24-4.24"/><path d="m14.83 14.83 4.24 4.24"/><path d="m9.17 14.83-4.24 4.24"/></svg>
                            <span>Tuna &amp; Banana</span>
                        </button>
                    </div>

                    <!-- CalorieNinjas Breakdown Container -->
                    <div id="ninja-breakdown-wrap" style="display: none;" class="smart-breakdown-card">
                        <div class="smart-breakdown-header">
                            <span class="smart-breakdown-title">Meal Breakdown</span>
                            <span class="smart-breakdown-badge">✓ Applied to values below</span>
                        </div>
                        <div id="ninja-breakdown-items" class="smart-breakdown-list"></div>
                        <div class="smart-breakdown-totals-bar">
                            <div id="ninja-breakdown-totals" class="smart-totals-grid"></div>
                        </div>
                    </div>
                </div>

                <!-- PANEL B: MANUAL ENTRY -->
                <div id="panel-logger-manual" class="logger-tab-panel" style="display: none;">
                    <div class="manual-log-wrap">
                        <div class="manual-food-row">
                            <div class="manual-food-input-group">
                                <div class="manual-input-icon">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"></path><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path><line x1="6" y1="1" x2="6" y2="4"></line><line x1="10" y1="1" x2="10" y2="4"></line><line x1="14" y1="1" x2="14" y2="4"></line></svg>
                                </div>
                                <input type="text" 
                                       id="manual-food-name" 
                                       class="manual-food-input" 
                                       placeholder="Food / Meal Name (e.g. 1 Chicken Shawarma, Whey Shake...)" 
                                       autocomplete="off">
                                <button type="button" 
                                        id="btn-clear-manual-food" 
                                        class="manual-field-clear-btn" 
                                        onclick="clearManualLogInputs()" 
                                        title="Clear Inputs">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                    <span>Clear</span>
                                </button>
                            </div>
                        </div>

                        <!-- 4-Column Manual Macro Grid -->
                        <div class="manual-macros-grid">
                            <div class="manual-macro-card card-cals">
                                <div class="manual-card-header">
                                    <div class="manual-card-tag">
                                        <span class="macro-stat-dot dot-cals"></span>
                                        <span>Calories</span>
                                    </div>
                                    <span class="manual-card-unit">kcal</span>
                                </div>
                                <div class="manual-card-body">
                                    <input type="number" 
                                           id="manual-macro-cals" 
                                           class="manual-num-input" 
                                           min="0" 
                                           step="1" 
                                           placeholder="0" 
                                           inputmode="numeric">
                                </div>
                            </div>

                            <div class="manual-macro-card card-pro">
                                <div class="manual-card-header">
                                    <div class="manual-card-tag">
                                        <span class="macro-stat-dot dot-pro"></span>
                                        <span>Protein</span>
                                    </div>
                                    <span class="manual-card-unit">g</span>
                                </div>
                                <div class="manual-card-body">
                                    <input type="number" 
                                           id="manual-macro-pro" 
                                           class="manual-num-input" 
                                           min="0" 
                                           step="0.1" 
                                           placeholder="0" 
                                           inputmode="decimal">
                                </div>
                            </div>

                            <div class="manual-macro-card card-carbs">
                                <div class="manual-card-header">
                                    <div class="manual-card-tag">
                                        <span class="macro-stat-dot dot-carbs"></span>
                                        <span>Carbs</span>
                                    </div>
                                    <span class="manual-card-unit">g</span>
                                </div>
                                <div class="manual-card-body">
                                    <input type="number" 
                                           id="manual-macro-carbs" 
                                           class="manual-num-input" 
                                           min="0" 
                                           step="0.1" 
                                           placeholder="0" 
                                           inputmode="decimal">
                                </div>
                            </div>

                            <div class="manual-macro-card card-fat">
                                <div class="manual-card-header">
                                    <div class="manual-card-tag">
                                        <span class="macro-stat-dot dot-fat"></span>
                                        <span>Fat</span>
                                    </div>
                                    <span class="manual-card-unit">g</span>
                                </div>
                                <div class="manual-card-body">
                                    <input type="number" 
                                           id="manual-macro-fat" 
                                           class="manual-num-input" 
                                           min="0" 
                                           step="0.1" 
                                           placeholder="0" 
                                           inputmode="decimal">
                                </div>
                            </div>
                        </div>

                        <!-- Manual Helpers Row -->
                        <div class="manual-helpers-row">
                            <div class="manual-pills-wrap">
                                <span class="manual-helper-label">Quick Add:</span>
                                <button type="button" class="manual-quick-pill" onclick="addManualMacroIncrement('cals', 100)">+100 kcal</button>
                                <button type="button" class="manual-quick-pill" onclick="addManualMacroIncrement('cals', 250)">+250 kcal</button>
                                <button type="button" class="manual-quick-pill" onclick="addManualMacroIncrement('pro', 20)">+20g Pro</button>
                                <button type="button" class="manual-quick-pill" onclick="addManualMacroIncrement('carbs', 30)">+30g Carb</button>
                                <button type="button" class="manual-quick-pill" onclick="addManualMacroIncrement('fat', 10)">+10g Fat</button>
                            </div>
                            <button type="button" id="btn-manual-autocalc" class="manual-autocalc-btn" onclick="calcManualCaloriesFromPcf()" title="Calculate calories from (4P + 4C + 9F)">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                                <span>Auto-calc Cals</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Compact Live Macro Preview Bar (Inline Inputs + Add Button) -->
            <form id="macro-log-form" action="index.php?page=log_macros" method="post" class="bento-macro-submit-bar">
                <?= csrf_field() ?>
                <input type="hidden" id="macro-input-mode" name="mode" value="add">
                <input type="hidden" id="macro-input-food-items" name="food_items" value="">
                
                <div class="bento-live-header-row">
                    <span class="bento-preview-label">Live Macros:</span>
                </div>

                <div class="bento-live-controls-row">
                    <div class="bento-live-preview-group">
                        <div class="bento-inline-field field-cals" title="Calories">
                            <span class="macro-stat-dot dot-cals"></span>
                            <span id="lbl-text-cals" class="bento-field-tag">Cal</span>
                            <input id="macro-input-cals" class="bento-field-input" type="number" min="0" name="calories" value="" required placeholder="+0" inputmode="numeric">
                        </div>

                        <div class="bento-inline-field field-pro" title="Protein (g)">
                            <span class="macro-stat-dot dot-pro"></span>
                            <span id="lbl-text-pro" class="bento-field-tag">Pro</span>
                            <input id="macro-input-pro" class="bento-field-input" type="number" min="0" name="protein_g" value="" placeholder="+0" inputmode="decimal" step="0.1">
                            <span class="bento-field-unit">g</span>
                        </div>

                        <div class="bento-inline-field field-carbs" title="Carbs (g)">
                            <span class="macro-stat-dot dot-carbs"></span>
                            <span id="lbl-text-carbs" class="bento-field-tag">Carb</span>
                            <input id="macro-input-carbs" class="bento-field-input" type="number" min="0" name="carbs_g" value="" placeholder="+0" inputmode="decimal" step="0.1">
                            <span class="bento-field-unit">g</span>
                        </div>

                        <div class="bento-inline-field field-fat" title="Fat (g)">
                            <span class="macro-stat-dot dot-fat"></span>
                            <span id="lbl-text-fat" class="bento-field-tag">Fat</span>
                            <input id="macro-input-fat" class="bento-field-input" type="number" min="0" name="fat_g" value="" placeholder="+0" inputmode="decimal" step="0.1">
                            <span class="bento-field-unit">g</span>
                        </div>
                    </div>

                    <button id="macro-save-btn" type="submit" class="bento-macro-save-btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <span id="btn-save-text">+ Add to Log</span>
                    </button>
                </div>
            </form>
        </div>

    </div>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"></script>
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
                                        <button type="button" class="meal-action-btn btn-swap-meal" <?= ($dayNum < $todayNum) ? 'disabled style="opacity: 0.45; cursor: not-allowed; color: var(--muted);" title="Cannot swap meals on past days (' . h($dayName) . ')"' : 'onclick="openSwapMealModal(' . (int) $meal['meal_id'] . ')" title="Swap with alternative healthy recipes"' ?>>
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
<!-- VIEW 3: PAST MEAL LOGS HISTORY                       -->
<!-- ==================================================== -->
<div id="view-meal-history" class="diet-view-panel" style="display: none;">
    <div class="diet-history-container">
        <!-- Top Banner / Summary KPI Cards -->
        <div class="diet-history-hero">
            <div class="diet-history-hero-text">
                <div class="diet-history-title-wrap">
                    <div class="diet-history-icon-badge">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                    </div>
                    <div>
                        <h2 class="diet-history-title">Past Meal Logs &amp; Nutrition History</h2>
                        <p class="diet-history-subtitle">Review your logged meals, daily macro breakdown, and nutritional consistency over time.</p>
                    </div>
                </div>
            </div>

            <!-- KPI Metric Cards -->
            <div class="diet-history-kpis">
                <div class="history-kpi-card">
                    <span class="history-kpi-label">Days Tracked</span>
                    <strong class="history-kpi-val"><?= $totalLoggedDaysCount ?></strong>
                    <span class="history-kpi-meta">Active logged days</span>
                </div>
                <div class="history-kpi-card">
                    <span class="history-kpi-label">Total Meals</span>
                    <strong class="history-kpi-val"><?= $totalMealsLoggedCount ?></strong>
                    <span class="history-kpi-meta">Logged entries</span>
                </div>
                <div class="history-kpi-card">
                    <span class="history-kpi-label">Avg Daily Intake</span>
                    <strong class="history-kpi-val"><?= number_format($avgDailyCals) ?> <small>kcal</small></strong>
                    <span class="history-kpi-meta">Across logged days</span>
                </div>
                <div class="history-kpi-card">
                    <span class="history-kpi-label">Daily Target</span>
                    <strong class="history-kpi-val text-lime"><?= number_format($targetCals) ?> <small>kcal</small></strong>
                    <span class="history-kpi-meta"><?= $targetPro ?>g P • <?= $targetCarbs ?>g C • <?= $targetFat ?>g F</span>
                </div>
            </div>
        </div>

        <!-- Toolbar / Interactive Filters -->
        <div class="diet-history-toolbar">
            <div class="history-search-wrap">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="diet-history-search" class="history-search-input" placeholder="Search meals or ingredients (e.g., Oatmeal, Tofu, Adobo)..." oninput="filterMealHistory()">
                <button type="button" id="btn-clear-history-search" class="history-search-clear" onclick="clearMealHistorySearch()" style="display:none;" title="Clear search">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>

            <div class="history-filters-wrap">
                <!-- Meal Type Filter Pills -->
                <div class="history-type-pills">
                    <button type="button" class="history-filter-pill active" data-type="all" onclick="setMealHistoryTypeFilter('all')">All Meals</button>
                    <button type="button" class="history-filter-pill" data-type="Breakfast" onclick="setMealHistoryTypeFilter('Breakfast')">Breakfast</button>
                    <button type="button" class="history-filter-pill" data-type="Lunch" onclick="setMealHistoryTypeFilter('Lunch')">Lunch</button>
                    <button type="button" class="history-filter-pill" data-type="Dinner" onclick="setMealHistoryTypeFilter('Dinner')">Dinner</button>
                    <button type="button" class="history-filter-pill" data-type="Snack" onclick="setMealHistoryTypeFilter('Snack')">Snack</button>
                </div>

                <!-- Date Range Filter Dropdown -->
                <select id="diet-history-range" class="history-range-select" onchange="filterMealHistory()">
                    <option value="all">All Dates</option>
                    <option value="7">Last 7 Days</option>
                    <option value="30">Last 30 Days</option>
                </select>
            </div>
        </div>

        <!-- History Timeline Container -->
        <div id="diet-history-timeline" class="diet-history-timeline">
            <?php if (empty($pastLogsByDate)): ?>
                <!-- Empty State -->
                <div class="diet-history-empty" id="diet-history-empty-state">
                    <div class="history-empty-icon">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                    </div>
                    <h3 class="history-empty-title">No Past Meal Logs Yet</h3>
                    <p class="history-empty-desc">You haven't logged any meals yet. When you log meals from your Weekly Plan or use the Live Macro Logger, your full nutrition history will appear here.</p>
                    <button type="button" class="btn-history-log-today" onclick="switchDietView('tracker')">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        <span>Log Today's Meal</span>
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($pastLogsByDate as $logDate => $dayData): 
                    $timestamp = strtotime($logDate);
                    $isToday = ($logDate === date('Y-m-d'));
                    $isYesterday = ($logDate === date('Y-m-d', strtotime('-1 day')));
                    $dateLabel = date('l, F j, Y', $timestamp);
                    if ($isToday) {
                        $relativeTag = 'Today';
                    } elseif ($isYesterday) {
                        $relativeTag = 'Yesterday';
                    } else {
                        $diffDays = (int) round((time() - $timestamp) / 86400);
                        $relativeTag = "{$diffDays} days ago";
                    }
                    
                    $dayCals = (int) $dayData['cals'];
                    $dayPro = round((float) $dayData['pro'], 1);
                    $dayCarbs = round((float) $dayData['carbs'], 1);
                    $dayFat = round((float) $dayData['fat'], 1);
                    $adherencePct = $targetCals > 0 ? (int) round(($dayCals / $targetCals) * 100) : 0;
                ?>
                    <div class="history-day-card" data-date="<?= h($logDate) ?>" data-timestamp="<?= $timestamp ?>">
                        <!-- Day Header -->
                        <div class="history-day-header">
                            <div class="history-day-left">
                                <div class="history-day-icon">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                </div>
                                <div>
                                    <div class="history-day-title-row">
                                        <h4 class="history-day-date"><?= h($dateLabel) ?></h4>
                                        <span class="history-day-tag <?= $isToday ? 'tag-today' : '' ?>"><?= h($relativeTag) ?></span>
                                    </div>
                                    <div class="history-day-meta">
                                        <span><?= count($dayData['meals']) ?> meal<?= count($dayData['meals']) === 1 ? '' : 's' ?> logged</span>
                                        <span class="meta-dot">•</span>
                                        <span class="history-adherence <?= $adherencePct >= 90 && $adherencePct <= 110 ? 'adherence-good' : '' ?>">
                                            <?= $adherencePct ?>% of target
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Day Macro Totals Bar -->
                            <div class="history-day-macros">
                                <div class="history-macro-pill pill-cals" title="Total Calories">
                                    <span class="macro-stat-dot dot-cals"></span>
                                    <strong><?= number_format($dayCals) ?></strong>
                                    <span class="unit">kcal</span>
                                </div>
                                <div class="history-macro-pill pill-pro" title="Total Protein">
                                    <span class="macro-stat-dot dot-pro"></span>
                                    <strong><?= $dayPro ?>g</strong>
                                    <span class="unit">Pro</span>
                                </div>
                                <div class="history-macro-pill pill-carbs" title="Total Carbohydrates">
                                    <span class="macro-stat-dot dot-carbs"></span>
                                    <strong><?= $dayCarbs ?>g</strong>
                                    <span class="unit">Carb</span>
                                </div>
                                <div class="history-macro-pill pill-fat" title="Total Dietary Fat">
                                    <span class="macro-stat-dot dot-fat"></span>
                                    <strong><?= $dayFat ?>g</strong>
                                    <span class="unit">Fat</span>
                                </div>
                            </div>
                        </div>

                        <!-- Meals List for this Day -->
                        <div class="history-day-meals">
                            <?php if (empty($dayData['meals'])): ?>
                                <div class="history-custom-total-notice">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                                    <span>Logged via Daily Macro Total (<?= number_format($dayCals) ?> kcal). No individual meal breakdown was saved for this day.</span>
                                </div>
                            <?php else: ?>
                                <div class="history-meals-grid">
                                    <?php foreach ($dayData['meals'] as $mealLog): 
                                        $mType = ucfirst(strtolower(trim($mealLog['meal_type'])));
                                        if (strpos(strtolower($mType), 'snack') !== false) $mType = 'Snack';
                                        $mTime = date('g:i A', strtotime($mealLog['logged_at']));
                                        $mCals = (int) $mealLog['calories'];
                                        $mPro = round((float) $mealLog['protein_g'], 1);
                                        $mCarbs = round((float) $mealLog['carbs_g'], 1);
                                        $mFat = round((float) $mealLog['fat_g'], 1);
                                        $photoUrl = get_meal_photo_url($mealLog['image_url'] ?? null, $mealLog['food_items'], $mealLog['meal_type']);
                                    ?>
                                        <div class="history-meal-card" data-meal-type="<?= h($mType) ?>" data-food-text="<?= h(strtolower($mealLog['food_items'] ?? '')) ?>">
                                            <!-- Card Main Tier: Thumbnail + Details + Calories -->
                                            <div class="history-meal-main">
                                                <div class="history-meal-thumb-wrap">
                                                    <img src="<?= h($photoUrl) ?>" alt="<?= h($mealLog['food_items']) ?>" class="history-meal-thumb" loading="lazy" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=300&q=80';">
                                                    <span class="history-meal-type-chip type-<?= strtolower($mType) ?>"><?= h($mType) ?></span>
                                                </div>
                                                <div class="history-meal-body">
                                                    <div class="history-meal-meta-bar">
                                                        <div class="history-meal-meta-left">
                                                            <span class="history-meal-time">
                                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                                                <?= h($mTime) ?>
                                                            </span>
                                                            <?php if (!empty($mealLog['meal_id'])): ?>
                                                                <span class="history-planned-tag">
                                                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                                    Planned Meal
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="history-custom-tag">Custom Entry</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="history-meal-cals-badge">
                                                            <strong><?= number_format($mCals) ?></strong>
                                                            <span>kcal</span>
                                                        </div>
                                                    </div>
                                                    <div class="history-meal-name" title="<?= h($mealLog['food_items']) ?>">
                                                        <?= nl2br(h($mealLog['food_items'] ?: 'Meal Logged')) ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Card Bottom Tier: 3-Macro Pills -->
                                            <div class="history-meal-footer">
                                                <div class="history-meal-macros-row">
                                                    <span class="meal-macro-chip chip-pro" title="Protein">
                                                        <span class="macro-stat-dot dot-pro"></span>
                                                        <strong><?= $mPro ?>g</strong>
                                                        <span class="macro-lbl">Protein</span>
                                                    </span>
                                                    <span class="meal-macro-chip chip-carbs" title="Carbohydrates">
                                                        <span class="macro-stat-dot dot-carbs"></span>
                                                        <strong><?= $mCarbs ?>g</strong>
                                                        <span class="macro-lbl">Carbs</span>
                                                    </span>
                                                    <span class="meal-macro-chip chip-fat" title="Dietary Fat">
                                                        <span class="macro-stat-dot dot-fat"></span>
                                                        <strong><?= $mFat ?>g</strong>
                                                        <span class="macro-lbl">Fat</span>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <!-- Filter Empty State (Hidden by default, shown when search/filter has 0 results) -->
                <div class="diet-history-empty" id="diet-history-filter-empty" style="display: none;">
                    <div class="history-empty-icon">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    </div>
                    <h3 class="history-empty-title">No Matching Meals Found</h3>
                    <p class="history-empty-desc">No past meal logs match your current search and filter settings. Try clearing the search or choosing "All Meals".</p>
                    <button type="button" class="btn-clear-filter-action" onclick="resetMealHistoryFilters()">Reset Filters</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div> <!-- Close #view-meal-history -->

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
                    <span class="inspect-chip chip-cals" id="inspect-chip-cals" title="Total Calories (kcal)">0 kcal</span>
                    <span class="inspect-chip chip-pro" id="inspect-chip-pro" title="Total Protein (P)">0g P</span>
                    <span class="inspect-chip chip-carbs" id="inspect-chip-carbs" title="Total Carbohydrates (C)">0g C</span>
                    <span class="inspect-chip chip-fat" id="inspect-chip-fat" title="Total Fat (F)">0g F</span>
                </div>
            </div>

            <!-- Macro Legend Guide -->
            <div class="inspect-macro-legend">
                <span class="inspect-legend-label">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <span>Macro Legend:</span>
                </span>
                <div class="inspect-legend-pills">
                    <span class="inspect-legend-tag chip-cals" title="Calories"><strong>kcal</strong> Calories</span>
                    <span class="inspect-legend-tag chip-pro" title="Protein"><strong>P</strong> Protein</span>
                    <span class="inspect-legend-tag chip-carbs" title="Carbohydrates"><strong>C</strong> Carbs</span>
                    <span class="inspect-legend-tag chip-fat" title="Fat"><strong>F</strong> Fat</span>
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

        <div class="ft-modal-footer">
            <button type="button" class="btn-cancel" onclick="closeSwapModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- MODAL: DIET PLAN INFO (Mobile view for Goal, Diet Protocol & Coach) -->
<div id="modal-diet-plan-info" class="ft-modal-overlay" style="display:none;" onclick="if(event.target===this)closeDietPlanInfoModal()">
    <div class="ft-modal-box ft-modal-compact">
        <div class="ft-modal-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div class="diet-info-modal-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="16" x2="12" y2="12"></line>
                        <line x1="12" y1="8" x2="12.01" y2="8"></line>
                    </svg>
                </div>
                <div>
                    <h3 class="ft-modal-title">Diet Plan Overview</h3>
                    <p class="ft-modal-subtitle">Goal &amp; dietary protocol</p>
                </div>
            </div>
            <button type="button" class="ft-modal-close" onclick="closeDietPlanInfoModal()" aria-label="Close modal">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="ft-modal-body" style="padding: 18px 20px;">
            <div class="diet-plan-info-list">
                <!-- 1. Primary Fitness Goal -->
                <div class="diet-plan-info-item">
                    <div class="diet-plan-info-icon goal-icon">
                        <span class="diet-chip-dot dot-lime" style="width: 10px; height: 10px;"></span>
                    </div>
                    <div class="diet-plan-info-text">
                        <span class="diet-plan-info-label">Primary Fitness Goal</span>
                        <strong class="diet-plan-info-val"><?= h(ucwords(str_replace('_', ' ', $activePlan['goal']))) ?></strong>
                    </div>
                </div>

                <!-- 2. Secondary Focus (if set) -->
                <?php if (!empty($memberProfile['secondary_focus'])): ?>
                <div class="diet-plan-info-item">
                    <div class="diet-plan-info-icon" style="background: rgba(132, 204, 22, 0.12); color: var(--lime); display: flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 9px; flex-shrink: 0;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </div>
                    <div class="diet-plan-info-text">
                        <span class="diet-plan-info-label">Secondary Focus</span>
                        <strong class="diet-plan-info-val"><?= h(ucwords(str_replace('_', ' ', $memberProfile['secondary_focus']))) ?></strong>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 3. Dietary Protocol -->
                <div class="diet-plan-info-item" style="border-bottom: none; margin-bottom: 0; padding-bottom: 0;">
                    <div class="diet-plan-info-icon diet-icon">
                        <span style="font-size: 16px;">🥗</span>
                    </div>
                    <div class="diet-plan-info-text">
                        <span class="diet-plan-info-label">Dietary Protocol</span>
                        <strong class="diet-plan-info-val"><?= h(ucwords(str_replace('-', ' ', $userDietaryRestriction === 'none' ? 'Standard' : $userDietaryRestriction))) ?></strong>
                    </div>
                </div>
            </div>
        </div>
        <div class="ft-modal-footer" style="padding: 12px 20px; justify-content: flex-end;">
            <button type="button" class="btn-cancel" onclick="closeDietPlanInfoModal()">Got it</button>
        </div>
    </div>
</div>

<script src="<?= h(asset_url('js/pages/diet.js')) ?>"></script>

<?php
    render_footer();
}
