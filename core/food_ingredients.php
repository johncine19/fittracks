<?php
declare(strict_types=1);

/**
 * FitTracks Food Library Ingredients & Nutrition Engine
 * Provides ingredient parsing, formatting, and rich recipe decomposition.
 */

/**
 * Returns structured ingredients for any food item.
 *
 * @param array $food Food item database record
 * @return array{main: array, optional: array, all: array}
 */
function get_food_ingredients(array $food): array
{
    // 1. Check if custom ingredients are already saved in the database
    if (!empty($food['ingredients'])) {
        $raw = trim((string) $food['ingredients']);
        $parsed = parse_ingredients_data($raw);
        if (!empty($parsed['all'])) {
            return $parsed;
        }
    }

    // 2. Check built-in master dictionary for staple and seeded recipes
    $foodName = trim((string) ($food['name'] ?? ''));
    $staples = get_staple_recipes_ingredients();
    
    foreach ($staples as $key => $recipe) {
        if (strcasecmp($foodName, $key) === 0 || stripos($foodName, $key) !== false || stripos($key, $foodName) !== false) {
            return format_ingredients_structure($recipe);
        }
    }

    // 3. Fallback: Parse ingredients dynamically from recipe_desc or food name
    return fallback_parse_ingredients($food);
}

/**
 * Formats a list of ingredients into {main: [], optional: [], all: []}
 */
function format_ingredients_structure(array $items): array
{
    $main = [];
    $optional = [];
    $all = [];
    foreach ($items as $item) {
        $isOptional = !empty($item['is_optional']);
        $cleanItem = [
            'name'        => trim((string) ($item['name'] ?? '')),
            'amount'      => trim((string) ($item['amount'] ?? '')),
            'unit'        => trim((string) ($item['unit'] ?? '')),
            'measure'     => trim((string) (($item['amount'] ?? '') . ' ' . ($item['unit'] ?? ''))),
            'type'        => $isOptional ? 'optional' : 'main',
            'is_optional' => $isOptional,
            'notes'       => trim((string) ($item['notes'] ?? ''))
        ];

        if (empty($cleanItem['measure']) && !empty($item['portion'])) {
            $cleanItem['measure'] = trim((string) $item['portion']);
        }

        $all[] = $cleanItem;
        if ($isOptional) {
            $optional[] = $cleanItem;
        } else {
            $main[] = $cleanItem;
        }
    }

    return [
        'main'     => $main,
        'optional' => $optional,
        'all'      => $all
    ];
}

/**
 * Calculates authentic USDA/real-world macronutrient and calorie values for an ingredient.
 *
 * @param string $name Ingredient name (e.g. "Rolled oats", "Unsweetened almond milk")
 * @param string $amountStr Quantity string (e.g. "50", "1", "1.5", "1/2")
 * @param string $unitStr Unit string (e.g. "g", "cup", "tbsp", "tsp", "pc")
 * @param string $portionStr Full portion text if available (e.g. "50 g (1/2 cup)", "to taste")
 * @return array{calories: int, protein_g: float, carbs_g: float, fat_g: float, fiber_g: float, sodium_mg: int}
 */
function calculate_ingredient_nutrition(string $name, string $amountStr = '', string $unitStr = '', string $portionStr = ''): array
{
    $n = strtolower(trim($name));
    $portion = strtolower(trim($portionStr ?: ($amountStr . ' ' . $unitStr)));

    // 1. Zero / Negligible Calorie Items (Sweeteners, spices, pinches, water, ice, aromatics)
    if (preg_match('/\b(stevia|splenda|monkfruit|erythritol|sweetener|sweeteners|bay leaf|bay leaves|ice|water|pinch|black pepper|cracked pepper|peppercorn|peppercorns|oregano|paprika|parsley|rosemary|thyme|cinnamon|chili flake|chili flakes|mint)\b/i', $n) ||
        preg_match('/\b(to taste|pinch|cubes)\b/i', $portion) ||
        preg_match('/^to taste$/i', $amountStr)) {
        return ['calories' => 0, 'protein_g' => 0.0, 'carbs_g' => 0.0, 'fat_g' => 0.0, 'fiber_g' => 0.0, 'sodium_mg' => 5];
    }

    // 2. Parse quantity amount
    $amt = 1.0;
    if (preg_match('/^(\d+)\s*\/\s*(\d+)/', $amountStr, $m)) {
        $amt = (float)$m[1] / max(1, (float)$m[2]);
    } elseif (is_numeric($amountStr) && (float)$amountStr > 0) {
        $amt = (float)$amountStr;
    } elseif (preg_match('/(\d+[\/\d\.]*)/', $portion, $m)) {
        if (strpos($m[1], '/') !== false) {
            $f = explode('/', $m[1]);
            $amt = (float)$f[0] / max(1, (float)$f[1]);
        } else {
            $amt = (float)$m[1];
        }
    }

    // 3. Normalize unit
    $unit = strtolower(trim($unitStr));
    if (empty($unit)) {
        if (preg_match('/(g|gram|grams|kg|ml|oz|tbsp|tsp|cup|cups|scoop|scoops|clove|cloves|pc|pcs|slice|slices|can|cans|cracker|crackers)/i', $portion, $um)) {
            $unit = $um[1];
        }
    }

    // 4. Authentic Nutritional Benchmark Database
    $db = [
        // Grains, Oats & Starches
        'rolled oat' => ['cals_100g' => 375, 'p_100g' => 13.0, 'c_100g' => 66.0, 'f_100g' => 6.5, 'fib_100g' => 10.0],
        'oat' => ['cals_100g' => 375, 'p_100g' => 13.0, 'c_100g' => 66.0, 'f_100g' => 6.5, 'fib_100g' => 10.0],
        'garlic brown rice' => ['cals_cup' => 195, 'p_cup' => 4.0, 'c_cup' => 42.0, 'f_cup' => 1.5, 'cals_100g' => 135, 'p_100g' => 2.8, 'c_100g' => 28.0, 'f_100g' => 1.0],
        'brown rice' => ['cals_cup' => 190, 'p_cup' => 4.0, 'c_cup' => 40.0, 'f_cup' => 1.5, 'cals_100g' => 123, 'p_100g' => 2.7, 'c_100g' => 25.6, 'f_100g' => 1.0],
        'white rice' => ['cals_cup' => 205, 'p_cup' => 4.2, 'c_cup' => 45.0, 'f_cup' => 0.4, 'cals_100g' => 130, 'p_100g' => 2.7, 'c_100g' => 28.0, 'f_100g' => 0.3],
        'rice' => ['cals_cup' => 200, 'p_cup' => 4.0, 'c_cup' => 44.0, 'f_cup' => 0.5, 'cals_100g' => 130, 'p_100g' => 2.7, 'c_100g' => 28.0, 'f_100g' => 0.3],
        'quinoa' => ['cals_cup' => 220, 'p_cup' => 8.0, 'c_cup' => 39.0, 'f_cup' => 3.5, 'cals_100g' => 120, 'p_100g' => 4.4, 'c_100g' => 21.3, 'f_100g' => 1.9],
        'sweet potato' => ['cals_100g' => 86, 'p_100g' => 1.6, 'c_100g' => 20.1, 'f_100g' => 0.1, 'fib_100g' => 3.0],
        'kamote' => ['cals_100g' => 86, 'p_100g' => 1.6, 'c_100g' => 20.1, 'f_100g' => 0.1, 'fib_100g' => 3.0],
        'sourdough' => ['cals_pc' => 90, 'p_pc' => 3.5, 'c_pc' => 17.5, 'f_pc' => 0.6, 'cals_100g' => 260, 'p_100g' => 9.0, 'c_100g' => 50.0, 'f_100g' => 1.5],
        'cracker' => ['cals_pc' => 22, 'p_pc' => 0.6, 'c_pc' => 4.2, 'f_pc' => 0.4, 'cals_100g' => 420, 'p_100g' => 10.0, 'c_100g' => 70.0, 'f_100g' => 9.0],
        'bread' => ['cals_pc' => 80, 'p_pc' => 3.0, 'c_pc' => 15.0, 'f_pc' => 1.0, 'cals_100g' => 265, 'p_100g' => 9.0, 'c_100g' => 49.0, 'f_100g' => 3.2],
        
        // Proteins, Poultry & Seafood
        'whey' => ['serving_g' => 30, 'cals_100g' => 367, 'p_100g' => 80.0, 'c_100g' => 4.0, 'f_100g' => 2.0, 'cals' => 110, 'p' => 24.0, 'c' => 1.2, 'f' => 0.6],
        'protein powder' => ['serving_g' => 30, 'cals_100g' => 367, 'p_100g' => 80.0, 'c_100g' => 4.0, 'f_100g' => 2.0, 'cals' => 110, 'p' => 24.0, 'c' => 1.2, 'f' => 0.6],
        'isolate' => ['serving_g' => 30, 'cals_100g' => 367, 'p_100g' => 80.0, 'c_100g' => 4.0, 'f_100g' => 2.0, 'cals' => 110, 'p' => 24.0, 'c' => 1.2, 'f' => 0.6],
        'chicken breast' => ['cals_100g' => 120, 'p_100g' => 23.5, 'c_100g' => 0.0, 'f_100g' => 1.5],
        'chicken' => ['cals_100g' => 140, 'p_100g' => 22.0, 'c_100g' => 0.0, 'f_100g' => 5.0],
        'beef sirloin' => ['cals_100g' => 165, 'p_100g' => 23.0, 'c_100g' => 0.0, 'f_100g' => 7.5],
        'beef' => ['cals_100g' => 180, 'p_100g' => 22.0, 'c_100g' => 0.0, 'f_100g' => 10.0],
        'tapa' => ['cals_100g' => 175, 'p_100g' => 22.0, 'c_100g' => 2.0, 'f_100g' => 8.0],
        'egg white' => ['cals_pc' => 17, 'p_pc' => 3.6, 'c_pc' => 0.2, 'f_pc' => 0.1, 'cals_100g' => 52, 'p_100g' => 11.0, 'c_100g' => 0.7, 'f_100g' => 0.2],
        'egg' => ['cals_pc' => 72, 'p_pc' => 6.3, 'c_pc' => 0.4, 'f_pc' => 4.8, 'cals_100g' => 143, 'p_100g' => 12.6, 'c_100g' => 0.8, 'f_100g' => 9.5],
        'tofu' => ['cals_100g' => 85, 'p_100g' => 10.0, 'c_100g' => 2.0, 'f_100g' => 4.5],
        'salmon' => ['cals_100g' => 180, 'p_100g' => 20.0, 'c_100g' => 0.0, 'f_100g' => 11.0],
        'tuna' => ['cals_100g' => 110, 'p_100g' => 24.0, 'c_100g' => 0.0, 'f_100g' => 1.0],
        'tinapa' => ['cals_100g' => 150, 'p_100g' => 22.0, 'c_100g' => 0.0, 'f_100g' => 6.5],
        'bangus' => ['cals_100g' => 148, 'p_100g' => 20.5, 'c_100g' => 0.0, 'f_100g' => 6.7],
        'milkfish' => ['cals_100g' => 148, 'p_100g' => 20.5, 'c_100g' => 0.0, 'f_100g' => 6.7],
        'shrimp' => ['cals_100g' => 85, 'p_100g' => 18.0, 'c_100g' => 0.5, 'f_100g' => 1.0],
        'bacon' => ['cals_pc' => 45, 'p_pc' => 3.0, 'c_pc' => 0.1, 'f_pc' => 3.5, 'cals_100g' => 450, 'p_100g' => 30.0, 'c_100g' => 1.0, 'f_100g' => 35.0],
        'chicharon' => ['cals_100g' => 540, 'p_100g' => 60.0, 'c_100g' => 0.0, 'f_100g' => 32.0],
        'edamame' => ['cals_100g' => 120, 'p_100g' => 11.0, 'c_100g' => 10.0, 'f_100g' => 5.0],
        'mung' => ['cals_100g' => 105, 'p_100g' => 7.0, 'c_100g' => 19.0, 'f_100g' => 0.4],

        // Dairy & Plant Milks
        'unsweetened almond milk' => ['cals_cup' => 35, 'p_cup' => 1.0, 'c_cup' => 1.0, 'f_cup' => 2.5, 'cals_100g' => 15, 'p_100g' => 0.5, 'c_100g' => 0.5, 'f_100g' => 1.1],
        'almond milk' => ['cals_cup' => 35, 'p_cup' => 1.0, 'c_cup' => 1.0, 'f_cup' => 2.5, 'cals_100g' => 15, 'p_100g' => 0.5, 'c_100g' => 0.5, 'f_100g' => 1.1],
        'skim milk' => ['cals_cup' => 85, 'p_cup' => 8.5, 'c_cup' => 12.0, 'f_cup' => 0.2, 'cals_100g' => 35, 'p_100g' => 3.5, 'c_100g' => 5.0, 'f_100g' => 0.1],
        'coconut cream' => ['cals_cup' => 450, 'p_cup' => 4.5, 'c_cup' => 7.0, 'f_cup' => 48.0, 'cals_tbsp' => 35, 'p_tbsp' => 0.4, 'c_tbsp' => 0.6, 'f_tbsp' => 3.5],
        'coconut milk' => ['cals_cup' => 400, 'p_cup' => 4.0, 'c_cup' => 6.0, 'f_cup' => 43.0, 'cals_tbsp' => 30, 'p_tbsp' => 0.3, 'c_tbsp' => 0.5, 'f_tbsp' => 3.0],
        'cottage cheese' => ['cals_cup' => 180, 'p_cup' => 24.0, 'c_cup' => 8.0, 'f_cup' => 5.0, 'cals_100g' => 85, 'p_100g' => 11.0, 'c_100g' => 3.5, 'f_100g' => 2.5],
        'greek yogurt' => ['cals_cup' => 130, 'p_cup' => 18.0, 'c_cup' => 6.5, 'f_cup' => 1.5, 'cals_100g' => 70, 'p_100g' => 10.0, 'c_100g' => 3.6, 'f_100g' => 0.8],
        'cheddar' => ['cals_100g' => 400, 'p_100g' => 25.0, 'c_100g' => 1.3, 'f_100g' => 33.0],

        // Fats, Butters & Seeds
        'almond butter' => ['cals_tbsp' => 98, 'p_tbsp' => 3.4, 'c_tbsp' => 3.0, 'f_tbsp' => 8.8, 'cals_100g' => 610, 'p_100g' => 21.0, 'c_100g' => 19.0, 'f_100g' => 55.0],
        'peanut butter' => ['cals_tbsp' => 95, 'p_tbsp' => 4.0, 'c_tbsp' => 3.5, 'f_tbsp' => 8.0, 'cals_100g' => 590, 'p_100g' => 25.0, 'c_100g' => 20.0, 'f_100g' => 50.0],
        'chia' => ['cals_tsp' => 22, 'p_tsp' => 0.8, 'c_tsp' => 1.9, 'f_tsp' => 1.4, 'cals_tbsp' => 65, 'p_tbsp' => 2.5, 'c_tbsp' => 5.5, 'f_tbsp' => 4.2, 'cals_100g' => 485, 'p_100g' => 16.5, 'c_100g' => 42.0, 'f_100g' => 31.0],
        'oil' => ['cals_tsp' => 40, 'p_tsp' => 0.0, 'c_tsp' => 0.0, 'f_tsp' => 4.5, 'cals_tbsp' => 120, 'p_tbsp' => 0.0, 'c_tbsp' => 0.0, 'f_tbsp' => 14.0],
        'butter' => ['cals_tbsp' => 102, 'p_tbsp' => 0.1, 'c_tbsp' => 0.0, 'f_tbsp' => 11.5, 'cals_tsp' => 34, 'p_tsp' => 0.0, 'c_tsp' => 0.0, 'f_tsp' => 3.8],
        'avocado' => ['cals_pc' => 240, 'p_pc' => 3.0, 'c_pc' => 12.0, 'f_pc' => 22.0, 'cals_100g' => 160, 'p_100g' => 2.0, 'c_100g' => 8.5, 'f_100g' => 14.5],
        'almond' => ['cals_tbsp' => 45, 'p_tbsp' => 1.6, 'c_tbsp' => 1.6, 'f_tbsp' => 4.0, 'cals_100g' => 580, 'p_100g' => 21.0, 'c_100g' => 22.0, 'f_100g' => 50.0],
        'mayonnaise' => ['cals_tbsp' => 45, 'p_tbsp' => 0.2, 'c_tbsp' => 1.0, 'f_tbsp' => 4.5],

        // Cocoa, Seasonings & Sauces
        'tablea' => ['cals_tbsp' => 20, 'p_tbsp' => 1.5, 'c_tbsp' => 4.0, 'f_tbsp' => 1.2],
        'cocoa' => ['cals_tbsp' => 18, 'p_tbsp' => 1.5, 'c_tbsp' => 4.0, 'f_tbsp' => 1.0, 'cals_tsp' => 6, 'p_tsp' => 0.5, 'c_tsp' => 1.3, 'f_tsp' => 0.3],
        'cacao' => ['cals_tbsp' => 20, 'p_tbsp' => 1.5, 'c_tbsp' => 4.0, 'f_tbsp' => 1.2],
        'soy sauce' => ['cals_tbsp' => 10, 'p_tbsp' => 1.5, 'c_tbsp' => 1.0, 'f_tbsp' => 0.0],
        'tamari' => ['cals_tbsp' => 12, 'p_tbsp' => 1.8, 'c_tbsp' => 1.0, 'f_tbsp' => 0.0],
        'vinegar' => ['cals_tbsp' => 3, 'p_tbsp' => 0.0, 'c_tbsp' => 0.5, 'f_tbsp' => 0.0],
        'garlic' => ['cals_clove' => 4, 'p_clove' => 0.2, 'c_clove' => 1.0, 'f_clove' => 0.0, 'cals_100g' => 145, 'p_100g' => 6.4, 'c_100g' => 33.0, 'f_100g' => 0.5],
        'ginger' => ['cals_tbsp' => 5, 'p_tbsp' => 0.1, 'c_tbsp' => 1.0, 'f_tbsp' => 0.0],
        'calamansi' => ['cals_pc' => 4, 'p_pc' => 0.1, 'c_pc' => 1.0, 'f_pc' => 0.0],
        'lemon' => ['cals_tbsp' => 4, 'p_tbsp' => 0.1, 'c_tbsp' => 1.0, 'f_tbsp' => 0.0],
        'tamarind' => ['cals_cup' => 10, 'p_cup' => 0.5, 'c_cup' => 2.0, 'f_cup' => 0.0],
        'maple' => ['cals_tbsp' => 10, 'p_tbsp' => 0.0, 'c_tbsp' => 2.5, 'f_tbsp' => 0.0],
        'honey' => ['cals_tsp' => 21, 'p_tsp' => 0.0, 'c_tsp' => 5.7, 'f_tsp' => 0.0],
        'miso' => ['cals_tsp' => 12, 'p_tsp' => 0.7, 'c_tsp' => 1.5, 'f_tsp' => 0.4],

        // Vegetables & Fruits
        'saba' => ['cals_pc' => 80, 'p_pc' => 1.0, 'c_pc' => 20.0, 'f_pc' => 0.2, 'cals_100g' => 110, 'p_100g' => 1.2, 'c_100g' => 28.0, 'f_100g' => 0.2],
        'banana' => ['cals_pc' => 105, 'p_pc' => 1.3, 'c_pc' => 27.0, 'f_pc' => 0.3, 'cals_100g' => 89, 'p_100g' => 1.1, 'c_100g' => 22.8, 'f_100g' => 0.3],
        'blueberry' => ['cals_cup' => 85, 'p_cup' => 1.1, 'c_cup' => 21.0, 'f_cup' => 0.5, 'cals_100g' => 57, 'p_100g' => 0.7, 'c_100g' => 14.5, 'f_100g' => 0.3],
        'apple' => ['cals_pc' => 95, 'p_pc' => 0.5, 'c_pc' => 25.0, 'f_pc' => 0.3, 'cals_100g' => 52, 'p_100g' => 0.3, 'c_100g' => 13.8, 'f_100g' => 0.2],
        'pineapple' => ['cals_cup' => 82, 'p_cup' => 0.9, 'c_cup' => 22.0, 'f_cup' => 0.2, 'cals_100g' => 50, 'p_100g' => 0.5, 'c_100g' => 13.0, 'f_100g' => 0.1],
        'cabbage' => ['cals_100g' => 25, 'p_100g' => 1.3, 'c_100g' => 5.8, 'f_100g' => 0.1],
        'sitaw' => ['cals_100g' => 47, 'p_100g' => 2.8, 'c_100g' => 8.3, 'f_100g' => 0.4],
        'green bean' => ['cals_100g' => 35, 'p_100g' => 1.9, 'c_100g' => 7.0, 'f_100g' => 0.2],
        'spinach' => ['cals_100g' => 23, 'p_100g' => 2.9, 'c_100g' => 3.6, 'f_100g' => 0.4],
        'kangkong' => ['cals_100g' => 19, 'p_100g' => 2.6, 'c_100g' => 3.1, 'f_100g' => 0.2],
        'malunggay' => ['cals_cup' => 20, 'p_cup' => 2.0, 'c_cup' => 2.5, 'f_cup' => 0.3, 'cals_100g' => 64, 'p_100g' => 9.4, 'c_100g' => 8.3, 'f_100g' => 1.4],
        'eggplant' => ['cals_100g' => 25, 'p_100g' => 1.0, 'c_100g' => 5.9, 'f_100g' => 0.2],
        'talong' => ['cals_100g' => 25, 'p_100g' => 1.0, 'c_100g' => 5.9, 'f_100g' => 0.2],
        'tomato' => ['cals_pc' => 22, 'p_pc' => 1.1, 'c_pc' => 4.8, 'f_pc' => 0.2, 'cals_100g' => 18, 'p_100g' => 0.9, 'c_100g' => 3.9, 'f_100g' => 0.2],
        'cucumber' => ['cals_100g' => 15, 'p_100g' => 0.7, 'c_100g' => 3.6, 'f_100g' => 0.1],
        'broccoli' => ['cals_100g' => 34, 'p_100g' => 2.8, 'c_100g' => 6.6, 'f_100g' => 0.4],
        'asparagus' => ['cals_100g' => 20, 'p_100g' => 2.2, 'c_100g' => 3.9, 'f_100g' => 0.1],
        'mushroom' => ['cals_100g' => 22, 'p_100g' => 3.1, 'c_100g' => 3.3, 'f_100g' => 0.3],
        'radish' => ['cals_100g' => 16, 'p_100g' => 0.7, 'c_100g' => 3.4, 'f_100g' => 0.1],
        'labanos' => ['cals_100g' => 16, 'p_100g' => 0.7, 'c_100g' => 3.4, 'f_100g' => 0.1],
        'onion' => ['cals_pc' => 44, 'p_pc' => 1.2, 'c_pc' => 10.0, 'f_pc' => 0.1, 'cals_100g' => 40, 'p_100g' => 1.1, 'c_100g' => 9.3, 'f_100g' => 0.1],
        'shallot' => ['cals_tbsp' => 7, 'p_tbsp' => 0.2, 'c_tbsp' => 1.7, 'f_tbsp' => 0.0],
        'chili' => ['cals_pc' => 4, 'p_pc' => 0.2, 'c_pc' => 0.9, 'f_pc' => 0.0]
    ];

    // Find best match in database
    $matched = null;
    foreach ($db as $k => $info) {
        if (strpos($n, $k) !== false) {
            $matched = $info;
            break;
        }
    }

    if (!$matched) {
        // Fallback by broad food category
        if (preg_match('/(meat|pork|beef|fish|chicken|turkey|tuna|salmon)/', $n)) {
            $matched = ['cals_100g' => 150, 'p_100g' => 22.0, 'c_100g' => 0.0, 'f_100g' => 6.0];
        } elseif (preg_match('/(seed|nut|butter|oil)/', $n)) {
            $matched = ['cals_100g' => 550, 'p_100g' => 18.0, 'c_100g' => 20.0, 'f_100g' => 48.0, 'cals_tbsp' => 90, 'p_tbsp' => 3.0, 'c_tbsp' => 3.0, 'f_tbsp' => 8.0];
        } elseif (preg_match('/(vegetable|green|leaf|bean|sprout)/', $n)) {
            $matched = ['cals_100g' => 30, 'p_100g' => 2.0, 'c_100g' => 6.0, 'f_100g' => 0.2];
        } else {
            $matched = ['cals_100g' => 60, 'p_100g' => 2.0, 'c_100g' => 10.0, 'f_100g' => 1.0];
        }
    }

    // Calculate macros based on unit & amount
    $cals = 0; $pro = 0; $carbs = 0; $fat = 0;

    $isGrams = preg_match('/^g\b|^gram/i', $unit) || ($unit === 'g' || $unit === 'grams');

    if (!$isGrams && str_contains($unit, 'cup') && isset($matched['cals_cup'])) {
        $cals = $matched['cals_cup'] * $amt;
        $pro = ($matched['p_cup'] ?? 0) * $amt;
        $carbs = ($matched['c_cup'] ?? 0) * $amt;
        $fat = ($matched['f_cup'] ?? 0) * $amt;
    } elseif (!$isGrams && str_contains($unit, 'tbsp') && isset($matched['cals_tbsp'])) {
        $cals = $matched['cals_tbsp'] * $amt;
        $pro = ($matched['p_tbsp'] ?? 0) * $amt;
        $carbs = ($matched['c_tbsp'] ?? 0) * $amt;
        $fat = ($matched['f_tbsp'] ?? 0) * $amt;
    } elseif (!$isGrams && str_contains($unit, 'tsp') && isset($matched['cals_tsp'])) {
        $cals = $matched['cals_tsp'] * $amt;
        $pro = ($matched['p_tsp'] ?? 0) * $amt;
        $carbs = ($matched['c_tsp'] ?? 0) * $amt;
        $fat = ($matched['f_tsp'] ?? 0) * $amt;
    } elseif (!$isGrams && str_contains($unit, 'clove') && isset($matched['cals_clove'])) {
        $cals = $matched['cals_clove'] * $amt;
        $pro = ($matched['p_clove'] ?? 0) * $amt;
        $carbs = ($matched['c_clove'] ?? 0) * $amt;
        $fat = ($matched['f_clove'] ?? 0) * $amt;
    } elseif (!$isGrams && preg_match('/(pc|pcs|slice|slices|egg|cracker|crackers)/', $unit) && isset($matched['cals_pc'])) {
        $cals = $matched['cals_pc'] * $amt;
        $pro = ($matched['p_pc'] ?? 0) * $amt;
        $carbs = ($matched['c_pc'] ?? 0) * $amt;
        $fat = ($matched['f_pc'] ?? 0) * $amt;
    } elseif (!$isGrams && str_contains($unit, 'scoop') && isset($matched['cals'])) {
        $cals = $matched['cals'] * $amt;
        $pro = ($matched['p'] ?? 0) * $amt;
        $carbs = ($matched['c'] ?? 0) * $amt;
        $fat = ($matched['f'] ?? 0) * $amt;
    } else {
        // Grams / volume calculation
        $grams = 100.0;
        if (str_contains($unit, 'g') && !str_contains($unit, 'kg')) {
            $grams = $amt;
        } elseif (str_contains($unit, 'kg')) {
            $grams = $amt * 1000.0;
        } elseif (str_contains($unit, 'ml')) {
            $grams = $amt; // 1ml ~ 1g
        } elseif (str_contains($unit, 'oz')) {
            $grams = $amt * 28.35;
        } elseif (str_contains($unit, 'cup')) {
            $grams = $amt * 160.0;
        } elseif (str_contains($unit, 'tbsp')) {
            $grams = $amt * 15.0;
        } elseif (str_contains($unit, 'tsp')) {
            $grams = $amt * 5.0;
        } elseif (isset($matched['serving_g'])) {
            $grams = $amt * $matched['serving_g'];
        }

        $cals100 = $matched['cals_100g'] ?? 100;
        $p100 = $matched['p_100g'] ?? 5;
        $c100 = $matched['c_100g'] ?? 10;
        $f100 = $matched['f_100g'] ?? 2;

        $cals = ($cals100 * $grams) / 100.0;
        $pro = ($p100 * $grams) / 100.0;
        $carbs = ($c100 * $grams) / 100.0;
        $fat = ($f100 * $grams) / 100.0;
    }

    return [
        'calories' => max(0, (int)round($cals)),
        'protein_g' => max(0.0, round($pro, 1)),
        'carbs_g' => max(0.0, round($carbs, 1)),
        'fat_g' => max(0.0, round($fat, 1))
    ];
}

/**
 * Parses user input or stored database value (JSON or multi-line text).
 */
function parse_ingredients_data(string $raw): array
{
    // Try JSON first
    if ((str_starts_with($raw, '[') && str_ends_with($raw, ']')) || (str_starts_with($raw, '{') && str_ends_with($raw, '}'))) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            // Check if stored as {main: [], optional: []}
            if (isset($decoded['main']) || isset($decoded['all'])) {
                $items = array_merge($decoded['main'] ?? [], $decoded['optional'] ?? []);
                return format_ingredients_structure($items);
            }
            return format_ingredients_structure($decoded);
        }
    }

    // Otherwise, parse multi-line text (e.g. "Sitaw — 150 g" or "Firm tofu: 100g")
    $lines = preg_split('/[\r\n]+/', $raw);
    $items = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $isOptional = (bool) preg_match('/\b(optional|add-on|garnish)\b/i', $line);
        // Clean out parentheses like (optional)
        $cleanLine = preg_replace('/\s*[\(\[]\s*(optional|add-on|garnish)\s*[\)\]]/i', '', $line);

        $name = '';
        $measure = '';

        // Match "Name — 150 g" or "Name - 150g" or "Name: 150g"
        if (preg_match('/^(.+?)\s*(?:—|–|-|:)\s*(.+)$/u', $cleanLine, $matches)) {
            $name = trim($matches[1]);
            $measure = trim($matches[2]);
        } elseif (preg_match('/^(\d+[\/\d\.]*\s*(?:g|kg|ml|oz|tbsp|tsp|cup|cups|clove|cloves|pc|pcs|slice|slices|scoop|scoops|plate|bowl|strip|strips|can|cans))\s+(?:of\s+)?(.+)$/i', $cleanLine, $matches)) {
            // E.g., "150g Sitaw"
            $measure = trim($matches[1]);
            $name = trim($matches[2]);
        } else {
            $name = $cleanLine;
            $measure = '1 portion';
        }

        if ($name !== '') {
            $items[] = [
                'name'        => $name,
                'measure'     => $measure,
                'is_optional' => $isOptional,
                'type'        => $isOptional ? 'optional' : 'main'
            ];
        }
    }

    return format_ingredients_structure($items);
}

/**
 * Formats ingredients list back to clean multi-line text for the edit textarea.
 */
function format_ingredients_for_textarea(array|string|null $ingredients, ?string $foodName = null): string
{
    if (is_string($ingredients) && !str_starts_with(trim($ingredients), '[') && !str_starts_with(trim($ingredients), '{')) {
        return trim($ingredients);
    }

    if (empty($ingredients) && !empty($foodName)) {
        $struct = get_food_ingredients(['name' => $foodName, 'ingredients' => null]);
        $ingredients = $struct['all'];
    } elseif (is_array($ingredients) && (isset($ingredients['all']) || isset($ingredients['main']))) {
        $ingredients = array_merge($ingredients['main'] ?? [], $ingredients['optional'] ?? []);
    } elseif (is_string($ingredients)) {
        $struct = parse_ingredients_data($ingredients);
        $ingredients = $struct['all'];
    }

    if (!is_array($ingredients)) {
        return '';
    }

    $lines = [];
    foreach ($ingredients as $item) {
        $name = trim((string) ($item['name'] ?? ''));
        if ($name === '') continue;

        $measure = trim((string) ($item['measure'] ?? ($item['amount'] ?? '') . ' ' . ($item['unit'] ?? '')));
        if ($measure === '') $measure = '1 serving';

        $isOpt = !empty($item['is_optional']);
        $line = "{$name} — {$measure}" . ($isOpt ? ' (optional)' : '');
        $lines[] = $line;
    }

    return implode("\n", $lines);
}

/**
 * Intelligent fallback recipe decomposition if no custom ingredients exist.
 */
function fallback_parse_ingredients(array $food): array
{
    $name = (string) ($food['name'] ?? '');
    $desc = (string) ($food['recipe_desc'] ?? '');
    $serving = (string) ($food['serving_size'] ?? '1 serving');

    $main = [];
    $optional = [];

    // Parse dish components from parenthesis e.g. "Tapsilog (Beef Tapa, Garlic Rice, Egg)"
    if (preg_match('/\((.+?)\)/', $name, $matches)) {
        $parts = explode(',', $matches[1]);
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $main[] = [
                    'name'        => $p,
                    'measure'     => '1 portion',
                    'type'        => 'main',
                    'is_optional' => false
                ];
            }
        }
    }

    if (empty($main)) {
        // Use clean base name
        $cleanName = preg_replace('/\s*\(.*?\)/', '', $name);
        $parts = preg_split('/\s+(?:with|&|\+)\s+/i', $cleanName);
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $main[] = [
                    'name'        => $p,
                    'measure'     => '1 portion',
                    'type'        => 'main',
                    'is_optional' => false
                ];
            }
        }
    }

    // Default seasoning / add-on
    $optional[] = [
        'name'        => 'Seasoning & cooking oil',
        'measure'     => '1 tsp',
        'type'        => 'optional',
        'is_optional' => true
    ];

    return [
        'main'     => $main,
        'optional' => $optional,
        'all'      => array_merge($main, $optional)
    ];
}

/**
 * Master library of staple dishes and verified ingredient compositions.
 */
function get_staple_recipes_ingredients(): array
{
    return [
        // ── Breakfast ──────────────────────────────────────────────────────────
        'Tapsilog (Beef Tapa, Garlic Brown Rice, Fried Egg)' => [
            ['name' => 'Cured beef sirloin tapa', 'amount' => '150', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Garlic brown rice', 'amount' => '1', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Sunny-side up egg', 'amount' => '1', 'unit' => 'pc', 'type' => 'main'],
            ['name' => 'Minced garlic', 'amount' => '3', 'unit' => 'cloves', 'type' => 'optional'],
            ['name' => 'Olive oil', 'amount' => '1', 'unit' => 'tsp', 'type' => 'optional'],
            ['name' => 'Sliced fresh tomatoes', 'amount' => '4', 'unit' => 'slices', 'type' => 'optional'],
            ['name' => 'Spiced cane vinegar dip', 'amount' => '1', 'unit' => 'tbsp', 'type' => 'optional'],
        ],
        'Bangsilog (Grilled Boneless Milkfish, Garlic Rice, Egg)' => [
            ['name' => 'Marinated boneless milkfish (bangus)', 'amount' => '180', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Garlic brown rice', 'amount' => '1', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Sunny-side up egg', 'amount' => '1', 'unit' => 'pc', 'type' => 'main'],
            ['name' => 'Garlic cloves', 'amount' => '3', 'unit' => 'cloves', 'type' => 'optional'],
            ['name' => 'Fresh calamansi', 'amount' => '2', 'unit' => 'pcs', 'type' => 'optional'],
            ['name' => 'Sliced tomatoes', 'amount' => '4', 'unit' => 'slices', 'type' => 'optional'],
        ],
        'Chicken Longsilog (Skinless Chicken Longganisa, Rice, Egg)' => [
            ['name' => 'Homemade skinless chicken longganisa', 'amount' => '150', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Garlic brown rice', 'amount' => '1', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Farm egg', 'amount' => '1', 'unit' => 'pc', 'type' => 'main'],
            ['name' => 'Garlic & crushed black pepper', 'amount' => '1', 'unit' => 'tsp', 'type' => 'optional'],
            ['name' => 'Cucumber slices', 'amount' => '4', 'unit' => 'slices', 'type' => 'optional'],
            ['name' => 'Spiced vinegar dip', 'amount' => '1', 'unit' => 'tbsp', 'type' => 'optional'],
        ],
        'Tortang Talong (Eggplant Omelet) with Garlic Rice' => [
            ['name' => 'Smoky roasted eggplant (talong)', 'amount' => '150', 'unit' => 'g (1 large)', 'type' => 'main'],
            ['name' => 'Whisked whole eggs', 'amount' => '2', 'unit' => 'pcs', 'type' => 'main'],
            ['name' => 'Garlic brown rice', 'amount' => '3/4', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Olive oil', 'amount' => '1', 'unit' => 'tsp', 'type' => 'optional'],
            ['name' => 'Pink Himalayan salt & pepper', 'amount' => '1', 'unit' => 'pinch', 'type' => 'optional'],
            ['name' => 'Fresh tomato slices', 'amount' => '3', 'unit' => 'slices', 'type' => 'optional'],
        ],
        'Tofu Scramble Adobo Style with Garlic Rice' => [
            ['name' => 'Crumbled firm organic tofu', 'amount' => '150', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Garlic brown rice', 'amount' => '3/4', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Baby spinach or kangkong', 'amount' => '50', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Minced garlic', 'amount' => '3', 'unit' => 'cloves', 'type' => 'optional'],
            ['name' => 'Low-sodium tamari / soy sauce', 'amount' => '1', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Cane vinegar', 'amount' => '1', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Nutritional yeast', 'amount' => '1', 'unit' => 'tsp', 'type' => 'optional'],
        ],
        'Oatmeal Protein Champorado with Chia & Almond Butter' => [
            ['name' => 'Rolled oats', 'amount' => '50', 'unit' => 'g (1/2 cup)', 'type' => 'main'],
            ['name' => 'Chocolate whey protein isolate', 'amount' => '30', 'unit' => 'g (1 scoop)', 'type' => 'main'],
            ['name' => 'Pure dark tablea cocoa powder', 'amount' => '1.5', 'unit' => 'tbsp', 'type' => 'main'],
            ['name' => 'Unsweetened almond milk', 'amount' => '1', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Chia seeds', 'amount' => '1', 'unit' => 'tsp', 'type' => 'optional'],
            ['name' => 'Creamy roasted almond butter', 'amount' => '1', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Stevia / natural sweetener', 'amount' => 'to taste', 'unit' => '', 'type' => 'optional'],
        ],
        'Keto Bacon, Spinach & Cheddar 3-Egg Omelet with Avocado' => [
            ['name' => 'Whole farm eggs', 'amount' => '3', 'unit' => 'pcs', 'type' => 'main'],
            ['name' => 'Fresh baby spinach', 'amount' => '50', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Aged cheddar cheese (shredded)', 'amount' => '30', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Smoked bacon strips', 'amount' => '2', 'unit' => 'slices (30 g)', 'type' => 'main'],
            ['name' => 'Fresh Hass avocado', 'amount' => '1/2', 'unit' => 'pc (75 g)', 'type' => 'main'],
            ['name' => 'Grass-fed butter', 'amount' => '1', 'unit' => 'tsp', 'type' => 'optional'],
            ['name' => 'Cracked black pepper', 'amount' => '1', 'unit' => 'pinch', 'type' => 'optional'],
        ],
        'Smoked Salmon Avocado Sourdough Toast with Soft Eggs' => [
            ['name' => 'Wild smoked salmon fillet slices', 'amount' => '80', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Artisan sourdough bread', 'amount' => '2', 'unit' => 'slices (70 g)', 'type' => 'main'],
            ['name' => 'Ripe Hass avocado (mashed)', 'amount' => '1/2', 'unit' => 'pc (75 g)', 'type' => 'main'],
            ['name' => 'Soft-boiled eggs', 'amount' => '2', 'unit' => 'pcs', 'type' => 'main'],
            ['name' => 'Fresh lemon juice', 'amount' => '1', 'unit' => 'tsp', 'type' => 'optional'],
            ['name' => 'Fresh dill / microgreens', 'amount' => '1', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Red chili flakes', 'amount' => '1', 'unit' => 'pinch', 'type' => 'optional'],
        ],
        'Gluten-Free Banana Oat Protein Pancakes' => [
            ['name' => 'Gluten-free rolled oats (blended)', 'amount' => '50', 'unit' => 'g (1/2 cup)', 'type' => 'main'],
            ['name' => 'Ripe banana (mashed)', 'amount' => '1', 'unit' => 'medium pc', 'type' => 'main'],
            ['name' => 'Vanilla whey protein', 'amount' => '25', 'unit' => 'g (1 scoop)', 'type' => 'main'],
            ['name' => 'Egg whites', 'amount' => '2', 'unit' => 'pcs (60 ml)', 'type' => 'main'],
            ['name' => 'Baking powder', 'amount' => '1/2', 'unit' => 'tsp', 'type' => 'optional'],
            ['name' => 'Ground cinnamon', 'amount' => '1/2', 'unit' => 'tsp', 'type' => 'optional'],
            ['name' => 'Sugar-free maple syrup', 'amount' => '1', 'unit' => 'tbsp', 'type' => 'optional'],
        ],
        'Tinapasilog (Smoked Fish, Garlic Rice, Egg)' => [
            ['name' => 'Smoked fish fillet (tinapa)', 'amount' => '150', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Garlic brown rice', 'amount' => '1', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Sunny-side up egg', 'amount' => '1', 'unit' => 'pc', 'type' => 'main'],
            ['name' => 'Sliced fresh tomatoes', 'amount' => '1', 'unit' => 'medium pc', 'type' => 'optional'],
            ['name' => 'Spiced cane vinegar dip', 'amount' => '1', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Calamansi', 'amount' => '1', 'unit' => 'pc', 'type' => 'optional'],
        ],

        // ── Lunch ─────────────────────────────────────────────────────────────
        'Chicken Breast Adobo with Garlic Brown Rice & Steamed Cabbage' => [
            ['name' => 'Skinless chicken breast', 'amount' => '200', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Garlic brown rice (cooked)', 'amount' => '1', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Steamed green cabbage wedges', 'amount' => '100', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Fresh garlic', 'amount' => '4', 'unit' => 'cloves', 'type' => 'optional'],
            ['name' => 'Low-sodium soy sauce', 'amount' => '2', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Cane vinegar', 'amount' => '2', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Dried bay leaves', 'amount' => '2', 'unit' => 'pcs', 'type' => 'optional'],
            ['name' => 'Whole black peppercorns', 'amount' => '1', 'unit' => 'tsp', 'type' => 'optional'],
        ],
        'Sinigang na Hipon (Shrimp & Water Spinach in Tamarind Broth)' => [
            ['name' => 'Fresh peeled shrimp', 'amount' => '180', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Water spinach (kangkong)', 'amount' => '100', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'White radish (labanos) slices', 'amount' => '60', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Steamed brown rice', 'amount' => '3/4', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Ripe native tomatoes', 'amount' => '1', 'unit' => 'medium pc', 'type' => 'optional'],
            ['name' => 'Red onion wedges', 'amount' => '1/2', 'unit' => 'pc', 'type' => 'optional'],
            ['name' => 'Tamarind broth base', 'amount' => '2', 'unit' => 'cups', 'type' => 'optional'],
            ['name' => 'Green finger chili (siling haba)', 'amount' => '1', 'unit' => 'pc', 'type' => 'optional'],
        ],
        'Ginisang Munggo (Mung Bean Stew) with Crispy Tofu & Brown Rice' => [
            ['name' => 'Whole green mung beans (munggo)', 'amount' => '80', 'unit' => 'g (1/2 cup)', 'type' => 'main'],
            ['name' => 'Firm tofu (crispy seared cubes)', 'amount' => '100', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Moringa leaves (malunggay)', 'amount' => '1', 'unit' => 'cup (30 g)', 'type' => 'main'],
            ['name' => 'Steamed brown rice', 'amount' => '3/4', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Minced garlic & sliced onions', 'amount' => '3', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Ripe diced tomatoes', 'amount' => '1', 'unit' => 'medium pc', 'type' => 'optional'],
            ['name' => 'Olive oil', 'amount' => '1', 'unit' => 'tsp', 'type' => 'optional'],
        ],
        'Grilled Chicken Inasal with Brown Rice & Pickled Papaya' => [
            ['name' => 'Chicken breast / leg quarter', 'amount' => '200', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Steamed brown rice', 'amount' => '1', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Pickled green papaya (atchara)', 'amount' => '40', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Calamansi & lemongrass marinade', 'amount' => '2', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Annatto basting oil', 'amount' => '1', 'unit' => 'tsp', 'type' => 'optional'],
            ['name' => 'Sinamak spiced vinegar dip', 'amount' => '1', 'unit' => 'tbsp', 'type' => 'optional'],
        ],
        'Grilled Salmon Teriyaki Bowl with Quinoa & Edamame' => [
            ['name' => 'Atlantic salmon fillet', 'amount' => '180', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Cooked quinoa', 'amount' => '3/4', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Steamed shelled edamame', 'amount' => '1/2', 'unit' => 'cup (75 g)', 'type' => 'main'],
            ['name' => 'Low-sodium teriyaki glaze', 'amount' => '1.5', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Toasted sesame seeds', 'amount' => '1', 'unit' => 'tsp', 'type' => 'optional'],
            ['name' => 'Sliced green scallions', 'amount' => '1', 'unit' => 'tbsp', 'type' => 'optional'],
        ],
        'Adobong Sitaw & Firm Tofu with Quinoa' => [
            ['name' => 'Sitaw / Yard-long beans', 'amount' => '150', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Firm tofu (pressed & cubed)', 'amount' => '100', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Cooked quinoa', 'amount' => '1/2', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Garlic', 'amount' => '2', 'unit' => 'cloves', 'type' => 'optional'],
            ['name' => 'Low-sodium soy sauce', 'amount' => '1', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Cane vinegar', 'amount' => '1', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Cooking oil', 'amount' => '1', 'unit' => 'tsp', 'type' => 'optional'],
            ['name' => 'Ground black pepper', 'amount' => '1', 'unit' => 'pinch', 'type' => 'optional'],
        ],
        'Keto Grilled Pork Tenderloin with Ensaladang Talong' => [
            ['name' => 'Lean pork tenderloin (grilled)', 'amount' => '200', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Roasted charred eggplant (talong)', 'amount' => '150', 'unit' => 'g (1 large)', 'type' => 'main'],
            ['name' => 'Diced red onion & ripe tomatoes', 'amount' => '3', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Calamansi juice & cane vinegar', 'amount' => '1.5', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Extra virgin olive oil', 'amount' => '1', 'unit' => 'tsp', 'type' => 'optional'],
            ['name' => 'Sea salt & ground pepper', 'amount' => '1', 'unit' => 'pinch', 'type' => 'optional'],
        ],
        'Lean Beef Picadillo with Diced Potatoes & Peas' => [
            ['name' => '90/10 extra lean ground beef', 'amount' => '180', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Diced russet potatoes', 'amount' => '60', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Sweet green peas', 'amount' => '40', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Steamed brown rice', 'amount' => '3/4', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Diced carrots', 'amount' => '30', 'unit' => 'g', 'type' => 'optional'],
            ['name' => 'Crushed tomatoes & garlic', 'amount' => '1/2', 'unit' => 'cup', 'type' => 'optional'],
            ['name' => 'Olive oil', 'amount' => '1', 'unit' => 'tsp', 'type' => 'optional'],
        ],
        'Chicken Tinola (Ginger Chicken Broth with Sayote & Malunggay)' => [
            ['name' => 'Skinless chicken breast chunks', 'amount' => '200', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Green sayote / chayote wedges', 'amount' => '100', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Moringa leaves (malunggay)', 'amount' => '1', 'unit' => 'cup (30 g)', 'type' => 'main'],
            ['name' => 'Steamed brown rice', 'amount' => '3/4', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Fresh ginger root (julienned)', 'amount' => '20', 'unit' => 'g', 'type' => 'optional'],
            ['name' => 'Sliced red onion & garlic', 'amount' => '2', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Nourishing clear broth', 'amount' => '2', 'unit' => 'cups', 'type' => 'optional'],
        ],
        'Pinakbet with Grilled Fish & Steamed Rice' => [
            ['name' => 'Grilled round scad (galunggong)', 'amount' => '150', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Kabocha squash (kalabasa)', 'amount' => '80', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Eggplant & okra slices', 'amount' => '80', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'String beans (sitaw)', 'amount' => '50', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Steamed brown rice', 'amount' => '3/4', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Sautéed garlic, onions & tomatoes', 'amount' => '3', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Low-sodium seasoning broth', 'amount' => '1', 'unit' => 'tbsp', 'type' => 'optional'],
        ],

        // ── Dinner ────────────────────────────────────────────────────────────
        'Inihaw na Bangus (Grilled Milkfish stuffed with Tomatoes & Onions)' => [
            ['name' => 'Boneless milkfish (bangus)', 'amount' => '220', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Steamed brown rice', 'amount' => '1', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Diced sweet onions & ripe tomatoes', 'amount' => '1', 'unit' => 'cup', 'type' => 'optional'],
            ['name' => 'Ginger slices & fresh calamansi', 'amount' => '2', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Banana leaf wrapper for grilling', 'amount' => '1', 'unit' => 'sheet', 'type' => 'optional'],
            ['name' => 'Soy-calamansi dipping sauce', 'amount' => '1', 'unit' => 'tbsp', 'type' => 'optional'],
        ],
        'Chicken Inasal Skewers with Cucumber Tomato Salad' => [
            ['name' => 'Skinless chicken breast skewers', 'amount' => '220', 'unit' => 'g (3 skewers)', 'type' => 'main'],
            ['name' => 'Crisp cucumber slices', 'amount' => '1', 'unit' => 'cup (80 g)', 'type' => 'main'],
            ['name' => 'Cherry tomatoes (halved)', 'amount' => '1/2', 'unit' => 'cup (50 g)', 'type' => 'main'],
            ['name' => 'Calamansi & garlic marinade', 'amount' => '2', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Light olive oil vinaigrette', 'amount' => '1', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Chopped fresh cilantro / parsley', 'amount' => '1', 'unit' => 'tsp', 'type' => 'optional'],
        ],
        'Salmon Sinigang (Salmon Head/Fillet in Sour Tamarind Broth)' => [
            ['name' => 'Fresh Atlantic salmon fillet/collar', 'amount' => '180', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Mustard greens (mustasa) or bok choy', 'amount' => '80', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'White radish & string beans', 'amount' => '60', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Steamed brown rice', 'amount' => '3/4', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Fresh tomatoes & red onion', 'amount' => '1', 'unit' => 'medium pc each', 'type' => 'optional'],
            ['name' => 'Natural tamarind soup base', 'amount' => '2', 'unit' => 'cups', 'type' => 'optional'],
            ['name' => 'Green finger chili', 'amount' => '1', 'unit' => 'pc', 'type' => 'optional'],
        ],
        'Vegetable Pinakbet with Air-Fried Tofu & Brown Rice' => [
            ['name' => 'Air-fried golden tofu cubes', 'amount' => '120', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Kabocha squash (kalabasa)', 'amount' => '80', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Bitter melon (ampalaya) & eggplant', 'amount' => '70', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Steamed brown rice', 'amount' => '3/4', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Garlic, shallots & native tomatoes', 'amount' => '3', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Low-sodium soy sauce & water', 'amount' => '1/2', 'unit' => 'cup', 'type' => 'optional'],
        ],
        'Laing (Taro Leaves in Spicy Coconut Milk with Shrimp)' => [
            ['name' => 'Dried taro leaves (dahon ng gabi)', 'amount' => '50', 'unit' => 'g dry', 'type' => 'main'],
            ['name' => 'Fresh peeled shrimp', 'amount' => '120', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Light coconut milk', 'amount' => '3/4', 'unit' => 'cup (150 ml)', 'type' => 'main'],
            ['name' => 'Steamed brown rice', 'amount' => '3/4', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Bird\'s eye chili (siling labuyo)', 'amount' => '2', 'unit' => 'pcs', 'type' => 'optional'],
            ['name' => 'Ginger strips & minced garlic', 'amount' => '2', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Lemongrass knot', 'amount' => '1', 'unit' => 'stalk', 'type' => 'optional'],
        ],
        'Keto Grilled Ribeye Steak with Garlic Butter Mushrooms & Asparagus' => [
            ['name' => 'Grass-fed ribeye steak', 'amount' => '250', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Tender asparagus spears', 'amount' => '100', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Sliced button mushrooms', 'amount' => '80', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Grass-fed garlic herb butter', 'amount' => '1.5', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Flaked sea salt & fresh rosemary', 'amount' => '1', 'unit' => 'pinch', 'type' => 'optional'],
        ],
        'Gluten-Free Chicken Arroz Caldo with Hard Boiled Egg' => [
            ['name' => 'Shredded chicken breast', 'amount' => '150', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Brown rice porridge (arroz)', 'amount' => '80', 'unit' => 'g dry', 'type' => 'main'],
            ['name' => 'Hard-boiled egg', 'amount' => '1', 'unit' => 'large pc', 'type' => 'main'],
            ['name' => 'Fresh ginger root (julienned)', 'amount' => '2', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Golden toasted garlic chips', 'amount' => '1', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Chopped green scallions & calamansi', 'amount' => '2', 'unit' => 'tbsp', 'type' => 'optional'],
        ],
        'Lean Beef Bistek Tagalog with Onion Rings & Brown Rice' => [
            ['name' => 'Thinly sliced top round beef sirloin', 'amount' => '180', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Sweet white onion rings', 'amount' => '100', 'unit' => 'g (1 onion)', 'type' => 'main'],
            ['name' => 'Steamed brown rice', 'amount' => '1', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Fresh calamansi juice marinade', 'amount' => '2', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Low-sodium soy sauce', 'amount' => '2', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Cracked black peppercorns & olive oil', 'amount' => '1', 'unit' => 'tsp', 'type' => 'optional'],
        ],
        'Grilled Tuna Belly with Steamed Brown Rice & Ensalada' => [
            ['name' => 'Yellowfin tuna belly steak', 'amount' => '200', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Steamed brown rice', 'amount' => '1', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Cucumber & tomato ensalada', 'amount' => '80', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Soy-calamansi marinade glaze', 'amount' => '2', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Sliced fresh red chilies', 'amount' => '1', 'unit' => 'pc', 'type' => 'optional'],
        ],
        'Vegan Bicol Express with Tofu, Eggplant & Coconut Milk' => [
            ['name' => 'Firm tofu (seared cubes)', 'amount' => '140', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Native eggplant wedges', 'amount' => '100', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Long green finger chilies (siling haba)', 'amount' => '50', 'unit' => 'g', 'type' => 'main'],
            ['name' => 'Light coconut cream', 'amount' => '1/2', 'unit' => 'cup (120 ml)', 'type' => 'main'],
            ['name' => 'Steamed brown rice', 'amount' => '3/4', 'unit' => 'cup', 'type' => 'main'],
            ['name' => 'Minced garlic, shallots & ginger', 'amount' => '2', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Miso or salted black bean paste', 'amount' => '1', 'unit' => 'tsp', 'type' => 'optional'],
        ],

        // ── Snacks ────────────────────────────────────────────────────────────
        'Boiled Saba Banana with Natural Peanut Butter' => [
            ['name' => 'Native saba bananas (steamed)', 'amount' => '2', 'unit' => 'pcs (150 g)', 'type' => 'main'],
            ['name' => '100% natural roasted peanut butter', 'amount' => '1', 'unit' => 'tbsp (16 g)', 'type' => 'main'],
            ['name' => 'Ceylon cinnamon dust', 'amount' => '1', 'unit' => 'pinch', 'type' => 'optional'],
        ],
        'Boiled Kamote (Sweet Potato) with Cinnamon' => [
            ['name' => 'Steamed purple / yellow sweet potato (kamote)', 'amount' => '150', 'unit' => 'g (1 root)', 'type' => 'main'],
            ['name' => 'Ground Ceylon cinnamon', 'amount' => '1/2', 'unit' => 'tsp', 'type' => 'optional'],
            ['name' => 'Flaked sea salt', 'amount' => '1', 'unit' => 'pinch', 'type' => 'optional'],
        ],
        'Whey Protein Shake with Skim Milk & Half Banana' => [
            ['name' => 'Whey protein isolate powder', 'amount' => '30', 'unit' => 'g (1 scoop)', 'type' => 'main'],
            ['name' => 'Cold skim milk / almond milk', 'amount' => '300', 'unit' => 'ml', 'type' => 'main'],
            ['name' => 'Ripe banana', 'amount' => '1/2', 'unit' => 'pc (50 g)', 'type' => 'main'],
            ['name' => 'Ice cubes', 'amount' => '4-5', 'unit' => 'cubes', 'type' => 'optional'],
            ['name' => 'Cinnamon dust', 'amount' => '1', 'unit' => 'pinch', 'type' => 'optional'],
        ],
        'Hard-Boiled Eggs with Pink Himalayan Salt & Black Pepper' => [
            ['name' => 'Free-range large eggs (hard-boiled)', 'amount' => '2', 'unit' => 'large pcs', 'type' => 'main'],
            ['name' => 'Pink Himalayan mineral salt', 'amount' => '1', 'unit' => 'pinch', 'type' => 'optional'],
            ['name' => 'Freshly cracked black pepper', 'amount' => '1', 'unit' => 'pinch', 'type' => 'optional'],
        ],
        'Plain Greek Yogurt with Blueberries & Crushed Almonds' => [
            ['name' => 'Plain thick strained Greek yogurt', 'amount' => '180', 'unit' => 'g (1 cup)', 'type' => 'main'],
            ['name' => 'Fresh blueberries', 'amount' => '75', 'unit' => 'g (1/2 cup)', 'type' => 'main'],
            ['name' => 'Raw crushed almonds', 'amount' => '15', 'unit' => 'g (1 tbsp)', 'type' => 'main'],
            ['name' => 'Pure honey or stevia', 'amount' => '1', 'unit' => 'tsp', 'type' => 'optional'],
        ],
        'Steamed Edamame in Pods with Sea Salt' => [
            ['name' => 'Whole green edamame pods (steamed)', 'amount' => '150', 'unit' => 'g (1 cup)', 'type' => 'main'],
            ['name' => 'Flaked sea salt', 'amount' => '1/2', 'unit' => 'tsp', 'type' => 'optional'],
            ['name' => 'Toasted sesame oil drops', 'amount' => '2', 'unit' => 'drops', 'type' => 'optional'],
        ],
        'Crispy Baked Pork Rinds (Chicharon) with Vinegar Dip' => [
            ['name' => 'Oven-baked crispy pork rinds (chicharon)', 'amount' => '40', 'unit' => 'g bag', 'type' => 'main'],
            ['name' => 'Spiced garlic cane vinegar', 'amount' => '2', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Crushed garlic clove', 'amount' => '1', 'unit' => 'clove', 'type' => 'optional'],
        ],
        'Apple Slices with Crunchy Almond Butter' => [
            ['name' => 'Crisp Fuji / Gala apple wedges', 'amount' => '150', 'unit' => 'g (1 apple)', 'type' => 'main'],
            ['name' => '100% natural crunchy almond butter', 'amount' => '16', 'unit' => 'g (1 tbsp)', 'type' => 'main'],
            ['name' => 'Cinnamon powder', 'amount' => '1', 'unit' => 'pinch', 'type' => 'optional'],
        ],
        'Tuna Salad on Whole Wheat Crackers' => [
            ['name' => 'Chunk light tuna in water (drained)', 'amount' => '120', 'unit' => 'g (1 can)', 'type' => 'main'],
            ['name' => 'Whole grain wheat crackers', 'amount' => '4', 'unit' => 'crackers (30 g)', 'type' => 'main'],
            ['name' => 'Light mayonnaise or Greek yogurt', 'amount' => '1', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Diced crisp celery & red onion', 'amount' => '2', 'unit' => 'tbsp', 'type' => 'optional'],
            ['name' => 'Lemon juice & cracked pepper', 'amount' => '1', 'unit' => 'tsp', 'type' => 'optional'],
        ],
        'Cottage Cheese with Fresh Pineapple Chunks' => [
            ['name' => 'Low-fat curd cottage cheese', 'amount' => '200', 'unit' => 'g (1 cup)', 'type' => 'main'],
            ['name' => 'Fresh sweet pineapple chunks', 'amount' => '80', 'unit' => 'g (1/2 cup)', 'type' => 'main'],
            ['name' => 'Fresh mint leaf sprig', 'amount' => '1', 'unit' => 'pc', 'type' => 'optional'],
        ]
    ];
}
