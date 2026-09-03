<?php
declare(strict_types=1);

function gym_rejected_page(): void
{
    if (!defined('AUTH_PAGE')) define('AUTH_PAGE', true);
    
    $user = current_user();
    if (!$user || $user['role'] !== 'gym_owner') {
        if (isset($_GET['action']) && $_GET['action'] === 'check_status') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'unauthorized', 'redirect' => 'index.php?page=login']);
            exit;
        }
        redirect('login');
    }

    $pdo = db();
    $gym = $pdo->query('SELECT gym_id, status, name, subscription_status, subscription_plan FROM gyms WHERE owner_user_id = ' . (int)$user['user_id'])->fetch();
    
    // AJAX status polling endpoint
    if (isset($_GET['action']) && $_GET['action'] === 'check_status') {
        header('Content-Type: application/json');
        if (!$gym) {
            echo json_encode(['status' => 'no_gym', 'redirect' => 'index.php?page=gym_onboarding']);
            exit;
        }
        
        $currentStatus = (string)$gym['status'];
        $targetRedirect = null;
        
        if ($currentStatus === 'approved') {
            $isActiveSub = ($gym['subscription_status'] === 'active' && !empty($gym['subscription_plan']));
            $targetRedirect = $isActiveSub ? 'index.php?page=dashboard' : 'index.php?page=gym_subscription';
        } elseif ($currentStatus === 'pending') {
            $targetRedirect = 'index.php?page=gym_pending';
        } elseif ($currentStatus !== 'rejected') {
            $targetRedirect = 'index.php?page=dashboard';
        }

        echo json_encode([
            'status' => $currentStatus,
            'redirect' => $targetRedirect
        ]);
        exit;
    }

    if (!$gym || $gym['status'] !== 'rejected') {
        if ($gym && $gym['status'] === 'approved') {
            $isActiveSub = ($gym['subscription_status'] === 'active' && !empty($gym['subscription_plan']));
            redirect($isActiveSub ? 'dashboard' : 'gym_subscription');
        } elseif ($gym && $gym['status'] === 'pending') {
            redirect('gym_pending');
        } else {
            redirect('gym_onboarding');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (post('action') === 'resubmit') {
            $pdo->prepare('DELETE FROM gyms WHERE gym_id = ?')->execute([$gym['gym_id']]);
            flash('You can now submit a new gym application.', 'success');
            redirect('gym_onboarding');
        }
    }

    // Suppress conflicting "Welcome back" flash toast on rejected page
    if (isset($_SESSION['flash']['message']) && str_starts_with((string)$_SESSION['flash']['message'], 'Welcome back')) {
        unset($_SESSION['flash']);
    }

    render_header('Application Rejected', $user);
    ?>
    <div class="split-login-viewport">
        <div class="split-login-frame pending-mode">
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
                            Update Details
                            <span class="highlight">& Re-Apply.</span>
                        </h1>
                        <p class="showcase-desc">
                            We were unable to approve your application with the documents provided. Review guidelines and re-submit anytime.
                        </p>
                    </div>

                    <!-- Three Feature Items -->
                    <div class="showcase-features">
                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Valid Permit Required</h4>
                                <p>Ensure business permits (DTI, SEC, or Mayor's Permit) are clear and current.</p>
                            </div>
                        </div>

                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                                    <circle cx="9" cy="10" r="2"></circle>
                                    <line x1="15" y1="8" x2="17" y2="8"></line>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Government ID</h4>
                                <p>Provide an unexpired photo ID (Passport, Driver's License, or UMID).</p>
                            </div>
                        </div>

                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M23 4v6h-6"></path>
                                    <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Fast Re-Verification</h4>
                                <p>Resubmitted applications are prioritized for expedited review.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Side Elevated Status Card -->
            <div class="split-login-card-pane">
                <div class="split-login-card status-card">
                    <!-- Red Alert Badge -->
                    <div class="split-status-badge" style="background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.35); color: #ef4444; box-shadow: 0 0 25px rgba(239, 68, 68, 0.18);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="15" y1="9" x2="9" y2="15"></line>
                            <line x1="9" y1="9" x2="15" y2="15"></line>
                        </svg>
                    </div>

                    <div class="split-card-header" style="margin-bottom: 6px;">
                        <h2 class="split-card-title" style="color: #ef4444;">Needs Attention</h2>
                        <p class="split-card-subtitle">
                            <?= !empty($gym['name']) ? 'Application for <span class="brand-highlight">' . h($gym['name']) . '</span>' : 'Application requires updated documents' ?>
                        </p>
                    </div>

                    <!-- Status Info Box -->
                    <div class="split-status-box" style="border-left-color: #ef4444;">
                        <div class="status-pill" style="background: rgba(239, 68, 68, 0.12); color: #b91c1c;">
                            <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#ef4444;"></span>
                            Action Required &bull; Rejected
                        </div>
                        <p>
                            Your gym application was not approved by the administration team. 
                            This typically occurs if your uploaded business permit or government ID was blurry, expired, or incomplete.
                        </p>
                    </div>

                    <!-- Action Buttons -->
                    <div style="display: flex; flex-direction: column; gap: 10px; width: 100%; margin-top: 6px;">
                        <form method="post" action="index.php?page=gym_rejected" style="width: 100%; margin: 0;">
                            <?= csrf_field() ?>
                            <button type="submit" name="action" value="resubmit" class="split-submit-btn" style="width: 100%;">
                                <span>Submit a New Application</span>
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                    <polyline points="12 5 19 12 12 19"></polyline>
                                </svg>
                            </button>
                        </form>

                        <div class="split-card-footer" style="margin-top: 8px;">
                            <div>
                                <a href="index.php?page=logout&silent=1" class="home-link">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                        <line x1="19" y1="12" x2="5" y2="12"></line>
                                        <polyline points="12 19 5 12 12 5"></polyline>
                                    </svg>
                                    Back to Login
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
    </div>

    <!-- Real-time AJAX Auto-Polling Script -->
    <script>
    (function() {
        let isRedirecting = false;

        function pollGymStatus() {
            if (isRedirecting) return;

            fetch('index.php?page=gym_rejected&action=check_status', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function(data) {
                if (data.redirect && !isRedirecting) {
                    isRedirecting = true;

                    const title = document.querySelector('.split-card-title');
                    const statusBox = document.querySelector('.split-status-box');
                    const badge = document.querySelector('.split-status-badge');

                    if (data.status === 'approved') {
                        if (title) {
                            title.textContent = 'Application Approved!';
                            title.style.color = '#84cc16';
                        }
                        if (badge) {
                            badge.style.borderColor = '#84cc16';
                            badge.style.background = 'rgba(132, 204, 22, 0.25)';
                            badge.innerHTML = '<svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="#65a30d" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                        }
                        if (statusBox) {
                            statusBox.style.borderLeftColor = '#84cc16';
                            statusBox.innerHTML = '<div class="status-pill" style="background:rgba(132,204,22,0.25);color:#3f6212;">✓ APPROVED</div><p style="font-weight:600;color:#1e293b;">Your facility has been approved! Redirecting...</p>';
                        }
                    } else if (data.status === 'pending') {
                        if (title) {
                            title.textContent = 'Status Reset to Pending';
                            title.style.color = '#1e293b';
                        }
                        if (badge) {
                            badge.style.borderColor = '#84cc16';
                            badge.style.background = 'rgba(132, 204, 22, 0.15)';
                            badge.innerHTML = '<svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="#65a30d" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>';
                        }
                        if (statusBox) {
                            statusBox.style.borderLeftColor = '#84cc16';
                            statusBox.innerHTML = '<div class="status-pill">⏳ IN REVIEW</div><p style="font-weight:600;color:#1e293b;">Your application is under review again. Redirecting...</p>';
                        }
                    }

                    setTimeout(function() {
                        window.location.href = data.redirect;
                    }, 800);
                }
            })
            .catch(function() {
                // Silently ignore connection blips and retry on next interval
            });
        }

        // Poll every 3.5 seconds
        setInterval(pollGymStatus, 3500);
    })();
    </script>
    <?php
    render_footer();
}

