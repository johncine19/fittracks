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
                <button type="submit" class="diet-action-btn diet-btn-generate" onclick="return confirm('Auto-generate a dietary plan? This will clear any draft meals you have added manually.');" title="Auto-generate an optimized 7-day meal plan">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/></svg>
                    <span>Generate Plan</span>
                </button>
            </form>
            <form method="post" style="margin:0;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="publish">
                <button type="submit" class="diet-action-btn diet-btn-publish" onclick="return confirm('Publish this diet plan?');" title="Publish this diet plan to member">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <span>Publish Plan</span>
                </button>
            </form>
        </div>
    </div>

<style>
/* 70/20/10 Color System Tokens */
.diet-builder-page {
    --diet-accent: #84cc16;
    --diet-accent-hover: #65a30d;
    --diet-accent-dark: #4d7c0f;
    --diet-accent-tint: rgba(132, 204, 22, 0.12);
    --diet-accent-border: rgba(132, 204, 22, 0.3);
    --diet-border: var(--line, rgba(255, 255, 255, 0.08));
    --diet-muted: var(--muted, #8792ad);
}
[data-theme="light"] .diet-builder-page {
    --diet-accent: #65a30d;
    --diet-accent-hover: #4d7c0f;
    --diet-accent-dark: #365314;
    --diet-accent-tint: rgba(101, 163, 13, 0.12);
    --diet-accent-border: rgba(101, 163, 13, 0.35);
    --diet-border: #cbd5e1;
    --diet-muted: #64748b;
}

/* Quiet Header Metadata */
.diet-header-meta {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    font-size: 12px;
    font-weight: 600;
    color: var(--diet-muted);
    letter-spacing: 0.3px;
}
.diet-meta-mode {
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: var(--ink);
    opacity: 0.9;
}
.diet-meta-sep {
    opacity: 0.35;
}

/* Action Buttons Bar */
.diet-actions-wrap {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.diet-action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 9px 16px;
    min-height: 40px;
    border-radius: 8px;
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    box-sizing: border-box;
    line-height: 1;
    border: 1px solid transparent;
}
.diet-action-btn svg {
    flex-shrink: 0;
}

/* Back Button (Neutral Ghost/Secondary) */
.diet-btn-back {
    background: var(--panel-soft);
    color: var(--ink);
    border-color: var(--diet-border);
}
.diet-btn-back:hover {
    background: var(--surface);
    border-color: var(--diet-muted);
    color: var(--ink);
    text-decoration: none;
    transform: translateX(-2px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
}

/* Generate Plan Button (Subtle Neutral Outline) */
.diet-btn-generate {
    background: var(--surface);
    color: var(--ink);
    border-color: var(--diet-border);
}
.diet-btn-generate:hover {
    background: var(--panel-soft);
    border-color: var(--diet-muted);
    color: var(--ink);
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
}

/* Publish Plan Button (Solid Primary Green Accent) */
.diet-btn-publish {
    background: var(--diet-accent);
    color: #080b0d;
    border-color: var(--diet-accent);
    font-weight: 800;
}
.diet-btn-publish:hover {
    background: var(--diet-accent-hover);
    border-color: var(--diet-accent-hover);
    color: #080b0d;
    box-shadow: 0 0 16px var(--diet-accent-tint);
    transform: translateY(-1px);
}

/* Light Theme Overrides */
[data-theme="light"] .diet-btn-back {
    background: #ffffff;
    color: #1e293b;
    border-color: #cbd5e1;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}
[data-theme="light"] .diet-btn-back:hover {
    background: #f8fafc;
    border-color: #94a3b8;
    color: #0f172a;
}
[data-theme="light"] .diet-btn-generate {
    background: #ffffff;
    color: #1e293b;
    border-color: #cbd5e1;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}
[data-theme="light"] .diet-btn-generate:hover {
    background: #f8fafc;
    border-color: #94a3b8;
    color: #0f172a;
}
[data-theme="light"] .diet-btn-publish {
    background: #65a30d;
    color: #ffffff;
    border-color: #65a30d;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}
[data-theme="light"] .diet-btn-publish:hover {
    background: #4d7c0f;
    border-color: #4d7c0f;
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(101, 163, 13, 0.25);
}

@media (max-width: 600px) {
    .diet-actions-wrap {
        width: 100%;
    }
    .diet-actions-wrap form {
        flex: 1;
    }
    .diet-action-btn {
        width: 100%;
    }
}

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
    background: var(--panel-soft) !important;
    color: var(--diet-accent) !important;
    border-color: var(--diet-accent-border) !important;
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

/* Custom Food Dropdown Styles */
.custom-food-dropdown-wrap {
    position: relative;
    width: 100%;
}
.custom-food-trigger {
    width: 100% !important;
    box-sizing: border-box !important;
    padding: 10px 14px !important;
    border-radius: 8px !important;
    border: 1px solid var(--diet-border) !important;
    background: var(--bg) !important;
    color: var(--ink) !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    justify-content: space-between !important;
    transition: all 0.2s ease !important;
    text-align: left !important;
    gap: 10px !important;
    user-select: none;
}
.custom-food-trigger:hover, .custom-food-trigger.is-open {
    border-color: var(--diet-accent) !important;
    box-shadow: 0 0 12px var(--diet-accent-tint) !important;
}
.custom-food-trigger-text {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    flex: 1 1 auto;
}
.custom-food-trigger-right {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    flex-shrink: 0 !important;
    margin-left: auto !important;
}
.trigger-count-badge {
    background: var(--panel-soft);
    color: var(--diet-accent);
    font-size: 11px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 10px;
    border: 1px solid var(--diet-accent-border);
    white-space: nowrap;
    flex-shrink: 0;
}
[data-theme="light"] .trigger-count-badge {
    background: rgba(101, 163, 13, 0.12);
    color: #365314;
    border-color: rgba(101, 163, 13, 0.3);
}
.custom-food-clear-btn {
    background: none;
    border: none;
    padding: 2px 5px;
    color: var(--muted);
    cursor: pointer;
    font-size: 12px;
    border-radius: 4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
}
.custom-food-clear-btn:hover {
    color: var(--danger);
    background: rgba(255, 77, 93, 0.15);
}
.custom-food-menu {
    position: absolute;
    left: 0;
    right: 0;
    top: calc(100% + 4px);
    z-index: 1000;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 10px;
    box-shadow: 0 12px 30px rgba(0,0,0,0.45);
    max-height: 290px;
    overflow-y: auto;
    overflow-x: hidden;
}
[data-theme="light"] .custom-food-menu {
    background: #ffffff;
    border-color: #cbd5e1;
    box-shadow: 0 12px 30px rgba(0,0,0,0.12);
}
.custom-food-item {
    padding: 10px 12px;
    border-bottom: 1px solid var(--line);
    cursor: pointer;
    transition: background 0.15s ease;
    display: flex;
    align-items: center;
    gap: 10px;
}
.custom-food-item:last-child {
    border-bottom: none;
}
.custom-food-item:hover {
    background: var(--panel-soft);
}
[data-theme="light"] .custom-food-item:hover {
    background: #f1f5f9;
}
.custom-food-item.is-selected {
    background: var(--diet-accent-tint);
    border-left: 3px solid var(--diet-accent);
}
.custom-select-option {
    padding: 10px 14px;
    border-bottom: 1px solid var(--diet-border);
    cursor: pointer;
    transition: background 0.15s ease;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    font-size: 13px;
    font-weight: 500;
    color: var(--ink);
    user-select: none;
}
.custom-select-option:last-child {
    border-bottom: none;
}
.custom-select-option:hover {
    background: var(--panel-soft);
}
[data-theme="light"] .custom-select-option:hover {
    background: #f1f5f9;
}
.custom-select-option.is-selected {
    background: var(--diet-accent-tint);
    font-weight: 700;
    color: var(--diet-accent);
    border-left: 3px solid var(--diet-accent);
}
[data-theme="light"] .custom-select-option.is-selected {
    color: #4d7c0f;
}
.custom-food-thumb {
    width: 38px;
    height: 38px;
    border-radius: 6px;
    object-fit: cover;
    flex-shrink: 0;
    background: var(--panel-soft);
}
.custom-food-title {
    font-size: 13px;
    font-weight: 700;
    color: var(--ink);
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.custom-food-meta {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11.5px;
    color: var(--diet-muted);
    margin-top: 3px;
    flex-wrap: wrap;
}
.custom-food-cal {
    color: var(--diet-accent);
    font-weight: 800;
}
[data-theme="light"] .custom-food-cal {
    color: #4d7c0f;
}
.custom-food-tag {
    font-size: 10px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 4px;
    background: var(--diet-accent-tint);
    color: var(--diet-accent);
    border: 1px solid var(--diet-accent-border);
}

/* Compact Online Search Button (Neutral secondary) */
.btn-online-search-compact {
    width: 100%;
    box-sizing: border-box;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    background: var(--bg);
    color: var(--diet-muted);
    border: 1px solid var(--diet-border);
    transition: all 0.2s ease;
    margin-top: 8px;
}
.btn-online-search-compact:hover {
    background: var(--panel-soft);
    color: var(--ink);
    border-color: var(--diet-muted);
}
[data-theme="light"] .btn-online-search-compact {
    background: #ffffff;
    color: #475569;
    border-color: #cbd5e1;
}
[data-theme="light"] .btn-online-search-compact:hover {
    background: #f8fafc;
    color: #0f172a;
    border-color: #94a3b8;
}

/* Clear Selected Food Button (Muted Neutral, subtle red hover) */
.btn-clear-food-selection {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    background: var(--panel-soft);
    color: var(--diet-muted);
    border: 1px solid var(--diet-border);
    white-space: nowrap;
    transition: all 0.2s ease;
    flex-shrink: 0;
}
.btn-clear-food-selection:hover {
    background: rgba(239, 68, 68, 0.12);
    border-color: rgba(239, 68, 68, 0.35);
    color: #ef4444;
}
[data-theme="light"] .btn-clear-food-selection {
    background: #f1f5f9;
    color: #64748b;
    border-color: #cbd5e1;
}
[data-theme="light"] .btn-clear-food-selection:hover {
    background: #fee2e2;
    border-color: #ef4444;
    color: #b91c1c;
}

/* Primary Add Meal Button (FitTrack Green Accent) */
.diet-btn-add-meal {
    background: var(--diet-accent) !important;
    color: #080b0d !important;
    border: 1px solid var(--diet-accent) !important;
    width: 100%;
    padding: 11px 16px;
    font-size: 14px;
    font-weight: 800;
    border-radius: 8px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s ease;
    margin-top: 6px;
}
.diet-btn-add-meal:hover {
    background: var(--diet-accent-hover) !important;
    border-color: var(--diet-accent-hover) !important;
    color: #080b0d !important;
    box-shadow: 0 0 16px var(--diet-accent-tint) !important;
    transform: translateY(-1px);
}
[data-theme="light"] .diet-btn-add-meal {
    background: #65a30d !important;
    color: #ffffff !important;
    border-color: #65a30d !important;
}
[data-theme="light"] .diet-btn-add-meal:hover {
    background: #4d7c0f !important;
    border-color: #4d7c0f !important;
    color: #ffffff !important;
}

/* Meal Type Badge on Cards (Quiet Neutral) */
.diet-meal-badge {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background: var(--panel-soft);
    color: var(--ink);
    border: 1px solid var(--diet-border);
    padding: 2px 8px;
    border-radius: 4px;
}
[data-theme="light"] .diet-meal-badge {
    background: #f1f5f9;
    color: #334155;
    border-color: #cbd5e1;
}

/* Destructive Remove Button (Subtle Red Outline) */
.btn-diet-remove-meal {
    background: transparent;
    border: 1px solid rgba(239, 68, 68, 0.35);
    color: #ef4444;
    padding: 5px 12px;
    font-size: 12px;
    font-weight: 600;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
}
.btn-diet-remove-meal:hover {
    background: rgba(239, 68, 68, 0.15);
    border-color: #ef4444;
    color: #ffffff;
}
[data-theme="light"] .btn-diet-remove-meal {
    border-color: #fca5a5;
    color: #dc2626;
}
[data-theme="light"] .btn-diet-remove-meal:hover {
    background: #fee2e2;
    border-color: #dc2626;
    color: #991b1b;
}

.modal-clear-query-btn {
    display: none;
    position: absolute;
    right: 36px;
    top: 50%;
    transform: translateY(-50%);
    background: var(--panel-soft);
    border: 1px solid var(--line);
    color: var(--muted);
    border-radius: 50%;
    width: 20px;
    height: 20px;
    cursor: pointer;
    font-size: 11px;
    line-height: 1;
    align-items: center;
    justify-content: center;
    padding: 0;
    transition: all 0.15s ease;
}
.modal-clear-query-btn:hover {
    color: var(--ink);
    background: var(--line);
}

/* Online Search Modal */
.diet-modal-overlay {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    background: rgba(0, 0, 0, 0.78) !important;
    backdrop-filter: blur(6px) !important;
    z-index: 99999 !important;
    display: none;
    align-items: center !important;
    justify-content: center !important;
    padding: 16px !important;
    box-sizing: border-box !important;
}
.diet-modal-overlay.active {
    display: flex !important;
    animation: dietModalFadeIn 0.2s ease;
}
@keyframes dietModalFadeIn {
    from { opacity: 0; transform: scale(0.98); }
    to { opacity: 1; transform: scale(1); }
}
.diet-modal-card {
    background: var(--surface);
    color: var(--ink);
    border: 1px solid var(--line);
    border-radius: 14px;
    width: 100% !important;
    max-width: 560px !important;
    max-height: 88vh !important;
    display: flex !important;
    flex-direction: column !important;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
    overflow: hidden;
}
[data-theme="light"] .diet-modal-card {
    background: #ffffff;
    border-color: #cbd5e1;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
}
.diet-modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--line);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.diet-modal-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
}
</style>

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
                    <label style="display:block; font-size: 12px; font-weight: 600; color: var(--diet-muted); margin-bottom: 6px;">Day of Week</label>
                    <div class="custom-food-dropdown-wrap" id="wrap_day_of_week">
                        <input type="hidden" name="day_of_week" id="input_day_of_week" value="1" required>
                        <div id="day_dropdown_trigger" class="custom-food-trigger" role="button" tabindex="0" onclick="toggleCustomSelect(event, 'day')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleCustomSelect(event, 'day');}">
                            <span class="custom-food-trigger-text">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--diet-accent)" stroke-width="2.2" style="flex-shrink: 0;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <span id="day_trigger_label">Monday</span>
                            </span>
                            <span class="custom-food-trigger-right">
                                <span id="day_trigger_badge" class="trigger-count-badge">Day 1</span>
                                <svg id="day_trigger_arrow" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="transition: transform 0.2s; flex-shrink: 0;"><polyline points="6 9 12 15 18 9"/></svg>
                            </span>
                        </div>
                        <div id="day_custom_menu" class="custom-food-menu" style="display: none;">
                            <?php foreach ($daysMap as $num => $name): 
                                $mCount = count($mealsByDay[$num] ?? []);
                            ?>
                                <div class="custom-select-option <?= $num === 1 ? 'is-selected' : '' ?>" data-day="<?= $num ?>" onclick="selectDayOfWeek(<?= $num ?>, '<?= $name ?>', event)">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                        <span><?= $name ?></span>
                                    </div>
                                    <span style="font-size: 11px; opacity: 0.75; font-weight: 600;"><?= $mCount ?> <?= $mCount === 1 ? 'meal' : 'meals' ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
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
                        <label style="font-size: 12px; font-weight: 700; color: var(--ink); margin: 0; display: inline-flex; align-items: center; gap: 6px;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--diet-accent)" stroke-width="2.5"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="2" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="2" x2="14" y2="4"/></svg>
                            <span>Choose from Food Library</span>
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
                
                <button type="submit" class="diet-btn-add-meal">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                    <span>Add Meal to Plan</span>
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
                    </div>
                </div>
                
                <?php if (empty($mealsByDay[$dayNum])): ?>
                    <div style="text-align: center; padding: 40px 20px; background: var(--bg); border: 1px dashed var(--diet-border); border-radius: 10px;">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="var(--diet-muted)" stroke-width="1.5" style="margin-bottom: 10px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <p style="margin: 0; font-size: 14px; font-weight: 600; color: var(--ink);">No meals planned for <?= $dayName ?> yet</p>
                        <p style="margin: 4px 0 0; font-size: 12.5px; color: var(--diet-muted);">Fill out the Add Meal form on the left or click "Generate Plan" above to create an automated 7-day menu.</p>
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
                                <form method="post" style="margin:0; flex-shrink: 0;" onsubmit="return confirm('Remove this meal from <?= $dayName ?>?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_meal">
                                    <input type="hidden" name="meal_id" value="<?= $meal['meal_id'] ?>">
                                    <button type="submit" class="btn-diet-remove-meal" title="Remove Meal">Remove</button>
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
const localFoods = <?= json_encode($allLibraryFoods, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> || [];
const memberRestriction = <?= json_encode($memberRestriction) ?> || 'none';

function isDietaryCompatible(foodRestriction, targetRestriction) {
    if (!targetRestriction || targetRestriction === 'none' || targetRestriction === 'all') {
        return true;
    }
    const r = (foodRestriction || 'none').toLowerCase().trim();
    const target = targetRestriction.toLowerCase().trim();

    if (r === target) return true;

    // Vegan dishes are suitable for vegetarian, pescatarian, halal
    if (target === 'vegetarian') {
        return r === 'vegetarian' || r === 'vegan';
    }
    if (target === 'pescatarian') {
        return r === 'pescatarian' || r === 'vegetarian' || r === 'vegan';
    }
    if (target === 'halal') {
        return r === 'halal' || r === 'vegetarian' || r === 'vegan';
    }
    if (target === 'vegan') {
        return r === 'vegan';
    }
    if (target === 'keto') {
        return r === 'keto';
    }
    if (target === 'gluten-free') {
        return r === 'gluten-free';
    }
    return false;
}

let selectedLocalFoodId = null;

function closeAllDropdowns() {
    const menus = [
        { menu: document.getElementById('day_custom_menu'), trigger: document.getElementById('day_dropdown_trigger'), arrow: document.getElementById('day_trigger_arrow'), wrap: document.getElementById('wrap_day_of_week') },
        { menu: document.getElementById('meal_type_custom_menu'), trigger: document.getElementById('meal_type_dropdown_trigger'), arrow: document.getElementById('meal_type_trigger_arrow'), wrap: document.getElementById('wrap_meal_type') },
        { menu: document.getElementById('custom_food_menu'), trigger: document.getElementById('food_dropdown_trigger'), arrow: document.getElementById('food_trigger_arrow'), wrap: document.getElementById('wrap_local_food') }
    ];

    menus.forEach(item => {
        if (item.menu) item.menu.style.display = 'none';
        if (item.trigger) item.trigger.classList.remove('is-open');
        if (item.arrow) item.arrow.style.transform = 'rotate(0deg)';
        if (item.wrap) item.wrap.style.zIndex = '';
    });
}

function toggleCustomSelect(param1, param2) {
    if (param1 && typeof param1.stopPropagation === 'function') {
        param1.stopPropagation();
    }

    let type = null;
    let forceState = null;

    if (typeof param1 === 'string') {
        type = param1;
        if (typeof param2 === 'boolean') forceState = param2;
    } else if (typeof param2 === 'string') {
        type = param2;
    }

    if (!type) return;

    const map = {
        'day': { menu: document.getElementById('day_custom_menu'), trigger: document.getElementById('day_dropdown_trigger'), arrow: document.getElementById('day_trigger_arrow'), wrap: document.getElementById('wrap_day_of_week') },
        'meal': { menu: document.getElementById('meal_type_custom_menu'), trigger: document.getElementById('meal_type_dropdown_trigger'), arrow: document.getElementById('meal_type_trigger_arrow'), wrap: document.getElementById('wrap_meal_type') },
        'food': { menu: document.getElementById('custom_food_menu'), trigger: document.getElementById('food_dropdown_trigger'), arrow: document.getElementById('food_trigger_arrow'), wrap: document.getElementById('wrap_local_food') }
    };

    const target = map[type];
    if (!target || !target.menu || !target.trigger) return;

    const isCurrentlyOpen = (target.menu.style.display === 'block');
    const shouldOpen = (forceState !== null) ? !!forceState : !isCurrentlyOpen;

    // Close all open dropdowns first
    closeAllDropdowns();

    if (shouldOpen) {
        target.menu.style.display = 'block';
        target.trigger.classList.add('is-open');
        if (target.arrow) target.arrow.style.transform = 'rotate(180deg)';
        if (target.wrap) target.wrap.style.zIndex = '1050';
    }
}

function toggleCustomFoodDropdown(forceOrEvent) {
    if (forceOrEvent && typeof forceOrEvent.stopPropagation === 'function') {
        forceOrEvent.stopPropagation();
        toggleCustomSelect('food');
    } else if (typeof forceOrEvent === 'boolean') {
        toggleCustomSelect('food', forceOrEvent);
    } else {
        toggleCustomSelect('food');
    }
}

function selectDayOfWeek(num, name, event) {
    if (event && typeof event.stopPropagation === 'function') {
        event.stopPropagation();
    }
    closeAllDropdowns();
    switchDay(num);
}

function selectMealType(type, event) {
    if (event && typeof event.stopPropagation === 'function') {
        event.stopPropagation();
    }
    closeAllDropdowns();
    const input = document.getElementById('meal_type_select');
    if (input) input.value = type;

    const label = document.getElementById('meal_type_trigger_label');
    if (label) label.textContent = type;

    const icons = {
        'Breakfast': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/></svg>',
        'Lunch': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
        'Dinner': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
        'Snack': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>'
    };
    const iconContainer = document.getElementById('meal_type_trigger_icon');
    if (iconContainer && icons[type]) {
        iconContainer.innerHTML = icons[type];
    }

    document.querySelectorAll('#meal_type_custom_menu .custom-select-option').forEach(el => {
        el.classList.toggle('is-selected', el.getAttribute('data-meal-type') === type);
    });

    updateLocalFoodDropdown();
}

function clearLocalFoodSelection(event) {
    if (event && typeof event.stopPropagation === 'function') {
        event.stopPropagation();
    }
    selectedLocalFoodId = null;
    const triggerLabel = document.getElementById('food_trigger_label');
    const clearBtn = document.getElementById('food_trigger_clear');
    const mealTypeSelect = document.getElementById('meal_type_select');
    const foodItemsInput = document.getElementById('meal_food_items');
    const pInput = document.getElementById('calc_p');
    const cInput = document.getElementById('calc_c');
    const fInput = document.getElementById('calc_f');
    const calsInput = document.getElementById('calc_cals');
    const imgInput = document.getElementById('meal_image_url');
    const indicator = document.getElementById('autofill_indicator');
    const indicatorText = document.getElementById('autofill_indicator_text');

    const typeName = mealTypeSelect ? mealTypeSelect.value : 'dish';
    if (triggerLabel) triggerLabel.textContent = `Select a ${typeName} dish...`;
    if (clearBtn) clearBtn.style.display = 'none';
    document.querySelectorAll('.custom-food-item').forEach(el => el.classList.remove('is-selected'));

    if (foodItemsInput) foodItemsInput.value = '';
    if (pInput) pInput.value = 0;
    if (cInput) cInput.value = 0;
    if (fInput) fInput.value = 0;
    if (calsInput) calsInput.value = 0;
    if (imgInput) imgInput.value = '';

    if (indicator && indicatorText) {
        indicatorText.textContent = 'Local food selection cleared.';
        indicator.style.display = 'inline-flex';
        setTimeout(() => { indicator.style.display = 'none'; }, 2000);
    }
}

function updateLocalFoodDropdown() {
    const mealTypeSelect = document.getElementById('meal_type_select');
    const listContainer = document.getElementById('custom_food_list');
    const showAllCheck = document.getElementById('show_all_restrictions');
    const countBadge = document.getElementById('food_trigger_badge');
    const countSpan = document.getElementById('library_item_count');
    const triggerLabel = document.getElementById('food_trigger_label');
    const clearBtn = document.getElementById('food_trigger_clear');
    if (!listContainer || !mealTypeSelect) return;

    const currentMealType = mealTypeSelect.value;
    const filterByDiet = !showAllCheck || !showAllCheck.checked;
    const activeRestriction = filterByDiet ? memberRestriction : 'all';

    const matchingFoods = localFoods.filter(f => {
        const matchesType = !currentMealType || f.meal_type.toLowerCase() === currentMealType.toLowerCase();
        const matchesDiet = isDietaryCompatible(f.dietary_restriction, activeRestriction);
        return matchesType && matchesDiet;
    });

    if (countBadge) {
        countBadge.textContent = matchingFoods.length;
    }
    if (countSpan) {
        const dietNote = (filterByDiet && memberRestriction !== 'none') ? ` (${memberRestriction.replace('-', ' ')})` : '';
        countSpan.textContent = `${matchingFoods.length} dish${matchingFoods.length === 1 ? '' : 'es'} available${dietNote}`;
    }

    // Reset or update trigger label
    if (selectedLocalFoodId && !matchingFoods.some(f => parseInt(f.food_id, 10) === selectedLocalFoodId)) {
        selectedLocalFoodId = null;
        if (triggerLabel) triggerLabel.textContent = `Select a ${currentMealType} dish...`;
        if (clearBtn) clearBtn.style.display = 'none';
    } else if (!selectedLocalFoodId && triggerLabel) {
        triggerLabel.textContent = `Select a ${currentMealType} dish...`;
    }

    if (matchingFoods.length === 0) {
        listContainer.innerHTML = `
            <div style="padding: 20px 14px; text-align: center; color: var(--muted); font-size: 12.5px;">
                <p style="margin: 0 0 4px; font-weight: 700; color: var(--ink);">No ${currentMealType} dishes found</p>
                <p style="margin: 0; font-size: 11.5px; opacity: 0.8;">Try checking "Show all diets" or search below.</p>
            </div>
        `;
        return;
    }

    listContainer.innerHTML = matchingFoods.map(food => {
        const id = parseInt(food.food_id, 10);
        const isSelected = (selectedLocalFoodId === id);
        const img = food.image_url || '';
        const dietTag = (food.dietary_restriction && food.dietary_restriction !== 'none') 
            ? `<span class="custom-food-tag">${food.dietary_restriction}</span>` 
            : '';
        const serving = food.serving_size ? `<span>${food.serving_size}</span> &bull; ` : '';

        return `
            <div class="custom-food-item ${isSelected ? 'is-selected' : ''}" data-food-id="${id}">
                ${img ? `<img src="${img}" class="custom-food-thumb" alt="${food.name}" onerror="this.style.display='none'">` : `
                    <div class="custom-food-thumb" style="display: flex; align-items: center; justify-content: center; color: var(--muted);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/></svg>
                    </div>
                `}
                <div style="flex: 1; min-width: 0;">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px;">
                        <span class="custom-food-title">${food.name}</span>
                        ${dietTag}
                    </div>
                    <div class="custom-food-meta">
                        ${serving}
                        <span class="custom-food-cal">${food.calories} kcal</span>
                        <span style="opacity: 0.5;">&bull;</span>
                        <span>P: ${food.protein_g}g</span>
                        <span>C: ${food.carbs_g}g</span>
                        <span>F: ${food.fat_g}g</span>
                    </div>
                </div>
            </div>
        `;
    }).join('');

    // Attach click listeners to options
    listContainer.querySelectorAll('.custom-food-item').forEach(el => {
        el.addEventListener('click', function(e) {
            e.stopPropagation();
            const id = parseInt(this.getAttribute('data-food-id'), 10);
            const chosen = localFoods.find(f => parseInt(f.food_id, 10) === id);
            if (chosen) {
                selectedLocalFoodId = id;
                selectedOnlineFood = null;
                const clearOnlineBtn = document.getElementById('btn_clear_online_food');
                const onlineBtnText = document.getElementById('online_search_btn_text');
                if (clearOnlineBtn) clearOnlineBtn.style.display = 'none';
                if (onlineBtnText) onlineBtnText.textContent = 'Search Online (Open Food Facts)';

                if (triggerLabel) {
                    triggerLabel.textContent = `${chosen.name} (${chosen.calories} kcal)`;
                }
                if (clearBtn) {
                    clearBtn.style.display = 'inline-flex';
                }
                closeAllDropdowns();
                updateLocalFoodDropdown();
                selectFoodItem(chosen, false);
            }
        });
    });
}

let selectedOnlineFood = null;

function selectFoodItem(item, syncMealType = true) {
    const foodItemsInput = document.getElementById('meal_food_items');
    const pInput = document.getElementById('calc_p');
    const cInput = document.getElementById('calc_c');
    const fInput = document.getElementById('calc_f');
    const calsInput = document.getElementById('calc_cals');
    const imgInput = document.getElementById('meal_image_url');
    const typeSelect = document.getElementById('meal_type_select');
    const indicator = document.getElementById('autofill_indicator');
    const indicatorText = document.getElementById('autofill_indicator_text');
    const clearOnlineBtn = document.getElementById('btn_clear_online_food');
    const onlineBtnText = document.getElementById('online_search_btn_text');

    const name = item.name;
    const cals = (item.calories !== undefined && item.calories !== null) ? item.calories : (item.calories_100g || 0);
    const pro = (item.protein_g !== undefined && item.protein_g !== null) ? item.protein_g : (item.protein_100g || 0);
    const carbs = (item.carbs_g !== undefined && item.carbs_g !== null) ? item.carbs_g : (item.carbs_100g || 0);
    const fat = (item.fat_g !== undefined && item.fat_g !== null) ? item.fat_g : (item.fat_100g || 0);
    const img = item.image || item.image_url || '';

    if (foodItemsInput) {
        foodItemsInput.value = item.serving_size ? `${item.serving_size} of ${name}` : name;
    }
    if (pInput) pInput.value = pro;
    if (cInput) cInput.value = carbs;
    if (fInput) fInput.value = fat;
    if (calsInput) calsInput.value = cals;
    if (imgInput) imgInput.value = img;

    if (syncMealType && item.meal_type) {
        selectMealType(item.meal_type);
    }

    if (item.is_online) {
        selectedOnlineFood = item;
        // Reset local dropdown if any was selected
        selectedLocalFoodId = null;
        const triggerLabel = document.getElementById('food_trigger_label');
        const localClearBtn = document.getElementById('food_trigger_clear');
        const typeName = typeSelect ? typeSelect.value : 'dish';
        if (triggerLabel) triggerLabel.textContent = `Select a ${typeName} dish...`;
        if (localClearBtn) localClearBtn.style.display = 'none';
        document.querySelectorAll('.custom-food-item').forEach(el => el.classList.remove('is-selected'));

        // Show the online clear button ONLY for online food
        if (clearOnlineBtn) {
            clearOnlineBtn.style.display = 'inline-flex';
        }
        if (onlineBtnText) {
            onlineBtnText.textContent = `Online: ${name} (${cals} kcal)`;
        }
    } else {
        // Local food selected: ensure online clear button is hidden
        selectedOnlineFood = null;
        if (clearOnlineBtn) {
            clearOnlineBtn.style.display = 'none';
        }
        if (onlineBtnText) {
            onlineBtnText.textContent = 'Search Online (Open Food Facts)';
        }
    }

    if (indicator) {
        if (indicatorText) {
            indicatorText.textContent = `Selected "${name}" (${cals} kcal · ${pro}g P) auto-filled!`;
        }
        indicator.style.display = 'inline-flex';
        setTimeout(() => { indicator.style.display = 'none'; }, 4000);
    }
}

function clearOnlineFoodSelection() {
    selectedOnlineFood = null;
    const foodItemsInput = document.getElementById('meal_food_items');
    const pInput = document.getElementById('calc_p');
    const cInput = document.getElementById('calc_c');
    const fInput = document.getElementById('calc_f');
    const calsInput = document.getElementById('calc_cals');
    const imgInput = document.getElementById('meal_image_url');
    const clearBtn = document.getElementById('btn_clear_online_food');
    const btnText = document.getElementById('online_search_btn_text');
    const indicator = document.getElementById('autofill_indicator');
    const indicatorText = document.getElementById('autofill_indicator_text');

    if (foodItemsInput) foodItemsInput.value = '';
    if (pInput) pInput.value = 0;
    if (cInput) cInput.value = 0;
    if (fInput) fInput.value = 0;
    if (calsInput) calsInput.value = 0;
    if (imgInput) imgInput.value = '';

    if (clearBtn) clearBtn.style.display = 'none';
    if (btnText) btnText.textContent = 'Search Online (Open Food Facts)';

    if (indicator && indicatorText) {
        indicatorText.textContent = 'Online food selection cleared.';
        indicator.style.display = 'inline-flex';
        setTimeout(() => { indicator.style.display = 'none'; }, 2000);
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

    const dayInput = document.getElementById('input_day_of_week');
    if (dayInput) {
        dayInput.value = dayNum;
    }
    const daysMapJs = {1:'Monday', 2:'Tuesday', 3:'Wednesday', 4:'Thursday', 5:'Friday', 6:'Saturday', 7:'Sunday'};
    const dayTriggerLabel = document.getElementById('day_trigger_label');
    const dayBadge = document.getElementById('day_trigger_badge');
    if (dayTriggerLabel && daysMapJs[dayNum]) {
        dayTriggerLabel.textContent = daysMapJs[dayNum];
    }
    if (dayBadge) {
        dayBadge.textContent = 'Day ' + dayNum;
    }
    document.querySelectorAll('#day_custom_menu .custom-select-option').forEach(el => {
        el.classList.toggle('is-selected', parseInt(el.getAttribute('data-day'), 10) === parseInt(dayNum, 10));
    });
}

// Online Food Search Modal Controller
let modalSearchDebounce = null;

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function openOnlineSearchModal() {
    const modal = document.getElementById('modal-online-food-search');
    const input = document.getElementById('modal_online_query');
    const clearBtn = document.getElementById('modal_online_clear_btn');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
        setTimeout(() => {
            if (input) {
                input.focus();
                if (clearBtn) {
                    clearBtn.style.display = input.value.trim().length > 0 ? 'inline-flex' : 'none';
                }
                if (input.value.trim().length >= 2) {
                    executeModalOnlineSearch(input.value.trim());
                }
            }
        }, 100);
    }
}

function closeOnlineSearchModal() {
    const modal = document.getElementById('modal-online-food-search');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('active');
    }
}

function executeModalOnlineSearch(query) {
    const resultsContainer = document.getElementById('modal_online_results');
    const loader = document.getElementById('modal_online_loader');
    if (!resultsContainer) return;

    if (!query || query.length < 2) {
        resultsContainer.innerHTML = `
            <div style="text-align: center; padding: 36px 14px; color: var(--muted); font-size: 13px;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="margin: 0 auto 10px; opacity: 0.5; display: block;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                Type a food or brand name above to search online.
            </div>
        `;
        return;
    }

    if (loader) loader.style.display = 'block';

    const url = 'index.php?page=food_lookup&action=openfoodfacts&query=' + encodeURIComponent(query);

    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (loader) loader.style.display = 'none';
            const items = data.products || [];

            if (items.length === 0) {
                resultsContainer.innerHTML = `
                    <div style="text-align: center; padding: 30px 14px; color: var(--muted); font-size: 13px;">
                        No online products found for "<strong>${escapeHtml(query)}</strong>".
                    </div>
                `;
                return;
            }

            resultsContainer.innerHTML = items.map((item, idx) => {
                const name = item.name || 'Unknown Product';
                const cals = item.calories_100g || 0;
                const pro = item.protein_100g || 0;
                const carbs = item.carbs_100g || 0;
                const fat = item.fat_100g || 0;
                const img = item.image || item.image_url || '';

                return `
                    <div class="modal-online-food-item" data-idx="${idx}" style="padding: 10px 12px; border-radius: 8px; border: 1px solid var(--line); background: var(--bg); cursor: pointer; display: flex; align-items: center; gap: 12px; transition: all 0.15s ease;">
                        ${img ? `<img src="${img}" style="width: 42px; height: 42px; border-radius: 6px; object-fit: cover; flex-shrink: 0; background: var(--panel-soft);" onerror="this.style.display='none'">` : `
                            <div style="width: 42px; height: 42px; border-radius: 6px; background: var(--panel-soft); display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--muted);">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/></svg>
                            </div>
                        `}
                        <div style="flex: 1; min-width: 0;">
                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 6px;">
                                <span style="color: var(--ink); font-size: 13.5px; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(name)}</span>
                                <span style="font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 4px; background: rgba(56, 189, 248, 0.15); color: #38bdf8; white-space: nowrap;">Open Food Facts</span>
                            </div>
                            <div style="display: flex; gap: 8px; font-size: 11.5px; color: var(--diet-muted); margin-top: 3px; align-items: center; flex-wrap: wrap;">
                                <span style="color: var(--diet-accent); font-weight: 700;">${cals} kcal / 100g</span>
                                <span style="opacity: 0.5;">&bull;</span>
                                <span>P: ${pro}g</span>
                                <span>C: ${carbs}g</span>
                                <span>F: ${fat}g</span>
                            </div>
                        </div>
                        <button type="button" style="font-size: 11px; font-weight: 700; padding: 4px 12px; height: auto; min-height: 28px; border-radius: 6px; border: 1px solid var(--diet-accent); background: var(--diet-accent); color: #080b0d; flex-shrink: 0; pointer-events: none;">
                            Select
                        </button>
                    </div>
                `;
            }).join('');

            resultsContainer.querySelectorAll('.modal-online-food-item').forEach((row, i) => {
                row.addEventListener('mouseenter', () => {
                    row.style.borderColor = 'var(--diet-accent)';
                    row.style.background = 'var(--panel-soft)';
                });
                row.addEventListener('mouseleave', () => {
                    row.style.borderColor = 'var(--diet-border)';
                    row.style.background = 'var(--bg)';
                });
                row.addEventListener('click', () => {
                    const chosen = items[i];
                    selectFoodItem({
                        name: chosen.name,
                        serving_size: '100g',
                        calories: chosen.calories_100g || 0,
                        protein_g: chosen.protein_100g || 0,
                        carbs_g: chosen.carbs_100g || 0,
                        fat_g: chosen.fat_100g || 0,
                        image: chosen.image || chosen.image_url || '',
                        is_online: true
                    }, false);
                    closeOnlineSearchModal();
                });
            });
        })
        .catch(() => {
            if (loader) loader.style.display = 'none';
            resultsContainer.innerHTML = '<div style="text-align: center; padding: 24px; color: var(--danger); font-size: 12.5px;">Network error connecting to Open Food Facts. Please try again.</div>';
        });
}

function clearModalOnlineQuery() {
    const input = document.getElementById('modal_online_query');
    const clearBtn = document.getElementById('modal_online_clear_btn');
    if (input) {
        input.value = '';
        input.focus();
    }
    if (clearBtn) {
        clearBtn.style.display = 'none';
    }
    executeModalOnlineSearch('');
}

document.addEventListener("DOMContentLoaded", function() {
    switchDay(1);
    updateMacros();
    updateLocalFoodDropdown();

    const onlineModalInput = document.getElementById('modal_online_query');
    const modalClearBtn = document.getElementById('modal_online_clear_btn');
    if (onlineModalInput) {
        onlineModalInput.addEventListener('input', function() {
            clearTimeout(modalSearchDebounce);
            const val = this.value.trim();
            if (modalClearBtn) {
                modalClearBtn.style.display = val.length > 0 ? 'inline-flex' : 'none';
            }
            modalSearchDebounce = setTimeout(() => {
                executeModalOnlineSearch(val);
            }, 300);
        });
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.custom-food-dropdown-wrap')) {
            closeAllDropdowns();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeOnlineSearchModal();
            closeAllDropdowns();
        }
    });
});
</script>

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
