<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';

// Security: Restrict migration execution strictly to CLI or an authorized secret token
$isCli = (php_sapi_name() === 'cli');
$secretKey = (string) (app_env('MIGRATION_SECRET') ?: (getenv('MIGRATION_SECRET') ?: ''));
$providedKey = (string) ($_GET['secret'] ?? '');

if (!$isCli && (empty($secretKey) || !hash_equals($secretKey, $providedKey))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Access Denied: Migrations can only be executed via the CLI (e.g. 'php migrate.php') or with a valid MIGRATION_SECRET.\n");
}

echo "<h1>Running Database Migrations...</h1>";
echo "<ul>";

try {
    $pdo = db();
    
    $migrations = [
        "ALTER TABLE gyms ADD COLUMN logo_url VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE gyms ADD COLUMN brand_color VARCHAR(10) DEFAULT NULL",
        "ALTER TABLE member_profiles ADD COLUMN target_weight_kg DECIMAL(5,2) DEFAULT NULL",
        "ALTER TABLE member_profiles ADD COLUMN target_body_fat_percent DECIMAL(5,2) DEFAULT NULL",
        "ALTER TABLE member_profiles ADD COLUMN target_arm_cm DECIMAL(5,2) DEFAULT NULL",
        "ALTER TABLE member_profiles ADD COLUMN target_chest_cm DECIMAL(5,2) DEFAULT NULL",
        "ALTER TABLE member_profiles ADD COLUMN target_waist_cm DECIMAL(5,2) DEFAULT NULL",
        "ALTER TABLE member_profiles ADD COLUMN target_exercise VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE member_profiles ADD COLUMN current_strength_max_kg DECIMAL(5,2) DEFAULT NULL",
        "ALTER TABLE member_profiles ADD COLUMN target_strength_max_kg DECIMAL(5,2) DEFAULT NULL",
        "ALTER TABLE member_profiles ADD COLUMN endurance_activity VARCHAR(50) DEFAULT NULL",
        "ALTER TABLE member_profiles ADD COLUMN current_endurance_distance_km DECIMAL(5,2) DEFAULT NULL",
        "ALTER TABLE member_profiles ADD COLUMN current_endurance_time_mins INT DEFAULT NULL",
        "ALTER TABLE member_profiles ADD COLUMN target_endurance_distance_km DECIMAL(5,2) DEFAULT NULL",
        "ALTER TABLE member_profiles ADD COLUMN target_endurance_time_mins INT DEFAULT NULL",
        "ALTER TABLE member_profiles ADD COLUMN weekly_workout_target TINYINT UNSIGNED DEFAULT NULL",
        "ALTER TABLE member_profiles ADD COLUMN preferred_duration_mins SMALLINT UNSIGNED DEFAULT 45",
        "ALTER TABLE training_plan_exercises ADD COLUMN target_weight_kg DECIMAL(6,2) DEFAULT NULL",
        "ALTER TABLE training_plan_exercises ADD COLUMN tempo VARCHAR(20) DEFAULT NULL",
        "ALTER TABLE training_plan_exercises ADD COLUMN rpe VARCHAR(20) DEFAULT NULL",
        "CREATE TABLE IF NOT EXISTS checkout_ratings (rating_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, attendance_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, rating TINYINT UNSIGNED NOT NULL, comment TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_attendance (attendance_id))",
        "CREATE TABLE IF NOT EXISTS workout_rules (rule_id int UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, experience_level int NOT NULL DEFAULT '1', biological_sex enum('male','female','any') NOT NULL DEFAULT 'any', primary_goal enum('fat_loss','muscle_gain','maintenance','general_health') NOT NULL, activity_level enum('sedentary','lightly_active','moderately_active','very_active','extra_active','any') NOT NULL DEFAULT 'any', recommended_workout_structure text NOT NULL)",
        "CREATE TABLE IF NOT EXISTS diet_rules (rule_id int UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, experience_level int NOT NULL DEFAULT '1', biological_sex enum('male','female','any') NOT NULL DEFAULT 'any', primary_goal enum('fat_loss','muscle_gain','maintenance','general_health') NOT NULL, activity_level enum('sedentary','lightly_active','moderately_active','very_active','extra_active','any') NOT NULL DEFAULT 'any', macro_split varchar(255) NOT NULL, notes text)",
        "INSERT IGNORE INTO workout_rules (rule_id, experience_level, biological_sex, primary_goal, activity_level, recommended_workout_structure) VALUES (1, 1, 'any', 'fat_loss', 'any', '3 days Full Body Resistance (Machine focused), 2 days Moderate Cardio (30 mins)')",
        "INSERT IGNORE INTO diet_rules (rule_id, experience_level, biological_sex, primary_goal, activity_level, macro_split, notes) VALUES (1, 1, 'any', 'fat_loss', 'any', '40% Protein, 30% Carbs, 30% Fat', 'Maintain a 300-500 calorie deficit.')",
        "UPDATE gyms SET subscription_renewal_date = DATE_ADD(CURDATE(), INTERVAL 1 MONTH) WHERE status = 'approved' AND subscription_status = 'active' AND subscription_renewal_date > DATE_ADD(CURDATE(), INTERVAL 2 MONTH)",
        "CREATE TABLE IF NOT EXISTS member_meal_logs (log_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, meal_type VARCHAR(50) NOT NULL, log_date DATE NOT NULL, meal_id INT UNSIGNED NULL, food_items TEXT NULL, calories INT UNSIGNED NOT NULL DEFAULT 0, protein_g DECIMAL(6,1) NOT NULL DEFAULT 0, carbs_g DECIMAL(6,1) NOT NULL DEFAULT 0, fat_g DECIMAL(6,1) NOT NULL DEFAULT 0, logged_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_user_meal_date (user_id, meal_type, log_date), INDEX idx_user_date (user_id, log_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS gym_equipment (equipment_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, gym_id INT UNSIGNED NOT NULL, name VARCHAR(150) NOT NULL, unit_number VARCHAR(50) NOT NULL DEFAULT '#1', category ENUM('Cardio', 'Strength', 'Free Weights', 'Machines', 'Functional Training', 'Other') NOT NULL DEFAULT 'Machines', location_area VARCHAR(100) DEFAULT 'Main Gym Floor', description TEXT DEFAULT NULL, equipment_condition ENUM('Excellent', 'Good', 'Fair', 'Poor') NOT NULL DEFAULT 'Good', status ENUM('available', 'in_use', 'maintenance', 'out_of_service') NOT NULL DEFAULT 'available', image_url VARCHAR(255) DEFAULT NULL, date_added DATE NOT NULL, last_maintenance_date DATE DEFAULT NULL, next_maintenance_date DATE DEFAULT NULL, maintenance_reason VARCHAR(255) DEFAULT NULL, expected_return_date DATE DEFAULT NULL, current_session_id INT UNSIGNED DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_gym_equip_unit (gym_id, name, unit_number), INDEX idx_gym_status (gym_id, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS equipment_sessions (session_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, gym_id INT UNSIGNED NOT NULL, equipment_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, start_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, end_time TIMESTAMP NULL DEFAULT NULL, duration_seconds INT UNSIGNED DEFAULT 0, session_status ENUM('active', 'completed', 'cancelled') NOT NULL DEFAULT 'active', INDEX idx_gym_equipment (gym_id, equipment_id), INDEX idx_user_status (user_id, session_status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS equipment_queues (queue_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, gym_id INT UNSIGNED NOT NULL, equipment_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, queue_position INT UNSIGNED NOT NULL DEFAULT 1, queue_status ENUM('waiting', 'notified', 'claimed', 'cancelled', 'expired') NOT NULL DEFAULT 'waiting', joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, notified_at TIMESTAMP NULL DEFAULT NULL, claim_deadline TIMESTAMP NULL DEFAULT NULL, resolved_at TIMESTAMP NULL DEFAULT NULL, INDEX idx_user_active_queue (user_id, equipment_id, queue_status), INDEX idx_equip_queue_pos (equipment_id, queue_status, queue_position)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS equipment_maintenance_logs (log_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, equipment_id INT UNSIGNED NOT NULL, gym_id INT UNSIGNED NOT NULL, logged_by_user_id INT UNSIGNED NOT NULL, action VARCHAR(50) NOT NULL, reason VARCHAR(255) DEFAULT NULL, expected_return_date DATE DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_equip_logs (equipment_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE INDEX idx_att_checkout_checkin ON attendance (check_out_time, check_in_time)",
        "CREATE INDEX idx_notif_created_read ON notifications (is_read, created_at)",
        "CREATE INDEX idx_notif_created ON notifications (created_at)",
        "CREATE INDEX idx_notif_cooldown ON notifications (user_id, type, title, created_at)",
        "CREATE INDEX idx_equip_queue_gym_status ON equipment_queues (gym_id, queue_status, equipment_id)",
        "CREATE TABLE IF NOT EXISTS gym_ratings (rating_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, gym_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, rating TINYINT UNSIGNED NOT NULL, review TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_member_gym_rating (user_id, gym_id), INDEX idx_gym_ratings_gym (gym_id), INDEX idx_gym_ratings_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS platform_reviews (review_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, gym_id INT UNSIGNED DEFAULT NULL, rating TINYINT UNSIGNED NOT NULL, review TEXT DEFAULT NULL, system_experience TINYINT UNSIGNED DEFAULT NULL, features_rating TINYINT UNSIGNED DEFAULT NULL, service_rating TINYINT UNSIGNED DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_owner_platform_review (user_id), INDEX idx_platform_rating (rating)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "ALTER TABLE users ADD COLUMN google_id VARCHAR(100) DEFAULT NULL AFTER user_id",
        "ALTER TABLE users ADD UNIQUE KEY uq_users_google_id (google_id)",
        "ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NULL",
        "ALTER TABLE gyms ADD COLUMN staff_hide_financials TINYINT(1) DEFAULT 0",
        "ALTER TABLE gyms ADD COLUMN eod_email_summary TINYINT(1) DEFAULT 1",
        "ALTER TABLE gyms ADD COLUMN last_eod_email_sent_at TIMESTAMP NULL DEFAULT NULL AFTER eod_email_summary",
        "ALTER TABLE gyms ADD COLUMN last_eod_email_sent_by INT UNSIGNED NULL DEFAULT NULL AFTER last_eod_email_sent_at",
        "CREATE TABLE IF NOT EXISTS gym_eod_email_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, gym_id INT UNSIGNED NOT NULL, report_date DATE NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'queued', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_gym_eod_email_date (gym_id, report_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "ALTER TABLE users ADD COLUMN staff_role VARCHAR(30) DEFAULT NULL",
        "ALTER TABLE users MODIFY COLUMN role ENUM('platform_admin','gym_owner','staff','trainer','member') NOT NULL",
        "CREATE TABLE IF NOT EXISTS gym_staff (staff_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, gym_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, staff_title VARCHAR(50) DEFAULT 'Front Desk Staff', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_gym_staff_user (user_id), INDEX idx_gym_staff_gym (gym_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "ALTER TABLE admin_audit_logs ADD COLUMN gym_id INT UNSIGNED DEFAULT NULL AFTER admin_user_id",
        "ALTER TABLE admin_audit_logs ADD INDEX idx_audit_logs_gym (gym_id)",
        "UPDATE admin_audit_logs a JOIN gym_staff gs ON gs.user_id = a.admin_user_id SET a.gym_id = gs.gym_id WHERE a.gym_id IS NULL",
        "UPDATE admin_audit_logs a JOIN gyms g ON g.owner_user_id = a.admin_user_id SET a.gym_id = g.gym_id WHERE a.gym_id IS NULL",
        "UPDATE admin_audit_logs a JOIN trainer_profiles tp ON tp.user_id = a.admin_user_id SET a.gym_id = tp.gym_id WHERE a.gym_id IS NULL",
        "ALTER TABLE gym_subscription_payments MODIFY payment_method ENUM('gcash','card','bank_transfer','cash','online') NOT NULL DEFAULT 'gcash'",
        "ALTER TABLE gym_subscription_payments ADD COLUMN xendit_reference_id VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE gym_subscription_payments ADD COLUMN xendit_session_id VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE gym_subscription_payments ADD COLUMN xendit_payment_id VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE gyms ADD COLUMN xendit_subaccount_id VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN xendit_customer_id VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE payments ADD COLUMN xendit_reference_id VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE payments ADD COLUMN xendit_session_id VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE payments ADD COLUMN xendit_payment_id VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE gym_subscription_payments ADD UNIQUE KEY uq_sub_xendit_reference (xendit_reference_id)",
        "ALTER TABLE gym_subscription_payments ADD UNIQUE KEY uq_sub_xendit_session (xendit_session_id)",
        "ALTER TABLE payments ADD UNIQUE KEY uq_payment_xendit_reference (xendit_reference_id)",
        "ALTER TABLE payments ADD UNIQUE KEY uq_payment_xendit_session (xendit_session_id)",
        "CREATE INDEX idx_att_gym_checkin ON attendance (gym_id, check_in_time)",
        "CREATE INDEX idx_att_user_checkin ON attendance (user_id, check_in_time)",
        "CREATE INDEX idx_payments_membership ON payments (membership_id)",
        "CREATE INDEX idx_payments_status_date ON payments (status, payment_date)",
        "CREATE INDEX idx_sub_payments_gym_date ON gym_subscription_payments (gym_id, payment_date)",
        "CREATE INDEX idx_sub_payments_status_date ON gym_subscription_payments (status, payment_date)",
        "CREATE INDEX idx_notif_user_read ON notifications (user_id, is_read, created_at)",
        "CREATE INDEX idx_gym_members_gym ON gym_members (gym_id)",
        "CREATE TABLE IF NOT EXISTS renewal_email_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, recipient_type ENUM('member', 'gym_owner') NOT NULL, target_id INT UNSIGNED NOT NULL, notice_type ENUM('reminder', 'expired') NOT NULL, reference_date DATE NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_renewal_notice (recipient_type, target_id, notice_type, reference_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "ALTER TABLE gyms ADD COLUMN eod_gate_time TIME NOT NULL DEFAULT '17:00:00'",
        "ALTER TABLE gyms ADD COLUMN pending_eod_gate_time TIME DEFAULT NULL",
        "ALTER TABLE gyms ADD COLUMN eod_gate_effective_date DATE DEFAULT NULL",
        "UPDATE platform_subscription_plans SET features = CONCAT(features, '\nFood & Nutrition Library') WHERE plan_key = 'starter' AND features NOT LIKE '%Food & Nutrition Library%'",
        "ALTER TABLE gyms ADD COLUMN secondary_valid_id_url VARCHAR(255) DEFAULT NULL AFTER valid_id_type",
        "ALTER TABLE gyms ADD COLUMN secondary_valid_id_type VARCHAR(100) DEFAULT NULL AFTER secondary_valid_id_url",
        "CREATE TABLE IF NOT EXISTS gym_verification_id_types (id INT AUTO_INCREMENT PRIMARY KEY, id_code VARCHAR(50) NOT NULL UNIQUE, id_name VARCHAR(100) NOT NULL, category ENUM('primary', 'secondary') NOT NULL DEFAULT 'primary', requires_supporting_id TINYINT(1) NOT NULL DEFAULT 0, display_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "INSERT INTO gym_verification_id_types (id_code, id_name, category, requires_supporting_id, display_order, is_active) VALUES ('philsys', 'Philippine National ID (PhilSys ID)', 'primary', 0, 10, 1), ('drivers_license', \"Driver's License\", 'primary', 0, 20, 1), ('passport', 'Passport', 'primary', 0, 30, 1), ('umid', 'UMID (Unified Multi-Purpose ID)', 'primary', 0, 40, 1), ('prc', 'PRC ID', 'primary', 0, 50, 1), ('sss', 'SSS ID', 'primary', 0, 60, 1), ('gsis', 'GSIS ID', 'primary', 0, 70, 1), ('tin', 'TIN ID', 'secondary', 1, 80, 1), ('postal', 'Postal ID', 'secondary', 1, 90, 1), ('other', 'Other Valid Government ID', 'secondary', 1, 100, 1) ON DUPLICATE KEY UPDATE id_name = VALUES(id_name), category = VALUES(category), requires_supporting_id = VALUES(requires_supporting_id), display_order = VALUES(display_order)",
        "ALTER TABLE food_items ADD COLUMN verification_status ENUM('unverified', 'verified', 'rejected') NOT NULL DEFAULT 'unverified' AFTER source",
        "ALTER TABLE food_items ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER verification_status",
        "ALTER TABLE food_items ADD COLUMN verified_at DATETIME DEFAULT NULL AFTER rejection_reason",
        "ALTER TABLE food_items ADD COLUMN verified_by_user_id INT DEFAULT NULL AFTER verified_at",
        "ALTER TABLE food_items ADD COLUMN created_by_user_id INT DEFAULT NULL AFTER verified_by_user_id",
        "CREATE INDEX idx_food_verification ON food_items (verification_status, gym_id)",
        "UPDATE food_items SET verification_status = 'verified' WHERE source = 'system' OR gym_id IS NULL",
        "ALTER TABLE gyms ADD COLUMN latitude DECIMAL(10, 8) DEFAULT NULL",
        "ALTER TABLE gyms ADD COLUMN longitude DECIMAL(11, 8) DEFAULT NULL",
        "ALTER TABLE gyms ADD COLUMN geofence_radius_meters SMALLINT UNSIGNED DEFAULT 100",
        "UPDATE gyms SET latitude = 35.6762, longitude = 139.6503 WHERE gym_id = 5 AND latitude IS NULL",
        "UPDATE gyms SET latitude = 35.6895, longitude = 139.6917 WHERE gym_id = 9 AND latitude IS NULL",
        "UPDATE gyms SET latitude = 14.5547, longitude = 121.0244 WHERE gym_id = 21 AND latitude IS NULL",
        "ALTER TABLE attendance ADD COLUMN exit_detected_at DATETIME DEFAULT NULL AFTER check_out_time",
        "ALTER TABLE attendance ADD COLUMN exit_grace_expires_at DATETIME DEFAULT NULL AFTER exit_detected_at",
        "CREATE INDEX idx_att_exit_grace ON attendance (check_out_time, exit_grace_expires_at)",
        "ALTER TABLE equipment_sessions ADD COLUMN trainer_user_id INT UNSIGNED NULL DEFAULT NULL AFTER user_id",
        "ALTER TABLE equipment_sessions ADD COLUMN partner_user_ids TEXT NULL DEFAULT NULL AFTER trainer_user_id",
        "ALTER TABLE membership_plans ADD COLUMN is_popular TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE platform_subscription_plans ADD COLUMN is_popular TINYINT(1) NOT NULL DEFAULT 0",
        "CREATE UNIQUE INDEX uq_trainer_comm_payment ON trainer_commissions (payment_id)",
        "CREATE UNIQUE INDEX uq_att_user_checkin_exact ON attendance (user_id, check_in_time)",

        // ── Multi-Gym Plan Management migrations ──
        "ALTER TABLE training_plans ADD COLUMN gym_id INT UNSIGNED DEFAULT NULL AFTER trainer_id",
        "ALTER TABLE training_plans ADD INDEX idx_training_plans_gym (gym_id)",
        "ALTER TABLE dietary_plans ADD COLUMN gym_id INT UNSIGNED DEFAULT NULL AFTER trainer_id",
        "ALTER TABLE dietary_plans ADD INDEX idx_dietary_plans_gym (gym_id)",
        "ALTER TABLE dietary_plans ADD COLUMN is_preferred TINYINT(1) NOT NULL DEFAULT 0",

        // Backfill gym_id from trainer_profiles for existing plans
        "UPDATE training_plans tp JOIN trainer_profiles tpr ON tp.trainer_id = tpr.trainer_id SET tp.gym_id = tpr.gym_id WHERE tp.gym_id IS NULL AND tpr.gym_id IS NOT NULL",
        "UPDATE dietary_plans dp JOIN trainer_profiles tpr ON dp.trainer_id = tpr.trainer_id SET dp.gym_id = tpr.gym_id WHERE dp.gym_id IS NULL AND tpr.gym_id IS NOT NULL",

        // Plan change audit trail
        "CREATE TABLE IF NOT EXISTS plan_change_log (log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, plan_type ENUM('workout','diet') NOT NULL, plan_id INT UNSIGNED NOT NULL, gym_id INT UNSIGNED DEFAULT NULL, changed_by_user_id INT UNSIGNED NOT NULL, action ENUM('created','updated','published','paused','archived','completed','deleted') NOT NULL, details TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_plan_log (plan_type, plan_id), INDEX idx_plan_log_gym (gym_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Add 'upcoming' and 'paused' statuses to training_plans and dietary_plans
        "ALTER TABLE training_plans MODIFY COLUMN status ENUM('active','completed','archived','draft','upcoming','paused') NOT NULL DEFAULT 'draft'",
        "ALTER TABLE dietary_plans MODIFY COLUMN status ENUM('active','completed','cancelled','draft','upcoming','paused') NOT NULL DEFAULT 'draft'",

        // ── Member Onboarding, Schedule Enforcement & Secondary Focus migrations ──
        "ALTER TABLE member_profiles ADD COLUMN preferred_workout_days VARCHAR(50) DEFAULT NULL AFTER preferred_duration_mins",
        "ALTER TABLE member_profiles ADD COLUMN secondary_focus VARCHAR(255) DEFAULT NULL AFTER primary_goal",
        "ALTER TABLE member_profiles ADD COLUMN trainer_assessment_notes TEXT DEFAULT NULL AFTER preferred_workout_days",

        // ── Active facility resolution (get_user_gym / memberships page) ──
        "CREATE INDEX idx_mem_user_status_end ON memberships (user_id, status, end_date)",

        // ── Ensure Framework & Operational Tables Exist ──
        "CREATE TABLE IF NOT EXISTS gym_members (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, gym_id INT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_gym_user (user_id, gym_id), KEY idx_gym_members_gym (gym_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS gym_images (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, gym_id INT UNSIGNED NOT NULL, image_url VARCHAR(255) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY fk_gym_images_gym (gym_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS cache (cache_key VARCHAR(255) NOT NULL PRIMARY KEY, cache_value LONGTEXT NOT NULL, expires_at INT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS jobs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_class VARCHAR(255) NOT NULL, payload TEXT NOT NULL, attempts INT UNSIGNED DEFAULT 0, created_at INT UNSIGNED NOT NULL, available_at INT UNSIGNED NOT NULL, KEY idx_jobs_available_attempts (available_at, attempts, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS sessions (session_id VARCHAR(128) NOT NULL PRIMARY KEY, session_data MEDIUMTEXT NOT NULL, expires_at INT UNSIGNED NOT NULL, KEY idx_sessions_expires (expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS member_badges (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, badge_type VARCHAR(50) NOT NULL, unlocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY unique_user_badge (user_id, badge_type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS daily_macros (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, log_date DATE NOT NULL, calories INT UNSIGNED DEFAULT 0, protein_g INT UNSIGNED DEFAULT 0, carbs_g INT UNSIGNED DEFAULT 0, fat_g INT UNSIGNED DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY unique_user_daily_macro (user_id, log_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // ── Scale & High-Concurrency Optimizations ──
        "CREATE INDEX idx_walkin_gym_date ON walk_in_transactions (gym_id, visit_date)",
        "CREATE INDEX idx_walkin_visit_date ON walk_in_transactions (visit_date)",
        "CREATE INDEX idx_jobs_available_attempts ON jobs (available_at, attempts, id)",
        "CREATE INDEX idx_sessions_expires ON sessions (expires_at)",
        "CREATE INDEX idx_att_user_gym_checkout ON attendance (user_id, gym_id, check_out_time, check_in_time)"
    ];

    foreach ($migrations as $sql) {
        try {
            $pdo->exec($sql);
            echo "<li><span style='color:green;'>Success:</span> " . htmlspecialchars($sql) . "</li>";
        } catch (PDOException $e) {
            // Ignore "Duplicate column name", "Duplicate key name", and already existing definitions
            $msg = $e->getMessage();
            if (strpos($msg, 'Duplicate column name') !== false || strpos($msg, 'Duplicate key name') !== false || strpos($msg, 'already exists') !== false) {
                echo "<li><span style='color:gray;'>Skipped (Already exists):</span> " . htmlspecialchars($sql) . "</li>";
            } else {
                echo "<li><span style='color:red;'>Error:</span> " . htmlspecialchars($msg) . "</li>";
            }
        }
    }
    
    echo "</ul><p><strong>Migration complete!</strong></p>";

    echo "<h2>Xendit Gateway Status:</h2>";
    $xenditSecret = (string) app_env('XENDIT_SECRET_KEY');
    $xenditWebhook = (string) app_env('XENDIT_WEBHOOK_TOKEN');
    if ($xenditSecret === '') {
        echo "<p style='color:red;'><strong>&#10060; XENDIT_SECRET_KEY is NOT configured in environment variables!</strong></p>";
    } else {
        $prefix = substr($xenditSecret, 0, 8);
        $mode = str_starts_with($xenditSecret, 'xnd_development_') ? 'Test/Development' : (str_starts_with($xenditSecret, 'xnd_production_') ? 'Live/Production' : 'Custom');
        echo "<p style='color:green;'>&#9989; XENDIT_SECRET_KEY is configured (Mode: {$mode}, Prefix: " . htmlspecialchars($prefix) . "..., Length: " . strlen($xenditSecret) . ")</p>";
    }
    if ($xenditWebhook === '') {
        echo "<p style='color:orange;'>&#9888;&#65039; XENDIT_WEBHOOK_TOKEN is NOT configured (webhook confirmation callbacks will fail).</p>";
    } else {
        echo "<p style='color:green;'>&#9989; XENDIT_WEBHOOK_TOKEN is configured.</p>";
    }
    echo "<p>App Base URL detected: <code>" . htmlspecialchars(app_base_url()) . "</code></p>";
    echo "<p>PHP cURL extension: " . (function_exists('curl_init') ? "<span style='color:green;'>Enabled</span>" : "<span style='color:red;'>Disabled</span>") . "</p>";
} catch (Exception $e) {
    echo "<h2>Critical Database Connection Error:</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
