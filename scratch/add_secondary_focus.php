<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../core/bootstrap.php';
$pdo = db();
try {
    $pdo->exec("ALTER TABLE member_profiles ADD COLUMN secondary_focus VARCHAR(255) DEFAULT NULL AFTER primary_goal");
    echo "Column secondary_focus added successfully.\n";
} catch (Throwable $e) {
    echo "Notice: " . $e->getMessage() . "\n";
}
