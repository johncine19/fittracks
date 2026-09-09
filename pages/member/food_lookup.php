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
    $query = trim((string) ($_GET['query'] ?? $_POST['query'] ?? ''));

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

    echo json_encode(['success' => false, 'error' => 'Invalid action. Specify calorieninjas or openfoodfacts.']);
    exit;
}
