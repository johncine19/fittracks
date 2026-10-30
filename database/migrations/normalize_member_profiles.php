<?php
declare(strict_types=1);

/**
 * Phase 1 Migration: Normalize member_profiles (1NF, 2NF, 3NF)
 *
 * Splits the 35-column member_profiles table into:
 *  - member_profiles (13 core columns: profile_id, user_id, birth_date, biological_sex, height_cm, activity_level, primary_goal, weekly_workout_target, preferred_duration_mins, fitness_tier, completed_weeks, created_at, updated_at)
 *  - progress_logs (with neck_cm added to consolidate historical body measurements without overwriting)
 *  - member_goals (goal_id, user_id, goal_type, exercise_or_activity, current_value, target_value, unit, updated_at)
 *  - focus_areas + member_focus_areas (1NF fix for secondary_focus)
 *  - dietary_restrictions + member_dietary_restrictions (1NF fix for dietary_restrictions)
 *  - member_workout_days (1NF fix for preferred_workout_days)
 *  - trainer_assessments (ownership separation for trainer_assessment_notes)
 */

if (!function_exists('db')) {
    require_once __DIR__ . '/../../core/bootstrap.php';
}

function run_normalize_member_profiles_migration(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();

    // 1. Create normalized lookup & junction tables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS focus_areas (
            focus_area_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS member_focus_areas (
            user_id INT UNSIGNED NOT NULL,
            focus_area_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, focus_area_id),
            KEY idx_mfa_area (focus_area_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dietary_restrictions (
            restriction_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS member_dietary_restrictions (
            user_id INT UNSIGNED NOT NULL,
            restriction_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, restriction_id),
            KEY idx_mdr_restriction (restriction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS member_workout_days (
            user_id INT UNSIGNED NOT NULL,
            day_of_week TINYINT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, day_of_week)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS member_goals (
            goal_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            goal_type VARCHAR(50) NOT NULL,
            exercise_or_activity VARCHAR(100) DEFAULT NULL,
            current_value DECIMAL(8,2) DEFAULT NULL,
            target_value DECIMAL(8,2) DEFAULT NULL,
            unit VARCHAR(20) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_goal_type (user_id, goal_type),
            KEY idx_mg_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS trainer_assessments (
            assessment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            member_user_id INT UNSIGNED NOT NULL,
            trainer_id INT UNSIGNED DEFAULT NULL,
            notes TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ta_member (member_user_id),
            KEY idx_ta_trainer (trainer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Seed standard dietary restrictions & focus areas
    $defaultRestrictions = ['none', 'vegetarian', 'vegan', 'keto', 'paleo', 'gluten_free', 'dairy_free', 'halal', 'pescatarian', 'low_carb'];
    $insRest = $pdo->prepare('INSERT IGNORE INTO dietary_restrictions (name) VALUES (?)');
    foreach ($defaultRestrictions as $rName) {
        $insRest->execute([$rName]);
    }

    $defaultFocusAreas = [
        'Building a visible six-pack',
        'Growing larger biceps and arms',
        'Developing a wide chest',
        'Sculpting a V-tapered back',
        'Shaping the lower body',
        'Increasing maximum strength',
        'Boosting explosive power',
        'Enhancing physical endurance',
        'Improving body flexibility',
        'Losing excess body fat',
        'Gaining lean body mass',
        'Reaching body recomposition',
        'Active Health & Longevity',
        'Stress Relief & Functional Movement',
        'Casual / Flexible Lifestyle',
        'Six-Pack Abs & Core Definition',
        'Biceps, Triceps & Arms',
    ];
    $insFocus = $pdo->prepare('INSERT IGNORE INTO focus_areas (name) VALUES (?)');
    foreach ($defaultFocusAreas as $fName) {
        $insFocus->execute([$fName]);
    }

    // 2. Ensure progress_logs has neck_cm
    $plCols = array_column($pdo->query('SHOW COLUMNS FROM progress_logs')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('neck_cm', $plCols, true)) {
        $pdo->exec('ALTER TABLE progress_logs ADD COLUMN neck_cm DECIMAL(5,2) DEFAULT NULL AFTER body_fat_percent');
    }

    // 3. Ensure member_profiles has birth_date
    $mpCols = array_column($pdo->query('SHOW COLUMNS FROM member_profiles')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('birth_date', $mpCols, true)) {
        $pdo->exec('ALTER TABLE member_profiles ADD COLUMN birth_date DATE DEFAULT NULL AFTER user_id');
    }

    // 4. If legacy columns still exist on member_profiles, migrate their data before dropping
    if (in_array('weight_kg', $mpCols, true) || in_array('age', $mpCols, true) || in_array('preferred_workout_days', $mpCols, true)) {
        $rows = $pdo->query('SELECT * FROM member_profiles')->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $uid = (int) $row['user_id'];

            // 4a. Migrate age -> birth_date
            if (empty($row['birth_date']) && !empty($row['age']) && (int)$row['age'] > 0) {
                $ageYears = max(10, min(120, (int)$row['age']));
                $birthYear = (int)date('Y') - $ageYears;
                $birthDate = sprintf('%04d-01-01', $birthYear);
                $pdo->prepare('UPDATE member_profiles SET birth_date = ? WHERE user_id = ? AND birth_date IS NULL')
                    ->execute([$birthDate, $uid]);
            }

            // 4b. Migrate body measurements into progress_logs
            $w = isset($row['weight_kg']) ? (float)$row['weight_kg'] : 0.0;
            $neck = !empty($row['neck_cm']) ? (float)$row['neck_cm'] : null;
            $waist = !empty($row['waist_cm']) ? (float)$row['waist_cm'] : null;
            $hips = !empty($row['hip_cm']) ? (float)$row['hip_cm'] : null;
            $chest = !empty($row['chest_cm']) ? (float)$row['chest_cm'] : null;
            $arm = !empty($row['arm_cm']) ? (float)$row['arm_cm'] : null;
            $height = (float)($row['height_cm'] ?? 0);
            $sex = (string)($row['biological_sex'] ?? 'male');

            $bf = null;
            if ($height > 0 && $neck !== null && $neck > 0 && $waist !== null && $waist > 0) {
                if ($sex !== 'female' && $waist > $neck) {
                    $calc = 495 / (1.0324 - 0.19077 * log10($waist - $neck) + 0.15456 * log10($height)) - 450;
                    if ($calc >= 3 && $calc <= 65) {
                        $bf = round($calc, 1);
                    }
                } elseif ($sex === 'female' && $hips !== null && $hips > 0 && ($waist + $hips) > $neck) {
                    $calc = 495 / (1.29579 - 0.35004 * log10($waist + $hips - $neck) + 0.22100 * log10($height)) - 450;
                    if ($calc >= 3 && $calc <= 65) {
                        $bf = round($calc, 1);
                    }
                }
            }

            if ($w > 0 || $neck !== null || $waist !== null || $hips !== null || $chest !== null || $arm !== null) {
                $latestStmt = $pdo->prepare('SELECT log_id FROM progress_logs WHERE user_id = ? ORDER BY log_date DESC, log_id DESC LIMIT 1');
                $latestStmt->execute([$uid]);
                $latestLogId = (int)$latestStmt->fetchColumn();

                if ($latestLogId > 0) {
                    $pdo->prepare('
                        UPDATE progress_logs SET
                            weight_kg = IF(weight_kg IS NULL OR weight_kg = 0, ?, weight_kg),
                            body_fat_percent = COALESCE(body_fat_percent, ?),
                            neck_cm = COALESCE(neck_cm, ?),
                            waist_cm = COALESCE(waist_cm, ?),
                            hips_cm = COALESCE(hips_cm, ?),
                            chest_cm = COALESCE(chest_cm, ?),
                            arm_cm = COALESCE(arm_cm, ?)
                        WHERE log_id = ?
                    ')->execute([$w > 0 ? $w : 70.0, $bf, $neck, $waist, $hips, $chest, $arm, $latestLogId]);
                } else {
                    $logDate = !empty($row['created_at']) ? substr((string)$row['created_at'], 0, 10) : date('Y-m-d');
                    $pdo->prepare('
                        INSERT INTO progress_logs (user_id, log_date, weight_kg, body_fat_percent, neck_cm, chest_cm, waist_cm, hips_cm, arm_cm, notes, recorded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ')->execute([$uid, $logDate, $w > 0 ? $w : 70.0, $bf, $neck, $chest, $waist, $hips, $arm, 'Initial profile baseline', $uid]);
                }
            }

            // 4c. Migrate preferred_workout_days -> member_workout_days
            if (!empty($row['preferred_workout_days'])) {
                $days = array_filter(array_map('intval', explode(',', (string)$row['preferred_workout_days'])), fn($d) => $d >= 1 && $d <= 7);
                $insDay = $pdo->prepare('INSERT IGNORE INTO member_workout_days (user_id, day_of_week) VALUES (?, ?)');
                foreach (array_unique($days) as $d) {
                    $insDay->execute([$uid, $d]);
                }
            }

            // 4d. Migrate secondary_focus -> focus_areas + member_focus_areas
            if (!empty($row['secondary_focus']) && strtolower(trim((string)$row['secondary_focus'])) !== 'none') {
                $rawFocus = str_replace('Biceps, Triceps & Arms', 'Biceps__COMMA__ Triceps & Arms', (string)$row['secondary_focus']);
                $parts = array_filter(array_map('trim', explode(',', $rawFocus)));
                foreach ($parts as $part) {
                    $areaName = str_replace('Biceps__COMMA__ Triceps & Arms', 'Biceps, Triceps & Arms', $part);
                    if ($areaName === '' || strtolower($areaName) === 'none') {
                        continue;
                    }
                    $pdo->prepare('INSERT IGNORE INTO focus_areas (name) VALUES (?)')->execute([$areaName]);
                    $areaIdStmt = $pdo->prepare('SELECT focus_area_id FROM focus_areas WHERE name = ? LIMIT 1');
                    $areaIdStmt->execute([$areaName]);
                    $areaId = (int)$areaIdStmt->fetchColumn();
                    if ($areaId > 0) {
                        $pdo->prepare('INSERT IGNORE INTO member_focus_areas (user_id, focus_area_id) VALUES (?, ?)')->execute([$uid, $areaId]);
                    }
                }
            }

            // 4e. Migrate dietary_restrictions -> dietary_restrictions + member_dietary_restrictions
            if (!empty($row['dietary_restrictions'])) {
                $parts = array_filter(array_map('trim', explode(',', (string)$row['dietary_restrictions'])));
                foreach ($parts as $part) {
                    if ($part === '') continue;
                    $pdo->prepare('INSERT IGNORE INTO dietary_restrictions (name) VALUES (?)')->execute([$part]);
                    $restIdStmt = $pdo->prepare('SELECT restriction_id FROM dietary_restrictions WHERE name = ? LIMIT 1');
                    $restIdStmt->execute([$part]);
                    $restId = (int)$restIdStmt->fetchColumn();
                    if ($restId > 0) {
                        $pdo->prepare('INSERT IGNORE INTO member_dietary_restrictions (user_id, restriction_id) VALUES (?, ?)')->execute([$uid, $restId]);
                    }
                }
            }

            // 4f. Migrate goals -> member_goals
            $upsertGoal = $pdo->prepare('
                INSERT INTO member_goals (user_id, goal_type, exercise_or_activity, current_value, target_value, unit)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    exercise_or_activity = COALESCE(VALUES(exercise_or_activity), exercise_or_activity),
                    current_value = COALESCE(VALUES(current_value), current_value),
                    target_value = COALESCE(VALUES(target_value), target_value),
                    unit = VALUES(unit)
            ');

            if (!empty($row['target_weight_kg'])) {
                $upsertGoal->execute([$uid, 'weight', null, $w > 0 ? $w : null, (float)$row['target_weight_kg'], 'kg']);
            }
            if (!empty($row['target_body_fat_percent'])) {
                $upsertGoal->execute([$uid, 'body_fat', null, $bf, (float)$row['target_body_fat_percent'], '%']);
            }
            if (!empty($row['target_waist_cm'])) {
                $upsertGoal->execute([$uid, 'waist', null, $waist, (float)$row['target_waist_cm'], 'cm']);
            }
            if (!empty($row['target_arm_cm'])) {
                $upsertGoal->execute([$uid, 'arm', null, $arm, (float)$row['target_arm_cm'], 'cm']);
            }
            if (!empty($row['target_chest_cm'])) {
                $upsertGoal->execute([$uid, 'chest', null, $chest, (float)$row['target_chest_cm'], 'cm']);
            }
            if (!empty($row['target_exercise']) || !empty($row['current_strength_max_kg']) || !empty($row['target_strength_max_kg'])) {
                $upsertGoal->execute([
                    $uid,
                    'strength',
                    !empty($row['target_exercise']) ? (string)$row['target_exercise'] : null,
                    !empty($row['current_strength_max_kg']) ? (float)$row['current_strength_max_kg'] : null,
                    !empty($row['target_strength_max_kg']) ? (float)$row['target_strength_max_kg'] : null,
                    'kg',
                ]);
            }
            if (!empty($row['endurance_activity']) || !empty($row['current_endurance_distance_km']) || !empty($row['target_endurance_distance_km'])) {
                $upsertGoal->execute([
                    $uid,
                    'endurance_distance',
                    !empty($row['endurance_activity']) ? (string)$row['endurance_activity'] : null,
                    !empty($row['current_endurance_distance_km']) ? (float)$row['current_endurance_distance_km'] : null,
                    !empty($row['target_endurance_distance_km']) ? (float)$row['target_endurance_distance_km'] : null,
                    'km',
                ]);
            }
            if (!empty($row['current_endurance_time_mins']) || !empty($row['target_endurance_time_mins'])) {
                $upsertGoal->execute([
                    $uid,
                    'endurance_time',
                    !empty($row['endurance_activity']) ? (string)$row['endurance_activity'] : null,
                    !empty($row['current_endurance_time_mins']) ? (float)$row['current_endurance_time_mins'] : null,
                    !empty($row['target_endurance_time_mins']) ? (float)$row['target_endurance_time_mins'] : null,
                    'mins',
                ]);
            }

            // 4g. Migrate trainer_assessment_notes -> trainer_assessments
            if (!empty($row['trainer_assessment_notes']) && trim((string)$row['trainer_assessment_notes']) !== '') {
                $existingAssess = $pdo->prepare('SELECT assessment_id FROM trainer_assessments WHERE member_user_id = ? LIMIT 1');
                $existingAssess->execute([$uid]);
                if (!$existingAssess->fetchColumn()) {
                    $trainerIdStmt = $pdo->prepare('SELECT trainer_id FROM trainer_assignments WHERE member_user_id = ? ORDER BY (status = "active") DESC, assignment_id DESC LIMIT 1');
                    $trainerIdStmt->execute([$uid]);
                    $trainerId = $trainerIdStmt->fetchColumn() ?: null;

                    $pdo->prepare('INSERT INTO trainer_assessments (member_user_id, trainer_id, notes) VALUES (?, ?, ?)')
                        ->execute([$uid, $trainerId ? (int)$trainerId : null, trim((string)$row['trainer_assessment_notes'])]);
                }
            }
        }

        // 5. Drop the 23 denormalized columns from member_profiles
        $colsToDrop = [
            'weight_kg',
            'neck_cm',
            'waist_cm',
            'hip_cm',
            'chest_cm',
            'arm_cm',
            'age',
            'secondary_focus',
            'dietary_restrictions',
            'preferred_workout_days',
            'target_weight_kg',
            'target_body_fat_percent',
            'target_arm_cm',
            'target_chest_cm',
            'target_waist_cm',
            'target_exercise',
            'current_strength_max_kg',
            'target_strength_max_kg',
            'endurance_activity',
            'current_endurance_distance_km',
            'current_endurance_time_mins',
            'target_endurance_distance_km',
            'target_endurance_time_mins',
            'trainer_assessment_notes',
        ];

        $dropClauses = [];
        foreach ($colsToDrop as $col) {
            if (in_array($col, $mpCols, true)) {
                $dropClauses[] = "DROP COLUMN `{$col}`";
            }
        }

        if (!empty($dropClauses)) {
            $pdo->exec('ALTER TABLE member_profiles ' . implode(', ', $dropClauses));
        }
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    run_normalize_member_profiles_migration();
    echo "Member profiles normalization migration completed successfully.\n";
}

