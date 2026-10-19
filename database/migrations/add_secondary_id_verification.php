<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

$pdo = db();

// 1. Add secondary_valid_id columns to gyms table
$gymColumns = [
    'secondary_valid_id_url' => "ALTER TABLE gyms ADD COLUMN secondary_valid_id_url VARCHAR(255) DEFAULT NULL AFTER valid_id_type",
    'secondary_valid_id_type' => "ALTER TABLE gyms ADD COLUMN secondary_valid_id_type VARCHAR(100) DEFAULT NULL AFTER secondary_valid_id_url",
];

foreach ($gymColumns as $col => $alterSql) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gyms' AND COLUMN_NAME = ?");
    $check->execute([$col]);
    if ((int)$check->fetchColumn() === 0) {
        $pdo->exec($alterSql);
        echo "Added gyms.{$col}.\n";
    } else {
        echo "gyms.{$col} already exists.\n";
    }
}

// 2. Create gym_verification_id_types table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS gym_verification_id_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_code VARCHAR(50) NOT NULL UNIQUE,
        id_name VARCHAR(100) NOT NULL,
        category ENUM('primary', 'secondary') NOT NULL DEFAULT 'primary',
        requires_supporting_id TINYINT(1) NOT NULL DEFAULT 0,
        display_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "Table gym_verification_id_types verified/created.\n";

// 3. Seed initial ID classifications approved in FitTrack policy
$defaultIdTypes = [
    // Primary IDs
    ['philsys', 'Philippine National ID (PhilSys ID)', 'primary', 0, 10],
    ['drivers_license', "Driver's License", 'primary', 0, 20],
    ['passport', 'Passport', 'primary', 0, 30],
    ['umid', 'UMID (Unified Multi-Purpose ID)', 'primary', 0, 40],
    ['prc', 'PRC ID', 'primary', 0, 50],
    ['sss', 'SSS ID', 'primary', 0, 60],
    ['gsis', 'GSIS ID', 'primary', 0, 70],
    // Secondary IDs (Require supporting ID)
    ['tin', 'TIN ID', 'secondary', 1, 80],
    ['postal', 'Postal ID', 'secondary', 1, 90],
    ['other', 'Other Valid Government ID', 'secondary', 1, 100],
];

$stmt = $pdo->prepare("
    INSERT INTO gym_verification_id_types (id_code, id_name, category, requires_supporting_id, display_order, is_active)
    VALUES (?, ?, ?, ?, ?, 1)
    ON DUPLICATE KEY UPDATE
        id_name = VALUES(id_name),
        category = VALUES(category),
        requires_supporting_id = VALUES(requires_supporting_id),
        display_order = VALUES(display_order)
");

foreach ($defaultIdTypes as $idData) {
    $stmt->execute($idData);
}
echo "Seeded " . count($defaultIdTypes) . " verification ID types.\n";
