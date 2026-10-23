<?php
declare(strict_types=1);

use Predis\Client;

class SessionRedisHandler implements SessionHandlerInterface
{
    private Client $redis;
    private int $ttl;
    private string $prefix = 'sess:';
    private string $lastReadHash = '';

    public function __construct(Client $redis, int $ttl = 86400)
    {
        $this->redis = $redis;
        $this->ttl = $ttl;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $data = $this->redis->get($this->prefix . $id);
            if ($data !== null) {
                $str = (string) $data;
                $this->lastReadHash = hash('xxh3', $str);
                return $str;
            }
            $this->lastReadHash = '';
            return '';
        } catch (Throwable) {
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        if ($data === '') {
            return true;
        }

        try {
            $isAuthenticated = str_contains($data, '"user_id"')
                || str_contains($data, 'pending_verify_')
                || str_contains($data, 'reset_email');

            if ($isAuthenticated) {
                $lifetime = (int) ini_get('session.gc_maxlifetime');
                if ($lifetime <= 0) $lifetime = $this->ttl;
            } else {
                // Keep anonymous/CSRF-only sessions short-lived (15m) to protect Redis RAM during bot floods
                $lifetime = 900;
                if ($this->lastReadHash !== '' && hash('xxh3', $data) === $this->lastReadHash) {
                    return true;
                }
            }

            $this->redis->setex($this->prefix . $id, $lifetime, $data);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->redis->del([$this->prefix . $id]);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        // Redis natively handles key expiration via TTL (setex)
        return 0;
    }
}
