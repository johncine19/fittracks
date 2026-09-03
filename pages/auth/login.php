<?php

declare(strict_types=1);

function handle_login(): void
{
    if (!defined('AUTH_PAGE')) define('AUTH_PAGE', true);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = (string) post('email');

        if (RateLimiter::tooManyAttempts($email, 5, 300)) {
            $secondsLeft = RateLimiter::secondsRemaining($email, 300);
            $wait = (int) ceil($secondsLeft / 60);
            flash("Too many login attempts. Please try again in about $wait minute(s).", 'danger');
            render_header('Sign in');
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
                                    Smarter Gym Management.
                                    <span class="highlight">Stronger Community.</span>
                                </h1>
                                <p class="showcase-desc">
                                    FitTrack helps gyms streamline operations, monitor member engagement, and drive results.
                                </p>
                            </div>

                            <!-- Three Feature Items -->
                            <div class="showcase-features">
                                <div class="showcase-feature-item">
                                    <div class="showcase-feature-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                            <line x1="18" y1="20" x2="18" y2="10"></line>
                                            <line x1="12" y1="20" x2="12" y2="4"></line>
                                            <line x1="6" y1="20" x2="6" y2="14"></line>
                                        </svg>
                                    </div>
                                    <div class="showcase-feature-body">
                                        <h4>Track Attendance</h4>
                                        <p>Monitor member check-ins and activity in real-time.</p>
                                    </div>
                                </div>

                                <div class="showcase-feature-item">
                                    <div class="showcase-feature-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                            <circle cx="9" cy="7" r="4"></circle>
                                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                        </svg>
                                    </div>
                                    <div class="showcase-feature-body">
                                        <h4>Engage Members</h4>
                                        <p>Boost engagement and retention with meaningful insights.</p>
                                    </div>
                                </div>

                                <div class="showcase-feature-item">
                                    <div class="showcase-feature-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="10"></circle>
                                            <polyline points="12 6 12 12 16 14"></polyline>
                                        </svg>
                                    </div>
                                    <div class="showcase-feature-body">
                                        <h4>Data-Driven Decisions</h4>
                                        <p>Turn data into actionable strategies for your gym's growth.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Side Lockout Card -->
                    <div class="split-login-card-pane">
                        <div class="split-login-card" style="text-align: center;">
                            <div class="split-card-header">
                                <h2 class="split-card-title">Security Lockout</h2>
                                <p class="split-card-subtitle">Please wait before trying again</p>
                            </div>
                            
                            <div style="margin: 30px 0;">
                                <div id="lockout-timer" style="font-size: 2.8rem; font-weight: 800; color: #65a30d; font-variant-numeric: tabular-nums; letter-spacing: 2px;">--:--</div>
                                <p style="color: #64748b; font-size: 13.5px; margin-top: 15px; line-height: 1.5;">
                                    For your security, your account is temporarily locked due to too many failed attempts.
                                </p>
                            </div>

                            <div class="split-card-footer">
                                <a href="index.php" class="home-link">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                                    Back to Home
                                </a>
                            </div>

                            <script>
                                let secondsLeft = <?= (int) $secondsLeft ?>;
                                const timerEl = document.getElementById('lockout-timer');
                                
                                function updateTimer() {
                                    if (secondsLeft <= 0) {
                                        timerEl.textContent = "0:00";
                                        window.location.href = 'index.php?page=login';
                                        return;
                                    }
                                    const m = Math.floor(secondsLeft / 60);
                                    const s = secondsLeft % 60;
                                    timerEl.textContent = m + ':' + (s < 10 ? '0' : '') + s;
                                    secondsLeft--;
                                }
                                
                                updateTimer();
                                setInterval(updateTimer, 1000);
                            </script>
                        </div>
                    </div>
                </div>
            </div>
    <?php
            render_footer();
            return;
        }

        $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify((string) post('password'), $user['password_hash'])) {
            if ($user['status'] === 'suspended' || $user['status'] === 'inactive') {
                RateLimiter::hit($email, 300);
                flash('Your account has been suspended. Please contact support.', 'danger');
                redirect('login');
            }

            // Block unverified members from accessing the app
            if ($user['role'] === 'member' && empty($user['email_verified_at'])) {
                $_SESSION['pending_verify_uid'] = (int) $user['user_id'];
                flash('Please verify your email address before signing in. Check your inbox or use the resend option below.', 'danger');
                redirect('login');
            }

            // Gym owner routing is now handled by core/auth.php require_login()
            RateLimiter::clear($email);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['user_id'];
            unset($_SESSION['pending_verify_uid']);

            // Only flash "Welcome back" for approved users to avoid confusing alerts on rejected/pending/onboarding screens
            $shouldFlashWelcome = true;
            if ($user['role'] === 'gym_owner') {
                $gymCheck = db()->query('SELECT status FROM gyms WHERE owner_user_id = ' . (int)$user['user_id'])->fetch();
                if (!$gymCheck || in_array($gymCheck['status'], ['pending', 'rejected'], true)) {
                    $shouldFlashWelcome = false;
                }
            }

            if ($shouldFlashWelcome) {
                flash('Welcome back, ' . $user['first_name'] . '!', 'success');
            }
            redirect('dashboard');
        }
        RateLimiter::hit($email, 300);
        flash('Invalid email or password.', 'danger');
    }

    $pendingVerify = isset($_SESSION['pending_verify_uid']);
    render_header('Sign in');
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
                            Smarter Gym Management.
                            <span class="highlight">Stronger Community.</span>
                        </h1>
                        <p class="showcase-desc">
                            FitTrack helps gyms streamline operations, monitor member engagement, and drive results.
                        </p>
                    </div>

                    <!-- Three Feature Items -->
                    <div class="showcase-features">
                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="18" y1="20" x2="18" y2="10"></line>
                                    <line x1="12" y1="20" x2="12" y2="4"></line>
                                    <line x1="6" y1="20" x2="6" y2="14"></line>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Track Attendance</h4>
                                <p>Monitor member check-ins and activity in real-time.</p>
                            </div>
                        </div>

                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="9" cy="7" r="4"></circle>
                                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Engage Members</h4>
                                <p>Boost engagement and retention with meaningful insights.</p>
                            </div>
                        </div>

                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <polyline points="12 6 12 12 16 14"></polyline>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Data-Driven Decisions</h4>
                                <p>Turn data into actionable strategies for your gym's growth.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Side Login Card Pane -->
            <div class="split-login-card-pane">
                <div class="split-login-card">
                    <div class="split-card-header">
                        <h2 class="split-card-title">Welcome Back!</h2>
                        <p class="split-card-subtitle">Log in to your <span class="brand-highlight">FitTrack</span> account</p>
                    </div>

                    <form method="post" class="split-card-form" novalidate onsubmit="const btn = this.querySelector('button[type=submit]'); btn.disabled = true; btn.innerHTML = '<svg class=\'fitness-loader mini\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\' style=\'margin-right:8px;\'><line x1=\'6\' y1=\'12\' x2=\'18\' y2=\'12\'></line><rect x=\'4\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'18\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'2\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect><rect x=\'20\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect></svg> SIGNING IN...';">
                        <?= csrf_field() ?>

                        <div class="split-form-group">
                            <label>Email Address</label>
                            <div class="split-input-wrap auth-input-group">
                                <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="12" cy="7" r="4"></circle>
                                </svg>
                                <input type="email" name="email" required placeholder="Enter your email"
                                    value="<?= h(post('email')) ?>"
                                    oninvalid="this.setCustomValidity('Please enter a valid email address.')"
                                    oninput="this.setCustomValidity('')">
                            </div>
                        </div>

                        <div class="split-form-group">
                            <label>Password</label>
                            <div class="split-input-wrap auth-input-group">
                                <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                                <input type="password" name="password" required placeholder="Enter your password"
                                    oninvalid="this.setCustomValidity('Please enter your password.')"
                                    oninput="this.setCustomValidity('')">
                            </div>
                        </div>

                        <div class="split-form-row">
                            <label class="split-checkbox-label">
                                <input type="checkbox" name="remember">
                                <span>Keep me signed in</span>
                            </label>
                            <a href="index.php?page=forgot_password" class="split-forgot-link">Forgot Password?</a>
                        </div>

                        <button type="submit" class="split-submit-btn">
                            <span>Log In</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                                <polyline points="12 5 19 12 12 19"></polyline>
                            </svg>
                        </button>

                        <div class="split-card-footer">
                            <div>Don't have an account? <a href="index.php?page=register" class="signup-link">Sign up here</a></div>
                            <div>
                                <a href="index.php" class="home-link">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                                    Back to Home
                                </a>
                            </div>
                        </div>
                    </form>

                    <?php if ($pendingVerify): ?>
                        <div style="margin-top: 18px; padding: 14px 16px; border-radius: 10px; border: 1px solid rgba(132, 204, 22, 0.35); background: rgba(132, 204, 22, 0.08);">
                            <p style="margin: 0 0 10px; font-size: 13px; color: #334155;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="#65a30d" stroke-width="2" width="15" height="15" style="vertical-align:-2px;margin-right:5px;">
                                    <circle cx="12" cy="12" r="10" />
                                    <line x1="12" y1="8" x2="12" y2="12" />
                                    <line x1="12" y1="16" x2="12.01" y2="16" />
                                </svg>
                                Email not verified yet &mdash; didn't receive the email?
                            </p>
                            <form method="post" action="index.php?page=verify_email" style="margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="resend">
                                <button type="submit" class="btn-secondary" style="width:100%;justify-content:center;font-size:13px;padding:8px 12px;background:#f1f5f9;color:#0f172a;border:1px solid #cbd5e1;border-radius:8px;font-weight:600;cursor:pointer;">Resend verification email</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php
    render_footer();
}

