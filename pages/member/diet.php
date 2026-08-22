<?php
declare(strict_types=1);

function diet_page(): void
{
    $user = require_roles(['member']);
    $pdo = db();
    $userId = (int) $user['user_id'];
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
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
    $plan = $pdo->prepare('SELECT dp.*, u.first_name as t_first, u.last_name as t_last FROM dietary_plans dp LEFT JOIN trainer_profiles tp ON tp.trainer_id = dp.trainer_id LEFT JOIN users u ON u.user_id = tp.user_id WHERE dp.member_user_id = ? AND dp.status = "active" ORDER BY dp.plan_id DESC LIMIT 1');
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
    $trainerName = $activePlan['trainer_id'] ? h($activePlan['t_first'] . ' ' . $activePlan['t_last']) : 'Auto-generated';
    
    $mealsRaw = $pdo->query('SELECT * FROM dietary_plan_meals WHERE plan_id = ' . $planId . ' ORDER BY day_of_week ASC, FIELD(meal_type, "Breakfast", "Lunch", "Dinner", "Snack")')->fetchAll();
    
    $mealsByDay = [];
    for ($i = 1; $i <= 7; $i++) {
        $mealsByDay[$i] = [];
    }
    foreach ($mealsRaw as $m) {
        $mealsByDay[(int)$m['day_of_week']][] = $m;
    }
    
    $daysMap = [1=>'Monday', 2=>'Tuesday', 3=>'Wednesday', 4=>'Thursday', 5=>'Friday', 6=>'Saturday', 7=>'Sunday'];
?>
<div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="margin: 0 0 5px 0;">My Diet Plan</h1>
        <p class="muted" style="margin: 0;">Goal: <?= h(ucwords(str_replace('_', ' ', $activePlan['goal']))) ?> | Assigned by: <?= $trainerName ?></p>
    </div>
    
    <!-- Generate a new plan overriding the current one -->
    <form id="regenerate-plan-form" method="post" style="margin: 0;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="generate_plan">
        <button type="button" onclick="confirmRegeneratePlan()" class="btn" style="background: var(--surface); color: var(--ink); border: 1px solid var(--line); border-radius: 8px; padding: 8px 16px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.92-10.26l5.67-5.67"/></svg>
            Regenerate Plan
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
        confirmButtonText: 'Yes, Regenerate Plan',
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
$targetCals = (int)$targetMacros['cals'];

// Fetch logged macros for today
$loggedMacros = $pdo->query("SELECT * FROM daily_macros WHERE user_id = {$userId} AND log_date = CURDATE()")->fetch();
$loggedCals = $loggedMacros ? (int)$loggedMacros['calories'] : 0;
$loggedPro = $loggedMacros ? (int)$loggedMacros['protein_g'] : 0;
$loggedCarbs = $loggedMacros ? (int)$loggedMacros['carbs_g'] : 0;
$loggedFat = $loggedMacros ? (int)$loggedMacros['fat_g'] : 0;

$pctCals = $targetCals > 0 ? min(100, round(($loggedCals / $targetCals) * 100)) : 0;
?>

<div class="skeleton-content animate-fade-in" style="background: linear-gradient(135deg, rgba(199,255,34,0.1) 0%, rgba(66,219,165,0.05) 100%); border: 1px solid rgba(199,255,34,0.3); border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); backdrop-filter: blur(16px);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 16px;">
        <div>
            <h2 style="margin: 0; font-size: 20px; color: var(--ink);">Today's Macro Tracker</h2>
            <p style="margin: 4px 0 0; color: var(--muted); font-size: 13px;">Log your food intake to stay on target.</p>
        </div>
        <div style="text-align: right;">
            <span id="macro-logged-cals" style="font-size: 24px; font-weight: 800; color: var(--lime);"><?= $loggedCals ?></span>
            <span style="color: var(--muted);">/ <span id="macro-target-cals"><?= $targetCals ?></span> kcal</span>
        </div>
    </div>

    <!-- Progress Bar -->
    <div style="height: 8px; background: rgba(255,255,255,0.1); border-radius: 4px; overflow: hidden; margin-bottom: 24px;">
        <div id="macro-progress-bar" style="height: 100%; width: <?= $pctCals ?>%; background: var(--lime); transition: width 0.7s ease; <?= $pctCals >= 100 ? 'background: #22c55e;' : '' ?>"></div>
    </div>

    <form id="macro-log-form" action="index.php?page=log_macros" method="post" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 12px; align-items: end; margin: 0;">
        <?= csrf_field() ?>
        <div>
            <label style="display:block; font-size:11px; color:var(--muted); margin-bottom:4px; text-transform:uppercase;">Calories (kcal)</label>
            <input id="macro-input-cals" type="number" name="calories" value="<?= $loggedCals ?: '' ?>" required style="width:100%; background:rgba(0,0,0,0.2); border:1px solid var(--line); color:var(--ink); padding:10px; border-radius:8px;">
        </div>
        <div>
            <label style="display:block; font-size:11px; color:var(--muted); margin-bottom:4px; text-transform:uppercase;">Protein (g)</label>
            <input id="macro-input-pro" type="number" name="protein_g" value="<?= $loggedPro ?: '' ?>" style="width:100%; background:rgba(0,0,0,0.2); border:1px solid var(--line); color:var(--ink); padding:10px; border-radius:8px;">
        </div>
        <div>
            <label style="display:block; font-size:11px; color:var(--muted); margin-bottom:4px; text-transform:uppercase;">Carbs (g)</label>
            <input id="macro-input-carbs" type="number" name="carbs_g" value="<?= $loggedCarbs ?: '' ?>" style="width:100%; background:rgba(0,0,0,0.2); border:1px solid var(--line); color:var(--ink); padding:10px; border-radius:8px;">
        </div>
        <div>
            <label style="display:block; font-size:11px; color:var(--muted); margin-bottom:4px; text-transform:uppercase;">Fat (g)</label>
            <input id="macro-input-fat" type="number" name="fat_g" value="<?= $loggedFat ?: '' ?>" style="width:100%; background:rgba(0,0,0,0.2); border:1px solid var(--line); color:var(--ink); padding:10px; border-radius:8px;">
        </div>
        <div>
            <button id="macro-save-btn" type="submit" style="width:100%; background:var(--lime); color:var(--bg); border:none; padding:11px; border-radius:8px; font-weight:bold; cursor:pointer; transition: opacity 0.2s;">Save Log</button>
        </div>
    </form>

<script>
(function() {
    const form     = document.getElementById('macro-log-form');
    const saveBtn  = document.getElementById('macro-save-btn');
    const bar      = document.getElementById('macro-progress-bar');
    const calsDisp = document.getElementById('macro-logged-cals');
    const tarDisp  = document.getElementById('macro-target-cals');
    if (!form) return;
    const csrf = form.querySelector('[name="csrf_token"]').value;

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';
        saveBtn.style.opacity = '0.7';

        // Build POST body explicitly so CSRF token is always included
        const body = new URLSearchParams({
            calories:   document.getElementById('macro-input-cals')?.value  || '0',
            protein_g:  document.getElementById('macro-input-pro')?.value   || '0',
            carbs_g:    document.getElementById('macro-input-carbs')?.value || '0',
            fat_g:      document.getElementById('macro-input-fat')?.value   || '0',
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
            data = await res.json();
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Could not reach the server. Please try again.', background: 'var(--bg)', color: 'var(--ink)' });
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Log';
            saveBtn.style.opacity = '1';
            return;
        }

        if (data && data.success) {
            // Animate calorie counter
            if (calsDisp) calsDisp.textContent = data.logged_cals;
            if (tarDisp && data.target_cals) tarDisp.textContent = data.target_cals;

            // Animate progress bar
            if (bar) {
                bar.style.width = data.pct_cals + '%';
                bar.style.background = data.pct_cals >= 100 ? '#22c55e' : 'var(--lime)';
            }

            // SweetAlert toast
            const Toast = Swal.mixin({
                toast: true, position: 'top-end', showConfirmButton: false,
                timer: 3000, timerProgressBar: true,
                background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
            });
            Toast.fire({ icon: 'success', title: 'Macros saved for today! 🍏' });
        } else {
            const msg = (data && data.error) ? data.error : 'Could not save macros. Please try again.';
            Swal.fire({ icon: 'error', title: 'Error', text: msg, background: 'var(--bg)', color: 'var(--ink)' });
        }

        saveBtn.disabled = false;
        saveBtn.textContent = 'Save Log';
        saveBtn.style.opacity = '1';
    });
})();
</script>
</div>

<div style="background: var(--surface); border-radius: 12px; border: 1px solid var(--line); overflow: hidden;">
    <!-- Tabs Header -->
    <div style="display: flex; overflow-x: auto; background: color-mix(in srgb, var(--surface) 90%, var(--ink)); border-bottom: 1px solid var(--line); scrollbar-width: none;">
        <?php foreach ($daysMap as $dayNum => $dayName): ?>
            <button class="diet-tab-btn <?= $dayNum === 1 ? 'active' : '' ?>" onclick="switchDietTab(<?= $dayNum ?>)" 
                    style="flex: 1; padding: 16px 20px; background: transparent; border: none; color: <?= $dayNum === 1 ? 'var(--lime)' : 'var(--muted)' ?>; font-weight: <?= $dayNum === 1 ? '600' : '400' ?>; cursor: pointer; border-bottom: 2px solid <?= $dayNum === 1 ? 'var(--lime)' : 'transparent' ?>; transition: all 0.2s ease; min-width: 100px;">
                <?= $dayName ?>
            </button>
        <?php endforeach; ?>
    </div>
    
    <!-- Tab Contents -->
    <div style="padding: 24px;">
        <?php foreach ($daysMap as $dayNum => $dayName): ?>
            <div id="diet-tab-<?= $dayNum ?>" class="diet-tab-content" style="display: <?= $dayNum === 1 ? 'block' : 'none' ?>; animation: fadeIn 0.3s ease;">
                <h3 style="margin-top: 0; color: var(--lime); font-size: 1.3rem; margin-bottom: 20px;">
                    <?= $dayName ?>'s Plan
                </h3>
                
                <?php if (empty($mealsByDay[$dayNum])): ?>
                    <div style="padding: 40px; text-align: center; color: var(--muted); background: var(--bg); border-radius: 8px; border: 1px dashed var(--line);">
                        <p style="margin:0; font-style: italic;">No meals specified for this day.</p>
                    </div>
                <?php else: ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr)); gap: 20px;">
                        <?php 
                        $dayCals = 0; $dayPro = 0; $dayCarbs = 0; $dayFat = 0;
                        foreach ($mealsByDay[$dayNum] as $meal): 
                            $dayCals += $meal['calories'];
                            $dayPro += $meal['protein_g'];
                            $dayCarbs += $meal['carbs_g'];
                            $dayFat += $meal['fat_g'];
                        ?>
                            <div style="background: var(--bg); padding: 20px; border-radius: 12px; border: 1px solid var(--line); position: relative; overflow: hidden;">
                                <!-- Left accent line -->
                                <div style="position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--lime);"></div>
                                
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                                    <strong style="color: var(--ink); font-size: 1.15rem;"><?= h($meal['meal_type']) ?></strong>
                                    <span style="background: color-mix(in srgb, var(--lime) 20%, transparent); color: var(--lime); padding: 4px 10px; border-radius: 20px; font-size: 0.85rem; font-weight: bold; border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);">
                                        <?= $meal['calories'] ?> kcal
                                    </span>
                                </div>
                                
                                <div style="color: var(--muted); font-size: 1rem; margin-bottom: 16px; line-height: 1.5; min-height: 48px;">
                                    <?= nl2br(h($meal['food_items'])) ?>
                                </div>
                                
                                <div style="display: flex; gap: 10px; font-size: 0.85rem; color: var(--ink); background: color-mix(in srgb, var(--surface) 50%, var(--bg)); padding: 10px; border-radius: 8px; justify-content: space-between;">
                                    <div style="text-align: center; flex: 1;"><strong style="display:block; color:var(--muted); font-size:10px; text-transform:uppercase;">Protein</strong> <?= $meal['protein_g'] ?>g</div>
                                    <div style="width:1px; background:var(--line);"></div>
                                    <div style="text-align: center; flex: 1;"><strong style="display:block; color:var(--muted); font-size:10px; text-transform:uppercase;">Carbs</strong> <?= $meal['carbs_g'] ?>g</div>
                                    <div style="width:1px; background:var(--line);"></div>
                                    <div style="text-align: center; flex: 1;"><strong style="display:block; color:var(--muted); font-size:10px; text-transform:uppercase;">Fat</strong> <?= $meal['fat_g'] ?>g</div>
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
                                <div style="font-size: 1.4rem; font-weight: bold; color: var(--lime);"><?= $dayCals ?> kcal</div>
                            </div>
                        </div>
                        <div style="display: flex; gap: 24px; font-size: 1rem;">
                            <div style="display: flex; flex-direction: column; align-items: flex-end;">
                                <span style="font-size: 0.8rem; color: var(--muted); text-transform: uppercase;">Protein</span>
                                <strong><?= $dayPro ?>g</strong>
                            </div>
                            <div style="display: flex; flex-direction: column; align-items: flex-end;">
                                <span style="font-size: 0.8rem; color: var(--muted); text-transform: uppercase;">Carbs</span>
                                <strong><?= $dayCarbs ?>g</strong>
                            </div>
                            <div style="display: flex; flex-direction: column; align-items: flex-end;">
                                <span style="font-size: 0.8rem; color: var(--muted); text-transform: uppercase;">Fat</span>
                                <strong><?= $dayFat ?>g</strong>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
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
</style>

<script>
function switchDietTab(dayNum) {
    // Hide all contents
    document.querySelectorAll('.diet-tab-content').forEach(el => el.style.display = 'none');
    // Reset all buttons
    document.querySelectorAll('.diet-tab-btn').forEach(btn => {
        btn.style.color = 'var(--muted)';
        btn.style.fontWeight = '400';
        btn.style.borderBottomColor = 'transparent';
    });
    
    // Show selected content
    document.getElementById('diet-tab-' + dayNum).style.display = 'block';
    
    // Highlight selected button
    const activeBtn = document.querySelector('.diet-tab-btn:nth-child(' + dayNum + ')');
    if(activeBtn) {
        activeBtn.style.color = 'var(--lime)';
        activeBtn.style.fontWeight = '600';
        activeBtn.style.borderBottomColor = 'var(--lime)';
    }
}
</script>

<?php
    render_footer();
}
