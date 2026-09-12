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
            
            // 5. Generate Meals for 7 days from food_items table
            $pdo->prepare('DELETE FROM dietary_plan_meals WHERE plan_id = ?')->execute([$planId]);
            
            // Check if dietary_restrictions column exists and get the value (fallback to none)
            try {
                $checkProfile = $pdo->query("SELECT dietary_restrictions FROM member_profiles WHERE user_id = {$memberId}")->fetch();
                $restriction = $checkProfile['dietary_restrictions'] ?? 'none';
            } catch (Exception $e) {
                $restriction = 'none';
            }

            // Query active foods matching member dietary restriction and gym scope
            $targetGymId = $gymId ?: get_user_gym_id($user);
            $foodQuery = "
                SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc 
                FROM food_items 
                WHERE is_active = 1 
                  AND (gym_id = ? OR gym_id IS NULL)
                  AND (dietary_restriction = ? OR dietary_restriction = 'none')
                ORDER BY (gym_id IS NOT NULL) DESC, RAND()
            ";
            $foodStmt = $pdo->prepare($foodQuery);
            $foodStmt->execute([$targetGymId, $restriction]);
            $dbFoods = $foodStmt->fetchAll();

            $foodsByType = ['Breakfast' => [], 'Lunch' => [], 'Dinner' => [], 'Snack' => []];
            foreach ($dbFoods as $f) {
                $foodsByType[$f['meal_type']][] = $f;
            }

            // Fallback for any meal type that has no matches
            foreach (['Breakfast', 'Lunch', 'Dinner', 'Snack'] as $mt) {
                if (empty($foodsByType[$mt])) {
                    $fallbackStmt = $pdo->prepare("SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc FROM food_items WHERE is_active = 1 AND meal_type = ? ORDER BY RAND()");
                    $fallbackStmt->execute([$mt]);
                    $foodsByType[$mt] = $fallbackStmt->fetchAll();
                }
            }
            
            $dist = ['Breakfast'=>0.25, 'Lunch'=>0.35, 'Dinner'=>0.30, 'Snack'=>0.10];
            $stmt = $pdo->prepare('INSERT INTO dietary_plan_meals (plan_id, day_of_week, meal_type, food_items, image_url, calories, protein_g, carbs_g, fat_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            
            for ($d = 1; $d <= 7; $d++) {
                foreach ($dist as $mType => $pct) {
                    $mCals = round($targetCals * $pct);
                    $mP = round($p_g * $pct);
                    $mC = round($c_g * $pct);
                    $mF = round($f_g * $pct);
                    $portionGrams = round($mCals / 1.5);
                    
                    $options = $foodsByType[$mType];
                    $selectedFood = !empty($options) ? $options[($d - 1) % count($options)] : null;
                    
                    if ($selectedFood) {
                        $mFood = $portionGrams . "g of " . $selectedFood['name'];
                        $mImg = $selectedFood['image_url'] ?? null;
                    } else {
                        $mFood = $portionGrams . "g of Healthy " . $mType;
                        $mImg = null;
                    }
                    
                    $stmt->execute([$planId, $d, $mType, $mFood, $mImg, $mCals, $mP, $mC, $mF]);
                }
            }
            
            flash("Dietary plan generated automatically from Food Library! Target: {$targetCals}kcal | P: {$p_g}g | C: {$c_g}g | F: {$f_g}g", 'success');
            header('Location: index.php?page=diet_builder&member_user_id=' . $memberId . $refQuery);
            exit;
        }

        if ($action === 'add_meal') {
            $dayOfWeek = (int) post('day_of_week');
            $mealType = post('meal_type');
            $foodItems = post('food_items');
            $calories = (int) post('calories');
            $protein = (float) post('protein_g');
            $carbs = (float) post('carbs_g');
            $fat = (float) post('fat_g');
            $imageUrl = trim((string) post('image_url')) ?: null;
            
            $stmt = $pdo->prepare('INSERT INTO dietary_plan_meals (plan_id, day_of_week, meal_type, food_items, image_url, calories, protein_g, carbs_g, fat_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$planId, $dayOfWeek, $mealType, $foodItems, $imageUrl, $calories, $protein, $carbs, $fat]);
            
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

            <?php 
                $memberRestriction = $profile['dietary_restrictions'] ?? 'none';
                $restColor = ($memberRestriction !== 'none') ? '#34d399' : 'var(--muted)';
                $restBg = ($memberRestriction !== 'none') ? 'rgba(52, 211, 153, 0.15)' : 'rgba(255,255,255,0.06)';
                $restBorder = ($memberRestriction !== 'none') ? 'rgba(52, 211, 153, 0.35)' : 'var(--line)';
            ?>
            <span class="badge" style="background: <?= $restBg ?>; color: <?= $restColor ?>; border: 1px solid <?= $restBorder ?>; font-size: 11.5px; padding: 3px 10px; font-weight: 700; border-radius: 6px; display: inline-flex; align-items: center; gap: 5px;" title="Member's Dietary Restriction">
                <span>🥗</span>
                <span>Diet: <strong><?= h(ucwords(str_replace('-', ' ', $memberRestriction))) ?></strong></span>
            </span>

            <?php if (!empty($profile['primary_goal'])): ?>
                <span class="badge" style="background: rgba(163, 230, 53, 0.12); color: var(--lime); border: 1px solid rgba(163, 230, 53, 0.25); font-size: 11.5px; padding: 3px 10px; font-weight: 700; border-radius: 6px;">
                    🎯 <?= h(ucwords(str_replace('_', ' ', $profile['primary_goal']))) ?>
                </span>
            <?php endif; ?>
        </div>
        <p style="color: var(--muted); font-size: 13px; margin: 4px 0 0;">Manage daily meals, nutrition, and macro targets tailored to this member's goals and dietary needs.</p>
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
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label style="font-size: 12px; font-weight: 600; color: var(--muted); margin: 0;">Search Food Library</label>
                        <button type="button" id="onlineSearchToggle" onclick="toggleOnlineSearch()" style="background: none; border: none; padding: 0; font-size: 11px; color: var(--lime); cursor: pointer; display: flex; align-items: center; gap: 4px; font-weight: 600;">
                            <span>🌐 Search Online (OFF)</span>
                        </button>
                    </div>
                    <?php if ($memberRestriction !== 'none'): ?>
                        <div style="font-size: 11px; color: #34d399; margin-bottom: 6px; display: flex; align-items: center; gap: 4px; font-weight: 500;">
                            <span>🥗 Prioritizing: <strong><?= h(ucwords(str_replace('-', ' ', $memberRestriction))) ?></strong></span>
                        </div>
                    <?php endif; ?>
                    <div style="position: relative;">
                        <input type="text" id="food_search_input" placeholder="Search dishes (e.g. Chicken, Salmon...)" autocomplete="off" style="width: 100%; box-sizing: border-box; padding: 9px 30px 9px 12px; border-radius: 8px; border: 1px solid var(--line); background: var(--bg); color: var(--ink); font-size: 13px;">
                        <span id="food_search_loader" style="display: none; position: absolute; right: 10px; top: 10px; font-size: 12px;">⏳</span>
                        <div id="food_autocomplete_box" style="display: none; position: absolute; left: 0; right: 0; top: 100%; margin-top: 4px; background: #161f30; border: 1px solid #334155; border-radius: 8px; max-height: 250px; overflow-y: auto; z-index: 999; box-shadow: 0 10px 25px rgba(0,0,0,0.7);"></div>
                    </div>
                    <div id="autofill_indicator" style="display: none; font-size: 11px; color: var(--lime); margin-top: 4px; font-weight: 600;">
                        ✓ Details & macros auto-filled from library!
                    </div>
                </div>

                <div>
                    <label style="display:block; font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 6px;">Meal Description / Portion</label>
                    <textarea name="food_items" id="meal_food_items" required rows="2" placeholder="e.g. 200g Grilled Chicken Breast with Brown Rice" style="width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--line); background: var(--bg); color: var(--ink); font-size: 13px; resize: vertical; line-height: 1.4;"></textarea>
                    <input type="hidden" name="image_url" id="meal_image_url" value="">
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
    const p = parseFloat(document.getElementById('calc_p').value) || 0;
    const c = parseFloat(document.getElementById('calc_c').value) || 0;
    const f = parseFloat(document.getElementById('calc_f').value) || 0;
    const calsInput = document.getElementById('calc_cals');
    if (calsInput) {
        calsInput.value = Math.round((p * 4) + (c * 4) + (f * 9));
    }
}

let isOnlineSearch = false;
let searchDebounce = null;

function toggleOnlineSearch() {
    isOnlineSearch = !isOnlineSearch;
    const btn = document.getElementById('onlineSearchToggle');
    const input = document.getElementById('food_search_input');
    if (btn) {
        btn.innerHTML = isOnlineSearch ? '<span style="color:#38bdf8;">🌐 Search Online (ON)</span>' : '<span>🌐 Search Online (OFF)</span>';
    }
    if (input) {
        input.placeholder = isOnlineSearch ? 'Search Open Food Facts (e.g. Greek Yogurt, Quest Bar...)' : 'Search dishes (e.g. Chicken, Salmon, Oats...)';
        if (input.value.trim().length >= 2) {
            triggerFoodSearch(input.value.trim());
        }
    }
}

function triggerFoodSearch(query) {
    const box = document.getElementById('food_autocomplete_box');
    const loader = document.getElementById('food_search_loader');
    if (!box) return;

    if (query.length < 2) {
        box.style.display = 'none';
        return;
    }

    if (loader) loader.style.display = 'block';

    const url = isOnlineSearch
        ? 'index.php?page=food_lookup&action=openfoodfacts&query=' + encodeURIComponent(query)
        : 'index.php?page=food_lookup&action=search_library&dietary_restriction=<?= urlencode($memberRestriction) ?>&query=' + encodeURIComponent(query);

    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (loader) loader.style.display = 'none';
            if (!data.success) {
                box.innerHTML = `<div style="padding: 12px; color: var(--muted); font-size: 12.5px; text-align: center;">${data.error || 'No items found'}</div>`;
                box.style.display = 'block';
                return;
            }

            const items = isOnlineSearch ? (data.products || []) : (data.items || []);
            if (items.length === 0) {
                box.innerHTML = '<div style="padding: 12px; color: var(--muted); font-size: 12.5px; text-align: center;">No matching foods found</div>';
                box.style.display = 'block';
                return;
            }

            box.innerHTML = items.map((item, idx) => {
                const name = item.name;
                const cals = isOnlineSearch ? (item.calories_100g || 0) : item.calories;
                const pro = isOnlineSearch ? (item.protein_100g || 0) : item.protein_g;
                const carbs = isOnlineSearch ? (item.carbs_100g || 0) : item.carbs_g;
                const fat = isOnlineSearch ? (item.fat_100g || 0) : item.fat_g;
                const tag = isOnlineSearch ? 'Online (OFF)' : (item.is_gym_custom ? 'Gym Custom' : 'Library');
                const tagBg = isOnlineSearch ? 'rgba(56, 189, 248, 0.15)' : (item.is_gym_custom ? 'rgba(168, 85, 247, 0.15)' : 'rgba(132, 204, 22, 0.15)');
                const tagColor = isOnlineSearch ? '#38bdf8' : (item.is_gym_custom ? '#c084fc' : '#a3e635');
                const img = item.image || item.image_url || '';

                return `
                    <div class="food-suggest-item" data-idx="${idx}" style="padding: 9px 12px; border-bottom: 1px solid rgba(255,255,255,0.05); cursor: pointer; transition: background 0.15s; display: flex; align-items: center; gap: 10px;">
                        ${img ? `<img src="${img}" style="width: 34px; height: 34px; border-radius: 6px; object-fit: cover; flex-shrink: 0; background: #000;" onerror="this.style.display='none'">` : ''}
                        <div style="flex: 1; min-width: 0;">
                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 6px;">
                                <span style="color: #ffffff; font-size: 13px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${name}</span>
                                <span style="font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 4px; background: ${tagBg}; color: ${tagColor}; white-space: nowrap;">${tag}</span>
                            </div>
                            <div style="display: flex; gap: 8px; font-size: 11px; color: var(--muted); margin-top: 3px;">
                                <span style="color: var(--lime); font-weight: 700;">${cals} kcal</span>
                                <span>P: ${pro}g</span>
                                <span>C: ${carbs}g</span>
                                <span>F: ${fat}g</span>
                                ${isOnlineSearch ? '<span style="opacity: 0.7;">(per 100g)</span>' : ''}
                            </div>
                        </div>
                    </div>
                `;
            }).join('');

            box.style.display = 'block';

            // Click listener
            box.querySelectorAll('.food-suggest-item').forEach((row, i) => {
                row.addEventListener('mouseenter', () => row.style.background = '#253349');
                row.addEventListener('mouseleave', () => row.style.background = 'transparent');
                row.addEventListener('click', () => {
                    const chosen = items[i];
                    selectFoodItem(chosen);
                });
            });
        })
        .catch(() => {
            if (loader) loader.style.display = 'none';
        });
}

function selectFoodItem(item) {
    const foodItemsInput = document.getElementById('meal_food_items');
    const pInput = document.getElementById('calc_p');
    const cInput = document.getElementById('calc_c');
    const fInput = document.getElementById('calc_f');
    const calsInput = document.getElementById('calc_cals');
    const imgInput = document.getElementById('meal_image_url');
    const typeSelect = document.querySelector('select[name="meal_type"]');
    const searchInput = document.getElementById('food_search_input');
    const box = document.getElementById('food_autocomplete_box');
    const indicator = document.getElementById('autofill_indicator');

    const name = item.name;
    const cals = isOnlineSearch ? (item.calories_100g || 0) : item.calories;
    const pro = isOnlineSearch ? (item.protein_100g || 0) : item.protein_g;
    const carbs = isOnlineSearch ? (item.carbs_100g || 0) : item.carbs_g;
    const fat = isOnlineSearch ? (item.fat_100g || 0) : item.fat_g;
    const img = item.image || item.image_url || '';

    if (foodItemsInput) {
        foodItemsInput.value = item.serving_size ? `${item.serving_size} of ${name}` : name;
    }
    if (pInput) pInput.value = pro;
    if (cInput) cInput.value = carbs;
    if (fInput) fInput.value = fat;
    if (calsInput) calsInput.value = cals;
    if (imgInput) imgInput.value = img;

    if (typeSelect && item.meal_type) {
        typeSelect.value = item.meal_type;
    }

    if (searchInput) searchInput.value = name;
    if (box) box.style.display = 'none';

    if (indicator) {
        indicator.style.display = 'block';
        setTimeout(() => { indicator.style.display = 'none'; }, 3500);
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

    const searchInput = document.getElementById('food_search_input');
    const box = document.getElementById('food_autocomplete_box');

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchDebounce);
            const val = this.value.trim();
            searchDebounce = setTimeout(() => {
                triggerFoodSearch(val);
            }, 250);
        });

        searchInput.addEventListener('focus', function() {
            if (this.value.trim().length >= 2) {
                triggerFoodSearch(this.value.trim());
            }
        });
    }

    document.addEventListener('click', function(e) {
        if (box && searchInput && !box.contains(e.target) && !searchInput.contains(e.target)) {
            box.style.display = 'none';
        }
    });
});
</script>
<?php
    render_footer();
}
