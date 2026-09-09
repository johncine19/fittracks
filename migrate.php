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
        "CREATE TABLE IF NOT EXISTS member_meal_logs (log_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, meal_type VARCHAR(50) NOT NULL, log_date DATE NOT NULL, meal_id INT UNSIGNED NULL, food_items TEXT NULL, calories INT UNSIGNED NOT NULL DEFAULT 0, protein_g DECIMAL(6,1) NOT NULL DEFAULT 0, carbs_g DECIMAL(6,1) NOT NULL DEFAULT 0, fat_g DECIMAL(6,1) NOT NULL DEFAULT 0, logged_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_user_meal_date (user_id, meal_type, log_date), INDEX idx_user_date (user_id, log_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
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
