<?php
require __DIR__ . '/core/bootstrap.php';
try {
    $pdo = db();
    $stmt = $pdo->query("SHOW COLUMNS FROM training_plan_exercises LIKE 'target_weight_kg'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE training_plan_exercises ADD COLUMN target_weight_kg DECIMAL(6,2) DEFAULT NULL");
        echo "Added target_weight_kg to training_plan_exercises successfully.\n";
    } else {
        echo "target_weight_kg exists in training_plan_exercises.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
