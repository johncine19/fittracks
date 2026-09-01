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
        "ALTER TABLE training_plan_exercises ADD COLUMN target_weight_kg DECIMAL(6,2) DEFAULT NULL",
        "CREATE TABLE IF NOT EXISTS checkout_ratings (rating_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, attendance_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, rating TINYINT UNSIGNED NOT NULL, comment TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_attendance (attendance_id))",
        "CREATE TABLE IF NOT EXISTS workout_rules (rule_id int UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, experience_level int NOT NULL DEFAULT '1', biological_sex enum('male','female','any') NOT NULL DEFAULT 'any', primary_goal enum('fat_loss','muscle_gain','maintenance','general_health') NOT NULL, activity_level enum('sedentary','lightly_active','moderately_active','very_active','extra_active','any') NOT NULL DEFAULT 'any', recommended_workout_structure text NOT NULL)",
        "CREATE TABLE IF NOT EXISTS diet_rules (rule_id int UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, experience_level int NOT NULL DEFAULT '1', biological_sex enum('male','female','any') NOT NULL DEFAULT 'any', primary_goal enum('fat_loss','muscle_gain','maintenance','general_health') NOT NULL, activity_level enum('sedentary','lightly_active','moderately_active','very_active','extra_active','any') NOT NULL DEFAULT 'any', macro_split varchar(255) NOT NULL, notes text)",
        "INSERT IGNORE INTO workout_rules (rule_id, experience_level, biological_sex, primary_goal, activity_level, recommended_workout_structure) VALUES (1, 1, 'any', 'fat_loss', 'any', '3 days Full Body Resistance (Machine focused), 2 days Moderate Cardio (30 mins)')",
        "INSERT IGNORE INTO diet_rules (rule_id, experience_level, biological_sex, primary_goal, activity_level, macro_split, notes) VALUES (1, 1, 'any', 'fat_loss', 'any', '40% Protein, 30% Carbs, 30% Fat', 'Maintain a 300-500 calorie deficit.')"
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
