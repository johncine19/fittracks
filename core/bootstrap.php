<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

date_default_timezone_set('Asia/Manila');

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://accounts.google.com/gsi/client https://apis.google.com cdn.jsdelivr.net cdnjs.cloudflare.com unpkg.com; style-src 'self' 'unsafe-inline' fonts.googleapis.com https://accounts.google.com/gsi/style cdn.jsdelivr.net cdnjs.cloudflare.com unpkg.com; img-src 'self' data: blob: *.imagekit.io res.cloudinary.com images.unsplash.com *.unsplash.com *.openfoodfacts.org *.openfoodfacts.net *.wikimedia.org https://*.googleusercontent.com https://ssl.gstatic.com; font-src 'self' fonts.gstatic.com; connect-src 'self' https://accounts.google.com cdn.jsdelivr.net *.jsdelivr.net cdnjs.cloudflare.com unpkg.com *.openfoodfacts.net *.openfoodfacts.org; frame-src 'self' https://accounts.google.com; object-src 'none';");

require __DIR__ . '/helpers.php';
require __DIR__ . '/../config/config.php';
require __DIR__ . '/database.php';
require __DIR__ . '/redis.php';
require __DIR__ . '/SessionDbHandler.php';
require __DIR__ . '/SessionRedisHandler.php';
require __DIR__ . '/Queue.php';
require __DIR__ . '/Cache.php';
require __DIR__ . '/emails.php';
require __DIR__ . '/xendit.php';

require __DIR__ . '/seeds.php';
$seedLockFile = __DIR__ . '/../storage/.seeded.lock';
if (!file_exists($seedLockFile)) {
    seed_reference_data_if_empty();
    if (is_dir(__DIR__ . '/../storage')) {
        @file_put_contents($seedLockFile, '1');
    }
}

// Cloudflare & Reverse Proxy Support: Real client IP restoration
// Only trust forwarded headers when the connection originates from an explicitly configured trusted proxy
$directIp = $_SERVER['REMOTE_ADDR'] ?? '';
$trustedProxiesRaw = (string) app_env('TRUSTED_PROXIES', '');

if (!empty($trustedProxiesRaw) && !empty($directIp)) {
    $trustedProxies = array_filter(array_map('trim', explode(',', $trustedProxiesRaw)));
    $isTrustedProxy = false;

    foreach ($trustedProxies as $trusted) {
        if ($trusted === '*' || $trusted === $directIp) {
            $isTrustedProxy = true;
            break;
        }
        if (str_contains($trusted, '/')) {
            [$subnet, $bits] = explode('/', $trusted, 2);
            $bits = (int) $bits;
            if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($directIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $subnetLong = ip2long($subnet);
                $directLong = ip2long($directIp);
                if ($subnetLong !== false && $directLong !== false && $bits >= 0 && $bits <= 32) {
                    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
                    if (($directLong & $mask) === ($subnetLong & $mask)) {
                        $isTrustedProxy = true;
                        break;
                    }
                }
            }
        }
    }

    if ($isTrustedProxy) {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)) {
            $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwardedIps = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $candidateIp = trim($forwardedIps[0]);
            if (filter_var($candidateIp, FILTER_VALIDATE_IP)) {
                $_SERVER['REMOTE_ADDR'] = $candidateIp;
            }
        }
    }
}

$redisClient = redis();
if ($redisClient !== null) {
    $sessionHandler = new SessionRedisHandler($redisClient);
} else {
    $sessionHandler = new SessionDbHandler(db());
}

// Detect HTTPS directly or when proxied by Cloudflare
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (isset($_SERVER['HTTP_CF_VISITOR']) && str_contains($_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"'));

ini_set('session.gc_maxlifetime', '86400');
ini_set('session.cookie_lifetime', '86400');
ini_set('session.use_strict_mode', '1');

session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_set_save_handler($sessionHandler, true);
session_start();
ob_start(); // Buffer all output so setup_error() can set HTTP headers even mid-render
require __DIR__ . '/csrf.php';
require __DIR__ . '/validators.php';
require __DIR__ . '/rate_limiter.php';
require __DIR__ . '/file_handler.php';
require __DIR__ . '/email_verification.php';
require __DIR__ . '/engagement_engine.php';
require __DIR__ . '/food_ingredients.php';
require __DIR__ . '/notifications.php';
require __DIR__ . '/subscription_service.php';
require __DIR__ . '/nutrition_service.php';
require __DIR__ . '/review_service.php';
require __DIR__ . '/goal_service.php';
require __DIR__ . '/../views/layout.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/../views/components.php';
require __DIR__ . '/../views/components/floating_rating_modal.php';

// --- Shared (used by multiple roles) ---
require __DIR__ . '/../pages/shared/exercise.php';
require __DIR__ . '/../pages/shared/workouts.php';
require __DIR__ . '/../pages/shared/messages.php';
require __DIR__ . '/../pages/shared/notifications.php';
