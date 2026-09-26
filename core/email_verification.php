<?php
declare(strict_types=1);

/**
 * Email verification for self-registered accounts.
 *
 * Deliberately "soft": a new member account works immediately after
 * registration (no login block), but an unverified member sees a
 * persistent reminder banner (see views/layout.php) with a link to verify
 * and a resend option, until they click the emailed link. This avoids
 * breaking the existing registration UX or blocking access if outgoing
 * mail is briefly unavailable, while still nudging real verification.
 *
 * Accounts created directly by an admin (pages/admin/users.php) are
 * marked verified at creation time, since the admin is vouching for the
 * email address.
 */

function create_email_verification_token(int $userId): string
{
    $token = bin2hex(random_bytes(32));
    db()->prepare(
        'REPLACE INTO email_verifications (user_id, token, expires_at) VALUES (?, ?, ?)'
    )->execute([$userId, $token, date('Y-m-d H:i:s', time() + 86400)]);
    return $token;
}

/**
 * Sends the verification email. Returns true on success. Never throws —
 * callers should treat failure as non-fatal (the account still works; the
 * banner will let the user retry).
 */
function send_verification_email(string $email, string $firstName, string $token): bool
{
    Emails::sendVerification($email, $firstName, $token);
    return true;
}

function app_base_url(): string
{
    // 1. Explicitly configured APP_URL (e.g., https://fitworks.tech)
    $appUrl = app_env('APP_URL');
    if (!empty($appUrl)) {
        $clean = rtrim((string) $appUrl, '/');
        if (!str_ends_with($clean, '.php')) {
            $clean .= '/index.php';
        }
        return $clean;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    // 2. Active Web Request Host (e.g., fitworks.tech or localhost)
    if (php_sapi_name() !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        $host = $_SERVER['HTTP_HOST'];
        $scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $dir = dirname($scriptPath);
        $base = ($dir === '/' || $dir === '\\' || $dir === '.') ? '' : rtrim($dir, '/');
        return $scheme . '://' . $host . $base . '/index.php';
    }

    // 3. Fallback to Render external URL if running without HTTP_HOST (e.g. background worker)
    $renderUrl = app_env('RENDER_EXTERNAL_URL');
    if (!empty($renderUrl)) {
        $clean = rtrim((string) $renderUrl, '/');
        if (!str_ends_with($clean, '.php')) {
            $clean .= '/index.php';
        }
        return $clean;
    }

    // 4. Localhost CLI fallback
    $projectFolder = basename(dirname(__DIR__));
    return 'http://localhost/' . $projectFolder . '/index.php';
}

function verify_email_token(string $token): ?int
{
    $row = query_all(
        'SELECT user_id FROM email_verifications WHERE token = ? AND expires_at > NOW()',
        [$token]
    );
    if (!$row) {
        return null;
    }
    $userId = (int) $row[0]['user_id'];
    db()->prepare('UPDATE users SET email_verified_at = NOW() WHERE user_id = ?')->execute([$userId]);
    db()->prepare('DELETE FROM email_verifications WHERE user_id = ?')->execute([$userId]);
    return $userId;
}
