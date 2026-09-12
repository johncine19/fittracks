<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

try {
    $pdo = db();

    // 1. Create table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `food_items` (
            `food_id` INT AUTO_INCREMENT PRIMARY KEY,
            `gym_id` INT NULL DEFAULT NULL,
            `name` VARCHAR(255) NOT NULL,
            `meal_type` ENUM('Breakfast', 'Lunch', 'Dinner', 'Snack') NOT NULL,
            `dietary_restriction` VARCHAR(100) DEFAULT 'none',
            `serving_size` VARCHAR(100) DEFAULT '1 serving',
            `calories` INT NOT NULL,
            `protein_g` DECIMAL(6,1) NOT NULL,
            `carbs_g` DECIMAL(6,1) NOT NULL,
            `fat_g` DECIMAL(6,1) NOT NULL,
            `image_url` VARCHAR(255) DEFAULT NULL,
            `recipe_desc` TEXT DEFAULT NULL,
            `source` VARCHAR(50) DEFAULT 'system',
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_food_gym_type` (`gym_id`, `meal_type`),
            KEY `idx_food_restriction` (`dietary_restriction`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Table food_items created or already exists.\n";

    // 2. Check if already seeded
    $count = (int) $pdo->query("SELECT COUNT(*) FROM food_items WHERE source = 'system'")->fetchColumn();
    if ($count > 0) {
        echo "Food items already seeded ($count items found).\n";
        exit;
    }

    // 3. Seed initial library of Filipino and fitness staple foods
    $seeds = [
        // Breakfast
        ['Tapsilog (Beef Tapa, Garlic Brown Rice, Fried Egg)', 'Breakfast', 'none', '1 serving (1 plate)', 520, 32.0, 55.0, 18.0, 'Lean cured beef tapa with garlic brown rice and a sunny-side up egg.'],
        ['Bangsilog (Grilled Boneless Milkfish, Garlic Rice, Egg)', 'Breakfast', 'pescatarian', '1 serving (1 plate)', 460, 34.0, 48.0, 14.0, 'Marinated boneless bangus grilled to perfection with garlic brown rice.'],
        ['Chicken Longsilog (Skinless Chicken Longganisa, Rice, Egg)', 'Breakfast', 'halal', '1 serving (1 plate)', 480, 36.0, 50.0, 15.0, 'Lean homemade chicken longganisa with brown garlic rice and an egg.'],
        ['Tortang Talong (Eggplant Omelet) with Garlic Rice', 'Breakfast', 'vegetarian', '1 serving', 380, 18.0, 44.0, 14.0, 'Smoky roasted eggplant coated in whisked egg and seared with olive oil.'],
        ['Tofu Scramble Adobo Style with Garlic Rice', 'Breakfast', 'vegan', '1 serving', 390, 22.0, 48.0, 12.0, 'Crumbled firm tofu seasoned with vinegar, garlic, and tamari.'],
        ['Oatmeal Protein Champorado with Chia & Almond Butter', 'Breakfast', 'vegetarian', '1 bowl', 420, 26.0, 52.0, 12.0, 'Rolled oats simmered with pure dark cocoa, whey protein, and chia seeds.'],
        ['Keto Bacon, Spinach & Cheddar 3-Egg Omelet with Avocado', 'Breakfast', 'keto', '1 serving', 490, 28.0, 5.0, 40.0, 'Fluffy 3-egg omelet loaded with spinach, cheddar, bacon, and avocado.'],
        ['Smoked Salmon Avocado Sourdough Toast with Soft Eggs', 'Breakfast', 'pescatarian', '2 slices', 450, 28.0, 38.0, 18.0, 'Wild smoked salmon over mashed avocado on artisan sourdough toast.'],
        ['Gluten-Free Banana Oat Protein Pancakes', 'Breakfast', 'gluten-free', '3 pancakes', 390, 24.0, 52.0, 8.0, 'Pancakes made from blended oats, banana, egg whites, and protein.'],
        ['Tinapasilog (Smoked Fish, Garlic Rice, Egg)', 'Breakfast', 'pescatarian', '1 plate', 430, 30.0, 48.0, 12.0, 'Traditional smoked fish with garlic rice and sliced fresh tomatoes.'],

        // Lunch
        ['Chicken Breast Adobo with Garlic Brown Rice & Steamed Cabbage', 'Lunch', 'none', '1 plate (200g chicken)', 540, 48.0, 52.0, 14.0, 'Skinless chicken breast simmered in soy sauce, vinegar, garlic, and bay leaves.'],
        ['Sinigang na Hipon (Shrimp & Water Spinach in Tamarind Broth)', 'Lunch', 'pescatarian', '1 large bowl + rice', 420, 36.0, 48.0, 8.0, 'Fresh shrimp simmered in sour tamarind soup with kangkong and radish.'],
        ['Ginisang Munggo (Mung Bean Stew) with Crispy Tofu & Brown Rice', 'Lunch', 'vegan', '1 bowl + rice', 460, 26.0, 64.0, 10.0, 'High-fiber mung bean soup simmered with moringa leaves and golden tofu.'],
        ['Grilled Chicken Inasal with Brown Rice & Pickled Papaya', 'Lunch', 'halal', '1 quarter chicken + rice', 510, 46.0, 48.0, 14.0, 'Chicken marinated in calamansi, lemongrass, and annatto, grilled over charcoal.'],
        ['Grilled Salmon Teriyaki Bowl with Quinoa & Edamame', 'Lunch', 'pescatarian', '1 bowl', 550, 42.0, 46.0, 20.0, 'Pan-seared Atlantic salmon fillet over quinoa with steamed edamame.'],
        ['Adobong Sitaw & Firm Tofu with Quinoa', 'Lunch', 'vegan', '1 bowl', 410, 22.0, 54.0, 12.0, 'Yard-long green beans and tofu sautéed in garlic adobo sauce.'],
        ['Keto Grilled Pork Tenderloin with Ensaladang Talong', 'Lunch', 'keto', '200g pork + salad', 480, 44.0, 6.0, 30.0, 'Lean pork tenderloin grilled with calamansi and roasted eggplant salad.'],
        ['Lean Beef Picadillo with Diced Potatoes & Peas', 'Lunch', 'none', '1 cup + rice', 520, 42.0, 50.0, 16.0, '90/10 lean ground beef stewed with carrots, peas, and tomatoes.'],
        ['Chicken Tinola (Ginger Chicken Broth with Sayote & Malunggay)', 'Lunch', 'none', '1 large bowl + rice', 460, 42.0, 44.0, 11.0, 'Nourishing ginger broth with chicken breast, green papaya, and moringa leaves.'],
        ['Pinakbet with Grilled Fish & Steamed Rice', 'Lunch', 'pescatarian', '1 plate', 440, 34.0, 52.0, 10.0, 'Mixed indigenous squash, okra, eggplant, and string beans with grilled fish.'],

        // Dinner
        ['Inihaw na Bangus (Grilled Milkfish stuffed with Tomatoes & Onions)', 'Dinner', 'pescatarian', '1 whole fish + rice', 480, 42.0, 44.0, 14.0, 'Charcoal grilled bangus stuffed with sweet onions and ripe tomatoes.'],
        ['Chicken Inasal Skewers with Cucumber Tomato Salad', 'Dinner', 'halal', '3 skewers + salad', 420, 45.0, 18.0, 12.0, 'Spiced grilled chicken breast skewers with fresh vinaigrette salad.'],
        ['Salmon Sinigang (Salmon Head/Fillet in Sour Tamarind Broth)', 'Dinner', 'pescatarian', '1 bowl + brown rice', 500, 38.0, 42.0, 18.0, 'Hearty salmon pieces simmered with tomatoes, mustard greens, and tamarind.'],
        ['Vegetable Pinakbet with Air-Fried Tofu & Brown Rice', 'Dinner', 'vegetarian', '1 plate', 430, 24.0, 58.0, 11.0, 'Squash, string beans, eggplant, and bitter melon with crispy tofu cubes.'],
        ['Laing (Taro Leaves in Spicy Coconut Milk with Shrimp)', 'Dinner', 'pescatarian', '1 cup + rice', 470, 28.0, 40.0, 22.0, 'Simmered dried taro leaves in rich coconut milk with bird-eye chili.'],
        ['Keto Grilled Ribeye Steak with Garlic Butter Mushrooms & Asparagus', 'Dinner', 'keto', '250g steak + veg', 620, 52.0, 5.0, 44.0, 'Grass-fed ribeye steak seared with herb garlic butter and tender asparagus.'],
        ['Gluten-Free Chicken Arroz Caldo with Hard Boiled Egg', 'Dinner', 'gluten-free', '1 large bowl', 420, 32.0, 50.0, 10.0, 'Rice porridge cooked with aromatic ginger, chicken breast, and toasted garlic.'],
        ['Lean Beef Bistek Tagalog with Onion Rings & Brown Rice', 'Dinner', 'none', '1 plate', 510, 44.0, 46.0, 15.0, 'Thinly sliced top round beef braised in calamansi, soy sauce, and white onions.'],
        ['Grilled Tuna Belly with Steamed Brown Rice & Ensalada', 'Dinner', 'pescatarian', '200g tuna + rice', 490, 46.0, 44.0, 14.0, 'Tuna belly marinated in soy and calamansi, grilled over high heat.'],
        ['Vegan Bicol Express with Tofu, Eggplant & Coconut Milk', 'Dinner', 'vegan', '1 bowl + rice', 460, 22.0, 52.0, 18.0, 'Green finger chilies, tofu, and eggplant simmered in coconut cream.'],

        // Snacks
        ['Boiled Saba Banana with Natural Peanut Butter', 'Snack', 'vegan', '2 saba + 1 tbsp peanut butter', 260, 6.0, 46.0, 8.0, 'Steamed native Filipino saba bananas served with creamy natural peanut butter.'],
        ['Boiled Kamote (Sweet Potato) with Cinnamon', 'Snack', 'vegan', '1 medium root (150g)', 160, 3.0, 38.0, 0.5, 'Steamed purple or golden sweet potato dusted with Ceylon cinnamon.'],
        ['Whey Protein Shake with Skim Milk & Half Banana', 'Snack', 'vegetarian', '1 shaker bottle (300ml)', 240, 28.0, 22.0, 2.5, 'Gold standard whey protein isolate shaken with milk and blended banana.'],
        ['Hard-Boiled Eggs with Pink Himalayan Salt & Black Pepper', 'Snack', 'keto', '2 large eggs', 140, 13.0, 1.0, 10.0, 'Free-range farm eggs hard-boiled and seasoned with cracked pepper.'],
        ['Plain Greek Yogurt with Blueberries & Crushed Almonds', 'Snack', 'vegetarian', '1 cup (180g)', 210, 18.0, 16.0, 8.0, 'Thick strained Greek yogurt topped with fresh blueberries and raw almonds.'],
        ['Steamed Edamame in Pods with Sea Salt', 'Snack', 'vegan', '1 cup (150g)', 180, 17.0, 14.0, 8.0, 'Young whole soybeans in pods steamed and seasoned with flaked sea salt.'],
        ['Crispy Baked Pork Rinds (Chicharon) with Vinegar Dip', 'Snack', 'keto', '40g bag', 210, 24.0, 0.0, 13.0, 'Zero-carb oven-puffed pork rinds dipped in spiced cane vinegar.'],
        ['Apple Slices with Crunchy Almond Butter', 'Snack', 'vegan', '1 medium apple + 1 tbsp butter', 200, 4.0, 28.0, 9.0, 'Crisp Fuji apple wedges paired with pure roasted almond butter.'],
        ['Tuna Salad on Whole Wheat Crackers', 'Snack', 'pescatarian', '1 can tuna + 4 crackers', 220, 24.0, 18.0, 5.0, 'Canned tuna in water mixed with light mayo, celery, and whole grain crackers.'],
        ['Cottage Cheese with Fresh Pineapple Chunks', 'Snack', 'vegetarian', '1 cup (200g)', 190, 24.0, 16.0, 2.5, 'Low-fat curd cottage cheese served with naturally sweet diced pineapple.']
    ];

    $stmt = $pdo->prepare("
        INSERT INTO `food_items` 
        (`gym_id`, `name`, `meal_type`, `dietary_restriction`, `serving_size`, `calories`, `protein_g`, `carbs_g`, `fat_g`, `recipe_desc`, `source`) 
        VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'system')
    ");

    $inserted = 0;
    foreach ($seeds as $item) {
        $stmt->execute([
            $item[0], // name
            $item[1], // meal_type
            $item[2], // restriction
            $item[3], // serving_size
            $item[4], // calories
            $item[5], // protein_g
            $item[6], // carbs_g
            $item[7], // fat_g
            $item[8]  // recipe_desc
        ]);
        $inserted++;
    }

    echo "Successfully seeded $inserted standard food items into the food_items table!\n";
} catch (Throwable $e) {
    echo "Error during migration: " . $e->getMessage() . "\n";
    exit(1);
}
