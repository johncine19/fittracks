<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

$pdo = db();

echo "Running Multi-Gym Plan Management Migration...\n";

// 1. Add gym_id and is_preferred to dietary_plans
$check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dietary_plans' AND COLUMN_NAME = 'gym_id'");
$check->execute();
if ((int)$check->fetchColumn() === 0) {
    $pdo->exec("ALTER TABLE dietary_plans ADD COLUMN gym_id INT UNSIGNED DEFAULT NULL AFTER trainer_id");
    $pdo->exec("ALTER TABLE dietary_plans ADD INDEX idx_dietary_plans_gym (gym_id)");
    echo "Added dietary_plans.gym_id and index.\n";
} else {
    echo "dietary_plans.gym_id already exists.\n";
}

$check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dietary_plans' AND COLUMN_NAME = 'is_preferred'");
$check->execute();
if ((int)$check->fetchColumn() === 0) {
    $pdo->exec("ALTER TABLE dietary_plans ADD COLUMN is_preferred TINYINT(1) NOT NULL DEFAULT 0");
    echo "Added dietary_plans.is_preferred.\n";
} else {
    echo "dietary_plans.is_preferred already exists.\n";
}

// 2. Add gym_id to training_plans
$check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'training_plans' AND COLUMN_NAME = 'gym_id'");
$check->execute();
if ((int)$check->fetchColumn() === 0) {
    $pdo->exec("ALTER TABLE training_plans ADD COLUMN gym_id INT UNSIGNED DEFAULT NULL AFTER trainer_id");
    $pdo->exec("ALTER TABLE training_plans ADD INDEX idx_training_plans_gym (gym_id)");
    echo "Added training_plans.gym_id and index.\n";
} else {
    echo "training_plans.gym_id already exists.\n";
}

// 3. Backfill gym_id from trainer_profiles
$pdo->exec("UPDATE training_plans tp JOIN trainer_profiles tpr ON tp.trainer_id = tpr.trainer_id SET tp.gym_id = tpr.gym_id WHERE tp.gym_id IS NULL AND tpr.gym_id IS NOT NULL");
$pdo->exec("UPDATE dietary_plans dp JOIN trainer_profiles tpr ON dp.trainer_id = tpr.trainer_id SET dp.gym_id = tpr.gym_id WHERE dp.gym_id IS NULL AND tpr.gym_id IS NOT NULL");
echo "Backfilled gym_id from trainer_profiles where available.\n";

// 4. Create plan_change_log audit trail table
$pdo->exec("CREATE TABLE IF NOT EXISTS plan_change_log (
    log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_type ENUM('workout','diet') NOT NULL,
    plan_id INT UNSIGNED NOT NULL,
    gym_id INT UNSIGNED DEFAULT NULL,
    changed_by_user_id INT UNSIGNED NOT NULL,
    action ENUM('created','updated','published','paused','archived','completed','deleted') NOT NULL,
    details TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_plan_log (plan_type, plan_id),
    INDEX idx_plan_log_gym (gym_id),
    INDEX idx_plan_log_user (changed_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "Verified plan_change_log table exists.\n";

// 5. Expand plan status ENUMs
try {
    $pdo->exec("ALTER TABLE training_plans MODIFY COLUMN status ENUM('active','completed','archived','draft','upcoming','paused') NOT NULL DEFAULT 'draft'");
    $pdo->exec("ALTER TABLE dietary_plans MODIFY COLUMN status ENUM('active','completed','cancelled','draft','upcoming','paused') NOT NULL DEFAULT 'draft'");
    echo "Expanded plan status ENUMs.\n";
} catch (Exception $e) {
    echo "Note on ENUM modification: " . $e->getMessage() . "\n";
}

echo "Migration finished successfully.\n";
