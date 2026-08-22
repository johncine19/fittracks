<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

date_default_timezone_set('Asia/Manila');

header('Content-Type: text/html; charset=UTF-8');

require __DIR__ . '/helpers.php';
require __DIR__ . '/../config/config.php';
require __DIR__ . '/database.php';
require __DIR__ . '/redis.php';
require __DIR__ . '/SessionDbHandler.php';
require __DIR__ . '/SessionRedisHandler.php';
require __DIR__ . '/Queue.php';
require __DIR__ . '/Cache.php';
require __DIR__ . '/emails.php';

require __DIR__ . '/seeds.php';
$seedLockFile = __DIR__ . '/../storage/.seeded.lock';
if (!file_exists($seedLockFile)) {
    seed_reference_data_if_empty();
    if (is_dir(__DIR__ . '/../storage')) {
        @file_put_contents($seedLockFile, '1');
    }
}

$redisClient = redis();
if ($redisClient !== null) {
    $sessionHandler = new SessionRedisHandler($redisClient);
} else {
    $sessionHandler = new SessionDbHandler(db());
}
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');

session_set_save_handler($sessionHandler, true);
session_start();
ob_start(); // Buffer all output so setup_error() can set HTTP headers even mid-render
require __DIR__ . '/csrf.php';
require __DIR__ . '/validators.php';
require __DIR__ . '/rate_limiter.php';
require __DIR__ . '/file_handler.php';
require __DIR__ . '/email_verification.php';
require __DIR__ . '/engagement_engine.php';
require __DIR__ . '/notifications.php';
require __DIR__ . '/../views/layout.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/../views/components.php';

// --- Shared (used by multiple roles) ---
require __DIR__ . '/../pages/shared/exercise.php';
require __DIR__ . '/../pages/shared/workouts.php';
require __DIR__ . '/../pages/shared/messages.php';
require __DIR__ . '/../pages/shared/notifications.php';
