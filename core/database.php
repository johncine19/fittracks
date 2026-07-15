<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Auto-migrate branding fields if missing
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM gyms")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('logo_url', $columns)) {
            $pdo->exec("ALTER TABLE gyms ADD COLUMN logo_url VARCHAR(255) DEFAULT NULL AFTER contact_info");
        }
        if (!in_array('brand_color', $columns)) {
            $pdo->exec("ALTER TABLE gyms ADD COLUMN brand_color VARCHAR(7) DEFAULT NULL AFTER logo_url");
        }
        
        // Auto-migrate gym_share_payouts table if missing
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `gym_share_payouts` (
                `payout_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `gym_id` INT UNSIGNED NOT NULL,
                `payment_id` INT UNSIGNED NOT NULL,
                `amount` DECIMAL(10,2) NOT NULL,
                `status` ENUM('pending','paid') NOT NULL DEFAULT 'pending',
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `fk_gsp_gym` (`gym_id`),
                KEY `fk_gsp_payment` (`payment_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        
        // Auto-convert platform-created plans to shared scope
        $pdo->exec("UPDATE membership_plans SET plan_scope = 'shared' WHERE gym_id IS NULL AND plan_scope = 'local'");
    } catch (Throwable) {
    }

    return $pdo;
}

function scalar(string $sql, array $params = []): mixed
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function query_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

