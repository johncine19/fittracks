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
            // Auto login session
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['user_id'];
            unset($_SESSION['pending_verify_uid'], $_SESSION['pending_verify_email'], $_SESSION['pending_verify_name']);
            flash('Email verified successfully! Welcome to FitTrack.', 'success');
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

    render_header('Verify Your Email', null);
    ?>
    <style>
        .onboarding-waiting-wrapper {
            min-height: calc(100vh - 120px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            position: relative;
            background: radial-gradient(circle at 50% 20%, rgba(132, 204, 22, 0.08) 0%, rgba(10, 15, 29, 0) 65%), #090d16;
            color: #f1f5f9;
            font-family: inherit;
        }

        .onboarding-waiting-card {
            max-width: 540px;
            width: 100%;
            background: rgba(17, 24, 39, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 40px 32px;
            text-align: center;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.7), 0 0 40px rgba(132, 204, 22, 0.06);
            position: relative;
            overflow: hidden;
            animation: cardFadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes cardFadeUp {
            from { opacity: 0; transform: translateY(20px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* Stepper header */
        .onboarding-stepper {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }
        .step-node {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.03em;
            color: #64748b;
        }
        .step-node.active {
            color: #bef264;
        }
        .step-node.active .step-num {
            background: #84cc16;
            color: #000;
            box-shadow: 0 0 14px rgba(132, 204, 22, 0.6);
        }
        .step-num {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #1e293b;
            color: #94a3b8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
        }
        .step-divider {
            width: 24px;
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
        }

        /* Pulsing Radar Illustration */
        .radar-stage {
            position: relative;
            width: 140px;
            height: 140px;
            margin: 0 auto 28px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .radar-ring {
            position: absolute;
            border-radius: 50%;
            border: 1.5px solid rgba(132, 204, 22, 0.35);
            animation: radarPulse 2.8s cubic-bezier(0.215, 0.61, 0.355, 1) infinite;
            pointer-events: none;
        }
        .radar-ring:nth-child(1) { width: 80px; height: 80px; animation-delay: 0s; }
        .radar-ring:nth-child(2) { width: 110px; height: 110px; animation-delay: 0.9s; }
        .radar-ring:nth-child(3) { width: 140px; height: 140px; animation-delay: 1.8s; }

        @keyframes radarPulse {
            0% { transform: scale(0.6); opacity: 0.9; border-color: rgba(163, 230, 53, 0.8); }
            50% { opacity: 0.4; }
            100% { transform: scale(1.3); opacity: 0; border-color: rgba(132, 204, 22, 0); }
        }

        .radar-center-orb {
            position: relative;
            z-index: 2;
            width: 74px;
            height: 74px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border: 2px solid #84cc16;
            box-shadow: 0 0 25px rgba(132, 204, 22, 0.4), inset 0 0 15px rgba(132, 204, 22, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #bef264;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            animation: orbFloat 3s ease-in-out infinite;
        }

        @keyframes orbFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-4px); }
        }

        .radar-center-orb.verified {
            background: linear-gradient(135deg, #22c55e 0%, #15803d 100%);
            border-color: #86efac;
            color: #ffffff;
            box-shadow: 0 0 35px rgba(34, 197, 94, 0.7);
            transform: scale(1.1);
            animation: none;
        }

        /* Live status pill */
        .live-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(132, 204, 22, 0.1);
            border: 1px solid rgba(132, 204, 22, 0.25);
            color: #bef264;
            padding: 5px 14px;
            border-radius: 9999px;
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            margin-bottom: 16px;
            text-transform: uppercase;
        }
        .live-pulse-dot {
            width: 8px;
            height: 8px;
            background: #a3e635;
            border-radius: 50%;
            box-shadow: 0 0 8px #a3e635;
            animation: blinkLive 1.4s infinite ease-in-out;
        }
        @keyframes blinkLive {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.3; transform: scale(0.85); }
        }

        /* Typography */
        .waiting-title {
            font-size: 1.6rem;
            font-weight: 700;
            color: #ffffff;
            margin: 0 0 10px;
            letter-spacing: -0.02em;
        }
        .waiting-desc {
            font-size: 0.95rem;
            line-height: 1.55;
            color: #94a3b8;
            margin: 0 0 18px;
        }
        .user-email-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #f8fafc;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 8px;
            margin: 4px 0;
            word-break: break-all;
        }

        /* Progress auto bar */
        .waiting-auto-bar-wrap {
            background: rgba(255, 255, 255, 0.06);
            border-radius: 999px;
            height: 4px;
            overflow: hidden;
            margin: 20px auto 26px;
            max-width: 380px;
            position: relative;
        }
        .waiting-auto-bar-fill {
            height: 100%;
            width: 30%;
            background: linear-gradient(90deg, #65a30d, #a3e635);
            border-radius: 999px;
            position: relative;
            animation: scanBar 2.2s ease-in-out infinite;
        }
        @keyframes scanBar {
            0% { transform: translateX(-100%); width: 25%; }
            50% { width: 50%; }
            100% { transform: translateX(450%); width: 25%; }
        }

        /* Action Buttons */
        .waiting-actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-width: 380px;
            margin: 0 auto;
        }
        .btn-action-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: #84cc16;
            color: #0b0f19;
            font-weight: 700;
            font-size: 0.92rem;
            padding: 12px 20px;
            border-radius: 10px;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(132, 204, 22, 0.35);
            border: none;
            cursor: pointer;
        }
        .btn-action-primary:hover {
            background: #a3e635;
            color: #000;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(163, 230, 53, 0.45);
        }
        .btn-action-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.05);
            color: #cbd5e1;
            font-weight: 600;
            font-size: 0.88rem;
            padding: 11px 18px;
            border-radius: 10px;
            text-decoration: none;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .btn-action-secondary:hover:not(:disabled) {
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.2);
        }
        .btn-action-secondary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* Toast Feedback */
        #resend-toast {
            display: none;
            margin-top: 16px;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            animation: fadeIn 0.3s ease;
        }
        .toast-success {
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
        }
        .toast-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .waiting-footer-links {
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            font-size: 0.82rem;
            color: #64748b;
        }
        .waiting-footer-links a {
            color: #94a3b8;
            text-decoration: none;
            transition: color 0.15s ease;
        }
        .waiting-footer-links a:hover {
            color: #bef264;
            text-decoration: underline;
        }
    </style>

    <div class="onboarding-waiting-wrapper">
        <div class="onboarding-waiting-card">
            <!-- Stepper -->
            <div class="onboarding-stepper">
                <div class="step-node active">
                    <span class="step-num">1</span>
                    <span>Verify Email</span>
                </div>
                <div class="step-divider"></div>
                <div class="step-node">
                    <span class="step-num">2</span>
                    <span>Setup Profile</span>
                </div>
                <div class="step-divider"></div>
                <div class="step-node">
                    <span class="step-num">3</span>
                    <span>Fitness Goal</span>
                </div>
            </div>

            <!-- Pulsing Radar Graphic -->
            <div class="radar-stage" id="radar-stage">
                <div class="radar-ring"></div>
                <div class="radar-ring"></div>
                <div class="radar-ring"></div>
                <div class="radar-center-orb" id="radar-orb">
                    <!-- Default Mail Icon -->
                    <svg id="icon-mail" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                        <polyline points="22,6 12,13 2,6"></polyline>
                    </svg>
                    <!-- Verified Checkmark Icon (Initially hidden) -->
                    <svg id="icon-check" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
            </div>

            <!-- Live badge -->
            <div>
                <span class="live-status-pill" id="status-pill">
                    <span class="live-pulse-dot" id="status-dot"></span>
                    <span id="status-text">Listening for Verification</span>
                </span>
            </div>

            <!-- Header Titles -->
            <h1 class="waiting-title" id="page-title">Check Your Inbox</h1>
            <p class="waiting-desc" id="page-desc">
                We sent a secure activation link to<br>
                <span class="user-email-badge"><?= h($email) ?></span>
            </p>

            <!-- Auto-scan indicator bar -->
            <div class="waiting-auto-bar-wrap" id="scan-bar-wrap">
                <div class="waiting-auto-bar-fill" id="scan-bar-fill"></div>
            </div>

            <!-- Action buttons -->
            <div class="waiting-actions" id="waiting-actions">
                <button type="button" id="btn-resend" class="btn-action-secondary" onclick="handleResend()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="23 4 23 10 17 10"></polyline>
                        <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                    </svg>
                    <span id="resend-label">Resend Verification Email</span>
                </button>

                <div id="resend-toast"></div>
            </div>

            <!-- Footer Links -->
            <div class="waiting-footer-links">
                Wrong email address? <a href="index.php?page=register">Register with another email</a> &bull;
                <a href="index.php?page=login">Sign In</a>
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
            const rings = document.querySelectorAll('.radar-ring');
            const title = document.getElementById('page-title');
            const desc = document.getElementById('page-desc');
            const statusPill = document.getElementById('status-pill');
            const statusDot = document.getElementById('status-dot');
            const statusText = document.getElementById('status-text');
            const scanBar = document.getElementById('scan-bar-fill');

            // Hide radar waves, show green checkmark
            rings.forEach(r => r.style.display = 'none');
            orb.classList.add('verified');
            iconMail.style.display = 'none';
            iconCheck.style.display = 'block';

            // Update copy
            statusPill.style.background = 'rgba(34, 197, 94, 0.2)';
            statusPill.style.borderColor = 'rgba(34, 197, 94, 0.5)';
            statusPill.style.color = '#86efac';
            statusDot.style.background = '#22c55e';
            statusDot.style.boxShadow = '0 0 10px #22c55e';
            statusText.textContent = 'Email Verified!';

            title.textContent = 'Email Verified Successfully!';
            title.style.color = '#bef264';
            desc.innerHTML = 'Account verified! Directing you to your profile setup...';

            if (scanBar) {
                scanBar.style.animation = 'none';
                scanBar.style.width = '100%';
                scanBar.style.background = '#22c55e';
            }

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
