<?php
declare(strict_types=1);

function log_macros_page(): void
{
    $user = require_roles(['member']);
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('diet');
    }
    
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    $mode = (string) (post('mode') ?: 'add'); // 'add', 'set', or 'reset'
    
    // Validate that inputs are not negative
    $rawCals  = post('calories');
    $rawPro   = post('protein_g');
    $rawCarbs = post('carbs_g');
    $rawFat   = post('fat_g');

    if ($mode !== 'reset') {
        if ((is_numeric($rawCals) && (float)$rawCals < 0) ||
            (is_numeric($rawPro) && (float)$rawPro < 0) ||
            (is_numeric($rawCarbs) && (float)$rawCarbs < 0) ||
            (is_numeric($rawFat) && (float)$rawFat < 0)) {
            if ($isAjax) {
                if (ob_get_level()) ob_clean();
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error'   => 'Calories and macros cannot be negative numbers. Please enter 0 or higher.'
                ]);
                exit;
            }
            flash('Calories and macros cannot be negative numbers.', 'error');
            redirect('diet');
        }
    }

    $addedCals  = max(0, (int) $rawCals);
    $addedPro   = max(0, (float) $rawPro);
    $addedCarbs = max(0, (float) $rawCarbs);
    $addedFat   = max(0, (float) $rawFat);
    
    $userId = (int) $user['user_id'];
    $pdo = db();

    try {
        if ($mode === 'reset') {
            $stmt = $pdo->prepare('INSERT INTO daily_macros (user_id, log_date, calories, protein_g, carbs_g, fat_g) 
                                   VALUES (?, CURDATE(), 0, 0, 0, 0)
                                   ON DUPLICATE KEY UPDATE calories = 0, protein_g = 0, carbs_g = 0, fat_g = 0');
            $stmt->execute([$userId]);
            $calories = $protein = $carbs = $fat = 0;
        } elseif ($mode === 'add') {
            $stmt = $pdo->prepare('INSERT INTO daily_macros (user_id, log_date, calories, protein_g, carbs_g, fat_g) 
                                   VALUES (?, CURDATE(), ?, ?, ?, ?)
                                   ON DUPLICATE KEY UPDATE 
                                       calories = calories + VALUES(calories), 
                                       protein_g = protein_g + VALUES(protein_g), 
                                       carbs_g = carbs_g + VALUES(carbs_g), 
                                       fat_g = fat_g + VALUES(fat_g)');
            $stmt->execute([$userId, $addedCals, $addedPro, $addedCarbs, $addedFat]);

            $curr = $pdo->query("SELECT calories, protein_g, carbs_g, fat_g FROM daily_macros WHERE user_id = {$userId} AND log_date = CURDATE()")->fetch();
            $calories = (int) ($curr['calories'] ?? 0);
            $protein  = (float) ($curr['protein_g'] ?? 0);
            $carbs    = (float) ($curr['carbs_g'] ?? 0);
            $fat      = (float) ($curr['fat_g'] ?? 0);
        } else { // 'set' / replace total
            $calories = $addedCals;
            $protein  = $addedPro;
            $carbs    = $addedCarbs;
            $fat      = $addedFat;

            $stmt = $pdo->prepare('INSERT INTO daily_macros (user_id, log_date, calories, protein_g, carbs_g, fat_g) 
                                   VALUES (?, CURDATE(), ?, ?, ?, ?)
                                   ON DUPLICATE KEY UPDATE 
                                       calories = VALUES(calories), 
                                       protein_g = VALUES(protein_g), 
                                       carbs_g = VALUES(carbs_g), 
                                       fat_g = VALUES(fat_g)');
            $stmt->execute([$userId, $calories, $protein, $carbs, $fat]);
        }
    } catch (Throwable $e) {
        if ($isAjax) {
            if (ob_get_level()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error'   => 'Could not record macros. Please try again.'
            ]);
            exit;
        }
        flash('Could not record macros. Please try again.', 'error');
        redirect('diet');
    }

    if ($isAjax) {
        // Fetch today's targets from active plan
        $targetRow = $pdo->query(
            "SELECT 
                SUM(dpm.calories) as target_cals,
                SUM(dpm.protein_g) as target_pro,
                SUM(dpm.carbs_g) as target_carbs,
                SUM(dpm.fat_g) as target_fat
             FROM dietary_plans dp
             JOIN dietary_plan_meals dpm ON dpm.plan_id = dp.plan_id
             WHERE dp.member_user_id = {$userId} AND dp.status = 'active'
               AND dpm.day_of_week = " . (int) date('N')
        )->fetch();
        
        $targetCals = (int) ($targetRow['target_cals'] ?? 0);
        $targetPro = (int) ($targetRow['target_pro'] ?? 0);
        $targetCarbs = (int) ($targetRow['target_carbs'] ?? 0);
        $targetFat = (int) ($targetRow['target_fat'] ?? 0);

        $pctCals = $targetCals > 0 ? min(100, round(($calories / $targetCals) * 100)) : 0;
        $pctPro = $targetPro > 0 ? min(100, round(($protein / $targetPro) * 100)) : 0;
        $pctCarbs = $targetCarbs > 0 ? min(100, round(($carbs / $targetCarbs) * 100)) : 0;
        $pctFat = $targetFat > 0 ? min(100, round(($fat / $targetFat) * 100)) : 0;

        if (ob_get_level()) ob_clean(); // discard any buffered HTML before JSON
        header('Content-Type: application/json');
        echo json_encode([
            'success'      => true,
            'mode'         => $mode,
            'added_cals'   => $addedCals,
            'logged_cals'  => $calories,
            'logged_pro'   => $protein,
            'logged_carbs' => $carbs,
            'logged_fat'   => $fat,
            'target_cals'  => $targetCals,
            'target_pro'   => $targetPro,
            'target_carbs' => $targetCarbs,
            'target_fat'   => $targetFat,
            'pct_cals'     => $pctCals,
            'pct_pro'      => $pctPro,
            'pct_carbs'    => $pctCarbs,
            'pct_fat'      => $pctFat,
        ]);
        exit;
    }
    
    flash('Macros logged successfully for today!', 'success');
    redirect('diet');
}
