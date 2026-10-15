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

    /** @return ?array{count:int, reset_at:int} */
    private static function readDb(string $key, int $windowSeconds): ?array
    {
        try {
            $pdo = db();
            $cacheKey = 'rl:' . hash('sha256', $key);
            $stmt = $pdo->prepare('SELECT cache_value, expires_at FROM cache WHERE cache_key = ?');
            $stmt->execute([$cacheKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && time() < (int)$row['expires_at']) {
                $data = json_decode((string)$row['cache_value'], true);
                if (is_array($data) && isset($data['count'], $data['reset_at'])) {
                    return $data;
                }
            }
            return ['count' => 0, 'reset_at' => time() + $windowSeconds];
        } catch (Throwable) {
            return null;
        }
    }

    private static function writeDb(string $key, array $data, int $windowSeconds): bool
    {
        try {
            $pdo = db();
            $cacheKey = 'rl:' . hash('sha256', $key);
            $stmt = $pdo->prepare('REPLACE INTO cache (cache_key, cache_value, expires_at) VALUES (?, ?, ?)');
            return $stmt->execute([
                $cacheKey,
                json_encode($data),
                (int) ($data['reset_at'] ?? (time() + $windowSeconds))
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    private static function clearDb(string $key): void
    {
        try {
            $pdo = db();
            $cacheKey = 'rl:' . hash('sha256', $key);
            $pdo->prepare('DELETE FROM cache WHERE cache_key = ?')->execute([$cacheKey]);
        } catch (Throwable) {}
    }

    public static function tooManyAttempts(string $identifier, int $maxAttempts = 5, int $windowSeconds = 300): bool
    {
        $rawKey = self::key($identifier);

        // 1. Redis (Primary distributed engine)
        $redis = function_exists('redis') ? redis() : null;
        if ($redis !== null) {
            try {
                $key = 'rl:' . hash('sha256', $rawKey);
                $count = (int) ($redis->get($key) ?? 0);
                return $count >= $maxAttempts;
            } catch (Throwable) {}
        }

        // 2. MySQL / TiDB Database Cache (Shared across all horizontal web nodes)
        $dbData = self::readDb($rawKey, $windowSeconds);
        if ($dbData !== null) {
            return $dbData['count'] >= $maxAttempts;
        }

        // 3. Disk fallback (Local container failsafe)
        return self::readDisk($rawKey, $windowSeconds)['count'] >= $maxAttempts;
    }

    public static function hit(string $identifier, int $windowSeconds = 300): void
    {
        $rawKey = self::key($identifier);

        // 1. Redis (Primary distributed engine)
        $redis = function_exists('redis') ? redis() : null;
        if ($redis !== null) {
            try {
                $key = 'rl:' . hash('sha256', $rawKey);
                $count = (int) $redis->incr($key);
                if ($count === 1) {
                    $redis->expire($key, $windowSeconds);
                }
                return;
            } catch (Throwable) {}
        }

        // 2. MySQL / TiDB Database Cache (Shared across all horizontal web nodes)
        $dbData = self::readDb($rawKey, $windowSeconds);
        if ($dbData !== null) {
            $dbData['count']++;
            if (self::writeDb($rawKey, $dbData, $windowSeconds)) {
                return;
            }
        }

        // 3. Disk fallback (Local container failsafe)
        $bucket = self::readDisk($rawKey, $windowSeconds);
        $bucket['count']++;
        file_put_contents(self::file($rawKey), json_encode($bucket), LOCK_EX);
    }

    public static function clear(string $identifier): void
    {
        $rawKey = self::key($identifier);

        // 1. Redis
        $redis = function_exists('redis') ? redis() : null;
        if ($redis !== null) {
            try {
                $key = 'rl:' . hash('sha256', $rawKey);
                $redis->del([$key]);
            } catch (Throwable) {}
        }

        // 2. Database Cache
        self::clearDb($rawKey);

        // 3. Disk fallback
        $file = self::file($rawKey);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public static function secondsRemaining(string $identifier, int $windowSeconds = 300): int
    {
        $rawKey = self::key($identifier);

        // 1. Redis
        $redis = function_exists('redis') ? redis() : null;
        if ($redis !== null) {
            try {
                $key = 'rl:' . hash('sha256', $rawKey);
                $ttl = (int) $redis->ttl($key);
                return max(0, $ttl);
            } catch (Throwable) {}
        }

        // 2. Database Cache
        $dbData = self::readDb($rawKey, $windowSeconds);
        if ($dbData !== null) {
            return max(0, $dbData['reset_at'] - time());
        }

        // 3. Disk fallback
        $bucket = self::readDisk($rawKey, $windowSeconds);
        return max(0, $bucket['reset_at'] - time());
    }

    private static function key(string $identifier): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return $ip . ':' . strtolower($identifier);
    }
}
