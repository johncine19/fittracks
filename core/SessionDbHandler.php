<?php
declare(strict_types=1);

class SessionDbHandler implements SessionHandlerInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
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
        $stmt = $this->pdo->prepare('SELECT session_data FROM sessions WHERE session_id = ? AND expires_at > ?');
        $stmt->execute([$id, time()]);
        
        $data = $stmt->fetchColumn();
        return $data !== false ? (string)$data : '';
    }

    public function write(string $id, string $data): bool
    {
        // Default session lifetime is usually governed by ini settings. We'll use 24 hours as a safe default if not set.
        $lifetime = (int) ini_get('session.gc_maxlifetime');
        if ($lifetime <= 0) $lifetime = 86400;
        
        $expiresAt = time() + $lifetime;
        
        $stmt = $this->pdo->prepare('REPLACE INTO sessions (session_id, session_data, expires_at) VALUES (?, ?, ?)');
        return $stmt->execute([$id, $data, $expiresAt]);
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE session_id = ?');
        return $stmt->execute([$id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE expires_at < ?');
        if ($stmt->execute([time()])) {
            return $stmt->rowCount();
        }
        return false;
    }
}
