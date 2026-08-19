<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function handle_forgot_password(): void
{
    if (!defined('AUTH_PAGE')) define('AUTH_PAGE', true);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = post('email');

        if (RateLimiter::tooManyAttempts('forgot:' . $email, 3, 600)) {
            flash('Too many reset requests for this email. Please wait a few minutes and try again.', 'danger');
            redirect('forgot_password');
        }
        RateLimiter::hit('forgot:' . $email, 600);

        $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Block password reset for unverified members
            if ($user['role'] === 'member' && empty($user['email_verified_at'])) {
                flash('Please verify your email address before resetting your password. Check your inbox or sign in to request a new verification email.', 'danger');
                redirect('forgot_password');
            }

            $token = sprintf('%06d', random_int(0, 999999));
            db()->prepare('REPLACE INTO password_resets (email, token, created_at) VALUES (?, ?, NOW())')->execute([$email, $token]);

            Emails::sendPasswordReset(
                $email,
                $user['first_name'] ?? 'Member',
                $token
            );
        }

        // Generic response to prevent user enumeration
        $_SESSION['reset_email'] = $email;
        flash('If an account is associated with this email, an OTP has been sent. The code expires in 15 minutes.', 'success');
        redirect('reset_password');
    }

    render_header('Forgot Password');
    ?>
    <section style="padding: 40px 0;">
        <div class="auth-card">
            <div class="auth-card-header">
                <h1 class="auth-title">FITTRACKS</h1>
                <p class="auth-subtitle">Reset your passcode</p>
            </div>
            <form method="post" class="auth-form" onsubmit="const btn = this.querySelector('button[type=submit]'); btn.disabled = true; btn.innerHTML = '<span class=\'loader\' style=\'width:16px;height:16px;border:2px solid var(--bg);border-bottom-color:transparent;border-radius:50%;display:inline-block;box-sizing:border-box;animation:rotation 1s linear infinite;margin-right:8px;\'></span> SENDING LINK...';">
                <?= csrf_field() ?>
                <div class="auth-field">
                    <label>EMAIL ADDRESS</label>
                    <div class="auth-input-group">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        <input type="email" name="email" required placeholder="Enter your registered email" value="<?= h(post('email')) ?>">
                    </div>
                </div>

                <button type="submit" class="auth-submit-btn">SEND RESET LINK <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg></button>

                <div class="auth-form-footer" style="margin-top: 15px;">
                    Remember your passcode? <a href="index.php?page=login">Sign In</a>
                </div>
            </form>
            <div class="corner corner-tl"></div>
            <div class="corner corner-tr"></div>
            <div class="corner corner-bl"></div>
            <div class="corner corner-br"></div>
        </div>
    </section>
    <?php
    render_footer();
}
