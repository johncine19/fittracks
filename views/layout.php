<?php
declare(strict_types=1);

function render_header(string $title, ?array $user = null): void
{
    $isAuthPage = defined('AUTH_PAGE') && AUTH_PAGE;
    $user = $isAuthPage ? null : ($user ?? current_user());
    $role = $user['role'] ?? null;
    $gym = null;
    if ($user) {
        $gym = get_user_gym($user);
    }
    $nav = [];
    if ($user) {
        $nav['dashboard'] = 'Dashboard';
        if ($role === 'platform_admin') {
            $nav += [
                'gym_applications' => 'Gym Applications',
                'gyms' => 'All Gyms',
                'users' => 'Users Accounts',
                'platform_plans' => 'Subscription Plans',
                'food_library' => 'Food Library',
                'reports' => 'Reports',
                'audit_logs' => 'Audit Logs',
                'announcements' => 'Announcements',
            ];
        }
        if ($role === 'gym_owner') {
            $nav += [
                'gym_profile' => 'Gym Profile',
                'scanner' => 'Scan QR',
                'users' => 'Users Accounts',
                'trainer_assignments' => 'Trainers',
                'plans' => 'Plans',
                'training' => 'Workouts',
                'memberships' => 'Memberships',
                'commissions' => 'Commissions',
                'walk_ins' => 'Walk-ins',
                'classes' => 'Classes',
                'attendance' => 'Attendance',
                'exercises' => 'Exercises',
                'food_library' => 'Food Library',
                'gym_equipment' => 'Equipment',
                'reports' => 'Reports',
                'messages' => 'Messages',
                'notifications' => 'Notifications'
            ];
        }
        if ($role === 'trainer') {
            $nav += ['qr_attendance' => 'My QR', 'my_commissions' => 'Commissions', 'trainer_members' => 'Clients', 'training' => 'Workouts', 'classes' => 'My Classes', 'messages' => 'Messages', 'notifications' => 'Notifications'];
        }
        if ($role === 'member') {
            $isGymMember = db()->prepare('SELECT 1 FROM gym_members WHERE user_id = ?');
            $isGymMember->execute([$user['user_id']]);
            $hasGym = (bool) $isGymMember->fetchColumn();

            $nav += ['qr_attendance' => 'My QR', 'my_workout' => 'Workouts', 'diet' => 'Diet Plan'];
            
            if ($hasGym) {
                $nav += ['equipment' => 'Equipment', 'trainers' => 'Trainers', 'memberships' => 'Membership', 'book_classes' => 'Classes', 'gym_selection' => 'Browse Gyms'];
            } else {
                $nav += ['gym_selection' => 'Select Gym'];
            }
            
            $nav += ['payments' => 'Payments', 'progress' => 'Progress', 'messages' => 'Messages', 'notifications' => 'Notifications'];
        }
    }
    $page = $_GET['page'] ?? 'dashboard';
    $flash = flash();
    if ($user && ($user['role'] ?? '') === 'member') {
        maybe_notify_membership_renewal((int) $user['user_id']);
        maybe_notify_membership_expired((int) $user['user_id']);
    }
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?> - <?= h($gym['name'] ?? 'FitTrack') ?></title>
        <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/../assets/app.css') ?>">
        <link rel="stylesheet" href="assets/dropdown.css?v=<?= file_exists(__DIR__ . '/../assets/dropdown.css') ? filemtime(__DIR__ . '/../assets/dropdown.css') : 1 ?>">
        <?php if ($gym && !empty($gym['brand_color'])): ?>
            <style>
                :root, [data-theme="light"], [data-theme="dark"] {
                    --lime: <?= h($gym['brand_color']) ?> !important;
                    --lime-dark: color-mix(in srgb, <?= h($gym['brand_color']) ?> 80%, black) !important;
                }
            </style>
        <?php endif; ?>
        <script>
            (function() {
                const saved = localStorage.getItem('fittracks_theme') || 'light';
                document.documentElement.setAttribute('data-theme', saved);
            })();

            // Global robust HTML entity escaping helper for client-side templates
            window.escapeHtml = function(str) {
                if (str === null || str === undefined) return '';
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            };
        </script>
        <script src="assets/dropdown.js?v=<?= file_exists(__DIR__ . '/../assets/dropdown.js') ? filemtime(__DIR__ . '/../assets/dropdown.js') : 1 ?>"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            if (typeof Chart === 'undefined') {
                document.write('<script src="assets/chart.umd.min.js"><\/script>');
            }
        </script>
        <script>
            // Global SVG Vector Icons for SweetAlert2 (eliminates distorted CSS multi-div checkmarks)
            (function() {
                if (!window.Swal) return;
                const checkmarkSvg = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:auto;"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                const crossSvg = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:auto;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
                const warnSvg = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:auto;"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
                const infoSvg = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:auto;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>';

                function injectToastIcon(opts) {
                    if (!opts || typeof opts !== 'object') return opts;
                    if (opts.icon === 'success' && !opts.iconHtml) opts.iconHtml = checkmarkSvg;
                    else if ((opts.icon === 'error' || opts.icon === 'danger') && !opts.iconHtml) opts.iconHtml = crossSvg;
                    else if (opts.icon === 'warning' && !opts.iconHtml) opts.iconHtml = warnSvg;
                    else if (opts.icon === 'info' && !opts.iconHtml) opts.iconHtml = infoSvg;
                    return opts;
                }

                const origFire = window.Swal.fire.bind(window.Swal);
                window.Swal.fire = function(...args) {
                    if (args.length === 1 && typeof args[0] === 'object') {
                        args[0] = injectToastIcon(args[0]);
                    } else if (args.length >= 3 && typeof args[2] === 'string') {
                        const icon = args[2];
                        const svg = icon === 'success' ? checkmarkSvg : (icon === 'error' ? crossSvg : (icon === 'warning' ? warnSvg : (icon === 'info' ? infoSvg : null)));
                        if (svg) {
                            return origFire({ title: args[0], html: args[1], icon: icon, iconHtml: svg });
                        }
                    }
                    return origFire(...args);
                };

                const origMixin = window.Swal.mixin.bind(window.Swal);
                window.Swal.mixin = function(mixinOpts) {
                    const instance = origMixin(mixinOpts);
                    const origInstFire = instance.fire.bind(instance);
                    instance.fire = function(...args) {
                        if (args.length === 1 && typeof args[0] === 'object') {
                            args[0] = injectToastIcon(args[0]);
                        }
                        return origInstFire(...args);
                    };
                    return instance;
                };
            })();
        </script>
        <script src="assets/audio.js?v=<?= filemtime(__DIR__ . '/../assets/audio.js') ?>"></script>
    </head>
    <body class="<?= $user ? 'app-body' : 'auth-body' ?>">
    
    <?php if (!$user): ?>
    <!-- Grain texture overlay for auth pages -->
    <svg style="position:fixed;inset:0;width:100%;height:100%;z-index:190;pointer-events:none;opacity:0.045;mix-blend-mode:overlay;" aria-hidden="true">
        <filter id="grain-filter"><feTurbulence type="fractalNoise" baseFrequency="0.85" numOctaves="3" stitchTiles="stitch"></feTurbulence><feColorMatrix type="saturate" values="0"></feColorMatrix></filter>
        <rect width="100%" height="100%" filter="url(#grain-filter)"></rect>
    </svg>
    <?php endif; ?>

    <style>
        /* Ensure links inside SweetAlert toasts are always clickable */
        .swal2-toast .swal2-html-container { pointer-events: auto !important; }
        .swal2-toast a { pointer-events: auto !important; position: relative; z-index: 2; }

        /* Sleek Modern Toast & Card Styling */
        .swal2-popup.swal2-toast {
            border-radius: 12px !important;
            padding: 12px 16px !important;
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.45), 0 2px 8px rgba(0, 0, 0, 0.25) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            background: rgba(18, 23, 33, 0.95) !important;
            backdrop-filter: blur(20px) saturate(180%) !important;
            -webkit-backdrop-filter: blur(20px) saturate(180%) !important;
            font-size: 13.5px !important;
            display: flex !important;
            align-items: center !important;
            cursor: pointer !important;
            transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.2s ease !important;
        }
        .swal2-popup.swal2-toast:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.55), 0 4px 12px rgba(0, 0, 0, 0.3) !important;
        }
        [data-theme="light"] .swal2-popup.swal2-toast {
            background: rgba(255, 255, 255, 0.96) !important;
            border: 1px solid #cbd5e1 !important;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1), 0 2px 6px rgba(0, 0, 0, 0.05) !important;
            color: #0f172a !important;
        }

        /* High-specificity override: Completely eliminate SweetAlert's internal animated multi-div lines */
        .swal2-popup.swal2-toast .swal2-icon [class^="swal2-success-"],
        .swal2-popup.swal2-toast .swal2-icon [class^="swal2-x-mark"],
        .swal2-popup.swal2-toast .swal2-icon .swal2-success-ring,
        .swal2-popup.swal2-toast .swal2-icon .swal2-success-fix,
        .swal2-popup.swal2-toast .swal2-icon .swal2-success-line-tip,
        .swal2-popup.swal2-toast .swal2-icon .swal2-success-line-long,
        .swal2-popup.swal2-toast .swal2-icon [class*="circular-line"],
        .swal2-popup.swal2-toast .swal2-icon > div:not(.swal2-icon-content),
        .swal2-popup.swal2-toast .swal2-icon > span {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
            width: 0 !important;
            height: 0 !important;
            border: none !important;
            transform: none !important;
            position: static !important;
        }
        .swal2-popup.swal2-toast .swal2-icon::before,
        .swal2-popup.swal2-toast .swal2-icon::after {
            display: none !important;
        }

        /* Modern Vector SVG Toast Icon Badges */
        .swal2-popup.swal2-toast .swal2-icon {
            width: 28px !important;
            height: 28px !important;
            min-width: 28px !important;
            max-width: 28px !important;
            margin: 0 12px 0 0 !important;
            border-radius: 50% !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            position: relative !important;
            transform: none !important;
            flex-shrink: 0 !important;
            border: 1.5px solid transparent !important;
            box-sizing: border-box !important;
            overflow: hidden !important;
        }
        .swal2-popup.swal2-toast .swal2-icon .swal2-icon-content {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 100% !important;
            height: 100% !important;
            font-size: 14px !important;
            line-height: 1 !important;
            visibility: visible !important;
            opacity: 1 !important;
        }
        .swal2-popup.swal2-toast .swal2-icon .swal2-icon-content svg {
            display: block !important;
            width: 16px !important;
            height: 16px !important;
            visibility: visible !important;
            opacity: 1 !important;
            flex-shrink: 0 !important;
        }

        /* Success Icon - Unmistakable Bold Green Checkmark (✓) */
        .swal2-toast .swal2-icon.swal2-success {
            border-color: rgba(132, 204, 22, 0.5) !important;
            background-color: rgba(132, 204, 22, 0.14) !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2384cc16' stroke-width='3.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='20 6 9 17 4 12'/%3E%3C/svg%3E") !important;
            background-repeat: no-repeat !important;
            background-position: center !important;
            background-size: 15px 15px !important;
        }
        [data-theme="light"] .swal2-toast .swal2-icon.swal2-success {
            border-color: rgba(101, 163, 13, 0.5) !important;
            background-color: rgba(101, 163, 13, 0.14) !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2365a30d' stroke-width='3.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='20 6 9 17 4 12'/%3E%3C/svg%3E") !important;
        }

        /* Error Icon - Bold Red Cross (✕) */
        .swal2-toast .swal2-icon.swal2-error {
            border-color: rgba(239, 68, 68, 0.5) !important;
            background-color: rgba(239, 68, 68, 0.14) !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23ef4444' stroke-width='3.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cline x1='18' y1='6' x2='6' y2='18'/%3E%3Cline x1='6' y1='6' x2='18' y2='18'/%3E%3C/svg%3E") !important;
            background-repeat: no-repeat !important;
            background-position: center !important;
            background-size: 14px 14px !important;
        }
        [data-theme="light"] .swal2-toast .swal2-icon.swal2-error {
            border-color: rgba(220, 38, 38, 0.5) !important;
            background-color: rgba(220, 38, 38, 0.14) !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23dc2626' stroke-width='3.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cline x1='18' y1='6' x2='6' y2='18'/%3E%3Cline x1='6' y1='6' x2='18' y2='18'/%3E%3C/svg%3E") !important;
        }

        /* Warning Icon - Amber Alert Triangle */
        .swal2-toast .swal2-icon.swal2-warning {
            border-color: rgba(245, 158, 11, 0.5) !important;
            background-color: rgba(245, 158, 11, 0.14) !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23f59e0b' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z'/%3E%3Cline x1='12' y1='9' x2='12' y2='13'/%3E%3Cline x1='12' y1='17' x2='12.01' y2='17'/%3E%3C/svg%3E") !important;
            background-repeat: no-repeat !important;
            background-position: center !important;
            background-size: 15px 15px !important;
        }
        [data-theme="light"] .swal2-toast .swal2-icon.swal2-warning {
            border-color: rgba(217, 119, 6, 0.5) !important;
            background-color: rgba(217, 119, 6, 0.14) !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23d97706' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z'/%3E%3Cline x1='12' y1='9' x2='12' y2='13'/%3E%3Cline x1='12' y1='17' x2='12.01' y2='17'/%3E%3C/svg%3E") !important;
        }

        /* Info Icon - Sky Blue Info Badge */
        .swal2-toast .swal2-icon.swal2-info {
            border-color: rgba(56, 189, 248, 0.5) !important;
            background-color: rgba(56, 189, 248, 0.14) !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2338bdf8' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='12' cy='12' r='10'/%3E%3Cline x1='12' y1='16' x2='12' y2='12'/%3E%3Cline x1='12' y1='8' x2='12.01' y2='8'/%3E%3C/svg%3E") !important;
            background-repeat: no-repeat !important;
            background-position: center !important;
            background-size: 15px 15px !important;
        }
        [data-theme="light"] .swal2-toast .swal2-icon.swal2-info {
            border-color: rgba(2, 132, 199, 0.5) !important;
            background-color: rgba(2, 132, 199, 0.14) !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%230284c7' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='12' cy='12' r='10'/%3E%3Cline x1='12' y1='16' x2='12' y2='12'/%3E%3Cline x1='12' y1='8' x2='12.01' y2='8'/%3E%3C/svg%3E") !important;
        }

        /* Toast Content Typography */
        .swal2-toast .swal2-title {
            font-size: 13.5px !important;
            font-weight: 600 !important;
            margin: 0 !important;
            padding: 0 !important;
            line-height: 1.4 !important;
            color: #f8fafc !important;
        }
        [data-theme="light"] .swal2-toast .swal2-title {
            color: #0f172a !important;
        }
        .swal2-toast .swal2-html-container {
            font-size: 12.5px !important;
            font-weight: 400 !important;
            margin: 2px 0 0 0 !important;
            padding: 0 !important;
            line-height: 1.4 !important;
            text-align: left !important;
            color: #cbd5e1 !important;
        }
        [data-theme="light"] .swal2-toast .swal2-html-container {
            color: #475569 !important;
        }
        .swal2-toast .swal2-actions {
            margin: 8px 0 0 0 !important;
            padding: 0 !important;
            width: 100% !important;
            justify-content: flex-start !important;
            gap: 6px !important;
        }
        .swal2-toast .swal2-confirm {
            background: #84cc16 !important;
            color: #080b0d !important;
            font-size: 11.5px !important;
            font-weight: 700 !important;
            border-radius: 999px !important;
            padding: 5px 14px !important;
            min-height: auto !important;
            margin: 0 !important;
            box-shadow: 0 2px 8px rgba(132, 204, 22, 0.25) !important;
            border: none !important;
            cursor: pointer !important;
            transition: all 0.2s ease !important;
        }
        .swal2-toast .swal2-confirm:hover {
            opacity: 0.92 !important;
            transform: translateY(-1px) !important;
        }
        
        /* Dynamic Timer Progress Bar */
        .swal2-toast .swal2-timer-progress-bar {
            height: 3px !important;
            border-radius: 0 0 12px 12px !important;
            opacity: 0.9 !important;
            background: #84cc16 !important;
        }
        .swal2-toast:has(.swal2-icon.swal2-success) .swal2-timer-progress-bar { background: #84cc16 !important; }
        .swal2-toast:has(.swal2-icon.swal2-error) .swal2-timer-progress-bar { background: #ef4444 !important; }
        .swal2-toast:has(.swal2-icon.swal2-warning) .swal2-timer-progress-bar { background: #f59e0b !important; }
        .swal2-toast:has(.swal2-icon.swal2-info) .swal2-timer-progress-bar { background: #38bdf8 !important; }

        /* Mobile specific bottom pill positioning */
        @media (max-width: 768px) {
            .swal2-container.swal2-bottom {
                bottom: calc(env(safe-area-inset-bottom, 0px) + 20px) !important;
                padding: 0 14px !important;
                z-index: 10000 !important;
            }
            .swal2-popup.swal2-toast {
                width: auto !important;
                max-width: calc(100vw - 28px) !important;
                margin: 0 auto !important;
                padding: 10px 14px !important;
                font-size: 12.5px !important;
                border-radius: 12px !important;
            }
            .swal2-toast .swal2-title {
                font-size: 13px !important;
            }
            .swal2-toast .swal2-html-container {
                font-size: 12px !important;
            }
            .swal2-toast .swal2-icon {
                width: 24px !important;
                height: 24px !important;
                min-width: 24px !important;
                margin: 0 10px 0 0 !important;
            }
            .swal2-toast .swal2-icon::after {
                width: 13px !important;
                height: 13px !important;
            }
        }

        /* SweetAlert Opaque Modal Theming (Prevents transparent see-through popups in dark mode) */
        .swal2-popup:not(.swal2-toast) {
            background: #11141d !important;
            color: #f8fafc !important;
            border: 1px solid rgba(255, 255, 255, 0.12) !important;
            border-radius: 14px !important;
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.75) !important;
        }
        [data-theme="light"] .swal2-popup:not(.swal2-toast) {
            background: #ffffff !important;
            color: #0f172a !important;
            border: 1px solid #cbd5e1 !important;
            box-shadow: 0 16px 36px rgba(0, 0, 0, 0.12) !important;
        }
        .swal2-popup:not(.swal2-toast) .swal2-title {
            color: #f8fafc !important;
        }
        [data-theme="light"] .swal2-popup:not(.swal2-toast) .swal2-title {
            color: #0f172a !important;
        }
        .swal2-popup:not(.swal2-toast) .swal2-html-container {
            color: #94a3b8 !important;
        }
        [data-theme="light"] .swal2-popup:not(.swal2-toast) .swal2-html-container {
            color: #475569 !important;
        }

        /* SweetAlert Action Buttons Theming */
        .swal2-popup:not(.swal2-toast) .swal2-actions {
            gap: 10px;
            margin-top: 1.25rem !important;
        }
        .swal2-popup:not(.swal2-toast) .swal2-confirm {
            border-radius: 8px !important;
            font-weight: 600 !important;
            font-size: 13.5px !important;
            padding: 9px 20px !important;
            box-shadow: none !important;
        }
        .swal2-popup:not(.swal2-toast) .swal2-styled.swal2-cancel,
        .swal2-styled.swal2-cancel {
            background-color: rgba(255, 255, 255, 0.08) !important;
            color: #f8fafc !important;
            border: 1px solid rgba(255, 255, 255, 0.16) !important;
            border-radius: 8px !important;
            font-weight: 600 !important;
            font-size: 13.5px !important;
            padding: 9px 20px !important;
            box-shadow: none !important;
            transition: all 0.15s ease !important;
        }
        .swal2-popup:not(.swal2-toast) .swal2-styled.swal2-cancel:hover,
        .swal2-styled.swal2-cancel:hover {
            background-color: rgba(255, 255, 255, 0.14) !important;
            color: #ffffff !important;
            border-color: rgba(255, 255, 255, 0.28) !important;
        }
        [data-theme="light"] .swal2-popup:not(.swal2-toast) .swal2-styled.swal2-cancel,
        [data-theme="light"] .swal2-styled.swal2-cancel {
            background-color: #f1f5f9 !important;
            color: #334155 !important;
            border: 1px solid #cbd5e1 !important;
        }
        [data-theme="light"] .swal2-popup:not(.swal2-toast) .swal2-styled.swal2-cancel:hover,
        [data-theme="light"] .swal2-styled.swal2-cancel:hover {
            background-color: #e2e8f0 !important;
            color: #0f172a !important;
            border-color: #94a3b8 !important;
        }

        /* SweetAlert Form Controls & Inputs Theming */
        .swal2-popup:not(.swal2-toast) .form-control,
        .swal2-popup:not(.swal2-toast) input:not([type="checkbox"]):not([type="radio"]):not([type="submit"]):not([type="button"]),
        .swal2-popup:not(.swal2-toast) select,
        .swal2-popup:not(.swal2-toast) textarea {
            background-color: #141824 !important;
            color: #f8fafc !important;
            border: 1px solid rgba(255, 255, 255, 0.14) !important;
            color-scheme: dark;
        }
        .swal2-popup:not(.swal2-toast) select option {
            background-color: #141824;
            color: #f8fafc;
        }
        [data-theme="light"] .swal2-popup:not(.swal2-toast) .form-control,
        [data-theme="light"] .swal2-popup:not(.swal2-toast) input:not([type="checkbox"]):not([type="radio"]):not([type="submit"]):not([type="button"]),
        [data-theme="light"] .swal2-popup:not(.swal2-toast) select,
        [data-theme="light"] .swal2-popup:not(.swal2-toast) textarea {
            background-color: #ffffff !important;
            color: #0f172a !important;
            border: 1px solid #cbd5e1 !important;
            color-scheme: light;
        }
        [data-theme="light"] .swal2-popup:not(.swal2-toast) input::placeholder,
        [data-theme="light"] .swal2-popup:not(.swal2-toast) textarea::placeholder {
            color: #94a3b8 !important;
        }
        [data-theme="light"] .swal2-popup:not(.swal2-toast) select option {
            background-color: #ffffff;
            color: #0f172a;
        }
        [data-theme="light"] .swal2-popup:not(.swal2-toast) label {
            color: #475569 !important;
        }

        /* Light Mode User Avatar */
        [data-theme="light"] .avatar {
            background: #ecfccb !important;
            border: 1px solid #bef264 !important;
            color: #365314 !important;
        }
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear,
        input::-ms-reveal,
        input::-ms-clear {
            display: none !important;
            width: 0 !important;
            height: 0 !important;
            pointer-events: none !important;
        }

        /* Topbar Header Responsive & Layer Stacking Rules */
        .topbar {
            position: sticky !important;
            top: 0 !important;
            z-index: 100 !important;
        }
        .notif-wrap {
            position: relative !important;
            z-index: 105 !important;
        }
        .topbar-title {
            min-width: 0;
            flex: 1 1 auto;
        }
        .topbar-title > div {
            min-width: 0;
        }
        .topbar h1 {
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            line-height: 1.25 !important;
        }
        .topbar-title p {
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }
        .notif-menu p {
            display: block !important;
            white-space: normal !important;
            overflow: visible !important;
            text-overflow: clip !important;
            font-size: 12px !important;
            line-height: 1.45 !important;
            color: var(--muted) !important;
            margin: 0 0 6px !important;
            word-break: break-word !important;
        }
        @media (max-width: 640px) {
            .topbar {
                gap: 8px !important;
                padding: 10px 14px !important;
                min-height: 58px !important;
            }
            .topbar-title {
                gap: 10px !important;
            }
            .topbar h1 {
                font-size: 16px !important;
            }
            .topbar-title p {
                font-size: 11px !important;
            }
            .user-chip {
                gap: 6px !important;
                flex-shrink: 0 !important;
            }
        }
        @media (max-width: 440px) {
            .topbar {
                padding: 8px 10px !important;
                gap: 6px !important;
            }
            .topbar-title {
                gap: 8px !important;
            }
            .topbar h1 {
                font-size: 15px !important;
            }
            .topbar-title p {
                display: none !important;
            }
            .user-chip {
                gap: 4px !important;
            }
            .menu-button,
            .theme-toggle,
            .notif-bell,
            .user-chip .avatar {
                width: 32px !important;
                height: 32px !important;
            }
        }

        /* Trial / Free Tier Compact Banner */
        .trial-compact-banner {
            margin-bottom: 16px;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.12);
            transition: all 0.2s ease;
        }
        .trial-compact-banner.is-trial {
            background: linear-gradient(90deg, rgba(132, 204, 22, 0.12), rgba(16, 185, 129, 0.05));
            border: 1px solid rgba(132, 204, 22, 0.35);
        }
        .trial-compact-banner.is-free {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--line);
        }
        .trial-banner-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            flex: 1 1 auto;
        }
        .trial-banner-icon {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .is-trial .trial-banner-icon {
            background: rgba(132, 204, 22, 0.2);
            color: var(--lime, #84cc16);
        }
        .is-free .trial-banner-icon {
            background: rgba(255, 255, 255, 0.07);
            color: var(--ink);
        }
        .trial-banner-content {
            min-width: 0;
            display: flex;
            flex-direction: column;
        }
        .trial-banner-title-row {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: nowrap;
        }
        .trial-banner-title {
            font-size: 13.5px;
            font-weight: 700;
            white-space: nowrap;
        }
        .is-trial .trial-banner-title {
            color: var(--lime, #84cc16);
        }
        .is-free .trial-banner-title {
            color: var(--ink);
        }
        .trial-banner-pill {
            font-size: 10px;
            font-weight: 800;
            padding: 1px 7px;
            border-radius: 10px;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .is-trial .trial-banner-pill {
            background: rgba(132, 204, 22, 0.2);
            color: #84cc16;
            border: 1px solid rgba(132, 204, 22, 0.3);
        }
        .is-free .trial-banner-pill {
            background: rgba(255, 255, 255, 0.08);
            color: var(--muted);
            border: 1px solid var(--line);
        }
        .trial-banner-subtext {
            font-size: 12px;
            color: var(--muted);
            margin-top: 1px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .trial-banner-btn {
            flex-shrink: 0;
            padding: 6px 14px;
            font-size: 12.5px;
            font-weight: 700;
            text-decoration: none;
            border-radius: 7px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
            transition: opacity 0.2s ease, transform 0.15s ease;
        }
        .trial-banner-btn:hover {
            opacity: 0.92;
            transform: translateY(-1px);
        }
        .trial-banner-btn.btn-trial {
            background: var(--lime, #84cc16);
            color: #0b110e;
        }
        .trial-banner-btn.btn-free {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .trial-banner-btn .btn-text-short {
            display: none;
        }
        .trial-banner-btn .btn-text-full {
            display: inline;
        }

        /* Mobile Responsive Optimization for Trial & Free Banner */
        @media (max-width: 640px) {
            .trial-compact-banner {
                padding: 7px 10px !important;
                margin-bottom: 12px !important;
                gap: 8px !important;
                border-radius: 8px !important;
            }
            .trial-banner-subtext {
                display: none !important;
            }
            .trial-banner-icon {
                width: 26px !important;
                height: 26px !important;
                border-radius: 6px !important;
            }
            .trial-banner-icon svg {
                width: 14px !important;
                height: 14px !important;
            }
            .trial-banner-title {
                font-size: 12.5px !important;
            }
            .trial-banner-pill {
                font-size: 9.5px !important;
                padding: 1px 5px !important;
            }
            .trial-banner-btn {
                padding: 5px 9px !important;
                font-size: 11.5px !important;
                border-radius: 6px !important;
            }
            .trial-banner-btn .btn-text-full {
                display: none !important;
            }
            .trial-banner-btn .btn-text-short {
                display: inline !important;
            }
        }
        @media (max-width: 380px) {
            .trial-compact-banner {
                padding: 6px 8px !important;
            }
            .trial-banner-icon {
                display: none !important;
            }
        }
        .sidebar-bottom a {
            padding: 9px 8px !important;
            gap: 8px !important;
        }
        .nav-sub-pill {
            margin-left: auto;
            font-size: 8.5px;
            font-weight: 800;
            line-height: 1.1;
            padding: 2.5px 6.5px;
            border-radius: 9999px;
            letter-spacing: 0.2px;
            text-transform: uppercase;
            white-space: nowrap;
            transition: all 0.2s ease;
            display: inline-block;
            vertical-align: middle;
            text-align: center;
            max-width: 105px;
            overflow: hidden;
            text-overflow: ellipsis;
            flex-shrink: 0;
        }
        .nav-sub-pill-trial {
            background: rgba(132, 204, 22, 0.18);
            color: #84cc16;
            border: 1px solid rgba(132, 204, 22, 0.6);
            box-shadow: 0 0 10px rgba(132, 204, 22, 0.15);
        }
        .nav-sub-pill-free {
            background: rgba(255, 255, 255, 0.08);
            color: var(--muted);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .nav-sub-pill-active {
            background: color-mix(in srgb, var(--teal) 18%, transparent);
            color: var(--teal);
            border: 1px solid color-mix(in srgb, var(--teal) 55%, transparent);
            box-shadow: 0 0 10px color-mix(in srgb, var(--teal) 15%, transparent);
        }
        .nav-sub-pill-expired {
            background: rgba(239, 68, 68, 0.18);
            color: var(--danger, #ef4444);
            border: 1px solid rgba(239, 68, 68, 0.55);
        }
        .sidebar-bottom a:hover .nav-sub-pill,
        .sidebar-bottom a.active .nav-sub-pill {
            background: rgba(0, 0, 0, 0.3) !important;
            color: #080b0d !important;
            border-color: rgba(0, 0, 0, 0.45) !important;
            box-shadow: none !important;
        }
        .app-frame.collapsed .nav-sub-pill {
            display: none !important;
        }
    </style>

    <?php if ($flash): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const isMobile = window.innerWidth <= 768;
                const Toast = Swal.mixin({
                    toast: true,
                    position: isMobile ? 'bottom' : 'top-end',
                    showConfirmButton: false,
                    timer: isMobile ? 3000 : 4500,
                    timerProgressBar: true,
                    background: getComputedStyle(document.documentElement).getPropertyValue('--panel').trim() || '#121721',
                    color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
                    didOpen: (toast) => {
                        toast.onmouseenter = Swal.stopTimer;
                        toast.onmouseleave = Swal.resumeTimer;
                        toast.onclick = () => Swal.close();
                    }
                });
                const flashType = <?= json_encode($flash['type'] === 'danger' ? 'error' : $flash['type']) ?>;
                const checkmarkSvg = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:auto;"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                const crossSvg = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:auto;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
                
                Toast.fire({
                    icon: flashType,
                    iconHtml: flashType === 'success' ? checkmarkSvg : (flashType === 'error' ? crossSvg : undefined),
                    html: <?= json_encode($flash['message']) ?>
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
                <a class="brand" href="index.php" style="display: flex; align-items: center; gap: 10px;">
                    <?php if ($gym && !empty($gym['logo_url'])): ?>
                        <?php
                            $words = preg_split('/\s+/', trim($gym['name'] ?? 'Gym'));
                            $gymInitials = count($words) >= 2 
                                ? mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1))
                                : mb_strtoupper(mb_substr($words[0] ?? 'G', 0, 2));
                        ?>
                        <img src="<?= h(upload_url($gym['logo_url'])) ?>" alt="Logo" loading="lazy" decoding="async" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';" style="height: 32px; max-width: 45px; object-fit: contain; border-radius: 4px; flex-shrink: 0;">
                        <span class="brand-mark" style="display: none;"><?= h($gymInitials) ?></span>
                        <span style="font-weight: 700; font-size: 1rem; line-height: 1.2; letter-spacing: -0.2px; white-space: normal;"><?= h($gym['name']) ?></span>
                    <?php elseif ($gym && !empty($gym['name'])): ?>
                        <?php
                            $words = preg_split('/\s+/', trim($gym['name']));
                            $gymInitials = count($words) >= 2 
                                ? mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1))
                                : mb_strtoupper(mb_substr($words[0], 0, 2));
                        ?>
                        <span class="brand-mark"><?= h($gymInitials) ?></span>
                        <span style="font-weight: 700; font-size: 1rem; line-height: 1.2; letter-spacing: -0.2px; white-space: normal;"><?= h($gym['name']) ?></span>
                    <?php else: ?>
                        <span class="brand-mark">FT</span>
                        <span>FitTrack</span>
                    <?php endif; ?>
                </a>
                <!-- Role badge (replaces non-functional role-switch select) -->
                <div class="role-badge"><?= h(ucfirst($user['role'])) ?></div>
                <nav class="side-nav">
                    <?php foreach ($nav as $key => $label): 
                        $tierBadge = null;
                        if ($role === 'gym_owner' && $gym) {
                            $mappedFeature = match ($key) {
                                'trainer_assignments' => 'trainers',
                                'commissions' => 'commissions',
                                'classes' => 'classes',
                                'audit_logs' => 'audit_logs',
                                'reports' => 'reports',
                                default => null,
                            };
                            if ($mappedFeature !== null && !gym_has_feature($mappedFeature, $gym)) {
                                $tierBadge = in_array($mappedFeature, ['audit_logs'], true) ? 'BIZ' : (in_array($mappedFeature, ['reports'], true) ? 'STARTER' : 'PRO');
                            }
                        }
                        $isActive = ($page === $key 
                            || ($key === 'memberships' && in_array($page, ['memberships', 'payments'], true))
                            || ($key === 'training' && in_array($page, ['training', 'admin_workouts', 'workout_builder'], true))
                            || ($key === 'trainer_assignments' && $page === 'diet_builder' && in_array($role, ['gym_owner', 'platform_admin'], true))
                            || ($key === 'trainer_members' && $page === 'diet_builder' && $role === 'trainer')
                        );
                    ?>
                        <a class="<?= $isActive ? 'active' : '' ?>" href="index.php?page=<?= h($key) ?>">
                            <span class="nav-icon"><?= nav_icon($key) ?></span>
                            <span class="nav-label"><?= h($label) ?></span>
                            <?php if ($tierBadge): ?>
                                <span style="margin-left: auto; font-size: 9px; font-weight: 800; padding: 1px 5px; border-radius: 4px; background: rgba(132, 204, 22, 0.12); color: #84cc16; border: 1px solid rgba(132, 204, 22, 0.25); letter-spacing: 0.5px;"><?= $tierBadge ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="sidebar-bottom">
                    <?php
                        $profileSubPill = null;
                        if ($role === 'gym_owner' && $gym) {
                            $trialInfo = gym_trial_info($gym);
                            $memberLimit = gym_member_limit($gym);
                            $capLabel = ($memberLimit === PHP_INT_MAX) ? '∞ CAP' : ($memberLimit . ' CAP');
                            if ($trialInfo['is_trial_active']) {
                                $profileSubPill = [
                                    'text'  => $trialInfo['days_left'] . 'D LEFT • 50 CAP',
                                    'title' => 'Free Trial (' . $trialInfo['days_left'] . ' days left • 50 Member Cap)',
                                    'type'  => 'trial',
                                ];
                            } elseif ($trialInfo['is_free']) {
                                $profileSubPill = [
                                    'text'  => 'FREE • 25 CAP',
                                    'title' => 'Limited Free Account (25 Member Cap)',
                                    'type'  => 'free',
                                ];
                            } elseif (($gym['subscription_status'] ?? '') === 'active') {
                                $planRaw = strtolower(trim($gym['subscription_plan'] ?? ''));
                                $planName = match (true) {
                                    str_contains($planRaw, 'starter')      => 'Starter',
                                    str_contains($planRaw, 'business')     => 'Biz',
                                    str_contains($planRaw, 'professional') => 'Pro',
                                    default                                => ucfirst($gym['subscription_plan'] ?? 'Pro')
                                };
                                $profileSubPill = [
                                    'text'  => strtoupper($planName) . ' • ' . $capLabel,
                                    'title' => 'Active Subscription: ' . ($gym['subscription_plan'] ?? 'Active'),
                                    'type'  => 'active',
                                ];
                            } elseif (!empty($gym['subscription_status']) && in_array($gym['subscription_status'], ['cancelled', 'expired'], true)) {
                                $profileSubPill = [
                                    'text'  => 'EXPIRED',
                                    'title' => 'Subscription Expired',
                                    'type'  => 'expired',
                                ];
                            }
                        } elseif ($role === 'member' && $user) {
                            $membershipStmt = db()->prepare('SELECT mp.plan_name, m.status FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = ? ORDER BY (m.status = "active") DESC, m.end_date DESC LIMIT 1');
                            $membershipStmt->execute([$user['user_id']]);
                            $membership = $membershipStmt->fetch();
                            if ($membership) {
                                if ($membership['status'] === 'active') {
                                    $profileSubPill = [
                                        'text'  => strtoupper($membership['plan_name']),
                                        'title' => 'Active Membership: ' . $membership['plan_name'],
                                        'type'  => 'active',
                                    ];
                                } elseif (in_array($membership['status'], ['cancelled', 'expired'], true)) {
                                    $profileSubPill = [
                                        'text'  => 'EXPIRED',
                                        'title' => 'Membership Expired',
                                        'type'  => 'expired',
                                    ];
                                }
                            }
                        }
                    ?>
                    <a class="<?= $page === 'profile' ? 'active' : '' ?>" href="index.php?page=profile">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                        <span class="nav-label">Profile</span>
                        <?php if ($profileSubPill): ?>
                            <span class="nav-sub-pill nav-sub-pill-<?= $profileSubPill['type'] ?>" title="<?= h($profileSubPill['title']) ?>">
                                <?= h($profileSubPill['text']) ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <?php if ($role !== 'platform_admin'): ?>
                        <a href="https://mail.google.com/mail/?view=cm&fs=1&to=johncinemartil596@gmail.com" target="_blank"><span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg></span> <span class="nav-label">Contact Support</span></a>
                    <?php endif; ?>
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
                        <?php if ($user && in_array($user['role'] ?? '', ['member', 'trainer'])): ?>
                            <?php $activeCheckinId = scalar('SELECT attendance_id FROM attendance WHERE user_id = ? AND check_out_time IS NULL ORDER BY check_in_time DESC LIMIT 1', [$user['user_id']]); ?>
                            <?php if ($activeCheckinId): ?>
                                <!-- Hidden checkout form — submitted via JS after optional rating -->
                                <form id="checkout-form" method="post" action="" style="display:none;">
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
                            <img src="<?= h(upload_url($user['profile_picture'])) ?>" alt="Avatar" class="avatar" loading="lazy" decoding="async" style="object-fit: cover;">
                        <?php else: ?>
                            <span class="avatar"><?= h(initials($user)) ?></span>
                        <?php endif; ?>
                        <div class="user-details">
                            <strong><?= h($user['first_name'] . ' ' . $user['last_name']) ?></strong>
                            <small><?= h(ucfirst($user['role'])) ?></small>
                        </div>
                    </div>
                </header>
                <main class="shell">
                    <?php if ($user && $user['role'] === 'member' && array_key_exists('email_verified_at', $user) && !$user['email_verified_at']): ?>
                        <div class="panel" style="margin-bottom:16px;padding:12px 16px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;border-left:3px solid var(--lime,#ccff00);">
                            <span>Please verify your email address (<?= h($user['email']) ?>) to secure your account.</span>
                            <form method="post" action="index.php?page=verify_email" style="margin:0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="resend">
                                <button type="submit" class="btn-secondary" style="white-space:nowrap;">Resend verification email</button>
                            </form>
                        </div>
                    <?php endif; ?>
                    <?php if ($user && $user['role'] === 'gym_owner'): 
                        $gymInfo = get_user_gym($user);
                        $trialInfo = gym_trial_info($gymInfo);
                    ?>
                        <?php if ($trialInfo['is_trial_active']): ?>
                            <div class="panel trial-compact-banner is-trial">
                                <div class="trial-banner-left">
                                    <div class="trial-banner-icon">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                    </div>
                                    <div class="trial-banner-content">
                                        <div class="trial-banner-title-row">
                                            <span class="trial-banner-title">Free Trial</span>
                                            <span class="trial-banner-pill">
                                                <?= $trialInfo['days_left'] ?>d left &bull; 50 cap
                                            </span>
                                        </div>
                                        <span class="trial-banner-subtext">Free Trial active (up to 50 members & 2 trainers). Upgrade for unlimited capacity.</span>
                                    </div>
                                </div>
                                <a href="index.php?page=gym_subscription" class="trial-banner-btn btn-trial">
                                    <span class="btn-text-full">View Plans & Upgrade</span>
                                    <span class="btn-text-short">Upgrade</span>
                                    <span class="btn-arrow">&rarr;</span>
                                </a>
                            </div>
                        <?php elseif ($trialInfo['is_free']): ?>
                            <div class="panel trial-compact-banner is-free">
                                <div class="trial-banner-left">
                                    <div class="trial-banner-icon">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                                    </div>
                                    <div class="trial-banner-content">
                                        <div class="trial-banner-title-row">
                                            <span class="trial-banner-title">Free Tier</span>
                                            <span class="trial-banner-pill">25 member cap</span>
                                        </div>
                                        <span class="trial-banner-subtext">Basic check-ins and tracking active. Upgrade for trainers, classes, and unlimited capacity.</span>
                                    </div>
                                </div>
                                <a href="index.php?page=gym_subscription" class="trial-banner-btn btn-free">
                                    <span class="btn-text-full">Upgrade Subscription</span>
                                    <span class="btn-text-short">Upgrade</span>
                                    <span class="btn-arrow">&rarr;</span>
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
    <?php else: ?>
        <main class="shell auth-shell">
    <?php endif;
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
    const saved = localStorage.getItem('fittracks_theme') || 'light';
    applyTheme(saved);
    btn.addEventListener('click', function() {
        const current = document.documentElement.getAttribute('data-theme') || 'light';
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

    $adminEmail = 'johncinemartil596@gmail.com';
    $supportHtml = <<<HTML
    <div class="floating-support-container" id="floatingSupport">
        <div class="floating-support-label">Contact Support</div>
        <a href="https://mail.google.com/mail/?view=cm&fs=1&to={$adminEmail}" target="_blank" class="floating-support-btn" title="Contact Support" aria-label="Contact Support">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                <polyline points="22,6 12,13 2,6"></polyline>
            </svg>
        </a>
    </div>
    <style>
        .floating-support-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            z-index: 9999;
            transition: opacity 0.25s ease, transform 0.25s ease, visibility 0.25s ease;
        }
        .floating-support-label {
            background: var(--panel, rgba(16, 19, 27, 0.9));
            color: var(--ink, #f8fafc);
            font-size: 11.5px;
            font-weight: 600;
            padding: 5px 12px;
            border-radius: 12px;
            border: 1px solid var(--line, rgba(255,255,255,0.08));
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            white-space: nowrap;
            letter-spacing: 0.04em;
            pointer-events: none;
        }
        .floating-support-btn {
            width: 50px;
            height: 50px;
            background: var(--lime, #c7ff22);
            color: var(--bg, #090b10);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 14px rgba(199, 255, 34, 0.4);
            transition: transform 0.2s, box-shadow 0.2s, opacity 0.2s;
        }
        .floating-support-btn:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 6px 20px rgba(199, 255, 34, 0.6);
            color: var(--bg, #090b10);
        }

        /* Auto-hide completely when typing, when any input is focused, or keyboard is open */
        body:has(input:focus) .floating-support-container,
        body:has(textarea:focus) .floating-support-container,
        body:has(select:focus) .floating-support-container,
        body.keyboard-open .floating-support-container {
            opacity: 0 !important;
            pointer-events: none !important;
            visibility: hidden !important;
            transform: translateY(16px) scale(0.8) !important;
        }

        /* Mobile specific unobtrusive layout */
        @media (max-width: 768px) {
            .floating-support-container {
                bottom: 16px;
                right: 16px;
                gap: 0;
            }
            .floating-support-label {
                display: none !important; /* Remove bulky text badge on mobile */
            }
            .floating-support-btn {
                width: 42px;
                height: 42px;
                opacity: 0.88;
                box-shadow: 0 3px 12px rgba(0, 0, 0, 0.35);
            }
            .floating-support-btn svg {
                width: 19px;
                height: 19px;
            }
            .floating-support-btn:active {
                opacity: 1;
                transform: scale(0.95);
            }
        }
    </style>
    <script>
    (function() {
        function updateKeyboardState() {
            var isInputActive = false;
            var active = document.activeElement;
            if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.tagName === 'SELECT')) {
                isInputActive = true;
            }
            var isViewportConstrained = false;
            if (window.visualViewport && window.visualViewport.height < (window.innerHeight * 0.78)) {
                isViewportConstrained = true;
            }
            document.body.classList.toggle('keyboard-open', isInputActive || isViewportConstrained);
        }

        document.addEventListener('focusin', function(e) {
            if (e.target && e.target.matches && e.target.matches('input, textarea, select')) {
                document.body.classList.add('keyboard-open');
            }
        });

        document.addEventListener('focusout', function(e) {
            setTimeout(updateKeyboardState, 80);
        });

        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', updateKeyboardState);
        }
    })();
    </script>
HTML;

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
    echo $supportHtml;
    echo '</body></html>';
}

if (!function_exists('setup_error')) {
    function setup_error(Throwable $e): void
    {
        error_log('Application Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        http_response_code(500);
        $isDev = in_array(strtolower($_ENV['APP_ENV'] ?? 'production'), ['development', 'dev', 'local'], true) 
                 || (isset($_SERVER['REMOTE_ADDR']) && in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'], true));
        ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FITTRACK - System Notification</title>
    <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/../assets/app.css') ?>">
</head>
<body>
<main class="shell">
    <section class="panel" style="max-width: 600px; margin: 40px auto;">
        <h1>Service Temporarily Unavailable</h1>
        <p>The system encountered a configuration or database connectivity issue. Please try again in a few moments.</p>
        <?php if ($isDev): ?>
            <div style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); border-radius: 8px; padding: 12px; margin: 16px 0; font-family: monospace; font-size: 13px; color: #ef4444; word-break: break-all;">
                <strong>Debug Info:</strong> <?= h($e->getMessage()) ?>
            </div>
            <ol style="font-size: 13px; color: var(--muted, #888); padding-left: 20px;">
                <li>Ensure the MySQL database is imported and running.</li>
                <li>Verify database credentials in your <code>.env</code> file.</li>
            </ol>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
        <?php
    }
}
