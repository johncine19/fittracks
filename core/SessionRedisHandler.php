<?php
declare(strict_types=1);

use Predis\Client;

class SessionRedisHandler implements SessionHandlerInterface
{
    private Client $redis;
    private int $ttl;
    private string $prefix = 'sess:';

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
            return $data !== null ? (string)$data : '';
        } catch (Throwable) {
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $lifetime = (int) ini_get('session.gc_maxlifetime');
            if ($lifetime <= 0) $lifetime = $this->ttl;
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
