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
