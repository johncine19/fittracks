<?php
declare(strict_types=1);

/**
 * Migration: Gym Pause & Maintenance Mode System for Live TiDB / MySQL
 *
 * Ensures:
 * 1. system_settings table exists and setting_value allows long notices (TEXT).
 * 2. notifications table has reference_id column and index for gym-scoped alerts.
 */

require_once __DIR__ . '/../../core/bootstrap.php';

$pdo = db();

echo "Running Gym Pause & Maintenance Mode System Migration (TiDB / MySQL)...\n";

// 1. Verify or create system_settings table
try {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` VARCHAR(80) NOT NULL PRIMARY KEY,
  `setting_value` TEXT NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `fk_setting_admin` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    echo "✓ Verified system_settings table.\n";
} catch (Throwable $e) {
    echo "! Error verifying system_settings table: " . $e->getMessage() . "\n";
}

// 2. Ensure system_settings.setting_value is TEXT (expanded from VARCHAR(255) to prevent truncation of custom notices)
try {
    $colStmt = $pdo->prepare("
        SELECT DATA_TYPE 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'system_settings' 
          AND COLUMN_NAME = 'setting_value'
    ");
    $colStmt->execute();
    $dataType = strtolower((string) $colStmt->fetchColumn());

    if ($dataType === 'varchar') {
        $pdo->exec("ALTER TABLE `system_settings` MODIFY COLUMN `setting_value` TEXT NOT NULL");
        echo "✓ Modified system_settings.setting_value from VARCHAR to TEXT for notice support.\n";
    } else {
        echo "✓ system_settings.setting_value is already {$dataType}.\n";
    }
} catch (Throwable $e) {
    echo "! Column check on system_settings failed: " . $e->getMessage() . "\n";
}

// 3. Ensure notifications.reference_id exists
try {
    $refColStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'notifications' 
          AND COLUMN_NAME = 'reference_id'
    ");
    $refColStmt->execute();
    if ((int) $refColStmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `notifications` ADD COLUMN `reference_id` INT UNSIGNED DEFAULT NULL AFTER `message`");
        echo "✓ Added notifications.reference_id column.\n";
    } else {
        echo "✓ notifications.reference_id already exists.\n";
    }
} catch (Throwable $e) {
    echo "! Column check on notifications failed: " . $e->getMessage() . "\n";
}

// 4. Ensure index on notifications.reference_id exists
try {
    $idxCheck = $pdo->query("SHOW INDEX FROM `notifications` WHERE Key_name = 'idx_notif_reference'");
    if (!$idxCheck->fetch()) {
        $pdo->exec("ALTER TABLE `notifications` ADD INDEX `idx_notif_reference` (`reference_id`)");
        echo "✓ Added index idx_notif_reference on notifications.\n";
    } else {
        echo "✓ Index idx_notif_reference already exists.\n";
    }
} catch (Throwable $e) {
    // Non-blocking if index check fails
    echo "! Index check on notifications: " . $e->getMessage() . "\n";
}

echo "=== Migration complete! Live TiDB / MySQL is ready for Gym Pause & Maintenance Mode. ===\n";

