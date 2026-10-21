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
    $memberAffiliatedGyms = [];
    if ($role === 'member' && !empty($user['user_id'])) {
        $memberAffiliatedGyms = query_all('
            SELECT DISTINCT g.gym_id, g.name,
                   (SELECT mp.plan_name 
                    FROM memberships m 
                    JOIN membership_plans mp ON mp.plan_id = m.plan_id 
                    WHERE m.user_id = ? AND mp.gym_id = g.gym_id AND m.status = "active" AND m.end_date >= CURDATE() 
                    ORDER BY m.end_date DESC LIMIT 1) as plan_name
            FROM gyms g
            WHERE g.gym_id IN (
                SELECT gym_id FROM gym_members WHERE user_id = ?
                UNION
                SELECT mp.gym_id FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = ? AND m.status = "active"
            )
            ORDER BY g.name ASC
        ', [(int)$user['user_id'], (int)$user['user_id'], (int)$user['user_id']]);
    }
    $nav = [];
    if ($user) {
        $nav['dashboard'] = 'Dashboard';
        if ($role === 'platform_admin') {
            $nav += [
                'notifications' => 'Notifications',
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
                'messages' => 'Messages',
                'notifications' => 'Notifications',
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
                'audit_logs' => 'Audit Logs',
            ];
        }
        if ($role === 'staff') {
            $nav += [
                'messages' => 'Messages',
                'notifications' => 'Notifications',
                'scanner' => 'Scan QR',
                'attendance' => 'Attendance',
                'walk_ins' => 'Walk-ins',
                'users' => 'Member Lookup',
                'gym_equipment' => 'Equipment',
            ];
        }
        if ($role === 'trainer') {
            $nav += [
                'messages' => 'Messages',
                'notifications' => 'Notifications',
                'qr_attendance' => 'My QR',
                'equipment' => 'Equipment',
                'trainer_members' => 'Clients',
                'training' => 'Workouts',
                'food_library' => 'Food Library',
                'classes' => 'My Classes',
                'my_commissions' => 'Commissions',
            ];
        }
        if ($role === 'member') {
            $isGymMember = db()->prepare('SELECT 1 FROM gym_members WHERE user_id = ?');
            $isGymMember->execute([$user['user_id']]);
            $hasGym = (bool) $isGymMember->fetchColumn();

            $nav += [
                'messages' => 'Messages',
                'notifications' => 'Notifications',
                'qr_attendance' => 'My QR',
                'my_workout' => 'Workouts',
                'diet' => 'Diet Plan',
            ];
            
            if ($hasGym) {
                $nav += ['trainers' => 'Trainers', 'memberships' => 'Membership', 'book_classes' => 'Classes', 'equipment' => 'Equipment', 'gym_selection' => 'Browse Gyms'];
            } else {
                $nav += ['gym_selection' => 'Select Gym'];
            }
            
            $nav += ['progress' => 'Progress'];
        }
    }
    $page = $_GET['page'] ?? 'dashboard';
    $flash = flash();
    if ($flash && in_array($page, ['setup_profile', 'setup_goal', 'setup_review', 'gym_onboarding', 'gym_pending', 'gym_rejected'], true)) {
        if (str_starts_with((string)($flash['message'] ?? ''), 'Welcome back')) {
            $flash = null;
        }
    }
    if ($user && ($user['role'] ?? '') === 'member') {
        maybe_notify_membership_renewal((int) $user['user_id']);
        maybe_notify_membership_expired((int) $user['user_id']);
    } elseif ($user && ($user['role'] ?? '') === 'gym_owner') {
        if (function_exists('process_gym_owner_renewal_reminder_emails')) {
            process_gym_owner_renewal_reminder_emails();
            process_gym_owner_plan_expired_emails();
        }
    }
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
        <title><?= h($title) ?> - <?= h($gym['name'] ?? 'FitTrack') ?></title>
        <link rel="manifest" href="manifest.json">
        <meta name="theme-color" content="#22c55e">
        <link rel="icon" type="image/svg+xml" href="assets/images/fittracks-icon.svg">
        <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/../assets/app.css') ?>">
        <link rel="stylesheet" href="assets/dropdown.css?v=<?= file_exists(__DIR__ . '/../assets/dropdown.css') ? filemtime(__DIR__ . '/../assets/dropdown.css') : 1 ?>">
        <?php if ($gym && !empty($gym['brand_color']) && gym_has_feature('custom_branding', $gym)): 
            $brandHex = ltrim((string)$gym['brand_color'], '#');
            if (strlen($brandHex) === 3) {
                $brandHex = $brandHex[0].$brandHex[0].$brandHex[1].$brandHex[1].$brandHex[2].$brandHex[2];
            }
            $r = hexdec(substr($brandHex, 0, 2));
            $g = hexdec(substr($brandHex, 2, 2));
            $b = hexdec(substr($brandHex, 4, 2));
            $yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
            $contrastColor = ($yiq >= 140) ? '#080b0d' : '#ffffff';
        ?>
            <style>
                :root, [data-theme="light"], [data-theme="dark"] {
                    --lime: <?= h($gym['brand_color']) ?> !important;
                    --lime-dark: color-mix(in srgb, <?= h($gym['brand_color']) ?> 80%, black) !important;
                    --lime-contrast: <?= $contrastColor ?> !important;
                }
            </style>
        <?php else: ?>
            <style>
                :root, [data-theme="light"], [data-theme="dark"] {
                    --lime-contrast: #080b0d;
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

            // Register Service Worker for PWA & Offline Support
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => {
                    navigator.serviceWorker.register('sw.js').catch(err => {
                        console.warn('[PWA] Service Worker registration failed:', err);
                    });
                });
            }
        </script>
        <script src="assets/dropdown.js?v=<?= file_exists(__DIR__ . '/../assets/dropdown.js') ? filemtime(__DIR__ . '/../assets/dropdown.js') : 1 ?>"></script>
        <script src="assets/sweetalert2.all.min.js"></script>
        <script>
            if (typeof Swal === 'undefined') {
                document.write('<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"><\/script>');
            }
        </script>
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

                const origFire = window.Swal.fire;
                window.Swal.fire = function(...args) {
                    if (args.length === 1 && typeof args[0] === 'object') {
                        args[0] = injectToastIcon(args[0]);
                    } else if (args.length >= 3 && typeof args[2] === 'string') {
                        const icon = args[2];
                        const svg = icon === 'success' ? checkmarkSvg : (icon === 'error' ? crossSvg : (icon === 'warning' ? warnSvg : (icon === 'info' ? infoSvg : null)));
                        if (svg) {
                            return origFire.call(this, { title: args[0], html: args[1], icon: icon, iconHtml: svg });
                        }
                    }
                    return origFire.apply(this, args);
                };

                const origMixin = window.Swal.mixin;
                window.Swal.mixin = function(mixinOpts) {
                    const subClass = origMixin.call(this, mixinOpts);
                    const origSubFire = subClass.fire;
                    subClass.fire = function(...args) {
                        if (args.length === 1 && typeof args[0] === 'object') {
                            args[0] = injectToastIcon(args[0]);
                        }
                        return origSubFire.apply(this, args);
                    };
                    return subClass;
                };
            })();
        </script>
        <link rel="stylesheet" href="assets/splash.css?v=<?= file_exists(__DIR__ . '/../assets/splash.css') ? filemtime(__DIR__ . '/../assets/splash.css') : 1 ?>">
        <script src="assets/splash.js?v=<?= file_exists(__DIR__ . '/../assets/splash.js') ? filemtime(__DIR__ . '/../assets/splash.js') : 1 ?>"></script>
        <script src="assets/audio.js?v=<?= file_exists(__DIR__ . '/../assets/audio.js') ? filemtime(__DIR__ . '/../assets/audio.js') : 1 ?>"></script>
        <?php if ($user): ?>
            <link rel="stylesheet" href="assets/gym-owner-tour.css?v=<?= file_exists(__DIR__ . '/../assets/gym-owner-tour.css') ? filemtime(__DIR__ . '/../assets/gym-owner-tour.css') : 1 ?>">
            <script src="assets/gym-owner-tour.js?v=<?= file_exists(__DIR__ . '/../assets/gym-owner-tour.js') ? filemtime(__DIR__ . '/../assets/gym-owner-tour.js') : 1 ?>" defer></script>
        <?php endif; ?>
    </head>
    <body class="<?= $user ? 'app-body' : 'auth-body' ?>" data-user-role="<?= h($user['role'] ?? '') ?>">
    <!-- In-App FitTrack Splash Preloader -->
    <div id="ft-splash-screen" aria-hidden="true">
        <div class="ft-splash-glow"></div>
        <div class="ft-splash-content">
            <div class="ft-splash-logo-wrap">
                <div class="ft-splash-ring"></div>
                <div class="ft-splash-logo">FT</div>
            </div>
            <div class="ft-splash-brand-title">Fit<span>Track</span></div>
            <div class="ft-splash-bar-track">
                <div class="ft-splash-bar-fill"></div>
            </div>
        </div>
    </div>
    
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
            font-size: 1.2rem !important;
            font-weight: 700 !important;
            line-height: 1.35 !important;
            padding: 0 1rem !important;
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

        /* Mobile-optimized SweetAlert Modal Popups */
        @media (max-width: 640px) {
            .swal2-container {
                padding: 12px !important;
            }
            .swal2-popup:not(.swal2-toast) {
                width: 90% !important;
                max-width: 330px !important;
                padding: 1.25rem 1rem 1rem !important;
                border-radius: 16px !important;
                box-sizing: border-box !important;
            }
            .swal2-popup:not(.swal2-toast) .swal2-icon {
                width: 3.25rem !important;
                height: 3.25rem !important;
                margin: 0.5rem auto 0.5rem !important;
                border-width: 3px !important;
            }
            .swal2-popup:not(.swal2-toast) .swal2-icon .swal2-icon-content {
                font-size: 1.85rem !important;
            }
            .swal2-popup:not(.swal2-toast) .swal2-title {
                font-size: 1.2rem !important;
                padding: 0 0.5rem !important;
                margin: 0 0 0.4rem !important;
                line-height: 1.3 !important;
                word-break: break-word !important;
            }
            .swal2-popup:not(.swal2-toast) .swal2-html-container {
                font-size: 0.88rem !important;
                padding: 0 0.5rem !important;
                margin: 0.25rem 0 0.75rem !important;
                line-height: 1.45 !important;
                word-break: break-word !important;
            }
            .swal2-popup:not(.swal2-toast) .swal2-actions {
                display: flex !important;
                flex-direction: row !important;
                width: 100% !important;
                margin: 0.75rem 0 0 0 !important;
                gap: 8px !important;
                box-sizing: border-box !important;
            }
            .swal2-popup:not(.swal2-toast) .swal2-actions button {
                flex: 1 1 0 !important;
                font-size: 0.88rem !important;
                padding: 8px 10px !important;
                min-height: 38px !important;
                margin: 0 !important;
                box-sizing: border-box !important;
                white-space: nowrap !important;
            }
        }

        /* Star Rating in SweetAlert Modals */
        .co-star {
            font-size: 2.25rem !important;
            cursor: pointer;
            color: #475569;
            transition: color 0.15s ease, transform 0.15s ease, text-shadow 0.15s ease;
            line-height: 1;
            user-select: none;
            display: inline-block;
            padding: 0 3px;
        }
        .co-star:hover {
            transform: scale(1.18);
        }
        [data-theme="light"] .co-star {
            color: #cbd5e1;
        }
        [data-theme="light"] .co-star:hover {
            color: #f59e0b;
        }

        /* Workout Checkout Rating Modal Optimizations */
        .swal2-popup.swal-checkout-modal {
            max-width: 420px !important;
            width: calc(100vw - 32px) !important;
            padding: 22px 20px 18px !important;
            border-radius: 16px !important;
            box-sizing: border-box !important;
            background: #111827 !important;
            border: 1px solid rgba(255, 255, 255, 0.12) !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6) !important;
        }
        [data-theme="light"] .swal2-popup.swal-checkout-modal {
            background: #ffffff !important;
            border-color: #cbd5e1 !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15) !important;
        }
        .swal-checkout-modal .swal2-title {
            font-size: 1.35rem !important;
            font-weight: 700 !important;
            line-height: 1.3 !important;
            margin: 0 0 8px 0 !important;
            padding: 0 !important;
            color: #f8fafc !important;
            text-align: center !important;
        }
        [data-theme="light"] .swal-checkout-modal .swal2-title {
            color: #0f172a !important;
        }
        .swal-checkout-modal .swal2-html-container {
            margin: 0 !important;
            padding: 0 !important;
        }
        .swal-checkout-modal .checkout-modal-desc {
            font-size: 12.5px !important;
            line-height: 1.45 !important;
            color: #94a3b8 !important;
            margin: 0 0 10px 0 !important;
            text-align: center !important;
        }
        [data-theme="light"] .swal-checkout-modal .checkout-modal-desc {
            color: #64748b !important;
        }
        .swal-checkout-modal #swal-star-row {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin: 10px 0 14px !important;
        }
        .swal-checkout-modal .co-star {
            font-size: 2.1rem !important;
            padding: 0 2px !important;
            touch-action: manipulation;
        }
        .swal-checkout-modal #swal-comment {
            width: 100% !important;
            min-height: 68px !important;
            padding: 9px 12px !important;
            border-radius: 8px !important;
            border: 1px solid rgba(255, 255, 255, 0.12) !important;
            background: rgba(255, 255, 255, 0.04) !important;
            color: #f8fafc !important;
            font-size: 13px !important;
            line-height: 1.4 !important;
            box-sizing: border-box !important;
            resize: none !important;
        }
        [data-theme="light"] .swal-checkout-modal #swal-comment {
            background: #f8fafc !important;
            border-color: #cbd5e1 !important;
            color: #0f172a !important;
        }
        .swal-checkout-modal .swal2-actions {
            display: grid !important;
            grid-template-columns: 1fr 1fr !important;
            width: 100% !important;
            gap: 10px !important;
            margin-top: 14px !important;
            box-sizing: border-box !important;
        }
        .swal-checkout-modal .swal2-deny {
            display: none !important;
        }
        .swal-checkout-modal .swal2-confirm,
        .swal-checkout-modal .swal2-cancel {
            width: 100% !important;
            margin: 0 !important;
            height: 40px !important;
            min-height: 40px !important;
            max-height: 40px !important;
            padding: 0 8px !important;
            font-size: 11.5px !important;
            font-weight: 600 !important;
            border-radius: 8px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            text-align: center !important;
            white-space: nowrap !important;
            box-sizing: border-box !important;
        }
        .swal-checkout-modal .swal-checkout-confirm-btn {
            background-color: #ef4444 !important;
            color: #ffffff !important;
            border: none !important;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3) !important;
            transition: all 0.15s ease !important;
        }
        .swal-checkout-modal .swal-checkout-confirm-btn:hover {
            background-color: #dc2626 !important;
            transform: translateY(-1px) !important;
        }
        .swal-checkout-modal .swal-skip-btn {
            background-color: rgba(255, 255, 255, 0.07) !important;
            color: #cbd5e1 !important;
            border: 1px solid rgba(255, 255, 255, 0.15) !important;
            transition: all 0.15s ease !important;
        }
        .swal-checkout-modal .swal-skip-btn:hover {
            background-color: rgba(255, 255, 255, 0.12) !important;
            color: #ffffff !important;
        }
        [data-theme="light"] .swal-checkout-modal .swal-skip-btn {
            background-color: #f1f5f9 !important;
            color: #475569 !important;
            border-color: #cbd5e1 !important;
        }
        [data-theme="light"] .swal-checkout-modal .swal-skip-btn:hover {
            background-color: #e2e8f0 !important;
            color: #0f172a !important;
        }

        @media (max-width: 480px) {
            .swal2-popup.swal-checkout-modal {
                width: calc(100vw - 24px) !important;
                max-width: 360px !important;
                padding: 16px 14px 12px !important;
                border-radius: 14px !important;
            }
            .swal-checkout-modal .swal2-title {
                font-size: 1.15rem !important;
                margin-bottom: 5px !important;
            }
            .swal-checkout-modal .checkout-modal-desc {
                font-size: 11.5px !important;
                line-height: 1.35 !important;
                margin-bottom: 6px !important;
            }
            .swal-checkout-modal #swal-star-row {
                gap: 7px !important;
                margin: 6px 0 10px !important;
            }
            .swal-checkout-modal .co-star {
                font-size: 1.7rem !important;
                padding: 0 1px !important;
            }
            .swal-checkout-modal #swal-comment {
                min-height: 56px !important;
                font-size: 12px !important;
                padding: 7px 9px !important;
            }
            .swal-checkout-modal .swal2-actions {
                display: grid !important;
                grid-template-columns: 1fr 1fr !important;
                gap: 8px !important;
                margin-top: 10px !important;
            }
            .swal-checkout-modal .swal2-actions .swal2-deny {
                display: none !important;
            }
            .swal-checkout-modal .swal2-confirm,
            .swal-checkout-modal .swal2-cancel {
                height: 38px !important;
                min-height: 38px !important;
                max-height: 38px !important;
                padding: 0 4px !important;
                font-size: 10.5px !important;
                white-space: nowrap !important;
                letter-spacing: -0.2px !important;
            }
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

        /* Responsive Topbar Cleanup for Mobile (<= 768px): Hide Topbar Switcher & Tour Button */
        @media (max-width: 768px) {
            .topbar #topbarFacilityDropdownWrap,
            .topbar .tour-quick-btn {
                display: none !important;
            }
            .shell {
                padding-bottom: 84px !important;
            }
        }

        /* ==========================================================================
           Mobile Floating Action Pill / FAB (Facility Switcher & Quick Tour)
           ========================================================================== */
        .mobile-facility-fab {
            display: none;
        }

        @media (max-width: 768px) {
            .mobile-facility-fab {
                display: block;
                position: fixed;
                bottom: max(20px, env(safe-area-inset-bottom, 20px));
                left: max(16px, env(safe-area-inset-left, 16px));
                z-index: 250;
                font-family: inherit;
            }

            .mobile-facility-fab-trigger {
                position: relative;
                display: flex;
                align-items: center;
                justify-content: center;
                width: 44px;
                height: 44px;
                padding: 0;
                border-radius: 50%;
                background: rgba(18, 24, 38, 0.94);
                backdrop-filter: blur(20px) saturate(180%);
                -webkit-backdrop-filter: blur(20px) saturate(180%);
                border: 1px solid rgba(255, 255, 255, 0.14);
                box-shadow: 0 10px 28px rgba(0, 0, 0, 0.48), 0 2px 8px rgba(0, 0, 0, 0.25);
                color: var(--lime, #84cc16);
                cursor: pointer;
                outline: none;
                user-select: none;
                touch-action: manipulation;
                transition: transform 0.18s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.18s ease, border-color 0.18s ease;
            }

            .mobile-facility-fab-trigger:active {
                transform: scale(0.92);
                box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35);
            }

            .mobile-facility-fab-icon {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 22px;
                height: 22px;
                color: var(--lime, #84cc16);
                flex-shrink: 0;
            }

            .mobile-facility-fab-badge {
                position: absolute;
                top: -3px;
                right: -3px;
                min-width: 18px;
                height: 18px;
                padding: 0 4px;
                border-radius: 9px;
                background: var(--lime, #84cc16);
                color: #080b0d;
                font-size: 10px;
                font-weight: 800;
                line-height: 18px;
                text-align: center;
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.4);
                border: 2px solid #0d121c;
                box-sizing: border-box;
                display: flex;
                align-items: center;
                justify-content: center;
                letter-spacing: -0.2px;
                flex-shrink: 0;
            }

            /* Backdrop */
            .mobile-facility-fab-backdrop {
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.65);
                backdrop-filter: blur(4px);
                -webkit-backdrop-filter: blur(4px);
                z-index: 991;
                opacity: 0;
                pointer-events: none;
                transition: opacity 0.22s cubic-bezier(0.16, 1, 0.3, 1);
            }

            .mobile-facility-fab-backdrop.open {
                opacity: 1;
                pointer-events: auto;
            }

            /* Bottom-sheet Popup */
            .mobile-facility-fab-sheet {
                position: fixed;
                bottom: max(72px, calc(env(safe-area-inset-bottom, 20px) + 52px));
                left: max(16px, env(safe-area-inset-left, 16px));
                width: min(340px, calc(100vw - 32px));
                max-height: calc(100vh - 120px);
                background: rgba(18, 24, 38, 0.98);
                backdrop-filter: blur(28px);
                -webkit-backdrop-filter: blur(28px);
                border: 1px solid rgba(255, 255, 255, 0.12);
                border-radius: 18px;
                box-shadow: 0 24px 64px rgba(0, 0, 0, 0.65), 0 4px 16px rgba(0, 0, 0, 0.35);
                z-index: 992;
                display: flex;
                flex-direction: column;
                overflow: hidden;
                opacity: 0;
                transform: translateY(12px) scale(0.96);
                pointer-events: none;
                transition: opacity 0.22s cubic-bezier(0.16, 1, 0.3, 1), transform 0.22s cubic-bezier(0.16, 1, 0.3, 1);
            }

            .mobile-facility-fab-sheet.open {
                opacity: 1;
                transform: translateY(0) scale(1);
                pointer-events: auto;
            }

            .mobile-fab-sheet-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 14px 16px 12px;
                border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            }

            .mobile-fab-sheet-header-text {
                display: flex;
                flex-direction: column;
            }

            .mobile-fab-sheet-title {
                font-size: 14px;
                font-weight: 700;
                color: #f8fafc;
                letter-spacing: -0.2px;
            }

            .mobile-fab-sheet-sub {
                font-size: 11px;
                color: var(--muted, #94a3b8);
                margin-top: 2px;
            }

            .mobile-fab-sheet-close {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 32px;
                height: 32px;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.08);
                border: 1px solid rgba(255, 255, 255, 0.12);
                color: #f8fafc;
                cursor: pointer;
                transition: all 0.15s ease;
                padding: 0;
                flex-shrink: 0;
            }

            .mobile-fab-sheet-close svg {
                width: 15px;
                height: 15px;
                display: block;
                stroke: currentColor;
            }

            .mobile-fab-sheet-close:hover,
            .mobile-fab-sheet-close:active {
                background: rgba(255, 255, 255, 0.18);
                border-color: rgba(255, 255, 255, 0.25);
                color: #ffffff;
            }

            .mobile-fab-sheet-body {
                padding: 12px 14px 14px;
                overflow-y: auto;
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            .mobile-fab-section-label {
                font-size: 10px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.6px;
                color: var(--muted, #94a3b8);
                padding: 0 4px 2px;
            }

            .mobile-fab-gym-list {
                display: flex;
                flex-direction: column;
                gap: 6px;
            }

            .mobile-fab-gym-item {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 10px 12px;
                border-radius: 12px;
                background: rgba(255, 255, 255, 0.03);
                border: 1px solid rgba(255, 255, 255, 0.08);
                text-decoration: none;
                color: inherit;
                transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
                cursor: pointer;
            }

            .mobile-fab-gym-item:hover,
            .mobile-fab-gym-item:active {
                background: rgba(255, 255, 255, 0.08);
                border-color: rgba(255, 255, 255, 0.18);
                transform: translateY(-1px);
            }

            .mobile-fab-gym-item.is-active {
                background: color-mix(in srgb, var(--lime, #84cc16) 12%, rgba(255, 255, 255, 0.03));
                border-color: color-mix(in srgb, var(--lime, #84cc16) 45%, transparent);
                box-shadow: 0 2px 8px color-mix(in srgb, var(--lime, #84cc16) 15%, transparent);
            }

            .mobile-fab-gym-icon {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 34px;
                height: 34px;
                border-radius: 8px;
                background: rgba(255, 255, 255, 0.06);
                color: var(--muted, #94a3b8);
                flex-shrink: 0;
            }

            .mobile-fab-gym-item.is-active .mobile-fab-gym-icon {
                background: color-mix(in srgb, var(--lime, #84cc16) 20%, transparent);
                color: var(--lime, #84cc16);
            }

            .mobile-fab-gym-details {
                flex: 1 1 auto;
                min-width: 0;
            }

            .mobile-fab-gym-name {
                font-size: 13.5px;
                font-weight: 700;
                color: #f8fafc;
                display: flex;
                align-items: center;
                gap: 6px;
            }

            .mobile-fab-active-pill {
                font-size: 9px;
                font-weight: 800;
                text-transform: uppercase;
                padding: 1px 6px;
                border-radius: 6px;
                background: var(--lime, #84cc16);
                color: #080b0d;
                letter-spacing: 0.4px;
            }

            .mobile-fab-gym-plan {
                font-size: 11px;
                color: var(--muted, #94a3b8);
                margin-top: 1px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .mobile-fab-gym-action {
                flex-shrink: 0;
                display: flex;
                align-items: center;
            }

            .mobile-fab-check-icon {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 22px;
                height: 22px;
                border-radius: 50%;
                background: color-mix(in srgb, var(--lime, #84cc16) 20%, transparent);
                color: var(--lime, #84cc16);
            }

            .mobile-fab-switch-arrow {
                color: var(--muted, #94a3b8);
                opacity: 0.6;
            }

            .mobile-fab-tour-section {
                margin-top: 4px;
                padding-top: 10px;
                border-top: 1px solid rgba(255, 255, 255, 0.08);
            }

            .mobile-fab-tour-trigger-btn {
                width: 100%;
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 10px 12px;
                border-radius: 12px;
                background: rgba(255, 255, 255, 0.04);
                border: 1px dashed rgba(255, 255, 255, 0.16);
                color: #f8fafc;
                cursor: pointer;
                text-align: left;
                transition: all 0.18s ease;
            }

            .mobile-fab-tour-trigger-btn:hover,
            .mobile-fab-tour-trigger-btn:active {
                background: rgba(255, 255, 255, 0.08);
                border-color: var(--lime, #84cc16);
            }

            .mobile-fab-tour-icon {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 32px;
                height: 32px;
                border-radius: 8px;
                background: color-mix(in srgb, var(--lime, #84cc16) 18%, transparent);
                color: var(--lime, #84cc16);
                flex-shrink: 0;
            }

            .mobile-fab-tour-text {
                flex: 1 1 auto;
                min-width: 0;
                display: flex;
                flex-direction: column;
            }

            .mobile-fab-tour-text strong {
                font-size: 12.5px;
                font-weight: 700;
                color: #f8fafc;
            }

            .mobile-fab-tour-text small {
                font-size: 10.5px;
                color: var(--muted, #94a3b8);
                line-height: 1.2;
            }

            .mobile-fab-tour-arrow {
                color: var(--muted, #94a3b8);
                flex-shrink: 0;
            }

            /* Light Theme Overrides */
            [data-theme="light"] .mobile-facility-fab-trigger {
                background: rgba(255, 255, 255, 0.95);
                border-color: #cbd5e1;
                box-shadow: 0 10px 28px rgba(0, 0, 0, 0.12), 0 2px 8px rgba(0, 0, 0, 0.06);
                color: var(--lime-dark, #4d7c0f);
            }

            [data-theme="light"] .mobile-facility-fab-badge {
                border-color: #ffffff;
            }

            [data-theme="light"] .mobile-facility-fab-sheet {
                background: rgba(255, 255, 255, 0.98);
                border-color: #cbd5e1;
                box-shadow: 0 20px 48px rgba(0, 0, 0, 0.15), 0 4px 12px rgba(0, 0, 0, 0.08);
            }

            [data-theme="light"] .mobile-fab-sheet-header {
                border-bottom-color: #e2e8f0;
            }

            [data-theme="light"] .mobile-fab-sheet-title {
                color: #0f172a;
            }

            [data-theme="light"] .mobile-fab-sheet-close {
                background: #f1f5f9;
                color: #64748b;
            }

            [data-theme="light"] .mobile-fab-sheet-close:hover {
                background: #e2e8f0;
                color: #0f172a;
            }

            [data-theme="light"] .mobile-fab-gym-item {
                background: #f8fafc;
                border-color: #e2e8f0;
                color: #0f172a;
            }

            [data-theme="light"] .mobile-fab-gym-item:hover,
            [data-theme="light"] .mobile-fab-gym-item:active {
                background: #f1f5f9;
                border-color: #cbd5e1;
            }

            [data-theme="light"] .mobile-fab-gym-item.is-active {
                background: color-mix(in srgb, var(--lime, #84cc16) 10%, #ffffff);
                border-color: var(--lime, #84cc16);
            }

            [data-theme="light"] .mobile-fab-gym-name {
                color: #0f172a;
            }

            [data-theme="light"] .mobile-fab-tour-section {
                border-top-color: #e2e8f0;
            }

            [data-theme="light"] .mobile-fab-tour-trigger-btn {
                background: #f8fafc;
                border-color: #cbd5e1;
                color: #0f172a;
            }

            [data-theme="light"] .mobile-fab-tour-text strong {
                color: #0f172a;
            }
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
        [data-theme="light"] .nav-sub-pill-free {
            background: rgba(0, 0, 0, 0.05);
            color: var(--muted);
            border: 1px solid var(--line);
        }
        .nav-sub-pill-active {
            background: color-mix(in srgb, var(--teal) 18%, transparent);
            color: var(--teal);
            border: 1px solid color-mix(in srgb, var(--teal) 55%, transparent);
            box-shadow: 0 0 10px color-mix(in srgb, var(--teal) 15%, transparent);
        }
        .nav-sub-pill-pending {
            background: rgba(245, 158, 11, 0.18);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.6);
            box-shadow: 0 0 10px rgba(245, 158, 11, 0.15);
        }
        .nav-sub-pill-cancelled {
            background: rgba(148, 163, 184, 0.15);
            color: var(--muted, #94a3b8);
            border: 1px solid rgba(148, 163, 184, 0.35);
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
        .app-frame.collapsed .nav-sub-pill,
        .app-frame.collapsed .sidebar-tier-badge,
        .app-frame.collapsed .sidebar-nav-badge,
        .app-frame.collapsed .sidebar-notif-badge {
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
                <?php 
                    $unreadNotifsCount = $user ? unread_notification_count((int) $user['user_id']) : 0;
                    $unreadMsgsCount   = $user ? (int) scalar('SELECT COUNT(*) FROM trainer_messages WHERE recipient_id = ? AND is_read = 0', [(int)$user['user_id']]) : 0;

                    $sidebarBadges = [];
                    if ($user) {
                        $uid = (int) $user['user_id'];
                        if ($role === 'member') {
                            $activeGymId = (int)($gym['gym_id'] ?? 0);
                            $assignmentStatus = scalar('
                                SELECT ca.status 
                                FROM trainer_assignments ca 
                                JOIN trainer_profiles tp ON tp.trainer_id = ca.trainer_id 
                                WHERE ca.member_user_id = ? ' . ($activeGymId > 0 ? ' AND tp.gym_id = ?' : '') . ' 
                                  AND ca.status IN ("active", "pending_trainer", "pending_admin") 
                                ORDER BY CASE WHEN ca.status = "active" THEN 1 ELSE 2 END 
                                LIMIT 1', 
                                $activeGymId > 0 ? [$uid, $activeGymId] : [$uid]
                            );
                            if ($assignmentStatus === 'active') {
                                $sidebarBadges['trainers'] = [
                                    'text' => 'Active',
                                    'class' => 'sidebar-nav-badge sidebar-badge-active',
                                    'title' => 'Assigned Personal Trainer Active'
                                ];
                            } elseif ($assignmentStatus === 'pending_trainer' || $assignmentStatus === 'pending_admin') {
                                $sidebarBadges['trainers'] = [
                                    'text' => 'Pending',
                                    'class' => 'sidebar-nav-badge sidebar-badge-pending',
                                    'title' => 'Trainer Request Pending'
                                ];
                            }

                            // Smart Badge for Member Diet Plan:
                            // "New" when newly published/unopened, transitioning to "Active" once viewed.
                            $hasActiveDiet = (bool) scalar('SELECT 1 FROM dietary_plans WHERE member_user_id = ? AND status = "active" LIMIT 1', [$uid]);
                            if ($hasActiveDiet) {
                                $hasUnreadDietNotif = ($page !== 'diet') && (bool) scalar('SELECT 1 FROM notifications WHERE user_id = ? AND is_read = 0 AND (title LIKE "%Diet Plan%" OR message LIKE "%dietary plan%") LIMIT 1', [$uid]);
                                if ($hasUnreadDietNotif) {
                                    $sidebarBadges['diet'] = [
                                        'text' => 'New',
                                        'class' => 'sidebar-nav-badge sidebar-badge-new',
                                        'title' => 'New Diet Plan published for you!'
                                    ];
                                } else {
                                    $sidebarBadges['diet'] = [
                                        'text' => 'Active',
                                        'class' => 'sidebar-nav-badge sidebar-badge-active',
                                        'title' => 'Personalized Diet Plan Active'
                                    ];
                                }
                            }

                            // Smart Badge for Member Workout Plan:
                            // "New" when newly published/unopened, transitioning to "Active" once viewed.
                            $hasActiveWorkout = (bool) scalar('SELECT 1 FROM training_plans WHERE member_user_id = ? AND status = "active" LIMIT 1', [$uid]);
                            if ($hasActiveWorkout) {
                                $hasUnreadWorkoutNotif = ($page !== 'my_workout') && (bool) scalar('SELECT 1 FROM notifications WHERE user_id = ? AND is_read = 0 AND (title LIKE "%Workout Plan%" OR message LIKE "%training routine%") LIMIT 1', [$uid]);
                                if ($hasUnreadWorkoutNotif) {
                                    $sidebarBadges['my_workout'] = [
                                        'text' => 'New',
                                        'class' => 'sidebar-nav-badge sidebar-badge-new',
                                        'title' => 'New Workout Plan published for you!'
                                    ];
                                } else {
                                    $sidebarBadges['my_workout'] = [
                                        'text' => 'Active',
                                        'class' => 'sidebar-nav-badge sidebar-badge-active',
                                        'title' => 'Personalized Workout Plan Active'
                                    ];
                                }
                            }
                        } elseif ($role === 'trainer') {
                            $coachId = ensure_coach_profile($uid);
                            if ($coachId) {
                                $pendingClients = (int) scalar('SELECT COUNT(*) FROM trainer_assignments WHERE trainer_id = ? AND status = "pending_trainer"', [$coachId]);
                                if ($pendingClients > 0) {
                                    $sidebarBadges['trainer_members'] = [
                                        'text' => $pendingClients > 99 ? '99+' : (string) $pendingClients,
                                        'class' => 'sidebar-nav-badge sidebar-badge-alert',
                                        'title' => $pendingClients . ' pending client request' . ($pendingClients > 1 ? 's' : '')
                                    ];
                                } else {
                                    $activeClients = (int) scalar('SELECT COUNT(*) FROM trainer_assignments WHERE trainer_id = ? AND status = "active"', [$coachId]);
                                    if ($activeClients > 0) {
                                        $sidebarBadges['trainer_members'] = [
                                            'text' => $activeClients > 99 ? '99+' : (string) $activeClients,
                                            'class' => 'sidebar-nav-badge',
                                            'title' => $activeClients . ' active client' . ($activeClients > 1 ? 's' : '')
                                        ];
                                    }
                                }
                            }
                        } elseif ($role === 'gym_owner' && $gym) {
                            $gymId = (int)$gym['gym_id'];

                            $pendingGymTrainers = (int) scalar('SELECT COUNT(*) FROM trainer_assignments ta JOIN trainer_profiles tp ON tp.trainer_id = ta.trainer_id WHERE tp.gym_id = ? AND ta.status = "pending_admin"', [$gymId]);
                            if ($pendingGymTrainers > 0) {
                                $sidebarBadges['trainer_assignments'] = [
                                    'text' => (string) $pendingGymTrainers,
                                    'class' => 'sidebar-nav-badge sidebar-badge-alert',
                                    'title' => $pendingGymTrainers . ' pending trainer assignment requests'
                                ];
                            }

                            // Memberships number badge for Gym Owner
                            $pendingGymMemberships = (int) scalar('
                                SELECT COUNT(DISTINCT m.membership_id) 
                                FROM memberships m 
                                JOIN membership_plans mp ON mp.plan_id = m.plan_id 
                                LEFT JOIN payments p ON p.membership_id = m.membership_id 
                                WHERE mp.gym_id = ? AND (m.status = "pending" OR p.status = "pending")
                            ', [$gymId]);

                            $activeGymMemberships = (int) scalar('
                                SELECT COUNT(*) 
                                FROM memberships m 
                                JOIN membership_plans mp ON mp.plan_id = m.plan_id 
                                WHERE mp.gym_id = ? AND m.status = "active" AND m.end_date >= CURDATE()
                            ', [$gymId]);

                            if ($pendingGymMemberships > 0) {
                                $sidebarBadges['memberships'] = [
                                    'text' => $pendingGymMemberships > 99 ? '99+' : (string) $pendingGymMemberships,
                                    'class' => 'sidebar-nav-badge sidebar-badge-alert',
                                    'title' => $pendingGymMemberships . ' pending membership' . ($pendingGymMemberships > 1 ? 's' : '') . ($activeGymMemberships > 0 ? ' (' . $activeGymMemberships . ' active)' : '')
                                ];
                            } elseif ($activeGymMemberships > 0) {
                                $sidebarBadges['memberships'] = [
                                    'text' => $activeGymMemberships > 99 ? '99+' : (string) $activeGymMemberships,
                                    'class' => 'sidebar-nav-badge',
                                    'title' => $activeGymMemberships . ' active membership' . ($activeGymMemberships > 1 ? 's' : '')
                                ];
                            }
                        } elseif ($role === 'platform_admin') {
                            $pendingGyms = (int) scalar('SELECT COUNT(*) FROM gyms WHERE status = "pending"');
                            if ($pendingGyms > 0) {
                                $sidebarBadges['gym_applications'] = [
                                    'text' => (string) $pendingGyms,
                                    'class' => 'sidebar-nav-badge sidebar-badge-alert',
                                    'title' => $pendingGyms . ' pending gym applications'
                                ];
                            }
                            try {
                                $pendingFoods = (int) scalar("SELECT COUNT(*) FROM food_items WHERE verification_status = 'unverified' AND source = 'custom'");
                                if ($pendingFoods > 0) {
                                    $sidebarBadges['food_library'] = [
                                        'text' => (string) $pendingFoods,
                                        'class' => 'sidebar-nav-badge sidebar-badge-alert',
                                        'title' => $pendingFoods . ' unverified custom foods awaiting review'
                                    ];
                                }
                            } catch (Throwable) {}
                        }
                    }
                ?>
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
                                'food_library' => 'food_library',
                                default => null,
                            };
                            if ($mappedFeature !== null && !gym_has_feature($mappedFeature, $gym)) {
                                $tierBadge = in_array($mappedFeature, ['audit_logs'], true) ? 'PRO' : (in_array($mappedFeature, ['reports', 'food_library'], true) ? 'STARTER' : 'PRO');
                            }
                        }
                        $isActive = ($page === $key 
                            || ($key === 'memberships' && in_array($page, ['memberships', 'payments'], true))
                            || ($key === 'training' && in_array($page, ['training', 'admin_workouts', 'workout_builder'], true))
                            || ($key === 'trainer_assignments' && $page === 'diet_builder' && in_array($role, ['gym_owner', 'platform_admin'], true))
                            || ($key === 'trainer_members' && in_array($page, ['diet_builder', 'trainer_assessment'], true) && $role === 'trainer')
                        );

                        // Determine if badge represents an unread / actionable notification in collapsed mode
                        $badgeHasUnread = false;
                        $dotModifier = '';
                        if (!empty($sidebarBadges[$key])) {
                            $badgeClass = $sidebarBadges[$key]['class'] ?? '';
                            if (str_contains($badgeClass, 'sidebar-badge-alert')) {
                                $badgeHasUnread = true;
                                $dotModifier = 'sidebar-badge-alert';
                            } elseif (str_contains($badgeClass, 'sidebar-badge-pending')) {
                                $badgeHasUnread = true;
                                $dotModifier = 'sidebar-badge-pending';
                            } elseif (str_contains($badgeClass, 'sidebar-badge-new')) {
                                $badgeHasUnread = true;
                                $dotModifier = 'sidebar-badge-new';
                            }
                        }
                    ?>
                        <a class="<?= $isActive ? 'active' : '' ?>" href="index.php?page=<?= h($key) ?>"<?= $key === 'notifications' ? ' id="sidebar-nav-notifications"' : ($key === 'messages' ? ' id="sidebar-nav-messages"' : '') ?>>
                            <span class="nav-icon" style="position: relative;">
                                <?= nav_icon($key) ?>
                                <?php if ($key === 'notifications'): ?>
                                    <span class="sidebar-notif-dot<?= $unreadNotifsCount > 0 ? ' has-unread' : '' ?>" id="sidebar-notif-dot"></span>
                                <?php elseif ($key === 'messages'): ?>
                                    <span class="sidebar-notif-dot<?= $unreadMsgsCount > 0 ? ' has-unread' : '' ?>" id="sidebar-messages-dot"></span>
                                <?php elseif ($badgeHasUnread): ?>
                                    <span class="sidebar-nav-dot has-unread <?= $dotModifier ?>"></span>
                                <?php endif; ?>
                            </span>
                            <span class="nav-label"><?= h($label) ?></span>
                            <?php if ($key === 'notifications'): ?>
                                <span class="sidebar-notif-badge<?= $unreadNotifsCount > 0 ? ' has-unread' : '' ?>" id="sidebar-notif-badge" style="<?= $unreadNotifsCount > 0 ? '' : 'display:none;' ?>"><?= $unreadNotifsCount > 99 ? '99+' : $unreadNotifsCount ?></span>
                            <?php elseif ($key === 'messages'): ?>
                                <span class="sidebar-notif-badge<?= $unreadMsgsCount > 0 ? ' has-unread' : '' ?>" id="sidebar-messages-badge" style="<?= $unreadMsgsCount > 0 ? '' : 'display:none;' ?>"><?= $unreadMsgsCount > 99 ? '99+' : $unreadMsgsCount ?></span>
                            <?php elseif (!empty($sidebarBadges[$key])): ?>
                                <span class="<?= h($sidebarBadges[$key]['class']) ?>" <?= !empty($sidebarBadges[$key]['title']) ? 'title="' . h($sidebarBadges[$key]['title']) . '"' : '' ?>>
                                    <?= h($sidebarBadges[$key]['text']) ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($tierBadge): ?>
                                <span class="sidebar-tier-badge" style="margin-left: auto; font-size: 8.5px; font-weight: 800; padding: 2px 5px; border-radius: 4px; background: rgba(132, 204, 22, 0.12); color: #84cc16; border: 1px solid rgba(132, 204, 22, 0.25); letter-spacing: 0.5px; flex-shrink: 0; white-space: nowrap; line-height: 1;"><?= $tierBadge ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="sidebar-bottom">
                    <?php
                        $profileSubPill = null;
                        if ($role === 'gym_owner' && $gym) {
                            $trialInfo = gym_trial_info($gym);
                            if ($trialInfo['is_trial_active']) {
                                $profileSubPill = [
                                    'text'  => 'FREE TRIAL',
                                    'title' => 'Free Trial (' . $trialInfo['days_left'] . ' days left)',
                                    'type'  => 'trial',
                                ];
                            } else {
                                $tier = gym_subscription_tier($gym);
                                if ($tier === 'free') {
                                    $isExpired = (!empty($gym['subscription_status']) && in_array($gym['subscription_status'], ['cancelled', 'expired'], true))
                                        || (!empty($gym['subscription_renewal_date']) && strtotime((string)$gym['subscription_renewal_date']) < strtotime('today'));
                                    $profileSubPill = [
                                        'text'  => 'FREE',
                                        'title' => $isExpired ? 'Current Plan: Free Tier (Subscription expired — click to upgrade)' : 'Current Plan: Free Tier',
                                        'type'  => 'free',
                                    ];
                                } else {
                                    $planRaw = strtolower(trim($gym['subscription_plan'] ?? ''));
                                    $planName = match (true) {
                                        str_contains($planRaw, 'starter')      => 'Starter',
                                        str_contains($planRaw, 'business')     => 'Business',
                                        str_contains($planRaw, 'enterprise')   => 'Enterprise',
                                        str_contains($planRaw, 'professional') => 'Pro',
                                        str_contains($planRaw, 'pro')          => 'Pro',
                                        default                                => ucfirst($gym['subscription_plan'] ?? 'Pro')
                                    };
                                    $profileSubPill = [
                                        'text'  => strtoupper($planName),
                                        'title' => 'Active Subscription: ' . ($gym['subscription_plan'] ?? 'Active'),
                                        'type'  => 'active',
                                    ];
                                }
                            }
                        } elseif ($role === 'member' && $user) {
                            $activeGymId = (int)($gym['gym_id'] ?? 0);
                            $membership = null;
                            if ($activeGymId > 0) {
                                $membershipStmt = db()->prepare('
                                    SELECT mp.plan_name, m.status, m.end_date 
                                    FROM memberships m 
                                    JOIN membership_plans mp ON mp.plan_id = m.plan_id 
                                    WHERE m.user_id = ? AND mp.gym_id = ? 
                                    ORDER BY (m.status = "active" AND m.end_date >= CURDATE()) DESC, (m.status = "pending") DESC, m.end_date DESC, m.membership_id DESC 
                                    LIMIT 1
                                ');
                                $membershipStmt->execute([$user['user_id'], $activeGymId]);
                                $membership = $membershipStmt->fetch();
                            } else {
                                $membershipStmt = db()->prepare('
                                    SELECT mp.plan_name, m.status, m.end_date 
                                    FROM memberships m 
                                    JOIN membership_plans mp ON mp.plan_id = m.plan_id 
                                    WHERE m.user_id = ? 
                                    ORDER BY (m.status = "active" AND m.end_date >= CURDATE()) DESC, (m.status = "pending") DESC, m.end_date DESC, m.membership_id DESC 
                                    LIMIT 1
                                ');
                                $membershipStmt->execute([$user['user_id']]);
                                $membership = $membershipStmt->fetch();
                            }
                            if ($membership) {
                                $isMemActive = ($membership['status'] === 'active' && !empty($membership['end_date']) && strtotime((string)$membership['end_date']) >= strtotime('today'));
                                if ($isMemActive) {
                                    $profileSubPill = [
                                        'text'  => strtoupper($membership['plan_name']),
                                        'title' => 'Active Membership (' . ($gym['name'] ?? 'Current Facility') . '): ' . $membership['plan_name'],
                                        'type'  => 'active',
                                    ];
                                } elseif ($membership['status'] === 'pending') {
                                    $profileSubPill = [
                                        'text'  => 'PENDING',
                                        'title' => 'Membership Pending Approval / Payment (' . ($gym['name'] ?? 'Current Facility') . '): ' . $membership['plan_name'],
                                        'type'  => 'pending',
                                    ];
                                } elseif ($membership['status'] === 'cancelled') {
                                    $profileSubPill = [
                                        'text'  => 'CANCELLED',
                                        'title' => 'Membership Cancelled (' . ($gym['name'] ?? 'Current Facility') . ')',
                                        'type'  => 'cancelled',
                                    ];
                                } else {
                                    $profileSubPill = [
                                        'text'  => 'EXPIRED',
                                        'title' => 'Membership Expired (' . ($gym['name'] ?? 'Current Facility') . ')',
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
                        <a href="mailto:johncinemartil596@gmail.com?subject=FitTracks%20Support" target="_blank"><span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg></span> <span class="nav-label">Contact Support</span></a>
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
                        <?php if (!empty($memberAffiliatedGyms) && count($memberAffiliatedGyms) > 1): ?>
                            <div id="topbarFacilityDropdownWrap" class="topbar-facility-dropdown-wrap"></div>
                            <script>
                            (function() {
                                function initFacilityDropdown() {
                                    if (typeof FitDropdown === 'undefined') {
                                        setTimeout(initFacilityDropdown, 50);
                                        return;
                                    }
                                    const container = document.getElementById('topbarFacilityDropdownWrap');
                                    if (!container) return;

                                    const memberGyms = <?= json_encode(array_map(function($mg) use ($gym) {
                                        return [
                                            'id'       => (string)$mg['gym_id'],
                                            'label'    => $mg['name'],
                                            'subtitle' => !empty($mg['plan_name']) ? $mg['plan_name'] : '',
                                            'icon'     => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>',
                                        ];
                                    }, $memberAffiliatedGyms)) ?>;

                                    const activeGymId = <?= json_encode((string)($gym['gym_id'] ?? '')) ?>;
                                    const totalGyms = memberGyms.length;

                                    window.topbarFacilityDropdown = new FitDropdown({
                                        container: container,
                                        customClass: 'topbar-facility-dropdown',
                                        value: activeGymId,
                                        items: memberGyms,
                                        searchable: false,
                                        zIndex: 120,
                                        menuAlign: 'auto',
                                        renderTrigger: function(selected) {
                                            const label = selected ? selected.label : 'Select Facility';
                                            return `
                                                <div class="topbar-facility-icon">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M3 21h18"/>
                                                        <path d="M5 21V7l8-4v18"/>
                                                        <path d="M19 21V11l-6-4"/>
                                                        <circle cx="9" cy="9" r="1"/>
                                                        <circle cx="9" cy="13" r="1"/>
                                                        <circle cx="9" cy="17" r="1"/>
                                                    </svg>
                                                </div>
                                                <div class="topbar-facility-body">
                                                    <span class="topbar-facility-label">Facility</span>
                                                    <span class="topbar-facility-current-name">${window.escapeHtml ? window.escapeHtml(label) : label}</span>
                                                </div>
                                                <span class="topbar-facility-badge">${totalGyms} Gyms</span>
                                            `;
                                        },
                                        onChange: function(selected) {
                                            if (selected && selected.id && String(selected.id) !== String(activeGymId)) {
                                                window.location.href = 'index.php?switch_active_gym=' + encodeURIComponent(selected.id) + '&page=<?= h($page) ?>';
                                            }
                                        }
                                    });
                                }

                                if (document.readyState === 'loading') {
                                    document.addEventListener('DOMContentLoaded', initFacilityDropdown);
                                } else {
                                    initFacilityDropdown();
                                }
                            })();
                            </script>
                        <?php endif; ?>
                        <?php if ($user && in_array($user['role'] ?? '', ['member', 'trainer'])): ?>
                            <?php 
                            auto_checkout_past_attendance((int) $user['user_id']);
                            $activeCheckinId = scalar('SELECT attendance_id FROM attendance WHERE user_id = ? AND check_out_time IS NULL AND check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY ORDER BY check_in_time DESC LIMIT 1', [$user['user_id']]); 
                            ?>
                            <?php if ($activeCheckinId): 
                                $activeCheckinRow = query_one('SELECT a.gym_id, g.name AS gym_name FROM attendance a LEFT JOIN gyms g ON g.gym_id = a.gym_id WHERE a.attendance_id = ?', [$activeCheckinId]);
                                $activeGymName = $activeCheckinRow['gym_name'] ?? 'your gym';
                            ?>
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
                                        var isLight = document.documentElement.getAttribute('data-theme') === 'light' || document.body.getAttribute('data-theme') === 'light';
                                        var emptyColor = isLight ? '#cbd5e1' : '#475569';
                                        var activeColor = '#f59e0b';
                                        var targetGymName = <?= json_encode($activeGymName) ?>;

                                        var starHtml = '<div id="swal-star-row">'
                                            + [1,2,3,4,5].map(function(v){
                                                return '<span class="co-star" data-val="' + v + '">★</span>';
                                              }).join('')
                                            + '</div>';

                                        Swal.fire({
                                            title: 'Rate Your Workout at ' + (targetGymName ? targetGymName : 'Your Gym'),
                                            html: '<p class="checkout-modal-desc">How was your workout session? Leave a 1–5 star rating and optional review for <strong>' + (targetGymName ? targetGymName : 'your gym') + '</strong>.</p>'
                                                + starHtml
                                                + '<textarea id="swal-comment" placeholder="Write an optional review (equipment, cleanliness, coaching, overall experience)..." rows="2"></textarea>',
                                            showCancelButton: true,
                                            showDenyButton: false,
                                            confirmButtonText: 'Submit & Check Out',
                                            cancelButtonText: 'Skip & Check Out',
                                            customClass: {
                                                popup: 'swal-checkout-modal',
                                                confirmButton: 'swal-checkout-confirm-btn',
                                                cancelButton: 'swal-skip-btn'
                                            },
                                            didOpen: function() {
                                                var stars = document.querySelectorAll('.co-star');
                                                var updateStars = function(val) {
                                                    stars.forEach(function(s, i) {
                                                        var isFilled = i < val;
                                                        s.style.color = isFilled ? activeColor : emptyColor;
                                                        s.style.transform = isFilled ? 'scale(1.15)' : 'scale(1)';
                                                        s.style.textShadow = isFilled ? '0 0 12px rgba(245, 158, 11, 0.45)' : 'none';
                                                    });
                                                };
                                                stars.forEach(function(star) {
                                                    star.addEventListener('mouseover', function() {
                                                        var val = parseInt(star.dataset.val, 10);
                                                        updateStars(val);
                                                    });
                                                    star.addEventListener('mouseout', function() {
                                                        updateStars(selectedRating);
                                                    });
                                                    star.addEventListener('click', function() {
                                                        selectedRating = parseInt(star.dataset.val, 10);
                                                        updateStars(selectedRating);
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
                        <?php if ($user && ($user['role'] ?? '') === 'gym_owner'): ?>
                            <button type="button" class="tour-quick-btn" id="gym-owner-tour-trigger" onclick="if(window.startGymOwnerTour){window.startGymOwnerTour(true);}" title="Take Guided Gym Owner Tour">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon>
                                </svg>
                                <span class="tour-btn-text">Quick Tour</span>
                            </button>
                        <?php elseif ($user && ($user['role'] ?? '') === 'member'): ?>
                            <button type="button" class="tour-quick-btn" id="member-tour-trigger" onclick="if(window.startMemberTour){window.startMemberTour(true);}" title="Take Guided Member Tour">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon>
                                </svg>
                                <span class="tour-btn-text">Quick Tour</span>
                            </button>
                        <?php endif; ?>
                        <?php render_notification_bell($user, $page, $unreadNotifsCount); ?>
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
                            <?php if (($user['role'] ?? '') !== 'gym_owner'): ?>
                                <small><?= h(ucwords(str_replace('_', ' ', (string)($user['role'] ?? '')))) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                </header>

                <?php 
                $hasGymOrTour = $user && (
                    (!empty($memberAffiliatedGyms) && count($memberAffiliatedGyms) >= 1) || 
                    (!empty($gym) && in_array($user['role'] ?? '', ['member', 'gym_owner']))
                );
                if ($hasGymOrTour): 
                    $gymCount = count($memberAffiliatedGyms);
                ?>
                    <!-- Mobile Floating Action Pill & Bottom Sheet (Facility Switcher & Quick Tour) -->
                    <div id="mobileFacilityFab" class="mobile-facility-fab" aria-label="Active Facility & Quick Tour">
                        <button type="button" class="mobile-facility-fab-trigger" id="mobileFacilityFabTrigger" aria-haspopup="dialog" aria-expanded="false" title="<?= $gymCount > 1 ? 'Switch facility (' . $gymCount . ' gyms) or take tour' : 'Facility & Quick Tour' ?>">
                            <span class="mobile-facility-fab-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 21h18"/>
                                    <path d="M5 21V7l8-4v18"/>
                                    <path d="M19 21V11l-6-4"/>
                                    <circle cx="9" cy="9" r="1"/>
                                    <circle cx="9" cy="13" r="1"/>
                                    <circle cx="9" cy="17" r="1"/>
                                </svg>
                            </span>
                            <?php if ($gymCount > 1): ?>
                                <span class="mobile-facility-fab-badge"><?= $gymCount ?></span>
                            <?php endif; ?>
                        </button>

                        <div class="mobile-facility-fab-backdrop" id="mobileFacilityFabBackdrop"></div>

                        <div class="mobile-facility-fab-sheet" id="mobileFacilityFabSheet" role="dialog" aria-modal="true" aria-hidden="true">
                            <div class="mobile-fab-sheet-header">
                                <div class="mobile-fab-sheet-header-text">
                                    <div class="mobile-fab-sheet-title">Active Facility</div>
                                    <div class="mobile-fab-sheet-sub"><?= $gymCount > 1 ? 'Switch workspace or take a tour' : 'Your gym workspace & tour' ?></div>
                                </div>
                                <button type="button" class="mobile-fab-sheet-close" id="mobileFacilityFabClose" aria-label="Close facility menu">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:block;">
                                        <line x1="18" y1="6" x2="6" y2="18"></line>
                                        <line x1="6" y1="6" x2="18" y2="18"></line>
                                    </svg>
                                </button>
                            </div>
                            <div class="mobile-fab-sheet-body">
                                <?php if (!empty($memberAffiliatedGyms)): ?>
                                    <div class="mobile-fab-section-label"><?= $gymCount > 1 ? 'Your Affiliated Facilities' : 'Current Facility' ?></div>
                                    <div class="mobile-fab-gym-list">
                                        <?php foreach ($memberAffiliatedGyms as $mg): 
                                            $isActive = (int)$mg['gym_id'] === (int)($gym['gym_id'] ?? 0);
                                        ?>
                                            <a href="<?= $gymCount > 1 ? 'index.php?switch_active_gym=' . urlencode((string)$mg['gym_id']) . '&page=' . urlencode($page) : 'javascript:void(0)' ?>" class="mobile-fab-gym-item <?= $isActive ? 'is-active' : '' ?>">
                                                <div class="mobile-fab-gym-icon">
                                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M3 21h18"/>
                                                        <path d="M5 21V7l8-4v18"/>
                                                        <path d="M19 21V11l-6-4"/>
                                                    </svg>
                                                </div>
                                                <div class="mobile-fab-gym-details">
                                                    <div class="mobile-fab-gym-name">
                                                        <?= h($mg['name']) ?>
                                                        <?php if ($isActive): ?>
                                                            <span class="mobile-fab-active-pill">Active</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="mobile-fab-gym-plan">
                                                        <?= !empty($mg['plan_name']) ? h($mg['plan_name']) : 'No active membership' ?>
                                                    </div>
                                                </div>
                                                <div class="mobile-fab-gym-action">
                                                    <?php if ($isActive): ?>
                                                        <span class="mobile-fab-check-icon">
                                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="mobile-fab-switch-arrow">
                                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php elseif ($gym): ?>
                                    <div class="mobile-fab-section-label">Current Facility</div>
                                    <div class="mobile-fab-gym-list">
                                        <div class="mobile-fab-gym-item is-active" style="cursor: default;">
                                            <div class="mobile-fab-gym-icon">
                                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M3 21h18"/>
                                                    <path d="M5 21V7l8-4v18"/>
                                                    <path d="M19 21V11l-6-4"/>
                                                </svg>
                                            </div>
                                            <div class="mobile-fab-gym-details">
                                                <div class="mobile-fab-gym-name">
                                                    <?= h($gym['name']) ?>
                                                    <span class="mobile-fab-active-pill">Active</span>
                                                </div>
                                                <div class="mobile-fab-gym-plan"><?= h(ucwords(str_replace('_', ' ', $user['role'] ?? ''))) ?></div>
                                            </div>
                                            <div class="mobile-fab-gym-action">
                                                <span class="mobile-fab-check-icon">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($user && in_array($user['role'] ?? '', ['member', 'gym_owner'])): 
                                    $userRole = (string)($user['role'] ?? '');
                                    $tourFn = ($userRole === 'gym_owner') ? 'startGymOwnerTour' : 'startMemberTour';
                                    $tourTitle = ($userRole === 'gym_owner') ? 'Guided Owner Tour' : 'Guided Quick Tour';
                                    $tourSubtitle = ($userRole === 'gym_owner') ? 'Interactive walk-through of gym management' : 'Interactive walk-through of the dashboard';
                                ?>
                                    <div class="mobile-fab-tour-section">
                                        <button type="button" class="mobile-fab-tour-trigger-btn" id="mobileFabTourTriggerBtn" data-tour-fn="<?= $tourFn ?>">
                                            <span class="mobile-fab-tour-icon">
                                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                                    <circle cx="12" cy="12" r="10"></circle>
                                                    <polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon>
                                                </svg>
                                            </span>
                                            <span class="mobile-fab-tour-text">
                                                <strong><?= $tourTitle ?></strong>
                                                <small><?= $tourSubtitle ?></small>
                                            </span>
                                            <svg class="mobile-fab-tour-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <script>
                    (function() {
                        function initMobileFacilityFab() {
                            const trigger = document.getElementById('mobileFacilityFabTrigger');
                            const sheet = document.getElementById('mobileFacilityFabSheet');
                            const backdrop = document.getElementById('mobileFacilityFabBackdrop');
                            const closeBtn = document.getElementById('mobileFacilityFabClose');
                            const tourBtn = document.getElementById('mobileFabTourTriggerBtn');

                            if (!trigger || !sheet || !backdrop) return;

                            function openFab() {
                                sheet.classList.add('open');
                                backdrop.classList.add('open');
                                trigger.setAttribute('aria-expanded', 'true');
                                sheet.setAttribute('aria-hidden', 'false');
                                document.body.style.overflow = 'hidden';
                            }

                            function closeFab() {
                                sheet.classList.remove('open');
                                backdrop.classList.remove('open');
                                trigger.setAttribute('aria-expanded', 'false');
                                sheet.setAttribute('aria-hidden', 'true');
                                document.body.style.overflow = '';
                            }

                            trigger.addEventListener('click', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                sheet.classList.contains('open') ? closeFab() : openFab();
                            });

                            if (closeBtn) {
                                closeBtn.addEventListener('click', function(e) {
                                    e.preventDefault();
                                    closeFab();
                                });
                            }

                            backdrop.addEventListener('click', closeFab);

                            document.addEventListener('keydown', function(e) {
                                if (e.key === 'Escape' && sheet.classList.contains('open')) {
                                    closeFab();
                                }
                            });

                            if (tourBtn) {
                                tourBtn.addEventListener('click', function(e) {
                                    e.preventDefault();
                                    closeFab();
                                    setTimeout(function() {
                                        const fnName = tourBtn.getAttribute('data-tour-fn') || 'startMemberTour';
                                        if (typeof window[fnName] === 'function') {
                                            window[fnName](true);
                                        }
                                    }, 250);
                                });
                            }
                        }

                        if (document.readyState === 'loading') {
                            document.addEventListener('DOMContentLoaded', initMobileFacilityFab);
                        } else {
                            initMobileFacilityFab();
                        }
                    })();
                    </script>
                <?php endif; ?>
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
                                        <span class="trial-banner-subtext">Free Trial active (up to 50 members, 2 trainers & 2 staff). Upgrade for unlimited capacity.</span>
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

    $requiredIndicatorScript = <<<HTML
<script>
(function() {
    function injectRequiredAsterisks(root) {
        var scope = (root && root.querySelectorAll) ? root : document;
        var reqInputs = scope.querySelectorAll('input[required], select[required], textarea[required], [aria-required="true"]');
        
        reqInputs.forEach(function(input) {
            if (input.type === 'hidden' || input.type === 'submit' || input.type === 'button') {
                return;
            }
            
            var label = null;
            if (input.id) {
                try {
                    label = document.querySelector('label[for="' + CSS.escape(input.id) + '"]');
                } catch(e) {}
            }
            if (!label) {
                label = input.closest('label');
            }
            if (!label && input.parentElement) {
                var group = input.closest('.form-group, .split-form-group, .form-row, .setup-pref-group, .input-group, div');
                if (group) {
                    label = group.querySelector('label');
                }
            }
            
            if (label) {
                // Skip if already contains .req-star or text asterisk
                if (label.querySelector('.req-star') || label.textContent.indexOf('*') !== -1) {
                    return;
                }
                
                var star = document.createElement('span');
                star.className = 'req-star';
                star.setAttribute('aria-hidden', 'true');
                star.textContent = ' *';
                
                try {
                    if (label.contains(input)) {
                        var refNode = input;
                        while (refNode && refNode.parentNode && refNode.parentNode !== label) {
                            refNode = refNode.parentNode;
                        }
                        if (refNode && refNode.parentNode === label) {
                            label.insertBefore(star, refNode);
                        } else {
                            label.appendChild(star);
                        }
                    } else {
                        label.appendChild(star);
                    }
                } catch (e) {
                    try {
                        label.appendChild(star);
                    } catch (ignore) {}
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            injectRequiredAsterisks(document);
        });
    } else {
        injectRequiredAsterisks(document);
    }

    var observer = new MutationObserver(function(mutations) {
        for (var i = 0; i < mutations.length; i++) {
            var m = mutations[i];
            if (m.addedNodes && m.addedNodes.length) {
                for (var j = 0; j < m.addedNodes.length; j++) {
                    var node = m.addedNodes[j];
                    if (node.nodeType === 1) {
                        injectRequiredAsterisks(node);
                    }
                }
            } else if (m.type === 'attributes' && m.attributeName === 'required') {
                if (m.target && m.target.nodeType === 1) {
                    injectRequiredAsterisks(m.target.parentElement || document);
                }
            }
        }
    });

    if (document.body) {
        observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['required'] });
    } else {
        document.addEventListener('DOMContentLoaded', function() {
            observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['required'] });
        });
    }
})();
</script>
HTML;

    $isAuthPage = defined('AUTH_PAGE') && AUTH_PAGE;


    if (!$isAuthPage && current_user()) {
        $u = current_user();
        if (($u['role'] ?? '') === 'member' && function_exists('render_member_floating_rating_modal')) {
            render_member_floating_rating_modal($u);
        } elseif (in_array(($u['role'] ?? ''), ['gym_owner', 'admin'], true) && function_exists('render_owner_floating_rating_modal')) {
            render_owner_floating_rating_modal($u);
        }
        echo '</main></section></div>';
        echo $navScript;
        echo $themeScript;
        echo $confirmScript;
        echo $passwordScript;
        echo $skeletonScript;
        echo $requiredIndicatorScript;
        echo '</body></html>';
        return;
    }
    echo '</main>';
    echo $themeScript;
    echo $confirmScript;
    echo $passwordScript;
    echo $skeletonScript;
    echo $requiredIndicatorScript;
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
