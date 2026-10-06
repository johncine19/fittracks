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
    $profile = $pdo->query('SELECT * FROM member_profiles WHERE user_id = ' . $memberId)->fetch() ?: [];
    $memberRestriction = trim((string)($profile['dietary_restrictions'] ?? 'none'));
    if ($memberRestriction === '') {
        $memberRestriction = 'none';
    }

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
            if ($goal === 'casual') $targetCals = $tdee;
            $targetCals = max(1200, round($targetCals));
            
            // 4. Macro Split (use prepared statements to prevent SQL injection)
            $stmtRule = $pdo->prepare('SELECT macro_split FROM diet_rules WHERE primary_goal = ? AND experience_level = ?');
            $stmtRule->execute([$goal, $expLevel]);
            $rule = $stmtRule->fetch();
            if (!$rule) {
                $stmtRule = $pdo->prepare('SELECT macro_split FROM diet_rules WHERE primary_goal = ?');
                $stmtRule->execute(['general_health']);
                $rule = $stmtRule->fetch();
            }
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
            $compatibleDiets = function_exists('get_compatible_dietary_restrictions')
                ? get_compatible_dietary_restrictions($restriction)
                : ($restriction !== 'none' ? [$restriction] : []);

            if (!empty($compatibleDiets)) {
                $inClause = implode(',', array_fill(0, count($compatibleDiets), '?'));
                $foodQuery = "
                    SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc 
                    FROM food_items 
                    WHERE is_active = 1 
                      AND (gym_id = ? OR gym_id IS NULL OR gym_id = 0)
                      AND dietary_restriction IN ({$inClause})
                    ORDER BY (gym_id IS NOT NULL AND gym_id > 0) DESC, RAND()
                ";
                $foodParams = array_merge([$targetGymId], $compatibleDiets);
            } else {
                $foodQuery = "
                    SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc 
                    FROM food_items 
                    WHERE is_active = 1 
                      AND (gym_id = ? OR gym_id IS NULL OR gym_id = 0)
                    ORDER BY (gym_id IS NOT NULL AND gym_id > 0) DESC, (dietary_restriction = 'none') DESC, RAND()
                ";
                $foodParams = [$targetGymId];
            }

            $foodStmt = $pdo->prepare($foodQuery);
            $foodStmt->execute($foodParams);
            $dbFoods = $foodStmt->fetchAll(PDO::FETCH_ASSOC);

            $foodsByType = ['Breakfast' => [], 'Lunch' => [], 'Dinner' => [], 'Snack' => []];
            foreach ($dbFoods as $f) {
                $mt = ucfirst(strtolower((string)$f['meal_type']));
                if (isset($foodsByType[$mt])) {
                    $foodsByType[$mt][] = $f;
                }
            }

            // Fallback for any meal type that has no matches
            foreach (['Breakfast', 'Lunch', 'Dinner', 'Snack'] as $mt) {
                if (empty($foodsByType[$mt])) {
                    if (!empty($compatibleDiets)) {
                        $inClause = implode(',', array_fill(0, count($compatibleDiets), '?'));
                        $fallbackStmt = $pdo->prepare("
                            SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc 
                            FROM food_items 
                            WHERE is_active = 1 
                              AND meal_type = ? 
                              AND dietary_restriction IN ({$inClause}) 
                            ORDER BY RAND()
                        ");
                        $fallbackStmt->execute(array_merge([$mt], $compatibleDiets));
                        $foodsByType[$mt] = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                    if (empty($foodsByType[$mt])) {
                        if (!in_array($restriction, ['vegetarian', 'vegan', 'halal'])) {
                            $fallbackStmt = $pdo->prepare("SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc FROM food_items WHERE is_active = 1 AND meal_type = ? ORDER BY RAND()");
                            $fallbackStmt->execute([$mt]);
                            $foodsByType[$mt] = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
                        } else {
                            $fallbackStmt = $pdo->prepare("SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc FROM food_items WHERE is_active = 1 AND dietary_restriction IN ('vegetarian', 'vegan') ORDER BY RAND()");
                            $fallbackStmt->execute();
                            $foodsByType[$mt] = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
                        }
                    }
                }
            }
            
            $dist = ['Breakfast'=>0.25, 'Lunch'=>0.35, 'Dinner'=>0.30, 'Snack'=>0.10];
            $stmt = $pdo->prepare('INSERT INTO dietary_plan_meals (plan_id, day_of_week, meal_type, food_items, image_url, calories, protein_g, carbs_g, fat_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            
            for ($d = 1; $d <= 7; $d++) {
                foreach ($dist as $mType => $pct) {
                    $options = $foodsByType[$mType];
                    $selectedFood = !empty($options) ? $options[($d - 1) % count($options)] : null;
                    
                    if ($selectedFood) {
                        $mFood = $selectedFood['name'];
                        $mImg = $selectedFood['image_url'] ?? null;
                        $mCals = (int) $selectedFood['calories'];
                        $mP = (float) $selectedFood['protein_g'];
                        $mC = (float) $selectedFood['carbs_g'];
                        $mF = (float) $selectedFood['fat_g'];
                    } else {
                        $mCals = round($targetCals * $pct);
                        $mP = round($p_g * $pct);
                        $mC = round($c_g * $pct);
                        $mF = round($f_g * $pct);
                        $mFood = "Healthy " . $mType;
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
            $daysOfWeek = post('days_of_week');
            if (!is_array($daysOfWeek) || empty($daysOfWeek)) {
                $singleDay = (int) post('day_of_week');
                $daysOfWeek = ($singleDay >= 1 && $singleDay <= 7) ? [$singleDay] : [1];
            }
            $mealType = post('meal_type');
            $foodItems = post('food_items');
            $calories = (int) post('calories');
            $protein = (float) post('protein_g');
            $carbs = (float) post('carbs_g');
            $fat = (float) post('fat_g');
            $imageUrl = trim((string) post('image_url')) ?: null;
            
            $stmt = $pdo->prepare('INSERT INTO dietary_plan_meals (plan_id, day_of_week, meal_type, food_items, image_url, calories, protein_g, carbs_g, fat_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            
            $daysMap = [1=>'Monday', 2=>'Tuesday', 3=>'Wednesday', 4=>'Thursday', 5=>'Friday', 6=>'Saturday', 7=>'Sunday'];
            $addedDaysCount = 0;
            $firstDay = 1;
            foreach ($daysOfWeek as $d) {
                $dInt = (int) $d;
                if ($dInt >= 1 && $dInt <= 7) {
                    if ($addedDaysCount === 0) $firstDay = $dInt;
                    $stmt->execute([$planId, $dInt, $mealType, $foodItems, $imageUrl, $calories, $protein, $carbs, $fat]);
                    $addedDaysCount++;
                }
            }
            
            if ($addedDaysCount > 1) {
                flash("Added {$mealType} to {$addedDaysCount} days!", 'success');
            } else {
                flash("Added {$mealType} to {$daysMap[$firstDay]}!", 'success');
            }
            
            $activeDayParam = '&active_day=' . (int)(post('active_day') ?: $firstDay);
            header('Location: index.php?page=diet_builder&member_user_id=' . $memberId . $refQuery . $activeDayParam);
            exit;
        }
        
        if ($action === 'copy_day_menu') {
            $fromDay = (int) post('from_day');
            $targetDays = post('target_days');
            $replaceExisting = (int) post('replace_existing', 1);
            $daysMap = [1=>'Monday', 2=>'Tuesday', 3=>'Wednesday', 4=>'Thursday', 5=>'Friday', 6=>'Saturday', 7=>'Sunday'];
            
            if ($fromDay >= 1 && $fromDay <= 7 && is_array($targetDays) && !empty($targetDays)) {
                $srcStmt = $pdo->prepare('SELECT meal_type, food_items, image_url, calories, protein_g, carbs_g, fat_g FROM dietary_plan_meals WHERE plan_id = ? AND day_of_week = ? ORDER BY FIELD(meal_type, "Breakfast", "Lunch", "Dinner", "Snack")');
                $srcStmt->execute([$planId, $fromDay]);
                $srcMeals = $srcStmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($srcMeals)) {
                    $insertStmt = $pdo->prepare('INSERT INTO dietary_plan_meals (plan_id, day_of_week, meal_type, food_items, image_url, calories, protein_g, carbs_g, fat_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $delStmt = $pdo->prepare('DELETE FROM dietary_plan_meals WHERE plan_id = ? AND day_of_week = ?');
                    
                    $validTargets = [];
                    foreach ($targetDays as $td) {
                        $tdInt = (int)$td;
                        if ($tdInt >= 1 && $tdInt <= 7 && $tdInt !== $fromDay) {
                            $validTargets[] = $tdInt;
                        }
                    }
                    
                    if (!empty($validTargets)) {
                        $pdo->beginTransaction();
                        foreach ($validTargets as $tDay) {
                            if ($replaceExisting) {
                                $delStmt->execute([$planId, $tDay]);
                            }
                            foreach ($srcMeals as $sm) {
                                $insertStmt->execute([
                                    $planId,
                                    $tDay,
                                    $sm['meal_type'],
                                    $sm['food_items'],
                                    $sm['image_url'],
                                    $sm['calories'],
                                    $sm['protein_g'],
                                    $sm['carbs_g'],
                                    $sm['fat_g']
                                ]);
                            }
                        }
                        $pdo->commit();
                        
                        $targetNames = array_map(function($d) use ($daysMap) { return $daysMap[$d] ?? "Day $d"; }, $validTargets);
                        flash("Successfully copied {$daysMap[$fromDay]}'s menu to " . implode(', ', $targetNames) . "!", 'success');
                    }
                } else {
                    flash("Cannot copy: {$daysMap[$fromDay]} has no meals scheduled.", 'warning');
                }
            }
            $activeDayParam = '&active_day=' . $fromDay;
            header('Location: index.php?page=diet_builder&member_user_id=' . $memberId . $refQuery . $activeDayParam);
            exit;
        }

        if ($action === 'clear_day_menu') {
            $clearDay = (int) post('clear_day');
            $daysMap = [1=>'Monday', 2=>'Tuesday', 3=>'Wednesday', 4=>'Thursday', 5=>'Friday', 6=>'Saturday', 7=>'Sunday'];
            if ($clearDay >= 1 && $clearDay <= 7) {
                $pdo->prepare('DELETE FROM dietary_plan_meals WHERE plan_id = ? AND day_of_week = ?')->execute([$planId, $clearDay]);
                flash("Cleared all meals for {$daysMap[$clearDay]}.", 'info');
            }
            $activeDayParam = '&active_day=' . $clearDay;
            header('Location: index.php?page=diet_builder&member_user_id=' . $memberId . $refQuery . $activeDayParam);
            exit;
        }

        if ($action === 'remove_meal') {
            $mealId = (int) post('meal_id');
            $currentDay = (int) post('current_day');
            $pdo->prepare('DELETE FROM dietary_plan_meals WHERE meal_id = ? AND plan_id = ?')->execute([$mealId, $planId]);
            $activeDayParam = ($currentDay >= 1 && $currentDay <= 7) ? '&active_day=' . $currentDay : '';
            header('Location: index.php?page=diet_builder&member_user_id=' . $memberId . $refQuery . $activeDayParam);
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

    // Fetch active local food library dishes for the dropdown
    $gymScopeId = (int) ($gymId ?? get_user_gym_id($user));
    if ($gymScopeId > 0) {
        $libraryFoodsStmt = $pdo->prepare("
            SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc, dietary_restriction, (gym_id IS NOT NULL AND gym_id > 0) as is_gym_custom
            FROM food_items 
            WHERE is_active = 1 AND (gym_id = ? OR gym_id IS NULL OR gym_id = 0)
            ORDER BY name ASC
        ");
        $libraryFoodsStmt->execute([$gymScopeId]);
    } else {
        $libraryFoodsStmt = $pdo->prepare("
            SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc, dietary_restriction, 0 as is_gym_custom
            FROM food_items 
            WHERE is_active = 1 AND (gym_id IS NULL OR gym_id = 0)
            ORDER BY name ASC
        ");
        $libraryFoodsStmt->execute();
    }
    $allLibraryFoods = $libraryFoodsStmt->fetchAll(PDO::FETCH_ASSOC);

    render_header('Build Diet Plan', $user);
    $daysMap = [1=>'Monday', 2=>'Tuesday', 3=>'Wednesday', 4=>'Thursday', 5=>'Friday', 6=>'Saturday', 7=>'Sunday'];
?>
<div class="diet-builder-page">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
        <div>
            <h2 style="margin: 0 0 6px 0; font-size: 22px; font-weight: 800; color: var(--ink);">
                Diet Plan for <?= h(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?>
            </h2>
            <div class="diet-header-meta">
                <span class="diet-meta-mode"><?= $user['role'] === 'gym_owner' ? 'GYM OWNER MODE' : ($user['role'] === 'platform_admin' ? 'ADMIN MODE' : 'ASSIGNED TRAINER') ?></span>
                <?php if ($memberRestriction !== 'none' && $memberRestriction !== ''): ?>
                    <span class="diet-meta-sep">·</span>
                    <span class="diet-meta-item">DIET: <strong style="color: var(--ink);"><?= h(strtoupper(str_replace('-', ' ', (string)$memberRestriction))) ?></strong></span>
                <?php endif; ?>
                <?php if (!empty($profile['primary_goal'])): ?>
                    <span class="diet-meta-sep">·</span>
                    <span class="diet-meta-item">GOAL: <strong style="color: var(--ink);"><?= h(ucwords(str_replace('_', ' ', (string)$profile['primary_goal']))) ?></strong></span>
                <?php endif; ?>
            </div>
            <p style="color: var(--diet-muted, var(--muted)); font-size: 13px; margin: 6px 0 0;">Manage daily meals, nutrition, and macro targets tailored to this member.</p>
        </div>
        <div class="diet-actions-wrap">
            <a href="<?= h($backUrl) ?>" class="diet-action-btn diet-btn-back" title="Go back">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                <span>Back</span>
            </a>
            <form method="post" style="margin:0;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="generate_plan">
                <button type="button" class="diet-action-btn diet-btn-generate" onclick="confirmGeneratePlan(this, event);" title="Auto-generate an optimized 7-day meal plan">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/></svg>
                    <span>Generate Plan</span>
                </button>
            </form>
            <form method="post" style="margin:0;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="publish">
                <button type="button" class="diet-action-btn diet-btn-publish" onclick="confirmPublishPlan(this, event);" title="Publish this diet plan to member">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <span>Publish Plan</span>
                </button>
            </form>
        </div>
    </div>

<link rel="stylesheet" href="<?= h(asset_url('css/pages/diet_builder.css')) ?>">

<div class="diet-builder-layout">
    <!-- Left Column: Add Meal Form -->
    <div style="width: 100%;">
        <section class="panel" style="position: sticky; top: 20px; padding: 20px; background: var(--surface); border: 1px solid var(--diet-border); border-radius: 12px;">
            <h3 style="margin: 0 0 16px; font-size: 16px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--diet-accent)" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                Add Meal
            </h3>
            <form method="post" style="display: flex; flex-direction: column; gap: 14px; margin: 0;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_meal">
                
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label style="font-size: 12px; font-weight: 700; color: var(--ink); margin: 0;">Apply to Day(s)</label>
                        <span id="target_days_count_badge" style="font-size: 11px; font-weight: 700; color: var(--diet-accent); background: var(--diet-accent-tint); padding: 2px 7px; border-radius: 10px; border: 1px solid var(--diet-accent-border);">1 day (Monday)</span>
                    </div>

                    <!-- Day chips selector -->
                    <div class="target-days-pills" id="target_days_pills" style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; margin-bottom: 7px;">
                        <?php 
                        $shortDays = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
                        foreach ($shortDays as $num => $sName): 
                        ?>
                            <button type="button" class="day-chip <?= $num === 1 ? 'is-active' : '' ?>" data-day="<?= $num ?>" onclick="toggleTargetDay(<?= $num ?>)" title="Apply to <?= $daysMap[$num] ?>">
                                <?= $sName ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <!-- Quick Preset Buttons -->
                    <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                        <button type="button" class="btn-day-preset" onclick="setTargetDaysPreset('active')">Current Day</button>
                        <button type="button" class="btn-day-preset" onclick="setTargetDaysPreset('weekdays')">Weekdays (M-F)</button>
                        <button type="button" class="btn-day-preset" onclick="setTargetDaysPreset('all')">All 7 Days</button>
                        <button type="button" class="btn-day-preset" onclick="setTargetDaysPreset('weekend')">Weekend</button>
                    </div>

                    <!-- Hidden inputs container for selected days -->
                    <div id="target_days_inputs">
                        <input type="hidden" name="days_of_week[]" value="1">
                    </div>
                    <input type="hidden" name="day_of_week" id="input_day_of_week" value="1">
                    <input type="hidden" name="active_day" id="builder_active_day_input" value="1">
                </div>
                
                <div>
                    <label style="display:block; font-size: 12px; font-weight: 600; color: var(--diet-muted); margin-bottom: 6px;">Meal Type</label>
                    <div class="custom-food-dropdown-wrap" id="wrap_meal_type">
                        <input type="hidden" name="meal_type" id="meal_type_select" value="Breakfast" required>
                        <div id="meal_type_dropdown_trigger" class="custom-food-trigger" role="button" tabindex="0" onclick="toggleCustomSelect(event, 'meal')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleCustomSelect(event, 'meal');}">
                            <span class="custom-food-trigger-text">
                                <span id="meal_type_trigger_icon" style="display: inline-flex; align-items: center; color: var(--diet-accent); flex-shrink: 0;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/></svg>
                                </span>
                                <span id="meal_type_trigger_label">Breakfast</span>
                            </span>
                            <span class="custom-food-trigger-right">
                                <svg id="meal_type_trigger_arrow" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="transition: transform 0.2s; flex-shrink: 0;"><polyline points="6 9 12 15 18 9"/></svg>
                            </span>
                        </div>
                        <div id="meal_type_custom_menu" class="custom-food-menu" style="display: none;">
                            <?php 
                            $mealTypesList = [
                                ['id' => 'Breakfast', 'icon' => '<path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/>', 'badge' => 'Morning', 'badgeColor' => '#f59e0b'],
                                ['id' => 'Lunch', 'icon' => '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>', 'badge' => 'Midday', 'badgeColor' => '#38bdf8'],
                                ['id' => 'Dinner', 'icon' => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>', 'badge' => 'Evening', 'badgeColor' => '#a855f7'],
                                ['id' => 'Snack', 'icon' => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>', 'badge' => 'Fuel', 'badgeColor' => '#10b981'],
                            ];
                            foreach ($mealTypesList as $mt):
                            ?>
                                <div class="custom-select-option <?= $mt['id'] === 'Breakfast' ? 'is-selected' : '' ?>" data-meal-type="<?= $mt['id'] ?>" onclick="selectMealType('<?= $mt['id'] ?>', event)">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="color: var(--diet-accent);"><?= $mt['icon'] ?></svg>
                                        <span><?= $mt['id'] ?></span>
                                    </div>
                                    <span style="font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; background: color-mix(in srgb, <?= $mt['badgeColor'] ?> 15%, transparent); color: <?= $mt['badgeColor'] ?>;"><?= $mt['badge'] ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Local Food Library Custom Dropdown (Adheres to Dietary Restriction) -->
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; flex-wrap: wrap; gap: 4px;">
                        <label style="font-size: 12px; font-weight: 700; color: var(--ink); margin: 0;">
                            Choose from Food Library
                        </label>
                        <?php if ($memberRestriction !== 'none' && $memberRestriction !== ''): ?>
                            <span style="font-size: 11.5px; color: var(--diet-muted); font-weight: 600;" title="Filtered to adhere to member dietary restriction">
                                Filtered for <?= h(ucwords(str_replace('-', ' ', (string)$memberRestriction))) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="custom-food-dropdown-wrap" id="wrap_local_food">
                        <div id="food_dropdown_trigger" class="custom-food-trigger" role="button" tabindex="0" onclick="toggleCustomFoodDropdown(event)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleCustomFoodDropdown(event);}">
                            <span class="custom-food-trigger-text">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--diet-accent)" stroke-width="2.2" style="flex-shrink: 0;"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/></svg>
                                <span id="food_trigger_label">Select a dish...</span>
                            </span>
                            <span class="custom-food-trigger-right">
                                <span id="food_trigger_clear" class="custom-food-clear-btn" role="button" tabindex="0" onclick="clearLocalFoodSelection(event)" style="display: none;" title="Clear selection">✕</span>
                                <span id="food_trigger_badge" class="trigger-count-badge">0</span>
                                <svg id="food_trigger_arrow" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="transition: transform 0.2s; flex-shrink: 0;"><polyline points="6 9 12 15 18 9"/></svg>
                            </span>
                        </div>

                        <div id="custom_food_menu" class="custom-food-menu" style="display: none;">
                            <div id="custom_food_list">
                                <!-- Populated dynamically by updateLocalFoodDropdown() -->
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 5px; font-size: 11px; flex-wrap: wrap; gap: 4px;">
                        <span id="library_item_count" style="color: var(--diet-muted); font-size: 11px;">Loading dishes...</span>
                        <?php if ($memberRestriction !== 'none'): ?>
                            <label style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer; color: var(--diet-muted); margin: 0; font-size: 11px;" title="Temporarily show dishes from all dietary categories">
                                <input type="checkbox" id="show_all_restrictions" onchange="updateLocalFoodDropdown()" style="cursor: pointer; accent-color: var(--diet-accent);">
                                <span>Show all diets</span>
                            </label>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <div style="display: flex; gap: 8px; align-items: stretch; margin-top: 8px;">
                        <button type="button" onclick="openOnlineSearchModal()" class="btn-online-search-compact" id="btn_online_search_trigger" style="flex: 1; margin-top: 0;" title="Search millions of products, snacks, and branded foods online">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            <span id="online_search_btn_text">Search Online (Open Food Facts)</span>
                        </button>
                        <button type="button" id="btn_clear_online_food" onclick="clearOnlineFoodSelection()" class="btn-clear-food-selection" style="display: none;" title="Clear selected food and reset inputs">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            <span>Clear</span>
                        </button>
                    </div>

                    <div id="autofill_indicator" style="display: none; font-size: 11.5px; color: var(--diet-accent); margin-top: 6px; font-weight: 700; align-items: center; gap: 5px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        <span id="autofill_indicator_text">Details & macros auto-filled!</span>
                    </div>
                </div>

                <div>
                    <label style="display:block; font-size: 12px; font-weight: 600; color: var(--diet-muted); margin-bottom: 6px;">Meal Description / Portion</label>
                    <textarea name="food_items" id="meal_food_items" required rows="2" placeholder="e.g. 200g Grilled Chicken Breast with Brown Rice" style="width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--diet-border); background: var(--bg); color: var(--ink); font-size: 13px; resize: vertical; line-height: 1.4;"></textarea>
                    <input type="hidden" name="image_url" id="meal_image_url" value="">
                </div>
                
                <!-- Macro Inputs 2x2 Grid -->
                <div>
                    <label style="display:block; font-size: 12px; font-weight: 600; color: var(--diet-muted); margin-bottom: 6px;">Nutrition & Macros</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div style="background: var(--bg); border: 1px solid var(--diet-border); border-radius: 8px; padding: 8px 10px;">
                            <span style="font-size: 11px; font-weight: 600; color: var(--diet-muted); display: block;">Calories</span>
                            <input type="number" name="calories" id="calc_cals" min="0" value="0" readonly style="width: 100%; border: none; background: transparent; color: var(--diet-accent); font-size: 16px; font-weight: 700; outline: none; padding: 2px 0;">
                        </div>
                        <div style="background: var(--bg); border: 1px solid var(--diet-border); border-radius: 8px; padding: 8px 10px;">
                            <span style="font-size: 11px; font-weight: 600; color: var(--diet-muted); display: block;">Protein (g)</span>
                            <input type="number" name="protein_g" id="calc_p" min="0" value="0" oninput="updateMacros()" style="width: 100%; border: none; background: transparent; color: var(--ink); font-size: 16px; font-weight: 600; outline: none; padding: 2px 0;">
                        </div>
                        <div style="background: var(--bg); border: 1px solid var(--diet-border); border-radius: 8px; padding: 8px 10px;">
                            <span style="font-size: 11px; font-weight: 600; color: var(--diet-muted); display: block;">Carbs (g)</span>
                            <input type="number" name="carbs_g" id="calc_c" min="0" value="0" oninput="updateMacros()" style="width: 100%; border: none; background: transparent; color: var(--ink); font-size: 16px; font-weight: 600; outline: none; padding: 2px 0;">
                        </div>
                        <div style="background: var(--bg); border: 1px solid var(--diet-border); border-radius: 8px; padding: 8px 10px;">
                            <span style="font-size: 11px; font-weight: 600; color: var(--diet-muted); display: block;">Fat (g)</span>
                            <input type="number" name="fat_g" id="calc_f" min="0" value="0" oninput="updateMacros()" style="width: 100%; border: none; background: transparent; color: var(--ink); font-size: 16px; font-weight: 600; outline: none; padding: 2px 0;">
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="diet-btn-add-meal" id="btn_submit_add_meal">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                    <span id="btn_submit_add_meal_text">Add Meal to Plan</span>
                </button>
            </form>
        </section>
    </div>
    
    <!-- Right Column: Week Schedule & Meals View -->
    <div style="min-width: 0; display: flex; flex-direction: column; gap: 16px;">
        <!-- Day Tabs Bar -->
        <div class="days-tabs-nav" style="display: flex; gap: 6px; overflow-x: auto; padding: 6px; background: var(--surface); border: 1px solid var(--diet-border); border-radius: 10px;">
            <?php foreach ($daysMap as $dayNum => $dayName): 
                $dayMealCount = count($mealsByDay[$dayNum] ?? []);
            ?>
                <button type="button" class="tab-btn" data-day="<?= $dayNum ?>" onclick="switchDay(<?= $dayNum ?>)" style="flex: 1; padding: 10px 10px; border-radius: 8px; border: 1px solid transparent; background: transparent; color: var(--diet-muted); font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; white-space: nowrap; display: flex; flex-direction: column; align-items: center; gap: 3px;">
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
            <div class="panel day-panel" id="day-panel-<?= $dayNum ?>" style="margin: 0; padding: 20px; background: var(--surface); border: 1px solid var(--diet-border); border-radius: 12px;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--diet-border); padding-bottom: 14px; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <h3 style="margin: 0; font-size: 17px; font-weight: 700; color: var(--ink);"><?= $dayName ?> Menu</h3>
                        <p style="margin: 2px 0 0; font-size: 12.5px; color: var(--diet-muted);"><?= count($mealsByDay[$dayNum]) ?> meals scheduled for this day</p>
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                        <span style="background: var(--diet-accent-tint); color: var(--diet-accent); border: 1px solid var(--diet-accent-border); padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
                            <span><?= $dayCals ?> kcal</span>
                        </span>
                        <span style="background: var(--bg); border: 1px solid var(--diet-border); padding: 4px 10px; border-radius: 20px; font-size: 12px; color: var(--diet-muted); font-weight: 600;">
                            P: <strong style="color: var(--ink);"><?= round($dayPro, 1) ?>g</strong>
                        </span>
                        <span style="background: var(--bg); border: 1px solid var(--diet-border); padding: 4px 10px; border-radius: 20px; font-size: 12px; color: var(--diet-muted); font-weight: 600;">
                            C: <strong style="color: var(--ink);"><?= round($dayCarbs, 1) ?>g</strong>
                        </span>
                        <span style="background: var(--bg); border: 1px solid var(--diet-border); padding: 4px 10px; border-radius: 20px; font-size: 12px; color: var(--diet-muted); font-weight: 600;">
                            F: <strong style="color: var(--ink);"><?= round($dayFat, 1) ?>g</strong>
                        </span>
                        <?php if (!empty($mealsByDay[$dayNum])): ?>
                            <button type="button" class="btn-copy-day-menu" onclick="openCopyDayModal(<?= $dayNum ?>, '<?= $dayName ?>', <?= count($mealsByDay[$dayNum]) ?>)" title="Copy this entire day's menu to other days">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                <span>Copy Day Menu</span>
                            </button>
                            <button type="button" class="btn-clear-day-menu" onclick="confirmClearDay(<?= $dayNum ?>, '<?= $dayName ?>')" title="Clear all meals from this day">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                <span>Clear Day</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if (empty($mealsByDay[$dayNum])): ?>
                    <div style="text-align: center; padding: 40px 20px; background: var(--bg); border: 1px dashed var(--diet-border); border-radius: 10px;">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="var(--diet-muted)" stroke-width="1.5" style="margin-bottom: 10px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <p style="margin: 0; font-size: 14px; font-weight: 600; color: var(--ink);">No meals planned for <?= $dayName ?> yet</p>
                        <p style="margin: 4px 0 0; font-size: 12.5px; color: var(--diet-muted);">Add meals individually or quickly copy from another day.</p>
                        <div style="display: flex; justify-content: center; gap: 8px; margin-top: 16px; flex-wrap: wrap;">
                            <?php foreach (['Breakfast', 'Lunch', 'Dinner', 'Snack'] as $qmt): ?>
                                <button type="button" class="btn-quick-slot" onclick="quickSelectMealSlot(<?= $dayNum ?>, '<?= $qmt ?>')">
                                    + Add <?= $qmt ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <?php foreach ($mealsByDay[$dayNum] as $meal): 
                            $photoUrl = get_meal_photo_url($meal['image_url'] ?? null, $meal['food_items'], $meal['meal_type']);
                        ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 14px; background: var(--bg); border: 1px solid var(--diet-border); border-radius: 10px; gap: 14px; transition: border-color 0.2s;">
                                <div style="display: flex; gap: 14px; align-items: center; min-width: 0; flex: 1;">
                                    <img src="<?= h($photoUrl) ?>" alt="" style="width: 58px; height: 58px; border-radius: 8px; object-fit: cover; flex-shrink: 0; border: 1px solid var(--diet-border); background: #000;" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80';">
                                    <div style="min-width: 0; flex: 1;">
                                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                            <span class="diet-meal-badge">
                                                <?= h($meal['meal_type']) ?>
                                            </span>
                                            <span style="font-size: 12px; font-weight: 700; color: var(--diet-accent);">
                                                <?= $meal['calories'] ?> kcal
                                            </span>
                                        </div>
                                        <p style="margin: 4px 0 0; font-size: 13.5px; font-weight: 500; color: var(--ink); line-height: 1.4; word-break: break-word;"><?= nl2br(h($meal['food_items'])) ?></p>
                                        <div style="display: flex; gap: 12px; margin-top: 6px; font-size: 11.5px; color: var(--diet-muted); font-weight: 500;">
                                            <span>Protein: <strong style="color: var(--ink);"><?= $meal['protein_g'] ?>g</strong></span>
                                            <span>Carbs: <strong style="color: var(--ink);"><?= $meal['carbs_g'] ?>g</strong></span>
                                            <span>Fat: <strong style="color: var(--ink);"><?= $meal['fat_g'] ?>g</strong></span>
                                        </div>
                                    </div>
                                </div>
                                <form method="post" style="margin:0; flex-shrink: 0;" onsubmit="return confirmRemoveMeal(this, event, <?= json_encode($dayName) ?>);">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_meal">
                                    <input type="hidden" name="meal_id" value="<?= $meal['meal_id'] ?>">
                                    <input type="hidden" name="current_day" value="<?= $dayNum ?>">
                                    <button type="submit" class="btn-diet-remove-meal" title="Remove Meal">Remove</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                        
                        <!-- Quick Add Slot Bar -->
                        <div class="day-quick-slot-bar" style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px; padding-top: 12px; border-top: 1px dashed var(--diet-border); align-items: center;">
                            <span style="font-size: 11.5px; font-weight: 700; color: var(--diet-muted); display: inline-flex; align-items: center; gap: 4px;">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--diet-accent)" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                Quick Add to <?= $dayName ?>:
                            </span>
                            <?php foreach (['Breakfast', 'Lunch', 'Dinner', 'Snack'] as $qmt): ?>
                                <button type="button" class="btn-quick-slot" onclick="quickSelectMealSlot(<?= $dayNum ?>, '<?= $qmt ?>')">
                                    + <?= $qmt ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Diet Builder Configuration & Script -->
<script>
window.DIET_BUILDER_CONFIG = {
    csrfToken: <?= json_encode(csrf_token()) ?>,
    memberRestriction: <?= json_encode($memberRestriction) ?> || 'none',
    localFoods: <?= json_encode($allLibraryFoods, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> || []
};
</script>
<script src="<?= h(asset_url('js/pages/diet_builder.js')) ?>"></script>

<!-- Online Food Search Modal (Open Food Facts) -->
<div class="diet-modal-overlay" id="modal-online-food-search" style="display: none;" onclick="if(event.target===this) closeOnlineSearchModal()">
    <div class="diet-modal-card">
        <div class="diet-modal-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(56, 189, 248, 0.15); color: #38bdf8; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 15.5px; font-weight: 700; color: var(--ink);">Search Online Foods</h3>
                    <p style="margin: 2px 0 0; font-size: 12px; color: var(--diet-muted);">Search 3M+ groceries, brands & ingredients on Open Food Facts</p>
                </div>
            </div>
            <button type="button" onclick="closeOnlineSearchModal()" title="Close" style="background:none; border:none; color:var(--muted); font-size:22px; cursor:pointer; line-height:1; padding:4px;">&times;</button>
        </div>

        <div class="diet-modal-body">
            <div style="position: relative; margin-bottom: 14px;">
                <input type="text" id="modal_online_query" placeholder="e.g. Greek Yogurt, Quest Bar, Tofu, Almond Milk..." autocomplete="off" style="width: 100%; box-sizing: border-box; padding: 11px 70px 11px 14px; border-radius: 8px; border: 1px solid var(--diet-border); background: var(--bg); color: var(--ink); font-size: 13.5px; outline: none;" onkeydown="if(event.key==='Enter') executeModalOnlineSearch(this.value.trim());">
                <button type="button" id="modal_online_clear_btn" class="modal-clear-query-btn" onclick="clearModalOnlineQuery()" title="Clear search text">✕</button>
                <svg id="modal_online_loader" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--diet-accent)" stroke-width="2.5" stroke-linecap="round" style="display: none; position: absolute; right: 14px; top: 12px; animation: spin 1s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
            </div>

            <div id="modal_online_results" style="display: flex; flex-direction: column; gap: 8px; max-height: 380px; overflow-y: auto;">
                <div style="text-align: center; padding: 36px 14px; color: var(--diet-muted); font-size: 13px;">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="margin: 0 auto 10px; opacity: 0.5; display: block;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    Type a food or brand name above to search online.
                </div>
            </div>
        </div>
    </div>
</div>
</div> <!-- End of .diet-builder-page -->
<?php
    render_footer();
}
