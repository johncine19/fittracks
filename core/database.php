<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $port = $_ENV['DB_PORT'] ?? '3306';
    $useSSL = !empty($_ENV['DB_PORT']) && (int) $_ENV['DB_PORT'] === 4000; // TiDB Cloud requires SSL
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ];
    if ($useSSL) {
        // TiDB Cloud Serverless requires SSL — use system CA bundle
        $caBundle = '/etc/ssl/certs/ca-certificates.crt';
        if (file_exists($caBundle)) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $caBundle;
        }
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    try {
        // Enforce utf8mb4_0900_ai_ci across TiDB Cloud and MySQL 8.0.
        // Overrides PHP mysqlnd's utf8mb4_general_ci default and prevents TiDB's utf8mb4_bin database default from breaking auth/queries.
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    } catch (Throwable) {
        try {
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (Throwable) {
            try {
                $pdo->exec("SET NAMES utf8mb4");
            } catch (Throwable) {}
        }
    }
    try {
        $pdo->exec("SET time_zone = '+08:00'");
    } catch (Throwable) {}

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

function query_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ? (array) $row : null;
}

function db_supports_triggers(?PDO $pdo = null): bool
{
    static $supports = null;
    if ($supports !== null) {
        return $supports;
    }
    try {
        $pdo = $pdo ?? db();
        $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        if (stripos($ver, 'TiDB') !== false) {
            $supports = false;
            return false;
        }
        $supports = true;
        return true;
    } catch (Throwable) {
        $supports = false;
        return false;
    }
}


