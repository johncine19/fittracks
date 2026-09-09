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
        $otp = trim((string) post('otp'));
        $password = (string) post('password');

        if (RateLimiter::tooManyAttempts('reset_otp:' . $reset_email, 5, 600)) {
            flash('Too many attempts. Please request a new OTP and try again shortly.', 'danger');
            render_header('Reset Password');
            ?>
            <div class="split-login-viewport">
                <div class="split-login-frame">
                    <div class="split-login-card-pane" style="max-width: 500px; margin: 0 auto;">
                        <div class="split-login-card">
                            <h2 class="split-card-title">Too Many Attempts</h2>
                            <p class="split-card-subtitle" style="margin-bottom: 20px;">Please wait a few minutes before requesting a new OTP.</p>
                            <a href="index.php?page=forgot_password" class="split-submit-btn" style="text-decoration:none; display:flex; justify-content:center; align-items:center;">Request New OTP</a>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            render_footer();
            return;
        }

        // Verify OTP (must be within 15 minutes)
        $stmt = db()->prepare('SELECT token FROM password_resets WHERE email = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
        $stmt->execute([$reset_email]);
        $reset = $stmt->fetch();

        if (!$reset || !hash_equals((string) $reset['token'], (string) $otp)) {
            RateLimiter::hit('reset_otp:' . $reset_email, 600);
            flash('Invalid or expired OTP. Please request a new one.', 'danger');
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
    <div class="split-login-viewport">
        <div class="split-login-frame">
            <!-- Left Hero Showcase -->
            <div class="split-login-showcase">
                <!-- Decorative Dot Matrix SVG -->
                <svg class="showcase-decor-dots" width="70" height="70" viewBox="0 0 70 70" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="10" cy="10" r="2.5" fill="#ffffff" />
                    <circle cx="26" cy="10" r="2.5" fill="#ffffff" />
                    <circle cx="42" cy="10" r="2.5" fill="#ffffff" />
                    <circle cx="58" cy="10" r="2.5" fill="#ffffff" />
                    <circle cx="10" cy="26" r="2.5" fill="#ffffff" />
                    <circle cx="26" cy="26" r="2.5" fill="#ffffff" />
                    <circle cx="42" cy="26" r="2.5" fill="#ffffff" />
                    <circle cx="58" cy="26" r="2.5" fill="#ffffff" />
                    <circle cx="10" cy="42" r="2.5" fill="#ffffff" />
                    <circle cx="26" cy="42" r="2.5" fill="#ffffff" />
                    <circle cx="42" cy="42" r="2.5" fill="#ffffff" />
                    <circle cx="58" cy="42" r="2.5" fill="#ffffff" />
                    <circle cx="10" cy="58" r="2.5" fill="#ffffff" />
                    <circle cx="26" cy="58" r="2.5" fill="#ffffff" />
                    <circle cx="42" cy="58" r="2.5" fill="#ffffff" />
                    <circle cx="58" cy="58" r="2.5" fill="#ffffff" />
                </svg>

                <!-- Decorative Diagonal Speed Stripes SVG -->
                <svg class="showcase-decor-stripes" viewBox="0 0 260 260" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <polygon points="120,260 220,0 260,0 160,260" fill="url(#limeGradient1)" opacity="0.65" />
                    <polygon points="40,260 140,0 170,0 70,260" fill="url(#limeGradient2)" opacity="0.45" />
                    <polygon points="0,260 90,0 110,0 20,260" fill="url(#limeGradient1)" opacity="0.25" />
                    <defs>
                        <linearGradient id="limeGradient1" x1="0%" y1="100%" x2="100%" y2="0%">
                            <stop offset="0%" stop-color="#4d7c0f" />
                            <stop offset="50%" stop-color="#84cc16" />
                            <stop offset="100%" stop-color="#bef264" />
                        </linearGradient>
                        <linearGradient id="limeGradient2" x1="0%" y1="100%" x2="100%" y2="0%">
                            <stop offset="0%" stop-color="#3f6212" />
                            <stop offset="100%" stop-color="#a3e635" />
                        </linearGradient>
                    </defs>
                </svg>

                <div class="split-login-showcase-content">
                    <!-- Top Brand Header -->
                    <div class="showcase-brand">
                        <div class="showcase-brand-icon">
                            <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: 100%; height: 100%;">
                                <path d="M6 8 L32 8 L29 14 L15 14 L13 18 L26 18 L23 24 L10 24 L5 34 L1 34 L6 8 Z" fill="#84cc16" />
                                <polygon points="12,5 36,5 34,9 10,9" fill="#a3e635" opacity="0.8" />
                                <polygon points="2,32 10,32 8,36 0,36" fill="#65a30d" />
                            </svg>
                        </div>
                        <div class="showcase-brand-text">
                            <div class="showcase-brand-name">FIT<span>TRACK</span></div>
                            <div class="showcase-brand-tagline">Manage. Engage. Grow.</div>
                        </div>
                    </div>

                    <!-- Middle Headline & Copy -->
                    <div class="showcase-hero-copy">
                        <h1 class="showcase-title">
                            Set New Passcode.
                            <span class="highlight">Almost Done!</span>
                        </h1>
                        <p class="showcase-desc">
                            Enter the 6-digit verification code sent to <strong style="color:var(--lime);"><?= h($reset_email) ?></strong> and choose a strong new passcode.
                        </p>
                    </div>

                    <!-- Three Feature Items -->
                    <div class="showcase-features">
                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Strong Password Protection</h4>
                                <p>Minimum 8 characters with numbers and letters for fortified security.</p>
                            </div>
                        </div>

                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"/>
                                    <polyline points="12 6 12 12 16 14"/>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>15-Minute Expiry</h4>
                                <p>Your OTP is short-lived to safeguard your account against unauthorized use.</p>
                            </div>
                        </div>

                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Instant Session Re-entry</h4>
                                <p>Once updated, you can immediately sign in with your new credentials.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Side Reset Form Card -->
            <div class="split-login-card-pane">
                <div class="split-login-card">
                    <div class="split-card-header">
                        <h2 class="split-card-title">New Passcode</h2>
                        <p class="split-card-subtitle">Verify OTP & update your <span class="brand-highlight">FitTrack</span> passcode</p>
                    </div>

                    <form method="post" class="split-card-form" novalidate onsubmit="const btn = this.querySelector('button[type=submit]'); if (btn) { btn.disabled = true; btn.innerHTML = '<svg class=\'fitness-loader mini\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\' style=\'margin-right:8px;\'><line x1=\'6\' y1=\'12\' x2=\'18\' y2=\'12\'></line><rect x=\'4\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'18\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'2\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect><rect x=\'20\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect></svg> UPDATING...'; }">
                        <?= csrf_field() ?>

                        <div class="split-form-group">
                            <label>6-Digit OTP Code</label>
                            <div class="split-input-wrap auth-input-group">
                                <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                                <input type="text" name="otp" required placeholder="Enter 6-digit code" pattern="\d{6}" maxlength="6"
                                       value="<?= h(post('otp')) ?>"
                                       style="letter-spacing: 0.2em; font-weight: 700; font-size: 16px;"
                                       oninvalid="this.setCustomValidity('Please enter the 6-digit OTP code.')"
                                       oninput="this.setCustomValidity('')">
                            </div>
                        </div>

                        <div class="split-form-group">
                            <label>New Passcode</label>
                            <div class="split-input-wrap auth-input-group">
                                <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                                <input type="password" id="reset-password-field" name="password" required placeholder="Min. 8 characters (letters & numbers)"
                                       oninvalid="this.setCustomValidity('Password must be at least 8 characters with letters and numbers.')"
                                       oninput="this.setCustomValidity('')">
                            </div>
                            <span style="font-size: 11px; color: var(--muted); margin-top: 4px; display: block;">Must include at least one letter and one number.</span>
                        </div>

                        <button type="submit" class="split-submit-btn" style="margin-top: 12px;">
                            <span>Update Passcode</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                                <polyline points="12 5 19 12 12 19"></polyline>
                            </svg>
                        </button>

                        <div class="split-card-footer" style="margin-top: 20px; display: flex; flex-direction: column; gap: 8px; text-align: center;">
                            <p style="margin: 0; color: var(--muted); font-size: 13px;">
                                Didn't receive the code?
                                <a href="index.php?page=forgot_password" style="color: var(--lime); font-weight: 700; text-decoration: none;">Request new OTP</a>
                            </p>
                            <p style="margin: 0; font-size: 13px;">
                                <a href="index.php?page=login" style="color: var(--muted); text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 5px;" onmouseover="this.style.color='var(--lime)'" onmouseout="this.style.color='var(--muted)'">
                                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                                    Back to Sign In
                                </a>
                            </p>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
    render_footer();
}
