<?php
declare(strict_types=1);

/**
 * Simple file-based rate limiter for login brute-force protection.
 *
 * Deliberately NOT session-based: an attacker can clear their own session
 * cookie at will, which would defeat a session-stored counter. Instead,
 * attempts are tracked server-side in storage/ratelimit/, keyed by a
 * combination of the client IP and the identifier being attacked (e.g. the
 * email address), so a counter survives across requests/cookies.
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
    private static function read(string $key, int $windowSeconds): array
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
        $key = self::key($identifier);
        return self::read($key, $windowSeconds)['count'] >= $maxAttempts;
    }

    public static function hit(string $identifier, int $windowSeconds = 300): void
    {
        $key = self::key($identifier);
        $bucket = self::read($key, $windowSeconds);
        $bucket['count']++;
        file_put_contents(self::file($key), json_encode($bucket), LOCK_EX);
    }

    public static function clear(string $identifier): void
    {
        $file = self::file(self::key($identifier));
        if (is_file($file)) {
            unlink($file);
        }
    }

    public static function secondsRemaining(string $identifier, int $windowSeconds = 300): int
    {
        $bucket = self::read(self::key($identifier), $windowSeconds);
        return max(0, $bucket['reset_at'] - time());
    }

    private static function key(string $identifier): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return $ip . ':' . strtolower($identifier);
    }
}
