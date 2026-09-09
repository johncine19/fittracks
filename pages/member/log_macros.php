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

    // ----------------------------------------------------
    // GUARD RAILS: Only Allow Today & One Log per Meal Type
    // ----------------------------------------------------
    $mealId = post('meal_id') ? (int) post('meal_id') : null;
    $rawMealType = (string) (post('meal_type') ?: '');
    $dayNum = post('day_of_week') ? (int) post('day_of_week') : null;
    $foodItems = (string) (post('food_items') ?: '');

    $planMeal = null;
    if ($mealId) {
        $stmtPlanMeal = $pdo->prepare(
            "SELECT dpm.meal_id, dpm.plan_id, dpm.day_of_week, dpm.meal_type, dpm.food_items, dpm.calories, dpm.protein_g, dpm.carbs_g, dpm.fat_g 
             FROM dietary_plan_meals dpm 
             JOIN dietary_plans dp ON dp.plan_id = dpm.plan_id 
             WHERE dpm.meal_id = ? AND dp.member_user_id = ? AND dp.status = 'active'"
        );
        $stmtPlanMeal->execute([$mealId, $userId]);
        $planMeal = $stmtPlanMeal->fetch();
        if ($planMeal) {
            $dayNum = (int) $planMeal['day_of_week'];
            if (empty($rawMealType)) {
                $rawMealType = $planMeal['meal_type'];
            }
            if (empty($foodItems)) {
                $foodItems = $planMeal['food_items'];
            }
        }
    }

    // Guard 1: Only Allow Logging for the Current Date
    $todayDayOfWeek = (int) date('N'); // 1 = Monday, 7 = Sunday
    if ($dayNum !== null && $dayNum !== $todayDayOfWeek) {
        $dayNames = [1=>'Monday', 2=>'Tuesday', 3=>'Wednesday', 4=>'Thursday', 5=>'Friday', 6=>'Saturday', 7=>'Sunday'];
        $todayName = $dayNames[$todayDayOfWeek] ?? 'today';
        $errMsg = "You can only log meals scheduled for today ({$todayName}). Past and upcoming scheduled days are view-only.";
        if ($isAjax) {
            if (ob_get_level()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'can_log_today_only' => true,
                'error' => $errMsg
            ]);
            exit;
        }
        flash($errMsg, 'warning');
        redirect('diet');
    }

    // Guard 2: Prevent Duplicate Meal Logging (1 Log Per Meal Type Per Day)
    $normalizedType = '';
    if (!empty($rawMealType) && $mode === 'add') {
        $normalizedType = ucfirst(strtolower(trim($rawMealType)));
        if (strpos(strtolower($normalizedType), 'snack') !== false) {
            $normalizedType = 'Snack';
        }

        $stmtCheckLog = $pdo->prepare(
            "SELECT log_id, logged_at FROM member_meal_logs WHERE user_id = ? AND meal_type = ? AND log_date = CURDATE()"
        );
        $stmtCheckLog->execute([$userId, $normalizedType]);
        $existingLog = $stmtCheckLog->fetch();

        if ($existingLog) {
            $loggedTime = date('g:i A', strtotime($existingLog['logged_at']));
            $errMsg = "{$normalizedType} already logged today at {$loggedTime}. You can log {$normalizedType} again tomorrow.";
            if ($isAjax) {
                if (ob_get_level()) ob_clean();
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'already_logged' => true,
                    'meal_type' => $normalizedType,
                    'logged_at' => $existingLog['logged_at'],
                    'logged_time' => $loggedTime,
                    'error' => $errMsg
                ]);
                exit;
            }
            flash($errMsg, 'warning');
            redirect('diet');
        }
    }

    $loggedAt = null;
    $loggedTime = null;

    try {
        if ($mode === 'reset') {
            $stmt = $pdo->prepare('INSERT INTO daily_macros (user_id, log_date, calories, protein_g, carbs_g, fat_g) 
                                   VALUES (?, CURDATE(), 0, 0, 0, 0)
                                   ON DUPLICATE KEY UPDATE calories = 0, protein_g = 0, carbs_g = 0, fat_g = 0');
            $stmt->execute([$userId]);
            // Clear today's meal logs on reset so member can re-log if needed
            $pdo->prepare('DELETE FROM member_meal_logs WHERE user_id = ? AND log_date = CURDATE()')->execute([$userId]);
            $calories = $protein = $carbs = $fat = 0;
        } elseif ($mode === 'add') {
            // Save specific meal slot to member_meal_logs
            if (!empty($normalizedType)) {
                $stmtInsertMeal = $pdo->prepare(
                    "INSERT INTO member_meal_logs 
                     (user_id, meal_type, log_date, meal_id, food_items, calories, protein_g, carbs_g, fat_g, logged_at) 
                     VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, NOW())"
                );
                $stmtInsertMeal->execute([
                    $userId,
                    $normalizedType,
                    $mealId ?: null,
                    $foodItems ?: null,
                    $addedCals,
                    $addedPro,
                    $addedCarbs,
                    $addedFat
                ]);
                $loggedAt = date('Y-m-d H:i:s');
                $loggedTime = date('g:i A');
            }

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
        // If DB duplicate key error
        if ($e instanceof PDOException && ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false)) {
            $timeFormatted = date('g:i A');
            if ($isAjax) {
                if (ob_get_level()) ob_clean();
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'already_logged' => true,
                    'meal_type' => $normalizedType,
                    'error' => "{$normalizedType} already logged today. You can log {$normalizedType} again tomorrow."
                ]);
                exit;
            }
        }
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
            'meal_type'    => $normalizedType,
            'meal_id'      => $mealId,
            'logged_at'    => $loggedAt ?? date('Y-m-d H:i:s'),
            'logged_time'  => $loggedTime ?? date('g:i A'),
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
