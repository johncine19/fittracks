<?php
require_once __DIR__ . '/core/bootstrap.php';

try {
    $pdo = db();
    $pdo->exec("ALTER TABLE training_plans MODIFY status ENUM('active','completed','archived','draft') NOT NULL DEFAULT 'draft'");
    echo "Successfully updated training_plans status ENUM.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
