<?php
declare(strict_types=1);

function food_lookup_page(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    $user = current_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required.']);
        exit;
    }

    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
    $query = trim((string) ($_GET['query'] ?? $_POST['query'] ?? $_GET['food'] ?? $_POST['food'] ?? ''));

    // Action 1: Search local database food library
    if ($action === 'search_library') {
        $gymId = get_user_gym_id($user);
        if (isset($_GET['gym_id']) && in_array($user['role'], ['gym_owner', 'trainer', 'platform_admin'])) {
            $gymId = (int)$_GET['gym_id'] ?: $gymId;
        }

        $params = [];
        $sql = 'SELECT * FROM food_items WHERE is_active = 1 ';
        if ($gymId) {
            $sql .= 'AND (gym_id = ? OR gym_id IS NULL) ';
            $params[] = $gymId;
        } else {
            $sql .= 'AND (gym_id IS NULL) ';
        }

        if (!empty($_GET['meal_type'])) {
            $sql .= 'AND meal_type = ? ';
            $params[] = (string)$_GET['meal_type'];
        }

        if (!empty($_GET['dietary_restriction']) && $_GET['dietary_restriction'] !== 'all') {
            $sql .= 'AND (dietary_restriction = ? OR dietary_restriction = "none") ';
            $params[] = (string)$_GET['dietary_restriction'];
        }

        if (!empty($query)) {
            $sql .= 'AND (name LIKE ? OR recipe_desc LIKE ?) ';
            $params[] = '%' . $query . '%';
            $params[] = '%' . $query . '%';
        }

        $sql .= 'ORDER BY (gym_id IS NOT NULL) DESC, name ASC LIMIT 30';

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'provider' => 'database_library',
            'count' => count($items),
            'items' => array_map(function($row) {
                return [
                    'food_id' => (int)$row['food_id'],
                    'name' => $row['name'],
                    'meal_type' => $row['meal_type'],
                    'dietary_restriction' => $row['dietary_restriction'],
                    'serving_size' => $row['serving_size'],
                    'calories' => (int)$row['calories'],
                    'protein_g' => (float)$row['protein_g'],
                    'carbs_g' => (float)$row['carbs_g'],
                    'fat_g' => (float)$row['fat_g'],
                    'image_url' => $row['image_url'],
                    'recipe_desc' => $row['recipe_desc'],
                    'is_gym_custom' => !is_null($row['gym_id']),
                    'source' => $row['source']
                ];
            }, $items)
        ]);
        exit;
    }

    // Action 2: Import external food from Open Food Facts or CalorieNinjas into gym's library
    if ($action === 'import_external_food') {
        try {
            if (!in_array($user['role'], ['gym_owner', 'trainer', 'platform_admin'])) {
                echo json_encode(['success' => false, 'error' => 'Permission denied. Only gym staff can import foods.']);
                exit;
            }

            $gymId = get_user_gym_id($user);
            $name = trim((string)($_POST['name'] ?? ''));
            $mealType = (string)($_POST['meal_type'] ?? 'Lunch');
            if (!in_array($mealType, ['Breakfast', 'Lunch', 'Dinner', 'Snack'])) {
                $mealType = 'Lunch';
            }

            $calories = max(0, (int)($_POST['calories'] ?? 0));
            $protein = max(0.0, (float)($_POST['protein_g'] ?? 0));
            $carbs = max(0.0, (float)($_POST['carbs_g'] ?? 0));
            $fat = max(0.0, (float)($_POST['fat_g'] ?? 0));
            $servingSize = trim((string)($_POST['serving_size'] ?? '1 serving')) ?: '1 serving';
            $imageUrl = trim((string)($_POST['image_url'] ?? '')) ?: null;
            $source = trim((string)($_POST['source'] ?? 'openfoodfacts'));
            $desc = trim((string)($_POST['recipe_desc'] ?? 'Imported from nutrition database.'));

            if (empty($name)) {
                echo json_encode(['success' => false, 'error' => 'Food name is required.']);
                exit;
            }

            $stmt = db()->prepare("
                INSERT INTO food_items 
                (gym_id, name, meal_type, dietary_restriction, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc, source)
                VALUES (?, ?, ?, 'none', ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user['role'] === 'platform_admin' ? null : $gymId,
                $name,
                $mealType,
                $servingSize,
                $calories,
                $protein,
                $carbs,
                $fat,
                $imageUrl,
                $desc,
                $source
            ]);

            $newFoodId = (int)db()->lastInsertId();

            echo json_encode([
                'success' => true,
                'food_id' => $newFoodId,
                'message' => 'Successfully imported "' . htmlspecialchars($name) . '" into the food library!',
                'item' => [
                    'food_id' => $newFoodId,
                    'name' => $name,
                    'meal_type' => $mealType,
                    'serving_size' => $servingSize,
                    'calories' => $calories,
                    'protein_g' => $protein,
                    'carbs_g' => $carbs,
                    'fat_g' => $fat,
                    'image_url' => $imageUrl
                ]
            ]);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Import failed: ' . $e->getMessage()]);
            exit;
        }
    }

    if (empty($query)) {
        echo json_encode(['success' => false, 'error' => 'Please enter a search query or meal description.']);
        exit;
    }

    if ($action === 'calorieninjas') {
        $apiKey = trim((string) ($_ENV['CALORIENINJAS_API_KEY'] ?? ''));
        if (empty($apiKey)) {
            echo json_encode(['success' => false, 'error' => 'CalorieNinjas API key is not configured in .env.']);
            exit;
        }

        $url = 'https://api.calorieninjas.com/v1/nutrition?query=' . urlencode($query);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Api-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false || $httpCode !== 200) {
            echo json_encode(['success' => false, 'error' => 'CalorieNinjas service error (HTTP ' . $httpCode . ').']);
            exit;
        }

        $data = json_decode($res, true);
        if (!isset($data['items']) || empty($data['items'])) {
            echo json_encode(['success' => false, 'error' => 'No nutrition information found for "' . htmlspecialchars($query, ENT_QUOTES, 'UTF-8') . '". Try being more descriptive (e.g., "2 eggs and 1 cup rice").']);
            exit;
        }

        $totCals = 0.0;
        $totPro = 0.0;
        $totCarbs = 0.0;
        $totFat = 0.0;

        $cleanItems = [];
        foreach ($data['items'] as $item) {
            $cals = (float) ($item['calories'] ?? 0);
            $pro = (float) ($item['protein_g'] ?? 0);
            $carbs = (float) ($item['carbohydrates_total_g'] ?? 0);
            $fat = (float) ($item['fat_total_g'] ?? 0);

            $totCals += $cals;
            $totPro += $pro;
            $totCarbs += $carbs;
            $totFat += $fat;

            $cleanItems[] = [
                'name' => ucwords((string) ($item['name'] ?? 'Food item')),
                'serving_size_g' => (float) ($item['serving_size_g'] ?? 0),
                'calories' => round($cals),
                'protein_g' => round($pro, 1),
                'carbs_g' => round($carbs, 1),
                'fat_g' => round($fat, 1),
                'fiber_g' => round((float) ($item['fiber_g'] ?? 0), 1),
                'sugar_g' => round((float) ($item['sugar_g'] ?? 0), 1),
                'sodium_mg' => round((float) ($item['sodium_mg'] ?? 0)),
                'potassium_mg' => round((float) ($item['potassium_mg'] ?? 0)),
            ];
        }

        echo json_encode([
            'success' => true,
            'provider' => 'calorieninjas',
            'query' => $query,
            'total_calories' => round($totCals),
            'total_protein' => round($totPro, 1),
            'total_carbs' => round($totCarbs, 1),
            'total_fat' => round($totFat, 1),
            'items' => $cleanItems,
        ]);
        exit;
    }

    if ($action === 'openfoodfacts') {
        // Query Open Food Facts mirror (fallback safely)
        $url = 'https://world.openfoodfacts.net/cgi/search.pl?search_terms=' . urlencode($query) . '&search_simple=1&action=process&json=1&page_size=12';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'FitTracksApp/1.0 (gym_management_app; contact@fittracks.com)');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false || $httpCode !== 200) {
            echo json_encode(['success' => false, 'error' => 'Open Food Facts service is currently unreachable.']);
            exit;
        }

        $data = json_decode($res, true);
        $products = [];

        if (isset($data['products']) && is_array($data['products'])) {
            foreach ($data['products'] as $p) {
                $name = trim((string) ($p['product_name'] ?? $p['product_name_en'] ?? ''));
                if (empty($name)) continue;

                $nutr = $p['nutriments'] ?? [];
                $cals = (float) ($nutr['energy-kcal_100g'] ?? $nutr['energy-kcal'] ?? 0);
                $pro = (float) ($nutr['proteins_100g'] ?? $nutr['proteins'] ?? 0);
                $carbs = (float) ($nutr['carbohydrates_100g'] ?? $nutr['carbohydrates'] ?? 0);
                $fat = (float) ($nutr['fat_100g'] ?? $nutr['fat'] ?? 0);

                // Skip entries that have zero in all macros
                if ($cals <= 0 && $pro <= 0 && $carbs <= 0 && $fat <= 0) continue;

                $products[] = [
                    'id' => (string) ($p['_id'] ?? $p['code'] ?? uniqid()),
                    'name' => $name,
                    'brand' => trim((string) ($p['brands'] ?? '')),
                    'serving_size' => trim((string) ($p['serving_size'] ?? '100g')),
                    'calories_100g' => round($cals),
                    'protein_100g' => round($pro, 1),
                    'carbs_100g' => round($carbs, 1),
                    'fat_100g' => round($fat, 1),
                    'image' => (string) ($p['image_front_small_url'] ?? $p['image_small_url'] ?? '')
                ];

                if (count($products) >= 8) break;
            }
        }

        if (empty($products)) {
            echo json_encode(['success' => false, 'error' => 'No branded food products found for "' . htmlspecialchars($query, ENT_QUOTES, 'UTF-8') . '". Try another keyword.']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'provider' => 'openfoodfacts',
            'query' => $query,
            'products' => $products
        ]);
        exit;
    }

    if ($action === 'decompose_meal') {
        $mealFood = trim((string)($_GET['food'] ?? $_POST['food'] ?? $query));
        $calories = max(0, (float)($_GET['calories'] ?? $_POST['calories'] ?? 0));
        $protein = max(0.0, (float)($_GET['protein_g'] ?? $_POST['protein_g'] ?? 0));
        $carbs = max(0.0, (float)($_GET['carbs_g'] ?? $_POST['carbs_g'] ?? 0));
        $fat = max(0.0, (float)($_GET['fat_g'] ?? $_POST['fat_g'] ?? 0));

        require_once __DIR__ . '/../../core/food_ingredients.php';

        // 1. Strip portion prefix e.g. "292g of " or "1 bowl of " or "1 serving of "
        $cleanName = trim(preg_replace('/\b(\d+g|\d+\s*g|\d+\s*ml|\d+\s*oz|\d+\s*plate|\d+\s*bowl|\d+\s*cup|\d+\s*serving|\d+\s*servings)\s*(?:of\s*)?/i', '', $mealFood));

        // 2. Query food_items database table for matching gym or system recipe
        $gymId = get_user_gym_id($user);
        $stmt = db()->prepare('
            SELECT * FROM food_items 
            WHERE (? IS NULL OR gym_id = ? OR gym_id IS NULL OR gym_id = 0) 
              AND (name = ? OR name LIKE ? OR ? LIKE CONCAT("%", name, "%"))
            ORDER BY (gym_id IS NOT NULL AND gym_id > 0) DESC, (name = ?) DESC LIMIT 1
        ');
        $stmt->execute([$gymId, $gymId, $cleanName, '%' . $cleanName . '%', $cleanName, $cleanName]);
        $foodRow = $stmt->fetch(PDO::FETCH_ASSOC);

        $recipeNotes = $foodRow['recipe_desc'] ?? null;
        $verifiedName = $foodRow['name'] ?? null;
        $targetCalories = $calories > 0 ? $calories : (!empty($foodRow['calories']) ? (float)$foodRow['calories'] : 500);
        $targetProtein  = $protein > 0 ? $protein : (!empty($foodRow['protein_g']) ? (float)$foodRow['protein_g'] : 30);
        $targetCarbs    = $carbs > 0 ? $carbs : (!empty($foodRow['carbs_g']) ? (float)$foodRow['carbs_g'] : 45);
        $targetFat      = $fat > 0 ? $fat : (!empty($foodRow['fat_g']) ? (float)$foodRow['fat_g'] : 15);

        if ($foodRow) {
            $ingredientsStruct = get_food_ingredients($foodRow);
        } else {
            $ingredientsStruct = get_food_ingredients(['name' => $cleanName, 'recipe_desc' => $cleanName]);
        }

        $rawItems = !empty($ingredientsStruct['all']) ? $ingredientsStruct['all'] : [];

        if (empty($rawItems)) {
            // Split by comma, "with", "and", "&", or "+"
            $parts = preg_split('/,|(\band\b)|(\bwith\b)|&|\+/i', $cleanName);
            foreach ($parts as $p) {
                $p = trim($p);
                if (!empty($p) && strlen($p) > 1) {
                    $rawItems[] = ['name' => $p, 'measure' => '1 portion'];
                }
            }
        }

        if (empty($rawItems)) {
            $rawItems[] = ['name' => $cleanName ?: $mealFood, 'measure' => '1 serving'];
        }

        // 3. Intelligently weight macronutrients by ingredient food category
        $proWeights = [];
        $carbsWeights = [];
        $fatWeights = [];

        foreach ($rawItems as $idx => $ing) {
            $n = strtolower($ing['name'] ?? '');

            // Seasonings / spices / sauces get very low macro weight
            if (preg_match('/(pepper|leaf|clove|salt|spice|cinnamon|vinegar|bay leaf|seasoning)/i', $n)) {
                $pw = 0.05; $cw = 0.1; $fw = 0.05;
            } elseif (preg_match('/(garlic|onion|soy sauce|tamari|ginger|calamansi|lemon)/i', $n)) {
                $pw = 0.2; $cw = 0.5; $fw = 0.1;
            } else {
                // Protein weighting
                if (preg_match('/(chicken|beef|pork|fish|egg|tofu|shrimp|turkey|tuna|salmon|bangus|sirloin|whey|meat|fillet)/i', $n)) {
                    $pw = 10.0;
                } elseif (preg_match('/(rice|quinoa|oat|bread|potato|pasta)/i', $n)) {
                    $pw = 1.0;
                } elseif (preg_match('/(cabbage|spinach|kangkong|greens|broccoli|veggie|radish)/i', $n)) {
                    $pw = 0.5;
                } else {
                    $pw = 0.2;
                }

                // Carb weighting
                if (preg_match('/(rice|quinoa|oat|bread|potato|pasta|noodle|\bcorn\b|flour|pancake|banana|sweet)/i', $n)) {
                    $cw = 10.0;
                } elseif (preg_match('/(cabbage|spinach|kangkong|greens|broccoli|radish|tomato)/i', $n)) {
                    $cw = 1.5;
                } else {
                    $cw = 0.2;
                }

                // Fat weighting
                if (preg_match('/(oil|butter|cheese|avocado|bacon|fat|mayo|cream|seeds|nuts)/i', $n)) {
                    $fw = 10.0;
                } elseif (preg_match('/(chicken|beef|pork|fish|egg|tofu|salmon|bangus|sirloin)/i', $n)) {
                    $fw = 4.0;
                } else {
                    $fw = 0.2;
                }
            }

            $proWeights[$idx] = $pw;
            $carbsWeights[$idx] = $cw;
            $fatWeights[$idx] = $fw;
        }

        $sumProW = max(0.1, array_sum($proWeights));
        $sumCarbsW = max(0.1, array_sum($carbsWeights));
        $sumFatW = max(0.1, array_sum($fatWeights));

        $items = [];
        foreach ($rawItems as $idx => $ing) {
            $ingName = trim((string)($ing['name'] ?? 'Ingredient'));
            $pro = round(($proWeights[$idx] / $sumProW) * $targetProtein, 1);
            $carbs = round(($carbsWeights[$idx] / $sumCarbsW) * $targetCarbs, 1);
            $fat = round(($fatWeights[$idx] / $sumFatW) * $targetFat, 1);
            $cals = max(2, (int)round(($pro * 4) + ($carbs * 4) + ($fat * 9)));

            $portion = trim((string)($ing['measure'] ?? ''));
            if (empty($portion)) {
                $amt = trim((string)($ing['amount'] ?? ''));
                $unit = trim((string)($ing['unit'] ?? ''));
                $portion = $amt ? ($amt . ($unit ? ' ' . $unit : '')) : '1 portion';
            }

            $items[] = [
                'name' => ucwords($ingName),
                'portion' => $portion,
                'serving_size_g' => !empty($ing['amount']) && is_numeric($ing['amount']) ? (float)$ing['amount'] : 100,
                'calories' => $cals,
                'protein_g' => $pro,
                'carbs_g' => $carbs,
                'fat_g' => $fat,
                'is_optional' => !empty($ing['is_optional'])
            ];
        }

        echo json_encode([
            'success' => true,
            'provider' => $foodRow ? 'gym_food_library' : 'master_recipes',
            'verified_food_name' => $verifiedName ?: $cleanName,
            'recipe_notes' => $recipeNotes,
            'query' => $mealFood,
            'items' => $items
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action. Specify calorieninjas, openfoodfacts, or decompose_meal.']);
    exit;
}
