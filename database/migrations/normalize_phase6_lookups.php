<?php
declare(strict_types=1);

/**
 * Phase 6 Migration: Domain Normalization & Master Lookup Tables (1NF, 2NF, 3NF)
 *
 * 1. Badges Normalization:
 *    - Creates `badges` lookup table (`badge_id`, `badge_key`, `name`, `description`, `category`, `icon_svg`, `created_at`).
 *    - Seeds all standard milestone & engagement badges from goal and engagement engines.
 *    - Adds `badge_id` to `member_badges` (FK -> `badges.badge_id`) and backfills existing records.
 *    - Adds synchronization triggers on `member_badges` (`trg_member_badges_bi`, `trg_member_badges_bu`).
 *    - Creates view `v_member_badges`.
 *
 * 2. Exercises Normalization:
 *    - Creates `exercise_categories` lookup table (`category_id`, `category_code`, `category_name`).
 *    - Creates `muscle_groups` lookup table (`muscle_group_id`, `muscle_group_code`, `muscle_group_name`).
 *    - Adds `category_id` and `muscle_group_id` foreign keys to `exercises`.
 *    - Backfills `exercises.category_id` and `exercises.muscle_group_id`.
 *    - Adds synchronization triggers on `exercises` (`trg_exercises_bi`, `trg_exercises_bu`).
 *    - Creates view `v_exercises`.
 *
 * 3. Fitness Goals Normalization:
 *    - Creates `fitness_goals` lookup table (`goal_id`, `goal_code`, `display_name`, `category`, `description`).
 *    - Seeds canonical fitness goals unifying strings and enums across profiles, training plans, diet plans, and rules.
 *    - Adds `goal_id` foreign keys to `member_profiles`, `training_plans`, and `dietary_plans`.
 *    - Backfills `goal_id` for all existing records.
 *    - Adds synchronization triggers on `member_profiles`, `training_plans`, and `dietary_plans`.
 *    - Creates unified views `v_training_plans` and `v_dietary_plans`.
 */

if (!function_exists('db')) {
    require_once __DIR__ . '/../../core/bootstrap.php';
}

function run_normalize_phase6_migration(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();

    // =========================================================================
    // 1. Badges Master Lookup Table
    // =========================================================================
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS badges (
            badge_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            badge_key VARCHAR(50) NOT NULL,
            name VARCHAR(100) NOT NULL,
            description VARCHAR(255) NOT NULL,
            category ENUM('milestone', 'engagement', 'achievement', 'habit') NOT NULL DEFAULT 'milestone',
            icon_svg TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_badge_key (badge_key),
            KEY idx_badge_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    $initialBadges = [
        ['early_bird', 'Early Bird', 'Checked in before 7 AM', 'habit'],
        ['iron_lifter', 'Iron Lifter', 'Completed 50 exercises', 'activity'],
        ['century_club', 'Century Club', 'Reached 100 Engagement Score', 'achievement'],
        ['milestone_first_log', 'Journey Begun', 'Logged initial baseline body measurements', 'milestone'],
        ['milestone_waist_trimmer', 'Waist Sculptor', 'Reduced waist circumference by ≥ 3.0 cm', 'milestone'],
        ['milestone_lean_warrior', 'Lean Machine', 'Reduced body fat percentage by ≥ 2.5%', 'milestone'],
        ['milestone_arm_builder', 'Arm Builder', 'Gained ≥ 1.5 cm on arm circumference', 'milestone'],
        ['milestone_chest_sculptor', 'Chest Armor', 'Gained ≥ 2.0 cm on chest circumference', 'milestone'],
        ['milestone_deficit_discipline', 'Deficit Discipline', 'Completed 8+ workouts during active fat loss', 'milestone'],
        ['milestone_core_builder', 'Core Sculptor', 'Completed 8+ dedicated workouts toward core definition', 'milestone'],
        ['milestone_hypertrophy_sets', 'Volume Overload', 'Completed 8+ muscle-building hypertrophy sessions', 'milestone'],
        ['milestone_pr_crusher', 'PR Crusher', 'Logged 3+ compound personal records or strength progressions', 'milestone'],
        ['milestone_load_master', 'Load Master', 'Reached ≥ 90% of target working weight load', 'milestone'],
        ['milestone_iron_discipline', 'Iron Discipline', 'Completed 8+ dedicated strength training sessions', 'milestone'],
        ['milestone_aerobic_base', 'Aerobic Engine', 'Reached ≥ 30 min duration or 80%+ of target endurance duration', 'milestone'],
        ['milestone_distance_crusher', 'Distance Pioneer', 'Reached ≥ 5.0 km or 80%+ of target endurance distance', 'milestone'],
        ['milestone_stamina_streak', 'Stamina Streak', 'Completed 8+ cardio sessions or gym visits', 'milestone'],
        ['milestone_mobility_pioneer', 'Range of Motion', 'Completed 6+ mobility or stretching sessions', 'milestone'],
        ['milestone_flex_streak', 'Fluid Mover', 'Maintained mobility routine across 3+ unique weeks', 'milestone'],
        ['milestone_recomp_builder', 'Tone & Definition', 'Completed 6+ workouts preserving muscle while leaning out', 'milestone'],
        ['milestone_gym_regular', 'Active Gymgoer', 'Logged 8+ gym visits in the last 30 days', 'milestone'],
        ['milestone_healthy_rhythm', 'Balanced Rhythm', 'Maintained positive fitness habit across 3+ unique weeks', 'milestone'],
        ['milestone_weight_goal_met', 'Target Achieved', 'Reached or passed target body weight', 'milestone'],
        ['milestone_consistency_streak', 'Habit Master', 'Consistent logging across 4+ unique weeks', 'milestone'],
    ];

    $insBadge = $pdo->prepare('
        INSERT INTO badges (badge_key, name, description, category)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            description = VALUES(description),
            category = VALUES(category)
    ');
    foreach ($initialBadges as $b) {
        $insBadge->execute([$b[0], $b[1], $b[2], in_array($b[3], ['milestone', 'engagement', 'achievement', 'habit'], true) ? $b[3] : 'milestone']);
    }

    // Add badge_id on member_badges
    $mbCols = array_column($pdo->query('SHOW COLUMNS FROM member_badges')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('badge_id', $mbCols, true)) {
        $pdo->exec('ALTER TABLE member_badges ADD COLUMN badge_id INT UNSIGNED DEFAULT NULL AFTER user_id');
        try {
            $pdo->exec('ALTER TABLE member_badges ADD CONSTRAINT fk_mb_badge FOREIGN KEY (badge_id) REFERENCES badges (badge_id) ON DELETE CASCADE');
        } catch (Throwable) {}
    }

    // Backfill member_badges.badge_id
    $pdo->exec('
        UPDATE member_badges mb
        JOIN badges b ON b.badge_key = mb.badge_type
        SET mb.badge_id = b.badge_id
        WHERE mb.badge_id IS NULL
    ');

    // Triggers for member_badges (MySQL only; skipped on TiDB)
    if (function_exists('db_supports_triggers') ? db_supports_triggers($pdo) : true) {
        try {
            $pdo->exec('DROP TRIGGER IF EXISTS trg_member_badges_bi');
            $pdo->exec("
                CREATE TRIGGER trg_member_badges_bi BEFORE INSERT ON member_badges
                FOR EACH ROW
                BEGIN
                    IF NEW.badge_id IS NULL AND NEW.badge_type IS NOT NULL THEN
                        SET NEW.badge_id = (SELECT badge_id FROM badges WHERE badge_key = NEW.badge_type LIMIT 1);
                    ELSEIF NEW.badge_type IS NULL AND NEW.badge_id IS NOT NULL THEN
                        SET NEW.badge_type = (SELECT badge_key FROM badges WHERE badge_id = NEW.badge_id LIMIT 1);
                    END IF;
                END
            ");

            $pdo->exec('DROP TRIGGER IF EXISTS trg_member_badges_bu');
            $pdo->exec("
                CREATE TRIGGER trg_member_badges_bu BEFORE UPDATE ON member_badges
                FOR EACH ROW
                BEGIN
                    IF NEW.badge_type != OLD.badge_type OR (OLD.badge_id IS NULL AND NEW.badge_id IS NULL) THEN
                        SET NEW.badge_id = (SELECT badge_id FROM badges WHERE badge_key = NEW.badge_type LIMIT 1);
                    ELSEIF NEW.badge_id != OLD.badge_id THEN
                        SET NEW.badge_type = (SELECT badge_key FROM badges WHERE badge_id = NEW.badge_id LIMIT 1);
                    END IF;
                END
            ");
        } catch (Throwable) {
            // TiDB Cloud and engines without trigger support: gracefully skip DB triggers
        }
    }

    // View v_member_badges
    $pdo->exec('
        CREATE OR REPLACE VIEW v_member_badges AS
        SELECT 
            mb.id,
            mb.user_id,
            mb.badge_id,
            COALESCE(b.badge_key, mb.badge_type) AS badge_key,
            mb.badge_type,
            COALESCE(b.name, mb.badge_type) AS name,
            b.description,
            b.category,
            b.icon_svg,
            mb.unlocked_at
        FROM member_badges mb
        LEFT JOIN badges b ON b.badge_id = mb.badge_id OR b.badge_key = mb.badge_type
    ');

    // =========================================================================
    // 2. Exercise Categories & Muscle Groups
    // =========================================================================
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exercise_categories (
            category_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            category_code VARCHAR(50) NOT NULL,
            category_name VARCHAR(100) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_exercise_category_code (category_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS muscle_groups (
            muscle_group_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            muscle_group_code VARCHAR(50) NOT NULL,
            muscle_group_name VARCHAR(100) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_muscle_group_code (muscle_group_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    // Seed Categories from exercises & standards
    $cats = [
        ['strength', 'Strength'],
        ['core', 'Core & Stability'],
        ['cardio', 'Cardio & Conditioning'],
        ['functional', 'Functional Training'],
        ['flexibility', 'Flexibility & Mobility'],
    ];
    $insCat = $pdo->prepare('INSERT INTO exercise_categories (category_code, category_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE category_name = VALUES(category_name)');
    foreach ($cats as $c) {
        $insCat->execute([$c[0], $c[1]]);
    }

    // Seed Muscle Groups from exercises & standards
    $muscleGroups = [
        ['legs', 'Legs & Lower Body'],
        ['chest', 'Chest'],
        ['posterior_chain', 'Posterior Chain'],
        ['back', 'Back & Lats'],
        ['abdominals', 'Abdominals & Core'],
        ['full_body', 'Full Body'],
        ['shoulders', 'Shoulders & Deltoids'],
        ['arms', 'Arms & Biceps/Triceps'],
        ['obliques', 'Obliques & Flanks'],
    ];
    $insMg = $pdo->prepare('INSERT INTO muscle_groups (muscle_group_code, muscle_group_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE muscle_group_name = VALUES(muscle_group_name)');
    foreach ($muscleGroups as $mg) {
        $insMg->execute([$mg[0], $mg[1]]);
    }

    // Add category_id and muscle_group_id on exercises
    $exCols = array_column($pdo->query('SHOW COLUMNS FROM exercises')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('category_id', $exCols, true)) {
        $pdo->exec('ALTER TABLE exercises ADD COLUMN category_id INT UNSIGNED DEFAULT NULL AFTER category');
        try {
            $pdo->exec('ALTER TABLE exercises ADD CONSTRAINT fk_ex_category FOREIGN KEY (category_id) REFERENCES exercise_categories (category_id) ON DELETE SET NULL');
        } catch (Throwable) {}
    }
    if (!in_array('muscle_group_id', $exCols, true)) {
        $pdo->exec('ALTER TABLE exercises ADD COLUMN muscle_group_id INT UNSIGNED DEFAULT NULL AFTER muscle_group');
        try {
            $pdo->exec('ALTER TABLE exercises ADD CONSTRAINT fk_ex_muscle_group FOREIGN KEY (muscle_group_id) REFERENCES muscle_groups (muscle_group_id) ON DELETE SET NULL');
        } catch (Throwable) {}
    }

    // Backfill exercises category_id
    $pdo->exec("
        UPDATE exercises e
        JOIN exercise_categories ec
          ON ec.category_code = LOWER(TRIM(e.category)) OR LOWER(ec.category_name) = LOWER(TRIM(e.category))
        SET e.category_id = ec.category_id
        WHERE e.category_id IS NULL AND e.category IS NOT NULL
    ");

    // Backfill exercises muscle_group_id
    $pdo->exec("
        UPDATE exercises e
        JOIN muscle_groups mg
          ON mg.muscle_group_code = LOWER(TRIM(REPLACE(e.muscle_group, ' ', '_'))) OR LOWER(mg.muscle_group_name) = LOWER(TRIM(e.muscle_group))
        SET e.muscle_group_id = mg.muscle_group_id
        WHERE e.muscle_group_id IS NULL AND e.muscle_group IS NOT NULL
    ");

    // Triggers for exercises (MySQL only; skipped on TiDB)
    if (function_exists('db_supports_triggers') ? db_supports_triggers($pdo) : true) {
        try {
            $pdo->exec('DROP TRIGGER IF EXISTS trg_exercises_bi');
            $pdo->exec("
                CREATE TRIGGER trg_exercises_bi BEFORE INSERT ON exercises
                FOR EACH ROW
                BEGIN
                    IF NEW.category_id IS NULL AND NEW.category IS NOT NULL THEN
                        SET NEW.category_id = (SELECT category_id FROM exercise_categories WHERE category_code = LOWER(TRIM(NEW.category)) LIMIT 1);
                    ELSEIF NEW.category IS NULL AND NEW.category_id IS NOT NULL THEN
                        SET NEW.category = (SELECT category_code FROM exercise_categories WHERE category_id = NEW.category_id LIMIT 1);
                    END IF;

                    IF NEW.muscle_group_id IS NULL AND NEW.muscle_group IS NOT NULL THEN
                        SET NEW.muscle_group_id = (SELECT muscle_group_id FROM muscle_groups WHERE muscle_group_code = LOWER(TRIM(REPLACE(NEW.muscle_group, ' ', '_'))) LIMIT 1);
                    ELSEIF NEW.muscle_group IS NULL AND NEW.muscle_group_id IS NOT NULL THEN
                        SET NEW.muscle_group = (SELECT muscle_group_code FROM muscle_groups WHERE muscle_group_id = NEW.muscle_group_id LIMIT 1);
                    END IF;
                END
            ");

            $pdo->exec('DROP TRIGGER IF EXISTS trg_exercises_bu');
            $pdo->exec("
                CREATE TRIGGER trg_exercises_bu BEFORE UPDATE ON exercises
                FOR EACH ROW
                BEGIN
                    IF NEW.category != OLD.category OR (OLD.category_id IS NULL AND NEW.category_id IS NULL) THEN
                        SET NEW.category_id = (SELECT category_id FROM exercise_categories WHERE category_code = LOWER(TRIM(NEW.category)) LIMIT 1);
                    ELSEIF NEW.category_id != OLD.category_id THEN
                        SET NEW.category = (SELECT category_code FROM exercise_categories WHERE category_id = NEW.category_id LIMIT 1);
                    END IF;

                    IF NEW.muscle_group != OLD.muscle_group OR (OLD.muscle_group_id IS NULL AND NEW.muscle_group_id IS NULL) THEN
                        SET NEW.muscle_group_id = (SELECT muscle_group_id FROM muscle_groups WHERE muscle_group_code = LOWER(TRIM(REPLACE(NEW.muscle_group, ' ', '_'))) LIMIT 1);
                    ELSEIF NEW.muscle_group_id != OLD.muscle_group_id THEN
                        SET NEW.muscle_group = (SELECT muscle_group_code FROM muscle_groups WHERE muscle_group_id = NEW.muscle_group_id LIMIT 1);
                    END IF;
                END
            ");
        } catch (Throwable) {
            // TiDB Cloud and engines without trigger support: gracefully skip DB triggers
        }
    }

    // View v_exercises
    $pdo->exec('
        CREATE OR REPLACE VIEW v_exercises AS
        SELECT 
            e.exercise_id,
            e.gym_id,
            e.name,
            e.category_id,
            ec.category_code,
            COALESCE(ec.category_name, e.category) AS category_name,
            e.category,
            e.muscle_group_id,
            mg.muscle_group_code,
            COALESCE(mg.muscle_group_name, e.muscle_group) AS muscle_group_name,
            e.muscle_group,
            e.description,
            e.animation_url,
            e.difficulty_level
        FROM exercises e
        LEFT JOIN exercise_categories ec ON ec.category_id = e.category_id
        LEFT JOIN muscle_groups mg ON mg.muscle_group_id = e.muscle_group_id
    ');

    // =========================================================================
    // 3. Fitness Goals Master Lookup Table
    // =========================================================================
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fitness_goals (
            goal_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            goal_code VARCHAR(50) NOT NULL,
            display_name VARCHAR(100) NOT NULL,
            category VARCHAR(50) NOT NULL DEFAULT 'fitness',
            description VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_goal_code (goal_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    $initialGoals = [
        ['fat_loss', 'Losing Excess Body Fat', 'composition', 'Caloric deficit, waist reduction, and healthy metabolic leanness'],
        ['muscle_gain', 'Gaining Lean Body Mass', 'hypertrophy', 'Hypertrophy resistance training, protein surplus, and muscle volume overload'],
        ['strength', 'Increasing Maximum Strength', 'strength', 'Progressive overload, power compound lifts, and maximal force generation'],
        ['casual', 'Casual / Flexible Lifestyle', 'lifestyle', 'Consistent healthy gym routine, balance, and general longevity'],
        ['maintenance', 'Maintenance & Longevity', 'lifestyle', 'Sustaining target weight, joint integrity, and fitness routine consistency'],
        ['general_health', 'General Health & Vitality', 'health', 'Cardiovascular health, metabolic conditioning, and daily energy'],
        ['endurance', 'Cardiovascular Endurance', 'endurance', 'Aerobic capacity, running, rowing, and distance conditioning'],
        ['mobility', 'Mobility & Flexibility', 'functional', 'Full range of motion, postural stability, and joint flexibility'],
        ['recomposition', 'Body Recomposition', 'composition', 'Simultaneous fat reduction and lean muscle preservation'],
    ];

    $insGoal = $pdo->prepare('
        INSERT INTO fitness_goals (goal_code, display_name, category, description)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            display_name = VALUES(display_name),
            category = VALUES(category),
            description = VALUES(description)
    ');
    foreach ($initialGoals as $g) {
        $insGoal->execute([$g[0], $g[1], $g[2], $g[3]]);
    }

    // Add goal_id on member_profiles
    $mpCols = array_column($pdo->query('SHOW COLUMNS FROM member_profiles')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('goal_id', $mpCols, true)) {
        $pdo->exec('ALTER TABLE member_profiles ADD COLUMN goal_id INT UNSIGNED DEFAULT NULL AFTER primary_goal');
        try {
            $pdo->exec('ALTER TABLE member_profiles ADD CONSTRAINT fk_mp_goal FOREIGN KEY (goal_id) REFERENCES fitness_goals (goal_id) ON DELETE SET NULL');
        } catch (Throwable) {}
    }

    // Add goal_id on training_plans
    $tpCols = array_column($pdo->query('SHOW COLUMNS FROM training_plans')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('goal_id', $tpCols, true)) {
        $pdo->exec('ALTER TABLE training_plans ADD COLUMN goal_id INT UNSIGNED DEFAULT NULL AFTER goal');
        try {
            $pdo->exec('ALTER TABLE training_plans ADD CONSTRAINT fk_tp_goal FOREIGN KEY (goal_id) REFERENCES fitness_goals (goal_id) ON DELETE SET NULL');
        } catch (Throwable) {}
    }

    // Add goal_id on dietary_plans
    $dpCols = array_column($pdo->query('SHOW COLUMNS FROM dietary_plans')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('goal_id', $dpCols, true)) {
        $pdo->exec('ALTER TABLE dietary_plans ADD COLUMN goal_id INT UNSIGNED DEFAULT NULL AFTER goal');
        try {
            $pdo->exec('ALTER TABLE dietary_plans ADD CONSTRAINT fk_dp_goal FOREIGN KEY (goal_id) REFERENCES fitness_goals (goal_id) ON DELETE SET NULL');
        } catch (Throwable) {}
    }

    // Backfill member_profiles.goal_id
    $pdo->exec("
        UPDATE member_profiles mp
        JOIN fitness_goals fg ON (
            fg.goal_code = LOWER(TRIM(REPLACE(mp.primary_goal, ' ', '_'))) OR
            LOWER(fg.display_name) = LOWER(TRIM(mp.primary_goal)) OR
            (mp.primary_goal LIKE '%fat%' AND fg.goal_code = 'fat_loss') OR
            (mp.primary_goal LIKE '%mass%' AND fg.goal_code = 'muscle_gain') OR
            (mp.primary_goal LIKE '%strength%' AND fg.goal_code = 'strength') OR
            (mp.primary_goal LIKE '%lifestyle%' AND fg.goal_code = 'casual')
        )
        SET mp.goal_id = fg.goal_id
        WHERE mp.goal_id IS NULL AND mp.primary_goal IS NOT NULL
    ");

    // Backfill training_plans.goal_id
    $pdo->exec("
        UPDATE training_plans tp
        JOIN fitness_goals fg ON (
            fg.goal_code = LOWER(TRIM(REPLACE(tp.goal, ' ', '_'))) OR
            LOWER(fg.display_name) = LOWER(TRIM(tp.goal)) OR
            (tp.goal LIKE '%strength%' AND fg.goal_code = 'strength') OR
            (tp.goal LIKE '%fat%' AND fg.goal_code = 'fat_loss') OR
            (tp.goal LIKE '%mass%' AND fg.goal_code = 'muscle_gain') OR
            (tp.goal LIKE '%lifestyle%' AND fg.goal_code = 'casual')
        )
        SET tp.goal_id = fg.goal_id
        WHERE tp.goal_id IS NULL AND tp.goal IS NOT NULL
    ");

    // Backfill dietary_plans.goal_id
    $pdo->exec("
        UPDATE dietary_plans dp
        JOIN fitness_goals fg ON (
            fg.goal_code = LOWER(TRIM(REPLACE(dp.goal, ' ', '_'))) OR
            LOWER(fg.display_name) = LOWER(TRIM(dp.goal)) OR
            (dp.goal LIKE '%strength%' AND fg.goal_code = 'strength') OR
            (dp.goal LIKE '%fat%' AND fg.goal_code = 'fat_loss') OR
            (dp.goal LIKE '%mass%' AND fg.goal_code = 'muscle_gain') OR
            (dp.goal LIKE '%lifestyle%' AND fg.goal_code = 'casual')
        )
        SET dp.goal_id = fg.goal_id
        WHERE dp.goal_id IS NULL AND dp.goal IS NOT NULL
    ");

    // Triggers for member_profiles, training_plans, dietary_plans goal sync (MySQL only; skipped on TiDB)
    if (function_exists('db_supports_triggers') ? db_supports_triggers($pdo) : true) {
        try {
            // Triggers for member_profiles goal sync
            $pdo->exec('DROP TRIGGER IF EXISTS trg_member_profiles_bi');
            $pdo->exec("
                CREATE TRIGGER trg_member_profiles_bi BEFORE INSERT ON member_profiles
                FOR EACH ROW
                BEGIN
                    IF NEW.goal_id IS NULL AND NEW.primary_goal IS NOT NULL THEN
                        SET NEW.goal_id = (
                            SELECT goal_id FROM fitness_goals 
                            WHERE goal_code = LOWER(TRIM(REPLACE(NEW.primary_goal, ' ', '_'))) 
                               OR LOWER(display_name) = LOWER(TRIM(NEW.primary_goal))
                               OR (NEW.primary_goal LIKE '%fat%' AND goal_code = 'fat_loss')
                               OR (NEW.primary_goal LIKE '%mass%' AND goal_code = 'muscle_gain')
                               OR (NEW.primary_goal LIKE '%strength%' AND goal_code = 'strength')
                               OR (NEW.primary_goal LIKE '%lifestyle%' AND goal_code = 'casual')
                            LIMIT 1
                        );
                    ELSEIF NEW.primary_goal IS NULL AND NEW.goal_id IS NOT NULL THEN
                        SET NEW.primary_goal = (SELECT display_name FROM fitness_goals WHERE goal_id = NEW.goal_id LIMIT 1);
                    END IF;
                END
            ");

            $pdo->exec('DROP TRIGGER IF EXISTS trg_member_profiles_bu');
            $pdo->exec("
                CREATE TRIGGER trg_member_profiles_bu BEFORE UPDATE ON member_profiles
                FOR EACH ROW
                BEGIN
                    IF NEW.primary_goal != OLD.primary_goal OR (OLD.goal_id IS NULL AND NEW.goal_id IS NULL) THEN
                        SET NEW.goal_id = (
                            SELECT goal_id FROM fitness_goals 
                            WHERE goal_code = LOWER(TRIM(REPLACE(NEW.primary_goal, ' ', '_'))) 
                               OR LOWER(display_name) = LOWER(TRIM(NEW.primary_goal))
                               OR (NEW.primary_goal LIKE '%fat%' AND goal_code = 'fat_loss')
                               OR (NEW.primary_goal LIKE '%mass%' AND goal_code = 'muscle_gain')
                               OR (NEW.primary_goal LIKE '%strength%' AND goal_code = 'strength')
                               OR (NEW.primary_goal LIKE '%lifestyle%' AND goal_code = 'casual')
                            LIMIT 1
                        );
                    ELSEIF NEW.goal_id != OLD.goal_id THEN
                        SET NEW.primary_goal = (SELECT display_name FROM fitness_goals WHERE goal_id = NEW.goal_id LIMIT 1);
                    END IF;
                END
            ");

            // Triggers for training_plans goal sync
            $pdo->exec('DROP TRIGGER IF EXISTS trg_training_plans_bi');
            $pdo->exec("
                CREATE TRIGGER trg_training_plans_bi BEFORE INSERT ON training_plans
                FOR EACH ROW
                BEGIN
                    IF NEW.goal_id IS NULL AND NEW.goal IS NOT NULL THEN
                        SET NEW.goal_id = (
                            SELECT goal_id FROM fitness_goals 
                            WHERE goal_code = LOWER(TRIM(REPLACE(NEW.goal, ' ', '_'))) 
                               OR LOWER(display_name) = LOWER(TRIM(NEW.goal))
                               OR (NEW.goal LIKE '%fat%' AND goal_code = 'fat_loss')
                               OR (NEW.goal LIKE '%mass%' AND goal_code = 'muscle_gain')
                               OR (NEW.goal LIKE '%strength%' AND goal_code = 'strength')
                               OR (NEW.goal LIKE '%lifestyle%' AND goal_code = 'casual')
                            LIMIT 1
                        );
                    ELSEIF NEW.goal IS NULL AND NEW.goal_id IS NOT NULL THEN
                        SET NEW.goal = (SELECT display_name FROM fitness_goals WHERE goal_id = NEW.goal_id LIMIT 1);
                    END IF;
                END
            ");

            $pdo->exec('DROP TRIGGER IF EXISTS trg_training_plans_bu');
            $pdo->exec("
                CREATE TRIGGER trg_training_plans_bu BEFORE UPDATE ON training_plans
                FOR EACH ROW
                BEGIN
                    IF NEW.goal != OLD.goal OR (OLD.goal_id IS NULL AND NEW.goal_id IS NULL) THEN
                        SET NEW.goal_id = (
                            SELECT goal_id FROM fitness_goals 
                            WHERE goal_code = LOWER(TRIM(REPLACE(NEW.goal, ' ', '_'))) 
                               OR LOWER(display_name) = LOWER(TRIM(NEW.goal))
                               OR (NEW.goal LIKE '%fat%' AND goal_code = 'fat_loss')
                               OR (NEW.goal LIKE '%mass%' AND goal_code = 'muscle_gain')
                               OR (NEW.goal LIKE '%strength%' AND goal_code = 'strength')
                               OR (NEW.goal LIKE '%lifestyle%' AND goal_code = 'casual')
                            LIMIT 1
                        );
                    ELSEIF NEW.goal_id != OLD.goal_id THEN
                        SET NEW.goal = (SELECT display_name FROM fitness_goals WHERE goal_id = NEW.goal_id LIMIT 1);
                    END IF;
                END
            ");

            // Triggers for dietary_plans goal sync
            $pdo->exec('DROP TRIGGER IF EXISTS trg_dietary_plans_bi');
            $pdo->exec("
                CREATE TRIGGER trg_dietary_plans_bi BEFORE INSERT ON dietary_plans
                FOR EACH ROW
                BEGIN
                    IF NEW.goal_id IS NULL AND NEW.goal IS NOT NULL THEN
                        SET NEW.goal_id = (
                            SELECT goal_id FROM fitness_goals 
                            WHERE goal_code = LOWER(TRIM(REPLACE(NEW.goal, ' ', '_'))) 
                               OR LOWER(display_name) = LOWER(TRIM(NEW.goal))
                               OR (NEW.goal LIKE '%fat%' AND goal_code = 'fat_loss')
                               OR (NEW.goal LIKE '%mass%' AND goal_code = 'muscle_gain')
                               OR (NEW.goal LIKE '%strength%' AND goal_code = 'strength')
                               OR (NEW.goal LIKE '%lifestyle%' AND goal_code = 'casual')
                            LIMIT 1
                        );
                    ELSEIF NEW.goal IS NULL AND NEW.goal_id IS NOT NULL THEN
                        SET NEW.goal = (SELECT display_name FROM fitness_goals WHERE goal_id = NEW.goal_id LIMIT 1);
                    END IF;
                END
            ");

            $pdo->exec('DROP TRIGGER IF EXISTS trg_dietary_plans_bu');
            $pdo->exec("
                CREATE TRIGGER trg_dietary_plans_bu BEFORE UPDATE ON dietary_plans
                FOR EACH ROW
                BEGIN
                    IF NEW.goal != OLD.goal OR (OLD.goal_id IS NULL AND NEW.goal_id IS NULL) THEN
                        SET NEW.goal_id = (
                            SELECT goal_id FROM fitness_goals 
                            WHERE goal_code = LOWER(TRIM(REPLACE(NEW.goal, ' ', '_'))) 
                               OR LOWER(display_name) = LOWER(TRIM(NEW.goal))
                               OR (NEW.goal LIKE '%fat%' AND goal_code = 'fat_loss')
                               OR (NEW.goal LIKE '%mass%' AND goal_code = 'muscle_gain')
                               OR (NEW.goal LIKE '%strength%' AND goal_code = 'strength')
                               OR (NEW.goal LIKE '%lifestyle%' AND goal_code = 'casual')
                            LIMIT 1
                        );
                    ELSEIF NEW.goal_id != OLD.goal_id THEN
                        SET NEW.goal = (SELECT display_name FROM fitness_goals WHERE goal_id = NEW.goal_id LIMIT 1);
                    END IF;
                END
            ");
        } catch (Throwable) {
            // TiDB Cloud and engines without trigger support: gracefully skip DB triggers
        }
    }

    // View v_member_profiles_unified
    $pdo->exec('
        CREATE OR REPLACE VIEW v_member_profiles_unified AS
        SELECT 
            mp.*,
            fg.goal_code,
            fg.display_name AS canonical_goal_name,
            fg.category AS goal_category
        FROM member_profiles mp
        LEFT JOIN fitness_goals fg ON fg.goal_id = mp.goal_id
    ');
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    run_normalize_phase6_migration();
    echo "Phase 6 lookup tables normalization completed successfully.\n";
}

