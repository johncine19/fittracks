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
        "CREATE TABLE IF NOT EXISTS checkout_ratings (rating_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, attendance_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, rating TINYINT UNSIGNED NOT NULL, comment TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_attendance (attendance_id))"
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
