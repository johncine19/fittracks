<?php
declare(strict_types=1);

function get_swap_meal_catalog(): array
{
    return [
        'Breakfast' => [
            [
                'id' => 'brk_1',
                'title' => 'Tapsilog (Lean Beef Tapa, Garlic Brown Rice, Sunny-Side Egg)',
                'tags' => ['High Protein', 'Filipino Fit', 'Gluten-Free Safe'],
                'restrictions' => ['none', 'halal', 'gluten-free', 'dairy-free'],
                'pro_pct' => 0.35, 'carbs_pct' => 0.45, 'fat_pct' => 0.20,
                'desc' => 'Tender lean cured beef with fragrant garlic brown rice and a sunny-side egg.'
            ],
            [
                'id' => 'brk_2',
                'title' => 'Bangsilog (Marinated Grilled Milkfish, Garlic Rice, Poached Egg)',
                'tags' => ['Pescatarian', 'High Omega-3', 'Gluten-Free'],
                'restrictions' => ['none', 'pescatarian', 'gluten-free', 'dairy-free', 'halal'],
                'pro_pct' => 0.35, 'carbs_pct' => 0.45, 'fat_pct' => 0.20,
                'desc' => 'Grilled Filipino bangus belly seasoned with garlic and vinegar, served with garlic rice.'
            ],
            [
                'id' => 'brk_3',
                'title' => 'Tortang Talong (Grilled Eggplant Omelet) with Brown Rice',
                'tags' => ['Vegetarian', 'Fiber Rich', 'Gluten-Free'],
                'restrictions' => ['none', 'vegetarian', 'gluten-free', 'halal'],
                'pro_pct' => 0.25, 'carbs_pct' => 0.50, 'fat_pct' => 0.25,
                'desc' => 'Smoky roasted eggplant dipped in whisked eggs, pan-seared with olive oil and brown rice.'
            ],
            [
                'id' => 'brk_4',
                'title' => 'Tofu Scramble Adobo Style with Garlic Cauliflower & Brown Rice',
                'tags' => ['Vegan', 'Plant Protein', 'Dairy-Free'],
                'restrictions' => ['none', 'vegetarian', 'vegan', 'dairy-free', 'gluten-free', 'halal'],
                'pro_pct' => 0.30, 'carbs_pct' => 0.50, 'fat_pct' => 0.20,
                'desc' => 'Crumbled firm tofu seasoned with soy sauce, garlic, and vinegar over brown rice.'
            ],
            [
                'id' => 'brk_5',
                'title' => 'Oatmeal Protein Champorado with Chia Seeds & Almond Butter',
                'tags' => ['High Fiber', 'Vegetarian', 'Sustained Energy'],
                'restrictions' => ['none', 'vegetarian', 'dairy-free', 'halal'],
                'pro_pct' => 0.25, 'carbs_pct' => 0.55, 'fat_pct' => 0.20,
                'desc' => 'Warm whole rolled oats simmered with pure tablea cocoa, whey or plant protein, and chia.'
            ],
            [
                'id' => 'brk_6',
                'title' => 'Keto Bacon, Spinach & Cheddar 3-Egg Omelet with Avocado',
                'tags' => ['Keto', 'Low Carb', 'High Fat'],
                'restrictions' => ['none', 'keto', 'gluten-free'],
                'pro_pct' => 0.30, 'carbs_pct' => 0.05, 'fat_pct' => 0.65,
                'desc' => 'Fluffy 3-egg omelet folded with baby spinach, aged cheddar, uncured bacon, and sliced avocado.'
            ],
            [
                'id' => 'brk_7',
                'title' => 'Chicken Longsilog (Skinless Lean Chicken Longganisa, Garlic Rice, Egg)',
                'tags' => ['High Protein', 'Halal', 'Filipino Fit'],
                'restrictions' => ['none', 'halal', 'dairy-free'],
                'pro_pct' => 0.38, 'carbs_pct' => 0.42, 'fat_pct' => 0.20,
                'desc' => 'Lean ground chicken breast cured with garlic and spices, pan-grilled with garlic brown rice.'
            ],
            [
                'id' => 'brk_8',
                'title' => 'Smoked Salmon Avocado Sourdough Toast with Soft Boiled Eggs',
                'tags' => ['Pescatarian', 'High Protein', 'Healthy Fats'],
                'restrictions' => ['none', 'pescatarian', 'dairy-free', 'halal'],
                'pro_pct' => 0.32, 'carbs_pct' => 0.45, 'fat_pct' => 0.23,
                'desc' => 'Wild smoked salmon on toasted artisanal sourdough with smashed avocado and eggs.'
            ],
        ],
        'Lunch' => [
            [
                'id' => 'lch_1',
                'title' => 'Chicken Breast Adobo with Garlic Brown Rice & Steamed Cabbage',
                'tags' => ['High Protein', 'Filipino Classic', 'Meal Prep'],
                'restrictions' => ['none', 'halal', 'dairy-free', 'gluten-free'],
                'pro_pct' => 0.40, 'carbs_pct' => 0.40, 'fat_pct' => 0.20,
                'desc' => 'Skinless chicken breast braised in soy, garlic, and vinegar with fiber-rich brown rice and greens.'
            ],
            [
                'id' => 'lch_2',
                'title' => 'Sinigang na Hipon (Shrimp & Water Spinach in Tamarind Broth) with Rice',
                'tags' => ['Pescatarian', 'Low Fat', 'Hydrating'],
                'restrictions' => ['none', 'pescatarian', 'gluten-free', 'dairy-free', 'halal'],
                'pro_pct' => 0.35, 'carbs_pct' => 0.50, 'fat_pct' => 0.15,
                'desc' => 'Succulent wild shrimp simmered in sour tamarind broth with kangkong, radish, and steamed rice.'
            ],
            [
                'id' => 'lch_3',
                'title' => 'Ginisang Munggo (Mung Bean Stew) with Crispy Tofu & Brown Rice',
                'tags' => ['Vegetarian', 'Vegan', 'High Fiber'],
                'restrictions' => ['none', 'vegetarian', 'vegan', 'dairy-free', 'halal', 'gluten-free'],
                'pro_pct' => 0.28, 'carbs_pct' => 0.55, 'fat_pct' => 0.17,
                'desc' => 'Nutritious mung bean stew simmered with moringa leaves, topped with air-fried golden tofu.'
            ],
            [
                'id' => 'lch_4',
                'title' => 'Grilled Chicken Inasal with Brown Rice & Atchara (Pickled Papaya)',
                'tags' => ['High Protein', 'Halal', 'Low Fat'],
                'restrictions' => ['none', 'halal', 'dairy-free', 'gluten-free'],
                'pro_pct' => 0.42, 'carbs_pct' => 0.40, 'fat_pct' => 0.18,
                'desc' => 'Char-grilled chicken marinated in calamansi, lemongrass, and annatto with brown rice.'
            ],
            [
                'id' => 'lch_5',
                'title' => 'Grilled Salmon Teriyaki Bowl with Quinoa, Edamame & Avocado',
                'tags' => ['Pescatarian', 'High Omega-3', 'Clean Eating'],
                'restrictions' => ['none', 'pescatarian', 'gluten-free', 'dairy-free', 'halal'],
                'pro_pct' => 0.36, 'carbs_pct' => 0.38, 'fat_pct' => 0.26,
                'desc' => 'Seared Atlantic salmon glazed lightly with teriyaki over fluffy quinoa and steamed edamame.'
            ],
            [
                'id' => 'lch_6',
                'title' => 'Adobong Sitaw & Firm Tofu with Quinoa & Roasted Sesame',
                'tags' => ['Vegan', 'Vegetarian', 'Plant Powered'],
                'restrictions' => ['none', 'vegetarian', 'vegan', 'dairy-free', 'halal'],
                'pro_pct' => 0.28, 'carbs_pct' => 0.52, 'fat_pct' => 0.20,
                'desc' => 'Crisp green yard-long beans and protein-packed firm tofu stir-fried in aromatic adobo sauce.'
            ],
            [
                'id' => 'lch_7',
                'title' => 'Keto Inihaw na Liempo (Grilled Pork Belly) with Ensaladang Talong',
                'tags' => ['Keto', 'Low Carb', 'Paleo'],
                'restrictions' => ['none', 'keto', 'paleo', 'gluten-free', 'dairy-free'],
                'pro_pct' => 0.32, 'carbs_pct' => 0.08, 'fat_pct' => 0.60,
                'desc' => 'Grilled seasoned pork strips paired with grilled eggplant salad dressed in calamansi and tomatoes.'
            ],
            [
                'id' => 'lch_8',
                'title' => 'Lean Ground Beef Picadillo with Diced Potatoes & Green Peas',
                'tags' => ['High Protein', 'Iron Rich', 'Gluten-Free'],
                'restrictions' => ['none', 'halal', 'gluten-free', 'dairy-free'],
                'pro_pct' => 0.38, 'carbs_pct' => 0.40, 'fat_pct' => 0.22,
                'desc' => 'Lean 90/10 ground beef simmered in tomato reduction with sweet peas, carrots, and potatoes.'
            ],
        ],
        'Dinner' => [
            [
                'id' => 'dnr_1',
                'title' => 'Chicken Tinola with Sayote, Moringa Leaves & Brown Rice',
                'tags' => ['Immunity Boost', 'High Protein', 'Filipino Classic'],
                'restrictions' => ['none', 'halal', 'gluten-free', 'dairy-free'],
                'pro_pct' => 0.40, 'carbs_pct' => 0.42, 'fat_pct' => 0.18,
                'desc' => 'Comforting ginger chicken soup loaded with antioxidant moringa leaves and tender chayote.'
            ],
            [
                'id' => 'dnr_2',
                'title' => 'Inihaw na Bangus (Milkfish) stuffed with Tomatoes, Onions & Rice',
                'tags' => ['Pescatarian', 'Heart Healthy', 'Filipino Fit'],
                'restrictions' => ['none', 'pescatarian', 'gluten-free', 'dairy-free', 'halal'],
                'pro_pct' => 0.36, 'carbs_pct' => 0.44, 'fat_pct' => 0.20,
                'desc' => 'Whole milkfish stuffed with fresh tomatoes and red onions, char-grilled to perfection.'
            ],
            [
                'id' => 'dnr_3',
                'title' => 'Vegetable Pinakbet with Pan-Seared Tofu & Brown Rice',
                'tags' => ['Vegetarian', 'High Fiber', 'Nutrient Dense'],
                'restrictions' => ['none', 'vegetarian', 'vegan', 'halal', 'dairy-free'],
                'pro_pct' => 0.26, 'carbs_pct' => 0.54, 'fat_pct' => 0.20,
                'desc' => 'Squash, okra, string beans, and eggplant simmered with crispy tofu cubes (no shrimp paste).'
            ],
            [
                'id' => 'dnr_4',
                'title' => 'Lean Beef & Broccoli Stir-Fry with Steamed Jasmine Rice',
                'tags' => ['High Protein', 'Quick & Healthy', 'Balanced'],
                'restrictions' => ['none', 'halal', 'dairy-free'],
                'pro_pct' => 0.42, 'carbs_pct' => 0.40, 'fat_pct' => 0.18,
                'desc' => 'Tender flank steak strips wok-tossed with fresh broccoli florets in ginger-garlic sauce.'
            ],
            [
                'id' => 'dnr_5',
                'title' => 'Ginataang Salmon with Spinach & Garlic Brown Rice',
                'tags' => ['Pescatarian', 'Healthy Coconut Fats', 'Gluten-Free'],
                'restrictions' => ['none', 'pescatarian', 'gluten-free', 'dairy-free', 'halal'],
                'pro_pct' => 0.34, 'carbs_pct' => 0.40, 'fat_pct' => 0.26,
                'desc' => 'Fresh salmon simmered in light coconut milk and baby spinach with garlic brown rice.'
            ],
            [
                'id' => 'dnr_6',
                'title' => 'Gising-Gising with Tofu, Green Beans & Coconut Cream',
                'tags' => ['Vegetarian', 'Vegan', 'Dairy-Free'],
                'restrictions' => ['none', 'vegetarian', 'vegan', 'gluten-free', 'dairy-free', 'halal'],
                'pro_pct' => 0.26, 'carbs_pct' => 0.48, 'fat_pct' => 0.26,
                'desc' => 'Finely chopped green beans cooked in mildly spiced coconut cream with pan-crisped tofu.'
            ],
            [
                'id' => 'dnr_7',
                'title' => 'Keto Baked Salmon Fillet with Lemon Butter & Roasted Asparagus',
                'tags' => ['Keto', 'High Omega-3', 'Low Carb'],
                'restrictions' => ['none', 'keto', 'pescatarian', 'gluten-free'],
                'pro_pct' => 0.35, 'carbs_pct' => 0.05, 'fat_pct' => 0.60,
                'desc' => 'Oven-baked wild salmon fillet topped with garlic herb butter and tender grilled asparagus.'
            ],
            [
                'id' => 'dnr_8',
                'title' => 'Halal Inihaw na Manok (Grilled Chicken Skewers) with Ensalada & Rice',
                'tags' => ['Halal', 'High Protein', 'Clean Eating'],
                'restrictions' => ['none', 'halal', 'gluten-free', 'dairy-free'],
                'pro_pct' => 0.44, 'carbs_pct' => 0.40, 'fat_pct' => 0.16,
                'desc' => 'Skewered spiced chicken breast grilled over open flames, served with tomato-cucumber salad.'
            ],
        ],
        'Snack' => [
            [
                'id' => 'snk_1',
                'title' => 'Boiled Saba Banana with Light Natural Peanut Butter',
                'tags' => ['Pre-Workout', 'Potassium Rich', 'Vegetarian'],
                'restrictions' => ['none', 'vegetarian', 'vegan', 'dairy-free', 'gluten-free', 'halal'],
                'pro_pct' => 0.15, 'carbs_pct' => 0.65, 'fat_pct' => 0.20,
                'desc' => 'Steamed Filipino saba banana paired with natural unsweetened peanut butter.'
            ],
            [
                'id' => 'snk_2',
                'title' => 'Boiled Kamote (Sweet Potato) with Cinnamon',
                'tags' => ['Clean Carb', 'Slow Digesting', 'Vegan'],
                'restrictions' => ['none', 'vegetarian', 'vegan', 'dairy-free', 'gluten-free', 'halal'],
                'pro_pct' => 0.10, 'carbs_pct' => 0.80, 'fat_pct' => 0.10,
                'desc' => 'Steamed purple or orange sweet potato sprinkled with fragrant Ceylon cinnamon.'
            ],
            [
                'id' => 'snk_3',
                'title' => 'Whey Protein Shake with Skim Milk & Half Banana',
                'tags' => ['Post-Workout', 'High Protein', 'Fast Absorbing'],
                'restrictions' => ['none', 'vegetarian', 'halal', 'gluten-free'],
                'pro_pct' => 0.55, 'carbs_pct' => 0.35, 'fat_pct' => 0.10,
                'desc' => 'Premium whey protein isolate blended with cold milk and potassium-rich banana.'
            ],
            [
                'id' => 'snk_4',
                'title' => 'Hard-Boiled Eggs with Himalayan Pink Salt & Black Pepper',
                'tags' => ['Keto Friendly', 'High Protein', 'Gluten-Free'],
                'restrictions' => ['none', 'keto', 'paleo', 'vegetarian', 'gluten-free', 'dairy-free', 'halal'],
                'pro_pct' => 0.38, 'carbs_pct' => 0.04, 'fat_pct' => 0.58,
                'desc' => 'Fresh large farm eggs boiled firm, seasoned with pink salt and cracked black pepper.'
            ],
            [
                'id' => 'snk_5',
                'title' => 'Greek Yogurt Cup with Blueberries & Roasted Almonds',
                'tags' => ['Gut Health', 'High Protein', 'Vegetarian'],
                'restrictions' => ['none', 'vegetarian', 'gluten-free'],
                'pro_pct' => 0.40, 'carbs_pct' => 0.35, 'fat_pct' => 0.25,
                'desc' => 'Thick strained plain Greek yogurt topped with antioxidant blueberries and raw almonds.'
            ],
            [
                'id' => 'snk_6',
                'title' => 'Steamed Edamame Beans with Coarse Sea Salt',
                'tags' => ['Vegan', 'Fiber Rich', 'Gluten-Free'],
                'restrictions' => ['none', 'vegetarian', 'vegan', 'dairy-free', 'gluten-free', 'halal'],
                'pro_pct' => 0.35, 'carbs_pct' => 0.40, 'fat_pct' => 0.25,
                'desc' => 'Tender young soybeans steamed in the pod with mineral coarse sea salt.'
            ],
            [
                'id' => 'snk_7',
                'title' => 'Keto Pork Chicharon (Rinds) with Spiced Vinegar Dip',
                'tags' => ['Zero Carb', 'Keto', 'Crunchy'],
                'restrictions' => ['none', 'keto', 'gluten-free', 'dairy-free'],
                'pro_pct' => 0.60, 'carbs_pct' => 0.01, 'fat_pct' => 0.39,
                'desc' => 'Crispy baked pork rinds with spicy garlic-infused native cane vinegar.'
            ],
        ]
    ];
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

        $map = [
            'adobo'         => 'https://images.unsplash.com/photo-1626082927389-6cd097cdc6ec?auto=format&fit=crop&w=600&q=80',
            'sinigang'      => 'https://images.unsplash.com/photo-1559847844-5315695dadae?auto=format&fit=crop&w=600&q=80',
            'tinola'        => 'https://images.unsplash.com/photo-1547592180-85f173990554?auto=format&fit=crop&w=600&q=80',
            'tapsilog'      => 'https://images.unsplash.com/photo-1525351484163-7529414344d8?auto=format&fit=crop&w=600&q=80',
            'silog'         => 'https://images.unsplash.com/photo-1525351484163-7529414344d8?auto=format&fit=crop&w=600&q=80',
            'bangsilog'     => 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?auto=format&fit=crop&w=600&q=80',
            'salmon'        => 'https://images.unsplash.com/photo-1467003909585-2f8a72700288?auto=format&fit=crop&w=600&q=80',
            'bangus'        => 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?auto=format&fit=crop&w=600&q=80',
            'fish'          => 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?auto=format&fit=crop&w=600&q=80',
            'hipon'         => 'https://images.unsplash.com/photo-1559847844-5315695dadae?auto=format&fit=crop&w=600&q=80',
            'tofu'          => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80',
            'champorado'    => 'https://images.unsplash.com/photo-1584776296944-ab6fb57b0bdd?auto=format&fit=crop&w=600&q=80',
            'oat'           => 'https://images.unsplash.com/photo-1584776296944-ab6fb57b0bdd?auto=format&fit=crop&w=600&q=80',
            'munggo'        => 'https://images.unsplash.com/photo-1547592180-85f173990554?auto=format&fit=crop&w=600&q=80',
            'pinakbet'      => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?auto=format&fit=crop&w=600&q=80',
            'laing'         => 'https://images.unsplash.com/photo-1455619452474-d2be8b1e70cd?auto=format&fit=crop&w=600&q=80',
            'bicol'         => 'https://images.unsplash.com/photo-1455619452474-d2be8b1e70cd?auto=format&fit=crop&w=600&q=80',
            'gising'        => 'https://images.unsplash.com/photo-1455619452474-d2be8b1e70cd?auto=format&fit=crop&w=600&q=80',
            'liempo'        => 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=600&q=80',
            'pork'          => 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=600&q=80',
            'chicken'       => 'https://images.unsplash.com/photo-1626082927389-6cd097cdc6ec?auto=format&fit=crop&w=600&q=80',
            'talong'        => 'https://images.unsplash.com/photo-1582169296194-e4d644c48063?auto=format&fit=crop&w=600&q=80',
            'arroz caldo'   => 'https://images.unsplash.com/photo-1563379091339-03b21ab4a4f8?auto=format&fit=crop&w=600&q=80',
            'lugaw'         => 'https://images.unsplash.com/photo-1563379091339-03b21ab4a4f8?auto=format&fit=crop&w=600&q=80',
            'banana'        => 'https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?auto=format&fit=crop&w=600&q=80',
            'saba'          => 'https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?auto=format&fit=crop&w=600&q=80',
            'kamote'        => 'https://images.unsplash.com/photo-1596040033229-a9821ebd058d?auto=format&fit=crop&w=600&q=80',
            'sweet potato'  => 'https://images.unsplash.com/photo-1596040033229-a9821ebd058d?auto=format&fit=crop&w=600&q=80',
            'egg'           => 'https://images.unsplash.com/photo-1525351484163-7529414344d8?auto=format&fit=crop&w=600&q=80',
            'salad'         => 'https://images.unsplash.com/photo-1512621776951-a57141f2eefd?auto=format&fit=crop&w=600&q=80',
            'smoothie'      => 'https://images.unsplash.com/photo-1553530666-ba11a7da3888?auto=format&fit=crop&w=600&q=80',
            'shake'         => 'https://images.unsplash.com/photo-1553530666-ba11a7da3888?auto=format&fit=crop&w=600&q=80',
            'whey'          => 'https://images.unsplash.com/photo-1553530666-ba11a7da3888?auto=format&fit=crop&w=600&q=80',
            'puto'          => 'https://images.unsplash.com/photo-1509440159596-0249088772ff?auto=format&fit=crop&w=600&q=80',
            'chicharon'     => 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=600&q=80',
            'peanut'        => 'https://images.unsplash.com/photo-1508061253366-f7da158b6d46?auto=format&fit=crop&w=600&q=80',
            'almond'        => 'https://images.unsplash.com/photo-1508061253366-f7da158b6d46?auto=format&fit=crop&w=600&q=80',
        ];

        foreach ($map as $keyword => $url) {
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

    // ----------------------------------------------------
    // AJAX: Get Meal Swap Alternatives
    // ----------------------------------------------------
    if ($action === 'get_swap_options') {
        $mealType = (string) (post('meal_type') ?: ($_GET['meal_type'] ?? 'Lunch'));
        $targetCals = max(100, (int) (post('calories') ?: ($_GET['calories'] ?? 400)));

        $profile = $pdo->query('SELECT dietary_restrictions, primary_goal FROM member_profiles WHERE user_id = ' . $userId)->fetch();
        $userRest = (string) ($profile['dietary_restrictions'] ?? 'none');

        $catalog = get_swap_meal_catalog();
        $candidates = $catalog[$mealType] ?? ($catalog['Lunch'] ?? []);

        $options = [];
        foreach ($candidates as $rec) {
            $p_g = (float) round(($targetCals * $rec['pro_pct']) / 4, 1);
            $c_g = (float) round(($targetCals * $rec['carbs_pct']) / 4, 1);
            $f_g = (float) round(($targetCals * $rec['fat_pct']) / 9, 1);
            $grams = (int) round($targetCals / 1.5);
            $foodStr = "{$grams}g of {$rec['title']}";

            $isMatched = in_array($userRest, $rec['restrictions'], true);

            $options[] = [
                'id' => $rec['id'],
                'title' => $rec['title'],
                'food_items' => $foodStr,
                'calories' => $targetCals,
                'protein_g' => $p_g,
                'carbs_g' => $c_g,
                'fat_g' => $f_g,
                'grams' => $grams,
                'tags' => $rec['tags'],
                'desc' => $rec['desc'],
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
            $targetCals = max(1200, round($targetCals));
            
            // 4. Macro Split
            $rule = $pdo->query("SELECT macro_split FROM diet_rules WHERE primary_goal = '{$goal}' AND experience_level = {$expLevel}")->fetch();
            if (!$rule) $rule = $pdo->query("SELECT macro_split FROM diet_rules WHERE primary_goal = 'general_health'")->fetch();
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
            
            // 6. Generate Meals
            $restriction = $profile['dietary_restrictions'] ?? 'none';
            $foods = [
                'none' => [
                    'Breakfast' => ['Tapsilog (Beef Tapa, Garlic Brown Rice, Egg)', 'Chicken Longsilog (Garlic Rice, Egg)', 'Oat Champorado with Tuyo (Dried Fish)'],
                    'Lunch' => ['Chicken Adobo with Brown Rice & Gasing (Cabbage)', 'Sinigang na Hipon (Shrimp) with Kangkong & Rice', 'Ginisang Munggo with Tinapa & Rice'],
                    'Dinner' => ['Lean Inihaw na Liempo with Ensaladang Talong', 'Chicken Tinola with Sayote, Moringa & Brown Rice', 'Pinakbet with Grilled Fish & Rice'],
                    'Snack' => ['Boiled Saba Banana', 'Boiled Kamote (Sweet Potato)', 'Steamed Puto with Cheese']
                ],
                'vegetarian' => [
                    'Breakfast' => ['Tortang Talong (Eggplant Omelet) with Garlic Rice', 'Oat Champorado with Almond Milk', 'Vegetarian Pancit Canton'],
                    'Lunch' => ['Ginisang Munggo (No Meat) with Brown Rice', 'Adobong Sitaw & Tofu with Rice', 'Tokwa at Baboy (using Soy Meat)'],
                    'Dinner' => ['Vegetable Pinakbet (No Bagoong) with Tofu & Rice', 'Gising-Gising (Tofu & Green Beans in Coconut Milk)', 'Laing (Taro Leaves in Spicy Coconut Milk)'],
                    'Snack' => ['Boiled Saba Banana', 'Boiled Kamote', 'Bibingka (Gluten-Free rice cake)']
                ],
                'vegan' => [
                    'Breakfast' => ['Tofu Scramble Adobo Style with Garlic Rice', 'Oat Champorado with Almond Milk', 'Vegan Arroz Caldo (Tofu & Ginger)'],
                    'Lunch' => ['Ginisang Munggo (Vegan) with Brown Rice', 'Adobong Sitaw & Tofu with Rice', 'Vegan Bicol Express with Tofu'],
                    'Dinner' => ['Vegetable Pinakbet (Vegan) with Quinoa', 'Laing (Vegan Taro Leaves in Spicy Coconut Milk)', 'Gising-Gising with Tofu & Coconut Cream'],
                    'Snack' => ['Boiled Saba Banana', 'Boiled Kamote', 'Espasol (Rice flour sweet)']
                ],
                'pescatarian' => [
                    'Breakfast' => ['Tinapasilog (Smoked Fish, Garlic Rice, Egg)', 'Bangsilog (Grilled Milkfish, Garlic Rice, Egg)', 'Oat Champorado with Tuyo'],
                    'Lunch' => ['Sinigang na Hipon (Shrimp) with Kangkong & Rice', 'Ginisang Munggo with Tinapa & Rice', 'Tuna Bicol Express with Rice'],
                    'Dinner' => ['Inihaw na Bangus stuffed with Tomatoes & Onions', 'Salmon Sinigang (Sour soup) with Rice', 'Ginataang Salmon with Spinach & Rice'],
                    'Snack' => ['Boiled Saba Banana', 'Boiled Kamote', 'Puto with Cheese']
                ],
                'halal' => [
                    'Breakfast' => ['Chicken Tapsilog (Chicken Tapa, Garlic Rice, Egg)', 'Chicken Longsilog (Garlic Rice, Egg)', 'Oat Champorado with Tuyo'],
                    'Lunch' => ['Halal Chicken Adobo with Brown Rice', 'Sinigang na Hipon with Kangkong & Rice', 'Ginisang Munggo with Tinapa & Rice'],
                    'Dinner' => ['Inihaw na Manok (Grilled Chicken) with Ensaladang Talong', 'Chicken Tinola with Sayote & Moringa', 'Pinakbet with Grilled Fish & Rice'],
                    'Snack' => ['Boiled Saba Banana', 'Boiled Kamote', 'Puto with Cheese']
                ],
                'gluten-free' => [
                    'Breakfast' => ['Tapsilog (using GF Tamari for Tapa)', 'Champorado (using GF Cocoa and Rice)', 'Bangsilog (Milkfish, GF Garlic Rice, Egg)'],
                    'Lunch' => ['Sinigang na Hipon (GF sour broth) with Brown Rice', 'Chicken Tinola with Sayote & Moringa', 'Ginisang Munggo with Rice'],
                    'Dinner' => ['Inihaw na Bangus stuffed with Tomatoes & Onions', 'Salmon Sinigang with Rice', 'Pinakbet (GF version) with Grilled Fish'],
                    'Snack' => ['Boiled Saba Banana', 'Boiled Kamote', 'Saging na Saba con Yelo (No condensed milk)']
                ],
                'keto' => [
                    'Breakfast' => ['Tortang Talong with Ground Pork (No Rice)', 'Tapsilog (Beef Tapa, Cauliflower Garlic Rice, Fried Egg)', 'Scrambled Eggs with Tinapa Flakes'],
                    'Lunch' => ['Inihaw na Liempo with Ensaladang Talong', 'Chicken Tinola (No Sayote, Extra Moringa)', 'Adobong Baboy (No sugar, low carb)'],
                    'Dinner' => ['Salmon Sinigang (No Gabi/Taro, low carb veggies)', 'Ginataang Manok (Chicken in Coconut Cream)', 'Inihaw na Bangus with Tomatoes & Onions'],
                    'Snack' => ['Chicharon (Pork Rinds)', 'Salted Peanuts', 'Hard-boiled Eggs']
                ],
                'paleo' => [
                    'Breakfast' => ['Tortang Talong with Ground Beef', 'Beef Tapa with Fried Egg (No Rice)', 'Boiled Eggs with Avocado'],
                    'Lunch' => ['Inihaw na Liempo with Ensaladang Talong', 'Chicken Tinola with Sayote & Moringa', 'Inihaw na Bangus (Grilled Milkfish)'],
                    'Dinner' => ['Tinola na Manok with Moringa & Sayote', 'Adobong Baboy (using Coconut Aminos)', 'Inihaw na Manok with Cucumber Salad'],
                    'Snack' => ['Salted Almonds', 'Boiled Kamote (in moderation)', 'Hard-boiled Eggs']
                ],
                'nut-allergy' => [
                    'Breakfast' => ['Tapsilog (Beef Tapa, Garlic Rice, Fried Egg)', 'Chicken Longsilog (Garlic Rice, Egg)', 'Oat Champorado with Tuyo'],
                    'Lunch' => ['Chicken Adobo with Brown Rice & Cabbage', 'Sinigang na Hipon with Kangkong & Rice', 'Ginisang Munggo with Tinapa & Rice'],
                    'Dinner' => ['Lean Inihaw na Liempo with Ensaladang Talong', 'Chicken Tinola with Sayote & Moringa', 'Pinakbet with Grilled Fish & Rice'],
                    'Snack' => ['Boiled Saba Banana', 'Boiled Kamote', 'Steamed Puto with Cheese']
                ],
                'dairy-free' => [
                    'Breakfast' => ['Tapsilog (Beef Tapa, Garlic Rice, Fried Egg)', 'Chicken Longsilog (Garlic Rice, Egg)', 'Oat Champorado (using Coconut Milk) with Tuyo'],
                    'Lunch' => ['Chicken Adobo with Brown Rice & Cabbage', 'Sinigang na Hipon with Kangkong & Rice', 'Ginisang Munggo with Tinapa & Rice'],
                    'Dinner' => ['Lean Inihaw na Liempo with Ensaladang Talong', 'Chicken Tinola with Sayote & Moringa', 'Pinakbet with Grilled Fish & Rice'],
                    'Snack' => ['Boiled Saba Banana', 'Boiled Kamote', 'Steamed Puto (No Cheese)']
                ],
            ];
            $dietFoods = $foods[$restriction] ?? $foods['none'];
            
            $dist = ['Breakfast'=>0.25, 'Lunch'=>0.35, 'Dinner'=>0.30, 'Snack'=>0.10];
            $stmt = $pdo->prepare('INSERT INTO dietary_plan_meals (plan_id, day_of_week, meal_type, food_items, calories, protein_g, carbs_g, fat_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            
            for ($d = 1; $d <= 7; $d++) {
                foreach ($dist as $mType => $pct) {
                    $mCals = round($targetCals * $pct);
                    $mP = round($p_g * $pct);
                    $mC = round($c_g * $pct);
                    $mF = round($f_g * $pct);
                    $portionGrams = round($mCals / 1.5);
                    
                    $options = $dietFoods[$mType];
                    $selectedFood = $options[($d - 1) % count($options)];
                    
                    $mFood = $portionGrams . "g of " . $selectedFood;
                    $stmt->execute([$planId, $d, $mType, $mFood, $mCals, $mP, $mC, $mF]);
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

    render_header('My Diet Plan', $user);
    
    if (!$activePlan) {
        echo '<div class="panel" style="text-align: center; padding: 50px 20px;">
                <h2 style="color: var(--muted); margin-bottom: 10px;">No Active Diet Plan</h2>
                <p style="margin-bottom: 24px;">You currently do not have an active diet plan.</p>
                <form method="post" style="display:inline-block;">
                    ' . csrf_field() . '
                    <input type="hidden" name="action" value="generate_plan">
                    <button type="submit" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold; font-size: 16px; padding: 12px 24px; border-radius: 8px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                        Generate Diet Plan
                    </button>
                </form>
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
<style>
/* ==================================================== */
/* 7-DAY MEAL PLAN SELECTOR NAVIGATION                 */
/* ==================================================== */
.diet-days-nav-wrapper {
    background: color-mix(in srgb, var(--surface) 90%, var(--ink));
    border-bottom: 1px solid var(--line);
    padding: 6px;
}
.diet-days-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 6px;
}
.diet-day-tab {
    background: transparent;
    border: 1px solid transparent;
    border-radius: 10px;
    padding: 10px 6px;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 3px;
    transition: all 0.2s ease;
    text-decoration: none;
    color: var(--muted);
    user-select: none;
    min-height: 52px;
}
.diet-day-tab:hover {
    background: var(--panel-soft);
    color: var(--ink);
}
.diet-day-tab.active {
    background: var(--bg);
    border-color: color-mix(in srgb, var(--lime) 40%, var(--line));
    color: var(--ink);
    box-shadow: 0 2px 10px rgba(0,0,0,0.15);
}
.diet-day-tab.active .diet-day-full,
.diet-day-tab.active .diet-day-short {
    color: var(--lime);
    font-weight: 800;
}
.diet-day-full {
    font-size: 13px;
    font-weight: 700;
    line-height: 1.2;
}
.diet-day-short {
    display: none;
    font-size: 13px;
    font-weight: 700;
    line-height: 1.2;
}
.diet-day-cals {
    font-size: 11px;
    color: var(--muted);
    font-weight: 600;
    white-space: nowrap;
}
.diet-day-today-tag {
    font-size: 9px;
    font-weight: 800;
    text-transform: uppercase;
    background: color-mix(in srgb, var(--lime) 20%, transparent);
    color: var(--lime);
    padding: 1px 5px;
    border-radius: 6px;
    border: 1px solid color-mix(in srgb, var(--lime) 35%, transparent);
    line-height: 1.1;
}

/* Day Header Bar */
.diet-plan-day-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 22px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--line);
    flex-wrap: wrap;
    gap: 12px;
}
.day-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.day-header-title {
    margin: 0;
    font-size: 20px;
    font-weight: 800;
    color: var(--ink);
}
.diet-today-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: color-mix(in srgb, var(--lime) 15%, transparent);
    color: var(--lime);
    border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
}
.diet-today-chip .chip-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--lime);
    box-shadow: 0 0 6px var(--lime);
}
.day-header-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.day-meta-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: var(--bg);
    border: 1px solid var(--line);
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12.5px;
    color: var(--muted);
    font-weight: 500;
}
.day-meta-pill strong {
    color: var(--ink);
    font-weight: 700;
}
.day-meta-pill.kcal-pill strong {
    color: var(--lime);
}

@media (max-width: 768px) {
    .diet-days-grid {
        gap: 3px;
    }
    .diet-day-tab {
        padding: 8px 2px;
        min-height: 48px;
    }
    .diet-day-full {
        display: none !important;
    }
    .diet-day-short {
        display: block !important;
    }
    .diet-day-cals {
        display: none !important;
    }
    .diet-day-today-tag {
        font-size: 8px !important;
        padding: 1px 3px !important;
    }
    .diet-plan-day-header {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 8px !important;
    }
    .day-header-meta {
        justify-content: flex-start !important;
    }
}
@media (max-width: 420px) {
    .diet-day-short {
        font-size: 12px !important;
    }
    .diet-today-chip {
        font-size: 10px !important;
    }
    .day-header-title {
        font-size: 17px !important;
    }
}

/* ==================================================== */
/* MEAL CARD PHOTO BANNER (IMAGEKIT & SMART AUTO-MATCH) */
/* ==================================================== */
.meal-photo-banner {
    position: relative;
    width: 100%;
    height: 145px;
    overflow: hidden;
    background: #0b0f17;
    border-top-left-radius: 11px;
    border-top-right-radius: 11px;
}
.meal-banner-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center;
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    display: block;
}
.meal-card:hover .meal-banner-img {
    transform: scale(1.06);
}
.meal-banner-gradient {
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, rgba(0,0,0,0.55) 0%, rgba(0,0,0,0.05) 45%, rgba(0,0,0,0.75) 100%);
    pointer-events: none;
}
.meal-banner-top {
    position: absolute;
    top: 9px;
    left: 9px;
    right: 9px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    pointer-events: auto;
    z-index: 2;
}
.meal-banner-type {
    background: rgba(15, 23, 42, 0.85);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    color: #fff;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    padding: 3px 8px;
    border-radius: 6px;
    border: 1px solid rgba(255,255,255,0.18);
}
.btn-edit-meal-photo {
    background: rgba(15, 23, 42, 0.85);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    color: var(--lime);
    font-size: 11px;
    font-weight: 700;
    border: 1px solid color-mix(in srgb, var(--lime) 40%, transparent);
    border-radius: 6px;
    padding: 3px 8px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s ease;
}
.btn-edit-meal-photo:hover {
    background: var(--lime);
    color: var(--lime-btn-text, #090b10);
    border-color: var(--lime);
}
.meal-banner-bottom {
    position: absolute;
    bottom: 9px;
    left: 9px;
    right: 9px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    pointer-events: none;
    z-index: 2;
}
.meal-banner-cals {
    background: rgba(0, 0, 0, 0.78);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    color: var(--lime);
    font-size: 12px;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 12px;
    border: 1px solid color-mix(in srgb, var(--lime) 35%, transparent);
}
.meal-banner-logged-tag {
    background: rgba(34, 197, 94, 0.9);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    color: #ffffff;
    font-size: 10.5px;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    box-shadow: 0 2px 8px rgba(34, 197, 94, 0.4);
}

.diet-action-strip {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 22px;
    gap: 14px;
    background: var(--panel);
    border: 1px solid var(--line);
    padding: 12px 18px;
    border-radius: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
}
.diet-strip-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    min-width: 0;
}
.diet-goal-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: color-mix(in srgb, var(--lime) 12%, transparent);
    color: var(--ink);
    border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    max-width: 100%;
    min-width: 0;
}
.diet-goal-pill .goal-text {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.diet-assigned-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--panel-soft);
    color: var(--ink);
    border: 1px solid var(--line);
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    max-width: 100%;
    min-width: 0;
    white-space: nowrap;
}
.diet-assigned-pill .trainer-text {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.diet-action-form {
    margin: 0;
    flex-shrink: 0;
}
.btn-diet-action {
    background: var(--panel-soft);
    color: var(--ink);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all 0.2s ease;
    white-space: nowrap;
}
.btn-diet-action:hover {
    border-color: var(--lime);
    color: var(--lime);
}

@media (max-width: 768px) {
    .diet-action-strip {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 12px !important;
        padding: 14px 16px !important;
        margin-bottom: 18px !important;
    }
    .diet-strip-meta {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        width: 100% !important;
        gap: 8px !important;
        flex-wrap: wrap !important;
    }
    .diet-action-form {
        width: 100% !important;
    }
    .btn-diet-action {
        width: 100% !important;
        padding: 11px 16px !important;
        font-size: 14px !important;
        border-radius: 10px !important;
        box-sizing: border-box !important;
    }
}
@media (max-width: 520px) {
    .diet-strip-meta {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 8px !important;
    }
    .diet-goal-pill,
    .diet-assigned-pill {
        width: 100% !important;
        box-sizing: border-box !important;
        justify-content: flex-start !important;
        font-size: 12.5px !important;
        padding: 6px 12px !important;
    }
}
</style>
<!-- Contextual Action Strip (Goal, Assigned By, and Plan Actions) -->
<div class="diet-action-strip">
    <div class="diet-strip-meta">
        <span class="diet-goal-pill">
            <span style="width: 7px; height: 7px; border-radius: 50%; background: var(--lime); display: inline-block; flex-shrink: 0;"></span>
            <span style="color: var(--muted); font-weight: 500;">Goal:</span>
            <strong class="goal-text" style="color: var(--ink); font-weight: 700;"><?= h(ucwords(str_replace('_', ' ', $activePlan['goal']))) ?></strong>
        </span>

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

<script>
function confirmRegeneratePlan() {
    Swal.fire({
        title: 'Regenerate Diet Plan?',
        text: 'This will archive your current plan and generate a new customized plan based on your current weight and goals.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: 'var(--lime, #c7ff22)',
        cancelButtonColor: 'transparent',
        confirmButtonText: '<span style="color:var(--lime-btn-text, #090b10);font-weight:800;">Yes, Regenerate Plan</span>',
        cancelButtonText: 'Cancel',
        background: getComputedStyle(document.documentElement).getPropertyValue('--panel-bg').trim() || '#121721',
        color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('regenerate-plan-form').submit();
        }
    });
}
</script>

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
window.FT_LOGGED_MEALS_TODAY = <?= json_encode($loggedMealsTodayMap) ?>;
window.FT_CURRENT_DAY_NUM = <?= $todayNum ?>;
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
                <button type="button" id="btn-analyze-ninja" class="smart-meal-action-btn">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <span id="btn-analyze-ninja-txt">Analyze & Fill</span>
                </button>
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
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; flex-wrap: wrap; gap: 4px;">
                    <strong style="color: var(--ink); font-size: 12px;">Meal Ingredients Breakdown</strong>
                    <span style="font-size: 10.5px; color: #22c55e; font-weight: 700; background: rgba(34,197,94,0.12); padding: 2px 8px; border-radius: 10px; border: 1px solid rgba(34,197,94,0.3);">✓ Applied to inputs below</span>
                </div>
                <div id="ninja-breakdown-items"></div>
                <div style="margin-top: 8px; padding-top: 6px; border-top: 1px solid color-mix(in srgb, var(--line) 80%, transparent); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px;">
                    <div style="font-weight: 800; font-size: 12.5px; color: var(--ink);" id="ninja-breakdown-totals"></div>
                    <span style="font-size: 11px; color: var(--muted);">Click "+ Add to Log" below to record this meal.</span>
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
                    <button type="button" id="btn-search-off" class="smart-meal-action-btn off-search-btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <span id="btn-search-off-txt">Search</span>
                    </button>
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

<style>
/* Top View Switcher Navigation */
.diet-main-nav-wrap {
    margin-bottom: 22px;
}
.diet-main-nav {
    display: inline-flex;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 4px;
    gap: 6px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    max-width: 100%;
    flex-wrap: wrap;
}
.diet-nav-pill {
    background: transparent;
    border: 1px solid transparent;
    color: var(--muted);
    padding: 9px 18px;
    border-radius: 9px;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 9px;
    transition: all 0.22s ease;
}
.diet-nav-pill:hover {
    color: var(--ink);
    background: color-mix(in srgb, var(--ink) 5%, transparent);
}
.diet-nav-pill.active {
    background: var(--panel-soft);
    color: var(--ink);
    border-color: color-mix(in srgb, var(--lime) 40%, var(--line));
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.1);
}
[data-theme="light"] .diet-nav-pill.active {
    background: #f1f5f9;
    color: #0f172a;
    border-color: var(--line);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}
.diet-nav-pill-title-row {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.diet-nav-title-full {
    display: inline;
}
.diet-nav-title-short {
    display: none;
}
.diet-nav-badge {
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 12px;
    letter-spacing: 0.2px;
}
.diet-nav-pill.active .tracker-badge {
    background: color-mix(in srgb, var(--lime) 18%, transparent);
    color: var(--lime);
    border: 1px solid color-mix(in srgb, var(--lime) 35%, transparent);
}
.diet-nav-pill:not(.active) .tracker-badge {
    background: color-mix(in srgb, var(--ink) 6%, transparent);
    color: var(--muted);
}
.diet-nav-pill.active .plan-badge {
    background: color-mix(in srgb, #38bdf8 18%, transparent);
    color: #38bdf8;
    border: 1px solid color-mix(in srgb, #38bdf8 35%, transparent);
}
.diet-nav-pill:not(.active) .plan-badge {
    background: color-mix(in srgb, var(--ink) 6%, transparent);
    color: var(--muted);
}
.diet-view-panel {
    animation: dietViewFade 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes dietViewFade {
    0% { opacity: 0; transform: translateY(6px); }
    100% { opacity: 1; transform: translateY(0); }
}

/* Adaptive Macro Tracker Theme System */
:root {
    --surface: var(--panel);
    --macro-cals: var(--lime);
    --macro-pro: #38bdf8;
    --macro-carbs: #fbbf24;
    --macro-fat: #f43f5e;
    --macro-card-bg: rgba(0, 0, 0, 0.28);
    --macro-input-bg: rgba(0, 0, 0, 0.32);
    --macro-track-bg: rgba(255, 255, 255, 0.08);
    --macro-tabs-bg: rgba(0, 0, 0, 0.35);
    --macro-btn-ink: #090b10;
    --macro-card-glow: 0 4px 20px rgba(0, 0, 0, 0.25);
    --macro-box-bg: linear-gradient(135deg, rgba(199,255,34,0.06) 0%, rgba(56,189,248,0.04) 50%, rgba(244,63,94,0.04) 100%), var(--panel);
    --macro-box-border: color-mix(in srgb, var(--lime) 25%, var(--line));
    --macro-cals-border: rgba(199, 255, 34, 0.22);
    --macro-pro-border: rgba(56, 189, 248, 0.22);
    --macro-carbs-border: rgba(251, 191, 36, 0.22);
    --macro-fat-border: rgba(244, 63, 94, 0.22);
}

[data-theme="light"] {
    --surface: #ffffff;
    --macro-cals: var(--lime);       /* deep forest lime */
    --macro-pro: #0284c7;           /* rich accessible sky blue */
    --macro-carbs: #d97706;         /* rich warm amber */
    --macro-fat: #e11d48;           /* rich berry rose */
    --macro-card-bg: #f8fafc;
    --macro-input-bg: #ffffff;
    --macro-track-bg: #e2e8f0;
    --macro-tabs-bg: var(--panel-soft);
    --macro-btn-ink: #ffffff;
    --macro-card-glow: 0 4px 18px rgba(0, 0, 0, 0.05);
    --macro-box-bg: #ffffff;
    --macro-box-border: var(--line);
    --macro-cals-border: color-mix(in srgb, var(--lime) 30%, var(--line));
    --macro-pro-border: color-mix(in srgb, #0284c7 30%, var(--line));
    --macro-carbs-border: color-mix(in srgb, #d97706 30%, var(--line));
    --macro-fat-border: color-mix(in srgb, #e11d48 30%, var(--line));
}

.macro-tracker-card {
    background: var(--macro-box-bg);
    border: 1px solid var(--macro-box-border);
    border-radius: 14px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: var(--macro-card-glow);
    backdrop-filter: blur(16px);
    transition: background 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
}

.macro-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}
.macro-card-title {
    margin: 0;
    font-size: 20px;
    color: var(--ink);
    display: flex;
    align-items: center;
    gap: 8px;
}
.macro-card-subtitle {
    margin: 4px 0 0;
    color: var(--muted);
    font-size: 13px;
}

.macro-aux-btn {
    background: var(--panel-soft);
    border: 1px solid var(--line);
    color: var(--muted);
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}
.macro-aux-btn:hover {
    color: var(--ink);
    border-color: color-mix(in srgb, var(--ink) 25%, var(--line));
}

.macro-stat-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
    margin-bottom: 22px;
}

.macro-stat-card {
    background: var(--macro-card-bg);
    border-radius: 10px;
    padding: 14px 16px;
    transition: background 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
}
.macro-stat-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
}
.macro-stat-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.macro-stat-pct {
    font-size: 11px;
    font-weight: 700;
}
.macro-stat-numbers {
    font-weight: 800;
    color: var(--ink);
    margin-bottom: 10px;
    line-height: 1.2;
}
.val-main {
    font-size: 18px;
    font-weight: 800;
}
.val-sub {
    font-size: 13px;
    font-weight: 400;
    color: var(--muted);
}
.macro-controls-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
    flex-wrap: wrap;
    gap: 10px;
}
.macro-reset-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
}
.macro-stat-card.card-cals  { border: 1px solid var(--macro-cals-border); }
.macro-stat-card.card-pro   { border: 1px solid var(--macro-pro-border); }
.macro-stat-card.card-carbs { border: 1px solid var(--macro-carbs-border); }
.macro-stat-card.card-fat   { border: 1px solid var(--macro-fat-border); }

.macro-stat-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    display: flex;
    align-items: center;
    gap: 6px;
}
.macro-stat-label.label-cals  { color: var(--macro-cals); }
.macro-stat-label.label-pro   { color: var(--macro-pro); }
.macro-stat-label.label-carbs { color: var(--macro-carbs); }
.macro-stat-label.label-fat   { color: var(--macro-fat); }

.macro-stat-dot {
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
}
.macro-stat-dot.dot-cals  { background: var(--macro-cals); }
.macro-stat-dot.dot-pro   { background: var(--macro-pro); }
.macro-stat-dot.dot-carbs { background: var(--macro-carbs); }
.macro-stat-dot.dot-fat   { background: var(--macro-fat); }

.macro-progress-track {
    height: 6px;
    background: var(--macro-track-bg);
    border-radius: 3px;
    overflow: hidden;
}

.macro-mode-tabs {
    display: inline-flex;
    background: var(--macro-tabs-bg);
    padding: 3px;
    border-radius: 8px;
    border: 1px solid var(--line);
}
.macro-tab-btn {
    background: transparent;
    color: var(--muted);
    border: none;
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 5px;
}
.macro-tab-btn:hover {
    color: var(--ink);
}
.macro-tab-btn.active {
    background: var(--lime);
    color: var(--macro-btn-ink);
    font-weight: 700;
}

.macro-reset-btn {
    background: rgba(239, 68, 68, 0.08);
    border: 1px solid rgba(239, 68, 68, 0.25);
    color: #ef4444;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.2s ease;
}
.macro-reset-btn:hover {
    background: rgba(239, 68, 68, 0.15);
    border-color: #ef4444;
}

.macro-input-lbl {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    margin-bottom: 5px;
    text-transform: uppercase;
    font-weight: 600;
}
.macro-input-lbl.label-cals  { color: var(--macro-cals); }
.macro-input-lbl.label-pro   { color: var(--macro-pro); }
.macro-input-lbl.label-carbs { color: var(--macro-carbs); }
.macro-input-lbl.label-fat   { color: var(--macro-fat); }

.macro-field {
    width: 100%;
    background: var(--macro-input-bg);
    color: var(--ink);
    padding: 10px 12px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    box-sizing: border-box;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
}
.macro-field::placeholder {
    color: var(--muted);
    opacity: 0.7;
}
.macro-field.field-cals  { border: 1px solid var(--macro-cals-border); }
.macro-field.field-pro   { border: 1px solid var(--macro-pro-border); }
.macro-field.field-carbs { border: 1px solid var(--macro-carbs-border); }
.macro-field.field-fat   { border: 1px solid var(--macro-fat-border); }

.macro-field.field-cals:focus  { outline: none; border-color: var(--macro-cals); box-shadow: 0 0 0 3px color-mix(in srgb, var(--macro-cals) 20%, transparent); }
.macro-field.field-pro:focus   { outline: none; border-color: var(--macro-pro); box-shadow: 0 0 0 3px color-mix(in srgb, var(--macro-pro) 20%, transparent); }
.macro-field.field-carbs:focus { outline: none; border-color: var(--macro-carbs); box-shadow: 0 0 0 3px color-mix(in srgb, var(--macro-carbs) 20%, transparent); }
.macro-field.field-fat:focus   { outline: none; border-color: var(--macro-fat); box-shadow: 0 0 0 3px color-mix(in srgb, var(--macro-fat) 20%, transparent); }

.macro-save-btn {
    width: 100%;
    background: var(--lime);
    color: var(--macro-btn-ink);
    border: none;
    padding: 11px 16px;
    border-radius: 8px;
    font-weight: bold;
    cursor: pointer;
    transition: opacity 0.2s ease, transform 0.15s ease;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}
.macro-save-btn:hover {
    opacity: 0.92;
    transform: translateY(-1px);
}

/* Smart Meal Assistant Styles */
.smart-meal-box {
    background: var(--macro-card-bg);
    border: 1px solid color-mix(in srgb, var(--macro-pro) 28%, var(--line));
    border-radius: 12px;
    padding: 16px 18px;
    margin-bottom: 18px;
    transition: all 0.25s ease;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
}
.smart-meal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    flex-wrap: wrap;
    gap: 10px;
}
.smart-meal-title {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 13.5px;
    font-weight: 700;
    color: var(--ink);
    letter-spacing: 0.3px;
    flex-wrap: wrap;
}
.smart-title-group {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.smart-api-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    font-weight: 800;
    color: var(--macro-pro);
    background: color-mix(in srgb, var(--macro-pro) 14%, transparent);
    padding: 2px 7px;
    border-radius: 12px;
    border: 1px solid color-mix(in srgb, var(--macro-pro) 28%, transparent);
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.smart-panel-desc {
    font-size: 12px;
    color: var(--muted);
    margin-bottom: 8px;
    line-height: 1.4;
}
.smart-meal-tabs {
    display: inline-flex;
    background: var(--macro-input-bg);
    padding: 3px;
    border-radius: 8px;
    border: 1px solid var(--line);
    gap: 3px;
}
.smart-tab-btn {
    background: transparent;
    border: none;
    color: var(--muted);
    padding: 5px 12px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}
.smart-tab-btn:hover {
    color: var(--ink);
}
.smart-tab-btn.active {
    background: color-mix(in srgb, var(--macro-pro) 18%, var(--surface));
    color: var(--macro-pro);
    font-weight: 700;
    border: 1px solid color-mix(in srgb, var(--macro-pro) 35%, transparent);
}
[data-theme="light"] .smart-tab-btn.active {
    background: #e0f2fe;
    color: #0284c7;
    border-color: #bae6fd;
}
.smart-meal-panel {
    display: none;
}
.smart-meal-panel.active {
    display: block;
}
.smart-meal-input-wrap {
    display: flex;
    gap: 8px;
    align-items: stretch;
}
.smart-meal-textarea {
    flex: 1;
    background: var(--macro-input-bg);
    border: 1px solid var(--line);
    color: var(--ink);
    padding: 9px 12px;
    border-radius: 8px;
    font-size: 13px;
    min-height: 40px;
    box-sizing: border-box;
    transition: all 0.2s ease;
}
.smart-meal-textarea:focus {
    outline: none;
    border-color: var(--macro-pro);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--macro-pro) 20%, transparent);
}
.smart-meal-action-btn {
    background: var(--macro-pro);
    color: #ffffff;
    border: none;
    padding: 0 16px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    transition: all 0.2s ease;
}
.smart-meal-action-btn:hover {
    filter: brightness(1.08);
    transform: translateY(-1px);
}
.smart-meal-action-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}
.smart-quick-pills {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: 8px;
    align-items: center;
}
.quick-pill-label {
    font-size: 11px;
    color: var(--muted);
    margin-right: 2px;
}
.smart-pill {
    background: color-mix(in srgb, var(--ink) 4%, transparent);
    border: 1px solid var(--line);
    color: var(--muted);
    padding: 4px 10px;
    border-radius: 14px;
    font-size: 11px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.18s ease;
    user-select: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.smart-pill:hover {
    background: color-mix(in srgb, var(--macro-pro) 12%, transparent);
    color: var(--macro-pro);
    border-color: color-mix(in srgb, var(--macro-pro) 30%, transparent);
}
.off-search-wrap {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.off-search-main {
    flex: 1;
    min-width: 220px;
}
.off-search-btn {
    background: var(--macro-carbs) !important;
    color: #090b10 !important;
}
.off-portion-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: var(--macro-input-bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 0 10px;
    height: 40px;
    box-sizing: border-box;
}
.off-portion-label,
.off-portion-unit {
    font-size: 11.5px;
    color: var(--muted);
    font-weight: 600;
    user-select: none;
}
.off-stepper-btn {
    background: color-mix(in srgb, var(--ink) 6%, transparent);
    border: 1px solid color-mix(in srgb, var(--line) 80%, transparent);
    color: var(--muted);
    width: 22px;
    height: 22px;
    border-radius: 4px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    transition: all 0.15s ease;
    flex-shrink: 0;
}
.off-stepper-btn:hover {
    color: var(--ink);
    background: color-mix(in srgb, var(--macro-carbs) 20%, transparent);
    border-color: var(--macro-carbs);
}
.off-serving-input {
    width: 66px;
    min-width: 60px;
    background: transparent;
    border: none;
    color: var(--ink);
    font-weight: 800;
    font-size: 14px;
    text-align: center;
    padding: 4px 2px;
    margin: 0;
    box-sizing: border-box;
    -moz-appearance: textfield;
    appearance: textfield;
}
.off-serving-input::-webkit-outer-spin-button,
.off-serving-input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.off-serving-input:focus {
    outline: none;
    background: color-mix(in srgb, var(--ink) 8%, transparent);
    border-radius: 4px;
}
.macro-log-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
    gap: 12px;
    align-items: end;
    margin: 0;
}
.macro-form-col {
    display: flex;
    flex-direction: column;
}
.macro-submit-col {
    display: flex;
    align-items: flex-end;
}
.smart-breakdown-card {
    margin-top: 12px;
    background: color-mix(in srgb, var(--macro-pro) 6%, var(--macro-card-bg));
    border: 1px solid color-mix(in srgb, var(--macro-pro) 22%, var(--line));
    border-radius: 9px;
    padding: 12px 14px;
    font-size: 12px;
}
.smart-breakdown-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 4px 0;
    border-bottom: 1px dashed color-mix(in srgb, var(--line) 70%, transparent);
    font-size: 12px;
}
.smart-breakdown-item:last-child {
    border-bottom: none;
}
.off-search-results {
    margin-top: 12px;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
    gap: 10px;
    max-height: 260px;
    overflow-y: auto;
    padding-right: 4px;
}
.off-food-card {
    background: var(--macro-input-bg);
    border: 1px solid var(--line);
    border-radius: 9px;
    padding: 10px 12px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 8px;
    text-align: left;
}
.off-food-card:hover {
    border-color: var(--macro-carbs);
    background: color-mix(in srgb, var(--macro-carbs) 8%, var(--macro-input-bg));
    transform: translateY(-2px);
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
}
.field-highlight-flash {
    animation: fieldFlashPulse 0.8s ease;
}
@keyframes fieldFlashPulse {
    0% { transform: scale(1); }
    30% { transform: scale(1.02); box-shadow: 0 0 12px var(--macro-pro); border-color: var(--macro-pro) !important; }
    100% { transform: scale(1); }
}

/* Celebration Popup Theme Support */
.diet-celebration-popup {
    background: linear-gradient(155deg, rgba(22, 28, 36, 0.96) 0%, rgba(10, 14, 18, 0.98) 100%) !important;
    border: 1px solid rgba(255, 255, 255, 0.12) !important;
    border-radius: 22px !important;
    box-shadow: 0 28px 70px -15px rgba(0, 0, 0, 0.9), 0 0 45px rgba(0, 0, 0, 0.6) !important;
    backdrop-filter: blur(28px) !important;
    -webkit-backdrop-filter: blur(28px) !important;
    padding: 26px 22px 22px !important;
}
[data-theme="light"] .diet-celebration-popup {
    background: #ffffff !important;
    border: 1px solid var(--line) !important;
    box-shadow: 0 25px 60px -10px rgba(0, 0, 0, 0.18), 0 0 30px rgba(0, 0, 0, 0.05) !important;
    color: var(--ink) !important;
}
[data-theme="light"] .celebration-title {
    color: #0f172a !important;
}
[data-theme="light"] .celebration-desc {
    color: #475569 !important;
}
[data-theme="light"] .celebration-card-wrap {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
}
[data-theme="light"] .celebration-mini-stat {
    background: #ffffff !important;
    border-color: #e2e8f0 !important;
}
[data-theme="light"] .celebration-mini-stat .mini-val {
    color: #0f172a !important;
}
[data-theme="light"] .celebration-track {
    background: #e2e8f0 !important;
}
.diet-celebration-popup.swal2-show {
    animation: celebrationScaleIn 0.28s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
}
.diet-celebration-popup.swal2-hide {
    animation: celebrationScaleOut 0.15s cubic-bezier(0.4, 0, 1, 1) forwards !important;
}
@keyframes celebrationScaleIn {
    0% { transform: scale(0.9) translateY(10px); opacity: 0; }
    100% { transform: scale(1) translateY(0); opacity: 1; }
}
@keyframes celebrationScaleOut {
    0% { transform: scale(1) translateY(0); opacity: 1; }
    100% { transform: scale(0.92) translateY(8px); opacity: 0; }
}
.diet-celebration-popup .swal2-close {
    color: #94a3b8 !important;
    top: 14px !important;
    right: 14px !important;
    z-index: 99999 !important;
    pointer-events: auto !important;
    cursor: pointer !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 34px !important;
    height: 34px !important;
    border-radius: 50% !important;
    background: rgba(255, 255, 255, 0.08) !important;
    border: none !important;
    transition: all 0.2s ease !important;
    font-size: 22px !important;
    line-height: 1 !important;
}
.diet-celebration-popup .swal2-close:hover {
    color: #ffffff !important;
    background: rgba(255, 255, 255, 0.18) !important;
    transform: scale(1.06) !important;
}
[data-theme="light"] .diet-celebration-popup .swal2-close {
    color: #64748b !important;
    background: rgba(0, 0, 0, 0.05) !important;
}
[data-theme="light"] .diet-celebration-popup .swal2-close:hover {
    color: #0f172a !important;
    background: rgba(0, 0, 0, 0.1) !important;
}
.diet-celebration-popup .swal2-html-container {
    margin: 0 !important;
    padding: 0 !important;
    overflow: visible !important;
}
.celebration-hero-pulse {
    animation: heroIconPulse 2.4s infinite ease-in-out;
}
@keyframes heroIconPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.06); }
}
.celebration-action-btn {
    transition: all 0.18s ease !important;
}
.celebration-action-btn:hover {
    transform: translateY(-2px);
    filter: brightness(1.08);
}
.celebration-secondary-btn {
    transition: all 0.18s ease !important;
}
.celebration-secondary-btn:hover {
    color: var(--ink) !important;
    border-color: color-mix(in srgb, var(--ink) 30%, var(--line)) !important;
    background: rgba(255, 255, 255, 0.06) !important;
    transform: translateY(-2px);
}
[data-theme="light"] .celebration-secondary-btn:hover {
    background: rgba(0, 0, 0, 0.04) !important;
}

/* ==================================================== */
/* MOBILE RESPONSIVENESS & UX OPTIMIZATIONS (<= 640px)  */
/* ==================================================== */
@media (max-width: 640px) {
    /* 1. Top View Switcher Segmented Control */
    .diet-main-nav-wrap {
        margin-bottom: 14px;
        width: 100%;
    }
    .diet-main-nav {
        display: grid;
        grid-template-columns: 1fr 1fr;
        width: 100%;
        box-sizing: border-box;
        padding: 3px;
        gap: 4px;
        border-radius: 12px;
    }
    .diet-nav-pill {
        flex-direction: column;
        justify-content: center;
        align-items: center;
        padding: 8px 4px;
        gap: 4px;
        width: 100%;
        box-sizing: border-box;
        font-size: 12px;
        text-align: center;
    }
    .diet-nav-pill-title-row {
        gap: 5px;
        justify-content: center;
    }
    .diet-nav-title-full {
        display: none;
    }
    .diet-nav-title-short {
        display: inline;
        font-weight: 700;
        font-size: 12px;
    }
    .diet-nav-badge {
        font-size: 10px;
        padding: 2px 6px;
        max-width: 96%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* 2. Macro Tracker Card Container */
    .macro-tracker-card {
        padding: 14px 12px;
        border-radius: 12px;
        margin-bottom: 16px;
    }
    .macro-card-header {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
        margin-bottom: 14px;
    }
    .macro-card-title {
        font-size: 17px;
    }
    .macro-card-subtitle {
        font-size: 12px;
    }
    .macro-aux-btn {
        width: 100%;
        justify-content: center;
        padding: 8px 12px;
        font-size: 11.5px;
        box-sizing: border-box;
    }

    /* 3. 4 Macro Stat Cards: Clean 2x2 Grid */
    .macro-stat-cards-grid {
        grid-template-columns: 1fr 1fr !important;
        gap: 8px !important;
        margin-bottom: 14px !important;
    }
    .macro-stat-card {
        padding: 10px 10px;
        border-radius: 10px;
    }
    .macro-stat-label {
        font-size: 10px;
        gap: 4px;
    }
    .macro-stat-dot {
        width: 6px;
        height: 6px;
    }
    .macro-stat-pct {
        font-size: 10px;
        white-space: nowrap;
    }
    .macro-stat-numbers {
        margin-bottom: 6px;
    }
    .val-main {
        font-size: 16px;
    }
    .val-sub {
        display: block;
        font-size: 10.5px;
        margin-top: 1px;
    }
    .macro-progress-track {
        height: 5px;
    }

    /* 4. Macro Mode Controls & Reset Bar */
    .macro-controls-bar {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
        margin-bottom: 12px;
    }
    .macro-mode-tabs {
        width: 100%;
        display: grid;
        grid-template-columns: 1fr 1fr;
        box-sizing: border-box;
        padding: 2px;
    }
    .macro-tab-btn {
        justify-content: center;
        padding: 7px 4px;
        font-size: 11px;
        width: 100%;
        box-sizing: border-box;
        text-align: center;
    }
    .macro-reset-wrap {
        width: 100%;
    }
    .macro-reset-btn {
        width: 100%;
        justify-content: center;
        padding: 7px 10px;
        font-size: 11.5px;
        box-sizing: border-box;
    }

    /* 5. Smart Meal Assistant Mobile Card */
    .smart-meal-box {
        padding: 12px 12px;
        border-radius: 10px;
        margin-bottom: 14px;
    }
    .smart-meal-header {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
        margin-bottom: 10px;
    }
    .smart-meal-title {
        justify-content: space-between;
        width: 100%;
        font-size: 12.5px;
    }
    .smart-api-badge {
        font-size: 9px;
        padding: 2px 6px;
    }
    .smart-meal-tabs {
        width: 100%;
        display: grid;
        grid-template-columns: 1fr 1fr;
        box-sizing: border-box;
        padding: 2px;
        gap: 2px;
    }
    .smart-tab-btn {
        justify-content: center;
        padding: 6px 4px;
        font-size: 11px;
        width: 100%;
        box-sizing: border-box;
    }
    .smart-panel-desc {
        font-size: 11px;
        margin-bottom: 7px;
    }
    .smart-meal-input-wrap {
        flex-direction: column;
        gap: 8px;
        width: 100%;
    }
    .smart-meal-textarea {
        width: 100%;
        font-size: 13.5px;
        min-height: 42px;
        box-sizing: border-box;
    }
    .smart-meal-action-btn {
        width: 100%;
        justify-content: center;
        height: 42px;
        font-size: 13px;
        box-sizing: border-box;
    }
    .smart-quick-pills {
        gap: 5px;
        margin-top: 8px;
    }
    .quick-pill-label {
        width: 100%;
        margin-bottom: 2px;
    }
    .smart-pill {
        padding: 5px 8px;
        font-size: 10.5px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .off-search-wrap {
        flex-direction: column;
        gap: 8px;
        width: 100%;
    }
    .off-search-main {
        min-width: 100%;
    }
    .off-portion-pill {
        width: 100%;
        justify-content: center;
        height: 38px;
        box-sizing: border-box;
    }
    .off-search-results {
        grid-template-columns: 1fr;
        max-height: 300px;
    }

    /* 6. Macro Log Form 2x2 Grid on Mobile */
    .macro-log-form-grid {
        grid-template-columns: 1fr 1fr !important;
        gap: 10px !important;
    }
    .macro-submit-col {
        grid-column: 1 / -1 !important;
        margin-top: 4px;
    }
    .macro-field {
        min-height: 44px;
        font-size: 15px;
        padding: 10px 10px;
    }
    .macro-save-btn {
        min-height: 46px;
        font-size: 15px;
        width: 100%;
    }
}
</style>
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"></script>
<script>
(function() {
    const form       = document.getElementById('macro-log-form');
    const saveBtn    = document.getElementById('macro-save-btn');
    const saveBtnTxt = document.getElementById('btn-save-text');
    const btnAutoCal = document.getElementById('btn-auto-calc-cals');
    const btnReset   = document.getElementById('btn-reset-macros');
    const modeInput  = document.getElementById('macro-input-mode');

    const tabAdd = document.getElementById('tab-mode-add');
    const tabSet = document.getElementById('tab-mode-set');

    const lblCals  = document.getElementById('lbl-text-cals');
    const lblPro   = document.getElementById('lbl-text-pro');
    const lblCarbs = document.getElementById('lbl-text-carbs');
    const lblFat   = document.getElementById('lbl-text-fat');
    
    // Inputs
    const inCals  = document.getElementById('macro-input-cals');
    const inPro   = document.getElementById('macro-input-pro');
    const inCarbs = document.getElementById('macro-input-carbs');
    const inFat   = document.getElementById('macro-input-fat');

    // Displays
    const calsDisp  = document.getElementById('macro-logged-cals');
    const tarDisp   = document.getElementById('macro-target-cals');
    const pctCals   = document.getElementById('macro-pct-cals');
    const barCals   = document.getElementById('macro-progress-bar-cals');

    const proDisp   = document.getElementById('macro-logged-pro');
    const tarPro    = document.getElementById('macro-target-pro');
    const pctPro    = document.getElementById('macro-pct-pro');
    const barPro    = document.getElementById('macro-progress-bar-pro');

    const carbsDisp = document.getElementById('macro-logged-carbs');
    const tarCarbs  = document.getElementById('macro-target-carbs');
    const pctCarbs  = document.getElementById('macro-pct-carbs');
    const barCarbs  = document.getElementById('macro-progress-bar-carbs');

    const fatDisp   = document.getElementById('macro-logged-fat');
    const tarFat    = document.getElementById('macro-target-fat');
    const pctFat    = document.getElementById('macro-pct-fat');
    const barFat    = document.getElementById('macro-progress-bar-fat');

    if (!form) return;
    const csrf = form.querySelector('[name="csrf_token"]').value;

    window.setMacroLogMode = function(mode) {
        if (!modeInput) return;
        modeInput.value = mode;

        if (mode === 'add') {
            tabAdd?.classList.add('active');
            tabSet?.classList.remove('active');

            if (lblCals)  lblCals.textContent  = '+ Add Calories';
            if (lblPro)   lblPro.textContent   = '+ Add Protein (g)';
            if (lblCarbs) lblCarbs.textContent = '+ Add Carbs (g)';
            if (lblFat)   lblFat.textContent   = '+ Add Fat (g)';

            if (inCals)  inCals.placeholder  = '+0';
            if (inPro)   inPro.placeholder   = '+0';
            if (inCarbs) inCarbs.placeholder = '+0';
            if (inFat)   inFat.placeholder   = '+0';

            if (inCals)  inCals.value  = '';
            if (inPro)   inPro.value   = '';
            if (inCarbs) inCarbs.value = '';
            if (inFat)   inFat.value   = '';

            if (saveBtnTxt) saveBtnTxt.textContent = '+ Add to Log';
        } else {
            tabSet?.classList.add('active');
            tabAdd?.classList.remove('active');

            if (lblCals)  lblCals.textContent  = 'Total Calories';
            if (lblPro)   lblPro.textContent   = 'Total Protein (g)';
            if (lblCarbs) lblCarbs.textContent = 'Total Carbs (g)';
            if (lblFat)   lblFat.textContent   = 'Total Fat (g)';

            if (inCals)  inCals.placeholder  = '0';
            if (inPro)   inPro.placeholder   = '0';
            if (inCarbs) inCarbs.placeholder = '0';
            if (inFat)   inFat.placeholder   = '0';

            // Populate with current daily totals
            if (inCals)  inCals.value  = (calsDisp?.textContent?.trim()  || '0');
            if (inPro)   inPro.value   = (proDisp?.textContent?.trim()   || '0');
            if (inCarbs) inCarbs.value = (carbsDisp?.textContent?.trim() || '0');
            if (inFat)   inFat.value   = (fatDisp?.textContent?.trim()   || '0');

            if (saveBtnTxt) saveBtnTxt.textContent = 'Update Total';
        }
    };

    // -------------------------------------------------------------
    // Smart Meal Assistant Handlers (CalorieNinjas & Open Food Facts)
    // -------------------------------------------------------------
    const tabSmartNinja   = document.getElementById('tab-smart-ninja');
    const tabSmartOff     = document.getElementById('tab-smart-off');
    const panelSmartNinja = document.getElementById('panel-smart-ninja');
    const panelSmartOff   = document.getElementById('panel-smart-off');

    const ninjaInput      = document.getElementById('smart-ninja-input');
    const ninjaBtn        = document.getElementById('btn-analyze-ninja');
    const ninjaBtnTxt     = document.getElementById('btn-analyze-ninja-txt');
    const ninjaBreakdown  = document.getElementById('ninja-breakdown-wrap');
    const ninjaItemsEl    = document.getElementById('ninja-breakdown-items');
    const ninjaTotalsEl   = document.getElementById('ninja-breakdown-totals');

    const offInput        = document.getElementById('smart-off-input');
    const offBtn          = document.getElementById('btn-search-off');
    const offBtnTxt       = document.getElementById('btn-search-off-txt');
    const offServingInput = document.getElementById('off-serving-input');
    const offContainer    = document.getElementById('off-results-container');
    const offGrid         = document.getElementById('off-results-grid');

    window.switchSmartTab = function(tab) {
        if (tab === 'ninja') {
            tabSmartNinja?.classList.add('active');
            tabSmartOff?.classList.remove('active');
            panelSmartNinja?.classList.add('active');
            panelSmartOff?.classList.remove('active');
            ninjaInput?.focus();
        } else {
            tabSmartOff?.classList.add('active');
            tabSmartNinja?.classList.remove('active');
            panelSmartOff?.classList.add('active');
            panelSmartNinja?.classList.remove('active');
            offInput?.focus();
        }
    };

    window.setQuickMealText = function(text) {
        if (!ninjaInput) return;
        ninjaInput.value = text;
        analyzeMealText();
    };

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function applyMacrosToForm(cals, pro, carbs, fat, foodName = '') {
        if (inCals)  inCals.value  = Math.round(parseFloat(cals) || 0);
        if (inPro)   inPro.value   = Math.round((parseFloat(pro) || 0) * 10) / 10;
        if (inCarbs) inCarbs.value = Math.round((parseFloat(carbs) || 0) * 10) / 10;
        if (inFat)   inFat.value   = Math.round((parseFloat(fat) || 0) * 10) / 10;

        // Visual flash pulse on inputs
        [inCals, inPro, inCarbs, inFat].forEach(inp => {
            if (!inp) return;
            inp.classList.remove('field-highlight-flash');
            void inp.offsetWidth; // trigger reflow
            inp.classList.add('field-highlight-flash');
        });

        // Toast notification
        const bgVal  = getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721';
        const inkVal = getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff';
        const Toast = Swal.mixin({
            toast: true, position: 'top-end', showConfirmButton: false,
            timer: 3500, timerProgressBar: true,
            background: bgVal, color: inkVal
        });

        const label = foodName ? `<strong>${escapeHtml(foodName)}</strong>` : 'Meal';
        Toast.fire({
            icon: 'success',
            title: `Applied ${label} (${Math.round(cals)} kcal) to inputs below!`
        });

        // Scroll to form fields
        form?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    async function analyzeMealText() {
        const text = ninjaInput?.value?.trim();
        if (!text) {
            ninjaInput?.focus();
            return;
        }

        if (ninjaBtn) ninjaBtn.disabled = true;
        if (ninjaBtnTxt) ninjaBtnTxt.textContent = 'Analyzing...';

        try {
            const res = await fetch('index.php?page=food_lookup&action=calorieninjas&query=' + encodeURIComponent(text));
            const data = await res.json();

            if (!data || !data.success) {
                Swal.fire({
                    icon: 'info',
                    title: 'Meal Not Found',
                    text: data?.error || 'Could not parse nutritional data for this query.',
                    background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                    color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff'
                });
                return;
            }

            // Render breakdown
            if (ninjaItemsEl && data.items && data.items.length) {
                ninjaItemsEl.innerHTML = data.items.map(item => `
                    <div class="smart-breakdown-item">
                        <span><strong>${escapeHtml(item.name)}</strong> (${item.serving_size_g}g)</span>
                        <span style="color: var(--muted); font-size: 11.5px;">
                            <strong style="color: var(--macro-cals);">${item.calories} kcal</strong> &bull;
                            <span style="color: var(--macro-pro);">${item.protein_g}g P</span> &bull;
                            <span style="color: var(--macro-carbs);">${item.carbs_g}g C</span> &bull;
                            <span style="color: var(--macro-fat);">${item.fat_g}g F</span>
                        </span>
                    </div>
                `).join('');
            }

            if (ninjaTotalsEl) {
                ninjaTotalsEl.innerHTML = `
                    <span style="color: var(--macro-cals);">${data.total_calories} kcal</span> &bull;
                    <span style="color: var(--macro-pro);">${data.total_protein}g Protein</span> &bull;
                    <span style="color: var(--macro-carbs);">${data.total_carbs}g Carbs</span> &bull;
                    <span style="color: var(--macro-fat);">${data.total_fat}g Fat</span>
                `;
            }

            if (ninjaBreakdown) ninjaBreakdown.style.display = 'block';

            applyMacrosToForm(data.total_calories, data.total_protein, data.total_carbs, data.total_fat, 'Meal');
        } catch (err) {
            Swal.fire({
                icon: 'error',
                title: 'Lookup Error',
                text: 'Could not connect to nutrition service. Please try again.',
                background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff'
            });
        } finally {
            if (ninjaBtn) ninjaBtn.disabled = false;
            if (ninjaBtnTxt) ninjaBtnTxt.textContent = 'Analyze & Fill';
        }
    }

    if (ninjaBtn) {
        ninjaBtn.addEventListener('click', analyzeMealText);
    }
    if (ninjaInput) {
        ninjaInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                analyzeMealText();
            }
        });
    }

    let cachedOffProducts = [];

    function renderOffProducts() {
        if (!offGrid || !cachedOffProducts.length) return;
        const portion = Math.max(1, parseFloat(offServingInput?.value || 100) || 100);
        const ratio = portion / 100;

        offGrid.innerHTML = cachedOffProducts.map((p, idx) => {
            const pCals = Math.round(p.calories_100g * ratio);
            const pPro = Math.round((p.protein_100g * ratio) * 10) / 10;
            const pCarbs = Math.round((p.carbs_100g * ratio) * 10) / 10;
            const pFat = Math.round((p.fat_100g * ratio) * 10) / 10;

            return `
                <div class="off-food-card" onclick="selectOffFood(${idx})">
                    <div>
                        <div style="font-size: 11px; color: var(--muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.3px;">
                            ${escapeHtml(p.brand || 'Food Product')}
                        </div>
                        <div style="font-size: 12.5px; font-weight: 700; color: var(--ink); margin: 2px 0 6px; line-height: 1.3;">
                            ${escapeHtml(p.name)}
                        </div>
                    </div>
                    <div>
                        <div style="display: flex; gap: 5px; flex-wrap: wrap; margin-bottom: 6px; font-size: 11px; font-weight: 700;">
                            <span style="color: var(--macro-cals); background: color-mix(in srgb, var(--macro-cals) 14%, transparent); padding: 2px 6px; border-radius: 6px;">${pCals} kcal</span>
                            <span style="color: var(--macro-pro); background: color-mix(in srgb, var(--macro-pro) 14%, transparent); padding: 2px 6px; border-radius: 6px;">${pPro}g P</span>
                            <span style="color: var(--macro-carbs); background: color-mix(in srgb, var(--macro-carbs) 14%, transparent); padding: 2px 6px; border-radius: 6px;">${pCarbs}g C</span>
                            <span style="color: var(--macro-fat); background: color-mix(in srgb, var(--macro-fat) 14%, transparent); padding: 2px 6px; border-radius: 6px;">${pFat}g F</span>
                        </div>
                        <div style="font-size: 10.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;">
                            <span>For ${portion}g</span>
                            <span style="color: var(--macro-pro); font-weight: 700;">Apply ➔</span>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    window.selectOffFood = function(idx) {
        const p = cachedOffProducts[idx];
        if (!p) return;
        const portion = Math.max(1, parseFloat(offServingInput?.value || 100) || 100);
        const ratio = portion / 100;

        const pCals = Math.round(p.calories_100g * ratio);
        const pPro = Math.round((p.protein_100g * ratio) * 10) / 10;
        const pCarbs = Math.round((p.carbs_100g * ratio) * 10) / 10;
        const pFat = Math.round((p.fat_100g * ratio) * 10) / 10;

        applyMacrosToForm(pCals, pPro, pCarbs, pFat, p.name);
    };

    window.adjustOffServing = function(delta) {
        if (!offServingInput) return;
        let val = parseFloat(offServingInput.value || 100) || 100;
        val = Math.max(5, Math.min(2000, val + delta));
        offServingInput.value = val;
        renderOffProducts();
    };

    if (offServingInput) {
        offServingInput.addEventListener('input', renderOffProducts);
    }

    async function searchOpenFoodFacts() {
        const query = offInput?.value?.trim();
        if (!query) {
            offInput?.focus();
            return;
        }

        if (offBtn) offBtn.disabled = true;
        if (offBtnTxt) offBtnTxt.textContent = 'Searching...';

        try {
            const res = await fetch('index.php?page=food_lookup&action=openfoodfacts&query=' + encodeURIComponent(query));
            const data = await res.json();

            if (!data || !data.success || !data.products || !data.products.length) {
                Swal.fire({
                    icon: 'info',
                    title: 'No Results',
                    text: data?.error || 'No branded foods found for this query.',
                    background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                    color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff'
                });
                return;
            }

            cachedOffProducts = data.products;
            if (offContainer) offContainer.style.display = 'block';
            renderOffProducts();
        } catch (err) {
            Swal.fire({
                icon: 'error',
                title: 'Search Error',
                text: 'Could not connect to Open Food Facts. Please try again.',
                background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff'
            });
        } finally {
            if (offBtn) offBtn.disabled = false;
            if (offBtnTxt) offBtnTxt.textContent = 'Search';
        }
    }

    if (offBtn) {
        offBtn.addEventListener('click', searchOpenFoodFacts);
    }
    if (offInput) {
        offInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchOpenFoodFacts();
            }
        });
    }

    // Helper: auto calculate calories from macros: (P * 4) + (C * 4) + (F * 9)
    function calcCaloriesFromMacros() {
        const p = parseFloat(inPro?.value || 0) || 0;
        const c = parseFloat(inCarbs?.value || 0) || 0;
        const f = parseFloat(inFat?.value || 0) || 0;
        return Math.round((p * 4) + (c * 4) + (f * 9));
    }

    if (btnAutoCal) {
        btnAutoCal.addEventListener('click', function() {
            const calculated = calcCaloriesFromMacros();
            if (calculated > 0 && inCals) {
                inCals.value = calculated;
                inCals.style.transition = 'background 0.3s ease';
                inCals.style.background = 'rgba(199,255,34,0.25)';
                setTimeout(() => { inCals.style.background = ''; }, 400);
            }
        });
    }

    function shootConfetti(particleCount = 70) {
        if (typeof confetti === 'function') {
            try {
                confetti({
                    particleCount: particleCount,
                    spread: 70,
                    origin: { y: 0.6 }
                });
            } catch (e) {}
        }
    }

    function fireCelebrationModal({
        themeColor,
        themeGlow,
        badgeText,
        iconSvg,
        title,
        metricLabel,
        loggedVal,
        targetVal,
        description,
        btnText,
        btnBg,
        isAll = false,
        allStats = null
    }) {
        let statsCardHtml = '';
        if (isAll && allStats) {
            statsCardHtml = `
                <div class="celebration-card-wrap" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 14px 10px; margin: 16px 0; display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;">
                    <div class="celebration-mini-stat" style="background: rgba(0,0,0,0.3); border: 1px solid color-mix(in srgb, var(--macro-cals) 25%, transparent); border-radius: 8px; padding: 8px 4px; text-align:center;">
                        <div style="font-size: 10px; font-weight: 700; color: var(--macro-cals); text-transform: uppercase;">Calories</div>
                        <div class="mini-val" style="font-size: 14px; font-weight: 800; color: #fff; margin: 3px 0;">${allStats.cals}</div>
                        <div style="font-size: 10px; color: #22c55e; font-weight: 700;">Target Met</div>
                    </div>
                    <div class="celebration-mini-stat" style="background: rgba(0,0,0,0.3); border: 1px solid color-mix(in srgb, var(--macro-pro) 25%, transparent); border-radius: 8px; padding: 8px 4px; text-align:center;">
                        <div style="font-size: 10px; font-weight: 700; color: var(--macro-pro); text-transform: uppercase;">Protein</div>
                        <div class="mini-val" style="font-size: 14px; font-weight: 800; color: #fff; margin: 3px 0;">${allStats.pro}g</div>
                        <div style="font-size: 10px; color: #22c55e; font-weight: 700;">Target Met</div>
                    </div>
                    <div class="celebration-mini-stat" style="background: rgba(0,0,0,0.3); border: 1px solid color-mix(in srgb, var(--macro-carbs) 25%, transparent); border-radius: 8px; padding: 8px 4px; text-align:center;">
                        <div style="font-size: 10px; font-weight: 700; color: var(--macro-carbs); text-transform: uppercase;">Carbs</div>
                        <div class="mini-val" style="font-size: 14px; font-weight: 800; color: #fff; margin: 3px 0;">${allStats.carbs}g</div>
                        <div style="font-size: 10px; color: #22c55e; font-weight: 700;">Target Met</div>
                    </div>
                    <div class="celebration-mini-stat" style="background: rgba(0,0,0,0.3); border: 1px solid color-mix(in srgb, var(--macro-fat) 25%, transparent); border-radius: 8px; padding: 8px 4px; text-align:center;">
                        <div style="font-size: 10px; font-weight: 700; color: var(--macro-fat); text-transform: uppercase;">Fat</div>
                        <div class="mini-val" style="font-size: 14px; font-weight: 800; color: #fff; margin: 3px 0;">${allStats.fat}g</div>
                        <div style="font-size: 10px; color: #22c55e; font-weight: 700;">Target Met</div>
                    </div>
                </div>`;
        } else {
            statsCardHtml = `
                <div class="celebration-card-wrap" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 14px 18px; margin: 16px 0; text-align: left;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <span style="font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px;">${metricLabel} Goal</span>
                        <span style="font-size: 11px; font-weight: 800; color: #22c55e; background: rgba(34,197,94,0.12); padding: 2px 8px; border-radius: 12px; border: 1px solid rgba(34,197,94,0.3); display: inline-flex; align-items: center; gap: 4px;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            Target Met
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px;">
                        <div>
                            <span style="font-size: 26px; font-weight: 900; color: ${themeColor};">${loggedVal}</span>
                            <span style="font-size: 12px; color: var(--muted); margin-left: 4px;">logged</span>
                        </div>
                        <span style="font-size: 13px; color: var(--muted); font-weight: 500;">Goal: <strong style="color:var(--ink);">${targetVal}</strong></span>
                    </div>
                    <div class="celebration-track" style="height: 6px; background: rgba(255,255,255,0.08); border-radius: 3px; overflow: hidden;">
                        <div style="height: 100%; width: 100%; background: #22c55e; border-radius: 3px;"></div>
                    </div>
                </div>`;
        }

        const modalHtml = `
            <div style="text-align: center; padding: 6px 4px;">
                <!-- Glowing Hero Icon -->
                <div class="celebration-hero-pulse" style="width: 76px; height: 76px; margin: 0 auto 16px; border-radius: 50%; background: radial-gradient(circle, ${themeGlow} 0%, rgba(15,20,17,0.92) 100%); border: 2px solid ${themeColor}; display: flex; align-items: center; justify-content: center; box-shadow: 0 0 40px ${themeGlow};">
                    ${iconSvg}
                </div>

                <!-- Milestone Tag -->
                <div style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; background: rgba(255,255,255,0.05); border: 1px solid ${themeColor}50; border-radius: 20px; font-size: 11px; font-weight: 800; color: ${themeColor}; letter-spacing: 0.8px; text-transform: uppercase; margin-bottom: 10px;">
                    <span style="width: 6px; height: 6px; border-radius: 50%; background: ${themeColor};"></span>
                    ${badgeText}
                </div>

                <!-- Title -->
                <h2 class="celebration-title">
                    ${title}
                </h2>

                <!-- Metric Breakdown -->
                ${statsCardHtml}

                <!-- Tip / Description -->
                <p class="celebration-desc">
                    ${description}
                </p>

                <!-- Action Buttons: Clear Affirmative + Explicit Close -->
                <div style="display: flex; justify-content: center; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 4px;">
                    <button type="button" class="celebration-action-btn celebration-close-trigger" style="background: ${btnBg}; color: var(--macro-btn-ink); font-weight: 800; font-size: 13.5px; border: none; padding: 11px 24px; border-radius: 9px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 8px 24px ${themeGlow};">
                        <span>Got it, continue!</span>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    </button>
                    <button type="button" class="celebration-secondary-btn celebration-close-trigger" style="background: transparent; color: var(--muted); font-weight: 600; font-size: 13px; border: 1px solid var(--line); padding: 10px 18px; border-radius: 9px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                        Close
                    </button>
                </div>
                <div style="font-size: 11.5px; color: var(--muted); margin-top: 13px;">
                    This milestone has been recorded in today's daily log.
                </div>
            </div>
        `;

        Swal.fire({
            html: modalHtml,
            showConfirmButton: false,
            showCloseButton: true,
            allowOutsideClick: true,
            allowEscapeKey: true,
            background: 'transparent',
            color: '#ffffff',
            width: 460,
            padding: '24px 20px 20px',
            customClass: {
                popup: 'diet-celebration-popup'
            },
            didOpen: (popup) => {
                const dismissModal = (e) => {
                    if (e) {
                        e.preventDefault();
                    }
                    Swal.close();
                    // Fallback to guarantee backdrop and popup cleanup in case of animation delay
                    setTimeout(() => {
                        const activeContainer = document.querySelector('.swal2-container');
                        if (activeContainer && activeContainer.querySelector('.diet-celebration-popup')) {
                            activeContainer.remove();
                            document.body.classList.remove('swal2-shown', 'swal2-height-auto');
                        }
                    }, 240);
                };

                // Ensure top-right 'X' button always dismisses reliably
                const closeBtn = popup.querySelector('.swal2-close');
                if (closeBtn) {
                    closeBtn.setAttribute('title', 'Close dialog');
                    closeBtn.onclick = dismissModal;
                }
                // Ensure all close buttons dismiss reliably
                popup.querySelectorAll('.celebration-close-trigger').forEach(function(btn) {
                    btn.onclick = dismissModal;
                });
            }
        });
    }

    // Reset button handler
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            Swal.fire({
                title: 'Reset Today\'s Log?',
                text: 'This will reset your logged calories, protein, carbs, and fat for today back to 0.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: 'transparent',
                confirmButtonText: 'Yes, Reset Today',
                cancelButtonText: 'Cancel',
                background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
            }).then(async (result) => {
                if (result.isConfirmed) {
                    await submitMacroLog('reset');
                }
            });
        });
    }

    async function submitMacroLog(forcedMode = null) {
        saveBtn.disabled = true;
        saveBtn.style.opacity = '0.7';

        const activeMode = forcedMode || modeInput?.value || 'add';

        // Validate that user is not submitting negative numbers
        const valCals  = parseFloat(inCals?.value  || 0);
        const valPro   = parseFloat(inPro?.value   || 0);
        const valCarbs = parseFloat(inCarbs?.value || 0);
        const valFat   = parseFloat(inFat?.value   || 0);

        if (activeMode !== 'reset' && (valCals < 0 || valPro < 0 || valCarbs < 0 || valFat < 0)) {
            const bgVal  = getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721';
            const inkVal = getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff';
            Swal.fire({
                icon: 'warning',
                title: 'Positive Numbers Only',
                text: 'Calories and macronutrients cannot be negative. Please enter 0 or a positive value.',
                background: bgVal,
                color: inkVal,
                confirmButtonColor: 'var(--lime, #c7ff22)',
                confirmButtonText: 'Understood'
            });
            saveBtn.disabled = false;
            saveBtn.style.opacity = '1';
            return;
        }

        // Capture previous values before update to detect newly unlocked milestones
        const prevPro   = parseFloat(proDisp?.textContent   || 0) || 0;
        const prevCals  = parseFloat(calsDisp?.textContent  || 0) || 0;
        const prevCarbs = parseFloat(carbsDisp?.textContent || 0) || 0;
        const prevFat   = parseFloat(fatDisp?.textContent   || 0) || 0;

        // In 'add' mode, auto-fill calories if left empty but macros were entered
        if (activeMode === 'add' && (!inCals.value || inCals.value === '0') && (inPro.value || inCarbs.value || inFat.value)) {
            inCals.value = calcCaloriesFromMacros();
        }

        const body = new URLSearchParams({
            mode:       activeMode,
            calories:   activeMode === 'reset' ? '0' : (inCals?.value  || '0'),
            protein_g:  activeMode === 'reset' ? '0' : (inPro?.value   || '0'),
            carbs_g:    activeMode === 'reset' ? '0' : (inCarbs?.value || '0'),
            fat_g:      activeMode === 'reset' ? '0' : (inFat?.value   || '0'),
            csrf_token: csrf
        });

        let data = null;
        try {
            const res = await fetch('index.php?page=log_macros', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: body.toString()
            });
            const text = await res.text();
            try {
                data = JSON.parse(text);
            } catch (jsonErr) {
                console.error("Non-JSON response from log_macros:", text);
                data = { success: false, error: 'Server response error. Please try again.' };
            }
        } catch (err) {
            const bgVal  = getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721';
            const inkVal = getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff';
            Swal.fire({
                icon: 'error',
                title: 'Connection Error',
                text: 'Could not reach the server. Please check your network connection.',
                background: bgVal,
                color: inkVal,
                confirmButtonColor: 'var(--lime, #c7ff22)',
                confirmButtonText: 'OK'
            });
            saveBtn.disabled = false;
            saveBtn.style.opacity = '1';
            return;
        }

        window.applyMacroLogResultToUI = function(data, isManual = false, activeMode = 'add') {
            if (!data || !data.success) return;

            // If user reset today's macros, reset meal slot cards too
            if (activeMode === 'reset') {
                if (typeof resetTodayMealCardsUI === 'function') {
                    resetTodayMealCardsUI();
                }
            }

            const prevPro   = parseFloat(proDisp?.textContent   || 0) || 0;
            const prevCals  = parseFloat(calsDisp?.textContent  || 0) || 0;
            const prevCarbs = parseFloat(carbsDisp?.textContent || 0) || 0;
            const prevFat   = parseFloat(fatDisp?.textContent   || 0) || 0;

            const newCals  = data.logged_cals;
            const newPro   = data.logged_pro;
            const newCarbs = data.logged_carbs;
            const newFat   = data.logged_fat;

            const targetCals  = data.target_cals;
            const targetPro   = data.target_pro;
            const targetCarbs = data.target_carbs;
            const targetFat   = data.target_fat;

            function formatStatus(logged, target, type) {
                if (target <= 0) {
                    return { text: '0%', color: 'var(--muted)', width: '0%', barBg: `var(--macro-${type})` };
                }
                const diff = logged - target;
                const pct = Math.round((logged / target) * 100);

                if (diff > 0) {
                    if (type === 'pro') {
                        return { text: `+${diff}g Over`, color: '#22c55e', width: '100%', barBg: '#22c55e' };
                    } else if (type === 'cals') {
                        return { text: `+${diff} kcal Over`, color: '#f59e0b', width: '100%', barBg: '#f59e0b' };
                    } else if (type === 'carbs') {
                        return { text: `+${diff}g Over`, color: '#f59e0b', width: '100%', barBg: '#f59e0b' };
                    } else { // fat
                        return { text: `+${diff}g Over`, color: '#f43f5e', width: '100%', barBg: '#f43f5e' };
                    }
                } else if (diff === 0) {
                    return { text: '100% ✓ Met', color: '#22c55e', width: '100%', barBg: '#22c55e' };
                } else {
                    return { text: `${pct}%`, color: 'var(--muted)', width: `${Math.min(100, pct)}%`, barBg: `var(--macro-${type})` };
                }
            }

            const calsProg  = formatStatus(newCals, targetCals, 'cals');
            const proProg   = formatStatus(newPro, targetPro, 'pro');
            const carbsProg = formatStatus(newCarbs, targetCarbs, 'carbs');
            const fatProg   = formatStatus(newFat, targetFat, 'fat');

            // Calories update
            if (calsDisp) calsDisp.textContent = newCals;
            if (tarDisp && targetCals) tarDisp.textContent = targetCals;
            if (pctCals) {
                pctCals.textContent = calsProg.text;
                pctCals.style.color = calsProg.color;
            }
            if (barCals) {
                barCals.style.width = calsProg.width;
                barCals.style.background = calsProg.barBg;
            }

            // Protein update
            if (proDisp) proDisp.textContent = newPro;
            if (tarPro && targetPro) tarPro.textContent = targetPro;
            if (pctPro) {
                pctPro.textContent = proProg.text;
                pctPro.style.color = proProg.color;
            }
            if (barPro) {
                barPro.style.width = proProg.width;
                barPro.style.background = proProg.barBg;
            }

            // Carbs update
            if (carbsDisp) carbsDisp.textContent = newCarbs;
            if (tarCarbs && targetCarbs) tarCarbs.textContent = targetCarbs;
            if (pctCarbs) {
                pctCarbs.textContent = carbsProg.text;
                pctCarbs.style.color = carbsProg.color;
            }
            if (barCarbs) {
                barCarbs.style.width = carbsProg.width;
                barCarbs.style.background = carbsProg.barBg;
            }

            // Fat update
            if (fatDisp) fatDisp.textContent = newFat;
            if (tarFat && targetFat) tarFat.textContent = targetFat;
            if (pctFat) {
                pctFat.textContent = fatProg.text;
                pctFat.style.color = fatProg.color;
            }
            if (barFat) {
                barFat.style.width = fatProg.width;
                barFat.style.background = fatProg.barBg;
            }

            // Update top tab navigation badge
            const trackerBadge = document.querySelector('.diet-nav-badge.tracker-badge');
            if (trackerBadge && targetCals) {
                trackerBadge.textContent = `${newCals} / ${targetCals} kcal`;
            }

            // Detect newly reached goals
            const reachedPro   = (prevPro < targetPro) && (newPro >= targetPro) && (targetPro > 0);
            const reachedCals  = (prevCals < targetCals) && (newCals >= targetCals) && (targetCals > 0);
            const reachedCarbs = (prevCarbs < targetCarbs) && (newCarbs >= targetCarbs) && (targetCarbs > 0);
            const reachedFat   = (prevFat < targetFat) && (newFat >= targetFat) && (targetFat > 0);

            const allNowMet  = (newCals >= targetCals && newPro >= targetPro && newCarbs >= targetCarbs && newFat >= targetFat) && (targetCals > 0 && targetPro > 0);
            const prevAllMet = (prevCals >= targetCals && prevPro >= targetPro && prevCarbs >= targetCarbs && prevFat >= targetFat);
            const reachedAll = allNowMet && !prevAllMet;

            const bgVal  = getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721';
            const inkVal = getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff';

            if (reachedAll) {
                shootConfetti(120);
                setTimeout(() => {
                    fireCelebrationModal({
                        themeColor: 'var(--lime, #c7ff22)',
                        themeGlow: 'rgba(199, 255, 34, 0.45)',
                        badgeText: 'DAILY TARGETS COMPLETED',
                        iconSvg: '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"></path><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"></path><path d="M4 22h16"></path><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"></path><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"></path><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"></path></svg>',
                        title: 'Perfect Macro Day!',
                        description: 'Incredible dedication! You have successfully reached every single nutritional target scheduled for today.',
                        btnText: 'Keep the Streak',
                        btnBg: 'var(--lime, #c7ff22)',
                        isAll: true,
                        allStats: { cals: newCals, pro: newPro, carbs: newCarbs, fat: newFat }
                    });
                }, 250);
            } else if (reachedPro) {
                shootConfetti(80);
                setTimeout(() => {
                    fireCelebrationModal({
                        themeColor: '#38bdf8',
                        themeGlow: 'rgba(56, 189, 248, 0.45)',
                        badgeText: 'PROTEIN GOAL ACHIEVED',
                        iconSvg: '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#38bdf8" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6.5 6.5 11 11"/><path d="m21 21-1-1"/><path d="m3 3 1 1"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/><path d="m3 10 7-7"/><path d="m14 21 7-7"/></svg>',
                        title: 'Protein Target Crushed!',
                        metricLabel: 'Protein',
                        loggedVal: newPro + 'g',
                        targetVal: targetPro + 'g',
                        description: 'Hitting your daily protein goal accelerates muscle recovery, preserves lean tissue, and sustains your strength.',
                        btnText: 'Keep Building',
                        btnBg: '#38bdf8'
                    });
                }, 250);
            } else if (reachedCals) {
                shootConfetti(70);
                setTimeout(() => {
                    fireCelebrationModal({
                        themeColor: 'var(--lime, #c7ff22)',
                        themeGlow: 'rgba(199, 255, 34, 0.45)',
                        badgeText: 'CALORIE GOAL ACHIEVED',
                        iconSvg: '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
                        title: 'Calorie Target Reached!',
                        metricLabel: 'Calories',
                        loggedVal: newCals + ' kcal',
                        targetVal: targetCals + ' kcal',
                        description: 'You reached your planned daily energy intake, keeping your nutrition perfectly aligned with your fitness goal.',
                        btnText: 'Continue',
                        btnBg: 'var(--lime, #c7ff22)'
                    });
                }, 250);
            } else if (reachedCarbs) {
                shootConfetti(60);
                setTimeout(() => {
                    fireCelebrationModal({
                        themeColor: '#fbbf24',
                        themeGlow: 'rgba(251, 191, 36, 0.45)',
                        badgeText: 'CARBOHYDRATES TARGET MET',
                        iconSvg: '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#fbbf24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>',
                        title: 'Carbs Target Met!',
                        metricLabel: 'Carbs',
                        loggedVal: newCarbs + 'g',
                        targetVal: targetCarbs + 'g',
                        description: 'Your glycogen stores are refueled and ready to power your next training session.',
                        btnText: 'Awesome',
                        btnBg: '#fbbf24'
                    });
                }, 250);
            } else if (reachedFat) {
                shootConfetti(60);
                setTimeout(() => {
                    fireCelebrationModal({
                        themeColor: '#f43f5e',
                        themeGlow: 'rgba(244, 63, 94, 0.45)',
                        badgeText: 'HEALTHY FATS TARGET MET',
                        iconSvg: '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#f43f5e" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><path d="m9 12 2 2 4-4"></path></svg>',
                        title: 'Healthy Fat Target Met!',
                        metricLabel: 'Fat',
                        loggedVal: newFat + 'g',
                        targetVal: targetFat + 'g',
                        description: 'Healthy fats optimize hormone balance, joint health, and steady long-term energy.',
                        btnText: 'Awesome',
                        btnBg: '#f43f5e'
                    });
                }, 250);
            } else if (isManual) {
                // Standard Toast feedback with clean text, no emojis
                const Toast = Swal.mixin({
                    toast: true, position: 'top-end', showConfirmButton: false,
                    timer: 3000, timerProgressBar: true,
                    background: bgVal,
                    color: inkVal,
                });

                let toastMsg = 'Macros saved for today.';
                if (activeMode === 'add') {
                    toastMsg = `Added to today's log. Total: <strong>${data.logged_cals} kcal</strong>`;
                } else if (activeMode === 'reset') {
                    toastMsg = 'Today\'s log reset to 0.';
                } else {
                    toastMsg = `Today's totals updated. Total: <strong>${data.logged_cals} kcal</strong>`;
                }

                Toast.fire({ icon: 'success', title: toastMsg });
            }
        };

        if (data && data.success) {
            window.applyMacroLogResultToUI(data, true, activeMode);

            // In 'add' or 'reset' mode, clear inputs ready for the next food entry
            if (activeMode === 'add' || activeMode === 'reset') {
                inCals.value = '';
                inPro.value = '';
                inCarbs.value = '';
                inFat.value = '';
            }
        } else {
            const bgVal  = getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721';
            const inkVal = getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff';
            const msg = (data && data.error) ? data.error : 'Could not save macros. Please try again.';
            Swal.fire({
                icon: 'warning',
                title: 'Unable to Save',
                text: msg,
                background: bgVal,
                color: inkVal,
                confirmButtonColor: 'var(--lime, #c7ff22)',
                confirmButtonText: 'OK'
            });
        }

        saveBtn.disabled = false;
        saveBtn.style.opacity = '1';
    }

    // Input sanitization: block minus / negative typing & pasting on macro fields
    [inCals, inPro, inCarbs, inFat].forEach(inp => {
        if (!inp) return;
        inp.addEventListener('keydown', function(e) {
            if (e.key === '-' || e.key === 'Subtract') {
                e.preventDefault();
            }
        });
        inp.addEventListener('input', function() {
            if (this.value !== '' && parseFloat(this.value) < 0) {
                this.value = '0';
            }
        });
        inp.addEventListener('paste', function(e) {
            const text = (e.clipboardData || window.clipboardData)?.getData('text');
            if (text && text.includes('-')) {
                e.preventDefault();
                const sanitized = text.replace(/[^0-9.]/g, '');
                this.value = sanitized;
            }
        });
    });

    if (offServingInput) {
        offServingInput.addEventListener('keydown', function(e) {
            if (e.key === '-' || e.key === 'Subtract') {
                e.preventDefault();
            }
        });
        offServingInput.addEventListener('input', function() {
            if (this.value !== '' && parseFloat(this.value) < 1) {
                this.value = '1';
            }
        });
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        submitMacroLog();
    });
})();
</script>
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
                        <div style="display: flex; align-items: center; gap: 5px;">
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
                                        <button type="button" 
                                                class="btn-edit-meal-photo" 
                                                id="btn-edit-photo-<?= $meal['meal_id'] ?>"
                                                data-meal-id="<?= $meal['meal_id'] ?>"
                                                data-food="<?= h($meal['food_items']) ?>"
                                                data-custom-url="<?= h($meal['image_url'] ?? '') ?>"
                                                data-resolved-url="<?= h($mealPhotoUrl) ?>"
                                                onclick="openMealPhotoModal(this)" 
                                                title="Change Photo (ImageKit or Custom Link)">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                            <span>Change</span>
                                        </button>
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
                <p class="ft-modal-subtitle" id="inspect-modal-subtitle">Itemized ingredients & micronutrients via CalorieNinjas</p>
            </div>
            <button type="button" class="ft-modal-close" onclick="closeInspectModal()" aria-label="Close modal">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>

        <div class="ft-modal-body">
            <!-- Planned Meal Header Bar -->
            <div class="inspect-meal-summary">
                <div class="inspect-meal-name" id="inspect-meal-name">Loading meal...</div>
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
                <div style="font-size: 11.5px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px;">
                    Itemized Ingredients & Macronutrients
                </div>
                <div class="inspect-items-wrap" id="inspect-items-container"></div>
                
                <!-- Micronutrient Highlights Banner -->
                <div id="inspect-micro-banner" class="inspect-micro-banner" style="display:none; margin-top: 14px;"></div>
            </div>

            <!-- Fallback/Notice -->
            <div id="inspect-fallback" style="display:none; padding:22px; text-align:center; background:var(--bg); border-radius:10px; border:1px dashed var(--line); margin:15px 0;">
                <p style="margin:0 0 6px 0; color:var(--ink); font-weight:700; font-size: 14px;" id="inspect-fallback-title">Planned Meal Targets</p>
                <p style="margin:0; color:var(--muted); font-size:12.5px;" id="inspect-fallback-msg">Detailed ingredient breakdown is being mapped from your assigned nutrition plan.</p>
            </div>
        </div>

        <!-- Modal Actions Footer -->
        <div class="ft-modal-footer">
            <button type="button" class="btn-cancel" onclick="closeInspectModal()">Close</button>
            <button type="button" id="btn-inspect-quick-log" class="btn-primary-action" onclick="quickLogFromInspection()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                <span>+ Log This Meal to Today</span>
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

<!-- ==================================================== -->
<!-- MODAL: CUSTOMIZE MEAL PHOTO (IMAGEKIT & URL)         -->
<!-- ==================================================== -->
<div id="modal-meal-photo" class="ft-modal-overlay" style="display:none;" onclick="if(event.target===this)closeMealPhotoModal()">
    <div class="ft-modal-box" style="max-width: 520px;">
        <div class="ft-modal-header">
            <div>
                <h3 class="ft-modal-title" style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    <span>Customize Meal Photo</span>
                </h3>
                <p class="ft-modal-subtitle" id="photo-modal-subtitle">Upload to ImageKit or enter a custom link</p>
            </div>
            <button type="button" class="ft-modal-close" onclick="closeMealPhotoModal()" aria-label="Close modal">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>

        <div class="ft-modal-body">
            <!-- Meal Item Banner Preview -->
            <div style="background: var(--bg); border: 1px solid var(--line); border-radius: 10px; overflow: hidden; margin-bottom: 16px;">
                <div style="position: relative; height: 160px; background: #0b0f17; overflow: hidden;">
                    <img id="photo-modal-preview-img" src="" alt="Preview" style="width: 100%; height: 100%; object-fit: cover; display: block;">
                    <div style="position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,0.2) 0%, rgba(0,0,0,0.75) 100%);"></div>
                    <div style="position: absolute; bottom: 10px; left: 12px; right: 12px;">
                        <span id="photo-modal-tag" style="display: inline-block; font-size: 10px; font-weight: 800; text-transform: uppercase; color: var(--lime); background: rgba(0,0,0,0.65); padding: 2px 7px; border-radius: 4px; border: 1px solid color-mix(in srgb, var(--lime) 40%, transparent); margin-bottom: 3px;">Auto-Matched Photo</span>
                        <div id="photo-modal-food-name" style="font-size: 13.5px; font-weight: 700; color: #fff; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">Meal name</div>
                    </div>
                </div>
            </div>

            <!-- Tabs: ImageKit Upload vs Direct URL vs Reset -->
            <div class="swap-tabs-bar" style="margin-bottom: 16px;">
                <button type="button" class="swap-tab-btn active" id="tab-btn-photo-upload" onclick="switchPhotoTab('upload')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <span>Upload (ImageKit)</span>
                </button>
                <button type="button" class="swap-tab-btn" id="tab-btn-photo-url" onclick="switchPhotoTab('url')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                    <span>Image URL</span>
                </button>
                <button type="button" class="swap-tab-btn" id="tab-btn-photo-reset" onclick="switchPhotoTab('reset')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
                    <span>Reset to Auto</span>
                </button>
            </div>

            <!-- Tab 1: Upload to ImageKit -->
            <div id="panel-photo-upload" class="photo-tab-panel">
                <div style="border: 2px dashed var(--line); border-radius: 10px; padding: 22px 16px; text-align: center; background: color-mix(in srgb, var(--surface) 40%, var(--bg)); cursor: pointer; transition: all 0.2s ease;" onclick="document.getElementById('photo-modal-file-input').click()">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="1.8" style="margin-bottom: 8px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <div style="font-size: 13.5px; font-weight: 700; color: var(--ink); margin-bottom: 4px;">Click to select photo</div>
                    <div style="font-size: 11.5px; color: var(--muted);">PNG, JPG, WebP up to 5MB • Saves directly to ImageKit CDN</div>
                    <input type="file" id="photo-modal-file-input" accept="image/jpeg,image/png,image/webp,image/gif" style="display: none;" onchange="handlePhotoFileSelected(this)">
                </div>
                <div id="photo-file-status" style="margin-top: 10px; font-size: 12px; color: var(--lime); font-weight: 600; display: none;"></div>
            </div>

            <!-- Tab 2: Direct Image URL -->
            <div id="panel-photo-url" class="photo-tab-panel" style="display: none;">
                <label style="display: block; font-size: 12px; font-weight: 700; color: var(--ink); margin-bottom: 6px;">Image Web Address (URL):</label>
                <input type="url" id="photo-modal-url-input" class="form-control" placeholder="https://... (e.g. Unsplash, ImageKit, Cloudinary)" style="width: 100%; padding: 10px 14px; border-radius: 8px; border: 1px solid var(--line); background: var(--bg); color: var(--ink); font-size: 13px;" oninput="handlePhotoUrlInput(this.value)">
                <div style="font-size: 11px; color: var(--muted); margin-top: 6px;">Paste any direct image link from the web to display on this meal card.</div>
            </div>

            <!-- Tab 3: Reset to Auto -->
            <div id="panel-photo-reset" class="photo-tab-panel" style="display: none;">
                <div style="background: color-mix(in srgb, var(--surface) 50%, var(--bg)); border: 1px solid var(--line); border-radius: 8px; padding: 16px; text-align: center;">
                    <div style="font-weight: 700; color: var(--ink); font-size: 13.5px; margin-bottom: 4px;">Revert to Auto-Matched Dish Photo</div>
                    <div style="font-size: 12px; color: var(--muted); line-height: 1.4;">This will remove any custom uploaded photo and restore the high-definition culinary food photo automatically tailored for this recipe.</div>
                </div>
            </div>
        </div>

        <div class="ft-modal-footer">
            <button type="button" class="btn-cancel" onclick="closeMealPhotoModal()">Cancel</button>
            <button type="button" id="btn-save-meal-photo" class="btn-save-swap" onclick="saveMealPhoto()">
                <span>Save Photo</span>
            </button>
        </div>
    </div>
</div>

<style>
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(5px); }
    to { opacity: 1; transform: translateY(0); }
}
.diet-tab-btn:hover {
    background: rgba(255,255,255,0.05) !important;
}

/* ==================================================== */
/* MEAL CARD ACTION BUTTONS                             */
/* ==================================================== */
.meal-card-actions {
    display: flex;
    gap: 8px;
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid color-mix(in srgb, var(--line) 70%, transparent);
    flex-wrap: wrap;
}
.meal-action-btn {
    flex: 1 1 auto;
    min-width: 78px;
    padding: 7px 10px;
    font-size: 11.5px;
    font-weight: 700;
    border-radius: 8px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    transition: all 0.2s ease;
    white-space: nowrap;
    text-decoration: none;
}
.meal-action-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.btn-log-meal {
    background: color-mix(in srgb, var(--lime) 15%, transparent);
    color: var(--lime);
    border: 1px solid color-mix(in srgb, var(--lime) 35%, transparent);
}
.btn-log-meal:hover:not(:disabled):not(.logged-completed) {
    background: var(--lime);
    color: var(--bg);
    box-shadow: 0 4px 14px color-mix(in srgb, var(--lime) 30%, transparent);
}
.btn-log-meal.logged-completed {
    background: color-mix(in srgb, #22c55e 14%, transparent) !important;
    color: #22c55e !important;
    border: 1px solid color-mix(in srgb, #22c55e 35%, transparent) !important;
    cursor: pointer !important;
    opacity: 0.95;
}
.btn-log-meal.logged-completed:hover {
    background: color-mix(in srgb, #22c55e 22%, transparent) !important;
}
.btn-log-meal.btn-view-only {
    background: color-mix(in srgb, var(--surface) 50%, var(--bg)) !important;
    color: var(--muted) !important;
    border: 1px solid var(--line) !important;
    cursor: not-allowed !important;
    opacity: 0.65;
}

/* Dynamic Meal Card States & Status Pills */
.meal-logged-check {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 19px;
    height: 19px;
    border-radius: 50%;
    background: #22c55e;
    color: #ffffff;
    font-size: 11px;
    font-weight: 800;
    line-height: 1;
    box-shadow: 0 0 8px rgba(34, 197, 94, 0.4);
}

.meal-status-pill-wrap {
    display: flex;
    align-items: center;
}

.meal-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11.5px;
    font-weight: 600;
    padding: 3px 10px;
    border-radius: 20px;
    line-height: 1.4;
    transition: all 0.25s ease;
}

.meal-status-pill.pill-logged {
    background: color-mix(in srgb, #22c55e 14%, transparent);
    color: #22c55e;
    border: 1px solid color-mix(in srgb, #22c55e 30%, transparent);
}

.meal-status-pill.pill-unlogged {
    background: color-mix(in srgb, var(--ink) 5%, transparent);
    color: var(--muted);
    border: 1px solid color-mix(in srgb, var(--line) 70%, transparent);
}

.meal-status-pill.pill-scheduled {
    background: color-mix(in srgb, var(--ink) 4%, transparent);
    color: var(--muted);
    border: 1px dashed color-mix(in srgb, var(--line) 80%, transparent);
    opacity: 0.85;
}

.meal-status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--muted);
    opacity: 0.7;
}
.btn-inspect-meal {
    background: color-mix(in srgb, var(--surface) 60%, var(--bg));
    color: var(--ink);
    border: 1px solid var(--line);
}
.btn-inspect-meal:hover:not(:disabled) {
    border-color: var(--lime);
    color: var(--lime);
}
.btn-swap-meal {
    background: color-mix(in srgb, var(--surface) 60%, var(--bg));
    color: var(--ink);
    border: 1px solid var(--line);
}
.btn-swap-meal:hover:not(:disabled) {
    border-color: #38bdf8;
    color: #38bdf8;
}

/* ==================================================== */
/* MODAL SYSTEM STYLES                                  */
/* ==================================================== */
.ft-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 99999;
    background: rgba(0, 0, 0, 0.75);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    animation: ftModalFadeIn 0.2s ease;
}
@keyframes ftModalFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
.ft-modal-box {
    background: var(--panel-bg, #121721);
    color: var(--ink);
    border: 1px solid var(--line);
    border-radius: 14px;
    width: 100%;
    max-width: 660px;
    max-height: 88vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 24px 60px rgba(0,0,0,0.6);
    overflow: hidden;
    animation: ftModalSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes ftModalSlideUp {
    from { opacity: 0; transform: translateY(14px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.ft-modal-header {
    padding: 18px 22px;
    border-bottom: 1px solid var(--line);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}
.ft-modal-title {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--ink);
}
.ft-modal-subtitle {
    margin: 3px 0 0 0;
    font-size: 12px;
    color: var(--muted);
}
.ft-modal-close {
    background: transparent;
    border: none;
    color: var(--muted);
    cursor: pointer;
    padding: 6px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}
.ft-modal-close:hover {
    background: rgba(255,255,255,0.08);
    color: var(--ink);
}
.ft-modal-body {
    padding: 20px 22px;
    overflow-y: auto;
    flex: 1 1 auto;
}
.ft-modal-footer {
    padding: 14px 22px;
    border-top: 1px solid var(--line);
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
    background: color-mix(in srgb, var(--bg) 40%, var(--panel-bg, #121721));
}
.btn-cancel {
    background: transparent;
    color: var(--muted);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 8px 16px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s ease;
}
.btn-cancel:hover {
    color: var(--ink);
    border-color: var(--muted);
}
.btn-primary-action {
    background: var(--lime);
    color: var(--bg);
    border: none;
    border-radius: 8px;
    padding: 8px 18px;
    font-weight: 800;
    font-size: 13px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}
.btn-primary-action:hover {
    opacity: 0.92;
    box-shadow: 0 4px 14px color-mix(in srgb, var(--lime) 35%, transparent);
}
.ft-spinner {
    width: 26px;
    height: 26px;
    border: 3px solid var(--line);
    border-top-color: var(--lime);
    border-radius: 50%;
    margin: 0 auto;
    animation: ftSpin 0.7s linear infinite;
}
@keyframes ftSpin {
    to { transform: rotate(360deg); }
}

/* ==================================================== */
/* INSPECTION MODAL SPECIFICS                           */
/* ==================================================== */
.inspect-meal-summary {
    background: color-mix(in srgb, var(--surface) 70%, var(--bg));
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}
.inspect-meal-name {
    font-size: 14.5px;
    font-weight: 700;
    color: var(--ink);
    max-width: 340px;
}
.inspect-macro-chips {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.inspect-chip {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 12px;
    border: 1px solid transparent;
}
.chip-cals {
    background: color-mix(in srgb, var(--lime) 18%, transparent);
    color: var(--lime);
    border-color: color-mix(in srgb, var(--lime) 30%, transparent);
}
.chip-pro {
    background: color-mix(in srgb, #38bdf8 18%, transparent);
    color: #38bdf8;
    border-color: color-mix(in srgb, #38bdf8 30%, transparent);
}
.chip-carbs {
    background: color-mix(in srgb, #fbbf24 18%, transparent);
    color: #fbbf24;
    border-color: color-mix(in srgb, #fbbf24 30%, transparent);
}
.chip-fat {
    background: color-mix(in srgb, #f43f5e 18%, transparent);
    color: #f43f5e;
    border-color: color-mix(in srgb, #f43f5e 30%, transparent);
}

.inspect-items-wrap {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.inspect-item-row {
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 10px 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.inspect-item-left {
    display: flex;
    flex-direction: column;
}
.inspect-item-title {
    font-size: 13px;
    font-weight: 700;
    color: var(--ink);
}
.inspect-item-portion {
    font-size: 11px;
    color: var(--muted);
}
.inspect-item-macros {
    display: flex;
    gap: 8px;
    font-size: 11px;
}
.inspect-micro-banner {
    background: color-mix(in srgb, var(--surface) 40%, var(--bg));
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 10px 14px;
    display: flex;
    justify-content: space-around;
    flex-wrap: wrap;
    gap: 8px;
}
.inspect-micro-stat {
    text-align: center;
}
.inspect-micro-stat-label {
    font-size: 10px;
    text-transform: uppercase;
    color: var(--muted);
    font-weight: 700;
    display: block;
}
.inspect-micro-stat-val {
    font-size: 12.5px;
    font-weight: 800;
    color: var(--ink);
}

/* ==================================================== */
/* SWAP MODAL SPECIFICS                                 */
/* ==================================================== */
.swap-current-ref {
    background: color-mix(in srgb, var(--surface) 60%, var(--bg));
    border: 1px solid var(--line);
    border-left: 4px solid var(--lime);
    border-radius: 8px;
    padding: 12px 14px;
    margin-bottom: 14px;
}
.swap-current-badge {
    font-size: 9.5px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--lime);
    display: block;
    margin-bottom: 4px;
}
.swap-current-food {
    font-size: 13.5px;
    font-weight: 700;
    color: var(--ink);
    margin-bottom: 4px;
}
.swap-current-macros {
    font-size: 11.5px;
    color: var(--muted);
}

.swap-tabs-bar {
    display: flex;
    gap: 6px;
    margin-bottom: 14px;
    border-bottom: 1px solid var(--line);
    padding-bottom: 10px;
}
.swap-tab-btn {
    background: transparent;
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--muted);
    padding: 6px 14px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}
.swap-tab-btn.active {
    background: var(--surface);
    color: var(--lime);
    border-color: var(--lime);
}

.swap-filters-row {
    display: flex;
    gap: 6px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}
.swap-filter-pill {
    background: transparent;
    border: 1px solid var(--line);
    border-radius: 20px;
    color: var(--muted);
    padding: 4px 12px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}
.swap-filter-pill.active {
    background: color-mix(in srgb, var(--lime) 15%, transparent);
    color: var(--lime);
    border-color: var(--lime);
}

.swap-recipes-grid {
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-height: 380px;
    overflow-y: auto;
    padding-right: 4px;
}
.swap-recipe-card {
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 12px 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    transition: all 0.2s ease;
}
.swap-recipe-card:hover {
    border-color: color-mix(in srgb, var(--lime) 50%, transparent);
    transform: translateY(-1px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.2);
}
.swap-recipe-info {
    flex: 1 1 auto;
}
.swap-recipe-title {
    font-size: 13.5px;
    font-weight: 700;
    color: var(--ink);
    margin-bottom: 4px;
}
.swap-recipe-tags {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
    margin-bottom: 6px;
}
.swap-tag {
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 4px;
    background: color-mix(in srgb, var(--surface) 60%, var(--bg));
    color: var(--muted);
    border: 1px solid var(--line);
}
.swap-tag.tag-diet {
    background: color-mix(in srgb, var(--lime) 15%, transparent);
    color: var(--lime);
    border-color: color-mix(in srgb, var(--lime) 30%, transparent);
    font-weight: 700;
}
.swap-recipe-macros {
    display: flex;
    gap: 10px;
    font-size: 11px;
    color: var(--muted);
}
.swap-recipe-macros strong {
    color: var(--ink);
}
.btn-select-swap {
    background: color-mix(in srgb, var(--lime) 15%, transparent);
    color: var(--lime);
    border: 1px solid color-mix(in srgb, var(--lime) 35%, transparent);
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 11.5px;
    font-weight: 800;
    cursor: pointer;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.2s ease;
    flex-shrink: 0;
}
.btn-select-swap:hover {
    background: var(--lime);
    color: var(--bg);
    box-shadow: 0 4px 12px color-mix(in srgb, var(--lime) 30%, transparent);
}

.swap-custom-input-row {
    display: flex;
    gap: 8px;
}
.swap-custom-field {
    flex: 1 1 auto;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 10px 14px;
    color: var(--ink);
    font-size: 13px;
    outline: none;
    transition: border-color 0.2s ease;
}
.swap-custom-field:focus {
    border-color: var(--lime);
}
.btn-calc-custom {
    background: var(--surface);
    color: var(--lime);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 10px 16px;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    transition: all 0.2s ease;
}
.btn-calc-custom:hover {
    border-color: var(--lime);
    background: color-mix(in srgb, var(--lime) 10%, transparent);
}
.swap-custom-card {
    background: color-mix(in srgb, var(--surface) 60%, var(--bg));
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 14px;
}

/* ==================================================== */
/* MOBILE RESPONSIVENESS (<= 640px)                     */
/* ==================================================== */
@media (max-width: 640px) {
    .meal-card-actions {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 6px;
    }
    .meal-action-btn {
        padding: 7px 4px;
        font-size: 10.5px;
        min-width: 0;
    }
    .ft-modal-box {
        max-height: 94vh;
        border-radius: 12px;
    }
    .ft-modal-header, .ft-modal-body, .ft-modal-footer {
        padding-left: 16px;
        padding-right: 16px;
    }
    .swap-recipe-card {
        flex-direction: column;
        align-items: flex-start;
    }
    .btn-select-swap {
        width: 100%;
        justify-content: center;
        margin-top: 6px;
    }
}
</style>

<script>
const FT_CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;

// State management for modals
let currentInspectingMeal = null;
let currentSwappingMeal = null;
let swapOptionsData = [];
let activeSwapFilter = 'all';
let calculatedCustomMealData = null;

// Photo Modal State
let currentPhotoMeal = null; // { mealId, foodItems, customUrl, resolvedUrl }
let currentPhotoTab = 'upload'; // 'upload', 'url', 'reset'
let selectedPhotoFile = null;

function openMealPhotoModal(btn) {
    const mealId = btn.getAttribute('data-meal-id');
    const foodItems = btn.getAttribute('data-food') || '';
    const customUrl = btn.getAttribute('data-custom-url') || '';
    const resolvedUrl = btn.getAttribute('data-resolved-url') || '';

    currentPhotoMeal = { mealId, foodItems, customUrl, resolvedUrl };
    selectedPhotoFile = null;

    document.getElementById('photo-modal-food-name').textContent = foodItems;
    document.getElementById('photo-modal-preview-img').src = customUrl || resolvedUrl;
    document.getElementById('photo-modal-tag').textContent = customUrl ? (customUrl.includes('imagekit.io') ? 'ImageKit Photo' : 'Custom Photo') : 'Auto-Matched Photo';
    document.getElementById('photo-modal-url-input').value = customUrl || '';
    document.getElementById('photo-modal-file-input').value = '';
    
    const fileStatus = document.getElementById('photo-file-status');
    fileStatus.style.display = 'none';
    fileStatus.textContent = '';

    if (customUrl && !customUrl.includes('imagekit.io')) {
        switchPhotoTab('url');
    } else {
        switchPhotoTab('upload');
    }

    const modal = document.getElementById('modal-meal-photo');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeMealPhotoModal() {
    const modal = document.getElementById('modal-meal-photo');
    modal.style.display = 'none';
    document.body.style.overflow = '';
    currentPhotoMeal = null;
    selectedPhotoFile = null;
}

function switchPhotoTab(tab) {
    currentPhotoTab = tab;
    ['upload', 'url', 'reset'].forEach(t => {
        const btn = document.getElementById('tab-btn-photo-' + t);
        const panel = document.getElementById('panel-photo-' + t);
        if (btn) btn.classList.toggle('active', t === tab);
        if (panel) panel.style.display = (t === tab) ? 'block' : 'none';
    });

    const previewImg = document.getElementById('photo-modal-preview-img');
    const tag = document.getElementById('photo-modal-tag');

    if (tab === 'reset') {
        previewImg.src = currentPhotoMeal ? currentPhotoMeal.resolvedUrl : '';
        tag.textContent = 'Auto-Matched Photo';
    } else if (tab === 'upload') {
        if (selectedPhotoFile) {
            // keep previewing selected file
        } else if (currentPhotoMeal && currentPhotoMeal.customUrl) {
            previewImg.src = currentPhotoMeal.customUrl;
            tag.textContent = currentPhotoMeal.customUrl.includes('imagekit.io') ? 'ImageKit Photo' : 'Custom Photo';
        } else if (currentPhotoMeal) {
            previewImg.src = currentPhotoMeal.resolvedUrl;
            tag.textContent = 'Auto-Matched Photo';
        }
    } else if (tab === 'url') {
        const inputVal = document.getElementById('photo-modal-url-input').value.trim();
        if (inputVal) {
            previewImg.src = inputVal;
            tag.textContent = 'Link Preview';
        } else if (currentPhotoMeal) {
            previewImg.src = currentPhotoMeal.customUrl || currentPhotoMeal.resolvedUrl;
            tag.textContent = currentPhotoMeal.customUrl ? 'Custom Photo' : 'Auto-Matched Photo';
        }
    }
}

function handlePhotoFileSelected(input) {
    const file = input.files && input.files[0];
    if (!file) return;

    if (file.size > 5 * 1024 * 1024) {
        Swal.fire({
            icon: 'warning',
            title: 'File Too Large',
            text: 'Please select an image smaller than 5MB.',
            confirmButtonColor: 'var(--lime)',
            background: 'var(--panel-bg, #121721)',
            color: 'var(--ink)'
        });
        input.value = '';
        return;
    }

    selectedPhotoFile = file;
    const fileStatus = document.getElementById('photo-file-status');
    fileStatus.style.display = 'block';
    fileStatus.textContent = `✓ Selected: ${file.name} (${(file.size / 1024).toFixed(0)} KB) • Ready for ImageKit`;

    // Local instant preview
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('photo-modal-preview-img').src = e.target.result;
        document.getElementById('photo-modal-tag').textContent = 'Local Preview (ImageKit)';
    };
    reader.readAsDataURL(file);
}

function handlePhotoUrlInput(val) {
    val = val.trim();
    const previewImg = document.getElementById('photo-modal-preview-img');
    const tag = document.getElementById('photo-modal-tag');
    if (val.startsWith('http://') || val.startsWith('https://')) {
        previewImg.src = val;
        tag.textContent = 'URL Preview';
    }
}

async function saveMealPhoto() {
    if (!currentPhotoMeal) return;

    const saveBtn = document.getElementById('btn-save-meal-photo');
    const originalText = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<div class="ft-spinner" style="width:14px; height:14px; border-width:2px; display:inline-block; vertical-align:middle; margin-right:6px;"></div> <span>Uploading to ImageKit...</span>';

    const formData = new FormData();
    formData.append('action', 'update_meal_photo');
    formData.append('meal_id', currentPhotoMeal.mealId);
    formData.append('photo_type', currentPhotoTab);
    formData.append('csrf_token', FT_CSRF_TOKEN);

    if (currentPhotoTab === 'upload') {
        if (!selectedPhotoFile) {
            Swal.fire({
                icon: 'info',
                title: 'No Image Chosen',
                text: 'Please click the box to select an image file to upload to ImageKit.',
                confirmButtonColor: 'var(--lime)',
                background: 'var(--panel-bg, #121721)',
                color: 'var(--ink)'
            });
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalText;
            return;
        }
        formData.append('photo_file', selectedPhotoFile);
    } else if (currentPhotoTab === 'url') {
        const urlVal = document.getElementById('photo-modal-url-input').value.trim();
        if (!urlVal) {
            Swal.fire({
                icon: 'warning',
                title: 'URL Required',
                text: 'Please enter a valid image web address.',
                confirmButtonColor: 'var(--lime)',
                background: 'var(--panel-bg, #121721)',
                color: 'var(--ink)'
            });
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalText;
            return;
        }
        formData.append('photo_url', urlVal);
    }

    try {
        const res = await fetch('index.php?page=diet', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            // Update image on meal card
            const cardImg = document.getElementById('meal-img-' + currentPhotoMeal.mealId);
            if (cardImg) {
                cardImg.src = data.resolved_url;
            }

            // Update button data attributes
            const btn = document.getElementById('btn-edit-photo-' + currentPhotoMeal.mealId);
            if (btn) {
                btn.setAttribute('data-custom-url', data.image_url || '');
                btn.setAttribute('data-resolved-url', data.resolved_url);
            }

            closeMealPhotoModal();

            const toastMsg = currentPhotoTab === 'upload' 
                ? 'Saved to ImageKit CDN successfully!' 
                : (currentPhotoTab === 'reset' ? 'Reverted to auto dish photo!' : 'Custom image URL saved!');

            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: toastMsg,
                showConfirmButton: false,
                timer: 3000,
                background: 'var(--panel-bg, #121721)',
                color: 'var(--ink)'
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Photo Update Failed',
                text: data.error || 'Could not save photo.',
                confirmButtonColor: 'var(--lime)',
                background: 'var(--panel-bg, #121721)',
                color: 'var(--ink)'
            });
        }
    } catch (err) {
        console.error("Photo upload error:", err);
        Swal.fire({
            icon: 'error',
            title: 'Network Error',
            text: 'Could not connect to server to upload photo.',
            confirmButtonColor: 'var(--lime)',
            background: 'var(--panel-bg, #121721)',
            color: 'var(--ink)'
        });
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
    }
}

// Switch weekly meal plan day tab
function switchDietTab(dayNum) {
    document.querySelectorAll('.diet-tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.diet-day-tab').forEach(btn => btn.classList.remove('active'));
    
    const targetContent = document.getElementById('diet-tab-' + dayNum);
    if (targetContent) targetContent.style.display = 'block';
    
    const activeBtn = document.getElementById('diet-day-btn-' + dayNum);
    if (activeBtn) {
        activeBtn.classList.add('active');
    }
}

// Top View Switcher (Today's Macro Tracker vs Weekly Meal Plan)
function switchDietView(view) {
    const btnTracker  = document.getElementById('btn-view-tracker');
    const btnPlan     = document.getElementById('btn-view-plan');
    const viewTracker = document.getElementById('view-macro-tracker');
    const viewPlan    = document.getElementById('view-meal-plan');

    if (view === 'plan') {
        btnPlan?.classList.add('active');
        btnTracker?.classList.remove('active');
        if (viewPlan) viewPlan.style.display = 'block';
        if (viewTracker) viewTracker.style.display = 'none';
        try { localStorage.setItem('fittracks_diet_active_tab', 'plan'); } catch (e) {}
    } else {
        btnTracker?.classList.add('active');
        btnPlan?.classList.remove('active');
        if (viewTracker) viewTracker.style.display = 'block';
        if (viewPlan) viewPlan.style.display = 'none';
        try { localStorage.setItem('fittracks_diet_active_tab', 'tracker'); } catch (e) {}
    }
}

// ----------------------------------------------------
// FEATURE 1: ONE-CLICK QUICK LOG PLANNED MEAL (WITH GUARD RAILS)
// ----------------------------------------------------
function normalizeMealType(rawType) {
    let t = (rawType || 'Meal').trim();
    if (t.toLowerCase().includes('snack')) return 'Snack';
    return t.charAt(0).toUpperCase() + t.slice(1).toLowerCase();
}

function handleAlreadyLoggedClick(mealType, timeStr) {
    const timeTxt = timeStr ? ` at <strong>${timeStr}</strong>` : ' earlier today';
    Swal.fire({
        icon: 'info',
        title: `${mealType} already logged today`,
        html: `<p style="margin:0 0 10px 0; font-size:14px; color:var(--ink);">${mealType} was recorded${timeTxt}.</p><p style="margin:0; font-size:13px; color:var(--muted);">You can log ${mealType.toLowerCase()} again tomorrow.</p>`,
        background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
        color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
        confirmButtonColor: 'var(--lime, #c7ff22)',
        confirmButtonText: 'Understood'
    });
}

function markMealAsLoggedUI(mealId, normType, timeStr) {
    const timeDisplay = timeStr || 'Today';
    
    // Update all cards matching this meal type for today
    const cards = document.querySelectorAll(`.meal-card[data-day="${window.FT_CURRENT_DAY_NUM}"]`);
    cards.forEach(c => {
        const cType = normalizeMealType(c.dataset.type);
        if (cType === normType) {
            const mId = c.dataset.mealId;
            const cBtn = c.querySelector('.btn-log-meal');
            if (cBtn) {
                cBtn.disabled = false; // keep accessible so clicking displays the gentle "already logged" message
                cBtn.className = 'meal-action-btn btn-log-meal logged-completed';
                cBtn.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg> <span>Logged ✓</span>`;
                cBtn.title = `${normType} already logged today at ${timeDisplay}. Click for details.`;
                cBtn.onclick = function() { handleAlreadyLoggedClick(normType, timeDisplay); };
            }
            const checkEl = document.getElementById('meal-check-' + mId);
            if (checkEl) checkEl.style.display = 'inline-flex';

            const statusEl = document.getElementById('meal-status-pill-' + mId);
            if (statusEl) {
                statusEl.className = 'meal-status-pill pill-logged';
                statusEl.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg> <span class="status-txt">Logged today at ${timeDisplay}</span>`;
            }
        }
    });

    // Also update inspection modal button if open
    const inspectBtn = document.getElementById('btn-inspect-quick-log');
    if (inspectBtn && currentInspectingMeal && normalizeMealType(currentInspectingMeal.type) === normType) {
        inspectBtn.disabled = false;
        inspectBtn.style.opacity = '0.9';
        inspectBtn.style.cursor = 'pointer';
        inspectBtn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg> <span>${normType} Already Logged Today</span>`;
        inspectBtn.onclick = function() {
            closeInspectModal();
            handleAlreadyLoggedClick(normType, timeDisplay);
        };
    }
}

function resetTodayMealCardsUI() {
    window.FT_LOGGED_MEALS_TODAY = {};
    const cards = document.querySelectorAll(`.meal-card[data-day="${window.FT_CURRENT_DAY_NUM}"]`);
    cards.forEach(c => {
        const mId = c.dataset.mealId;
        const normType = normalizeMealType(c.dataset.type);
        const cBtn = c.querySelector('.btn-log-meal');
        if (cBtn) {
            cBtn.disabled = false;
            cBtn.className = 'meal-action-btn btn-log-meal';
            cBtn.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg> <span>+ Log Meal</span>`;
            cBtn.title = `Log your ${normType.toLowerCase()} to track your daily nutrition`;
            cBtn.onclick = function() { quickLogPlannedMeal(parseInt(mId, 10)); };
        }
        const checkEl = document.getElementById('meal-check-' + mId);
        if (checkEl) checkEl.style.display = 'none';

        const statusEl = document.getElementById('meal-status-pill-' + mId);
        if (statusEl) {
            statusEl.className = 'meal-status-pill pill-unlogged';
            statusEl.innerHTML = `<span class="meal-status-dot"></span> <span class="status-txt">No meal logged yet</span>`;
        }
    });
}

async function quickLogPlannedMeal(mealId) {
    const card = document.getElementById('meal-card-' + mealId);
    if (!card) return;

    if (card._isLogging) return; // Prevent double clicks

    const rawType = card.dataset.type || 'Meal';
    const normType = normalizeMealType(rawType);
    const dayNum = parseInt(card.dataset.day || 0, 10);
    const cals = parseFloat(card.dataset.cals || 0);
    const pro = parseFloat(card.dataset.pro || 0);
    const carbs = parseFloat(card.dataset.carbs || 0);
    const fat = parseFloat(card.dataset.fat || 0);

    // Guard 1: Only today can be logged
    if (dayNum !== window.FT_CURRENT_DAY_NUM) {
        Swal.fire({
            icon: 'info',
            title: 'Only Today\'s Meals Can Be Logged',
            text: 'This meal is scheduled for another day. Daily meal logging is restricted to today only.',
            background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
            color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
            confirmButtonColor: 'var(--lime, #c7ff22)',
            confirmButtonText: 'Understood'
        });
        return;
    }

    // Guard 2: Already logged today
    if (window.FT_LOGGED_MEALS_TODAY && window.FT_LOGGED_MEALS_TODAY[normType]) {
        handleAlreadyLoggedClick(normType, window.FT_LOGGED_MEALS_TODAY[normType].time);
        return;
    }

    const btn = card.querySelector('.btn-log-meal');
    const originalHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<span class="ft-spinner" style="width:12px; height:12px; border-width:2px; display:inline-block;"></span> <span>Saving...</span>`;
    }
    card._isLogging = true;

    try {
        const body = new URLSearchParams({
            mode: 'add',
            meal_id: mealId.toString(),
            day_of_week: dayNum.toString(),
            meal_type: normType,
            calories: cals.toString(),
            protein_g: pro.toString(),
            carbs_g: carbs.toString(),
            fat_g: fat.toString(),
            csrf_token: FT_CSRF_TOKEN
        });

        const res = await fetch('index.php?page=log_macros', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: body.toString()
        });

        const data = await res.json();
        card._isLogging = false;

        if (data && data.already_logged) {
            // Already logged (duplicate prevented by backend/DB)
            const logTime = data.logged_time || 'earlier today';
            if (!window.FT_LOGGED_MEALS_TODAY) window.FT_LOGGED_MEALS_TODAY = {};
            window.FT_LOGGED_MEALS_TODAY[normType] = { time: logTime, meal_id: mealId };
            markMealAsLoggedUI(mealId, normType, logTime);
            handleAlreadyLoggedClick(normType, logTime);
            return;
        }

        if (data && data.can_log_today_only) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
            Swal.fire({
                icon: 'info',
                title: 'Today Only',
                text: data.error || 'Only meals for today can be logged.',
                background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
                confirmButtonColor: 'var(--lime, #c7ff22)',
            });
            return;
        }

        if (data && data.success) {
            const loggedTime = data.logged_time || new Date().toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'});
            if (!window.FT_LOGGED_MEALS_TODAY) window.FT_LOGGED_MEALS_TODAY = {};
            window.FT_LOGGED_MEALS_TODAY[normType] = { time: loggedTime, meal_id: mealId };

            // Apply live UI updates to the meal cards immediately
            markMealAsLoggedUI(mealId, normType, loggedTime);

            // Apply live update to Today's Macro Tracker DOM
            if (typeof window.applyMacroLogResultToUI === 'function') {
                window.applyMacroLogResultToUI(data, false);
            }

            // Toast feedback with subtle notification
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: `${normType} Logged!`,
                html: `${normType} logged today at ${loggedTime}.<br><strong>+${cals} kcal</strong> (${pro}g P • ${carbs}g C • ${fat}g F) added.`,
                showConfirmButton: true,
                confirmButtonText: 'View Tracker',
                confirmButtonColor: 'var(--lime, #c7ff22)',
                showCancelButton: false,
                timer: 4000,
                timerProgressBar: true,
                background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
            }).then((result) => {
                if (result.isConfirmed) {
                    switchDietView('tracker');
                }
            });
        } else {
            throw new Error(data.error || 'Failed to log meal');
        }
    } catch (err) {
        console.error(err);
        card._isLogging = false;
        if (btn) {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        }
        Swal.fire({
            icon: 'error',
            title: 'Unable to Log Meal',
            text: err.message || 'Could not record meal to daily macros.',
            background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
            color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
            confirmButtonColor: 'var(--lime, #c7ff22)',
        });
    }
}

// ----------------------------------------------------
// FEATURE 1: INGREDIENT & MICRONUTRIENT INSPECTION
// ----------------------------------------------------
async function inspectPlannedMeal(mealId) {
    const card = document.getElementById('meal-card-' + mealId);
    if (!card) return;

    currentInspectingMeal = {
        mealId: mealId,
        type: card.dataset.type || 'Meal',
        food: card.dataset.food || '',
        cals: parseFloat(card.dataset.cals || 0),
        pro: parseFloat(card.dataset.pro || 0),
        carbs: parseFloat(card.dataset.carbs || 0),
        fat: parseFloat(card.dataset.fat || 0)
    };

    const modal = document.getElementById('modal-inspect-meal');
    const title = document.getElementById('inspect-modal-title');
    const subtitle = document.getElementById('inspect-modal-subtitle');
    const nameEl = document.getElementById('inspect-meal-name');
    const chipCals = document.getElementById('inspect-chip-cals');
    const chipPro = document.getElementById('inspect-chip-pro');
    const chipCarbs = document.getElementById('inspect-chip-carbs');
    const chipFat = document.getElementById('inspect-chip-fat');
    const loadingEl = document.getElementById('inspect-loading');
    const contentEl = document.getElementById('inspect-content');
    const fallbackEl = document.getElementById('inspect-fallback');
    const itemsCont = document.getElementById('inspect-items-container');
    const microBanner = document.getElementById('inspect-micro-banner');

    title.textContent = `${currentInspectingMeal.type} Breakdown`;
    subtitle.textContent = `Ingredient inspection & micronutrient profile`;
    nameEl.textContent = currentInspectingMeal.food;
    chipCals.textContent = `${currentInspectingMeal.cals} kcal`;
    chipPro.textContent = `${currentInspectingMeal.pro}g P`;
    chipCarbs.textContent = `${currentInspectingMeal.carbs}g C`;
    chipFat.textContent = `${currentInspectingMeal.fat}g F`;

    // Configure inspect quick log button state
    const inspectLogBtn = document.getElementById('btn-inspect-quick-log');
    const dayNum = parseInt(card.dataset.day || 0, 10);
    const normType = normalizeMealType(currentInspectingMeal.type);

    if (inspectLogBtn) {
        if (dayNum !== window.FT_CURRENT_DAY_NUM) {
            inspectLogBtn.disabled = true;
            inspectLogBtn.style.opacity = '0.6';
            inspectLogBtn.style.cursor = 'not-allowed';
            inspectLogBtn.onclick = null;
            inspectLogBtn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg> <span>View Only (Scheduled for Another Day)</span>`;
        } else if (window.FT_LOGGED_MEALS_TODAY && window.FT_LOGGED_MEALS_TODAY[normType]) {
            const logTime = window.FT_LOGGED_MEALS_TODAY[normType].time;
            inspectLogBtn.disabled = false;
            inspectLogBtn.style.opacity = '0.9';
            inspectLogBtn.style.cursor = 'pointer';
            inspectLogBtn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg> <span>${normType} Already Logged Today</span>`;
            inspectLogBtn.onclick = function() {
                closeInspectModal();
                handleAlreadyLoggedClick(normType, logTime);
            };
        } else {
            inspectLogBtn.disabled = false;
            inspectLogBtn.style.opacity = '1';
            inspectLogBtn.style.cursor = 'pointer';
            inspectLogBtn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg> <span>+ Log This Meal to Today</span>`;
            inspectLogBtn.onclick = function() { quickLogFromInspection(); };
        }
    }

    itemsCont.innerHTML = '';
    microBanner.style.display = 'none';
    fallbackEl.style.display = 'none';
    contentEl.style.display = 'none';
    loadingEl.style.display = 'block';
    modal.style.display = 'flex';

    // Prepare clean search query (e.g., "350g of Chicken Adobo" -> "350g Chicken Adobo")
    const cleanQuery = currentInspectingMeal.food.replace(/\b(\d+g)\s+of\s+/i, '$1 ');

    try {
        const res = await fetch('index.php?page=food_lookup&action=calorieninjas&query=' + encodeURIComponent(cleanQuery));
        const data = await res.json();
        loadingEl.style.display = 'none';

        if (data && data.success && Array.isArray(data.items) && data.items.length > 0) {
            contentEl.style.display = 'block';

            let totFiber = 0, totSugar = 0, totSodium = 0, totPotassium = 0;

            itemsCont.innerHTML = data.items.map(item => {
                totFiber += (item.fiber_g || 0);
                totSugar += (item.sugar_g || 0);
                totSodium += (item.sodium_mg || 0);
                totPotassium += (item.potassium_mg || 0);

                return `
                    <div class="inspect-item-row">
                        <div class="inspect-item-left">
                            <span class="inspect-item-title">${escapeHtml(item.name)}</span>
                            <span class="inspect-item-portion">${item.serving_size_g ? Math.round(item.serving_size_g) + 'g serving' : '1 serving'}</span>
                        </div>
                        <div class="inspect-item-macros">
                            <span class="inspect-chip chip-cals">${item.calories} kcal</span>
                            <span class="inspect-chip chip-pro">${item.protein_g}g P</span>
                            <span class="inspect-chip chip-carbs">${item.carbs_g}g C</span>
                            <span class="inspect-chip chip-fat">${item.fat_g}g F</span>
                        </div>
                    </div>
                `;
            }).join('');

            // Micronutrient banner
            microBanner.style.display = 'flex';
            microBanner.innerHTML = `
                <div class="inspect-micro-stat">
                    <span class="inspect-micro-stat-label">Dietary Fiber</span>
                    <span class="inspect-micro-stat-val">${totFiber.toFixed(1)}g</span>
                </div>
                <div class="inspect-micro-stat">
                    <span class="inspect-micro-stat-label">Sugars</span>
                    <span class="inspect-micro-stat-val">${totSugar.toFixed(1)}g</span>
                </div>
                <div class="inspect-micro-stat">
                    <span class="inspect-micro-stat-label">Sodium</span>
                    <span class="inspect-micro-stat-val">${Math.round(totSodium)}mg</span>
                </div>
                <div class="inspect-micro-stat">
                    <span class="inspect-micro-stat-label">Potassium</span>
                    <span class="inspect-micro-stat-val">${Math.round(totPotassium)}mg</span>
                </div>
            `;
        } else {
            // Graceful fallback to planned targets
            fallbackEl.style.display = 'block';
            document.getElementById('inspect-fallback-title').textContent = 'Scheduled Meal Nutrition';
            document.getElementById('inspect-fallback-msg').textContent = `Target: ${currentInspectingMeal.cals} kcal | ${currentInspectingMeal.pro}g Protein | ${currentInspectingMeal.carbs}g Carbs | ${currentInspectingMeal.fat}g Fat`;
        }
    } catch (err) {
        console.error("Inspection error:", err);
        loadingEl.style.display = 'none';
        fallbackEl.style.display = 'block';
        document.getElementById('inspect-fallback-title').textContent = 'Scheduled Meal Nutrition';
        document.getElementById('inspect-fallback-msg').textContent = `Target: ${currentInspectingMeal.cals} kcal | ${currentInspectingMeal.pro}g Protein | ${currentInspectingMeal.carbs}g Carbs | ${currentInspectingMeal.fat}g Fat`;
    }
}

function closeInspectModal() {
    const modal = document.getElementById('modal-inspect-meal');
    if (modal) modal.style.display = 'none';
    currentInspectingMeal = null;
}

function quickLogFromInspection() {
    if (!currentInspectingMeal) return;
    const mealId = currentInspectingMeal.mealId;
    closeInspectModal();
    quickLogPlannedMeal(mealId);
}

// ----------------------------------------------------
// FEATURE 2: SWAP MEAL WITH ALTERNATIVE HEALTHY RECIPES
// ----------------------------------------------------
async function openSwapMealModal(mealId) {
    const card = document.getElementById('meal-card-' + mealId);
    if (!card) return;

    currentSwappingMeal = {
        mealId: mealId,
        dayNum: card.dataset.day || '1',
        type: card.dataset.type || 'Meal',
        food: card.dataset.food || '',
        cals: parseFloat(card.dataset.cals || 0),
        pro: parseFloat(card.dataset.pro || 0),
        carbs: parseFloat(card.dataset.carbs || 0),
        fat: parseFloat(card.dataset.fat || 0)
    };

    const modal = document.getElementById('modal-swap-meal');
    const title = document.getElementById('swap-modal-title');
    const subtitle = document.getElementById('swap-modal-subtitle');
    const currentFood = document.getElementById('swap-current-food');
    const currentMacros = document.getElementById('swap-current-macros');
    const loadingEl = document.getElementById('swap-loading');
    const gridEl = document.getElementById('swap-recipes-grid');

    title.textContent = `Swap ${currentSwappingMeal.type}`;
    subtitle.textContent = `Choose an alternative recipe balanced around ${currentSwappingMeal.cals} kcal`;
    currentFood.textContent = currentSwappingMeal.food;
    currentMacros.textContent = `${currentSwappingMeal.cals} kcal • ${currentSwappingMeal.pro}g Protein • ${currentSwappingMeal.carbs}g Carbs • ${currentSwappingMeal.fat}g Fat`;

    // Reset tabs
    switchSwapTab('curated');
    document.getElementById('swap-custom-input').value = '';
    document.getElementById('swap-custom-result').style.display = 'none';
    calculatedCustomMealData = null;

    gridEl.innerHTML = '';
    loadingEl.style.display = 'block';
    modal.style.display = 'flex';

    try {
        const url = `index.php?page=diet&action=get_swap_options&meal_type=${encodeURIComponent(currentSwappingMeal.type)}&calories=${currentSwappingMeal.cals}&meal_id=${mealId}`;
        const res = await fetch(url);
        const data = await res.json();
        loadingEl.style.display = 'none';

        if (data && data.success && Array.isArray(data.options)) {
            swapOptionsData = data.options;
            
            // Set user diet restriction on filter pill
            const dietPill = document.getElementById('pill-filter-diet');
            if (dietPill) {
                const restName = data.user_restriction && data.user_restriction !== 'none'
                    ? data.user_restriction.replace('-', ' ')
                    : 'Balanced';
                dietPill.textContent = `Matched: ${capitalize(restName)}`;
            }

            renderSwapRecipes();
        } else {
            gridEl.innerHTML = `<div style="padding:24px; text-align:center; color:var(--muted);">No alternative recipes found. You can enter a custom meal below.</div>`;
        }
    } catch (err) {
        console.error("Swap options fetch error:", err);
        loadingEl.style.display = 'none';
        gridEl.innerHTML = `<div style="padding:24px; text-align:center; color:var(--muted);">Could not load alternatives. Please check your connection or use Custom Meal Search.</div>`;
    }
}

function renderSwapRecipes() {
    const gridEl = document.getElementById('swap-recipes-grid');
    if (!gridEl) return;

    let filtered = swapOptionsData;
    if (activeSwapFilter === 'diet') {
        filtered = swapOptionsData.filter(o => o.is_diet_match);
    } else if (activeSwapFilter === 'high-protein') {
        filtered = swapOptionsData.filter(o => o.protein_g >= 30 || (o.tags && o.tags.some(t => t.toLowerCase().includes('protein'))));
    }

    if (filtered.length === 0) {
        gridEl.innerHTML = `<div style="padding:30px 10px; text-align:center; color:var(--muted); font-size:13px;">No recipes match the "${activeSwapFilter}" filter. Showing all options.</div>`;
        setTimeout(() => { filterSwapRecipes('all', document.querySelector('.swap-filter-pill')); }, 1200);
        return;
    }

    gridEl.innerHTML = filtered.map(opt => {
        const tagBadges = (opt.tags || []).map(t => {
            const isDietTag = opt.is_diet_match && t.toLowerCase().includes('diet');
            return `<span class="swap-tag ${isDietTag ? 'tag-diet' : ''}">${escapeHtml(t)}</span>`;
        }).join('');

        const dietMatchBadge = opt.is_diet_match ? `<span class="swap-tag tag-diet">✓ Diet Match</span>` : '';

        return `
            <div class="swap-recipe-card">
                <div class="swap-recipe-info">
                    <div class="swap-recipe-title">${escapeHtml(opt.title)}</div>
                    <div class="swap-recipe-tags">${dietMatchBadge}${tagBadges}</div>
                    <div class="swap-recipe-macros">
                        <span><strong>${opt.calories}</strong> kcal</span>
                        <span>•</span>
                        <span><strong>${opt.protein_g}g</strong> Protein</span>
                        <span>•</span>
                        <span><strong>${opt.carbs_g}g</strong> Carbs</span>
                        <span>•</span>
                        <span><strong>${opt.fat_g}g</strong> Fat</span>
                        <span style="color:var(--muted); font-size:10.5px;">(${opt.grams}g portion)</span>
                    </div>
                </div>
                <button type="button" class="btn-select-swap" onclick="confirmSwapRecipe('${escapeHtml(opt.food_items)}', ${opt.calories}, ${opt.protein_g}, ${opt.carbs_g}, ${opt.fat_g})">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 3h5v5"/><path d="M4 20L21 3"/><path d="M21 16v5h-5"/><path d="M15 15l6 6"/><path d="M4 4l5 5"/></svg>
                    <span>Swap to This</span>
                </button>
            </div>
        `;
    }).join('');
}

function filterSwapRecipes(filter, btnEl) {
    activeSwapFilter = filter;
    document.querySelectorAll('.swap-filter-pill').forEach(b => b.classList.remove('active'));
    if (btnEl) btnEl.classList.add('active');
    renderSwapRecipes();
}

function switchSwapTab(tab) {
    const tabCurated = document.getElementById('tab-swap-curated');
    const tabCustom  = document.getElementById('tab-swap-custom');
    const panelCurated = document.getElementById('swap-panel-curated');
    const panelCustom  = document.getElementById('swap-panel-custom');

    if (tab === 'custom') {
        tabCustom.classList.add('active');
        tabCurated.classList.remove('active');
        panelCustom.style.display = 'block';
        panelCurated.style.display = 'none';
    } else {
        tabCurated.classList.add('active');
        tabCustom.classList.remove('active');
        panelCurated.style.display = 'block';
        panelCustom.style.display = 'none';
    }
}

// Custom Search calculation via CalorieNinjas
async function calculateCustomSwap() {
    const input = document.getElementById('swap-custom-input');
    const query = input ? input.value.trim() : '';
    if (!query) {
        Swal.fire({
            icon: 'warning',
            title: 'Enter Meal Description',
            text: 'Please enter a food item or ingredients (e.g., 200g chicken breast, 1 cup brown rice).',
            background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
            color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
            confirmButtonColor: 'var(--lime, #c7ff22)',
        });
        return;
    }

    const btn = document.getElementById('btn-calc-custom-swap');
    const btnText = document.getElementById('btn-calc-custom-text');
    const loadingEl = document.getElementById('swap-custom-loading');
    const resultCard = document.getElementById('swap-custom-result');

    btn.disabled = true;
    btnText.textContent = 'Calculating...';
    loadingEl.style.display = 'block';
    resultCard.style.display = 'none';

    try {
        const res = await fetch('index.php?page=food_lookup&action=calorieninjas&query=' + encodeURIComponent(query));
        const data = await res.json();
        loadingEl.style.display = 'none';
        btn.disabled = false;
        btnText.textContent = 'Calculate';

        if (data && data.success) {
            calculatedCustomMealData = {
                food_items: query,
                calories: Math.round(data.total_calories || 0),
                protein_g: parseFloat(data.total_protein || 0),
                carbs_g: parseFloat(data.total_carbs || 0),
                fat_g: parseFloat(data.total_fat || 0)
            };

            document.getElementById('swap-custom-name').textContent = query;
            document.getElementById('swap-custom-cals').textContent = `${calculatedCustomMealData.calories} kcal`;
            document.getElementById('swap-custom-macros-row').innerHTML = `
                <span><strong>${calculatedCustomMealData.protein_g}g</strong> Protein</span>
                <span>•</span>
                <span><strong>${calculatedCustomMealData.carbs_g}g</strong> Carbs</span>
                <span>•</span>
                <span><strong>${calculatedCustomMealData.fat_g}g</strong> Fat</span>
            `;
            resultCard.style.display = 'block';
        } else {
            Swal.fire({
                icon: 'warning',
                title: 'No Nutrition Data',
                text: data.error || 'Could not find nutrition values for this query. Try adding portions like "150g" or "1 cup".',
                background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
                confirmButtonColor: 'var(--lime, #c7ff22)',
            });
        }
    } catch (err) {
        console.error("Custom swap calc error:", err);
        loadingEl.style.display = 'none';
        btn.disabled = false;
        btnText.textContent = 'Calculate';
        Swal.fire({
            icon: 'error',
            title: 'Connection Error',
            text: 'Could not connect to nutrition engine. Please check your network.',
            background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
            color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
            confirmButtonColor: 'var(--lime, #c7ff22)',
        });
    }
}

function applyCustomSwap() {
    if (!calculatedCustomMealData) return;
    confirmSwapRecipe(
        calculatedCustomMealData.food_items,
        calculatedCustomMealData.calories,
        calculatedCustomMealData.protein_g,
        calculatedCustomMealData.carbs_g,
        calculatedCustomMealData.fat_g
    );
}

// Send swap action to backend
async function confirmSwapRecipe(foodItems, cals, pro, carbs, fat) {
    if (!currentSwappingMeal) return;

    const mealId = currentSwappingMeal.mealId;
    const dayNum = currentSwappingMeal.dayNum;
    const mealType = currentSwappingMeal.type;

    try {
        const body = new URLSearchParams({
            action: 'swap_meal',
            meal_id: mealId.toString(),
            food_items: foodItems,
            calories: cals.toString(),
            protein_g: pro.toString(),
            carbs_g: carbs.toString(),
            fat_g: fat.toString(),
            csrf_token: FT_CSRF_TOKEN
        });

        const res = await fetch('index.php?page=diet', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: body.toString()
        });

        const data = await res.json();
        if (data && data.success) {
            closeSwapModal();

            // 1. Update meal card DOM in place
            const card = document.getElementById('meal-card-' + mealId);
            if (card) {
                card.dataset.food = foodItems;
                card.dataset.cals = cals;
                card.dataset.pro = pro;
                card.dataset.carbs = carbs;
                card.dataset.fat = fat;

                const calsBadge = document.getElementById('meal-cals-badge-' + mealId);
                const foodText  = document.getElementById('meal-food-' + mealId);
                const proEl     = document.getElementById('meal-pro-' + mealId);
                const carbsEl   = document.getElementById('meal-carbs-' + mealId);
                const fatEl     = document.getElementById('meal-fat-' + mealId);

                if (calsBadge) calsBadge.textContent = `${cals} kcal`;
                if (foodText)  foodText.textContent = foodItems;
                if (proEl)     proEl.textContent = pro;
                if (carbsEl)   carbsEl.textContent = carbs;
                if (fatEl)     fatEl.textContent = fat;

                // Update photo if auto-resolved for new food item
                if (data.resolved_url) {
                    const cardImg = document.getElementById('meal-img-' + mealId);
                    if (cardImg) cardImg.src = data.resolved_url;
                    const editPhotoBtn = document.getElementById('btn-edit-photo-' + mealId);
                    if (editPhotoBtn) {
                        editPhotoBtn.setAttribute('data-food', foodItems);
                        editPhotoBtn.setAttribute('data-resolved-url', data.resolved_url);
                    }
                }

                // Subtle flash animation on updated card
                card.style.transition = 'box-shadow 0.3s ease, border-color 0.3s ease';
                card.style.borderColor = 'var(--lime)';
                card.style.boxShadow = '0 0 20px color-mix(in srgb, var(--lime) 30%, transparent)';
                setTimeout(() => {
                    card.style.borderColor = 'var(--line)';
                    card.style.boxShadow = 'none';
                }, 1600);
            }

            // 2. Update day totals in DOM
            if (data.day_totals) {
                const dayCalsEl  = document.getElementById('day-total-cals-' + dayNum);
                const dayProEl   = document.getElementById('day-total-pro-' + dayNum);
                const dayCarbsEl = document.getElementById('day-total-carbs-' + dayNum);
                const dayFatEl   = document.getElementById('day-total-fat-' + dayNum);

                if (dayCalsEl)  dayCalsEl.textContent = data.day_totals.calories;
                if (dayProEl)   dayProEl.textContent = data.day_totals.protein_g;
                if (dayCarbsEl) dayCarbsEl.textContent = data.day_totals.carbs_g;
                if (dayFatEl)   dayFatEl.textContent = data.day_totals.fat_g;
            }

            // 3. If swapped meal is today's schedule, update today's targets in Macro Tracker
            if (data.is_today && data.today_targets) {
                const tarDisp  = document.getElementById('macro-target-cals');
                const tarPro   = document.getElementById('macro-target-pro');
                const tarCarbs = document.getElementById('macro-target-carbs');
                const tarFat   = document.getElementById('macro-target-fat');

                if (tarDisp)  tarDisp.textContent = data.today_targets.target_cals;
                if (tarPro)   tarPro.textContent = data.today_targets.target_pro;
                if (tarCarbs) tarCarbs.textContent = data.today_targets.target_carbs;
                if (tarFat)   tarFat.textContent = data.today_targets.target_fat;

                // Re-evaluate Macro Tracker progress percentages
                const loggedCals = parseFloat(document.getElementById('macro-logged-cals')?.textContent || 0);
                const loggedPro  = parseFloat(document.getElementById('macro-logged-pro')?.textContent || 0);
                const loggedCarbs = parseFloat(document.getElementById('macro-logged-carbs')?.textContent || 0);
                const loggedFat  = parseFloat(document.getElementById('macro-logged-fat')?.textContent || 0);

                if (typeof window.applyMacroLogResultToUI === 'function') {
                    window.applyMacroLogResultToUI({
                        success: true,
                        logged_cals: loggedCals,
                        logged_pro: loggedPro,
                        logged_carbs: loggedCarbs,
                        logged_fat: loggedFat,
                        target_cals: data.today_targets.target_cals,
                        target_pro: data.today_targets.target_pro,
                        target_carbs: data.today_targets.target_carbs,
                        target_fat: data.today_targets.target_fat
                    }, false);
                }
            }

            // Toast feedback
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: `${mealType} Swapped!`,
                html: `New meal: <strong>${escapeHtml(foodItems)}</strong> (${cals} kcal)`,
                showConfirmButton: false,
                timer: 3500,
                timerProgressBar: true,
                background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
            });
        } else {
            throw new Error(data.error || 'Could not swap meal.');
        }
    } catch (err) {
        console.error("Swap meal error:", err);
        Swal.fire({
            icon: 'error',
            title: 'Swap Failed',
            text: err.message || 'Could not update meal plan. Please try again.',
            background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
            color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
            confirmButtonColor: 'var(--lime, #c7ff22)',
        });
    }
}

function closeSwapModal() {
    const modal = document.getElementById('modal-swap-meal');
    if (modal) modal.style.display = 'none';
    currentSwappingMeal = null;
    swapOptionsData = [];
}

// Utility: escape HTML
function escapeHtml(str) {
    if (!str) return '';
    return str.toString()
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Utility: capitalize
function capitalize(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1);
}

document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam  = urlParams.get('tab');
    let savedTab = null;
    try { savedTab = localStorage.getItem('fittracks_diet_active_tab'); } catch (e) {}

    if (tabParam === 'plan' || (!tabParam && savedTab === 'plan')) {
        switchDietView('plan');
    } else {
        switchDietView('tracker');
    }
});
</script>

<?php
    render_footer();
}
