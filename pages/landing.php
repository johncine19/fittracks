<?php
declare(strict_types=1);

if (!function_exists('landing_icon')) {
    function landing_icon(string $name, string $class = ''): string
    {
        $classAttr = $class ? ' class="' . htmlspecialchars($class) . '"' : '';
        $icons = [
            'chart' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="10" width="3" height="8" rx="1"/><rect x="12" y="5" width="3" height="13" rx="1"/><rect x="17" y="13" width="3" height="5" rx="1"/></svg>',
            'zap' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
            'arrow-left' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>',
            'arrow-right' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>',
            'x' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
            'building' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/></svg>',
            'users' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'qr' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M7 7h.01"/><path d="M18 7h.01"/><path d="M7 18h.01"/><path d="M18 18h.01"/></svg>',
            'card' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
            'dumbbell' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6.5 6.5h11M6.5 17.5h11M2.5 9.5v5M21.5 9.5v5M4.5 8v8M19.5 8v8M6.5 6.5v11M17.5 6.5v11"/></svg>',
            'clipboard' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg>',
            'trend-up' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>',
            'bell' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
            'check' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
            'star' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
            'quote' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.638-3.996 5.849h3.983v10h-9.983z"/></svg>',
            'menu' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>',
            'alert-circle' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
            'shield' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>',
            'play' => '<svg' . $classAttr . ' xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="6 4 20 12 6 20 6 4"/></svg>',
        ];

        return $icons[$name] ?? '';
    }
}

if (!function_exists('landing_hero_qr_path')) {
    /**
     * Decorative 21×21 QR-style glyph for the hero's check-in card, returned as a
     * single SVG path. Deterministic (no RNG state touched) so markup is stable.
     */
    function landing_hero_qr_path(): string
    {
        $size = 21;
        $finders = [[0, 0], [$size - 7, 0], [0, $size - 7]];
        $d = '';
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $dark = null;
                foreach ($finders as [$fx, $fy]) {
                    $rx = $x - $fx;
                    $ry = $y - $fy;
                    if ($rx >= -1 && $rx <= 7 && $ry >= -1 && $ry <= 7) {
                        $inFinder = $rx >= 0 && $rx <= 6 && $ry >= 0 && $ry <= 6;
                        $ring = $rx === 0 || $rx === 6 || $ry === 0 || $ry === 6;
                        $core = $rx >= 2 && $rx <= 4 && $ry >= 2 && $ry <= 4;
                        $dark = $inFinder && ($ring || $core);
                        break;
                    }
                }
                if ($dark === null) {
                    $dark = (crc32("ft-qr-$x-$y") % 100) < 47;
                }
                if ($dark) {
                    $d .= "M{$x} {$y}h1v1h-1z";
                }
            }
        }
        return $d;
    }
}

function landing_page(): void
{
    // If user is already logged in, send straight to dashboard
    if (current_user()) {
        redirect('dashboard');
    }

    // ── Handle Demo Request POST ─────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['demo_request'])) {
        try {
            verify_csrf();
            $full_name = trim((string) ($_POST['full_name'] ?? ''));
            $email = trim((string) ($_POST['work_email'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $gym_name = trim((string) ($_POST['gym_name'] ?? ''));
            $members = trim((string) ($_POST['approx_members'] ?? ''));
            $role = trim((string) ($_POST['role'] ?? ''));
            $message = trim((string) ($_POST['message'] ?? ''));

            // Validate required fields
            $errors = [];
            if (!$full_name)
                $errors[] = 'Full Name is required';
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL))
                $errors[] = 'A valid work email is required';
            if (!$phone)
                $errors[] = 'Mobile number is required';

            if (empty($errors)) {
                // Create table if not exists
                db()->exec("CREATE TABLE IF NOT EXISTS demo_requests (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    gym_name VARCHAR(150) DEFAULT NULL,
                    approx_members VARCHAR(50) DEFAULT NULL,
                    full_name VARCHAR(200) NOT NULL,
                    work_email VARCHAR(200) NOT NULL,
                    phone VARCHAR(50) DEFAULT NULL,
                    role VARCHAR(100) DEFAULT NULL,
                    message TEXT DEFAULT NULL,
                    status ENUM('new','contacted','demo_scheduled','closed') DEFAULT 'new',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_status (status),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                // Ensure columns exist on pre-existing tables
                try {
                    $cols = db()->query("SHOW COLUMNS FROM demo_requests")->fetchAll(PDO::FETCH_COLUMN);
                    if (!in_array('gym_name', $cols)) {
                        db()->exec("ALTER TABLE demo_requests ADD COLUMN gym_name VARCHAR(150) DEFAULT NULL");
                    }
                    if (!in_array('message', $cols)) {
                        db()->exec("ALTER TABLE demo_requests ADD COLUMN message TEXT DEFAULT NULL");
                    }
                } catch (\Throwable $e) {
                }

                // Insert
                $stmt = db()->prepare("INSERT INTO demo_requests (gym_name, approx_members, full_name, work_email, phone, role, message) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$gym_name, $members, $full_name, $email, $phone, $role, $message]);

                // Email notification via Queue
                try {
                    $adminEmail = $_ENV['ADMIN_EMAIL'] ?? 'johncinemartil596@gmail.com';
                    $subject = "New FitTrack Demo Request from {$full_name}";
                    $body = "<h2>New FitTrack Demo Request</h2>
                        <p><strong>Name:</strong> {$full_name}</p>
                        <p><strong>Email:</strong> {$email}</p>
                        <p><strong>Phone:</strong> {$phone}</p>
                        <p><strong>Gym Name:</strong> {$gym_name}</p>
                        <p><strong>Role:</strong> {$role}</p>
                        <p><strong>Approx Members:</strong> {$members}</p>
                        <p><strong>Message:</strong> {$message}</p>";
                    Queue::push('send_email', ['to' => $adminEmail, 'subject' => $subject, 'body' => $body]);
                } catch (\Throwable $e) {
                    error_log("Demo request email failed: " . $e->getMessage());
                }

                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            } else {
                header('Content-Type: application/json');
                http_response_code(422);
                echo json_encode(['success' => false, 'errors' => $errors]);
                exit;
            }
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['success' => false, 'errors' => ['Server error. Please try again.']]);
            exit;
        }
    }

    // ── Live stats ──────────────────────────────────────────────────
    $stat_gyms = 0;
    $stat_members = 0;
    $stat_trainers = 0;
    try {
        $stat_gyms = (int) scalar("SELECT COUNT(*) FROM gyms WHERE status = 'approved'");
        $stat_members = (int) scalar('SELECT COUNT(*) FROM users WHERE role = "member" AND status = "active"');
        $stat_trainers = (int) scalar('SELECT COUNT(*) FROM users WHERE role = "trainer" AND status = "active"');
    } catch (\Throwable $e) {
    }

    // ── Platform Ratings & Owner Reviews ───────────────────────────
    $platformStats   = get_platform_rating_stats();
    $platformReviews = get_platform_reviews(12);


    // ── Platform Subscription Plans ──────────────────────────────────
    $platformPlans = get_platform_subscription_plans();
    $starterPlan = $platformPlans['starter'] ?? [
        'name' => 'Starter',
        'price' => 599,
        'price_label' => '₱599',
        'desc' => 'Ideal for boutique fitness studios and single-location facilities.',
        'popular' => false,
        'features' => ['Up to 100 Active Members', 'Solo Gym Owner Model (0 Trainers)', 'Walk-in Management & Daily Pass', 'Dynamic QR Check-in & Scanner', 'Membership Plans & GCash / Online Pay', 'Basic Expiration Reminders (7-Day Notice)', 'Basic Financial Reports & CSV Export', 'Basic Activity History']
    ];
    $proPlan = $platformPlans['professional'] ?? [
        'name' => 'Professional',
        'price' => 999,
        'price_label' => '₱999',
        'desc' => 'Designed for growing commercial gyms with full coaching staff.',
        'popular' => true,
        'features' => ['Up to 500 Active Members', 'Personal Trainers & Client Assignments (Unlimited)', 'Trainer Commission Tracking & Payouts', 'Workout Plans & Exercise Library', 'Class Scheduling & Online Booking with Waitlists', 'Automated Multi-Stage Renewal Reminders (30d/14d/7d/1d)', 'Member Engagement Scoring & Inactivity Alerts', 'Advanced Analytics & Financial Growth Trends', 'Staff Activity Logs']
    ];
    $businessPlan = $platformPlans['business'] ?? [
        'name' => 'Business',
        'price' => 1999,
        'price_label' => '₱1,999',
        'desc' => 'For high-volume gyms that need room to grow.',
        'popular' => false,
        'features' => ['Unlimited Active Members', 'Unlimited Trainers', 'All Professional features, including classes, trainer tools, and advanced reports', 'Custom Gym Brand Color', 'End-of-Day Summary Emails', 'Staff Financial Visibility Controls']
    ];
    ?>
    <!doctype html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>FitTrack — Manage. Engage. Grow. Commercial Gym Operations Platform</title>
        <meta name="description"
            content="FitTrack helps gyms streamline operations, monitor member engagement, QR attendance, billing, and drive results.">
        <meta name="robots" content="index, follow">

        <!-- Canonical URL -->
        <link rel="canonical" href="https://fitworks.tech/">

        <!-- Favicon & Mobile Touch Icons for Google Search & Browsers -->
        <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico">
        <link rel="icon" type="image/png" sizes="48x48" href="assets/images/favicon-48x48.png">
        <link rel="icon" type="image/png" sizes="192x192" href="assets/images/favicon.png">
        <link rel="icon" type="image/svg+xml" href="assets/images/fittracks-icon.svg">
        <link rel="apple-touch-icon" href="assets/images/favicon.png">

        <!-- Open Graph / Facebook / LinkedIn / WhatsApp -->
        <meta property="og:type" content="website">
        <meta property="og:url" content="https://fitworks.tech/">
        <meta property="og:title" content="FitTrack — Commercial Gym Operations &amp; Membership Platform">
        <meta property="og:description"
            content="FitTrack helps gyms streamline operations, monitor member engagement, QR attendance, billing, and drive results.">
        <meta property="og:image" content="https://fitworks.tech/assets/landing/gym-management.png">
        <meta property="og:site_name" content="FitTrack">

        <!-- Twitter / X -->
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:url" content="https://fitworks.tech/">
        <meta name="twitter:title" content="FitTrack — Commercial Gym Operations &amp; Membership Platform">
        <meta name="twitter:description"
            content="FitTrack helps gyms streamline operations, monitor member engagement, QR attendance, billing, and drive results.">
        <meta name="twitter:image" content="https://fitworks.tech/assets/landing/gym-management.png">

        <!-- Schema.org Structured Data -->
        <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "SoftwareApplication",
            "name": "FitTrack",
            "applicationCategory": "BusinessApplication",
            "operatingSystem": "Web",
            "description": "FitTrack helps gyms streamline operations, monitor member engagement, QR attendance, billing, and drive results.",
            "url": "https://fitworks.tech/",
            "offers": [
                {
                    "@type": "Offer",
                    "name": "Starter",
                    "price": "599",
                    "priceCurrency": "PHP"
                },
                {
                    "@type": "Offer",
                    "name": "Professional",
                    "price": "999",
                    "priceCurrency": "PHP"
                },
                {
                    "@type": "Offer",
                    "name": "Business",
                    "price": "1999",
                    "priceCurrency": "PHP"
                }
            ]
        }
        </script>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link
            href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,700;1,900&display=swap"
            rel="stylesheet">
        <link rel="stylesheet" href="assets/landing.css?v=<?= filemtime(__DIR__ . '/../assets/landing.css') ?>">
        <link rel="stylesheet" href="<?= h(asset_url('css/pages/landing-hero.css')) ?>">
        <link rel="stylesheet" href="<?= h(asset_url('css/pages/landing-architecture.css')) ?>">
        <link rel="preload" as="image" href="assets/landing/hero_gym.png" fetchpriority="high">
        <link rel="stylesheet" href="assets/splash.css?v=<?= file_exists(__DIR__ . '/../assets/splash.css') ? filemtime(__DIR__ . '/../assets/splash.css') : 1 ?>">
        <script src="assets/splash.js?v=<?= file_exists(__DIR__ . '/../assets/splash.js') ? filemtime(__DIR__ . '/../assets/splash.js') : 1 ?>"></script>
    </head>

    <body>
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

        <!-- ==========================================================================
       01. BRAND LOGO & NAVIGATION
       ========================================================================== -->
        <nav class="saas-nav" id="mainNav">
            <div class="container nav-container">
                <a href="#" class="brand-logo-wrap">
                    <div class="brand-f-logo">F</div>
                    <div class="brand-text-block">
                        <div class="brand-logotype">
                            <span class="brand-fit">FIT</span><span class="brand-track">TRACK</span>
                        </div>
                        <span class="brand-tagline">MANAGE. ENGAGE. GROW.</span>
                    </div>
                </a>

                <ul class="nav-menu">
                    <li><a href="#features" class="nav-link">Features</a></li>
                    <li><a href="#how-it-works" class="nav-link">How It Works</a></li>
                    <li><a href="#testimonials" class="nav-link">Testimonials</a></li>
                    <li><a href="#pricing" class="nav-link">Pricing</a></li>
                    <li><a href="#faq" class="nav-link">FAQ</a></li>
                </ul>

                <div class="nav-actions">
                    <a href="index.php?page=register" class="nav-auth-link">Join as Member</a>
                    <a href="javascript:void(0)" onclick="openDemoModal()" class="nav-demo-link">Request Demo</a>
                    <a href="index.php?page=login" class="btn btn-secondary btn-sm">Log In</a>
                    <a href="index.php?page=gym_register" class="btn btn-lime btn-sm">Register Gym</a>
                </div>

                <div class="nav-mobile-actions">
                    <a href="index.php?page=login" class="mobile-nav-login-btn">Log In</a>
                    <button class="mobile-toggle" id="mobileMenuBtn" aria-label="Toggle Navigation">
                        <?= landing_icon('menu', 'mobile-toggle-icon') ?>
                    </button>
                </div>
            </div>
        </nav>

        <!-- Mobile Menu Drawer -->
        <div class="mobile-drawer" id="mobileDrawer">
            <a href="#features" class="mobile-drawer-link" onclick="closeMobileMenu()">Features</a>
            <a href="#how-it-works" class="mobile-drawer-link" onclick="closeMobileMenu()">How It Works</a>
            <a href="#testimonials" class="mobile-drawer-link" onclick="closeMobileMenu()">Testimonials</a>
            <a href="#pricing" class="mobile-drawer-link" onclick="closeMobileMenu()">Pricing</a>
            <a href="#faq" class="mobile-drawer-link" onclick="closeMobileMenu()">FAQ</a>
            <a href="index.php?page=register" class="mobile-drawer-link" onclick="closeMobileMenu()">Join as Member</a>
            <a href="javascript:void(0)" class="mobile-drawer-link" onclick="closeMobileMenu(); openDemoModal();">Request Demo Walkthrough</a>
            <a href="index.php?page=gym_register" class="btn btn-lime mobile-drawer-cta" onclick="closeMobileMenu()">Register Your Gym</a>
        </div>

        <!-- Ambient Lighting Glow Orbs -->
        <div class="ambient-glow glow-top"></div>
        <div class="ambient-glow glow-hero"></div>

        <!-- ==========================================================================
       02. HERO SECTION — IMMERSIVE: SPOTLIT GYM FLOOR + LIVE PRODUCT HUD
       Styles: assets/css/pages/landing-hero.css · Motion: assets/js/pages/landing-hero.js
       ========================================================================== -->
        <?php
        $heroFeedSeed = [
            ['Andrea S.', 'AS', 18, 'Monthly', 'Active', false, '2m'],
            ['Jomar P.', 'JP', 200, 'Annual', 'Active', false, '5m'],
            ['Lia C.', 'LC', 320, 'PT · Coach Mia', 'Renews in 4d', true, '9m'],
            ['Ken D.', 'KD', 150, 'Day pass', 'Walk-in', false, '12m'],
        ];
        ?>
        <header class="hx-hero is-pending" id="hero">
            <script>
                // Failsafe: never leave the hero hidden if the hero script fails to load.
                setTimeout(function () { var h = document.getElementById('hero'); if (h) h.classList.remove('is-pending'); }, 4000);
            </script>

            <!-- Background depth stack -->
            <div class="hx-stage" aria-hidden="true">
                <div class="hx-dolly">
                    <div class="hx-drift">
                        <img class="hx-photo" src="assets/landing/hero_gym.png" alt="" width="1024" height="1024" fetchpriority="high" decoding="async">
                    </div>
                </div>
                <div class="hx-dim"></div>
                <div class="hx-glow"></div>
                <canvas class="hx-particles"></canvas>
                <div class="hx-shade"></div>
                <div class="hx-grain"></div>
            </div>

            <div class="hx-body">
                <div class="container hx-grid">
                    <!-- Copy -->
                    <div class="hx-copy">
                        <h1 class="hx-title">
                            <span class="hx-line"><span class="hx-line-inner" style="--i:0">All-in-one</span></span>
                            <span class="hx-line"><span class="hx-line-inner" style="--i:1">software to</span></span>
                            <span class="hx-line hx-line-accent"><span class="hx-line-inner" style="--i:2"><span class="hx-rotator" data-hx-rotator><span class="hx-word is-active">grow</span><span class="hx-word" aria-hidden="true">run</span><span class="hx-word" aria-hidden="true">scale</span><span class="hx-word" aria-hidden="true">power</span></span> your gym.</span></span>
                        </h1>

                        <p class="hx-sub hx-reveal" style="--d:640ms">
                            <span class="hx-sub-desktop">From the front desk to the training floor, FitTrack brings check-ins, memberships, payments, and coaching into one place—so your team can spend less time chasing updates.</span>
                            <span class="hx-sub-mobile">Check-ins, billing, and coaching—streamlined in one platform.</span>
                        </p>

                        <div class="hx-ctas hx-reveal" style="--d:780ms">
                            <a href="index.php?page=gym_register" class="hx-btn hx-btn-primary hx-magnetic" id="hero-cta-start">
                                <span>Start your gym setup</span>
                                <?= landing_icon('arrow-right', 'hx-btn-arrow') ?>
                            </a>
                            <button type="button" class="hx-btn hx-btn-ghost hx-magnetic" id="hero-cta-demo" onclick="openDemoModal()">
                                <span class="hx-play" aria-hidden="true"><?= landing_icon('play') ?></span>
                                <span>Request a demo</span>
                            </button>
                        </div>

                        <ul class="hx-cues hx-reveal" style="--d:920ms">
                            <li><?= landing_icon('check') ?>14-day free trial</li>
                            <li><?= landing_icon('check') ?>No credit card required</li>
                            <li class="hx-cue-desktop"><?= landing_icon('check') ?>Free tier fallback anytime</li>
                        </ul>
                    </div>

                    <!-- Live product HUD (illustrative preview, hidden from assistive tech) -->
                    <div class="hx-hud" aria-hidden="true">
                        <div class="hx-float hx-float-rev" data-depth="0.55">
                            <div class="hx-card hx-rev">
                                <span class="hx-kicker">Revenue · This month</span>
                                <div class="hx-rev-row">
                                    <span class="hx-rev-value">₱<span data-hx-revenue data-target="184250">184,250</span></span>
                                    <span class="hx-trend">▲ 12.4%</span>
                                </div>
                                <svg class="hx-spark" viewBox="0 0 250 72" preserveAspectRatio="none">
                                    <defs>
                                        <linearGradient id="hxSparkFill" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0" stop-color="#84cc16" stop-opacity=".35" />
                                            <stop offset="1" stop-color="#84cc16" stop-opacity="0" />
                                        </linearGradient>
                                    </defs>
                                    <path class="hx-spark-area" fill="url(#hxSparkFill)" d="M0 60 L22 55 L44 58 L66 46 L88 50 L110 38 L132 42 L154 30 L176 34 L198 21 L220 25 L236 14 L236 72 L0 72 Z" />
                                    <path class="hx-spark-line" pathLength="1" fill="none" stroke="#a3e635" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" d="M0 60 L22 55 L44 58 L66 46 L88 50 L110 38 L132 42 L154 30 L176 34 L198 21 L220 25 L236 14" />
                                    <circle class="hx-spark-ping" cx="236" cy="14" r="4" fill="#a3e635" />
                                    <circle class="hx-spark-dot" cx="236" cy="14" r="3.5" fill="#ecfccb" stroke="#84cc16" stroke-width="2" />
                                </svg>
                            </div>
                        </div>

                        <div class="hx-float hx-float-feed" data-depth="1">
                            <div class="hx-card hx-feed">
                                <div class="hx-card-head">
                                    <div>
                                        <span class="hx-kicker"><span class="hx-live-dot"></span>Front desk · Live</span>
                                        <strong class="hx-card-title">Check-ins today</strong>
                                    </div>
                                    <span class="hx-count" data-hx-count>128</span>
                                </div>
                                <ul class="hx-feed-list" data-hx-feed>
                                    <?php foreach ($heroFeedSeed as [$fName, $fInit, $fHue, $fPlan, $fStatus, $fWarn, $fTime]): ?>
                                        <li class="hx-feed-row">
                                            <span class="hx-avatar" style="--h:<?= (int) $fHue ?>"><?= htmlspecialchars($fInit) ?></span>
                                            <span class="hx-feed-meta">
                                                <b><?= htmlspecialchars($fName) ?></b>
                                                <small><?= htmlspecialchars($fPlan) ?> · <em class="hx-status<?= $fWarn ? ' is-warn' : '' ?>"><?= htmlspecialchars($fStatus) ?></em></small>
                                            </span>
                                            <span class="hx-feed-time" data-min="<?= (int) $fTime ?>"><?= htmlspecialchars($fTime) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>

                        <div class="hx-float hx-float-scan" data-depth="1.6">
                            <div class="hx-card hx-scan">
                                <div class="hx-qr">
                                    <svg viewBox="0 0 21 21" shape-rendering="crispEdges"><path fill="#0b0f14" d="<?= landing_hero_qr_path() ?>" /></svg>
                                    <span class="hx-scanline"></span>
                                </div>
                                <div class="hx-scan-info">
                                    <span class="hx-kicker">QR check-in</span>
                                    <strong class="hx-scan-title"><?= landing_icon('check') ?>Access granted</strong>
                                    <span class="hx-scan-sub" data-hx-scan-sub>Andrea S. · Main entrance</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom band: scroll cue -->
            <div class="hx-bottom">
                <button type="button" class="hx-scroll" data-hx-scroll aria-label="Scroll to explore">
                    <span>Scroll</span>
                    <span class="hx-scroll-line" aria-hidden="true"></span>
                </button>
            </div>
        </header>

        <!-- ==========================================================================
       03. TRUST / INTRODUCTION SECTION
       ========================================================================== -->
        <section class="trust-section reveal-on-scroll">
            <div class="container">
                <div class="section-header">
                    <span class="section-label">Built for Purpose</span>
                    <h2 class="section-title">Built for the people who keep a gym moving.</h2>
                    <p class="section-desc">FitTrack connects all key roles in your fitness ecosystem under one unified
                        operation.</p>
                </div>

                <div class="trust-roles-grid">
                    <div class="trust-role-card">
                        <span class="role-tag">Business Leadership</span>
                        <h3 class="role-title">Gym Owners</h3>
                        <p class="role-desc">Manage revenue, member subscriptions, staff payroll, facility capacity, and
                            business metrics from a centralized control panel.</p>
                    </div>

                    <div class="trust-role-card">
                        <span class="role-tag">Coaching Staff</span>
                        <h3 class="role-title">Trainers</h3>
                        <p class="role-desc">Assign personalized workout routines, build dietary plans, track member
                            assessments, and evaluate training compliance.</p>
                    </div>

                    <div class="trust-role-card">
                        <span class="role-tag">Fitness Community</span>
                        <h3 class="role-title">Members</h3>
                        <p class="role-desc">Seamlessly check in via QR, track workout logs, monitor body metrics, view
                            equipment queues, and maintain streak goals.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ==========================================================================
       04. THE GYM MANAGEMENT PROBLEM
       ========================================================================== -->
        <section class="problem-section reveal-on-scroll">
            <div class="container problem-grid">
                <div class="problem-points">
                    <div class="section-header" style="text-align: left; margin-bottom: 2rem;">
                        <span class="section-label">The Operational Challenge</span>
                        <h2 class="section-title">Everything Your Gym Needs. In One Place.</h2>
                        <p class="section-desc">Traditional gym management often suffers from disconnected software tools,
                            manual paperwork, and fragmented data.</p>
                    </div>

                    <div class="problem-point-card">
                        <div class="problem-icon"><?= landing_icon('alert-circle', 'icon-svg') ?></div>
                        <div>
                            <div class="problem-text-title">Fragmented Member Records</div>
                            <div class="problem-text-desc">Membership status, medical forms, and payment histories scattered
                                across spreadsheets.</div>
                        </div>
                    </div>

                    <div class="problem-point-card">
                        <div class="problem-icon"><?= landing_icon('alert-circle', 'icon-svg') ?></div>
                        <div>
                            <div class="problem-text-title">Unmonitored Member Churn</div>
                            <div class="problem-text-desc">No automated warnings when regular members drop off or stop
                                attending classes.</div>
                        </div>
                    </div>

                    <div class="problem-point-card">
                        <div class="problem-icon"><?= landing_icon('alert-circle', 'icon-svg') ?></div>
                        <div>
                            <div class="problem-text-title">Disconnected Trainer Workflows</div>
                            <div class="problem-text-desc">Workout routines delivered on paper cards or unorganized instant
                                messages.</div>
                        </div>
                    </div>
                </div>

                <div class="problem-summary-card ft-custom-card-assembly" id="ftProblemSummaryCard">
                    <!-- SVG Background with Dynamic Cutout Path -->
                    <svg class="ft-card-svg" aria-hidden="true">
                        <defs>
                            <clipPath id="ftCardClip">
                                <path id="ftCardClipPath" />
                            </clipPath>

                            <linearGradient id="ftCardGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#242c3d" stop-opacity="0.94" />
                                <stop offset="50%" stop-color="#141824" stop-opacity="0.97" />
                                <stop offset="100%" stop-color="#0c1018" stop-opacity="0.99" />
                            </linearGradient>

                            <linearGradient id="ftSpecularGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#ffffff" stop-opacity="0.75" />
                                <stop offset="35%" stop-color="#bef264" stop-opacity="0.55" />
                                <stop offset="70%" stop-color="#ffffff" stop-opacity="0.12" />
                                <stop offset="100%" stop-color="#84cc16" stop-opacity="0.35" />
                            </linearGradient>

                            <linearGradient id="ftPillGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#2a3346" stop-opacity="0.96" />
                                <stop offset="100%" stop-color="#131722" stop-opacity="0.98" />
                            </linearGradient>

                            <filter id="ftCardShadow" x="-15%" y="-15%" width="130%" height="130%">
                                <feDropShadow dx="0" dy="28" stdDeviation="30" flood-color="#000000" flood-opacity="0.8" />
                                <feDropShadow dx="0" dy="0" stdDeviation="20" flood-color="#84cc16" flood-opacity="0.14" />
                            </filter>

                            <filter id="ftPillShadow" x="-20%" y="-20%" width="140%" height="140%">
                                <feDropShadow dx="0" dy="14" stdDeviation="16" flood-color="#000000" flood-opacity="0.7" />
                                <feDropShadow dx="0" dy="0" stdDeviation="12" flood-color="#84cc16" flood-opacity="0.22" />
                            </filter>
                        </defs>

                        <path class="ft-card-bg" id="ftCardBgPath" filter="url(#ftCardShadow)" />
                        <path class="ft-card-specular" id="ftCardSpecularPath" />
                    </svg>

                    <!-- Frosted Glass Sheen Layer (Clipped to Notched Silhouette) -->
                    <div class="ft-card-inner-sheen" aria-hidden="true"></div>

                    <!-- Card Content Overlay -->
                    <div class="ft-card-content">
                        <div class="ft-glass-top">
                            <span class="summary-tag">
                                <span class="summary-tag-dot"></span>
                                The FitTrack Engine
                            </span>
                            <div class="ft-glass-badge">
                                <?= landing_icon('zap') ?>
                                <span>Core Engine</span>
                            </div>
                        </div>

                        <h3 class="summary-headline">
                            FitTrack brings every operational thread <span class="summary-headline-accent">into a single live system.</span>
                        </h3>

                        <div class="ft-card-bottom-row">
                            <p class="summary-body">
                                From automated turnstile QR scanning to live trainer assignments and financial reporting, FitTrack
                                eliminates administrative friction so your team can focus on member retention and growth.
                            </p>
                        </div>
                    </div>

                    <!-- Floating Companion Pill nestled in the bottom-right cutout -->
                    <div class="floating-pill-wrap" id="ftFloatingPill">
                        <svg class="pill-svg" aria-hidden="true">
                            <path class="pill-bg" id="ftPillBgPath" filter="url(#ftPillShadow)" />
                            <path class="pill-specular" id="ftPillSpecularPath" />
                        </svg>
                        <div class="pill-content">
                            <span class="pill-dot"></span>
                            <span>99.98% System Uptime</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ==========================================================================
       05. THE FITTRACK SOLUTION — CONNECTED OPERATING PIPELINE
       Styles: assets/css/pages/landing-architecture.css
       ========================================================================== -->
        <section class="arch-section reveal-on-scroll">
            <div class="arch-backdrop" aria-hidden="true"></div>
            <div class="container">
                <div class="section-header">
                    <span class="section-label">Unified Architecture</span>
                    <h2 class="section-title">One Platform. Total Connectivity.</h2>
                    <p class="section-desc">From floor interactions to executive intelligence—watch how FitTrack turns daily gym operations into an automated, synchronized workflow.</p>
                </div>

                <div class="arch-pipeline">
                    <!-- Stage 01: Ingestion & Touchpoints -->
                    <div class="arch-card arch-card-1">
                        <div class="arch-card-glow" aria-hidden="true"></div>
                        <svg class="arch-border-svg" aria-hidden="true" focusable="false">
                            <path class="arch-path arch-path-top" pathLength="1000" />
                            <path class="arch-path arch-path-bottom" pathLength="1000" />
                        </svg>
                        <div class="arch-merge-point" aria-hidden="true"></div>
                        <div>
                            <div class="arch-card-top">
                                <span class="arch-step-badge">Stage 01</span>
                                <span class="arch-status-pill"><span class="arch-pulse-dot"></span>Live Ingestion</span>
                            </div>
                            <h3 class="arch-card-title">Gym Touchpoints</h3>
                            <p class="arch-card-sub">Every member, coach, and billing event captured at source without manual intervention.</p>

                            <ul class="arch-feature-list">
                                <li class="arch-feature-item">
                                    <div class="arch-feature-icon"><?= landing_icon('qr') ?></div>
                                    <div class="arch-feature-content">
                                        <b>Dynamic QR Turnstile Check-Ins</b>
                                        <span>Instant identity validation &amp; time-stamped entrance logs.</span>
                                    </div>
                                </li>
                                <li class="arch-feature-item">
                                    <div class="arch-feature-icon"><?= landing_icon('card') ?></div>
                                    <div class="arch-feature-content">
                                        <b>GCash, POS &amp; Walk-In Payments</b>
                                        <span>Automated receipts, day pass activation &amp; plan upgrades.</span>
                                    </div>
                                </li>
                                <li class="arch-feature-item">
                                    <div class="arch-feature-icon"><?= landing_icon('clipboard') ?></div>
                                    <div class="arch-feature-content">
                                        <b>Coach Routines &amp; Diet Logging</b>
                                        <span>Trainers assign client workouts and check compliance on the floor.</span>
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <div class="arch-card-footer">
                            <span>Sub-second event stream</span>
                            <?= landing_icon('zap') ?>
                        </div>
                    </div>

                    <!-- Animated Beam Connector 1 (Stage 1 -> Stage 2) -->
                    <div class="arch-connector arch-connector-1" aria-hidden="true">
                        <div class="arch-track">
                            <span class="arch-pulse-beam"></span>
                            <span class="arch-pulse-comet"></span>
                        </div>
                        <div class="arch-connector-node"><?= landing_icon('arrow-right') ?></div>
                    </div>

                    <!-- Stage 02: FitTrack Operations Engine (Hub) -->
                    <div class="arch-card arch-card-hub arch-card-2">
                        <div class="arch-card-glow" aria-hidden="true"></div>
                        <svg class="arch-border-svg" aria-hidden="true" focusable="false">
                            <path class="arch-path arch-path-top" pathLength="1000" />
                            <path class="arch-path arch-path-bottom" pathLength="1000" />
                        </svg>
                        <div class="arch-merge-point" aria-hidden="true"></div>
                        <div>
                            <div class="arch-card-top">
                                <span class="arch-step-badge">Stage 02 · Core</span>
                                <span class="arch-status-pill"><span class="arch-pulse-dot"></span>FitTrack Engine</span>
                            </div>
                            <h3 class="arch-card-title">Intelligent Automation</h3>
                            <p class="arch-card-sub">The central brain that handles expiration rules, payroll math, and security protocols in real time.</p>

                            <ul class="arch-feature-list">
                                <li class="arch-feature-item">
                                    <div class="arch-feature-icon"><?= landing_icon('bell') ?></div>
                                    <div class="arch-feature-content">
                                        <b>Multi-Stage Renewal Automation</b>
                                        <span>Dispatches automated reminders at 30d, 14d, 7d, and 1d notices.</span>
                                    </div>
                                </li>
                                <li class="arch-feature-item">
                                    <div class="arch-feature-icon"><?= landing_icon('chart') ?></div>
                                    <div class="arch-feature-content">
                                        <b>Trainer Commission Splitter</b>
                                        <span>Calculates trainer shares per session with automatic payroll accrual.</span>
                                    </div>
                                </li>
                                <li class="arch-feature-item">
                                    <div class="arch-feature-icon"><?= landing_icon('shield') ?></div>
                                    <div class="arch-feature-content">
                                        <b>Pass-Sharing Security Guard</b>
                                        <span>Flags duplicate concurrent scans and stops barcode screenshot abuse.</span>
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <div class="arch-card-footer">
                            <span>99.98% System uptime</span>
                            <?= landing_icon('shield') ?>
                        </div>
                    </div>

                    <!-- Animated Beam Connector 2 (Stage 2 -> Stage 3) -->
                    <div class="arch-connector arch-connector-2" aria-hidden="true">
                        <div class="arch-track">
                            <span class="arch-pulse-beam"></span>
                            <span class="arch-pulse-comet"></span>
                        </div>
                        <div class="arch-connector-node"><?= landing_icon('arrow-right') ?></div>
                    </div>

                    <!-- Stage 03: Actionable Outcomes -->
                    <div class="arch-card arch-card-3">
                        <div class="arch-card-glow" aria-hidden="true"></div>
                        <svg class="arch-border-svg" aria-hidden="true" focusable="false">
                            <path class="arch-path arch-path-top" pathLength="1000" />
                            <path class="arch-path arch-path-bottom" pathLength="1000" />
                        </svg>
                        <div class="arch-merge-point" aria-hidden="true"></div>
                        <div>
                            <div class="arch-card-top">
                                <span class="arch-step-badge">Stage 03</span>
                                <span class="arch-status-pill"><span class="arch-pulse-dot"></span>Executive View</span>
                            </div>
                            <h3 class="arch-card-title">Business Growth</h3>
                            <p class="arch-card-sub">Immediate clarity on revenue velocity, staff performance, and member retention curves.</p>

                            <ul class="arch-feature-list">
                                <li class="arch-feature-item">
                                    <div class="arch-feature-icon"><?= landing_icon('trend-up') ?></div>
                                    <div class="arch-feature-content">
                                        <b>Real-Time Financial P&amp;L &amp; MRR</b>
                                        <span>Live revenue breakdowns by plan, walk-ins, and coach packages.</span>
                                    </div>
                                </li>
                                <li class="arch-feature-item">
                                    <div class="arch-feature-icon"><?= landing_icon('users') ?></div>
                                    <div class="arch-feature-content">
                                        <b>Floor Capacity &amp; Peak Heatmaps</b>
                                        <span>Monitor current gym floor density and equipment peak usage hours.</span>
                                    </div>
                                </li>
                                <li class="arch-feature-item">
                                    <div class="arch-feature-icon"><?= landing_icon('check') ?></div>
                                    <div class="arch-feature-content">
                                        <b>1-Click Payroll CSV Export</b>
                                        <span>Complete staff payouts and revenue reports ready in seconds.</span>
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <div class="arch-card-footer">
                            <span>Zero administrative friction</span>
                            <?= landing_icon('star') ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ==========================================================================
       06. CORE FEATURES (Platform Capabilities - Serpentine Hexagon Infographic)
       ========================================================================== -->
        <section class="has-parallax-orbs features-section reveal-on-scroll" id="features">
            <div class="parallax-orbs" aria-hidden="true">
                <span class="parallax-orb orb-lime" data-parallax="0.22"></span>
                <span class="parallax-orb orb-sky" data-parallax="-0.12"></span>
            </div>

            <!-- Global SVG Gradients for Hexagon & Wing -->
            <svg style="position: absolute; width: 0; height: 0; overflow: hidden;" aria-hidden="true">
                <defs>
                    <linearGradient id="capHexFillGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stop-color="#14191a" stop-opacity="0.95" />
                        <stop offset="55%" stop-color="#1c2419" stop-opacity="0.98" />
                        <stop offset="85%" stop-color="#4d7c0f" stop-opacity="0.92" />
                        <stop offset="100%" stop-color="#bef264" stop-opacity="1" />
                    </linearGradient>

                    <linearGradient id="capHexBevelGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#bef264" stop-opacity="0.85" />
                        <stop offset="45%" stop-color="#84cc16" stop-opacity="0.55" />
                        <stop offset="80%" stop-color="#ffffff" stop-opacity="0.95" />
                        <stop offset="100%" stop-color="#bef264" stop-opacity="1" />
                    </linearGradient>

                    <linearGradient id="capWingFillGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" stop-color="#182218" stop-opacity="0.95" />
                        <stop offset="30%" stop-color="#12161f" stop-opacity="0.92" />
                        <stop offset="100%" stop-color="#0b0e14" stop-opacity="0.96" />
                    </linearGradient>

                    <linearGradient id="capWingBorderGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" stop-color="#84cc16" stop-opacity="0.6" />
                        <stop offset="50%" stop-color="#475569" stop-opacity="0.3" />
                        <stop offset="90%" stop-color="#bef264" stop-opacity="0.5" />
                        <stop offset="100%" stop-color="#84cc16" stop-opacity="0.8" />
                    </linearGradient>
                </defs>
            </svg>

            <div class="container">
                <div class="section-header">
                    <span class="section-label">Platform Capabilities</span>
                    <h2 class="section-title">Everything moving behind the workout.</h2>
                    <p class="section-desc">The daily details that keep members showing up and your team on the same page.</p>
                </div>

                <div class="serpentine-container" id="capabilitiesSerpentineTimeline">
                    <!-- SVG Serpentine Track Line -->
                    <svg class="serpentine-track-svg" aria-hidden="true">
                        <path class="track-line" id="capabilitiesTrackPath" />
                    </svg>

                    <!-- Item 01 -->
                    <div class="cap-item">
                        <div class="cap-node">01</div>
                        <div class="cap-card">
                            <div class="cap-hex-badge">
                                <svg class="cap-hex-svg" viewBox="0 0 100 100" preserveAspectRatio="none">
                                    <polygon points="25,5 75,5 98,50 75,95 25,95 2,50" fill="url(#capHexFillGrad)" stroke="url(#capHexBevelGrad)" stroke-width="2" />
                                    <line x1="25" y1="95" x2="75" y2="95" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" />
                                </svg>
                                <div class="cap-hex-icon"><?= landing_icon('users', 'icon-svg') ?></div>
                            </div>
                            <div class="cap-wing">
                                <svg class="cap-wing-svg" viewBox="0 0 500 100" preserveAspectRatio="none">
                                    <polygon points="0,5 470,5 498,50 470,95 0,95" fill="url(#capWingFillGrad)" stroke="url(#capWingBorderGrad)" stroke-width="1.5" />
                                </svg>
                                <div class="cap-wing-body">
                                    <h3 class="cap-title">Member Management</h3>
                                    <p class="cap-desc">Complete digital records, membership plan statuses, profiles, and attendance history.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Item 02 (Stagger Right) -->
                    <div class="cap-item stagger-right">
                        <div class="cap-node">02</div>
                        <div class="cap-card">
                            <div class="cap-hex-badge">
                                <svg class="cap-hex-svg" viewBox="0 0 100 100" preserveAspectRatio="none">
                                    <polygon points="25,5 75,5 98,50 75,95 25,95 2,50" fill="url(#capHexFillGrad)" stroke="url(#capHexBevelGrad)" stroke-width="2" />
                                    <line x1="25" y1="95" x2="75" y2="95" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" />
                                </svg>
                                <div class="cap-hex-icon"><?= landing_icon('qr', 'icon-svg') ?></div>
                            </div>
                            <div class="cap-wing">
                                <svg class="cap-wing-svg" viewBox="0 0 500 100" preserveAspectRatio="none">
                                    <polygon points="0,5 470,5 498,50 470,95 0,95" fill="url(#capWingFillGrad)" stroke="url(#capWingBorderGrad)" stroke-width="1.5" />
                                </svg>
                                <div class="cap-wing-body">
                                    <h3 class="cap-title">Dynamic QR Attendance</h3>
                                    <p class="cap-desc">Secure time-stamped check-ins using rotating dynamic QR codes to prevent pass-sharing.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Item 03 -->
                    <div class="cap-item">
                        <div class="cap-node">03</div>
                        <div class="cap-card">
                            <div class="cap-hex-badge">
                                <svg class="cap-hex-svg" viewBox="0 0 100 100" preserveAspectRatio="none">
                                    <polygon points="25,5 75,5 98,50 75,95 25,95 2,50" fill="url(#capHexFillGrad)" stroke="url(#capHexBevelGrad)" stroke-width="2" />
                                    <line x1="25" y1="95" x2="75" y2="95" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" />
                                </svg>
                                <div class="cap-hex-icon"><?= landing_icon('card', 'icon-svg') ?></div>
                            </div>
                            <div class="cap-wing">
                                <svg class="cap-wing-svg" viewBox="0 0 500 100" preserveAspectRatio="none">
                                    <polygon points="0,5 470,5 498,50 470,95 0,95" fill="url(#capWingFillGrad)" stroke="url(#capWingBorderGrad)" stroke-width="1.5" />
                                </svg>
                                <div class="cap-wing-body">
                                    <h3 class="cap-title">Online Payments</h3>
                                    <p class="cap-desc">Streamlined membership billing, recurring payments, walk-in fees, and audit logging.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Item 04 (Stagger Right) -->
                    <div class="cap-item stagger-right">
                        <div class="cap-node">04</div>
                        <div class="cap-card">
                            <div class="cap-hex-badge">
                                <svg class="cap-hex-svg" viewBox="0 0 100 100" preserveAspectRatio="none">
                                    <polygon points="25,5 75,5 98,50 75,95 25,95 2,50" fill="url(#capHexFillGrad)" stroke="url(#capHexBevelGrad)" stroke-width="2" />
                                    <line x1="25" y1="95" x2="75" y2="95" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" />
                                </svg>
                                <div class="cap-hex-icon"><?= landing_icon('dumbbell', 'icon-svg') ?></div>
                            </div>
                            <div class="cap-wing">
                                <svg class="cap-wing-svg" viewBox="0 0 500 100" preserveAspectRatio="none">
                                    <polygon points="0,5 470,5 498,50 470,95 0,95" fill="url(#capWingFillGrad)" stroke="url(#capWingBorderGrad)" stroke-width="1.5" />
                                </svg>
                                <div class="cap-wing-body">
                                    <h3 class="cap-title">Trainer Management</h3>
                                    <p class="cap-desc">Assign coaches to members, manage training schedules, and monitor client progression.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Item 05 -->
                    <div class="cap-item">
                        <div class="cap-node">05</div>
                        <div class="cap-card">
                            <div class="cap-hex-badge">
                                <svg class="cap-hex-svg" viewBox="0 0 100 100" preserveAspectRatio="none">
                                    <polygon points="25,5 75,5 98,50 75,95 25,95 2,50" fill="url(#capHexFillGrad)" stroke="url(#capHexBevelGrad)" stroke-width="2" />
                                    <line x1="25" y1="95" x2="75" y2="95" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" />
                                </svg>
                                <div class="cap-hex-icon"><?= landing_icon('clipboard', 'icon-svg') ?></div>
                            </div>
                            <div class="cap-wing">
                                <svg class="cap-wing-svg" viewBox="0 0 500 100" preserveAspectRatio="none">
                                    <polygon points="0,5 470,5 498,50 470,95 0,95" fill="url(#capWingFillGrad)" stroke="url(#capWingBorderGrad)" stroke-width="1.5" />
                                </svg>
                                <div class="cap-wing-body">
                                    <h3 class="cap-title">Workout &amp; Dietary Plans</h3>
                                    <p class="cap-desc">Custom routine builders with automated calorie and macro calculation engines.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Item 06 (Stagger Right) -->
                    <div class="cap-item stagger-right">
                        <div class="cap-node">06</div>
                        <div class="cap-card">
                            <div class="cap-hex-badge">
                                <svg class="cap-hex-svg" viewBox="0 0 100 100" preserveAspectRatio="none">
                                    <polygon points="25,5 75,5 98,50 75,95 25,95 2,50" fill="url(#capHexFillGrad)" stroke="url(#capHexBevelGrad)" stroke-width="2" />
                                    <line x1="25" y1="95" x2="75" y2="95" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" />
                                </svg>
                                <div class="cap-hex-icon"><?= landing_icon('zap', 'icon-svg') ?></div>
                            </div>
                            <div class="cap-wing">
                                <svg class="cap-wing-svg" viewBox="0 0 500 100" preserveAspectRatio="none">
                                    <polygon points="0,5 470,5 498,50 470,95 0,95" fill="url(#capWingFillGrad)" stroke="url(#capWingBorderGrad)" stroke-width="1.5" />
                                </svg>
                                <div class="cap-wing-body">
                                    <h3 class="cap-title">Equipment Availability</h3>
                                    <p class="cap-desc">Live equipment queue monitoring and maintenance schedule tracking.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Item 07 -->
                    <div class="cap-item">
                        <div class="cap-node">07</div>
                        <div class="cap-card">
                            <div class="cap-hex-badge">
                                <svg class="cap-hex-svg" viewBox="0 0 100 100" preserveAspectRatio="none">
                                    <polygon points="25,5 75,5 98,50 75,95 25,95 2,50" fill="url(#capHexFillGrad)" stroke="url(#capHexBevelGrad)" stroke-width="2" />
                                    <line x1="25" y1="95" x2="75" y2="95" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" />
                                </svg>
                                <div class="cap-hex-icon"><?= landing_icon('trend-up', 'icon-svg') ?></div>
                            </div>
                            <div class="cap-wing">
                                <svg class="cap-wing-svg" viewBox="0 0 500 100" preserveAspectRatio="none">
                                    <polygon points="0,5 470,5 498,50 470,95 0,95" fill="url(#capWingFillGrad)" stroke="url(#capWingBorderGrad)" stroke-width="1.5" />
                                </svg>
                                <div class="cap-wing-body">
                                    <h3 class="cap-title">Fitness Progress</h3>
                                    <p class="cap-desc">Track weight, body measurement changes, strength records, and milestone achievements.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Item 08 (Stagger Right) -->
                    <div class="cap-item stagger-right">
                        <div class="cap-node">08</div>
                        <div class="cap-card">
                            <div class="cap-hex-badge">
                                <svg class="cap-hex-svg" viewBox="0 0 100 100" preserveAspectRatio="none">
                                    <polygon points="25,5 75,5 98,50 75,95 25,95 2,50" fill="url(#capHexFillGrad)" stroke="url(#capHexBevelGrad)" stroke-width="2" />
                                    <line x1="25" y1="95" x2="75" y2="95" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" />
                                </svg>
                                <div class="cap-hex-icon"><?= landing_icon('bell', 'icon-svg') ?></div>
                            </div>
                            <div class="cap-wing">
                                <svg class="cap-wing-svg" viewBox="0 0 500 100" preserveAspectRatio="none">
                                    <polygon points="0,5 470,5 498,50 470,95 0,95" fill="url(#capWingFillGrad)" stroke="url(#capWingBorderGrad)" stroke-width="1.5" />
                                </svg>
                                <div class="cap-wing-body">
                                    <h3 class="cap-title">Engagement Monitoring</h3>
                                    <p class="cap-desc">Automated alerts flag inactive members early so staff can re-engage them before churn.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ==========================================================================
       07. FEATURE PRESENTATION (ALTERNATING ROWS WITH ACCURATE GYM PHOTOS)
       ========================================================================== -->
        <section class="feature-presentation-section reveal-on-scroll" id="feature-deepdives">
            <div class="container">
                <!-- Mobile-Only Segmented Pill Tabs -->
                <div class="deepdive-mobile-nav" id="deepdiveMobileNav">
                    <button class="deepdive-pill-btn active" onclick="switchDeepDiveTab(0)">
                        <span class="pill-num">01</span> Members
                    </button>
                    <button class="deepdive-pill-btn" onclick="switchDeepDiveTab(1)">
                        <span class="pill-num">02</span> QR Access
                    </button>
                    <button class="deepdive-pill-btn" onclick="switchDeepDiveTab(2)">
                        <span class="pill-num">03</span> Trainers
                    </button>
                    <button class="deepdive-pill-btn" onclick="switchDeepDiveTab(3)">
                        <span class="pill-num">04</span> Analytics
                    </button>
                </div>

                <!-- Feature Presentation Track: Alternating rows on desktop, horizontal snap carousel on mobile -->
                <div class="feature-presentation-track" id="featurePresentationTrack">
                    <!-- Row 1: Member Management -->
                    <div class="feature-row" id="deepdiveCard-0">
                        <div class="feature-text-block">
                            <span class="feature-label">01 — Administration</span>
                        <h2 class="feature-row-title">Member Management & Records</h2>
                        <p class="feature-row-desc">
                            Keep every member record clean, organized, and accessible. Easily verify subscription renewals,
                            attendance streaks, and account status in seconds.
                        </p>
                        <ul class="feature-bullets">
                            <li class="feature-bullet-item">
                                <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                Instant status verification (Active, Expiring, Pending)
                            </li>
                            <li class="feature-bullet-item">
                                <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                Automated membership renewal notifications
                            </li>
                            <li class="feature-bullet-item">
                                <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                Centralized profile storage and health assessments
                            </li>
                        </ul>
                    </div>
                    <div class="feature-media-frame">
                        <img src="assets/landing/gym_member.png" alt="Member Training" class="feature-media-photo" data-parallax="0.1" data-parallax-scale="1.2" data-parallax-sm="0">
                        <div class="feature-media-ui-overlay">
                            <div class="media-ui-title">MEMBER STATUS</div>
                            <div class="media-ui-val">Active Annual Membership • Verified</div>
                        </div>
                    </div>
                </div>

                <!-- Row 2: Dynamic QR Attendance -->
                <div class="feature-row reverse" id="deepdiveCard-1">
                    <div class="feature-text-block">
                        <span class="feature-label">02 — Access Control</span>
                        <h2 class="feature-row-title">Dynamic QR Turnstile Verification</h2>
                        <p class="feature-row-desc">
                            Replace legacy keycards with secure dynamic QR scanning. Members generate a fresh security code
                            on their smartphone for instant turnstile check-in.
                        </p>
                        <ul class="feature-bullets">
                            <li class="feature-bullet-item">
                                <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                Prevents membership card sharing
                            </li>
                            <li class="feature-bullet-item">
                                <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                Logs peak hours and floor utilization in real time
                            </li>
                            <li class="feature-bullet-item">
                                <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                Automated self-checkout and session feedback
                            </li>
                        </ul>
                    </div>
                    <div class="feature-media-frame">
                        <img src="assets/landing/gym_qr_scan.png" alt="Dynamic QR Turnstile Entrance"
                            class="feature-media-photo" data-parallax="0.1" data-parallax-scale="1.2" data-parallax-sm="0">
                        <div class="feature-media-ui-overlay">
                            <div class="media-ui-title">DYNAMIC QR SCANNER</div>
                            <div class="media-ui-val">QR Code Verified — Turnstile Unlocked (08:42 AM)</div>
                        </div>
                    </div>
                </div>

                <!-- Row 3: Trainer Coaching -->
                <div class="feature-row" id="deepdiveCard-2">
                    <div class="feature-text-block">
                        <span class="feature-label">03 — Coaching Staff</span>
                        <h2 class="feature-row-title">Personalized Trainer Guidance</h2>
                        <p class="feature-row-desc">
                            Empower personal trainers to build customized workout programs, log client body assessments, and
                            monitor training compliance directly within the system.
                        </p>
                        <ul class="feature-bullets">
                            <li class="feature-bullet-item">
                                <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                Custom routine templates and exercise libraries
                            </li>
                            <li class="feature-bullet-item">
                                <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                Trainer commission tracking and session logging
                            </li>
                            <li class="feature-bullet-item">
                                <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                Integrated client messaging and feedback
                            </li>
                        </ul>
                    </div>
                    <div class="feature-media-frame">
                        <img src="assets/landing/gym_trainer.png" alt="Trainer Guidance" class="feature-media-photo" data-parallax="0.1" data-parallax-scale="1.2" data-parallax-sm="0">
                        <div class="feature-media-ui-overlay">
                            <div class="media-ui-title">COACHING DASHBOARD</div>
                            <div class="media-ui-val">12 Active Clients Assigned • 98% Program Compliance</div>
                        </div>
                    </div>
                </div>

                <!-- Row 4: Gym Operations & Analytics -->
                <div class="feature-row reverse" id="deepdiveCard-3">
                    <div class="feature-text-block">
                        <span class="feature-label">04 — Business Intelligence</span>
                        <h2 class="feature-row-title">Live Facility Operations & Analytics</h2>
                        <p class="feature-row-desc">
                            Give gym management full real-time operational control. Monitor peak floor hours, staff
                            schedules, and financial revenue stats on mobile or tablet devices.
                        </p>
                        <ul class="feature-bullets">
                            <li class="feature-bullet-item">
                                <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                Real-time occupancy & check-in metrics
                            </li>
                            <li class="feature-bullet-item">
                                <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                Recurring revenue & payment audit logging
                            </li>
                            <li class="feature-bullet-item">
                                <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                Rule-based inactivity alerts
                            </li>
                        </ul>
                    </div>
                    <div class="feature-media-frame">
                        <img src="assets/landing/gym_analytics_tablet.png" alt="Gym Operations Analytics Tablet"
                            class="feature-media-photo" data-parallax="0.1" data-parallax-scale="1.2" data-parallax-sm="0">
                        <div class="feature-media-ui-overlay">
                            <div class="media-ui-title">OPERATIONS CONTROL</div>
                            <div class="media-ui-val">Live Facility Analytics • 100% System Synchronization</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mobile-Only Carousel Dot Indicators -->
            <div class="deepdive-dots" id="deepdiveDots">
                <button class="deepdive-dot active" aria-label="01 Members" onclick="switchDeepDiveTab(0)"></button>
                <button class="deepdive-dot" aria-label="02 QR Access" onclick="switchDeepDiveTab(1)"></button>
                <button class="deepdive-dot" aria-label="03 Trainers" onclick="switchDeepDiveTab(2)"></button>
                <button class="deepdive-dot" aria-label="04 Analytics" onclick="switchDeepDiveTab(3)"></button>
            </div>
        </div>
    </section>

        <!-- Ambient Glow Orb -->
        <div class="ambient-glow glow-middle"></div>

        <!-- ==========================================================================
       08. PRODUCT SHOWCASE — LAPTOP MOCKUP CAROUSEL
       ========================================================================== -->
        <section class="showcase-section reveal-on-scroll" id="live-operations">
            <div class="parallax-layer showcase-bg" data-parallax="0.16" aria-hidden="true"></div>
            <div class="container">
                <div class="section-header">
                    <span class="section-label">Live Gym Operations</span>
                    <h2 class="section-title">See FitTrack in Action</h2>
                    <p class="section-desc">Explore the actual interface powering real gym operations — dashboard, members,
                        attendance, payments, and more.</p>
                </div>

                <!-- Laptop Device Frame -->
                <div class="laptop-wrapper" id="laptopCarousel">
                    <div class="laptop-device">
                        <!-- Laptop Screen -->
                        <div class="laptop-screen">
                            <!-- Browser Top Bar -->
                            <div class="laptop-browser-bar">
                                <div class="browser-dots">
                                    <span class="bdot bdot-red"></span>
                                    <span class="bdot bdot-yellow"></span>
                                    <span class="bdot bdot-green"></span>
                                </div>
                                <div class="browser-address" id="laptopAddressBar">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#84cc16"
                                        stroke-width="2.5">
                                        <rect x="3" y="11" width="18" height="11" rx="2" />
                                        <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                                    </svg>
                                    <span>fittrack.app/admin/dashboard</span>
                                </div>
                                <div class="browser-actions">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                        stroke="rgba(255,255,255,0.3)" stroke-width="2">
                                        <path d="M4 4h16v16H4z" />
                                    </svg>
                                </div>
                            </div>

                            <!-- Slide Container -->
                            <div class="laptop-slides-viewport">
                                <div class="laptop-slide active" data-slide-label="Gym Dashboard"
                                    data-slide-url="fittrack.app/admin/dashboard">
                                    <img src="assets/landing/slide_dashboard.png"
                                        alt="FitTrack Dashboard — Active Members, Revenue, Check-ins Overview"
                                        loading="lazy">
                                </div>
                                <div class="laptop-slide" data-slide-label="Member Management"
                                    data-slide-url="fittrack.app/admin/members">
                                    <img src="assets/landing/slide_members.png"
                                        alt="FitTrack Member Management — Digital Records & Profiles" loading="lazy">
                                </div>
                                <div class="laptop-slide" data-slide-label="QR Attendance"
                                    data-slide-url="fittrack.app/admin/attendance">
                                    <img src="assets/landing/slide_attendance.png"
                                        alt="FitTrack QR Attendance — Dynamic Gate Verification" loading="lazy">
                                </div>
                                <div class="laptop-slide" data-slide-label="Payment Transactions"
                                    data-slide-url="fittrack.app/admin/payments">
                                    <img src="assets/landing/slide_payments.png"
                                        alt="FitTrack Payments — Revenue Tracking & Billing" loading="lazy">
                                </div>
                                <div class="laptop-slide" data-slide-label="Equipment Inventory"
                                    data-slide-url="fittrack.app/admin/equipment">
                                    <img src="assets/landing/slide_equipment.png"
                                        alt="FitTrack Equipment — Inventory & Maintenance Tracking" loading="lazy">
                                </div>
                                <div class="laptop-slide" data-slide-label="Membership Plans"
                                    data-slide-url="fittrack.app/admin/plans">
                                    <img src="assets/landing/slide_plans.png"
                                        alt="FitTrack Plans — Membership Tier Configuration" loading="lazy">
                                </div>
                                <div class="laptop-slide" data-slide-label="Trainer Management"
                                    data-slide-url="fittrack.app/admin/trainers">
                                    <img src="assets/landing/slide_trainers.png"
                                        alt="FitTrack Trainers — Workout Coaching & Client Roster" loading="lazy">
                                </div>
                            </div>

                            <!-- Navigation Arrows (visible on hover) -->
                            <button class="laptop-nav-arrow laptop-nav-prev" onclick="changeLaptopSlide(-1)"
                                aria-label="Previous slide">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="15 18 9 12 15 6" />
                                </svg>
                            </button>
                            <button class="laptop-nav-arrow laptop-nav-next" onclick="changeLaptopSlide(1)"
                                aria-label="Next slide">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="9 18 15 12 9 6" />
                                </svg>
                            </button>

                            <!-- Feature Label Bar -->
                            <div class="laptop-feature-label" id="laptopFeatureLabel">
                                <span class="lfl-icon">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#84cc16"
                                        stroke-width="2">
                                        <rect x="3" y="3" width="18" height="18" rx="2" />
                                        <path d="M3 9h18" />
                                        <path d="M9 21V9" />
                                    </svg>
                                </span>
                                <span class="lfl-text">Gym Dashboard</span>
                            </div>
                        </div>

                        <!-- Laptop Base / Hinge -->
                        <div class="laptop-base">
                            <div class="laptop-hinge"></div>
                            <div class="laptop-bottom">
                                <div class="laptop-trackpad"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Dot Indicators -->
                    <div class="laptop-dots" id="laptopDots">
                        <button class="laptop-dot active" onclick="goToLaptopSlide(0)" aria-label="Dashboard"></button>
                        <button class="laptop-dot" onclick="goToLaptopSlide(1)" aria-label="Members"></button>
                        <button class="laptop-dot" onclick="goToLaptopSlide(2)" aria-label="Attendance"></button>
                        <button class="laptop-dot" onclick="goToLaptopSlide(3)" aria-label="Payments"></button>
                        <button class="laptop-dot" onclick="goToLaptopSlide(4)" aria-label="Equipment"></button>
                        <button class="laptop-dot" onclick="goToLaptopSlide(5)" aria-label="Plans"></button>
                        <button class="laptop-dot" onclick="goToLaptopSlide(6)" aria-label="Trainers"></button>
                    </div>


                </div>
            </div>
        </section>

        <!-- ==========================================================================
       09. HOW FITTRACK WORKS
       ========================================================================== -->
        <section class="has-parallax-orbs how-section reveal-on-scroll" id="how-it-works">
            <div class="parallax-orbs" aria-hidden="true">
                <span class="parallax-orb orb-lime" data-parallax="0.22"></span>
                <span class="parallax-orb orb-sky" data-parallax="-0.12"></span>
            </div>
            <div class="container">
                <div class="section-header">
                    <span class="section-label">Simple Onboarding</span>
                    <h2 class="section-title">How FitTrack Works</h2>
                    <p class="section-desc">Get your commercial facility up and running in 4 clear steps.</p>
                </div>

                <div class="steps-grid">
                    <div class="step-card">
                        <div class="step-num">01</div>
                        <h3 class="step-title">Register & Start Free Trial</h3>
                        <p class="step-desc">Submit your facility details and start your 14-day evaluation trial immediately upon approval. No upfront payment required.</p>
                    </div>

                    <div class="step-card">
                        <div class="step-num">02</div>
                        <h3 class="step-title">Set Up Workspace</h3>
                        <p class="step-desc">Configure membership plans, add coaching staff, and define facility access
                            rules.</p>
                    </div>

                    <div class="step-card">
                        <div class="step-num">03</div>
                        <h3 class="step-title">Manage Operations</h3>
                        <p class="step-desc">Enable QR turnstile verification, record payments, and assign workout plans.
                        </p>
                    </div>

                    <div class="step-card">
                        <div class="step-num">04</div>
                        <h3 class="step-title">Monitor Engagement</h3>
                        <p class="step-desc">Track member attendance trends and receive automatic inactivity alerts.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ==========================================================================
       10. USER EXPERIENCE BY USER TYPE
       ========================================================================== -->
        <section class="has-parallax-orbs users-section reveal-on-scroll">
            <div class="parallax-orbs" aria-hidden="true">
                <span class="parallax-orb orb-lime" data-parallax="0.22"></span>
                <span class="parallax-orb orb-sky" data-parallax="-0.12"></span>
            </div>
            <div class="container">
                <div class="section-header">
                    <span class="section-label">Tailored Interfaces</span>
                    <h2 class="section-title">Designed for Every User in Your Facility</h2>
                    <p class="section-desc">Customized experiences optimized for the specific tasks of owners, coaches, and
                        members.</p>
                </div>

                <div class="users-tabs-container">
                    <div class="users-tab-header">
                        <button class="user-tab-btn active" data-user="user-owners">For Gym Owners</button>
                        <button class="user-tab-btn" data-user="user-trainers">For Trainers</button>
                        <button class="user-tab-btn" data-user="user-members">For Members</button>
                    </div>

                    <div class="user-pane-content active" id="user-owners">
                        <div>
                            <h3 class="user-pane-title">Complete Business Oversight</h3>
                            <p class="user-pane-desc">
                                Monitor peak occupancy, membership revenue, staff commissions, and churn metrics from a
                                single executive dashboard.
                            </p>
                            <ul class="user-benefit-list">
                                <li class="user-benefit-item">
                                    <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                    Live turnstile capacity metrics
                                </li>
                                <li class="user-benefit-item">
                                    <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                    Subscription billing reports
                                </li>
                                <li class="user-benefit-item">
                                    <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                    Staff role permission management
                                </li>
                            </ul>
                        </div>
                        <img src="assets/landing/gym_management.png" alt="Gym Management"
                            style="border-radius: var(--radius-lg); border: 1px solid var(--border-medium);">
                    </div>

                    <div class="user-pane-content" id="user-trainers">
                        <div>
                            <h3 class="user-pane-title">Professional Client Management</h3>
                            <p class="user-pane-desc">
                                Build workout templates, create dietary plans, track client assessment metrics, and
                                communicate seamlessly.
                            </p>
                            <ul class="user-benefit-list">
                                <li class="user-benefit-item">
                                    <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                    Custom routine library builder
                                </li>
                                <li class="user-benefit-item">
                                    <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                    Calorie & macro calculator integration
                                </li>
                                <li class="user-benefit-item">
                                    <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                    Direct member progress tracking
                                </li>
                            </ul>
                        </div>
                        <img src="assets/landing/gym_trainer.png" alt="Trainer Workflows"
                            style="border-radius: var(--radius-lg); border: 1px solid var(--border-medium);">
                    </div>

                    <div class="user-pane-content" id="user-members">
                        <div>
                            <h3 class="user-pane-title">Frictionless Member Mobile Experience</h3>
                            <p class="user-pane-desc">
                                Scan dynamic QR codes at the gate, track workout logs, follow diet plans, and view live
                                equipment availability queues.
                            </p>
                            <ul class="user-benefit-list">
                                <li class="user-benefit-item">
                                    <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                    Instant QR check-in
                                </li>
                                <li class="user-benefit-item">
                                    <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                    Goal achievement & streak logs
                                </li>
                                <li class="user-benefit-item">
                                    <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                    Equipment queue status
                                </li>
                            </ul>
                        </div>
                        <img src="assets/landing/gym_member.png" alt="Member Mobile App"
                            style="border-radius: var(--radius-lg); border: 1px solid var(--border-medium);">
                    </div>
                </div>
            </div>
        </section>

        <!-- ==========================================================================
       11. TESTIMONIALS — INFINITE AUTO-SLIDING MARQUEE TICKER (6 COMMERCIAL REVIEWS)
       ========================================================================== -->
        <section class="testimonials-section reveal-on-scroll" id="testimonials">
            <div class="container">
                <div class="section-header">
                    <span class="section-label">Verified Gym Owner Ratings</span>
                    <h2 class="section-title">Trusted by Commercial Fitness Leaders</h2>
                    <p class="section-desc">See how FitTrack powers gym operations and client retention nationwide.</p>
                </div>
            </div>

            <?php if ($platformStats['total_reviews'] > 0): ?>
            <!-- Dynamic FitTrack Platform Rating Banner -->
            <div class="container" style="margin-bottom: 28px;">
                <div class="platform-rating-banner">
                    <div class="platform-banner-main">
                        <div class="platform-score-num">
                            <?= number_format($platformStats['avg_rating'], 1) ?>
                        </div>
                        <div class="platform-score-info">
                            <div class="platform-score-header">
                                <div class="platform-score-stars">
                                    <?= render_star_rating((float)$platformStats['avg_rating'], 18, false) ?>
                                </div>
                                <span class="platform-score-pill">
                                    FitTrack Platform Score
                                </span>
                            </div>
                            <div class="platform-score-meta">
                                Based on <strong><?= $platformStats['total_reviews'] ?></strong> verified gym owner <?= $platformStats['total_reviews'] === 1 ? 'review' : 'reviews' ?> &bull; 100% verified facility operators
                            </div>
                        </div>
                    </div>

                    <div class="platform-banner-actions">
                        <div class="platform-metrics-grid">
                            <div class="platform-metric-item">
                                <div class="platform-metric-val"><?= number_format($platformStats['avg_system'], 1) ?>★</div>
                                <div class="platform-metric-lbl">System UX</div>
                            </div>
                            <div class="platform-metric-divider"></div>
                            <div class="platform-metric-item">
                                <div class="platform-metric-val"><?= number_format($platformStats['avg_features'], 1) ?>★</div>
                                <div class="platform-metric-lbl">Features</div>
                            </div>
                            <div class="platform-metric-divider"></div>
                            <div class="platform-metric-item">
                                <div class="platform-metric-val"><?= number_format($platformStats['avg_service'], 1) ?>★</div>
                                <div class="platform-metric-lbl">Service &amp; Support</div>
                            </div>
                        </div>
                        <a href="index.php?page=gym_register" class="btn btn-lime btn-sm platform-banner-btn">
                            Register Gym &rarr;
                        </a>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- Fallback Banner when no ratings yet -->
            <div class="container" style="margin-bottom: 28px;">
                <div class="platform-rating-banner fallback">
                    <div class="platform-banner-main">
                        <div class="platform-fallback-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        </div>
                        <div class="platform-score-info">
                            <div class="platform-score-header">
                                <strong style="font-size:15px; color:#fff;">Authentic Gym Owner Reviews</strong>
                                <span class="platform-score-pill">Verified Only</span>
                            </div>
                            <p class="platform-score-meta" style="margin:0;">
                                We never publish fabricated reviews. All ratings are contributed exclusively by enrolled commercial gym owners.
                            </p>
                        </div>
                    </div>
                    <div class="platform-banner-actions">
                        <a href="index.php?page=gym_register" class="btn btn-lime btn-sm platform-banner-btn">
                            Join as Gym Partner &rarr;
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Gym Owner Reviews Ticker Track -->
            <?php
            // Prepare cards from real database reviews only
            $marqueeCards = [];
            foreach ($platformReviews as $pr) {
                if (!empty($pr['review'])) {
                    $marqueeCards[] = [
                        'rating' => (float)$pr['rating'],
                        'review' => $pr['review'],
                        'first_name' => $pr['first_name'] ?? 'Gym',
                        'last_name' => $pr['last_name'] ?? 'Owner',
                        'gym_name' => $pr['gym_name'] ?? 'Commercial Gym',
                        'system_experience' => $pr['system_experience'] ?? null,
                        'features_rating' => $pr['features_rating'] ?? null,
                        'service_rating' => $pr['service_rating'] ?? null,
                    ];
                }
            }
            ?>

            <?php if (!empty($marqueeCards)): ?>
                <?php
                // Duplicate cards to allow smooth continuous ticker sliding
                $displayCards = $marqueeCards;
                while (count($displayCards) < 6) {
                    $displayCards = array_merge($displayCards, $marqueeCards);
                }
                $displayCards = array_merge($displayCards, $displayCards); // duplicate for seamless 50% translateX loop
                ?>
                <div class="ticker-container">
                    <div class="ticker-track">
                        <?php foreach ($displayCards as $card): ?>
                            <div class="testimonial-card ticker-card" style="display:flex; flex-direction:column; justify-content:space-between;">
                                <div>
                                    <div class="ticker-card-header" style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
                                        <div style="display:inline-flex; align-items:center; flex-shrink:0;">
                                            <?= render_star_rating((float)($card['rating'] ?? 5), 14, false) ?>
                                        </div>
                                        <span class="ticker-verified-badge" style="display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:600; color:var(--lime, #c7ff22); background:rgba(199,255,34,0.08); border:1px solid rgba(199,255,34,0.2); padding:2px 8px; border-radius:999px; white-space:nowrap; flex-shrink:0;">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                            Verified Gym Owner
                                        </span>
                                    </div>
                                    <p class="quote-text" style="font-size:13.5px; line-height:1.55; color:rgba(255,255,255,0.88); margin-bottom:14px; font-style:italic;">
                                        &ldquo;<?= h($card['review']) ?>&rdquo;
                                    </p>
                                </div>
                                <div>
                                    <?php if (!empty($card['system_experience']) || !empty($card['features_rating'])): ?>
                                        <div style="display:flex; gap:8px; font-size:10.5px; color:var(--muted, #94a3b8); margin-bottom:12px; padding-bottom:10px; border-bottom:1px solid rgba(255,255,255,0.06);">
                                            <?php if (!empty($card['system_experience'])): ?>
                                                <span>UX: <strong style="color:#fff;"><?= (int)$card['system_experience'] ?>★</strong></span>
                                            <?php endif; ?>
                                            <?php if (!empty($card['features_rating'])): ?>
                                                <span>Features: <strong style="color:#fff;"><?= (int)$card['features_rating'] ?>★</strong></span>
                                            <?php endif; ?>
                                            <?php if (!empty($card['service_rating'])): ?>
                                                <span>Support: <strong style="color:#fff;"><?= (int)$card['service_rating'] ?>★</strong></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="author-info" style="display:flex; align-items:center; gap:10px;">
                                        <div class="author-avatar" style="width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg, rgba(199,255,34,0.25), rgba(56,189,248,0.25)); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:12px; color:#fff; border:1px solid rgba(255,255,255,0.12);">
                                            <?= strtoupper(substr($card['first_name'] ?? 'G', 0, 1) . substr($card['last_name'] ?? 'O', 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="author-name" style="font-weight:700; font-size:13.5px; color:#fff;">
                                                <?= h(($card['first_name'] ?? 'Gym') . ' ' . ($card['last_name'] ?? 'Owner')) ?>
                                            </div>
                                            <div class="author-role" style="font-size:11.5px; color:var(--muted, #94a3b8);">
                                                Gym Owner &bull; <?= h($card['gym_name'] ?? 'Commercial Fitness') ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <!-- Fallback Empty State when no testimonials submitted yet -->
                <div class="container" style="max-width: 680px; margin: 0 auto; text-align: center; padding: 44px 24px; background: rgba(255, 255, 255, 0.025); border: 1px dashed rgba(255, 255, 255, 0.12); border-radius: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(199, 255, 34, 0.08); border: 1px solid rgba(199, 255, 34, 0.2); display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; color: var(--lime, #c7ff22);">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    </div>
                    <h3 style="font-size: 18px; font-weight: 700; color: #fff; margin: 0 0 8px;">No Partner Testimonials Yet</h3>
                    <p style="font-size: 13.5px; color: #94a3b8; line-height: 1.6; max-width: 480px; margin: 0 auto 20px;">
                        FitTrack only displays real, verified feedback submitted by registered gym owners from their management portal. When our partners submit reviews, they will be showcased here.
                    </p>
                    <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                        <a href="index.php?page=gym_register" class="btn btn-lime btn-sm" style="text-decoration: none; padding: 8px 18px; font-size: 13px;">
                            Register Gym &rarr;
                        </a>
                        <a href="index.php?page=login" class="btn btn-secondary btn-sm" style="text-decoration: none; padding: 8px 18px; font-size: 13px; background: rgba(255,255,255,0.05); color: #fff; border: 1px solid rgba(255,255,255,0.12);">
                            Gym Owner Portal Login
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <!-- ==========================================================================
       11.8. FREQUENTLY ASKED QUESTIONS (FAQ)
       ========================================================================== -->
        <section class="faq-section reveal-on-scroll" id="faq">
            <div class="container">
                <div class="section-header">
                    <span class="section-label">Got Questions?</span>
                    <h2 class="section-title">Frequently Asked Questions</h2>
                    <p class="section-desc">Everything you need to know about setting up and running FitTrack in your facility.</p>
                </div>

                <div class="faq-accordion-wrap">
                    <div class="faq-item">
                        <button class="faq-question-btn" onclick="toggleFaq(this)">
                            <span>Do I need specialized turnstile hardware for QR scanning?</span>
                            <span class="faq-icon">+</span>
                        </button>
                        <div class="faq-answer">
                            <p>No expensive hardware is required! FitTrack runs seamlessly on any tablet, smartphone, or laptop camera placed at your front desk or gate. If you already have automated physical turnstile gates, FitTrack can integrate via standard webhooks.</p>
                        </div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question-btn" onclick="toggleFaq(this)">
                            <span>Can I import existing member records from Excel/CSV?</span>
                            <span class="faq-icon">+</span>
                        </button>
                        <div class="faq-answer">
                            <p>Yes! Our onboarding engine includes a 1-click CSV importer. You can upload your existing member roster, active membership plans, and contact details in seconds during workspace setup.</p>
                        </div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question-btn" onclick="toggleFaq(this)">
                            <span>What makes FitTrack different from other gym management software?</span>
                            <span class="faq-icon">+</span>
                        </button>
                        <div class="faq-answer">
                            <p>Unlike generic gym software, FitTrack combines real-time QR turnstile gate verification, rule-based member engagement scoring and inactivity alerts, equipment inventory maintenance, and dedicated trainer coaching workflows into a unified, dark-mode commercial platform.</p>
                        </div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question-btn" onclick="toggleFaq(this)">
                            <span>How does FitTrack detect members at risk of dropping out?</span>
                            <span class="faq-icon">+</span>
                        </button>
                        <div class="faq-answer">
                            <p>FitTrack uses a configurable, rule-based engagement score based on attendance, class participation, weekly consistency, completed workouts, and progress updates. Separately, inactivity alerts can notify staff when a member has gone without a check-in for the configured number of days. These tools highlight activity patterns for staff to review; they do not predict whether a member will cancel.</p>
                        </div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question-btn" onclick="toggleFaq(this)">
                            <span>Is there a free trial or contract lock-in?</span>
                            <span class="faq-icon">+</span>
                        </button>
                        <div class="faq-answer">
                            <p>None at all! Every approved facility begins with an automatic <strong>14-Day Free Trial (up to 50 active members & 2 trainers)</strong> with zero payment or credit card required. If you choose not to subscribe after 14 days, your facility gracefully transitions to our <strong>Limited Free Account (up to 25 members)</strong> so your daily turnstile check-ins and member operations never stop.</p>
                        </div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question-btn" onclick="toggleFaq(this)">
                            <span>Can trainers manage custom workouts and nutrition plans?</span>
                            <span class="faq-icon">+</span>
                        </button>
                        <div class="faq-answer">
                            <p>Yes! Trainers have a dedicated workspace portal where they can create personalized training split routines, assign macro dietary targets, track client exercise compliance, and message members directly.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ==========================================================================
       12. PRICING
       ========================================================================== -->
        <section class="has-parallax-orbs pricing-section reveal-on-scroll" id="pricing">
            <div class="parallax-orbs" aria-hidden="true">
                <span class="parallax-orb orb-lime" data-parallax="0.22"></span>
                <span class="parallax-orb orb-sky" data-parallax="-0.12"></span>
            </div>
            <div class="container">
                <div class="section-header">
                    <span class="section-label">Risk-Free Commercial Onboarding</span>
                    <h2 class="section-title">Plans Built to Scale With Your Gym</h2>
                    <p class="section-desc">Every commercial tier begins with an all-inclusive 14-day free trial. No upfront payment required. Upgrade anytime, or continue seamlessly on our Free Tier.</p>
                </div>

                <!-- 14-Day Free Trial & Free Tier Announcement Banner -->
                <div class="pricing-trial-highlight">
                    <div class="trial-highlight-left">
                        <div class="trial-highlight-badge">
                            <span class="pulse-dot"></span>
                            14-Day Free Trial Included
                        </div>
                        <h3 class="trial-highlight-heading">Evaluation Trial &bull; Zero Upfront Payment</h3>
                        <div class="trial-highlight-perks">
                            <div class="trial-perk-item">
                                <span class="trial-perk-check"><?= landing_icon('check', 'check-svg') ?></span>
                                <span><strong>14-Day Evaluation:</strong> Up to 50 active members &amp; 2 trainers included.</span>
                            </div>
                            <div class="trial-perk-item">
                                <span class="trial-perk-check"><?= landing_icon('check', 'check-svg') ?></span>
                                <span><strong>Zero Lockout:</strong> Fallback to our Limited Free Tier (25 members) anytime.</span>
                            </div>
                        </div>
                    </div>
                    <div class="trial-highlight-right">
                        <a href="index.php?page=gym_register" class="btn btn-lime btn-lg">
                            <span>Start 14-Day Free Trial</span>
                            <?= landing_icon('arrow-right', 'btn-svg') ?>
                        </a>
                        <span class="trial-note">No credit card required • Instant access</span>
                    </div>
                </div>

                <!-- Billing Cycle Toggle (Monthly vs Annual / Yearly) -->
                <div class="pricing-billing-switch-wrap">
                    <div class="pricing-billing-switch" id="landingBillingSwitch">
                        <button type="button" class="billing-switch-btn active" id="landingBillingMonthly" onclick="setLandingBillingCycle('monthly')">
                            Monthly Billing
                        </button>
                        <button type="button" class="billing-switch-btn" id="landingBillingYearly" onclick="setLandingBillingCycle('yearly')">
                            Annual / Yearly
                            <span class="billing-discount-badge">SAVE ~2 MONTHS</span>
                        </button>
                    </div>
                </div>

                <div class="pricing-grid" id="pricingGrid">
                    <?php foreach ($platformPlans as $pKey => $p): 
                        $isPop = !empty($p['popular']);
                        $annPrice = (float)($p['annual_price'] ?? round((float)$p['price'] * 10, 2));
                        $annSavings = max(0, ((float)$p['price'] * 12) - $annPrice);
                        $initialCount = 4;
                        $hasMore = count($p['features']) > $initialCount;
                        $initialFeatures = array_slice($p['features'], 0, $initialCount);
                        $extraFeatures = array_slice($p['features'], $initialCount);
                    ?>
                    <!-- <?= h($p['name']) ?> -->
                    <div class="pricing-card <?= $isPop ? 'popular' : '' ?>">
                        <?php if ($isPop): ?>
                            <span class="popular-badge">★ Most Popular</span>
                        <?php endif; ?>

                        <div class="plan-header-top">
                            <h3 class="plan-name"><?= h($p['name']) ?></h3>
                            <p class="plan-desc"><?= h($p['desc']) ?></p>
                        </div>

                        <div class="plan-price-box">
                            <span class="price-currency">₱</span>
                            <span class="price-val" id="landing-price-<?= h($pKey) ?>"><?= number_format((float)$p['price']) ?></span>
                            <span class="price-period" id="landing-period-<?= h($pKey) ?>">/ month</span>
                        </div>
                        <div class="plan-annual-savings" id="landing-savings-<?= h($pKey) ?>" style="display: none;">
                            Save ₱<?= number_format($annSavings) ?> with annual billing
                        </div>

                        <div class="plan-features-container">
                            <ul class="plan-features-list">
                                <?php foreach ($initialFeatures as $feat): ?>
                                    <li class="plan-feature-item">
                                        <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                        <span><?= h($feat) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                            <?php if ($hasMore): ?>
                                <div class="plan-features-collapsible" id="extra-features-<?= h($pKey) ?>" style="display: none;">
                                    <ul class="plan-features-list plan-features-extra">
                                        <?php foreach ($extraFeatures as $feat): ?>
                                            <li class="plan-feature-item">
                                                <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                                <span><?= h($feat) ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <button type="button" class="plan-features-toggle-btn" onclick="togglePlanFeatures('<?= h($pKey) ?>', this)">
                                    <span class="toggle-text">+ <?= count($extraFeatures) ?> more features</span>
                                    <svg class="toggle-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                </button>
                            <?php else: ?>
                                <div class="plan-features-toggle-placeholder"></div>
                            <?php endif; ?>
                        </div>

                        <div class="plan-card-actions">
                            <a href="index.php?page=gym_register&plan=<?= h($pKey) ?>" class="btn <?= $isPop ? 'btn-lime' : 'btn-outline' ?> btn-lg plan-card-trial-btn" style="width: 100%;">
                                Start 14-Day Free Trial
                            </a>
                            <div class="plan-card-subnote">14-Day Trial Included &bull; Upgrade Anytime</div>
                            <div class="plan-card-skip-trial">
                                <a href="index.php?page=gym_register&plan=<?= h($pKey) ?>&intent=subscribe" class="skip-trial-link" title="Skip trial and subscribe directly">
                                    <span>Skip trial &amp; subscribe now</span>
                                    <span class="skip-trial-arrow">&rarr;</span>
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pricing Carousel Dot Indicators for Mobile -->
                <div class="pricing-dots" id="pricingDots">
                    <button class="pricing-dot" aria-label="Starter Plan" onclick="scrollToPricingPlan(0)"></button>
                    <button class="pricing-dot active" aria-label="Professional Plan" onclick="scrollToPricingPlan(1)"></button>
                    <button class="pricing-dot" aria-label="Business Plan" onclick="scrollToPricingPlan(2)"></button>
                </div>

                <!-- Plan Distribution Trigger CTA Button -->
                <div class="pricing-distribution-cta">
                    <button type="button" class="btn-plan-distribution" onclick="openPlanDistributionModal()" id="btn-open-plan-distribution">
                        <span class="btn-distribution-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>
                            </svg>
                        </span>
                        <span class="btn-distribution-text">Compare Complete Plan Distribution & Capabilities</span>
                        <svg class="btn-distribution-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <polyline points="9 18 15 12 9 6"></polyline>
                        </svg>
                    </button>
                </div>

                <div class="pricing-bottom-cue">
                    <span>Need multi-branch licensing or a custom walkthrough?</span>
                    <a href="javascript:void(0)" onclick="openDemoModal()" class="pricing-demo-link">
                        Request a Personalized Demo →
                    </a>
                </div>
            </div>
        </section>

        <!-- ==========================================================================
       13. FINAL CTA & FOOTER
       ========================================================================== -->
        <section class="cta-section reveal-on-scroll">
            <div class="container">
                <div class="cta-box">
                    <div class="parallax-layer cta-bg" data-parallax="0.14" aria-hidden="true"></div>
                    <h2 class="cta-title">Ready to bring your gym operations together?</h2>
                    <p class="cta-desc">
                        Manage your gym, members, trainers, payments, and engagement with FitTrack.
                    </p>
                    <div class="cta-btn-group">
                        <a href="index.php?page=gym_register" class="btn btn-lime btn-lg">
                            <span>Register Your Gym</span>
                            <?= landing_icon('arrow-right', 'btn-svg') ?>
                        </a>
                        <button class="btn btn-secondary btn-lg" onclick="openDemoModal()">
                            <?= landing_icon('play', 'btn-svg') ?>
                            <span>Request a Demo</span>
                        </button>
                    </div>
                    <div class="cta-micro-cues">
                        <span class="cta-cue-item">
                            <span class="cue-check"><?= landing_icon('check', 'check-svg') ?></span>
                            14-Day Free Trial Included
                        </span>
                        <span class="cta-cue-bullet">·</span>
                        <span class="cta-cue-item">
                            <span class="cue-check"><?= landing_icon('check', 'check-svg') ?></span>
                            No credit card required
                        </span>
                        <span class="cta-cue-bullet">·</span>
                        <span class="cta-cue-item">
                            <span class="cue-check"><?= landing_icon('check', 'check-svg') ?></span>
                            Free Tier fallback anytime
                        </span>
                    </div>
                </div>
            </div>
        </section>

        <!-- SaaS Footer -->
        <footer class="saas-footer">
            <div class="container">
                <div class="footer-grid">
                    <div class="footer-brand-col">
                        <a href="#" class="brand-logo-wrap">
                            <div class="brand-f-logo">F</div>
                            <div class="brand-text-block">
                                <div class="brand-logotype">
                                    <span class="brand-fit">FIT</span><span class="brand-track">TRACK</span>
                                </div>
                                <span class="brand-tagline">MANAGE. ENGAGE. GROW.</span>
                            </div>
                        </a>
                        <p class="footer-brand-desc">
                            A data-driven web application for gym operations and member engagement monitoring. Built for
                            modern commercial fitness centers.
                        </p>
                    </div>

                    <div>
                        <h4 class="footer-col-title">Platform</h4>
                        <ul class="footer-links">
                            <li><a href="#features" class="footer-link">Member Management</a></li>
                            <li><a href="#features" class="footer-link">Dynamic QR Access</a></li>
                            <li><a href="#features" class="footer-link">Trainer Coaching</a></li>
                            <li><a href="#features" class="footer-link">Engagement Monitoring</a></li>
                        </ul>
                    </div>

                    <div>
                        <h4 class="footer-col-title">Resources</h4>
                        <ul class="footer-links">
                            <li><a href="#how-it-works" class="footer-link">How It Works</a></li>
                            <li><a href="#pricing" class="footer-link">Pricing Plans</a></li>
                            <li><a href="#testimonials" class="footer-link">Customer Stories</a></li>
                        </ul>
                    </div>

                    <div>
                        <h4 class="footer-col-title">Account</h4>
                        <ul class="footer-links">
                            <li><a href="index.php?page=login" class="footer-link">Log In</a></li>
                            <li><a href="index.php?page=register" class="footer-link">Join as Member</a></li>
                            <li><a href="index.php?page=gym_register" class="footer-link">Register Gym</a></li>
                            <li><a href="javascript:void(0)" onclick="openDemoModal()" class="footer-link">Request Demo</a>
                            </li>
                        </ul>
                    </div>

                    <div>
                        <h4 class="footer-col-title">Legal</h4>
                        <ul class="footer-links">
                            <li><a href="index.php?page=privacy" class="footer-link">Privacy Policy</a></li>
                            <li><a href="index.php?page=terms" class="footer-link">Terms of Service</a></li>
                            <li><a href="index.php?page=privacy#security" class="footer-link">Security Compliance</a></li>
                        </ul>
                    </div>
                </div>

                <div class="footer-bottom">
                    <div>© <?= date('Y') ?> FitTrack Systems. All rights reserved.</div>
                    <div>Commercial Gym Operations Engine</div>
                </div>
            </div>
        </footer>

        <!-- ==========================================================================
       SUBSCRIPTION PLAN DISTRIBUTION MODAL
       ========================================================================== -->
        <div class="modal-backdrop" id="planDistributionModalBackdrop" onclick="handleDistributionBackdropClick(event)">
            <div class="modal-card distribution-modal-card">
                <div class="modal-header distribution-modal-header">
                    <div>
                        <div class="distribution-eyebrow">
                            <span class="dot"></span>
                            Commercial SaaS Capabilities
                        </div>
                        <h3 class="modal-title">Subscription Plan Distribution</h3>
                    </div>
                    <button class="modal-close-btn" onclick="closePlanDistributionModal()" aria-label="Close modal">
                        <?= landing_icon('x', 'close-icon-svg') ?>
                    </button>
                </div>

                <div class="modal-body distribution-modal-body">
                    <p class="distribution-intro">
                        Review the exact capability distribution across our commercial gym tiers. Every approved facility starts with a complimentary <strong>14-day evaluation trial (50 members & 2 trainers)</strong> with zero payment required, and graceful fallback to our <strong>Limited Free Account (25 members)</strong>.
                    </p>
                    <!-- Mobile Comparison Controls -->
                    <div class="dist-mobile-controls">
                        <div class="dist-swipe-hint">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8L22 12L18 16"/><path d="M6 8L2 12L6 16"/><path d="M2 12H22"/></svg>
                            <span>Swipe horizontally to compare tiers</span>
                        </div>
                        <div class="dist-mobile-tabs">
                            <button type="button" class="dist-mobile-tab-btn active" onclick="jumpToDistTier(0, this)">All Plans</button>
                            <button type="button" class="dist-mobile-tab-btn" onclick="jumpToDistTier(1, this)">Starter</button>
                            <button type="button" class="dist-mobile-tab-btn" onclick="jumpToDistTier(2, this)">★ Pro</button>
                            <button type="button" class="dist-mobile-tab-btn" onclick="jumpToDistTier(3, this)">Business</button>
                        </div>
                    </div>

                    <div class="distribution-table-wrap" id="distTableWrap">
                        <table class="distribution-table">
                            <thead>
                                <tr>
                                    <th class="col-cap">Capability / Module</th>
                                    <th class="col-tier starter">
                                        <div class="tier-head">
                                            <span class="tier-name"><?= h($starterPlan['name']) ?></span>
                                            <span class="tier-price"><?= h($starterPlan['price_label']) ?><small>/mo</small></span>
                                        </div>
                                    </th>
                                    <th class="col-tier popular">
                                        <div class="tier-head">
                                            <span class="popular-tag">★ Most Popular</span>
                                            <span class="tier-name"><?= h($proPlan['name']) ?></span>
                                            <span class="tier-price"><?= h($proPlan['price_label']) ?><small>/mo</small></span>
                                        </div>
                                    </th>
                                    <th class="col-tier business">
                                        <div class="tier-head">
                                            <span class="tier-name"><?= h($businessPlan['name']) ?></span>
                                            <span class="tier-price"><?= h($businessPlan['price_label']) ?><small>/mo</small></span>
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Category 1 -->
                                <tr class="dist-cat-row">
                                    <td colspan="4">01 — Target Profile & Capacity Limits</td>
                                </tr>
                                <tr>
                                    <td><strong>Target Facility Scale</strong></td>
                                    <td><span class="dist-pill muted">Boutique / Solo</span></td>
                                    <td class="col-popular"><span class="dist-pill lime">Growing Commercial</span></td>
                                    <td><span class="dist-pill purple">High-Volume Gym</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Active Member Capacity</strong></td>
                                    <td><span class="dist-text-highlight">Up to 100 Members</span></td>
                                    <td class="col-popular"><span class="dist-text-lime">Up to 500 Members</span></td>
                                    <td><span class="dist-text-purple">Unlimited Members</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Trainer Capacity</strong></td>
                                    <td><span class="status-locked">Locked</span></td>
                                    <td class="col-popular"><span class="dist-text-lime">Unlimited Trainers</span></td>
                                    <td><span class="dist-text-purple">Unlimited Trainers</span></td>
                                </tr>

                                <!-- Category 2 -->
                                <tr class="dist-cat-row">
                                    <td colspan="4">02 — Core Operations & Access Control</td>
                                </tr>
                                <tr>
                                    <td>Walk-In & Daily Pass Management</td>
                                    <td><span class="status-access">✓ Full Access</span></td>
                                    <td class="col-popular"><span class="status-access">✓ Full Access</span></td>
                                    <td><span class="status-access">✓ Full Access</span></td>
                                </tr>
                                <tr>
                                    <td>Dynamic QR Check-in & Attendance</td>
                                    <td><span class="status-access">✓ Full Access</span></td>
                                    <td class="col-popular"><span class="status-access">✓ Full Access</span></td>
                                    <td><span class="status-access">✓ Full Access</span></td>
                                </tr>
                                <tr>
                                    <td>Membership Plans & Online GCash Pay</td>
                                    <td><span class="status-access">✓ Full Access</span></td>
                                    <td class="col-popular"><span class="status-access">✓ Full Access</span></td>
                                    <td><span class="status-access">✓ Full Access</span></td>
                                </tr>

                                <!-- Category 3 -->
                                <tr class="dist-cat-row">
                                    <td colspan="4">03 — Staff Coaching & Training Programs</td>
                                </tr>
                                <tr>
                                    <td>Trainers & Client Assignments</td>
                                    <td><span class="dist-pill muted">0 Trainers (Solo Owner)</span></td>
                                    <td class="col-popular"><span class="status-access">✓ Full Access (Unlimited)</span></td>
                                    <td><span class="status-access">✓ Full Access (Unlimited)</span></td>
                                </tr>
                                <tr>
                                    <td>Trainer Commission Tracking & Payouts</td>
                                    <td><span class="status-locked">🔒 Locked</span></td>
                                    <td class="col-popular"><span class="status-access">✓ Full Access</span></td>
                                    <td><span class="status-access">✓ Full Access</span></td>
                                </tr>
                                <tr>
                                    <td>Workout Plans & Exercise Library</td>
                                    <td><span class="status-locked">🔒 Locked</span></td>
                                    <td class="col-popular"><span class="status-access">✓ Full Access</span></td>
                                    <td><span class="status-access">✓ Full Access</span></td>
                                </tr>

                                <!-- Category 4 -->
                                <tr class="dist-cat-row">
                                    <td colspan="4">04 — Group Classes & Online Booking</td>
                                </tr>
                                <tr>
                                    <td>Class Scheduling & Member Bookings</td>
                                    <td><span class="dist-pill muted">Basic Schedule</span></td>
                                    <td class="col-popular"><span class="status-access">✓ Full Booking + Waitlists</span></td>
                                    <td><span class="status-access">✓ Full Booking + Waitlists</span></td>
                                </tr>

                                <!-- Category 5 -->
                                <tr class="dist-cat-row">
                                    <td colspan="4">05 — Member Retention & Automated Reminders</td>
                                </tr>
                                <tr>
                                    <td>Automated Renewal Reminders</td>
                                    <td><span class="dist-pill muted">7-Day Alert</span></td>
                                    <td class="col-popular"><span class="dist-pill sky">Automated (30d/14d/7d/1d)</span></td>
                                    <td><span class="dist-pill purple">Automated (30d/14d/7d/1d)</span></td>
                                </tr>
                                <tr>
                                    <td>Member Engagement Scores & Inactivity Alerts</td>
                                    <td><span class="status-locked">🔒 Locked</span></td>
                                    <td class="col-popular"><span class="status-access">✓ Engagement Scores & Inactivity Alerts</span></td>
                                    <td><span class="status-access">✓ Engagement Scores & Inactivity Alerts</span></td>
                                </tr>

                                <!-- Category 6 -->
                                <tr class="dist-cat-row">
                                    <td colspan="4">06 — Financial Reports & Governance</td>
                                </tr>
                                <tr>
                                    <td>Dashboard Analytics & Operational KPIs</td>
                                    <td><span class="dist-pill muted">Basic KPIs</span></td>
                                    <td class="col-popular"><span class="dist-pill sky">Advanced Charts & Trends</span></td>
                                    <td><span class="dist-pill purple">Advanced Charts & Trends</span></td>
                                </tr>
                                <tr>
                                    <td>Financial Reports & Data CSV Export</td>
                                    <td><span class="dist-pill muted">Summary Export</span></td>
                                    <td class="col-popular"><span class="dist-pill sky">Advanced Trends & CSV</span></td>
                                    <td><span class="dist-pill purple">Advanced Trends & CSV</span></td>
                                </tr>
                                <tr>
                                    <td>End-of-Day Summary Emails</td>
                                    <td><span class="status-locked">Locked</span></td>
                                    <td class="col-popular"><span class="status-locked">Locked</span></td>
                                    <td><span class="status-access">✓ Available</span></td>
                                </tr>
                                <tr>
                                    <td>Custom Gym Brand Color</td>
                                    <td><span class="status-locked">🔒 Locked</span></td>
                                    <td class="col-popular"><span class="status-locked">🔒 Locked</span></td>
                                    <td><span class="status-access">✓ Full Access</span></td>
                                </tr>
                                <tr>
                                    <td>Staff Financial Visibility Controls</td>
                                    <td><span class="status-locked">🔒 Locked</span></td>
                                    <td class="col-popular"><span class="status-locked">🔒 Locked</span></td>
                                    <td><span class="status-access">✓ Full Access</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="distribution-modal-footer">
                    <div class="dist-footer-ctas">
                        <a href="index.php?page=gym_register&plan=starter" class="btn btn-outline btn-sm">
                            <span class="btn-text-full">Get <?= h($starterPlan['name']) ?> (<?= h($starterPlan['price_label']) ?>)</span>
                            <span class="btn-text-mobile">Starter (<?= h($starterPlan['price_label']) ?>)</span>
                        </a>
                        <a href="index.php?page=gym_register&plan=professional" class="btn btn-lime btn-sm">
                            <span class="btn-text-full">Get <?= h($proPlan['name']) ?> (<?= h($proPlan['price_label']) ?>)</span>
                            <span class="btn-text-mobile">★ Pro (<?= h($proPlan['price_label']) ?>)</span>
                        </a>
                        <a href="index.php?page=gym_register&plan=business" class="btn btn-outline btn-sm">
                            <span class="btn-text-full">Get <?= h($businessPlan['name']) ?> (<?= h($businessPlan['price_label']) ?>)</span>
                            <span class="btn-text-mobile">Business (<?= h($businessPlan['price_label']) ?>)</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ==========================================================================
       DEMO REQUEST MODAL
       ========================================================================== -->
        <div class="modal-backdrop" id="demoModalBackdrop" onclick="handleBackdropClick(event)">
            <div class="modal-card">
                <div class="modal-header">
                    <h3 class="modal-title">Request a Live FitTrack Demo</h3>
                    <button class="modal-close-btn" onclick="closeDemoModal()" aria-label="Close modal">
                        <?= landing_icon('x', 'close-icon-svg') ?>
                    </button>
                </div>

                <div class="modal-body">
                    <form id="demoForm" onsubmit="submitDemoForm(event)">
                        <?= csrf_field() ?>
                        <input type="hidden" name="demo_request" value="1">

                        <div id="modalAlert"
                            style="display: none; padding: 0.75rem; border-radius: 6px; font-size: 0.875rem; margin-bottom: 1rem;">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="full_name" class="form-input" placeholder="e.g. Alex Rivera" required>
                        </div>

                        <div class="form-grid-2">
                            <div class="form-group">
                                <label class="form-label">Work Email *</label>
                                <input type="email" name="work_email" class="form-input" placeholder="alex@yourgym.com"
                                    required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Mobile Number *</label>
                                <input type="tel" name="phone" class="form-input" placeholder="09123456789" required>
                            </div>
                        </div>

                        <div class="form-grid-2">
                            <div class="form-group">
                                <label class="form-label">Gym Name</label>
                                <input type="text" name="gym_name" class="form-input" placeholder="Titan Fitness">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Approx. Members</label>
                                <select name="approx_members" class="form-select">
                                    <option value="1-150">1 - 150 members</option>
                                    <option value="151-500">151 - 500 members</option>
                                    <option value="500+">500+ members</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Your Role</label>
                            <select name="role" class="form-select">
                                <option value="Gym Owner">Gym Owner</option>
                                <option value="General Manager">General Manager</option>
                                <option value="Head Trainer">Head Trainer</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Message / Specific Questions</label>
                            <textarea name="message" class="form-input" rows="2"
                                placeholder="Tell us about your gym setup..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-lime" id="submitDemoBtn" style="width: 100%;">
                            Submit Demo Request
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ==========================================================================
       VANILLA JAVASCRIPT LOGIC, 3D TILT EFFECT & SCROLL OBSERVER
       ========================================================================== -->
        <!-- Back to Top Floating Button -->
        <button type="button" id="backToTopBtn" class="back-to-top" aria-label="Back to top" title="Back to top">
            <span class="back-to-top-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="18 15 12 9 6 15"></polyline>
                </svg>
            </span>
            <span class="back-to-top-text">Back to Top</span>
        </button>


        <!-- Landing Page Configuration & Script -->
        <script>
        window.LANDING_CONFIG = {
            plansData: <?= json_encode($platformPlans, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
        };
        </script>
        <script src="<?= h(asset_url('js/pages/landing.js')) ?>"></script>
        <script src="<?= h(asset_url('js/pages/landing-hero.js')) ?>"></script>
        <script src="<?= h(asset_url('js/pages/landing-architecture.js')) ?>"></script>

    </body>

    </html>
    <?php
}
