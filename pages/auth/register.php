<?php
declare(strict_types=1);

function handle_register(): void
{
    if (!defined('AUTH_PAGE')) define('AUTH_PAGE', true);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $validator = new Validator();
        $rules = [
            'account_type'     => 'required',
            'first_name'       => 'required|min:1|max:100',
            'last_name'        => 'required|min:1|max:100',
            'email'            => 'required|email|max:255',
            'password'         => 'required|min:8',
            'confirm_password' => 'required',
        ];

        $valid = $validator->validate($_POST, $rules);

        if ($valid && (string) post('password') !== (string) post('confirm_password')) {
            $valid = false;
            flash('Passwords do not match. Please re-enter your password.', 'danger');
        } elseif ($valid && empty($_POST['agree_terms'])) {
            $valid = false;
            flash('You must agree to the Terms of Service and Privacy Policy to create an account.', 'danger');
        } elseif ($valid && !is_acceptable_password((string) post('password'))) {
            $valid = false;
            flash('Password must be at least 8 characters with a letter and a number, and not be too common.', 'danger');
        } elseif (!$valid) {
            flash($validator->firstError(), 'danger');
        }

        if ($valid) {
            $existing = scalar('SELECT user_id FROM users WHERE email = ?', [post('email')]);
            if ($existing) {
                $valid = false;
                flash('An account with that email already exists.', 'danger');
            }
        }



        if ($valid) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $phone = (string) post('phone');
                if ($phone !== '') {
                    $phone = preg_replace('/[^0-9]/', '', $phone);
                    if (strlen($phone) !== 11) {
                        throw new Exception('Phone number must be exactly 11 digits.');
                    }
                }

                $role = (post('account_type') === 'gym_owner') ? 'gym_owner' : 'member';

                // Capitalize first letter of each word (Title Case)
                $firstName = mb_convert_case(trim((string) post('first_name')), MB_CASE_TITLE, 'UTF-8');
                $lastName  = mb_convert_case(trim((string) post('last_name')), MB_CASE_TITLE, 'UTF-8');

                // Gym owners proceed directly to gym onboarding and are marked verified immediately
                $emailVerifiedAt = ($role === 'gym_owner') ? date('Y-m-d H:i:s') : null;

                $stmt = $pdo->prepare(
                    'INSERT INTO users (role, first_name, last_name, email, password_hash, phone, status, email_verified_at)
                     VALUES (?, ?, ?, ?, ?, ?, "active", ?)'
                );
                $stmt->execute([
                    $role,
                    $firstName,
                    $lastName,
                    trim((string) post('email')),
                    password_hash((string) post('password'), PASSWORD_DEFAULT),
                    $phone ?: null,
                    $emailVerifiedAt,
                ]);
                $userId = (int) $pdo->lastInsertId();

                $pdo->commit();

                // If gym owner, log in and proceed straight into the gym onboarding wizard
                if ($role === 'gym_owner') {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $userId;
                    unset($_SESSION['pending_verify_uid']);

                    flash('Welcome to FitTrack! Let\'s set up your gym facility profile.', 'success');
                    redirect('gym_onboarding');
                    return;
                }

                // Member flow: Send verification email before login
                flash('Registration successful! Please check your email to verify your account.', 'success');

                // Send a verification email. Login is blocked until the member verifies.
                $emailSent = false;
                try {
                    $token = create_email_verification_token($userId);
                    $emailSent = send_verification_email((string) post('email'), $firstName, $token);
                } catch (Throwable) {
                    // ignore — user can request a resend from the login page
                }

                $msg = $emailSent
                    ? 'Account created! Please check your email (' . post('email') . ') to verify your address before signing in.'
                    : 'Account created! We could not send a verification email right now — use the resend option on the login page.';
                flash($msg, 'success');
                redirect('login');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('Registration failed: ' . $e->getMessage(), 'danger');
            }
        }
    }    render_header('Create account');
    ?>
    <div class="split-login-viewport">
        <div class="split-login-frame register-mode">
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

            <!-- Right Side Register Card Pane -->
            <div class="split-login-card-pane">
                <div class="split-login-card register-card">
                    <div class="split-card-header">
                        <h2 class="split-card-title">Create Account</h2>
                        <p class="split-card-subtitle">Join <span class="brand-highlight">FitTrack</span> today</p>
                    </div>

                    <?php $startOnStep2 = ($_SERVER['REQUEST_METHOD'] === 'POST' && (!empty(post('password')) || !empty(post('confirm_password')))); ?>

                    <!-- Mobile Step Tracker (Mobile-Only) -->
                    <div class="mobile-step-tracker step-mobile-only">
                        <button type="button" class="mobile-step-tab <?= !$startOnStep2 ? 'active' : '' ?>" id="tab-step-1" onclick="goToStep(1)">
                            <span class="step-num">1</span>
                            <span>Basic Info</span>
                        </button>
                        <button type="button" class="mobile-step-tab <?= $startOnStep2 ? 'active' : '' ?>" id="tab-step-2" onclick="goToStep(2)">
                            <span class="step-num">2</span>
                            <span>Security</span>
                        </button>
                    </div>

                    <form method="post" class="split-card-form" novalidate onsubmit="const btn = this.querySelector('button[type=submit]'); if (btn) { btn.disabled = true; btn.innerHTML = '<svg class=\'fitness-loader mini\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\' style=\'margin-right:8px;\'><line x1=\'6\' y1=\'12\' x2=\'18\' y2=\'12\'></line><rect x=\'4\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'18\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'2\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect><rect x=\'20\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect></svg> CREATING ACCOUNT...'; }">
                        <?= csrf_field() ?>

                        <!-- STEP 1: Basic Information -->
                        <div class="split-step-pane <?= $startOnStep2 ? 'step-hidden' : '' ?>" id="step-pane-1">
                            <!-- Account Type Selection -->
                            <div class="split-form-group">
                                <label>I AM A:</label>
                                <?php $isGymOwner = (isset($_GET['role']) && $_GET['role'] === 'gym_owner'); ?>
                                <div class="split-account-type-grid">
                                    <label class="split-account-type-btn">
                                        <input type="radio" name="account_type" value="member" <?= !$isGymOwner ? 'checked' : '' ?>>
                                        <span>Member</span>
                                    </label>
                                    <label class="split-account-type-btn">
                                        <input type="radio" name="account_type" value="gym_owner" <?= $isGymOwner ? 'checked' : '' ?>>
                                        <span>Gym Owner</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Name Fields -->
                            <div class="split-form-row-2col names-row">
                                <div class="split-form-group">
                                    <label>First Name</label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                            <circle cx="12" cy="7" r="4"></circle>
                                        </svg>
                                        <input name="first_name" required placeholder="First name"
                                               autocapitalize="words"
                                               style="text-transform: capitalize;"
                                               value="<?= h(post('first_name')) ?>"
                                               onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())"
                                               oninvalid="this.setCustomValidity('Please enter your first name.')"
                                               oninput="this.setCustomValidity('')">
                                    </div>
                                </div>

                                <div class="split-form-group">
                                    <label>Last Name</label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                            <circle cx="12" cy="7" r="4"></circle>
                                        </svg>
                                        <input name="last_name" required placeholder="Last name"
                                               autocapitalize="words"
                                               style="text-transform: capitalize;"
                                               value="<?= h(post('last_name')) ?>"
                                               onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())"
                                               oninvalid="this.setCustomValidity('Please enter your last name.')"
                                               oninput="this.setCustomValidity('')">
                                    </div>
                                </div>
                            </div>

                            <!-- Email & Phone Fields (Paired) -->
                            <div class="split-form-row-2col">
                                <div class="split-form-group">
                                    <label>Email Address</label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                            <polyline points="22,6 12,13 2,6"></polyline>
                                        </svg>
                                        <input type="email" name="email" required placeholder="Enter email"
                                               value="<?= h(post('email')) ?>"
                                               oninvalid="this.setCustomValidity('Please enter a valid email address.')"
                                               oninput="this.setCustomValidity('')">
                                    </div>
                                </div>

                                <div class="split-form-group">
                                    <label style="display: inline-flex; align-items: baseline; gap: 4px; white-space: nowrap;">Phone <span style="font-weight: 400; color: #94a3b8; font-size: 11px;">(optional)</span></label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path>
                                        </svg>
                                        <input name="phone" type="tel" maxlength="11" placeholder="09xxxxxxxxx"
                                               value="<?= h(post('phone')) ?>"
                                               oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)">
                                    </div>
                                </div>
                            </div>

                            <!-- Mobile Step 1 Continue Button -->
                            <button type="button" class="split-submit-btn step-mobile-only" onclick="goToStep(2)">
                                <span>Continue to Security</span>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                    <polyline points="12 5 19 12 12 19"></polyline>
                                </svg>
                            </button>
                        </div>

                        <!-- STEP 2: Security & Password -->
                        <div class="split-step-pane <?= $startOnStep2 ? '' : 'step-hidden' ?>" id="step-pane-2">
                            <!-- Password & Confirm Password Fields (Paired) -->
                            <div class="split-form-row-2col">
                                <div class="split-form-group">
                                    <label>Password</label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                        </svg>
                                        <input type="password" name="password" id="register_password" required minlength="8"
                                               placeholder="Min. 8 chars (letters & numbers)"
                                               oninput="checkPasswordStrength(this.value); checkPasswordMatch();">
                                    </div>
                                    <div class="split-pw-strength-bar">
                                        <div id="pw-str-1" class="split-pw-strength-seg"></div>
                                        <div id="pw-str-2" class="split-pw-strength-seg"></div>
                                        <div id="pw-str-3" class="split-pw-strength-seg"></div>
                                        <div id="pw-str-4" class="split-pw-strength-seg"></div>
                                    </div>
                                    <p id="pw-str-text" class="split-pw-hint">Letter & number required.</p>
                                </div>

                                <div class="split-form-group">
                                    <label>Confirm Password</label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                        </svg>
                                        <input type="password" name="confirm_password" id="register_confirm_password" required minlength="8"
                                               placeholder="Re-enter password"
                                               oninput="checkPasswordMatch();">
                                    </div>
                                    <div style="height: 3px; margin-top: 4px;"></div>
                                    <p id="pw-match-text" class="split-pw-hint" style="display: none;"></p>
                                </div>
                            </div>

                            <!-- Terms of Service Agreement -->
                            <label class="split-terms-label">
                                <input type="checkbox" name="agree_terms" value="1" required
                                       <?= !empty($_POST['agree_terms']) ? 'checked' : '' ?>
                                       oninvalid="this.setCustomValidity('Please accept the Terms of Service and Privacy Policy to proceed.')"
                                       oninput="this.setCustomValidity('')">
                                <span>
                                    I agree to the <a href="index.php?page=terms" target="_blank">Terms of Service</a> and <a href="index.php?page=privacy" target="_blank">Privacy Policy</a>.
                                </span>
                            </label>

                            <!-- Submit Button -->
                            <button type="submit" class="split-submit-btn">
                                <span>Create Account</span>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                    <polyline points="12 5 19 12 12 19"></polyline>
                                </svg>
                            </button>

                            <!-- Mobile Back Button -->
                            <button type="button" class="split-step-back-btn step-mobile-only" onclick="goToStep(1)">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                                Back to Basic Info
                            </button>
                        </div>

                        <div class="split-card-footer">
                            <div>Already have an account? <a href="index.php?page=login" class="signup-link">Sign in</a></div>
                            <div>
                                <a href="index.php" class="home-link">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                                    Back to Home
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
    let currentMobileStep = <?= $startOnStep2 ? 2 : 1 ?>;

    function goToStep(step) {
        if (window.innerWidth > 768) return; // Desktop displays both steps simultaneously

        const pane1 = document.getElementById('step-pane-1');
        const pane2 = document.getElementById('step-pane-2');
        const tab1  = document.getElementById('tab-step-1');
        const tab2  = document.getElementById('tab-step-2');

        if (step === 2) {
            const fn = document.querySelector('input[name="first_name"]');
            const ln = document.querySelector('input[name="last_name"]');
            const em = document.querySelector('input[name="email"]');

            if (fn && !fn.value.trim()) {
                fn.focus();
                fn.reportValidity();
                return;
            }
            if (ln && !ln.value.trim()) {
                ln.focus();
                ln.reportValidity();
                return;
            }
            if (em && (!em.value.trim() || !em.checkValidity())) {
                em.focus();
                em.reportValidity();
                return;
            }

            pane1.classList.add('step-hidden');
            pane2.classList.remove('step-hidden');
            if (tab1) tab1.classList.remove('active');
            if (tab2) tab2.classList.add('active');
            currentMobileStep = 2;
        } else {
            pane2.classList.add('step-hidden');
            pane1.classList.remove('step-hidden');
            if (tab2) tab2.classList.remove('active');
            if (tab1) tab1.classList.add('active');
            currentMobileStep = 1;
        }
    }

    window.addEventListener('resize', function() {
        const pane1 = document.getElementById('step-pane-1');
        const pane2 = document.getElementById('step-pane-2');
        if (!pane1 || !pane2) return;
        if (window.innerWidth > 768) {
            pane1.classList.remove('step-hidden');
            pane2.classList.remove('step-hidden');
        } else {
            if (currentMobileStep === 1) {
                pane1.classList.remove('step-hidden');
                pane2.classList.add('step-hidden');
            } else {
                pane1.classList.add('step-hidden');
                pane2.classList.remove('step-hidden');
            }
        }
    });

    function checkPasswordStrength(pw) {
        const input = document.getElementById('register_password');
        let strength = 0;
        let msg = 'Must include at least one letter and one number.';
        
        if (pw.length >= 8) strength++;
        if (/[A-Za-z]/.test(pw) && /[0-9]/.test(pw)) strength++;
        if (pw.length >= 12) strength++;
        if (/[^A-Za-z0-9]/.test(pw)) strength++;
        
        if (pw.length === 0) strength = 0;

        const colors = ['#e2e8f0', '#ef4444', '#f97316', '#eab308', '#65a30d'];
        const text = ['Must include at least one letter and one number.', 'Weak', 'Fair', 'Good', 'Strong'];
        
        for (let i = 1; i <= 4; i++) {
            const el = document.getElementById('pw-str-' + i);
            if (el) el.style.background = (strength >= i) ? colors[strength] : '#e2e8f0';
        }
        
        const textEl = document.getElementById('pw-str-text');
        if (textEl) {
            textEl.innerText = text[strength] || text[0];
            textEl.style.color = (strength > 0) ? colors[strength] : '#64748b';
        }
        
        if (strength < 2 && pw.length > 0) {
            input.setCustomValidity('Password is too weak. Please follow the guidelines.');
        } else {
            input.setCustomValidity('');
        }
    }

    function checkPasswordMatch() {
        const pw = document.getElementById('register_password');
        const cpw = document.getElementById('register_confirm_password');
        const matchText = document.getElementById('pw-match-text');
        if (!cpw || !pw) return;

        if (cpw.value === '') {
            cpw.setCustomValidity('');
            if (matchText) matchText.style.display = 'none';
            return;
        }

        if (cpw.value !== pw.value) {
            cpw.setCustomValidity('Passwords do not match.');
            if (matchText) {
                matchText.style.display = 'block';
                matchText.style.color = '#ef4444';
                matchText.textContent = 'Passwords do not match.';
            }
        } else {
            cpw.setCustomValidity('');
            if (matchText) {
                matchText.style.display = 'block';
                matchText.style.color = '#65a30d';
                matchText.textContent = '✓ Passwords match';
            }
        }
    }
    </script>
    <?php
    render_footer();
}

