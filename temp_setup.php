<?php
require __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();
require __DIR__ . '/config/config.php';
require __DIR__ . '/core/database.php';
$pdo = db();

$pdo->exec("
CREATE TABLE IF NOT EXISTS gym_members (
    gym_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (gym_id, user_id)
);
");

$pdo->exec("
INSERT IGNORE INTO gym_members (gym_id, user_id)
SELECT DISTINCT mp.gym_id, m.user_id 
FROM memberships m 
JOIN membership_plans mp ON m.plan_id = mp.plan_id 
WHERE mp.gym_id IS NOT NULL;
");

echo "Tables created and seeded.";
