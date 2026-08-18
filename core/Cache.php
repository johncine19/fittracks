<?php
declare(strict_types=1);

class Cache
{
    /**
     * Get an item from the cache, or execute the given Closure and store the result.
     * Uses Redis if available; falls back to MySQL database cache.
     *
     * @param string $key
     * @param int $ttl Seconds until expiration
     * @param Closure $callback
     * @return mixed
     */
    public static function remember(string $key, int $ttl, Closure $callback)
    {
        $redis = function_exists('redis') ? redis() : null;

        if ($redis !== null) {
            try {
                $cached = $redis->get('cache:' . $key);
                if ($cached !== null) {
                    return json_decode($cached, true);
                }

                $value = $callback();
                $redis->setex('cache:' . $key, $ttl, json_encode($value));
                return $value;
            } catch (Throwable $e) {
                error_log('Redis cache error, falling back to DB: ' . $e->getMessage());
            }
        }

        // MySQL database fallback
        $pdo = db();
        $stmt = $pdo->prepare('SELECT cache_value, expires_at FROM cache WHERE cache_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && time() < (int)$row['expires_at']) {
            return json_decode($row['cache_value'], true);
        }

        $value = $callback();

        $stmt = $pdo->prepare('REPLACE INTO cache (cache_key, cache_value, expires_at) VALUES (?, ?, ?)');
        $stmt->execute([
            $key,
            json_encode($value),
            time() + $ttl
        ]);

        return $value;
    }

    public static function forget(string $key): void
    {
        $redis = function_exists('redis') ? redis() : null;
        if ($redis !== null) {
            try {
                $redis->del(['cache:' . $key]);
            } catch (Throwable) {}
        }

        try {
            $pdo = db();
            $stmt = $pdo->prepare('DELETE FROM cache WHERE cache_key = ?');
            $stmt->execute([$key]);
        } catch (Throwable) {}
    }
}
