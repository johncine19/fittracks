<?php
declare(strict_types=1);

function log_macros_page(): void
{
    $user = require_roles(['member']);
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('diet');
    }
    
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    $calories = (int) post('calories');
    $protein = (int) post('protein_g');
    $carbs = (int) post('carbs_g');
    $fat = (int) post('fat_g');
    
    $userId = (int) $user['user_id'];
    
    $pdo = db();
    
    $stmt = $pdo->prepare('INSERT INTO daily_macros (user_id, log_date, calories, protein_g, carbs_g, fat_g) 
                           VALUES (?, CURDATE(), ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE calories = VALUES(calories), protein_g = VALUES(protein_g), carbs_g = VALUES(carbs_g), fat_g = VALUES(fat_g)');
    
    $stmt->execute([$userId, $calories, $protein, $carbs, $fat]);

    if ($isAjax) {
        // Fetch today's target from active plan
        $targetRow = $pdo->query(
            "SELECT SUM(dpm.calories) as target_cals
             FROM dietary_plans dp
             JOIN dietary_plan_meals dpm ON dpm.plan_id = dp.plan_id
             WHERE dp.member_user_id = {$userId} AND dp.status = 'active'
               AND dpm.day_of_week = " . (int) date('N')
        )->fetch();
        $targetCals = (int) ($targetRow['target_cals'] ?? 0);
        $pctCals = $targetCals > 0 ? min(100, round(($calories / $targetCals) * 100)) : 0;

        if (ob_get_level()) ob_clean(); // discard any buffered HTML before JSON
        header('Content-Type: application/json');
        echo json_encode([
            'success'      => true,
            'logged_cals'  => $calories,
            'logged_pro'   => $protein,
            'logged_carbs' => $carbs,
            'logged_fat'   => $fat,
            'target_cals'  => $targetCals,
            'pct_cals'     => $pctCals,
        ]);
        exit;
    }
    
    flash('Macros logged successfully for today!', 'success');
    redirect('diet');
}
