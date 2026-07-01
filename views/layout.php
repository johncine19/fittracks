<?php
declare(strict_types=1);

function render_header(string $title, ?array $user = null): void
{
    $user = $user ?? current_user();
    $role = $user['role'] ?? null;
    $nav = [];
    if ($user) {
        $nav['dashboard'] = 'Dashboard';
        if ($role === 'admin') {
            $nav += ['users' => 'Users', 'trainer_assignments' => 'Trainers', 'plans' => 'Plans', 'payments' => 'Payments', 'walk_ins' => 'Walk-ins', 'classes' => 'Classes', 'scanner' => 'Scan QR', 'reports' => 'Reports'];
        }
        if ($role === 'staff') {
            $nav += ['members' => 'Members', 'trainer_assignments' => 'Trainers', 'memberships' => 'Memberships', 'payments' => 'Payments', 'walk_ins' => 'Walk-ins', 'scanner' => 'Scan QR', 'attendance' => 'Attendance', 'classes' => 'Classes'];
        }
        if ($role === 'trainer') {
            $nav += ['trainer_members' => 'Clients', 'training' => 'Training', 'messages' => 'Messages'];
        }
        if ($role === 'member') {
            $nav += ['profile' => 'Profile', 'qr_attendance' => 'My QR', 'my_diet' => 'Nutrition', 'memberships' => 'Membership', 'payments' => 'Payments', 'book_classes' => 'Classes', 'progress' => 'Progress', 'messages' => 'Messages'];
        }
    }
    $page = $_GET['page'] ?? 'dashboard';
    $flash = flash();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?> - APEX GYM</title>
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
        <!-- Mobile sidebar overlay -->
        <div class="sidebar-overlay" id="sidebar-overlay"></div>
        <div class="app-frame">
            <aside class="sidebar" id="main-sidebar">
                <a class="brand" href="index.php">
                    <span class="brand-mark">AG</span>
                    <span>APEX GYM</span>
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
});
</script>
HTML;

    if (current_user()) {
        echo '</main></section></div>';
        echo $navScript;
        echo $themeScript;
        echo $confirmScript;
        echo '</body></html>';
        return;
    }
    echo '</main>';
    echo $confirmScript;
    echo '</body></html>';
}

function setup_error(Throwable $e): void
{
    http_response_code(500);
    ?>
    <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>FITTRACK setup</title><link rel="stylesheet" href="assets/app.css"></head><body><main class="shell"><section class="panel"><h1>Database setup needed</h1><p>FITTRACK could not connect to the MySQL schema yet.</p><p class="muted"><?= h($e->getMessage()) ?></p><ol><li>Create/import the <code>gym_management</code> database using <code>gym_management.sql</code>.</li><li>Check the database constants at the top of <code>index.php</code>.</li><li>Reload this page.</li></ol></section></main></body></html>
    <?php
}
