<?php
declare(strict_types=1);

function current_user(bool $forceRefresh = false): ?array
{
    static $cachedUser = null;
    static $cachedUserId = null;

    $sessionUserId = $_SESSION['user_id'] ?? null;
    if (empty($sessionUserId)) {
        $restored = restore_user_from_remember_cookie();
        if ($restored !== null) {
            $cachedUser = $restored;
            $cachedUserId = (int) $restored['user_id'];
            return $cachedUser;
        }
        $cachedUser = null;
        $cachedUserId = null;
        return null;
    }

    if (!$forceRefresh && $cachedUser !== null && $cachedUserId === $sessionUserId) {
        return $cachedUser;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE user_id = ? AND status = "active"');
    $stmt->execute([$sessionUserId]);
    $user = $stmt->fetch();
    $cachedUser = $user ?: null;
    $cachedUserId = $sessionUserId;
    return $cachedUser;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        $requestedPage = (string) ($_GET['page'] ?? '');
        if ($requestedPage !== '' && !in_array($requestedPage, ['login', 'logout', 'register', 'verify_email', 'verify_waiting', 'reset_password'], true)) {
            $_SESSION['return_to'] = $requestedPage;
        }
        redirect('login');
    }

    $page = $_GET['page'] ?? 'dashboard';
    
    // Auto-expire member subscriptions on-the-fly if lapsed (throttled to once per session per hour or on plan pages to prevent write-lock churn)
    if ($user['role'] === 'member') {
        $lastMemberExpiryCheck = (int)($_SESSION['last_member_expiry_check'] ?? 0);
        if ((time() - $lastMemberExpiryCheck > 3600 || in_array($page, ['memberships', 'my_plans'], true)) && function_exists('auto_expire_lapsed_member_subscriptions')) {
            auto_expire_lapsed_member_subscriptions((int) $user['user_id']);
            $_SESSION['last_member_expiry_check'] = time();
        }
    }

    // Globally enforce physical profile + goal setup for members
    if ($user['role'] === 'member' && !in_array($page, ['setup_profile', 'setup_goal', 'logout'], true)) {
        $setupSessionKey = 'profile_setup_ok_' . (int) $user['user_id'];
        if (empty($_SESSION[$setupSessionKey])) {
            $memberProfile = member_profile((int) $user['user_id']);
            if ($memberProfile === null) {
                redirect('setup_profile');
            } elseif (empty($memberProfile['primary_goal'])) {
                redirect('setup_goal');
            }
            $_SESSION[$setupSessionKey] = true;
        }
    }

    // Handle Gym Owner onboarding & subscription interception
    if ($user['role'] === 'gym_owner' && !in_array($page, ['gym_onboarding', 'gym_pending', 'gym_rejected', 'gym_subscription', 'logout'], true)) {
        $gym = get_user_gym($user);
        $lastOwnerExpiryCheck = (int)($_SESSION['last_owner_expiry_check'] ?? 0);
        if ($gym && (time() - $lastOwnerExpiryCheck > 3600 || $page === 'gym_subscription') && function_exists('auto_expire_lapsed_gym_subscriptions')) {
            auto_expire_lapsed_gym_subscriptions((int) $gym['gym_id']);
            $_SESSION['last_owner_expiry_check'] = time();
            $gym = get_user_gym($user, true);
        }
        
        if (!$gym) {
            redirect('gym_onboarding');
        } elseif ($gym['status'] === 'pending') {
            redirect('gym_pending');
        } elseif ($gym['status'] === 'rejected') {
            redirect('gym_rejected');
        } elseif ($gym['status'] === 'suspended') {
            flash('Your gym account has been suspended by platform administration. Please contact support.', 'danger');
            redirect('logout');
        }
        // Approved gym owners have immediate access to dashboard (Free Trial or Limited Free Account)
    }

    return $user;
}

function can(array $user, array $roles): bool
{
    return in_array($user['role'], $roles, true);
}

function require_roles(array $roles): array
{
    $user = require_login();
    if (!can($user, $roles)) {
        http_response_code(403);
        render_header('Access denied', $user);
        echo '<section class="panel"><h1>Access denied</h1><p>Your account does not have permission to open this page.</p></section>';
        render_footer();
        exit;
    }
    return $user;
}

function handle_logout(): never
{
    $silent = isset($_GET['silent']) || (isset($_SERVER['HTTP_REFERER']) && str_contains($_SERVER['HTTP_REFERER'], 'gym_rejected'));
    clear_remember_token();
    unset($_SESSION['return_to']);
    session_destroy();
    session_start();
    if (!$silent) {
        flash('You have successfully logged out.', 'success');
    }
    redirect('login');
}

function remember_cookie_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['HTTP_CF_VISITOR']) && str_contains($_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"'));
}

function ensure_remember_tokens_table(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_remember_tokens (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            selector CHAR(24) NOT NULL UNIQUE,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_remember_user (user_id),
            INDEX idx_remember_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ensured = true;
}

function set_remember_cookie(string $value, int $expiresAt): void
{
    if (headers_sent()) {
        return;
    }
    setcookie('ft_remember', $value, [
        'expires' => $expiresAt,
        'path' => '/',
        'secure' => remember_cookie_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Issue a 30-day selector+validator remember-me token in MySQL and set the HttpOnly cookie.
 */
function issue_remember_token(int $userId, int $days = 30): void
{
    if ($userId <= 0) {
        return;
    }
    try {
        $pdo = db();
        ensure_remember_tokens_table($pdo);

        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $validator);
        $expiresTs = time() + ($days * 86400);
        $expiresAt = date('Y-m-d H:i:s', $expiresTs);

        // Clean up expired tokens opportunistically for this user in one query
        $pdo->prepare('DELETE FROM user_remember_tokens WHERE user_id = ? AND expires_at <= NOW()')->execute([$userId]);
        $pdo->prepare('INSERT INTO user_remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $selector, $tokenHash, $expiresAt]);

        set_remember_cookie($selector . ':' . $validator, $expiresTs);
    } catch (Throwable $e) {
        error_log('[RememberMe] Failed to issue token: ' . $e->getMessage());
    }
}

/**
 * Revoke the current browser's remember-me token (if any) and clear the cookie.
 */
function clear_remember_token(): void
{
    $raw = (string) ($_COOKIE['ft_remember'] ?? '');
    if ($raw !== '' && str_contains($raw, ':')) {
        [$selector] = explode(':', $raw, 2);
        if (preg_match('/^[a-f0-9]{24}$/', $selector)) {
            try {
                $pdo = db();
                ensure_remember_tokens_table($pdo);
                $pdo->prepare('DELETE FROM user_remember_tokens WHERE selector = ?')->execute([$selector]);
            } catch (Throwable $e) {
                // Non-fatal
            }
        }
    }
    unset($_COOKIE['ft_remember']);
    set_remember_cookie('', time() - 3600);
}

/**
 * Restore an authenticated session from the ft_remember cookie using a single JOIN query (no N+1).
 */
function restore_user_from_remember_cookie(): ?array
{
    $raw = (string) ($_COOKIE['ft_remember'] ?? '');
    if ($raw === '' || !str_contains($raw, ':')) {
        return null;
    }

    [$selector, $validator] = explode(':', $raw, 2);
    if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
        clear_remember_token();
        return null;
    }

    try {
        $pdo = db();
        ensure_remember_tokens_table($pdo);

        // Single JOIN query fetches both token and user row in one round-trip
        $stmt = $pdo->prepare('
            SELECT t.id AS _remember_id, t.token_hash AS _remember_hash, t.expires_at AS _remember_expires, u.*
            FROM user_remember_tokens t
            JOIN users u ON u.user_id = t.user_id
            WHERE t.selector = ?
            LIMIT 1
        ');
        $stmt->execute([$selector]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || ($row['status'] ?? '') !== 'active' || strtotime((string) $row['_remember_expires']) <= time()) {
            if ($row) {
                $pdo->prepare('DELETE FROM user_remember_tokens WHERE id = ?')->execute([(int) $row['_remember_id']]);
            }
            clear_remember_token();
            return null;
        }

        if (!hash_equals((string) $row['_remember_hash'], hash('sha256', $validator))) {
            // Possible token theft/mismatch: revoke this selector immediately
            $pdo->prepare('DELETE FROM user_remember_tokens WHERE id = ?')->execute([(int) $row['_remember_id']]);
            clear_remember_token();
            return null;
        }

        // Rotate validator on use to prevent replay attacks
        $newValidator = bin2hex(random_bytes(32));
        $newHash = hash('sha256', $newValidator);
        $expiresTs = time() + (30 * 86400);
        $pdo->prepare('UPDATE user_remember_tokens SET token_hash = ?, expires_at = ? WHERE id = ?')
            ->execute([$newHash, date('Y-m-d H:i:s', $expiresTs), (int) $row['_remember_id']]);
        set_remember_cookie($selector . ':' . $newValidator, $expiresTs);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = (int) $row['user_id'];

        unset($row['_remember_id'], $row['_remember_hash'], $row['_remember_expires']);
        return $row;
    } catch (Throwable $e) {
        error_log('[RememberMe] Failed to restore session: ' . $e->getMessage());
        return null;
    }
}

/**
 * Show the dashboard welcome banner only on a user's first login and mark it seen.
 */
function consume_first_login_welcome_banner(array $user): bool
{
    $userId = (int) ($user['user_id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }

    $pdo = db();
    if (!array_key_exists('welcome_banner_seen', $user)) {
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM users LIKE "welcome_banner_seen"')->fetchAll();
            if (empty($cols)) {
                $pdo->exec('ALTER TABLE users ADD COLUMN welcome_banner_seen TINYINT(1) NOT NULL DEFAULT 0');
            }
            $stmt = $pdo->prepare('SELECT welcome_banner_seen FROM users WHERE user_id = ?');
            $stmt->execute([$userId]);
            $seen = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    } else {
        $seen = (int) $user['welcome_banner_seen'];
    }

    if ($seen === 1) {
        return false;
    }

    try {
        $pdo->prepare('UPDATE users SET welcome_banner_seen = 1 WHERE user_id = ?')->execute([$userId]);
        current_user(true);
    } catch (Throwable $e) {
        // Non-fatal; client-side storage still prevents repeat prompts in the same browser.
    }

    return true;
}



