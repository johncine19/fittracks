<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function handle_forgot_password(): void
{
    if (!defined('AUTH_PAGE')) define('AUTH_PAGE', true);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim((string) post('email'));

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
                            Account Recovery.
                            <span class="highlight">Fast & Secure.</span>
                        </h1>
                        <p class="showcase-desc">
                            Don't worry — we'll help you securely reset your passcode so you can get back to tracking your fitness journey.
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
                                <h4>Instant Verification</h4>
                                <p>We'll send a 6-digit one-time passcode directly to your registered inbox.</p>
                            </div>
                        </div>

                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Encrypted Protection</h4>
                                <p>Single-use security token valid for 15 minutes to keep your account safe.</p>
                            </div>
                        </div>

                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                    <polyline points="22 4 12 14.01 9 11.01"/>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Swift Restoration</h4>
                                <p>Pick a new password and immediately regain full access to your profile.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Side Reset Card Pane -->
            <div class="split-login-card-pane">
                <div class="split-login-card">
                    <div class="split-card-header">
                        <h2 class="split-card-title">Reset Passcode</h2>
                        <p class="split-card-subtitle">Enter your email for <span class="brand-highlight">FitTrack</span> account recovery</p>
                    </div>

                    <form method="post" class="split-card-form" novalidate onsubmit="const btn = this.querySelector('button[type=submit]'); if (btn) { btn.disabled = true; btn.innerHTML = '<svg class=\'fitness-loader mini\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\' style=\'margin-right:8px;\'><line x1=\'6\' y1=\'12\' x2=\'18\' y2=\'12\'></line><rect x=\'4\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'18\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'2\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect><rect x=\'20\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect></svg> SENDING CODE...'; }">
                        <?= csrf_field() ?>

                        <div class="split-form-group">
                            <label>Email Address</label>
                            <div class="split-input-wrap auth-input-group">
                                <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                    <polyline points="22,6 12,13 2,6"></polyline>
                                </svg>
                                <input type="email" name="email" required placeholder="Enter your registered email"
                                       value="<?= h(post('email')) ?>"
                                       oninvalid="this.setCustomValidity('Please enter your registered email address.')"
                                       oninput="this.setCustomValidity('')">
                            </div>
                        </div>

                        <button type="submit" class="split-submit-btn" style="margin-top: 10px;">
                            <span>Send Reset OTP</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                                <polyline points="12 5 19 12 12 19"></polyline>
                            </svg>
                        </button>

                        <div class="split-card-footer" style="margin-top: 24px; text-align: center;">
                            <p style="margin: 0; color: var(--muted); font-size: 14px;">
                                Remember your passcode?
                                <a href="index.php?page=login" style="color: var(--lime); font-weight: 700; text-decoration: none;">Sign In</a>
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
