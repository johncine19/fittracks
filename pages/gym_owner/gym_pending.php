<?php
declare(strict_types=1);

function gym_pending_page(): void
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
    $gym = $pdo->query('SELECT status, name, subscription_status, subscription_plan FROM gyms WHERE owner_user_id = ' . (int)$user['user_id'])->fetch();
    
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
        } elseif ($currentStatus === 'rejected') {
            $targetRedirect = 'index.php?page=gym_rejected';
        } elseif ($currentStatus !== 'pending') {
            $targetRedirect = 'index.php?page=dashboard';
        }

        echo json_encode([
            'status' => $currentStatus,
            'redirect' => $targetRedirect
        ]);
        exit;
    }

    if (!$gym || $gym['status'] !== 'pending') {
        if ($gym && $gym['status'] === 'approved') {
            $isActiveSub = ($gym['subscription_status'] === 'active' && !empty($gym['subscription_plan']));
            redirect($isActiveSub ? 'dashboard' : 'gym_subscription');
        } elseif ($gym && $gym['status'] === 'rejected') {
            redirect('gym_rejected');
        } else {
            redirect('gym_onboarding');
        }
    }

    // Suppress conflicting "Welcome back" flash toast on pending page
    if (isset($_SESSION['flash']['message']) && str_starts_with((string)$_SESSION['flash']['message'], 'Welcome back')) {
        unset($_SESSION['flash']);
    }

    render_header('Application Pending', $user);
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
                            Your Facility Setup
                            <span class="highlight">Is Under Review.</span>
                        </h1>
                        <p class="showcase-desc">
                            Our team is currently verifying your submitted documents. Once approved, your facility dashboard will be activated.
                        </p>
                    </div>

                    <!-- Three Feature Items / Timeline -->
                    <div class="showcase-features">
                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Documents Received</h4>
                                <p>Your business permit and valid ID have been securely saved and queued.</p>
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
                                <h4>Verification In Progress</h4>
                                <p>We verify facility credentials to maintain platform security and trust.</p>
                            </div>
                        </div>

                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M5 13l4 4L19 7"></path>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Instant Activation</h4>
                                <p>Upon approval, member management, plans, and QR check-ins unlock instantly.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Side Elevated Status Card -->
            <div class="split-login-card-pane">
                <div class="split-login-card status-card">
                    <!-- Glowing Loader Badge -->
                    <div class="split-status-badge">
                        <svg class="fitness-loader" xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="6" y1="12" x2="18" y2="12"></line>
                            <rect x="4" y="8" width="2" height="8" rx="1"></rect>
                            <rect x="18" y="8" width="2" height="8" rx="1"></rect>
                            <rect x="2" y="10" width="2" height="4" rx="1"></rect>
                            <rect x="20" y="10" width="2" height="4" rx="1"></rect>
                        </svg>
                    </div>

                    <div class="split-card-header" style="margin-bottom: 6px;">
                        <h2 class="split-card-title">Under Review</h2>
                        <p class="split-card-subtitle">
                            <?= !empty($gym['name']) ? 'Verifying facility: <span class="brand-highlight">' . h($gym['name']) . '</span>' : 'Verifying your facility profile' ?>
                        </p>
                    </div>

                    <!-- Status Info Box -->
                    <div class="split-status-box">
                        <div class="status-pill">
                            <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#65a30d;"></span>
                            In Review &bull; 1-2 Business Days
                        </div>
                        <p>
                            Your gym application has been received and is currently being verified by our administration team. 
                            We will send an email confirmation to <strong><?= h($user['email']) ?></strong> as soon as your account is approved.
                        </p>
                    </div>

                    <!-- Action Buttons -->
                    <div style="display: flex; flex-direction: column; gap: 10px; width: 100%; margin-top: 6px;">
                        <button type="button" onclick="location.reload()" class="split-submit-btn">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="23 4 23 10 17 10"></polyline>
                                <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                            </svg>
                            <span>Check Status / Refresh</span>
                        </button>

                        <div class="split-card-footer" style="margin-top: 8px;">
                            <div>
                                <a href="index.php?page=logout" class="home-link">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                                    Sign Out
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

            fetch('index.php?page=gym_pending&action=check_status', {
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
                        if (title) title.textContent = 'Application Approved!';
                        if (badge) {
                            badge.style.borderColor = '#84cc16';
                            badge.style.background = 'rgba(132, 204, 22, 0.25)';
                            badge.innerHTML = '<svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="#65a30d" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                        }
                        if (statusBox) {
                            statusBox.style.borderLeftColor = '#84cc16';
                            statusBox.innerHTML = '<div class="status-pill" style="background:rgba(132,204,22,0.25);color:#3f6212;">✓ APPROVED</div><p style="font-weight:600;color:#1e293b;">Your facility has been approved! Redirecting to setup...</p>';
                        }
                    } else if (data.status === 'rejected') {
                        if (title) {
                            title.textContent = 'Application Updated';
                            title.style.color = '#ef4444';
                        }
                        if (badge) {
                            badge.style.borderColor = '#ef4444';
                            badge.style.background = 'rgba(239, 68, 68, 0.2)';
                            badge.innerHTML = '<svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>';
                        }
                        if (statusBox) {
                            statusBox.style.borderLeftColor = '#ef4444';
                            statusBox.innerHTML = '<div class="status-pill" style="background:rgba(239,68,68,0.2);color:#b91c1c;">⚠️ ACTION REQUIRED</div><p style="font-weight:600;color:#1e293b;">Your application has been reviewed. Redirecting...</p>';
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

