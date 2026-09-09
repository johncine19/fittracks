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

        if (data && data.success) {
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

            // In 'add' or 'reset' mode, clear inputs ready for the next food entry
            if (activeMode === 'add' || activeMode === 'reset') {
                inCals.value = '';
                inPro.value = '';
                inCarbs.value = '';
                inFat.value = '';
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
            } else {
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
        <div style="display: flex; overflow-x: auto; background: color-mix(in srgb, var(--surface) 90%, var(--ink)); border-bottom: 1px solid var(--line); scrollbar-width: none;">
            <?php foreach ($daysMap as $dayNum => $dayName): ?>
                <button class="diet-tab-btn <?= $dayNum === $todayNum ? 'active' : '' ?>" onclick="switchDietTab(<?= $dayNum ?>)" 
                        style="flex: 1; padding: 16px 18px; background: transparent; border: none; color: <?= $dayNum === $todayNum ? 'var(--lime)' : 'var(--muted)' ?>; font-weight: <?= $dayNum === $todayNum ? '700' : '500' ?>; cursor: pointer; border-bottom: 2px solid <?= $dayNum === $todayNum ? 'var(--lime)' : 'transparent' ?>; transition: all 0.2s ease; min-width: 100px; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                    <span><?= $dayName ?></span>
                    <?php if ($dayNum === $todayNum): ?>
                        <span style="font-size: 10px; background: color-mix(in srgb, var(--lime) 20%, transparent); color: var(--lime); padding: 1px 7px; border-radius: 10px; border: 1px solid color-mix(in srgb, var(--lime) 35%, transparent); font-weight: 800; text-transform: uppercase;">Today</span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>
        
        <!-- Tab Contents -->
        <div style="padding: 24px;">
            <?php foreach ($daysMap as $dayNum => $dayName): ?>
                <div id="diet-tab-<?= $dayNum ?>" class="diet-tab-content" style="display: <?= $dayNum === $todayNum ? 'block' : 'none' ?>; animation: fadeIn 0.3s ease;">
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
</div> <!-- Close #view-meal-plan -->

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
        btn.style.fontWeight = '500';
        btn.style.borderBottomColor = 'transparent';
    });
    
    // Show selected content
    const targetContent = document.getElementById('diet-tab-' + dayNum);
    if (targetContent) targetContent.style.display = 'block';
    
    // Highlight selected button
    const activeBtn = document.querySelector('.diet-tab-btn:nth-child(' + dayNum + ')');
    if (activeBtn) {
        activeBtn.style.color = 'var(--lime)';
        activeBtn.style.fontWeight = '700';
        activeBtn.style.borderBottomColor = 'var(--lime)';
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
