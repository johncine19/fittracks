<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

$pdo = db();

$columns = [
    'verification_status' => "ALTER TABLE food_items ADD COLUMN verification_status ENUM('unverified', 'verified', 'rejected') NOT NULL DEFAULT 'unverified' AFTER source",
    'rejection_reason'    => "ALTER TABLE food_items ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER verification_status",
    'verified_at'         => "ALTER TABLE food_items ADD COLUMN verified_at DATETIME DEFAULT NULL AFTER rejection_reason",
    'verified_by_user_id' => "ALTER TABLE food_items ADD COLUMN verified_by_user_id INT DEFAULT NULL AFTER verified_at",
    'created_by_user_id'  => "ALTER TABLE food_items ADD COLUMN created_by_user_id INT DEFAULT NULL AFTER verified_by_user_id",
];

foreach ($columns as $col => $alterSql) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_items' AND COLUMN_NAME = ?");
    $check->execute([$col]);
    if ((int)$check->fetchColumn() === 0) {
        $pdo->exec($alterSql);
        echo "Added food_items.{$col}.\n";
    } else {
        echo "food_items.{$col} already exists.\n";
    }
}

// Add index on verification status for fast query filtering
try {
    $idxCheck = $pdo->query("SHOW INDEX FROM food_items WHERE Key_name = 'idx_food_verification'");
    if (!$idxCheck->fetch()) {
        $pdo->exec("ALTER TABLE food_items ADD INDEX idx_food_verification (verification_status, gym_id)");
        echo "Added index idx_food_verification.\n";
    }
} catch (Throwable $e) {}

// Ensure all existing system / staple foods are marked verified
$pdo->exec("UPDATE food_items SET verification_status = 'verified' WHERE source = 'system' OR gym_id IS NULL");
echo "Updated default system foods to 'verified'.\n";
