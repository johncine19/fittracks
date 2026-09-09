<?php
require __DIR__ . '/core/bootstrap.php';

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
        "ALTER TABLE training_plan_exercises ADD COLUMN target_weight_kg DECIMAL(6,2) DEFAULT NULL",
        "CREATE TABLE IF NOT EXISTS checkout_ratings (rating_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, attendance_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, rating TINYINT UNSIGNED NOT NULL, comment TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_attendance (attendance_id))",
        "CREATE TABLE IF NOT EXISTS workout_rules (rule_id int UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, experience_level int NOT NULL DEFAULT '1', biological_sex enum('male','female','any') NOT NULL DEFAULT 'any', primary_goal enum('fat_loss','muscle_gain','maintenance','general_health') NOT NULL, activity_level enum('sedentary','lightly_active','moderately_active','very_active','extra_active','any') NOT NULL DEFAULT 'any', recommended_workout_structure text NOT NULL)",
        "CREATE TABLE IF NOT EXISTS diet_rules (rule_id int UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, experience_level int NOT NULL DEFAULT '1', biological_sex enum('male','female','any') NOT NULL DEFAULT 'any', primary_goal enum('fat_loss','muscle_gain','maintenance','general_health') NOT NULL, activity_level enum('sedentary','lightly_active','moderately_active','very_active','extra_active','any') NOT NULL DEFAULT 'any', macro_split varchar(255) NOT NULL, notes text)",
        "INSERT IGNORE INTO workout_rules (rule_id, experience_level, biological_sex, primary_goal, activity_level, recommended_workout_structure) VALUES (1, 1, 'any', 'fat_loss', 'any', '3 days Full Body Resistance (Machine focused), 2 days Moderate Cardio (30 mins)')",
        "INSERT IGNORE INTO diet_rules (rule_id, experience_level, biological_sex, primary_goal, activity_level, macro_split, notes) VALUES (1, 1, 'any', 'fat_loss', 'any', '40% Protein, 30% Carbs, 30% Fat', 'Maintain a 300-500 calorie deficit.')",
        "UPDATE gyms SET subscription_plan = 'Professional', subscription_status = 'active', subscription_renewal_date = '2027-12-31' WHERE status = 'approved' AND (subscription_status IS NULL OR subscription_status = 'inactive' OR subscription_plan IS NULL OR subscription_plan = '')",
        "CREATE TABLE IF NOT EXISTS member_meal_logs (log_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, meal_type VARCHAR(50) NOT NULL, log_date DATE NOT NULL, meal_id INT UNSIGNED NULL, food_items TEXT NULL, calories INT UNSIGNED NOT NULL DEFAULT 0, protein_g DECIMAL(6,1) NOT NULL DEFAULT 0, carbs_g DECIMAL(6,1) NOT NULL DEFAULT 0, fat_g DECIMAL(6,1) NOT NULL DEFAULT 0, logged_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_user_meal_date (user_id, meal_type, log_date), INDEX idx_user_date (user_id, log_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS gym_equipment (equipment_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, gym_id INT UNSIGNED NOT NULL, name VARCHAR(150) NOT NULL, unit_number VARCHAR(50) NOT NULL DEFAULT '#1', category ENUM('Cardio', 'Strength', 'Free Weights', 'Machines', 'Functional Training', 'Other') NOT NULL DEFAULT 'Machines', location_area VARCHAR(100) DEFAULT 'Main Gym Floor', description TEXT DEFAULT NULL, equipment_condition ENUM('Excellent', 'Good', 'Fair', 'Poor') NOT NULL DEFAULT 'Good', status ENUM('available', 'in_use', 'maintenance', 'out_of_service') NOT NULL DEFAULT 'available', image_url VARCHAR(255) DEFAULT NULL, date_added DATE NOT NULL, last_maintenance_date DATE DEFAULT NULL, next_maintenance_date DATE DEFAULT NULL, maintenance_reason VARCHAR(255) DEFAULT NULL, expected_return_date DATE DEFAULT NULL, current_session_id INT UNSIGNED DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_gym_equip_unit (gym_id, name, unit_number), INDEX idx_gym_status (gym_id, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS equipment_sessions (session_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, gym_id INT UNSIGNED NOT NULL, equipment_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, start_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, end_time TIMESTAMP NULL DEFAULT NULL, duration_seconds INT UNSIGNED DEFAULT 0, session_status ENUM('active', 'completed', 'cancelled') NOT NULL DEFAULT 'active', INDEX idx_gym_equipment (gym_id, equipment_id), INDEX idx_user_status (user_id, session_status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS equipment_queues (queue_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, gym_id INT UNSIGNED NOT NULL, equipment_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, queue_position INT UNSIGNED NOT NULL DEFAULT 1, queue_status ENUM('waiting', 'notified', 'claimed', 'cancelled', 'expired') NOT NULL DEFAULT 'waiting', joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, notified_at TIMESTAMP NULL DEFAULT NULL, claim_deadline TIMESTAMP NULL DEFAULT NULL, resolved_at TIMESTAMP NULL DEFAULT NULL, INDEX idx_user_active_queue (user_id, equipment_id, queue_status), INDEX idx_equip_queue_pos (equipment_id, queue_status, queue_position)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS equipment_maintenance_logs (log_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, equipment_id INT UNSIGNED NOT NULL, gym_id INT UNSIGNED NOT NULL, logged_by_user_id INT UNSIGNED NOT NULL, action VARCHAR(50) NOT NULL, reason VARCHAR(255) DEFAULT NULL, expected_return_date DATE DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_equip_logs (equipment_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    foreach ($migrations as $sql) {
        try {
            $pdo->exec($sql);
            echo "<li><span style='color:green;'>Success:</span> " . htmlspecialchars($sql) . "</li>";
        } catch (PDOException $e) {
            // Ignore "Duplicate column name" errors (1060 in MySQL)
            if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                echo "<li><span style='color:gray;'>Skipped (Already exists):</span> " . htmlspecialchars($sql) . "</li>";
            } else {
                echo "<li><span style='color:red;'>Error:</span> " . htmlspecialchars($e->getMessage()) . "</li>";
            }
        }
    }
    
    echo "</ul><p><strong>Migration complete!</strong> You can now return to the application.</p>";
} catch (Exception $e) {
    echo "<h2>Critical Database Connection Error:</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
