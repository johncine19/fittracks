<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

header('Content-Type: text/html; charset=UTF-8');

require __DIR__ . '/../config/config.php';
require __DIR__ . '/database.php';
require __DIR__ . '/redis.php';
require __DIR__ . '/SessionDbHandler.php';
require __DIR__ . '/SessionRedisHandler.php';
require __DIR__ . '/Queue.php';
require __DIR__ . '/Cache.php';

require __DIR__ . '/seeds.php';
seed_reference_data_if_empty();

$redisClient = redis();
if ($redisClient !== null) {
    $sessionHandler = new SessionRedisHandler($redisClient);
} else {
    $sessionHandler = new SessionDbHandler(db());
}
session_set_save_handler($sessionHandler, true);
session_start();
ob_start(); // Buffer all output so setup_error() can set HTTP headers even mid-render
require __DIR__ . '/helpers.php';
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
