<?php
declare(strict_types=1);

/**
 * FitTracks Redis Client Wrapper
 * 
 * Provides high-performance in-memory caching with graceful fallbacks.
 * If Redis is not installed, offline, or disabled, operations gracefully 
 * return null/false without blocking execution or raising fatal errors.
 */

// IDE stub class: Prevents "Undefined type/class 'Redis'" lint warnings in editors
// when running on local development machines without the compiled C ext-redis extension.
if (!class_exists('Redis')) {
    class Redis
    {
        public function connect(string $host, int $port = 6379, float $timeout = 0.0): bool { return false; }
        public function auth(mixed $auth): bool { return false; }
        public function select(int $db): bool { return false; }
        public function setex(string $key, int $ttl, string $value): bool { return false; }
        public function get(string $key): mixed { return false; }
        public function del(string ...$keys): int { return 0; }
        public function ttl(string $key): int { return -2; }
    }
}

class RedisClient
{
    private static ?Redis $instance = null;
    private static bool $attempted = false;

    public static function getConnection(): ?Redis
    {
        if (self::$attempted) {
            return self::$instance;
        }

        self::$attempted = true;

        // Check if Redis is enabled via environment variables
        $enabled = strtolower((string)app_env('REDIS_ENABLED', 'true'));
        if (in_array($enabled, ['0', 'false', 'off', 'no'], true)) {
            return null;
        }

        if (!extension_loaded('redis')) {
            return null;
        }

        $redisUrl = (string)app_env('REDIS_URL', '');
        $host = (string)app_env('REDIS_HOST', '127.0.0.1');
        $port = (int)app_env('REDIS_PORT', 6379);
        $password = (string)app_env('REDIS_PASSWORD', '');
        $timeout = (float)app_env('REDIS_TIMEOUT', 0.5); // 500ms max connection timeout to prevent lag
        $dbIndex = (int)app_env('REDIS_DB', 0);

        // Render provides REDIS_URL (e.g. redis://red-xxxx:6379 or redis://default:pass@red-xxxx:6379)
        if (!empty($redisUrl)) {
            $parsed = parse_url($redisUrl);
            if (!empty($parsed['host'])) {
                $host = (string)$parsed['host'];
            }
            if (!empty($parsed['port'])) {
                $port = (int)$parsed['port'];
            }
            if (!empty($parsed['pass'])) {
                $password = (string)$parsed['pass'];
            }
            if (!empty($parsed['path']) && is_numeric(ltrim((string)$parsed['path'], '/'))) {
                $dbIndex = (int)ltrim((string)$parsed['path'], '/');
            }
        }

        try {
            $redis = new Redis();
            $connected = $redis->connect($host, $port, $timeout);
            if (!$connected) {
                return null;
            }

            if (!empty($password)) {
                $redis->auth($password);
            }

            // Optional DB index
            if ($dbIndex > 0) {
                $redis->select($dbIndex);
            }

            self::$instance = $redis;
            return self::$instance;
        } catch (Throwable $e) {
            error_log('Redis connection notice: ' . $e->getMessage());
            self::$instance = null;
            return null;
        }
    }

    public static function isConnected(): bool
    {
        return self::getConnection() !== null;
    }

    public static function setex(string $key, int $ttlSeconds, string $value): bool
    {
        $conn = self::getConnection();
        if (!$conn) {
            return false;
        }
        try {
            return (bool)$conn->setex($key, $ttlSeconds, $value);
        } catch (Throwable $e) {
            error_log('Redis setex error: ' . $e->getMessage());
            return false;
        }
    }

    public static function get(string $key): ?string
    {
        $conn = self::getConnection();
        if (!$conn) {
            return null;
        }
        try {
            $val = $conn->get($key);
            return $val !== false ? (string)$val : null;
        } catch (Throwable $e) {
            error_log('Redis get error: ' . $e->getMessage());
            return null;
        }
    }

    public static function del(string ...$keys): int
    {
        $conn = self::getConnection();
        if (!$conn || empty($keys)) {
            return 0;
        }
        try {
            return (int)$conn->del(...$keys);
        } catch (Throwable $e) {
            error_log('Redis del error: ' . $e->getMessage());
            return 0;
        }
    }

    public static function ttl(string $key): int
    {
        $conn = self::getConnection();
        if (!$conn) {
            return -2;
        }
        try {
            return (int)$conn->ttl($key);
        } catch (Throwable $e) {
            error_log('Redis ttl error: ' . $e->getMessage());
            return -2;
        }
    }
}
