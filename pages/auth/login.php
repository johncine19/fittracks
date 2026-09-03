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
            <section style="padding: 40px 0;">
                <div class="auth-card" style="text-align: center;">
                    <div class="auth-card-header">
                        <h1 class="auth-title">FITTRACKS</h1>
                        <p class="auth-subtitle">Please wait before trying again.</p>
                    </div>
                    
                    <div style="margin: 30px 0;">
                        <div id="lockout-timer" style="font-size: 2.5rem; font-weight: bold; color: var(--lime); font-variant-numeric: tabular-nums; letter-spacing: 2px;">--:--</div>
                        <p style="color: var(--muted); font-size: 14px; margin-top: 15px;">
                            For your security, your account is temporarily locked due to too many failed attempts.
                        </p>
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
            </section>
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
            flash('Welcome back, ' . $user['first_name'] . '!', 'success');
            redirect('dashboard');
        }
        RateLimiter::hit($email, 300);
        flash('Invalid email or password.', 'danger');
    }

    $pendingVerify = isset($_SESSION['pending_verify_uid']);
    render_header('Sign in');
    ?>
    <section style="padding: 40px 0;">
        <div class="auth-card">
            <div class="auth-card-header">
                <h1 class="auth-title">FITTRACKS</h1>
                <p class="auth-subtitle">Sign in to your account</p>
            </div>
            <form method="post" class="auth-form" novalidate onsubmit="const btn = this.querySelector('button[type=submit]'); btn.disabled = true; btn.innerHTML = '<svg class=\'fitness-loader mini\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\' style=\'margin-right:8px;\'><line x1=\'6\' y1=\'12\' x2=\'18\' y2=\'12\'></line><rect x=\'4\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'18\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'2\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect><rect x=\'20\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect></svg> SIGNING IN...';">
                <?= csrf_field() ?>
                <div class="auth-field">
                    <label>EMAIL ADDRESS</label>
                    <div class="auth-input-group">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                            <polyline points="22,6 12,13 2,6"></polyline>
                        </svg>
                        <input type="email" name="email" required placeholder="Enter your email"
                            value="<?= h(post('email')) ?>"
                            oninvalid="this.setCustomValidity('Please enter a valid email address.')"
                            oninput="this.setCustomValidity('')">
                    </div>
                </div>

                <div class="auth-field">
                    <label>PASSWORD</label>
                    <div class="auth-input-group">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        <input type="password" name="password" required placeholder="Enter your password"
                            oninvalid="this.setCustomValidity('Please enter your password.')"
                            oninput="this.setCustomValidity('')">
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: var(--muted); margin-top: -5px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; text-transform: none; letter-spacing: normal;">
                        <input type="checkbox" name="remember" style="width: auto;"> Keep me signed in
                    </label>
                    <a href="index.php?page=forgot_password" style="color: var(--lime); text-decoration: none;">Forgot password?</a>
                </div>

                <button type="submit" class="auth-submit-btn">SIGN IN <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M5 12h14"></path>
                        <path d="M12 5l7 7-7 7"></path>
                    </svg></button>

                <div class="auth-form-footer" style="margin-top: 15px; display: flex; flex-direction: column; gap: 10px;">
                    <div>New here? <a href="index.php?page=register">Create an account</a></div>
                    <div style="margin-top: 5px;">
                        <a href="index.php" style="color: var(--muted); text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 5px; transition: color 0.2s;" onmouseover="this.style.color='var(--lime)'" onmouseout="this.style.color='var(--muted)'">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                            Back to Home
                        </a>
                    </div>
                </div>
            </form>

            <?php if ($pendingVerify): ?>
                <div style="margin-top: 16px; padding: 14px 16px; border-radius: 8px; border: 1px solid var(--lime, #ccff00); background: rgba(204,255,0,.06);">
                    <p style="margin: 0 0 10px; font-size: 13px; color: var(--text-color);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15" style="vertical-align:-2px;margin-right:5px;">
                            <circle cx="12" cy="12" r="10" />
                            <line x1="12" y1="8" x2="12" y2="12" />
                            <line x1="12" y1="16" x2="12.01" y2="16" />
                        </svg>
                        Email not verified yet &mdash; didn't receive the email?</p>
                    <form method="post" action="index.php?page=verify_email" style="margin: 0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="resend">
                        <button type="submit" class="btn-secondary" style="width:100%;justify-content:center;">Resend verification email</button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="corner corner-tl"></div>
            <div class="corner corner-tr"></div>
            <div class="corner corner-bl"></div>
            <div class="corner corner-br"></div>
        </div>
    </section>
<?php
    render_footer();
}
