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
    $profile = $pdo->query('SELECT primary_goal, weight_kg FROM member_profiles WHERE user_id = ' . $memberId)->fetch();
    
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
        // Create empty draft
        $goal = $profile['primary_goal'] ?? 'general_health';
        $title = 'Diet Plan for ' . $member['first_name'];
        $stmt = $pdo->prepare('INSERT INTO dietary_plans (member_user_id, trainer_id, title, goal, status) VALUES (?, ?, ?, ?, "draft")');
        $stmt->execute([$memberId, $trainerId, $title, $goal]);
        $planId = (int) $pdo->lastInsertId();
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
                        <input type="number" name="calories" min="0" value="0">
                    </label>
                    <label>Protein (g)
                        <input type="number" name="protein_g" min="0" value="0">
                    </label>
                    <label>Carbs (g)
                        <input type="number" name="carbs_g" min="0" value="0">
                    </label>
                    <label>Fat (g)
                        <input type="number" name="fat_g" min="0" value="0">
                    </label>
                </div>
                
                <button class="btn btn-primary" style="margin-top: 10px; width: 100%;">Add Meal</button>
            </form>
        </section>
    </div>
    
    <!-- Week View -->
    <div style="flex: 2; display: flex; flex-direction: column; gap: 20px;">
        <?php foreach ($daysMap as $dayNum => $dayName): ?>
            <div class="panel" style="margin: 0; padding: 15px;">
                <h3 style="margin-top: 0; border-bottom: 1px solid var(--line); padding-bottom: 10px; margin-bottom: 10px;"><?= $dayName ?></h3>
                
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
<?php
    render_footer();
}
