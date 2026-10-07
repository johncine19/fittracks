<?php
declare(strict_types=1);

function handle_gym_register(): void
{
    if (!defined('AUTH_PAGE')) define('AUTH_PAGE', true);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $validator = new Validator();
        $rules = [
            'first_name'       => 'required|min:1|max:100',
            'last_name'        => 'required|min:1|max:100',
            'email'            => 'required|email|max:255',
            'phone'            => 'required|digits:11',
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

        $email = strtolower(trim((string) post('email')));
        $_POST['email'] = $email;

        if ($valid) {
            $existing = scalar('SELECT user_id FROM users WHERE email = ?', [$email]);
            if ($existing) {
                $valid = false;
                flash('An account with that email already exists.', 'danger');
            }
        }



        if ($valid) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $phone = preg_replace('/[^0-9]/', '', (string) post('phone'));
                if (strlen($phone) !== 11) {
                    throw new Exception('Mobile number is required and must be exactly 11 digits.');
                }

                // This route is exclusively for gym owner accounts.
                $role = 'gym_owner';

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
                    $email,
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
    }
    render_header('Register your gym');
    ?>
    <style>
        .split-login-viewport.gym-owner-register-viewport { padding: 0; align-items: stretch; }
        .split-login-frame.gym-owner-register-mode {
            --gym-owner-accent: #84cc16;
            width: 100%;
            max-width: none;
            min-height: 100dvh;
            grid-template-columns: minmax(0, .95fr) minmax(0, 1.05fr);
            padding: 0;
            gap: 0;
            border: 0;
            border-radius: 0;
            background: #17151d;
            box-shadow: none;
        }
        .split-login-viewport.gym-owner-register-viewport::before { display: none; }
        .gym-owner-register-mode .split-login-showcase {
            min-height: 100dvh;
            margin: 0;
            padding: clamp(32px, 5vw, 88px);
            border-radius: 0;
            background-image: linear-gradient(180deg, rgba(10, 14, 18, .18) 0%, rgba(10, 14, 18, .28) 42%, rgba(7, 9, 13, .92) 100%), url('assets/images/loginback.png?v=3');
            background-position: center;
        }
        .gym-owner-register-mode .split-login-showcase::before { display: none; }
        .gym-owner-register-mode .split-login-showcase-content { justify-content: space-between; }
        .gym-owner-register-mode .showcase-brand-icon { background: rgba(132, 204, 22, .12); border: 1px solid rgba(132, 204, 22, .3); border-radius: 12px; padding: 8px; }
        .gym-owner-register-mode .showcase-title { max-width: 440px; font-size: 2.2rem; }
        .gym-owner-register-mode .showcase-title .highlight { color: #a3e635; }
        .gym-owner-register-mode .showcase-hero-copy { margin: auto 0 24px; }
        .gym-owner-register-mode .showcase-desc { color: rgba(255, 255, 255, .78); }
        .gym-owner-register-mode .showcase-features { display: flex; flex-direction: column; gap: 14px; }
        .gym-owner-register-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 13px;
            border: 1px solid rgba(255, 255, 255, .2);
            border-radius: 999px;
            background: rgba(15, 23, 42, .48);
            color: #f8fafc;
            font-size: 12px;
            text-decoration: none;
            white-space: nowrap;
            backdrop-filter: blur(12px);
        }
        .gym-owner-register-back:hover { border-color: rgba(163, 230, 53, .65); color: #bef264; }
        .gym-owner-register-mode .split-login-card-pane { display: flex; align-items: center; justify-content: center; padding: clamp(28px, 4vw, 64px); background: #f8f9fa; }
        .gym-owner-register-mode .split-login-card { width: 100%; max-width: 600px; padding: clamp(24px, 3vw, 40px); border: 1px solid rgba(33, 30, 41, .06); border-radius: 16px; background: #fff; box-shadow: 0 10px 30px rgba(0, 0, 0, .08); color: #211e29; }
        .gym-owner-register-mode .split-card-header { margin-bottom: 22px; text-align: left; }
        .gym-owner-register-badge { display: inline-flex; align-items: center; width: fit-content; margin-bottom: 12px; padding: 4px 9px; border: 1px solid rgba(77, 124, 15, .22); border-radius: 999px; background: rgba(132, 204, 22, .07); color: #4d7c0f; font-size: 9px; font-weight: 800; letter-spacing: .1em; }
        .gym-owner-register-mode .split-card-title { color: #211e29; font-size: 2rem; }
        .gym-owner-register-mode .split-card-subtitle { color: #625d6b; }
        .gym-owner-register-mode .split-card-subtitle a { color: #4d7c0f; font-weight: 600; }
        .gym-owner-google-section { margin-top: 18px; }
        .gym-owner-google-button {
            display: flex;
            width: 100%;
            justify-content: center;
            min-height: 44px;
        }
        #gym-google-signup-btn { display: flex !important; width: 100% !important; min-width: 0; justify-content: center !important; box-sizing: border-box; }
        #gym-google-signup-btn iframe { display: block; max-width: 100%; margin-left: auto !important; margin-right: auto !important; }
        .gym-owner-google-divider { display: flex; align-items: center; gap: 12px; margin: 0 0 12px; color: #746f7d; font-size: 11px; }
        .gym-owner-google-divider::before,
        .gym-owner-google-divider::after { content: ''; flex: 1; height: 1px; background: rgba(33, 30, 41, .16); }
        .gym-owner-register-mode .mobile-step-tracker,
        .gym-owner-register-mode .step-mobile-only,
        .gym-owner-register-mode .split-card-footer { display: none !important; }
        .gym-owner-register-mode .split-card-form { gap: 13px; }
        .gym-owner-register-mode .split-step-pane,
        .gym-owner-register-mode .split-step-pane.step-hidden { display: contents !important; }
        .gym-owner-register-mode .split-form-row-2col:not(.names-row) { grid-template-columns: 1fr; }
        .gym-owner-register-mode .split-form-row-2col.security-row { grid-template-columns: 1fr; }
        .gym-owner-register-mode .split-form-group label { color: #514b5d; }
        .gym-owner-register-mode .split-input-wrap input { border: 1px solid #111; background: #fff; color: #211e29; }
        .gym-owner-register-mode .split-input-wrap input::placeholder { color: #6b6674; }
        .gym-owner-register-mode .split-input-wrap input:focus { border-color: #6ab000; box-shadow: 0 0 0 3px rgba(106, 176, 0, .2); }
        .gym-owner-register-mode .split-input-icon { color: #514b5d; }
        .gym-owner-register-mode .split-pw-hint { color: #64748b; }
        .gym-owner-register-mode .gym-owner-register-intro { display: none; }
        .gym-owner-register-mode .split-terms-label { color: #403b49; }
        .gym-owner-register-mode .split-terms-label a { color: #4d7c0f; }
        .gym-owner-form-section-heading { display: flex; align-items: center; gap: 10px; margin: 4px 0 12px; color: #383342; font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        .gym-owner-form-section-heading::after { content: ''; height: 1px; flex: 1; background: #e5e7eb; }
        .gym-owner-register-mode .split-submit-btn { min-height: 48px; margin-top: 0; border: 0; background: #65a30d; color: #101407; font-weight: 800; transition: transform .18s ease, box-shadow .18s ease, background-color .18s ease; }
        .gym-owner-register-mode .split-submit-btn:hover { background: #84cc16; transform: translateY(-2px); box-shadow: 0 8px 18px rgba(101, 163, 13, .24); }
        .gym-owner-register-mode .split-submit-btn:active { transform: translateY(0); }
        .gym-owner-google-button iframe { border-radius: 8px; }
        .gym-owner-register-mode .gym-owner-register-plan { margin-bottom: 4px !important; }
        @media (max-width: 1300px) and (min-width: 769px) {
            .split-login-frame.gym-owner-register-mode { grid-template-columns: .9fr 1.1fr; }
            .gym-owner-register-mode .split-login-card-pane { padding: 32px 38px; }
            .gym-owner-register-mode .split-login-showcase { min-height: 100dvh; padding: 32px; }
        }
        @media (min-width: 1025px) {
            .gym-owner-register-mode .split-login-card { padding: 26px 32px; }
            .gym-owner-register-mode .split-card-header { margin-bottom: 14px; }
            .gym-owner-register-mode .split-form-row-2col:not(.names-row),
            .gym-owner-register-mode .split-form-row-2col.security-row { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
            .gym-owner-register-mode .split-card-form { gap: 9px; }
            .gym-owner-google-section { margin-top: 12px; }
            .gym-owner-google-divider { margin-bottom: 8px; }
        }
        @media (max-width: 768px) {
            .split-login-viewport.gym-owner-register-viewport { padding: 12px 10px !important; }
            .split-login-frame.gym-owner-register-mode {
                display: grid !important;
                grid-template-columns: minmax(0, 1fr) !important;
                width: 100% !important;
                min-height: 0 !important;
                max-width: 440px !important;
                min-width: 0 !important;
                padding: 0 !important;
                gap: 0 !important;
                background: transparent !important;
                border: 0 !important;
                box-shadow: none !important;
                overflow: visible !important;
                box-sizing: border-box !important;
            }
            .gym-owner-register-mode .mobile-step-tracker {
                display: flex !important;
                margin: 0 0 14px;
                padding: 4px;
                border: 1px solid #111;
                border-radius: 999px;
                background: #fff;
            }
            .gym-owner-register-mode .mobile-step-tab { color: #514b5d; }
            .gym-owner-register-mode .mobile-step-tab.active {
                background: #211e29;
                color: #fff;
                box-shadow: 0 2px 8px rgba(0, 0, 0, .2);
            }
            .gym-owner-register-mode .mobile-step-tab .step-num { background: #e5e7eb; color: #211e29; }
            .gym-owner-register-mode .mobile-step-tab.active .step-num { background: #84cc16; color: #101407; }
            .gym-owner-register-mode .step-mobile-only { display: flex !important; }
            .gym-owner-register-mode .split-step-pane {
                display: flex !important;
                flex-direction: column;
                gap: 12px;
            }
            .gym-owner-register-mode .split-step-pane.step-hidden { display: none !important; }
            .gym-owner-register-mode .split-login-showcase {
                min-height: 92px !important;
                padding: 12px !important;
                border-radius: 14px 14px 0 0;
                background-image: linear-gradient(180deg, rgba(8, 12, 16, .25), rgba(7, 9, 13, .9)), url('assets/images/loginback.png?v=3') !important;
                background-size: cover !important;
                background-position: center !important;
                text-align: left !important;
                box-sizing: border-box !important;
            }
            .gym-owner-register-mode .split-login-showcase::before,
            .gym-owner-register-mode .showcase-decor-dots { display: none !important; }
            .gym-owner-register-mode .showcase-hero-copy,
            .gym-owner-register-mode .showcase-features { display: none !important; }
            .gym-owner-register-mode .showcase-brand { flex-wrap: wrap; gap: 8px; }
            .gym-owner-register-mode .showcase-brand-icon { width: 36px; height: 36px; }
            .gym-owner-register-mode .showcase-brand-name { font-size: 21px; }
            .gym-owner-register-mode .showcase-brand-tagline { font-size: 9px; }
            .gym-owner-register-mode .gym-owner-register-back { margin-left: auto; padding: 6px 9px; font-size: 10px; }
            .gym-owner-register-mode .split-login-card-pane { display: block; width: 100%; min-width: 0; padding: 10px 8px !important; border-radius: 0 0 14px 14px; background: #f8f9fa !important; box-sizing: border-box !important; }
            .gym-owner-register-mode .split-login-card { width: 100%; min-width: 0; padding: 18px 16px !important; border: 1px solid rgba(33, 30, 41, .06) !important; border-radius: 14px !important; background: #fff !important; box-shadow: 0 8px 24px rgba(0, 0, 0, .07) !important; box-sizing: border-box !important; }
            .gym-owner-register-mode .split-card-header { text-align: left; }
            .gym-owner-register-mode .split-form-row-2col { grid-template-columns: 1fr 1fr; }
            .gym-owner-register-mode .split-form-row-2col:not(.names-row) { grid-template-columns: 1fr; }
            .gym-owner-register-mode .split-form-row-2col.security-row { grid-template-columns: 1fr; }
        }
        @media (max-width: 360px) {
            .gym-owner-register-mode .split-card-title { font-size: 1.65rem; }
            .gym-owner-register-mode .split-login-card { padding: 16px 13px !important; }
            .gym-owner-register-mode .split-login-card-pane { padding-right: 6px !important; padding-left: 6px !important; }
        }
    </style>
    <div class="split-login-viewport gym-owner-register-viewport">
                <div class="split-login-frame register-mode gym-owner-register-mode">
            <!-- Left Hero Showcase -->
            <div class="split-login-showcase">
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
                        <a class="gym-owner-register-back" href="index.php" aria-label="Back to FitTrack website">
                            Back to website <span aria-hidden="true">&#8594;</span>
                        </a>
                    </div>

                    <!-- Middle Headline & Copy -->
                    <div class="showcase-hero-copy">
                        <h1 class="showcase-title">
                                    Grow your fitness business with us.
                                    <span class="highlight">Build a stronger community.</span>
                        </h1>
                        <p class="showcase-desc">
                                    Bring your facility, members, and daily operations together in one gym management workspace.
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
                    <div class="gym-owner-register-badge">GYM OWNER REGISTRATION</div>
                    <div class="split-card-header">
                        <h2 class="split-card-title">Create an account</h2>
                        <p class="split-card-subtitle">Already have an account? <a href="index.php?page=login">Log in</a></p>
                    </div>

                    <?php $googleClientId = (string) app_env('GOOGLE_CLIENT_ID', ''); ?>
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
                            <?php 
                            $requestedPlan = trim((string)($_GET['plan'] ?? ''));
                            $isSubscribeIntent = (isset($_GET['intent']) && $_GET['intent'] === 'subscribe');
                            $plansList = get_platform_subscription_plans();
                            $selectedPlanObj = $plansList[$requestedPlan] ?? null;
                            if ($selectedPlanObj) {
                                $_SESSION['intended_platform_plan'] = $requestedPlan;
                                $_SESSION['intended_platform_action'] = $isSubscribeIntent ? 'subscribe' : 'trial';
                            }
                            ?>
                            <?php if ($selectedPlanObj): ?>
                                <div class="gym-owner-register-plan" style="background: rgba(132, 204, 22, 0.12); border: 1px solid rgba(132, 204, 22, 0.35); border-radius: 12px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <span style="color: var(--lime, #84cc16); font-size: 18px;">⚡</span>
                                        <div>
                                    <div style="font-size: 13px; font-weight: 700; color: #211e29;">
                                                Targeting <?= h($selectedPlanObj['name']) ?> Plan (<?= h($selectedPlanObj['price_label']) ?>/mo)
                                            </div>
                                    <div style="font-size: 11.5px; color: #625d6b;">
                                                <?= $isSubscribeIntent ? 'Direct subscription & immediate payment.' : 'Includes 14-day free trial on signup.' ?>
                                            </div>
                                        </div>
                                    </div>
                                    <span style="font-size: 10.5px; background: rgba(132,204,22,0.25); color: var(--lime, #84cc16); font-weight: 800; padding: 3px 8px; border-radius: 6px; letter-spacing: 0.5px; white-space: nowrap;">
                                        <?= $isSubscribeIntent ? 'DIRECT PAY' : '14-DAY TRIAL' ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <div class="gym-owner-register-intro"><strong>For gym owners and operators</strong><span>Use your business contact details. You’ll add your gym information in the next step.</span></div>

                            <h3 class="gym-owner-form-section-heading">Account Details</h3>

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
                                               style="text-transform: lowercase;"
                                               oninvalid="this.setCustomValidity('Please enter a valid email address.')"
                                               oninput="this.setCustomValidity(''); this.value = this.value.toLowerCase()"
                                               onblur="this.value = this.value.trim().toLowerCase()">
                                    </div>
                                </div>

                                <div class="split-form-group">
                                    <label style="display: inline-flex; align-items: baseline; gap: 4px; white-space: nowrap;">Mobile Number *</label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path>
                                        </svg>
                                        <input name="phone" type="tel" pattern="[0-9]{11}" maxlength="11" placeholder="09xxxxxxxxx" required
                                               value="<?= h(post('phone')) ?>"
                                               title="Please enter an 11-digit mobile number (e.g. 09123456789)"
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
                            <h3 class="gym-owner-form-section-heading">Security</h3>
                            <!-- Password & Confirm Password Fields (Paired) -->
                            <div class="split-form-row-2col security-row">
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
                                <span>Create Owner Account</span>
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
                            <div>Joining a gym? <a href="index.php?page=register" class="signup-link">Create a member account</a></div>
                            <div>
                                <a href="index.php" class="home-link">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                                    Back to Home
                                </a>
                            </div>
                        </div>
                    </form>
                    <?php if ($googleClientId !== ''): ?>
                        <div class="gym-owner-google-section">
                            <div class="gym-owner-google-divider">or register with</div>
                            <div class="gym-owner-google-button">
                                <div id="gym-google-signup-btn"></div>
                            </div>
                        </div>
                    <?php endif; ?>
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
            const ph = document.querySelector('input[name="phone"]');
            if (ph && (!ph.value.trim() || ph.value.trim().length !== 11)) {
                ph.focus();
                ph.reportValidity();
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
    <?php if ($googleClientId !== ''): ?>
        <script src="https://accounts.google.com/gsi/client" async defer></script>
        <script>
        function handleGymGoogleCredentialResponse(response) {
            if (!response || !response.credential) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'index.php?page=google_gym_auth';
            const credential = document.createElement('input');
            credential.type = 'hidden';
            credential.name = 'credential';
            credential.value = response.credential;
            form.appendChild(credential);
            document.body.appendChild(form);
            form.submit();
        }

        function initGymGoogleSignup() {
            const container = document.getElementById('gym-google-signup-btn');
            if (!container || container.hasChildNodes() || !window.google?.accounts?.id) return;
            google.accounts.id.initialize({
                client_id: <?= json_encode($googleClientId) ?>,
                callback: handleGymGoogleCredentialResponse
            });
            const section = container.closest('.gym-owner-google-section');
            const width = section ? Math.min(400, Math.floor(section.clientWidth)) : 320;
            google.accounts.id.renderButton(container, {
                theme: 'outline',
                size: 'large',
                width: Math.max(220, width),
                text: 'signup_with',
                shape: 'rectangular',
                logo_alignment: 'left',
                locale: 'en'
            });
        }

        window.addEventListener('load', initGymGoogleSignup);
        const gymGoogleTimer = setInterval(function() {
            if (window.google?.accounts?.id) {
                initGymGoogleSignup();
                clearInterval(gymGoogleTimer);
            }
        }, 150);
        setTimeout(function() { clearInterval(gymGoogleTimer); }, 5000);
        </script>
    <?php endif; ?>
    <?php
    render_footer();
}

