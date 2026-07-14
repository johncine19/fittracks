<?php
require __DIR__ . '/core/bootstrap.php';

try {
    $pdo = db();
    $pdo->exec("ALTER TABLE member_profiles MODIFY COLUMN primary_goal VARCHAR(255) NOT NULL");
    echo "Successfully altered primary_goal column.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
