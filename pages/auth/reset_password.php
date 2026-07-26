<?php
declare(strict_types=1);

function handle_reset_password(): void
{
    if (!defined('AUTH_PAGE')) define('AUTH_PAGE', true);
    $reset_email = $_SESSION['reset_email'] ?? '';
    if (!$reset_email) {
        flash('Please request a new OTP.', 'danger');
        redirect('forgot_password');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $otp = post('otp');
        $password = post('password');

        if (RateLimiter::tooManyAttempts('reset_otp:' . $reset_email, 5, 600)) {
            flash('Too many attempts. Please request a new OTP and try again shortly.', 'danger');
            render_header('Reset Password');
            echo '<section class="panel"><h1>Too many attempts</h1><p>Please request a new OTP.</p></section>';
            render_footer();
            return;
        }

        // Verify OTP
        $stmt = db()->prepare('SELECT token FROM password_resets WHERE email = ?');
        $stmt->execute([$reset_email]);
        $reset = $stmt->fetch();

        if (!$reset || !hash_equals((string) $reset['token'], (string) $otp)) {
            RateLimiter::hit('reset_otp:' . $reset_email, 600);
            flash('Invalid or expired OTP.', 'danger');
        } elseif (!is_acceptable_password((string) $password)) {
            flash('Password must be at least 8 characters with a letter and a number, and not a common password.', 'danger');
        } else {
            // Update password
            $hash = password_hash((string) $password, PASSWORD_DEFAULT);
            db()->prepare('UPDATE users SET password_hash = ? WHERE email = ?')->execute([$hash, $reset_email]);

            // Delete token
            db()->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$reset_email]);
            RateLimiter::clear('reset_otp:' . $reset_email);
            unset($_SESSION['reset_email']);

            flash('Your password has been reset successfully. You can now log in.', 'success');
            redirect('login');
        }
    }

    render_header('Reset Password');
    ?>
    <section style="padding: 40px 0;">
        <div class="auth-card">
            <div class="auth-card-header">
                <h1 class="auth-title">FITTRACKS</h1>
                <p class="auth-subtitle">Create a new passcode</p>
            </div>
            <form method="post" class="auth-form" onsubmit="const btn = this.querySelector('button[type=submit]'); btn.disabled = true; btn.innerHTML = '<span class=\'loader\' style=\'width:16px;height:16px;border:2px solid var(--bg);border-bottom-color:transparent;border-radius:50%;display:inline-block;box-sizing:border-box;animation:rotation 1s linear infinite;margin-right:8px;\'></span> UPDATING...';">
                <?= csrf_field() ?>
                <div class="auth-field">
                    <label>6-DIGIT OTP</label>
                    <div class="auth-input-group">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        <input type="text" name="otp" required placeholder="Enter 6-digit code" pattern="\d{6}" maxlength="6" value="<?= h(post('otp')) ?>">
                    </div>
                </div>

                <div class="auth-field">
                    <label>NEW PASSCODE</label>
                    <div class="auth-input-group">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        <input type="password" name="password" required placeholder="Min. 8 characters, with a letter and a number">
                    </div>
                </div>
                <p class="muted" style="font-size:12px;margin-top:-8px;">Must include at least one letter and one number.</p>

                <button type="submit" class="auth-submit-btn">UPDATE PASSWORD <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg></button>
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
