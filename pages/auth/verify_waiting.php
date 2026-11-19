<?php
declare(strict_types=1);

function verify_waiting_page(): void
{
    if (!defined('AUTH_PAGE')) {
        define('AUTH_PAGE', true);
    }

    $userId = null;
    if (!empty($_SESSION['pending_verify_uid'])) {
        $userId = (int) $_SESSION['pending_verify_uid'];
    } elseif (!empty($_SESSION['user_id'])) {
        $userId = (int) $_SESSION['user_id'];
    }

    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
        || isset($_GET['ajax']);

    if (!$userId) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'verified' => false, 'redirect' => 'index.php?page=login']);
            exit;
        }
        redirect('login');
    }

    $user = db()->query('SELECT user_id, first_name, last_name, email, role, email_verified_at FROM users WHERE user_id = ' . (int)$userId)->fetch();
    if (!$user) {
        unset($_SESSION['pending_verify_uid']);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'verified' => false, 'redirect' => 'index.php?page=login']);
            exit;
        }
        redirect('login');
    }

    // Determine target redirect once verified
    $targetPage = ($user['role'] === 'gym_owner')
        ? 'gym_onboarding'
        : (($user['role'] === 'member') ? 'setup_profile' : 'dashboard');
    $targetUrl = 'index.php?page=' . $targetPage;

    // Handle AJAX polling check
    if (($_GET['action'] ?? '') === 'check') {
        header('Content-Type: application/json; charset=utf-8');
        if (!empty($user['email_verified_at'])) {
            $alreadyLoggedIn = !empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$user['user_id'];
            // Auto login session
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['user_id'];
            unset($_SESSION['pending_verify_uid'], $_SESSION['pending_verify_email'], $_SESSION['pending_verify_name']);
            if (!$alreadyLoggedIn && empty($_SESSION['email_verified_flash_set'])) {
                $_SESSION['email_verified_flash_set'] = 1;
                $verifiedMsg = ($user['role'] === 'member')
                    ? 'Email verified successfully! Let’s complete your member profile.'
                    : (($user['role'] === 'gym_owner')
                        ? 'Email verified successfully! Please complete your gym profile to submit your application.'
                        : 'Email verified successfully! Welcome to FitTrack.');
                flash($verifiedMsg, 'success');
            }
            echo json_encode(['success' => true, 'verified' => true, 'redirect' => $targetUrl]);
            exit;
        }
        session_write_close();
        echo json_encode(['success' => true, 'verified' => false]);
        exit;
    }

    // Handle Resend Request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (post('action') === 'resend' || ($_GET['action'] ?? '') === 'resend')) {
        if (!empty($user['email_verified_at'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['user_id'];
            unset($_SESSION['pending_verify_uid']);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'verified' => true, 'redirect' => $targetUrl]);
                exit;
            }
            redirect($targetPage);
        }

        $rateLimitKey = 'resend_verify:' . $userId;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3, 300)) {
            $msg = 'Please wait a few minutes before requesting another verification email.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $msg]);
                exit;
            }
            flash($msg, 'danger');
            redirect('verify_waiting');
        }
        RateLimiter::hit($rateLimitKey, 300);

        $token = create_email_verification_token((int) $user['user_id']);
        $sent = send_verification_email((string) $user['email'], (string) $user['first_name'], $token);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => $sent,
                'message' => $sent ? 'Verification link resent! Please check your inbox.' : 'Failed to send email. Please check your mail setup or try again later.'
            ]);
            exit;
        }

        flash($sent ? 'Verification email resent! Please check your inbox.' : 'Failed to send verification email.', $sent ? 'success' : 'danger');
        redirect('verify_waiting');
    }

    // If already verified on synchronous page load, sign in and proceed
    if (!empty($user['email_verified_at'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['user_id'];
        unset($_SESSION['pending_verify_uid'], $_SESSION['pending_verify_email'], $_SESSION['pending_verify_name']);
        flash('Email verified! Let’s continue setting up your profile.', 'success');
        redirect($targetPage);
    }

    $email = (string) $user['email'];
    $emailDomain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
    $webmailUrl = '';
    $webmailLabel = '';
    if (in_array($emailDomain, ['gmail.com', 'googlemail.com'], true)) {
        $webmailUrl = 'https://mail.google.com/';
        $webmailLabel = 'Open Gmail';
    } elseif (in_array($emailDomain, ['outlook.com', 'hotmail.com', 'live.com', 'msn.com'], true)) {
        $webmailUrl = 'https://outlook.live.com/mail/';
        $webmailLabel = 'Open Outlook';
    } elseif (in_array($emailDomain, ['yahoo.com', 'ymail.com'], true)) {
        $webmailUrl = 'https://mail.yahoo.com/';
        $webmailLabel = 'Open Yahoo Mail';
    }
    $isGymOwner = (($user['role'] ?? '') === 'gym_owner');

    render_header('Verify Your Email', null);
    ?>
    <style>
        .vw-wrap {
            min-height: calc(100vh - 110px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
            background: var(--bg, #0b0f17);
            color: var(--ink, #f1f5f9);
        }

        .vw-card {
            width: 100%;
            max-width: 440px;
            background: var(--panel, #111827);
            border: 1px solid var(--line, rgba(148, 163, 184, 0.18));
            border-radius: 20px;
            padding: 32px 28px 28px;
            text-align: center;
            box-shadow: 0 18px 48px rgba(0, 0, 0, 0.16);
            animation: vwFadeUp 0.45s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes vwFadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Compact Stepper */
        .vw-stepper {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 24px;
            padding-bottom: 18px;
            border-bottom: 1px solid var(--line, rgba(148, 163, 184, 0.15));
            flex-wrap: wrap;
        }
        .vw-step {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            color: var(--muted, #64748b);
        }
        .vw-step.active {
            color: var(--ink, #f8fafc);
        }
        .vw-step-num {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--panel-soft, rgba(148, 163, 184, 0.14));
            color: var(--muted, #64748b);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
        }
        .vw-step.active .vw-step-num {
            background: var(--lime, #84cc16);
            color: var(--lime-btn-text, #090b10);
        }
        .vw-step-sep {
            width: 18px;
            height: 1px;
            background: var(--line, rgba(148, 163, 184, 0.25));
        }

        /* Clean Mail Icon Badge */
        .vw-icon-box {
            width: 64px;
            height: 64px;
            margin: 0 auto 18px;
            border-radius: 18px;
            background: color-mix(in srgb, var(--lime, #84cc16) 12%, transparent);
            border: 1px solid color-mix(in srgb, var(--lime, #84cc16) 30%, transparent);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--lime-dark, #65a30d);
            transition: all 0.3s ease;
        }
        [data-theme="dark"] .vw-icon-box {
            color: var(--lime, #a3e635);
        }
        .vw-icon-box.verified {
            background: rgba(34, 197, 94, 0.15);
            border-color: rgba(34, 197, 94, 0.45);
            color: #22c55e;
            transform: scale(1.06);
        }

        /* Status Pill */
        .vw-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 5px 12px;
            border-radius: 999px;
            background: color-mix(in srgb, var(--lime, #84cc16) 10%, transparent);
            border: 1px solid color-mix(in srgb, var(--lime, #84cc16) 25%, transparent);
            color: var(--lime-dark, #65a30d);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin-bottom: 14px;
        }
        [data-theme="dark"] .vw-status-pill {
            color: var(--lime, #bef264);
        }
        .vw-pulse-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--lime, #84cc16);
            animation: vwPulse 1.6s ease-in-out infinite;
        }
        @keyframes vwPulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.35; transform: scale(0.8); }
        }

        .vw-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--ink, #f8fafc);
            margin: 0 0 8px;
            letter-spacing: -0.02em;
        }
        .vw-desc {
            font-size: 0.92rem;
            line-height: 1.5;
            color: var(--muted, #94a3b8);
            margin: 0 0 14px;
        }
        .vw-email-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--panel-soft, rgba(148, 163, 184, 0.1));
            border: 1px solid var(--line, rgba(148, 163, 184, 0.2));
            color: var(--ink, #f8fafc);
            font-size: 0.88rem;
            font-weight: 600;
            padding: 7px 14px;
            border-radius: 10px;
            word-break: break-all;
            margin-bottom: 22px;
        }

        /* Actions */
        .vw-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .vw-btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            background: var(--lime, #84cc16);
            color: var(--lime-btn-text, #090b10);
            font-weight: 700;
            font-size: 0.92rem;
            padding: 12px 18px;
            border-radius: 11px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: transform 0.15s ease, filter 0.15s ease;
        }
        .vw-btn-primary:hover {
            filter: brightness(1.05);
            transform: translateY(-1px);
        }
        .vw-btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            background: var(--panel-soft, rgba(148, 163, 184, 0.1));
            color: var(--ink, #e2e8f0);
            font-weight: 600;
            font-size: 0.88rem;
            padding: 11px 18px;
            border-radius: 11px;
            border: 1px solid var(--line, rgba(148, 163, 184, 0.22));
            cursor: pointer;
            transition: background 0.15s ease, border-color 0.15s ease;
        }
        .vw-btn-secondary:hover:not(:disabled) {
            background: color-mix(in srgb, var(--panel-soft, #1e293b) 80%, var(--ink, #fff) 8%);
        }
        .vw-btn-secondary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .vw-tip {
            margin: 14px 0 0;
            font-size: 12px;
            color: var(--muted, #64748b);
            line-height: 1.45;
        }

        /* Toast Feedback */
        #resend-toast {
            display: none;
            margin-top: 14px;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 0.84rem;
            font-weight: 500;
            line-height: 1.45;
            text-align: center;
        }
        .toast-success {
            background: rgba(34, 197, 94, 0.12);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #16a34a;
        }
        [data-theme="dark"] .toast-success { color: #86efac; }
        .toast-error {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #dc2626;
        }
        [data-theme="dark"] .toast-error { color: #fca5a5; }

        .vw-footer {
            margin-top: 22px;
            padding-top: 16px;
            border-top: 1px solid var(--line, rgba(148, 163, 184, 0.15));
            font-size: 0.82rem;
            color: var(--muted, #64748b);
            line-height: 1.6;
        }
        .vw-footer a {
            color: var(--ink, #cbd5e1);
            font-weight: 600;
            text-decoration: none;
        }
        .vw-footer a:hover {
            color: var(--lime-dark, #65a30d);
            text-decoration: underline;
        }
        [data-theme="dark"] .vw-footer a:hover {
            color: var(--lime, #bef264);
        }

        @media (max-width: 480px) {
            .vw-card { padding: 26px 20px 22px; border-radius: 16px; }
            .vw-title { font-size: 1.35rem; }
        }
    </style>

    <div class="vw-wrap">
        <div class="vw-card">
            <!-- Stepper -->
            <div class="vw-stepper">
                <div class="vw-step active">
                    <span class="vw-step-num">1</span>
                    <span>Verify Email</span>
                </div>
                <div class="vw-step-sep"></div>
                <div class="vw-step">
                    <span class="vw-step-num">2</span>
                    <span><?= $isGymOwner ? 'Gym Onboarding' : 'Setup Profile' ?></span>
                </div>
                <?php if (!$isGymOwner): ?>
                    <div class="vw-step-sep"></div>
                    <div class="vw-step">
                        <span class="vw-step-num">3</span>
                        <span>Fitness Goal</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Icon -->
            <div class="vw-icon-box" id="radar-orb">
                <svg id="icon-mail" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                    <polyline points="22,6 12,13 2,6"></polyline>
                </svg>
                <svg id="icon-check" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.75" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>

            <!-- Live Status -->
            <div>
                <span class="vw-status-pill" id="status-pill">
                    <span class="vw-pulse-dot" id="status-dot"></span>
                    <span id="status-text">Waiting for Verification</span>
                </span>
            </div>

            <!-- Title & Email -->
            <h1 class="vw-title" id="page-title">Check your inbox</h1>
            <p class="vw-desc" id="page-desc">
                We sent a verification link to your email address. Click the link to continue.
            </p>
            <div class="vw-email-chip" id="email-chip"><?= h($email) ?></div>

            <!-- Actions -->
            <div class="vw-actions" id="waiting-actions">
                <?php if ($webmailUrl !== ''): ?>
                    <a href="<?= h($webmailUrl) ?>" target="_blank" rel="noopener noreferrer" class="vw-btn-primary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                            <polyline points="15 3 21 3 21 9"></polyline>
                            <line x1="10" y1="14" x2="21" y2="3"></line>
                        </svg>
                        <span><?= h($webmailLabel) ?></span>
                    </a>
                <?php endif; ?>

                <button type="button" id="btn-resend" class="<?= $webmailUrl !== '' ? 'vw-btn-secondary' : 'vw-btn-primary' ?>" onclick="handleResend()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="23 4 23 10 17 10"></polyline>
                        <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                    </svg>
                    <span id="resend-label">Resend Verification Email</span>
                </button>

                <div id="resend-toast"></div>
            </div>

            <p class="vw-tip">Didn&apos;t see it? Check your Spam or Promotions folder.</p>

            <!-- Footer Links -->
            <div class="vw-footer">
                Wrong email address? <a href="index.php?page=register">Register again</a> &bull;
                <a href="index.php?page=login">Sign in</a>
            </div>
        </div>
    </div>

    <script>
    (function() {
        let isVerified = false;
        let pollTimer = null;
        let countdownTimer = null;
        let cooldownSeconds = 0;
        const csrfToken = "<?= h(csrf_token()) ?>";

        const checkEndpoint = 'index.php?page=verify_waiting&action=check';
        const resendEndpoint = 'index.php?page=verify_waiting&action=resend';

        // 1. Live status polling every 2.5 seconds
        async function checkVerificationStatus() {
            if (isVerified) return;
            try {
                const response = await fetch(checkEndpoint, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (!response.ok) return;
                const data = await response.json();

                if (data.verified && data.redirect) {
                    onVerifiedSuccess(data.redirect);
                }
            } catch (err) {
                // Silently swallow network hiccups during polling
            }
        }

        function onVerifiedSuccess(redirectUrl) {
            isVerified = true;
            if (pollTimer) clearInterval(pollTimer);

            // Morph UI to celebrate
            const orb = document.getElementById('radar-orb');
            const iconMail = document.getElementById('icon-mail');
            const iconCheck = document.getElementById('icon-check');
            const title = document.getElementById('page-title');
            const desc = document.getElementById('page-desc');
            const emailChip = document.getElementById('email-chip');
            const statusPill = document.getElementById('status-pill');
            const statusDot = document.getElementById('status-dot');
            const statusText = document.getElementById('status-text');

            orb.classList.add('verified');
            iconMail.style.display = 'none';
            iconCheck.style.display = 'block';
            if (emailChip) emailChip.style.display = 'none';

            // Update copy
            statusPill.style.background = 'rgba(34, 197, 94, 0.14)';
            statusPill.style.borderColor = 'rgba(34, 197, 94, 0.4)';
            statusPill.style.color = '#22c55e';
            statusDot.style.background = '#22c55e';
            statusDot.style.animation = 'none';
            statusText.textContent = 'Email Verified';

            title.textContent = 'Email Verified!';
            desc.textContent = 'Your account is confirmed. Redirecting you now...';

            // Redirect smoothly
            setTimeout(function() {
                window.location.href = redirectUrl;
            }, 1200);
        }

        // Start polling
        pollTimer = setInterval(checkVerificationStatus, 2500);

        // Also check immediately when tab gains focus (e.g. member verifies on another tab/window)
        window.addEventListener('focus', checkVerificationStatus);
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') {
                checkVerificationStatus();
            }
        });

        // 2. Resend verification email handler
        window.handleResend = async function() {
            if (cooldownSeconds > 0) return;

            const btn = document.getElementById('btn-resend');
            const label = document.getElementById('resend-label');
            const toast = document.getElementById('resend-toast');

            btn.disabled = true;
            label.textContent = 'Sending email...';
            toast.style.display = 'none';

            try {
                const formData = new FormData();
                formData.append('action', 'resend');
                formData.append('csrf_token', csrfToken);

                const res = await fetch(resendEndpoint, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: formData
                });
                const data = await res.json();

                if (data.verified && data.redirect) {
                    onVerifiedSuccess(data.redirect);
                    return;
                }

                toast.textContent = data.message || (data.success ? 'Email sent!' : 'Failed to send.');
                toast.className = data.success ? 'toast-success' : 'toast-error';
                toast.style.display = 'block';

                if (data.success) {
                    startCooldown(60);
                } else {
                    btn.disabled = false;
                    label.textContent = 'Resend Verification Email';
                }
            } catch (err) {
                toast.textContent = 'Could not contact server. Please try again.';
                toast.className = 'toast-error';
                toast.style.display = 'block';
                btn.disabled = false;
                label.textContent = 'Resend Verification Email';
            }
        };

        function startCooldown(seconds) {
            cooldownSeconds = seconds;
            const btn = document.getElementById('btn-resend');
            const label = document.getElementById('resend-label');
            btn.disabled = true;

            if (countdownTimer) clearInterval(countdownTimer);
            countdownTimer = setInterval(function() {
                cooldownSeconds--;
                if (cooldownSeconds <= 0) {
                    clearInterval(countdownTimer);
                    btn.disabled = false;
                    label.textContent = 'Resend Verification Email';
                } else {
                    label.textContent = 'Resend in ' + cooldownSeconds + 's';
                }
            }, 1000);
        }
    })();
    </script>
    <?php
    render_footer();
}
