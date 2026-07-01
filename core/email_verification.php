<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

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
    $link = app_base_url() . '?page=verify_email&token=' . urlencode($token);

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USER'] ?? '';
        $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int) ($_ENV['SMTP_PORT'] ?? 587);

        $mail->setFrom($_ENV['SMTP_FROM'] ?? 'no-reply@fittracks.com', $_ENV['SMTP_FROM_NAME'] ?? 'FITTRACKS');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Verify your FITTRACKS email address';
        $mail->Body    = 'Hi ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . ',<br><br>'
            . 'Please confirm your email address by clicking the link below:<br>'
            . '<a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Verify my email</a><br><br>'
            . 'This link expires in 24 hours.';

        $mail->send();
        return true;
    } catch (PHPMailerException) {
        return false;
    }
}

function app_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(str_replace('index.php', '', $_SERVER['SCRIPT_NAME'] ?? ''), '/');
    return $scheme . '://' . $host . $path . '/index.php';
}

function verify_email_token(string $token): bool
{
    $row = query_all(
        'SELECT user_id FROM email_verifications WHERE token = ? AND expires_at > NOW()',
        [$token]
    );
    if (!$row) {
        return false;
    }
    $userId = (int) $row[0]['user_id'];
    db()->prepare('UPDATE users SET email_verified_at = NOW() WHERE user_id = ?')->execute([$userId]);
    db()->prepare('DELETE FROM email_verifications WHERE user_id = ?')->execute([$userId]);
    return true;
}
