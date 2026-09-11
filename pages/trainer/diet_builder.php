<?php
declare(strict_types=1);

require_once __DIR__ . '/../member/diet.php';

function diet_builder_page(): void
{
    $user = require_roles(['trainer', 'gym_owner', 'platform_admin']);
    $memberId = (int) ($_GET['member_user_id'] ?? 0);
    $ref = trim((string)($_GET['ref'] ?? ''));
    
    if (!$memberId) {
        redirect('dashboard');
    }
    
    $pdo = db();
    
    // Determine Back URL
    if ($user['role'] === 'gym_owner') {
        $backUrl = ($ref === 'users') ? 'index.php?page=users&tab=member' : 'index.php?page=trainer_assignments';
    } elseif ($user['role'] === 'platform_admin') {
        $backUrl = ($ref === 'users') ? 'index.php?page=users&tab=member' : 'index.php?page=trainer_assignments';
    } else {
        $backUrl = 'index.php?page=trainer_members';
    }
    $refQuery = $ref ? '&ref=' . urlencode($ref) : '';

    // Fetch member
    $member = $pdo->query('SELECT first_name, last_name, profile_picture FROM users WHERE user_id = ' . $memberId)->fetch();
    if (!$member) {
        flash('Member not found.', 'danger');
        header('Location: ' . $backUrl);
        exit;
    }
    $profile = $pdo->query('SELECT * FROM member_profiles WHERE user_id = ' . $memberId)->fetch();

    // Authorization & Scoping checks
    if ($user['role'] === 'trainer') {
        $trainerProfile = $pdo->query('SELECT trainer_id FROM trainer_profiles WHERE user_id = ' . (int)$user['user_id'])->fetch();
        if (!$trainerProfile) {
            flash('Trainer profile not found.', 'danger');
            redirect('trainer_members');
        }
        $trainerId = (int) $trainerProfile['trainer_id'];

        // Verify active assignment
        $isAssigned = (bool) scalar('SELECT 1 FROM trainer_assignments WHERE trainer_id = ? AND member_user_id = ? AND status = "active"', [$trainerId, $memberId]);
        if (!$isAssigned) {
            flash('You can only manage meal plans for members actively assigned to you.', 'danger');
            redirect('trainer_members');
        }
    } elseif ($user['role'] === 'gym_owner') {
        $gymId = (int) scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [(int)$user['user_id']]);
        if (!$gymId) {
            flash('No active gym found for your account.', 'danger');
            redirect('dashboard');
        }
        // Verify member belongs to this gym
        $memberInGym = (bool) scalar('
            SELECT 1 FROM gym_members WHERE user_id = ? AND gym_id = ?
            UNION
            SELECT 1 FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = ? AND mp.gym_id = ?
        ', [$memberId, $gymId, $memberId, $gymId]);

        if (!$memberInGym) {
            flash('This member does not belong to your gym facility.', 'danger');
            header('Location: ' . $backUrl);
            exit;
        }
        $trainerId = ensure_coach_profile((int)$user['user_id']);
    } else {
        // platform_admin
        $trainerId = ensure_coach_profile((int)$user['user_id']);
    }
    
    // Check for draft for this member (unified so trainer and gym owner work on the same plan)
    $stmt = $pdo->prepare('SELECT * FROM dietary_plans WHERE member_user_id = ? AND status = "draft" ORDER BY plan_id DESC LIMIT 1');
    $stmt->execute([$memberId]);
    $draft = $stmt->fetch();
    
    if (!$draft) {
        // Check if there is an active plan to clone as a draft
        $stmtActive = $pdo->prepare('SELECT * FROM dietary_plans WHERE member_user_id = ? AND status = "active" ORDER BY plan_id DESC LIMIT 1');
        $stmtActive->execute([$memberId]);
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
            
            $stmtInsertMeal = $pdo->prepare('INSERT INTO dietary_plan_meals (plan_id, day_of_week, meal_type, food_items, image_url, calories, protein_g, carbs_g, fat_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($meals as $m) {
                $stmtInsertMeal->execute([$planId, $m['day_of_week'], $m['meal_type'], $m['food_items'], $m['image_url'] ?? null, $m['calories'], $m['protein_g'], $m['carbs_g'], $m['fat_g']]);
            }
        } else {
            // Create empty draft
            $goal = $profile['primary_goal'] ?? 'general_health';
            $title = 'Diet Plan for ' . ($member['first_name'] ?? 'Member');
            $stmt = $pdo->prepare('INSERT INTO dietary_plans (member_user_id, trainer_id, title, goal, status) VALUES (?, ?, ?, ?, "draft")');
            $stmt->execute([$memberId, $trainerId, $title, $goal]);
            $planId = (int) $pdo->lastInsertId();
        }
    } else {
        $planId = (int) $draft['plan_id'];
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = post('action');
        
        if ($action === 'publish') {
            // Archive old active plans
            $pdo->prepare('UPDATE dietary_plans SET status = "completed" WHERE member_user_id = ? AND status = "active"')->execute([$memberId]);
            // Set draft to active and record this trainer/owner as publisher
            $pdo->prepare('UPDATE dietary_plans SET status = "active", trainer_id = ? WHERE plan_id = ?')->execute([$trainerId, $planId]);
            
            $editorRoleText = ($user['role'] === 'gym_owner') ? 'gym owner' : (($user['role'] === 'platform_admin') ? 'admin' : 'trainer');
            notify_user($memberId, 'system', 'New Diet Plan!', 'Your ' . $editorRoleText . ' has published an updated dietary plan for you.');
            flash('Diet plan published successfully!', 'success');
            
            header('Location: ' . $backUrl);
            exit;
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
                header('Location: index.php?page=diet_builder&member_user_id=' . $memberId . $refQuery);
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
            header('Location: index.php?page=diet_builder&member_user_id=' . $memberId . $refQuery);
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
            
            header('Location: index.php?page=diet_builder&member_user_id=' . $memberId . $refQuery);
            exit;
        }
        
        if ($action === 'remove_meal') {
            $mealId = (int) post('meal_id');
            $pdo->prepare('DELETE FROM dietary_plan_meals WHERE meal_id = ? AND plan_id = ?')->execute([$mealId, $planId]);
            header('Location: index.php?page=diet_builder&member_user_id=' . $memberId . $refQuery);
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
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; flex-wrap: wrap; gap: 14px;">
    <div>
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <h2 style="margin:0;">Diet Plan for <?= h(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?></h2>
            <?php if ($user['role'] === 'gym_owner'): ?>
                <span class="badge" style="background: rgba(59, 130, 246, 0.15); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.3); font-size: 11px; padding: 3px 8px; font-weight: 700; border-radius: 6px;">Gym Owner Mode</span>
            <?php elseif ($user['role'] === 'platform_admin'): ?>
                <span class="badge" style="background: rgba(168, 85, 247, 0.15); color: #a855f7; border: 1px solid rgba(168, 85, 247, 0.3); font-size: 11px; padding: 3px 8px; font-weight: 700; border-radius: 6px;">Admin Mode</span>
            <?php else: ?>
                <span class="badge" style="background: rgba(132, 204, 22, 0.15); color: #84cc16; border: 1px solid rgba(132, 204, 22, 0.3); font-size: 11px; padding: 3px 8px; font-weight: 700; border-radius: 6px;">Assigned Trainer</span>
            <?php endif; ?>
        </div>
        <p style="color: var(--muted); font-size: 13px; margin: 4px 0 0;">Manage daily meals, nutrition, and macro targets for this member.</p>
    </div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
        <a href="<?= h($backUrl) ?>" class="btn btn-ghost" style="text-decoration: none;">&larr; Back</a>
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

<style>
.diet-builder-layout {
    display: grid;
    grid-template-columns: 340px 1fr;
    gap: 24px;
    align-items: flex-start;
}
@media (max-width: 992px) {
    .diet-builder-layout {
        grid-template-columns: 1fr;
    }
}
.tab-btn.active {
    background: var(--bg) !important;
    color: var(--lime) !important;
    border-color: color-mix(in srgb, var(--lime) 40%, var(--line)) !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.tab-btn:hover:not(.active) {
    background: var(--panel-soft) !important;
    color: var(--ink) !important;
}
.day-panel {
    display: none;
}
.day-panel.active {
    display: block;
}
</style>

<div class="diet-builder-layout">
    <!-- Left Column: Add Meal Form -->
    <div style="width: 100%;">
        <section class="panel" style="position: sticky; top: 20px; padding: 20px; background: var(--surface); border: 1px solid var(--line); border-radius: 12px;">
            <h3 style="margin: 0 0 16px; font-size: 16px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                Add Meal
            </h3>
            <form method="post" style="display: flex; flex-direction: column; gap: 14px; margin: 0;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_meal">
                
                <div>
                    <label style="display:block; font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 6px;">Day of Week</label>
                    <select name="day_of_week" required style="width: 100%; box-sizing: border-box; padding: 9px 12px; border-radius: 8px; border: 1px solid var(--line); background: var(--bg); color: var(--ink); font-size: 13.5px;">
                        <?php foreach($daysMap as $num => $name): ?>
                            <option value="<?= $num ?>"><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label style="display:block; font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 6px;">Meal Type</label>
                    <select name="meal_type" required style="width: 100%; box-sizing: border-box; padding: 9px 12px; border-radius: 8px; border: 1px solid var(--line); background: var(--bg); color: var(--ink); font-size: 13.5px;">
                        <option value="Breakfast">Breakfast</option>
                        <option value="Lunch">Lunch</option>
                        <option value="Dinner">Dinner</option>
                        <option value="Snack">Snack</option>
                    </select>
                </div>
                
                <div>
                    <label style="display:block; font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 6px;">Food Items / Description</label>
                    <textarea name="food_items" required rows="3" placeholder="e.g. 2 boiled eggs, 1 slice whole wheat toast..." style="width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--line); background: var(--bg); color: var(--ink); font-size: 13px; resize: vertical; line-height: 1.4;"></textarea>
                </div>
                
                <!-- Macro Inputs 2x2 Grid -->
                <div>
                    <label style="display:block; font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 6px;">Nutrition & Macros</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div style="background: var(--bg); border: 1px solid var(--line); border-radius: 8px; padding: 8px 10px;">
                            <span style="font-size: 11px; font-weight: 600; color: var(--muted); display: block;">Calories</span>
                            <input type="number" name="calories" id="calc_cals" min="0" value="0" readonly style="width: 100%; border: none; background: transparent; color: var(--lime); font-size: 16px; font-weight: 700; outline: none; padding: 2px 0;">
                        </div>
                        <div style="background: var(--bg); border: 1px solid var(--line); border-radius: 8px; padding: 8px 10px;">
                            <span style="font-size: 11px; font-weight: 600; color: var(--muted); display: block;">Protein (g)</span>
                            <input type="number" name="protein_g" id="calc_p" min="0" value="0" oninput="updateMacros()" style="width: 100%; border: none; background: transparent; color: var(--ink); font-size: 16px; font-weight: 600; outline: none; padding: 2px 0;">
                        </div>
                        <div style="background: var(--bg); border: 1px solid var(--line); border-radius: 8px; padding: 8px 10px;">
                            <span style="font-size: 11px; font-weight: 600; color: var(--muted); display: block;">Carbs (g)</span>
                            <input type="number" name="carbs_g" id="calc_c" min="0" value="0" oninput="updateMacros()" style="width: 100%; border: none; background: transparent; color: var(--ink); font-size: 16px; font-weight: 600; outline: none; padding: 2px 0;">
                        </div>
                        <div style="background: var(--bg); border: 1px solid var(--line); border-radius: 8px; padding: 8px 10px;">
                            <span style="font-size: 11px; font-weight: 600; color: var(--muted); display: block;">Fat (g)</span>
                            <input type="number" name="fat_g" id="calc_f" min="0" value="0" oninput="updateMacros()" style="width: 100%; border: none; background: transparent; color: var(--ink); font-size: 16px; font-weight: 600; outline: none; padding: 2px 0;">
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" style="margin-top: 6px; width: 100%; padding: 11px; font-weight: 700; font-size: 14px; display: inline-flex; justify-content: center; align-items: center; gap: 6px; border-radius: 8px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                    Add Meal to Plan
                </button>
            </form>
        </section>
    </div>
    
    <!-- Right Column: Week Schedule & Meals View -->
    <div style="min-width: 0; display: flex; flex-direction: column; gap: 16px;">
        <!-- Day Tabs Bar -->
        <div class="days-tabs-nav" style="display: flex; gap: 6px; overflow-x: auto; padding: 6px; background: var(--surface); border: 1px solid var(--line); border-radius: 10px;">
            <?php foreach ($daysMap as $dayNum => $dayName): 
                $dayMealCount = count($mealsByDay[$dayNum] ?? []);
            ?>
                <button type="button" class="tab-btn" data-day="<?= $dayNum ?>" onclick="switchDay(<?= $dayNum ?>)" style="flex: 1; padding: 10px 10px; border-radius: 8px; border: 1px solid transparent; background: transparent; color: var(--muted); font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; white-space: nowrap; display: flex; flex-direction: column; align-items: center; gap: 3px;">
                    <span><?= $dayName ?></span>
                    <span style="font-size: 11px; opacity: 0.75; font-weight: 500;"><?= $dayMealCount ?> <?= $dayMealCount === 1 ? 'meal' : 'meals' ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Day Meal Panels -->
        <?php foreach ($daysMap as $dayNum => $dayName): 
            $dayCals = 0; $dayPro = 0; $dayCarbs = 0; $dayFat = 0;
            foreach ($mealsByDay[$dayNum] as $m) {
                $dayCals += (int)($m['calories'] ?? 0);
                $dayPro += (float)($m['protein_g'] ?? 0);
                $dayCarbs += (float)($m['carbs_g'] ?? 0);
                $dayFat += (float)($m['fat_g'] ?? 0);
            }
        ?>
            <div class="panel day-panel" id="day-panel-<?= $dayNum ?>" style="margin: 0; padding: 20px; background: var(--surface); border: 1px solid var(--line); border-radius: 12px;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--line); padding-bottom: 14px; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <h3 style="margin: 0; font-size: 17px; font-weight: 700; color: var(--ink);"><?= $dayName ?> Menu</h3>
                        <p style="margin: 2px 0 0; font-size: 12.5px; color: var(--muted);"><?= count($mealsByDay[$dayNum]) ?> meals scheduled for this day</p>
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                        <span style="background: rgba(163, 230, 53, 0.12); color: var(--lime); border: 1px solid rgba(163, 230, 53, 0.25); padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 700;">
                            🔥 <?= $dayCals ?> kcal
                        </span>
                        <span style="background: var(--bg); border: 1px solid var(--line); padding: 4px 10px; border-radius: 20px; font-size: 12px; color: var(--ink); font-weight: 600;">
                            P: <?= round($dayPro, 1) ?>g
                        </span>
                        <span style="background: var(--bg); border: 1px solid var(--line); padding: 4px 10px; border-radius: 20px; font-size: 12px; color: var(--ink); font-weight: 600;">
                            C: <?= round($dayCarbs, 1) ?>g
                        </span>
                        <span style="background: var(--bg); border: 1px solid var(--line); padding: 4px 10px; border-radius: 20px; font-size: 12px; color: var(--ink); font-weight: 600;">
                            F: <?= round($dayFat, 1) ?>g
                        </span>
                    </div>
                </div>
                
                <?php if (empty($mealsByDay[$dayNum])): ?>
                    <div style="text-align: center; padding: 40px 20px; background: var(--bg); border: 1px dashed var(--line); border-radius: 10px;">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.5" style="margin-bottom: 10px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <p style="margin: 0; font-size: 14px; font-weight: 600; color: var(--ink);">No meals planned for <?= $dayName ?> yet</p>
                        <p style="margin: 4px 0 0; font-size: 12.5px; color: var(--muted);">Fill out the Add Meal form on the left or click "Generate Plan" above to create an automated 7-day menu.</p>
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <?php foreach ($mealsByDay[$dayNum] as $meal): 
                            $photoUrl = get_meal_photo_url($meal['image_url'] ?? null, $meal['food_items'], $meal['meal_type']);
                        ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 14px; background: var(--bg); border: 1px solid var(--line); border-radius: 10px; gap: 14px; transition: border-color 0.2s;">
                                <div style="display: flex; gap: 14px; align-items: center; min-width: 0; flex: 1;">
                                    <img src="<?= h($photoUrl) ?>" alt="" style="width: 58px; height: 58px; border-radius: 8px; object-fit: cover; flex-shrink: 0; border: 1px solid var(--line); background: #000;" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80';">
                                    <div style="min-width: 0; flex: 1;">
                                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                            <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); padding: 2px 8px; border-radius: 4px;">
                                                <?= h($meal['meal_type']) ?>
                                            </span>
                                            <span style="font-size: 12px; font-weight: 700; color: var(--lime);">
                                                <?= $meal['calories'] ?> kcal
                                            </span>
                                        </div>
                                        <p style="margin: 4px 0 0; font-size: 13.5px; font-weight: 500; color: var(--ink); line-height: 1.4; word-break: break-word;"><?= nl2br(h($meal['food_items'])) ?></p>
                                        <div style="display: flex; gap: 12px; margin-top: 6px; font-size: 11.5px; color: var(--muted); font-weight: 500;">
                                            <span>Protein: <strong style="color: var(--ink);"><?= $meal['protein_g'] ?>g</strong></span>
                                            <span>Carbs: <strong style="color: var(--ink);"><?= $meal['carbs_g'] ?>g</strong></span>
                                            <span>Fat: <strong style="color: var(--ink);"><?= $meal['fat_g'] ?>g</strong></span>
                                        </div>
                                    </div>
                                </div>
                                <form method="post" style="margin:0; flex-shrink: 0;" onsubmit="return confirm('Remove this meal from <?= $dayName ?>?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_meal">
                                    <input type="hidden" name="meal_id" value="<?= $meal['meal_id'] ?>">
                                    <button class="btn btn-sm btn-danger" style="padding: 6px 12px; font-size: 12px; border-radius: 6px;" title="Remove Meal">Remove</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
function updateMacros() {
    const p = parseInt(document.getElementById('calc_p').value) || 0;
    const c = parseInt(document.getElementById('calc_c').value) || 0;
    const f = parseInt(document.getElementById('calc_f').value) || 0;
    const calsInput = document.getElementById('calc_cals');
    if (calsInput) {
        calsInput.value = (p * 4) + (c * 4) + (f * 9);
    }
}

function switchDay(dayNum) {
    document.querySelectorAll('.day-panel').forEach(panel => {
        panel.style.display = 'none';
        panel.classList.remove('active');
    });
    const activePanel = document.getElementById('day-panel-' + dayNum);
    if (activePanel) {
        activePanel.style.display = 'block';
        activePanel.classList.add('active');
    }

    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    const activeBtn = document.querySelector('.tab-btn[data-day="' + dayNum + '"]');
    if (activeBtn) {
        activeBtn.classList.add('active');
    }

    const daySelect = document.querySelector('select[name="day_of_week"]');
    if (daySelect) {
        daySelect.value = dayNum;
    }
}
document.addEventListener("DOMContentLoaded", function() {
    switchDay(1);
    updateMacros();
});
</script>
<?php
    render_footer();
}
