<?php
require __DIR__ . '/core/bootstrap.php';

try {
    $pdo = db();
    
    // Check if column target_weight_kg exists
    $stmt = $pdo->query("SHOW COLUMNS FROM member_profiles LIKE 'target_weight_kg'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE member_profiles ADD COLUMN target_weight_kg DECIMAL(5,2) DEFAULT NULL");
        echo "Added target_weight_kg successfully.\n";
    } else {
        echo "target_weight_kg already exists.\n";
    }

    // Check if column target_body_fat_percent exists
    $stmt = $pdo->query("SHOW COLUMNS FROM member_profiles LIKE 'target_body_fat_percent'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE member_profiles ADD COLUMN target_body_fat_percent DECIMAL(5,2) DEFAULT NULL");
        echo "Added target_body_fat_percent successfully.\n";
    } else {
        echo "target_body_fat_percent already exists.\n";
    }

    echo "Database fixed successfully.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
