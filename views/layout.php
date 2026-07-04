<?php
declare(strict_types=1);

function render_header(string $title, ?array $user = null): void
{
    $isAuthPage = defined('AUTH_PAGE') && AUTH_PAGE;
    $user = $isAuthPage ? null : ($user ?? current_user());
    $role = $user['role'] ?? null;
    $nav = [];
    if ($user) {
        $nav['dashboard'] = 'Dashboard';
        if ($role === 'admin') {
            $nav += [
                'users' => 'Users Accounts',
                'trainer_assignments' => 'Trainers',
                'plans' => 'Plans',
                'memberships' => 'Memberships',
                'payments' => 'Payments',
                'walk_ins' => 'Walk-ins',
                'classes' => 'Classes',
                'attendance' => 'Attendance',
                'scanner' => 'Scan QR',
                'exercises' => 'Exercises',
                'reports' => 'Reports',
                'messages' => 'Messages',
                'notifications' => 'Notifications'
            ];
        }
        if ($role === 'trainer') {
            $nav += ['trainer_members' => 'Clients', 'training' => 'Training', 'classes' => 'My Classes', 'messages' => 'Messages', 'notifications' => 'Notifications'];
        }
        if ($role === 'member') {
            $nav += ['qr_attendance' => 'My QR', 'my_workout' => 'Workouts', 'memberships' => 'Membership', 'payments' => 'Payments', 'book_classes' => 'Classes', 'progress' => 'Progress', 'messages' => 'Messages', 'notifications' => 'Notifications'];
        }
    }
    $page = $_GET['page'] ?? 'dashboard';
    $flash = flash();
    if ($user && ($user['role'] ?? '') === 'member') {
        maybe_notify_membership_renewal((int) $user['user_id']);
    }
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?> - FitTrack</title>
        <link rel="stylesheet" href="assets/app.css">
        <script>
            (function() {
                const saved = localStorage.getItem('fittracks_theme');
                if (saved === 'light') document.documentElement.setAttribute('data-theme', 'light');
            })();
        </script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    </head>
    <body class="<?= $user ? 'app-body' : 'auth-body' ?>">
    <?php if ($flash): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const Toast = Swal.mixin({
                    toast: true, position: 'top-end', showConfirmButton: false,
                    timer: 3000, timerProgressBar: true,
                    background: '#090b10', color: '#ffffff',
                    didOpen: (toast) => {
                        toast.onmouseenter = Swal.stopTimer;
                        toast.onmouseleave = Swal.resumeTimer;
                    }
                });
                Toast.fire({
                    icon: <?= json_encode($flash['type'] === 'danger' ? 'error' : $flash['type']) ?>,
                    title: <?= json_encode($flash['message']) ?>
                });
            });
        </script>
    <?php endif; ?>
    <?php if ($user): ?>
        <!-- Background Video -->
        <?php if (!isset($_COOKIE['fittracks_video_bg']) || $_COOKIE['fittracks_video_bg'] !== 'off'): ?>
        <video autoplay loop muted playsinline class="bg-video" id="app-bg-video">
            <source src="assets/images/miles.mp4" type="video/mp4">
        </video>
        <?php endif; ?>
        
        <!-- Mobile sidebar overlay -->
        <div class="sidebar-overlay" id="sidebar-overlay"></div>
        <div class="app-frame">
            <aside class="sidebar" id="main-sidebar">
                <a class="brand" href="index.php">
                    <span class="brand-mark">FT</span>
                    <span>FitTrack</span>
                </a>
                <!-- Role badge (replaces non-functional role-switch select) -->
                <div class="role-badge"><?= h(ucfirst($user['role'])) ?></div>
                <nav class="side-nav">
                    <?php foreach ($nav as $key => $label): ?>
                        <a class="<?= $page === $key ? 'active' : '' ?>" href="index.php?page=<?= h($key) ?>">
                            <span class="nav-icon"><?= nav_icon($key) ?></span>
                            <span class="nav-label"><?= h($label) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="sidebar-bottom">
                    <a href="index.php?page=profile"><span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span> <span class="nav-label">Settings</span></a>
                    <a href="index.php?page=logout" data-confirm="Are you sure you want to sign out?"><span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></span> <span class="nav-label">Sign Out</span></a>
                </div>
            </aside>
            <section class="main-area">
                <header class="topbar">
                    <div class="topbar-title">
                        <button class="menu-button" id="menu-toggle" type="button" aria-label="Toggle navigation">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                        </button>
                        <div>
                            <h1><?= h($title) ?></h1>
                            <p><?= h(date('D, F j, Y')) ?></p>
                        </div>
                    </div>
                    <div class="user-chip">
                        <?php if ($user && ($user['role'] ?? '') === 'member'): ?>
                            <?php $activeCheckinId = scalar('SELECT attendance_id FROM attendance WHERE user_id = ? AND check_out_time IS NULL ORDER BY check_in_time DESC LIMIT 1', [$user['user_id']]); ?>
                            <?php if ($activeCheckinId): ?>
                                <!-- Hidden checkout form — submitted via JS after optional rating -->
                                <form id="checkout-form" method="post" action="index.php?page=profile" style="display:none;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="self_checkout" value="1">
                                    <input type="hidden" name="attendance_id" value="<?= (int) $activeCheckinId ?>">
                                    <input type="hidden" name="rating"  id="checkout-rating-val"  value="">
                                    <input type="hidden" name="comment" id="checkout-comment-val" value="">
                                </form>
                                <button type="button" id="checkout-btn" style="background: var(--lime); color: var(--bg); font-weight: bold; padding: 0 12px; font-size: 13px; border-radius: 20px; height: 34px; display: flex; align-items: center; border: none; cursor: pointer; white-space: nowrap;">Check Out</button>
                                <script>
                                (function() {
                                    var btn = document.getElementById('checkout-btn');
                                    if (!btn) return;
                                    btn.addEventListener('click', function() {
                                        var selectedRating = 0;

                                        var starHtml = '<div style="display:flex;justify-content:center;gap:10px;margin:12px 0 16px;" id="swal-star-row">'
                                            + [1,2,3,4,5].map(function(v){
                                                return '<span class="co-star" data-val="' + v + '" style="font-size:2rem;cursor:pointer;color:rgba(255,255,255,0.15);transition:color .15s;line-height:1;">★</span>';
                                              }).join('')
                                            + '</div>';

                                        Swal.fire({
                                            title: ' How was your session?',
                                            html: '<p style="color:var(--muted,#8792ad);font-size:13px;margin:0 0 4px;">Rate your experience (optional)</p>'
                                                + starHtml
                                                + '<textarea id="swal-comment" placeholder="Any comments? (optional)" rows="3" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--ink,#f8fafc);font-size:14px;resize:vertical;box-sizing:border-box;"></textarea>',
                                            background: 'var(--surface-color, #18251eff)',
                                            color: 'var(--ink, #46ab5dff)',
                                            showCancelButton: true,
                                            confirmButtonText: 'Submit & Check Out',
                                            cancelButtonText: 'Skip & Check Out',
                                            confirmButtonColor: 'var(--lime, #c7ff22)',
                                            cancelButtonColor: 'transparent',
                                            customClass: { cancelButton: 'swal-skip-btn' },
                                            didOpen: function() {
                                                var stars = document.querySelectorAll('.co-star');
                                                stars.forEach(function(star) {
                                                    star.addEventListener('mouseover', function() {
                                                        var val = parseInt(star.dataset.val);
                                                        stars.forEach(function(s,i){ s.style.color = i < val ? '#c7ff22' : 'rgba(255,255,255,0.15)'; });
                                                    });
                                                    star.addEventListener('mouseout', function() {
                                                        stars.forEach(function(s,i){ s.style.color = i < selectedRating ? '#c7ff22' : 'rgba(255,255,255,0.15)'; });
                                                    });
                                                    star.addEventListener('click', function() {
                                                        selectedRating = parseInt(star.dataset.val);
                                                        stars.forEach(function(s,i){ s.style.color = i < selectedRating ? '#c7ff22' : 'rgba(255,255,255,0.15)'; });
                                                    });
                                                });
                                            },
                                            preConfirm: function() {
                                                document.getElementById('checkout-rating-val').value  = selectedRating || '';
                                                document.getElementById('checkout-comment-val').value = (document.getElementById('swal-comment').value || '').trim();
                                            }
                                        }).then(function(result) {
                                            if (result.isConfirmed) {
                                                document.getElementById('checkout-form').submit();
                                            } else if (result.dismiss === Swal.DismissReason.cancel) {
                                                // Skip — clear rating fields then submit
                                                document.getElementById('checkout-rating-val').value  = '';
                                                document.getElementById('checkout-comment-val').value = '';
                                                document.getElementById('checkout-form').submit();
                                            }
                                        });
                                    });
                                })();
                                </script>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php render_notification_bell($user, $page); ?>
                        <button class="theme-toggle" id="theme-toggle-btn" title="Toggle Light/Dark Mode" type="button">
                            <!-- icon injected by JS -->
                        </button>
                        <?php if (!empty($user['profile_picture'])): ?>
                            <img src="assets/uploads/<?= h($user['profile_picture']) ?>" alt="Avatar" class="avatar" style="object-fit: cover;">
                        <?php else: ?>
                            <span class="avatar"><?= h(initials($user)) ?></span>
                        <?php endif; ?>
                        <div>
                            <strong><?= h($user['first_name'] . ' ' . $user['last_name']) ?></strong>
                            <small><?= h(ucfirst($user['role'])) ?></small>
                        </div>
                    </div>
                </header>
                <main class="shell">
                    <?php if ($user && array_key_exists('email_verified_at', $user) && !$user['email_verified_at']): ?>
                        <div class="panel" style="margin-bottom:16px;padding:12px 16px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;border-left:3px solid var(--lime,#ccff00);">
                            <span>Please verify your email address (<?= h($user['email']) ?>) to secure your account.</span>
                            <form method="post" action="index.php?page=verify_email" style="margin:0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="resend">
                                <button type="submit" class="btn-secondary" style="white-space:nowrap;">Resend verification email</button>
                            </form>
                        </div>
                    <?php endif; ?>
    <?php else: ?>
        <main class="shell auth-shell">
    <?php endif; ?>
    <?php
}

function render_footer(): void
{
    $confirmScript = <<<HTML
<script>
document.addEventListener('click', function(e) {
    const confirmEl = e.target.closest('[data-confirm]');
    if (confirmEl) {
        e.preventDefault();
        Swal.fire({
            title: 'Are you sure?',
            text: confirmEl.getAttribute('data-confirm'),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: confirmEl.getAttribute('data-confirm-btn') || 'OK',
            confirmButtonColor: 'var(--lime, #ccff00)',
            cancelButtonColor: '#d33',
            background: 'var(--surface-color, #090b10)',
            color: 'var(--text-color, #ffffff)'
        }).then((result) => {
            if (result.isConfirmed) {
                if (confirmEl.tagName === 'A') {
                    window.location.href = confirmEl.href;
                } else if (confirmEl.closest('form')) {
                    confirmEl.closest('form').submit();
                }
            }
        });
    }
});
</script>
HTML;

    $themeScript = <<<HTML
<script>
(function() {
    const ICONS = {
        dark: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
        light: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>'
    };
    const btn = document.getElementById('theme-toggle-btn');
    if (!btn) return;
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('fittracks_theme', theme);
        btn.innerHTML = theme === 'light' ? ICONS.light : ICONS.dark;
        btn.title = theme === 'light' ? 'Switch to Dark Mode' : 'Switch to Light Mode';
    }
    const saved = localStorage.getItem('fittracks_theme') || 'dark';
    applyTheme(saved);
    btn.addEventListener('click', function() {
        const current = document.documentElement.getAttribute('data-theme') || 'dark';
        applyTheme(current === 'dark' ? 'light' : 'dark');
    });
})();
</script>
HTML;

    $navScript = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggle   = document.getElementById('menu-toggle');
    const sidebar  = document.getElementById('main-sidebar');
    const overlay  = document.getElementById('sidebar-overlay');
    const frame    = document.querySelector('.app-frame');

    function openSidebar() {
        sidebar?.classList.add('open');
        overlay?.classList.add('open');
    }
    function closeSidebar() {
        sidebar?.classList.remove('open');
        overlay?.classList.remove('open');
    }

    toggle?.addEventListener('click', () => {
        const isMobile = window.innerWidth <= 980;
        if (isMobile) {
            sidebar?.classList.contains('open') ? closeSidebar() : openSidebar();
        } else {
            frame?.classList.toggle('collapsed');
        }
    });

    overlay?.addEventListener('click', closeSidebar);

    // Close drawer on nav link click (mobile)
    sidebar?.querySelectorAll('a').forEach(a => {
        a.addEventListener('click', () => {
            if (window.innerWidth <= 980) closeSidebar();
        });
    });

    const notifToggle = document.getElementById('notif-toggle');
    const notifDropdown = document.getElementById('notif-dropdown');
    const notifWrap = document.getElementById('notif-wrap');
    notifToggle?.addEventListener('click', function(e) {
        e.stopPropagation();
        const open = !notifDropdown?.hasAttribute('hidden');
        if (open) {
            notifDropdown?.setAttribute('hidden', '');
            notifToggle.setAttribute('aria-expanded', 'false');
        } else {
            notifDropdown?.removeAttribute('hidden');
            notifToggle.setAttribute('aria-expanded', 'true');
        }
    });
    document.addEventListener('click', function(e) {
        if (!notifWrap || notifDropdown?.hasAttribute('hidden')) return;
        if (!notifWrap.contains(e.target)) {
            notifDropdown.setAttribute('hidden', '');
            notifToggle?.setAttribute('aria-expanded', 'false');
        }
    });
});
</script>
HTML;

    $passwordScript = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    function setupPasswordToggle(input) {
        if (input.dataset.hasToggle) return;
        input.dataset.hasToggle = 'true';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'password-toggle-btn';
        btn.style.cssText = 'background: none; border: none; padding: 0; margin-left: 8px; cursor: pointer; display: flex; align-items: center; color: var(--muted);';
        
        btn.innerHTML = `
            <svg class="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="display: none; position: static !important; left: auto !important; pointer-events: none;">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                <circle cx="12" cy="12" r="3"></circle>
            </svg>
            <svg class="eye-off-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="position: static !important; left: auto !important; pointer-events: none;">
                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                <line x1="1" y1="1" x2="23" y2="23"></line>
            </svg>
        `;
        
        const parent = input.parentElement;
        if (parent.classList.contains('auth-input-group')) {
            parent.appendChild(btn);
            btn.style.position = 'absolute';
            btn.style.right = '12px';
            input.style.paddingRight = '35px';
        } else {
            const wrapper = document.createElement('div');
            wrapper.style.position = 'relative';
            const computedStyle = window.getComputedStyle(input);
            wrapper.style.display = input.style.display === 'block' || computedStyle.display === 'block' ? 'block' : 'inline-block';
            wrapper.style.width = input.style.width || '100%';
            
            if (input.classList.contains('form-control') || input.style.width === '100%') {
                 wrapper.style.display = 'block';
            }

            if (input.classList.contains('swal2-input')) {
                // Do not wrap SweetAlert inputs as it breaks Swal.getInput()
                input.parentNode.insertBefore(btn, input.nextSibling);
                btn.style.position = 'absolute';
                
                const container = input.parentElement;
                if (window.getComputedStyle(container).position === 'static') {
                    container.style.position = 'relative';
                }
                
                input.style.paddingRight = '35px';
                
                const updatePosition = () => {
                    if (!input.offsetWidth) return;
                    btn.style.top = (input.offsetTop + input.offsetHeight / 2) + 'px';
                    btn.style.left = (input.offsetLeft + input.offsetWidth - 35) + 'px';
                    btn.style.transform = 'translateY(-50%)';
                };
                
                updatePosition();
                
                const ro = new ResizeObserver(updatePosition);
                ro.observe(input);
                if (container) ro.observe(container);
            } else {
                const wrapper = document.createElement('div');
                wrapper.style.position = 'relative';
                const computedStyle = window.getComputedStyle(input);
                wrapper.style.display = input.style.display === 'block' || computedStyle.display === 'block' ? 'block' : 'inline-block';
                wrapper.style.width = input.style.width || '100%';
                
                if (input.classList.contains('form-control') || input.style.width === '100%') {
                     wrapper.style.display = 'block';
                }

                if (computedStyle.margin !== '0px') {
                    wrapper.style.margin = computedStyle.margin;
                    input.style.margin = '0';
                }
                
                input.parentNode.insertBefore(wrapper, input);
                wrapper.appendChild(input);
                
                btn.style.position = 'absolute';
                btn.style.right = '10px';
                btn.style.top = '50%';
                btn.style.transform = 'translateY(-50%)';
                btn.style.marginLeft = '0';
                
                input.style.paddingRight = '35px';
                wrapper.appendChild(btn);
            }
        }
        
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            if (input.type === 'password') {
                input.type = 'text';
                btn.querySelector('.eye-icon').style.display = 'block';
                btn.querySelector('.eye-off-icon').style.display = 'none';
            } else {
                input.type = 'password';
                btn.querySelector('.eye-icon').style.display = 'none';
                btn.querySelector('.eye-off-icon').style.display = 'block';
            }
        });
    }

    // Initialize existing ones
    document.querySelectorAll('input[type="password"]').forEach(setupPasswordToggle);

    // Watch for new ones being added dynamically
    const observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            for (const node of mutation.addedNodes) {
                if (node.nodeType === 1) { // Element node
                    if (node.matches && node.matches('input[type="password"]')) {
                        setupPasswordToggle(node);
                    }
                    if (node.querySelectorAll) {
                        node.querySelectorAll('input[type="password"]').forEach(setupPasswordToggle);
                    }
                }
            }
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
});
</script>
HTML;

    $skeletonScript = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.body.classList.add('loaded');
});
</script>
HTML;

    $isAuthPage = defined('AUTH_PAGE') && AUTH_PAGE;

    if (!$isAuthPage && current_user()) {
        echo '</main></section></div>';
        echo $navScript;
        echo $themeScript;
        echo $confirmScript;
        echo $passwordScript;
        echo $skeletonScript;
        echo '</body></html>';
        return;
    }
    echo '</main>';
    echo $confirmScript;
    echo $passwordScript;
    echo $skeletonScript;
    echo '</body></html>';
}

function setup_error(Throwable $e): void
{
    http_response_code(500);
    ?>
    <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>FITTRACK setup</title><link rel="stylesheet" href="assets/app.css"></head><body><main class="shell"><section class="panel"><h1>Database setup needed</h1><p>FITTRACK could not connect to the MySQL schema yet.</p><p class="muted"><?= h($e->getMessage()) ?></p><ol><li>Create/import the <code>gym_management</code> database using <code>gym_management.sql</code>.</li><li>Check the database constants at the top of <code>index.php</code>.</li><li>Reload this page.</li></ol></section></main></body></html>
    <?php
}
