<?php
declare(strict_types=1);

/**
 * High-performance Rate Limiter.
 * Uses Redis atomic INCR + EXPIRE when available; falls back to disk storage.
 */
final class RateLimiter
{
    private static function dir(): string
    {
        $dir = __DIR__ . '/../storage/ratelimit';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function file(string $key): string
    {
        return self::dir() . '/' . hash('sha256', $key) . '.json';
    }

    /** @return array{count:int, reset_at:int} */
    private static function readDisk(string $key, int $windowSeconds): array
    {
        $file = self::file($key);
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && isset($data['count'], $data['reset_at']) && $data['reset_at'] >= time()) {
                return $data;
            }
        }
        return ['count' => 0, 'reset_at' => time() + $windowSeconds];
    }

    public static function tooManyAttempts(string $identifier, int $maxAttempts = 5, int $windowSeconds = 300): bool
    {
        $redis = function_exists('redis') ? redis() : null;
        if ($redis !== null) {
            try {
                $key = 'rl:' . hash('sha256', self::key($identifier));
                $count = (int) ($redis->get($key) ?? 0);
                return $count >= $maxAttempts;
            } catch (Throwable) {}
        }

        $key = self::key($identifier);
        return self::readDisk($key, $windowSeconds)['count'] >= $maxAttempts;
    }

    public static function hit(string $identifier, int $windowSeconds = 300): void
    {
        $redis = function_exists('redis') ? redis() : null;
        if ($redis !== null) {
            try {
                $key = 'rl:' . hash('sha256', self::key($identifier));
                $count = (int) $redis->incr($key);
                if ($count === 1) {
                    $redis->expire($key, $windowSeconds);
                }
                return;
            } catch (Throwable) {}
        }

        $key = self::key($identifier);
        $bucket = self::readDisk($key, $windowSeconds);
        $bucket['count']++;
        file_put_contents(self::file($key), json_encode($bucket), LOCK_EX);
    }

    public static function clear(string $identifier): void
    {
        $redis = function_exists('redis') ? redis() : null;
        if ($redis !== null) {
            try {
                $key = 'rl:' . hash('sha256', self::key($identifier));
                $redis->del([$key]);
            } catch (Throwable) {}
        }

        $file = self::file(self::key($identifier));
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public static function secondsRemaining(string $identifier, int $windowSeconds = 300): int
    {
        $redis = function_exists('redis') ? redis() : null;
        if ($redis !== null) {
            try {
                $key = 'rl:' . hash('sha256', self::key($identifier));
                $ttl = (int) $redis->ttl($key);
                return max(0, $ttl);
            } catch (Throwable) {}
        }

        $bucket = self::readDisk(self::key($identifier), $windowSeconds);
        return max(0, $bucket['reset_at'] - time());
    }

    private static function key(string $identifier): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return $ip . ':' . strtolower($identifier);
    }
}
