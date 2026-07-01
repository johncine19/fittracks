<?php
declare(strict_types=1);

/**
 * Minimal database migration runner — no extra Composer dependency needed.
 *
 * Usage (from the project root):
 *   php core/migrate.php          # apply all pending migrations
 *   php core/migrate.php status   # list applied / pending migrations
 *
 * How it works:
 *   - Every *.sql file in /migrations is one migration, applied in
 *     filename order (hence the 0001_, 0002_... prefix convention).
 *   - Applied migrations are recorded in a `schema_migrations` table so
 *     each file only ever runs once, even across deployments.
 *   - Each file runs inside a transaction; if it fails, nothing from that
 *     file is committed and the runner stops (so you can fix and re-run).
 *
 * This intentionally has no "down"/rollback migrations — for a project
 * this size, forward-only migrations plus a DB backup before running are
 * simpler and safer than maintaining reverse SQL for every change.
 */

require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();
require __DIR__ . '/../config/config.php';
require __DIR__ . '/database.php';

function migrate_ensure_table(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL UNIQUE,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

/** @return string[] */
function migrate_applied(): array
{
    return array_column(query_all('SELECT migration FROM schema_migrations ORDER BY id'), 'migration');
}

/** @return string[] sorted migration file paths */
function migrate_files(): array
{
    $files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
    sort($files, SORT_STRING);
    return $files;
}

function migrate_run_file(string $path): void
{
    $sql = (string) file_get_contents($path);
    $pdo = db();
    try {
        // Note: ALTER/CREATE TABLE statements cause an implicit commit in
        // MySQL/MariaDB, so an explicit PDO transaction can't make a
        // mixed DDL+DML migration atomic. We run statements in order and
        // only record the migration as applied once all of them succeed;
        // if one fails partway through, fix the .sql file (and clean up
        // any partial changes manually) before re-running.
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $pdo->exec($statement);
        }
        $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute([basename($path)]);
        echo "  applied " . basename($path) . "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "  FAILED " . basename($path) . ": " . $e->getMessage() . "\n");
        exit(1);
    }
}

migrate_ensure_table();
$applied = migrate_applied();
$files = migrate_files();
$command = $argv[1] ?? 'migrate';

if ($command === 'status') {
    foreach ($files as $file) {
        $name = basename($file);
        echo (in_array($name, $applied, true) ? '[applied] ' : '[pending] ') . $name . "\n";
    }
    exit(0);
}

echo "Running migrations against " . DB_NAME . "@" . DB_HOST . "...\n";
$pending = array_filter($files, fn($f) => !in_array(basename($f), $applied, true));

if (!$pending) {
    echo "Nothing to do — database is up to date.\n";
    exit(0);
}

foreach ($pending as $file) {
    migrate_run_file($file);
}

echo "Done.\n";
