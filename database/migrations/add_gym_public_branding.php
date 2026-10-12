<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

$pdo = db();
$columns = [
    'cover_image_url' => "ALTER TABLE gyms ADD COLUMN cover_image_url VARCHAR(255) DEFAULT NULL AFTER logo_url",
    'public_description' => "ALTER TABLE gyms ADD COLUMN public_description TEXT DEFAULT NULL AFTER cover_image_url",
];

foreach ($columns as $column => $alterSql) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gyms' AND COLUMN_NAME = ?");
    $check->execute([$column]);
    if ((int)$check->fetchColumn() === 0) {
        $pdo->exec($alterSql);
        echo "Added gyms.{$column}.\n";
    } else {
        echo "gyms.{$column} already exists.\n";
    }
}
