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

