<?php
declare(strict_types=1);

function diet_page(): void
{
    $user = require_roles(['member']);
    $pdo = db();
    $userId = (int) $user['user_id'];
    
    // Fetch active diet plan
    $plan = $pdo->prepare('SELECT dp.*, u.first_name as t_first, u.last_name as t_last FROM dietary_plans dp JOIN trainer_profiles tp ON tp.trainer_id = dp.trainer_id JOIN users u ON u.user_id = tp.user_id WHERE dp.member_user_id = ? AND dp.status = "active" ORDER BY dp.plan_id DESC LIMIT 1');
    $plan->execute([$userId]);
    $activePlan = $plan->fetch();

    render_header('My Diet Plan', $user);
    
    if (!$activePlan) {
        echo '<div class="panel" style="text-align: center; padding: 50px 20px;">
                <h2 style="color: var(--muted); margin-bottom: 10px;">No Active Diet Plan</h2>
                <p>You currently do not have an active diet plan assigned by a trainer.</p>
              </div>';
        render_footer();
        return;
    }
    
    $planId = (int) $activePlan['plan_id'];
    $trainerName = h($activePlan['t_first'] . ' ' . $activePlan['t_last']);
    
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
<div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom: 20px;">
    <div>
        <h1 style="margin: 0 0 5px 0;">My Diet Plan</h1>
        <p class="muted" style="margin: 0;">Goal: <?= h(ucwords(str_replace('_', ' ', $activePlan['goal']))) ?> | Assigned by: <?= $trainerName ?></p>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
    <?php foreach ($daysMap as $dayNum => $dayName): ?>
        <div class="panel plan-card-glow" style="margin: 0; padding: 20px;">
            <h3 style="margin-top: 0; border-bottom: 2px solid var(--lime); padding-bottom: 10px; margin-bottom: 15px; color: var(--lime); font-size: 1.3rem;">
                <?= $dayName ?>
            </h3>
            
            <?php if (empty($mealsByDay[$dayNum])): ?>
                <p class="muted" style="margin:0; text-align: center; font-style: italic;">No meals specified.</p>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 15px;">
                    <?php 
                    $dayCals = 0; $dayPro = 0; $dayCarbs = 0; $dayFat = 0;
                    foreach ($mealsByDay[$dayNum] as $meal): 
                        $dayCals += $meal['calories'];
                        $dayPro += $meal['protein_g'];
                        $dayCarbs += $meal['carbs_g'];
                        $dayFat += $meal['fat_g'];
                    ?>
                        <div style="background: color-mix(in srgb, var(--surface) 50%, var(--bg)); padding: 15px; border-radius: 8px; border: 1px solid var(--line);">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                <strong style="color: var(--ink); font-size: 1.1rem;"><?= h($meal['meal_type']) ?></strong>
                                <span style="background: var(--lime); color: var(--bg); padding: 3px 8px; border-radius: 20px; font-size: 0.8rem; font-weight: bold;">
                                    <?= $meal['calories'] ?> kcal
                                </span>
                            </div>
                            
                            <div style="color: var(--muted); font-size: 0.95rem; margin-bottom: 10px; line-height: 1.5;">
                                <?= nl2br(h($meal['food_items'])) ?>
                            </div>
                            
                            <div style="display: flex; gap: 15px; font-size: 0.85rem; color: var(--ink); background: var(--bg); padding: 8px; border-radius: 6px;">
                                <div><strong>P:</strong> <?= $meal['protein_g'] ?>g</div>
                                <div><strong>C:</strong> <?= $meal['carbs_g'] ?>g</div>
                                <div><strong>F:</strong> <?= $meal['fat_g'] ?>g</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <!-- Daily Totals -->
                    <div style="margin-top: 5px; padding: 15px; background: rgba(163, 230, 53, 0.1); border: 1px solid rgba(163, 230, 53, 0.2); border-radius: 8px; text-align: center;">
                        <div style="font-size: 0.85rem; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px;">Daily Totals</div>
                        <div style="font-size: 1.1rem; font-weight: bold; color: var(--lime);">
                            <?= $dayCals ?> kcal
                        </div>
                        <div style="display: flex; justify-content: center; gap: 15px; margin-top: 5px; font-size: 0.9rem;">
                            <span>Pro: <?= $dayPro ?>g</span>
                            <span>Carbs: <?= $dayCarbs ?>g</span>
                            <span>Fat: <?= $dayFat ?>g</span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php
    render_footer();
}
