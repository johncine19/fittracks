<?php
declare(strict_types=1);

function diet_builder_page(): void
{
    $user = require_roles(['trainer']);
    $memberId = (int) ($_GET['member_user_id'] ?? 0);
    
    if (!$memberId) {
        redirect('dashboard');
    }
    
    $pdo = db();
    
    // Fetch member
    $member = $pdo->query('SELECT first_name, last_name, profile_picture FROM users WHERE user_id = ' . $memberId)->fetch();
    $profile = $pdo->query('SELECT * FROM member_profiles WHERE user_id = ' . $memberId)->fetch();
    
    // Fetch trainer ID
    $trainerProfile = $pdo->query('SELECT trainer_id FROM trainer_profiles WHERE user_id = ' . (int)$user['user_id'])->fetch();
    if (!$trainerProfile) {
        die("Trainer profile not found.");
    }
    $trainerId = (int) $trainerProfile['trainer_id'];
    
    // Check for draft
    $stmt = $pdo->prepare('SELECT * FROM dietary_plans WHERE member_user_id = ? AND trainer_id = ? AND status = "draft" LIMIT 1');
    $stmt->execute([$memberId, $trainerId]);
    $draft = $stmt->fetch();
    
    if (!$draft) {
        // Check if there is an active plan to clone as a draft
        $stmtActive = $pdo->prepare('SELECT * FROM dietary_plans WHERE member_user_id = ? AND trainer_id = ? AND status = "active" LIMIT 1');
        $stmtActive->execute([$memberId, $trainerId]);
        $activePlan = $stmtActive->fetch();
        
        if ($activePlan) {
            $goal = $activePlan['goal'];
            $title = $activePlan['title'];
            $stmtInsert = $pdo->prepare('INSERT INTO dietary_plans (member_user_id, trainer_id, title, goal, status) VALUES (?, ?, ?, ?, "draft")');
            $stmtInsert->execute([$memberId, $trainerId, $title, $goal]);
            $planId = (int) $pdo->lastInsertId();
            
            // Copy meals
            $stmtMeals = $pdo->prepare('SELECT * FROM dietary_plan_meals WHERE plan_id = ?');
            $stmtMeals->execute([$activePlan['plan_id']]);
            $meals = $stmtMeals->fetchAll();
            
            $stmtInsertMeal = $pdo->prepare('INSERT INTO dietary_plan_meals (plan_id, day_of_week, meal_type, food_items, calories, protein_g, carbs_g, fat_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($meals as $m) {
                $stmtInsertMeal->execute([$planId, $m['day_of_week'], $m['meal_type'], $m['food_items'], $m['calories'], $m['protein_g'], $m['carbs_g'], $m['fat_g']]);
            }
        } else {
            // Create empty draft
            $goal = $profile['primary_goal'] ?? 'general_health';
            $title = 'Diet Plan for ' . $member['first_name'];
            $stmt = $pdo->prepare('INSERT INTO dietary_plans (member_user_id, trainer_id, title, goal, status) VALUES (?, ?, ?, ?, "draft")');
            $stmt->execute([$memberId, $trainerId, $title, $goal]);
            $planId = (int) $pdo->lastInsertId();
        }
    } else {
        $planId = (int) $draft['plan_id'];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
        
        if ($action === 'publish') {
            // Archive old active plans
            $pdo->prepare('UPDATE dietary_plans SET status = "completed" WHERE member_user_id = ? AND status = "active"')->execute([$memberId]);
            // Set draft to active
            $pdo->prepare('UPDATE dietary_plans SET status = "active" WHERE plan_id = ?')->execute([$planId]);
            
            notify_user($memberId, 'system', 'New Diet Plan!', 'Your trainer has published a new dietary plan for you.');
            flash('Diet plan published successfully!', 'success');
            redirect('trainer_members');
        }

        if ($action === 'generate_plan') {
            $goal = $profile['primary_goal'] ?: 'general_health';
            $tier = $profile['fitness_tier'] ?: 1;
            $expLevel = in_array($tier, [1,2]) ? 1 : (in_array($tier, [3,4]) ? 2 : 3);
            
            // 1. Calculate BMR (Mifflin-St Jeor)
            $w = (float) $profile['weight_kg'];
            $h = (float) $profile['height_cm'];
            $a = (int) $profile['age'];
            if ($w == 0 || $h == 0) {
                flash('Member profile is missing height or weight. Cannot generate plan accurately.', 'danger');
                header('Location: index.php?page=diet_builder&member_user_id=' . $memberId);
                exit;
            }
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
            
            // 5. Generate Meals for 7 days
            $pdo->prepare('DELETE FROM dietary_plan_meals WHERE plan_id = ?')->execute([$planId]);
            
            // Check if dietary_restrictions column exists and get the value (fallback to none)
            try {
                $checkProfile = $pdo->query("SELECT dietary_restrictions FROM member_profiles WHERE user_id = {$memberId}")->fetch();
                $restriction = $checkProfile['dietary_restrictions'] ?? 'none';
            } catch (Exception $e) {
                $restriction = 'none';
            }
            
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
                    
                    // Rotate meal options to vary foods day-by-day
                    $options = $dietFoods[$mType];
                    $selectedFood = $options[($d - 1) % count($options)];
                    
                    $mFood = $portionGrams . "g of " . $selectedFood;
                    $stmt->execute([$planId, $d, $mType, $mFood, $mCals, $mP, $mC, $mF]);
                }
            }
            
            flash("Dietary plan generated automatically! Target: {$targetCals}kcal | P: {$p_g}g | C: {$c_g}g | F: {$f_g}g", 'success');
            header('Location: index.php?page=diet_builder&member_user_id=' . $memberId);
            exit;
        }

        if ($action === 'add_meal') {
            $dayOfWeek = (int) post('day_of_week');
            $mealType = post('meal_type');
            $foodItems = post('food_items');
            $calories = (int) post('calories');
            $protein = (int) post('protein_g');
            $carbs = (int) post('carbs_g');
            $fat = (int) post('fat_g');
            
            $stmt = $pdo->prepare('INSERT INTO dietary_plan_meals (plan_id, day_of_week, meal_type, food_items, calories, protein_g, carbs_g, fat_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$planId, $dayOfWeek, $mealType, $foodItems, $calories, $protein, $carbs, $fat]);
            
            header('Location: index.php?page=diet_builder&member_user_id=' . $memberId);
            exit;
        }
        
        if ($action === 'remove_meal') {
            $mealId = (int) post('meal_id');
            $pdo->prepare('DELETE FROM dietary_plan_meals WHERE meal_id = ? AND plan_id = ?')->execute([$mealId, $planId]);
            header('Location: index.php?page=diet_builder&member_user_id=' . $memberId);
            exit;
        }
    }

    // Fetch meals
    $mealsRaw = $pdo->query('SELECT * FROM dietary_plan_meals WHERE plan_id = ' . $planId . ' ORDER BY day_of_week ASC, FIELD(meal_type, "Breakfast", "Lunch", "Dinner", "Snack")')->fetchAll();
    
    $mealsByDay = [];
    for ($i = 1; $i <= 7; $i++) {
        $mealsByDay[$i] = [];
    }
    foreach ($mealsRaw as $m) {
        $mealsByDay[(int)$m['day_of_week']][] = $m;
    }

    render_header('Build Diet Plan', $user);
    $daysMap = [1=>'Monday', 2=>'Tuesday', 3=>'Wednesday', 4=>'Thursday', 5=>'Friday', 6=>'Saturday', 7=>'Sunday'];
?>
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
    <h2>Diet Plan for <?= h($member['first_name'] . ' ' . $member['last_name']) ?></h2>
    <div style="display: flex; gap: 10px;">
        <a href="index.php?page=trainer_members" class="btn btn-ghost">Back</a>
        <form method="post" style="margin:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="generate_plan">
            <button class="btn" style="background:var(--accent); color:white;" onclick="return confirm('Auto-generate a dietary plan? This will clear any draft meals you have added manually.');">Generate Plan</button>
        </form>
        <form method="post" style="margin:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="publish">
            <button class="btn btn-primary" onclick="return confirm('Publish this diet plan?');">Publish Plan</button>
        </form>
    </div>
</div>

<div style="display: flex; gap: 20px; align-items: flex-start;">
    <!-- Add Meal Form -->
    <div style="flex: 1; max-width: 350px;">
        <section class="panel sticky-panel" style="position: sticky; top: 20px;">
            <h3 style="margin-top:0;">Add Meal</h3>
            <form method="post" class="form grid-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_meal">
                
                <label>Day of Week
                    <select name="day_of_week" required>
                        <?php foreach($daysMap as $num => $name): ?>
                            <option value="<?= $num ?>"><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                
                <label>Meal Type
                    <select name="meal_type" required>
                        <option value="Breakfast">Breakfast</option>
                        <option value="Lunch">Lunch</option>
                        <option value="Dinner">Dinner</option>
                        <option value="Snack">Snack</option>
                    </select>
                </label>
                
                <label>Food Items / Description
                    <textarea name="food_items" required rows="3" placeholder="e.g. 2 boiled eggs, 1 slice whole wheat toast..."></textarea>
                </label>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <label>Calories
                        <input type="number" name="calories" id="calc_cals" min="0" value="0" readonly style="background:var(--bg); border-color:transparent;">
                    </label>
                    <label>Protein (g)
                        <input type="number" name="protein_g" id="calc_p" min="0" value="0" oninput="updateMacros()">
                    </label>
                    <label>Carbs (g)
                        <input type="number" name="carbs_g" id="calc_c" min="0" value="0" oninput="updateMacros()">
                    </label>
                    <label>Fat (g)
                        <input type="number" name="fat_g" id="calc_f" min="0" value="0" oninput="updateMacros()">
                    </label>
                </div>
                
                <script>
                function updateMacros() {
                    const p = parseInt(document.getElementById('calc_p').value) || 0;
                    const c = parseInt(document.getElementById('calc_c').value) || 0;
                    const f = parseInt(document.getElementById('calc_f').value) || 0;
                    document.getElementById('calc_cals').value = (p * 4) + (c * 4) + (f * 9);
                }
                </script>
                </div>
                
                <button class="btn btn-primary" style="margin-top: 10px; width: 100%;">Add Meal</button>
            </form>
        </section>
    </div>
    
    <!-- Week View -->
    <div style="flex: 2; display: flex; flex-direction: column; gap: 15px;">
        <style>
            .tab-btn.active {
                background: var(--lime) !important;
                color: var(--bg) !important;
                border-color: var(--lime) !important;
                font-weight: bold;
            }
            .day-panel {
                display: none;
            }
            .day-panel.active {
                display: block;
            }
        </style>
        
        <!-- Day Tabs -->
        <div class="days-tabs" style="display: flex; gap: 8px; margin-bottom: 5px; overflow-x: auto; padding-bottom: 5px; border-bottom: 1px solid var(--line);">
            <?php foreach ($daysMap as $dayNum => $dayName): ?>
                <button type="button" class="tab-btn" data-day="<?= $dayNum ?>" onclick="switchDay(<?= $dayNum ?>)" style="padding: 8px 16px; border-radius: 6px; border: 1px solid var(--line); background: var(--surface); color: var(--ink); font-weight: 500; cursor: pointer; transition: all 0.2s; white-space: nowrap;">
                    <?= $dayName ?>
                </button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($daysMap as $dayNum => $dayName): ?>
            <div class="panel day-panel" id="day-panel-<?= $dayNum ?>" style="margin: 0; padding: 15px;">
                <h3 style="margin-top: 0; border-bottom: 1px solid var(--line); padding-bottom: 10px; margin-bottom: 10px;"><?= $dayName ?> Plan</h3>
                
                <?php if (empty($mealsByDay[$dayNum])): ?>
                    <p class="muted" style="margin:0; font-size:13px;">No meals assigned for this day.</p>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php 
                        $dayCals = 0; $dayPro = 0; $dayCarbs = 0; $dayFat = 0;
                        foreach ($mealsByDay[$dayNum] as $meal): 
                            $dayCals += $meal['calories'];
                            $dayPro += $meal['protein_g'];
                            $dayCarbs += $meal['carbs_g'];
                            $dayFat += $meal['fat_g'];
                        ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px; background: var(--surface); border: 1px solid var(--line); border-radius: 6px;">
                                <div>
                                    <strong style="color: var(--lime);"><?= h($meal['meal_type']) ?></strong>
                                    <p style="margin: 4px 0 0; font-size: 13px;"><?= nl2br(h($meal['food_items'])) ?></p>
                                    <div style="display: flex; gap: 10px; margin-top: 6px; font-size: 12px; color: var(--muted);">
                                        <span><?= $meal['calories'] ?> kcal</span>
                                        <span>P: <?= $meal['protein_g'] ?>g</span>
                                        <span>C: <?= $meal['carbs_g'] ?>g</span>
                                        <span>F: <?= $meal['fat_g'] ?>g</span>
                                    </div>
                                </div>
                                <form method="post" style="margin:0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_meal">
                                    <input type="hidden" name="meal_id" value="<?= $meal['meal_id'] ?>">
                                    <button class="btn btn-sm btn-danger" style="padding: 4px 8px; font-size: 11px;">Remove</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                        
                        <!-- Daily Totals -->
                        <div style="margin-top: 10px; padding-top: 10px; border-top: 1px dashed var(--line); font-size: 13px; font-weight: bold; color: var(--ink);">
                            Daily Totals: <?= $dayCals ?> kcal | Protein: <?= $dayPro ?>g | Carbs: <?= $dayCarbs ?>g | Fat: <?= $dayFat ?>g
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
function switchDay(dayNum) {
    document.querySelectorAll('.day-panel').forEach(panel => {
        panel.classList.remove('active');
    });
    const activePanel = document.getElementById('day-panel-' + dayNum);
    if (activePanel) activePanel.classList.add('active');

    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    const activeBtn = document.querySelector('.tab-btn[data-day="' + dayNum + '"]');
    if (activeBtn) activeBtn.classList.add('active');

    const daySelect = document.querySelector('select[name="day_of_week"]');
    if (daySelect) {
        daySelect.value = dayNum;
    }
}
document.addEventListener("DOMContentLoaded", function() {
    switchDay(1);
});
</script>
<?php
    render_footer();
}
