<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $port = (string) ($_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'] ?? (getenv('DB_PORT') !== false ? getenv('DB_PORT') : '3306'));
    $useSSL = $port !== '' && (int) $port === 4000; // TiDB Cloud requires SSL
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET character_set_client = utf8mb4, character_set_results = utf8mb4, character_set_connection = utf8mb4, collation_connection = utf8mb4_0900_ai_ci, time_zone = '+08:00'",
    ];
    if ($useSSL) {
        // TiDB Cloud Serverless requires SSL — use system CA bundle
        $caBundle = '/etc/ssl/certs/ca-certificates.crt';
        if (file_exists($caBundle)) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $caBundle;
        }
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (Throwable) {
        // Safe fallback for older MariaDB engines without utf8mb4_0900_ai_ci
        $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        try {
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (Throwable) {}
        try {
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (Throwable) {}
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

function query_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ? (array) $row : null;
}

function db_is_tidb(?PDO $pdo = null): bool
{
    static $isTiDB = null;
    if ($isTiDB !== null) {
        return $isTiDB;
    }
    try {
        $pdo = $pdo ?? db();
        $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $isTiDB = (stripos($ver, 'TiDB') !== false);
        return $isTiDB;
    } catch (Throwable) {
        $isTiDB = false;
        return false;
    }
}

function db_supports_triggers(?PDO $pdo = null): bool
{
    if (db_is_tidb($pdo)) {
        return false;
    }
    return true;
}


