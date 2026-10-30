<?php
declare(strict_types=1);

/**
 * Phase 4 Migration: Normalize meal, food items, and nutrition tables
 *
 * 1. meal_foods junction table:
 *    - Resolves dietary_plan_meals.food_items text list into atomic food references (meal_id, food_id, servings, item macros)
 * 2. member_meal_log_foods junction table:
 *    - Resolves member_meal_logs.food_items text list into atomic food references (log_id, food_id, servings, item macros)
 * 3. food_dietary_restrictions junction table:
 *    - Normalizes food_items.dietary_restriction string into junction table linked to dietary_restrictions(restriction_id)
 * 4. food_ingredients relational table:
 *    - Normalizes food_items.ingredients JSON text into atomic 1NF rows (ingredient_id, food_id, name, amount, unit, type, display_order)
 * 5. v_daily_meal_macros View:
 *    - Provides a dynamic computed view of daily macro sums directly aggregated from member_meal_logs
 */

if (!function_exists('db')) {
    require_once __DIR__ . '/../../core/bootstrap.php';
}

function run_normalize_phase4_migration(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();

    // =========================================================================
    // 1. Create Normalized Tables & View
    // =========================================================================
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS meal_foods (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            meal_id INT UNSIGNED NOT NULL,
            food_id INT DEFAULT NULL,
            food_name VARCHAR(255) NOT NULL,
            servings DECIMAL(5,2) NOT NULL DEFAULT 1.00,
            calories INT UNSIGNED NOT NULL DEFAULT 0,
            protein_g DECIMAL(6,1) NOT NULL DEFAULT 0.0,
            carbs_g DECIMAL(6,1) NOT NULL DEFAULT 0.0,
            fat_g DECIMAL(6,1) NOT NULL DEFAULT 0.0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_mf_meal (meal_id),
            KEY idx_mf_food (food_id),
            CONSTRAINT fk_mf_meal FOREIGN KEY (meal_id) REFERENCES dietary_plan_meals (meal_id) ON DELETE CASCADE,
            CONSTRAINT fk_mf_food FOREIGN KEY (food_id) REFERENCES food_items (food_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS member_meal_log_foods (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            log_id INT UNSIGNED NOT NULL,
            food_id INT DEFAULT NULL,
            food_name VARCHAR(255) NOT NULL,
            servings DECIMAL(5,2) NOT NULL DEFAULT 1.00,
            calories INT UNSIGNED NOT NULL DEFAULT 0,
            protein_g DECIMAL(6,1) NOT NULL DEFAULT 0.0,
            carbs_g DECIMAL(6,1) NOT NULL DEFAULT 0.0,
            fat_g DECIMAL(6,1) NOT NULL DEFAULT 0.0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_mmlf_log (log_id),
            KEY idx_mmlf_food (food_id),
            CONSTRAINT fk_mmlf_log FOREIGN KEY (log_id) REFERENCES member_meal_logs (log_id) ON DELETE CASCADE,
            CONSTRAINT fk_mmlf_food FOREIGN KEY (food_id) REFERENCES food_items (food_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS food_dietary_restrictions (
            food_id INT NOT NULL,
            restriction_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (food_id, restriction_id),
            KEY idx_fdr_restriction (restriction_id),
            CONSTRAINT fk_fdr_food FOREIGN KEY (food_id) REFERENCES food_items (food_id) ON DELETE CASCADE,
            CONSTRAINT fk_fdr_restriction FOREIGN KEY (restriction_id) REFERENCES dietary_restrictions (restriction_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS food_ingredients (
            ingredient_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            food_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            amount VARCHAR(50) DEFAULT NULL,
            unit VARCHAR(50) DEFAULT NULL,
            type ENUM('main','optional') NOT NULL DEFAULT 'main',
            display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_fi_food (food_id),
            CONSTRAINT fk_fi_food FOREIGN KEY (food_id) REFERENCES food_items (food_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    // Dynamic aggregated view of daily meal macros
    try {
        $pdo->exec("
            CREATE OR REPLACE VIEW v_daily_meal_macros AS
            SELECT 
                user_id,
                log_date,
                COUNT(*) AS logged_meals_count,
                COALESCE(SUM(calories), 0) AS calculated_calories,
                COALESCE(SUM(protein_g), 0.0) AS calculated_protein_g,
                COALESCE(SUM(carbs_g), 0.0) AS calculated_carbs_g,
                COALESCE(SUM(fat_g), 0.0) AS calculated_fat_g
            FROM member_meal_logs
            GROUP BY user_id, log_date
        ");
    } catch (Throwable) {}

    // =========================================================================
    // 2. Backfill food_dietary_restrictions from food_items
    // =========================================================================
    $restrictionsMap = [];
    try {
        $rRows = $pdo->query('SELECT restriction_id, name FROM dietary_restrictions')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rRows as $r) {
            $norm = strtolower(str_replace(['-', ' '], '_', trim((string)$r['name'])));
            $restrictionsMap[$norm] = (int)$r['restriction_id'];
        }
    } catch (Throwable) {}

    $foods = $pdo->query('SELECT food_id, dietary_restriction FROM food_items')->fetchAll(PDO::FETCH_ASSOC);
    $insFdr = $pdo->prepare('INSERT IGNORE INTO food_dietary_restrictions (food_id, restriction_id) VALUES (?, ?)');
    foreach ($foods as $f) {
        $fid = (int)$f['food_id'];
        $rawRest = strtolower(str_replace(['-', ' '], '_', trim((string)($f['dietary_restriction'] ?? 'none'))));
        if (isset($restrictionsMap[$rawRest])) {
            $insFdr->execute([$fid, $restrictionsMap[$rawRest]]);
        } elseif (isset($restrictionsMap['none'])) {
            $insFdr->execute([$fid, $restrictionsMap['none']]);
        }
    }

    // =========================================================================
    // 3. Backfill food_ingredients from food_items.ingredients JSON
    // =========================================================================
    $foodIngRows = $pdo->query("SELECT food_id, ingredients FROM food_items WHERE ingredients IS NOT NULL AND ingredients != ''")->fetchAll(PDO::FETCH_ASSOC);
    $insIng = $pdo->prepare('INSERT INTO food_ingredients (food_id, name, amount, unit, type, display_order) VALUES (?, ?, ?, ?, ?, ?)');
    $checkExistingIng = $pdo->prepare('SELECT COUNT(*) FROM food_ingredients WHERE food_id = ?');

    foreach ($foodIngRows as $fiRow) {
        $fid = (int)$fiRow['food_id'];
        $checkExistingIng->execute([$fid]);
        if ((int)$checkExistingIng->fetchColumn() > 0) {
            continue; // Already migrated
        }

        $decoded = json_decode((string)$fiRow['ingredients'], true);
        if (is_array($decoded)) {
            $order = 1;
            foreach ($decoded as $item) {
                if (!empty($item['name'])) {
                    $iName = trim((string)$item['name']);
                    $iAmount = !empty($item['amount']) ? trim((string)$item['amount']) : null;
                    $iUnit = !empty($item['unit']) ? trim((string)$item['unit']) : null;
                    $iType = (!empty($item['type']) && strtolower((string)$item['type']) === 'optional') ? 'optional' : 'main';
                    $insIng->execute([$fid, $iName, $iAmount, $iUnit, $iType, $order++]);
                }
            }
        }
    }

    // =========================================================================
    // 4. Backfill meal_foods from dietary_plan_meals
    // =========================================================================
    $mealFoodsCount = (int)$pdo->query('SELECT COUNT(*) FROM meal_foods')->fetchColumn();
    if ($mealFoodsCount === 0) {
        // Build food name -> food_id lookup dictionary
        $allLibraryFoods = $pdo->query('SELECT food_id, name, calories, protein_g, carbs_g, fat_g FROM food_items')->fetchAll(PDO::FETCH_ASSOC);
        $foodLookup = [];
        foreach ($allLibraryFoods as $lf) {
            $foodLookup[strtolower(trim((string)$lf['name']))] = $lf;
        }

        $allPlanMeals = $pdo->query('SELECT meal_id, food_items, calories, protein_g, carbs_g, fat_g FROM dietary_plan_meals')->fetchAll(PDO::FETCH_ASSOC);
        $insMf = $pdo->prepare('INSERT INTO meal_foods (meal_id, food_id, food_name, servings, calories, protein_g, carbs_g, fat_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

        foreach ($allPlanMeals as $pm) {
            $mid = (int)$pm['meal_id'];
            $rawText = trim((string)($pm['food_items'] ?? ''));
            if ($rawText === '') {
                continue;
            }

            // Extract portion if present, e.g. "475g of Ginisang Munggo (Vegan) with Brown Rice"
            $cleanedName = $rawText;
            $servings = 1.00;
            if (preg_match('/^(\d+)g\s+of\s+(.+)$/i', $rawText, $m)) {
                $cleanedName = trim($m[2]);
            }

            $matchedFoodId = null;
            $lowerClean = strtolower($cleanedName);
            if (isset($foodLookup[$lowerClean])) {
                $matchedFoodId = (int)$foodLookup[$lowerClean]['food_id'];
            } else {
                foreach ($foodLookup as $fNameLower => $fMeta) {
                    if (stripos($cleanedName, $fNameLower) !== false || stripos($fNameLower, $cleanedName) !== false) {
                        $matchedFoodId = (int)$fMeta['food_id'];
                        break;
                    }
                }
            }

            $insMf->execute([
                $mid,
                $matchedFoodId,
                $cleanedName,
                $servings,
                (int)$pm['calories'],
                (float)$pm['protein_g'],
                (float)$pm['carbs_g'],
                (float)$pm['fat_g'],
            ]);
        }
    }

    // =========================================================================
    // 5. Backfill member_meal_log_foods from member_meal_logs
    // =========================================================================
    $logFoodsCount = (int)$pdo->query('SELECT COUNT(*) FROM member_meal_log_foods')->fetchColumn();
    if ($logFoodsCount === 0) {
        $allMemberLogs = $pdo->query('SELECT log_id, meal_id, food_items, calories, protein_g, carbs_g, fat_g FROM member_meal_logs')->fetchAll(PDO::FETCH_ASSOC);
        $insMmlf = $pdo->prepare('INSERT INTO member_meal_log_foods (log_id, food_id, food_name, servings, calories, protein_g, carbs_g, fat_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

        foreach ($allMemberLogs as $ml) {
            $lid = (int)$ml['log_id'];
            $rawText = trim((string)($ml['food_items'] ?? ''));
            if ($rawText === '') {
                continue;
            }

            $cleanedName = $rawText;
            $servings = 1.00;
            if (preg_match('/^(\d+)g\s+of\s+(.+)$/i', $rawText, $m)) {
                $cleanedName = trim($m[2]);
            }

            $matchedFoodId = null;
            if (!empty($ml['meal_id'])) {
                $stmtFoodId = $pdo->prepare('SELECT food_id FROM meal_foods WHERE meal_id = ? LIMIT 1');
                $stmtFoodId->execute([(int)$ml['meal_id']]);
                $mfFoodId = $stmtFoodId->fetchColumn();
                if ($mfFoodId) {
                    $matchedFoodId = (int)$mfFoodId;
                }
            }

            $insMmlf->execute([
                $lid,
                $matchedFoodId,
                $cleanedName,
                $servings,
                (int)$ml['calories'],
                (float)$ml['protein_g'],
                (float)$ml['carbs_g'],
                (float)$ml['fat_g'],
            ]);
        }
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    run_normalize_phase4_migration();
    echo "Phase 4 food and meal normalization migration completed successfully.\n";
}

