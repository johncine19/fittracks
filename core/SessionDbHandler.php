<?php
declare(strict_types=1);

class SessionDbHandler implements SessionHandlerInterface
{
    private PDO $pdo;
    private string $lastReadHash = '';
    private bool $existedOnRead = false;

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
        $stmt = $this->pdo->prepare('SELECT session_data, expires_at FROM sessions WHERE session_id = ? AND expires_at > ?');
        $stmt->execute([$id, time()]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $data = (string) $row['session_data'];
            $this->lastReadHash = hash('xxh3', $data);
            $this->existedOnRead = ((int) $row['expires_at'] - time()) > 600;
            return $data;
        }
        $this->lastReadHash = '';
        $this->existedOnRead = false;
        return '';
    }

    public function write(string $id, string $data): bool
    {
        if ($data === '') {
            return true;
        }

        if ($this->existedOnRead && hash('xxh3', $data) === $this->lastReadHash) {
            return true;
        }

        $isAuthenticated = str_contains($data, '"user_id"')
            || str_contains($data, 'pending_verify_')
            || str_contains($data, 'reset_email');

        if ($isAuthenticated) {
            $lifetime = max(86400, (int) ini_get('session.gc_maxlifetime'));
        } else {
            $lifetime = 900; // 15 minutes for unauthenticated sessions
        }
        
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
