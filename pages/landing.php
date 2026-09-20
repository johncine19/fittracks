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

    // ── Platform Subscription Plans ──────────────────────────────────
    $platformPlans = get_platform_subscription_plans();
    $starterPlan = $platformPlans['starter'] ?? [
        'name' => 'Starter',
        'price' => 599,
        'price_label' => '₱599',
        'desc' => 'Ideal for boutique fitness studios and single-location facilities.',
        'popular' => false,
        'features' => ['Up to 100 Active Members', 'Walk-in Management & Daily Pass', 'Dynamic QR Check-in & Scanner', 'Membership Plans & GCash / Online Pay', 'Basic Expiration Reminders (7-Day Notice)', 'Basic Financial Reports & CSV Export', 'Basic Activity History']
    ];
    $proPlan = $platformPlans['professional'] ?? [
        'name' => 'Professional',
        'price' => 999,
        'price_label' => '₱999',
        'desc' => 'Designed for growing commercial gyms with full coaching staff.',
        'popular' => true,
        'features' => ['Up to 500 Active Members', 'Personal Trainers & Client Assignments', 'Trainer Commission Tracking & Payouts', 'Workout Plans & Exercise Library', 'Class Scheduling & Online Booking with Waitlists', 'Automated Multi-Stage Renewal Reminders (30d/14d/7d/1d)', 'Member Engagement Scoring & Churn Risk Alerts', 'Advanced Analytics & Financial Growth Trends', 'Staff Activity Logs']
    ];
    $businessPlan = $platformPlans['business'] ?? [
        'name' => 'Business',
        'price' => 1999,
        'price_label' => '₱1,999',
        'desc' => 'For multi-branch & large-scale fitness centers.',
        'popular' => false,
        'features' => ['Unlimited Active Members', 'Multi-Branch Management & Centralized Dashboard', 'Consolidated Cross-Branch Financial Reporting', 'Full Compliance Security Audit Trail (IPs, Diffs)', 'Custom App Brand Color & White-Label Theme', 'Dedicated Account Manager & Priority Support']
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

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link
            href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,700;1,900&display=swap"
            rel="stylesheet">
        <link rel="stylesheet" href="assets/landing.css?v=<?= filemtime(__DIR__ . '/../assets/landing.css') ?>">
    </head>

    <body>

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
                    <a href="index.php?page=login" class="nav-auth-link">Log In</a>
                    <a href="javascript:void(0)" onclick="openDemoModal()" class="nav-demo-link">Request Demo</a>
                    <a href="index.php?page=gym_onboarding" class="btn btn-lime btn-sm">Register Gym</a>
                </div>

                <button class="mobile-toggle" id="mobileMenuBtn" aria-label="Toggle Navigation">
                    <?= landing_icon('menu', 'mobile-toggle-icon') ?>
                </button>
            </div>
        </nav>

        <!-- Mobile Menu Drawer -->
        <div class="mobile-drawer" id="mobileDrawer">
            <a href="#features" class="mobile-drawer-link" onclick="closeMobileMenu()">Features</a>
            <a href="#how-it-works" class="mobile-drawer-link" onclick="closeMobileMenu()">How It Works</a>
            <a href="#testimonials" class="mobile-drawer-link" onclick="closeMobileMenu()">Testimonials</a>
            <a href="#pricing" class="mobile-drawer-link" onclick="closeMobileMenu()">Pricing</a>
            <a href="#faq" class="mobile-drawer-link" onclick="closeMobileMenu()">FAQ</a>
            <a href="index.php?page=login" class="mobile-drawer-link" onclick="closeMobileMenu()">Log In</a>
            <a href="javascript:void(0)" class="mobile-drawer-link" onclick="closeMobileMenu(); openDemoModal();">Request Demo Walkthrough</a>
            <a href="index.php?page=gym_onboarding" class="btn btn-lime mobile-drawer-cta" onclick="closeMobileMenu()">Register Your Gym</a>
        </div>

        <!-- Ambient Lighting Glow Orbs -->
        <div class="ambient-glow glow-top"></div>
        <div class="ambient-glow glow-hero"></div>

        <!-- ==========================================================================
       02. HERO SECTION — FULL-BLEED GYM BACKGROUND WITH FLOATING ENGINE
       ========================================================================== -->
        <header class="hero-section reveal-on-scroll">
            <div class="container">
                <div class="hero-grid">
                    <!-- Left Side: Brand Narrative & Feature Pills Floating directly on Background -->
                    <div class="hero-left">
                        <div class="hero-brand-header">
                            <h1 class="hero-title">
                                Smarter Gym Management.
                                <span class="title-highlight">Stronger Community.</span>
                            </h1>
                            <p class="hero-subtitle">
                                FitTrack helps gyms streamline operations, monitor member engagement, dynamic QR
                                attendance, and drive growth.
                            </p>
                        </div>

                        <div class="hero-feature-pills">
                            <div class="hero-feature-pill">
                                <div class="pill-icon"><?= landing_icon('chart', 'icon-svg') ?></div>
                                <div>
                                    <div class="pill-title">Track Attendance</div>
                                    <div class="pill-sub">Monitor member check-ins and floor activity in real time.</div>
                                </div>
                            </div>

                            <div class="hero-feature-pill">
                                <div class="pill-icon"><?= landing_icon('zap', 'icon-svg') ?></div>
                                <div>
                                    <div class="pill-title">Engage Members</div>
                                    <div class="pill-sub">Automated churn warnings flag inactive members early.</div>
                                </div>
                            </div>
                        </div>

                        <div class="hero-cta-block">
                            <div class="hero-ctas">
                                <a href="index.php?page=gym_onboarding" class="btn btn-lime btn-lg">
                                    <span>Register Your Gym</span>
                                    <?= landing_icon('arrow-right', 'btn-svg') ?>
                                </a>
                                <button class="btn btn-secondary btn-lg" onclick="openDemoModal()">
                                    <?= landing_icon('play', 'btn-svg') ?>
                                    <span>Request a Demo</span>
                                </button>
                            </div>
                            <div class="hero-cta-cues">
                                <span class="cta-cue-item">
                                    <span class="cue-check"><?= landing_icon('check', 'check-svg') ?></span>
                                    Instant 2-min setup
                                </span>
                                <span class="cta-cue-bullet">·</span>
                                <span class="cta-cue-item">
                                    <span class="cue-check"><?= landing_icon('check', 'check-svg') ?></span>
                                    No credit card required
                                </span>
                                <span class="cta-cue-bullet">·</span>
                                <span class="cta-cue-item">
                                    <span class="cue-check"><?= landing_icon('check', 'check-svg') ?></span>
                                    Cancel anytime
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Right Side: Realistic Laptop Device Showcase with Auto-Slide & Hover Arrows -->
                    <div class="hero-right">
                        <div class="hero-laptop-container" id="heroLaptopContainer">
                            <div class="hero-laptop-frame">
                                <div class="hero-laptop-bezel">
                                    <div class="hero-laptop-camera"></div>
                                    <div class="hero-laptop-screen">
                                        <!-- Top Browser Chrome Bar -->
                                        <div class="hero-laptop-browser">
                                            <div class="browser-dots">
                                                <span class="dot dot-red"></span>
                                                <span class="dot dot-yellow"></span>
                                                <span class="dot dot-green"></span>
                                            </div>
                                            <div class="hero-laptop-url">
                                                <?= landing_icon('shield', 'url-shield-icon') ?>
                                                <span id="heroLaptopUrl">fittrack.app/admin/dashboard</span>
                                            </div>
                                            <div class="hero-laptop-live-badge">
                                                <span class="pulse-dot"></span> LIVE ENGINE
                                            </div>
                                        </div>

                                        <!-- Laptop Viewport Content & Image Slides -->
                                        <div class="hero-laptop-viewport" id="heroLaptopViewport">
                                            <!-- Subtle Hover Prev/Next Arrows -->
                                            <button class="hero-slider-arrow prev" onclick="changeHeroLaptopSlide(-1)"
                                                aria-label="Previous Feature">
                                                <?= landing_icon('arrow-left', 'arrow-icon-svg') ?>
                                            </button>
                                            <button class="hero-slider-arrow next" onclick="changeHeroLaptopSlide(1)"
                                                aria-label="Next Feature">
                                                <?= landing_icon('arrow-right', 'arrow-icon-svg') ?>
                                            </button>

                                            <!-- Image Slide 0: Gym Operations Dashboard -->
                                            <div class="hero-laptop-slide active" data-url="fittrack.app/admin/dashboard"
                                                data-label="Gym Operations Dashboard">
                                                <img src="assets/landing/slide_dashboard.png"
                                                    alt="FitTrack Live Gym Operations Dashboard" loading="eager">
                                            </div>

                                            <!-- Image Slide 1: Member Roster -->
                                            <div class="hero-laptop-slide" data-url="fittrack.app/admin/members"
                                                data-label="Member Management Roster">
                                                <img src="assets/landing/slide_members.png"
                                                    alt="FitTrack Active Member Directory" loading="lazy">
                                            </div>

                                            <!-- Image Slide 2: QR Turnstile Attendance -->
                                            <div class="hero-laptop-slide" data-url="fittrack.app/admin/attendance"
                                                data-label="Dynamic QR Turnstile Stream">
                                                <img src="assets/landing/slide_attendance.png"
                                                    alt="FitTrack Turnstile Gate Verification" loading="lazy">
                                            </div>

                                            <!-- Image Slide 3: Payment Ledger & Invoices -->
                                            <div class="hero-laptop-slide" data-url="fittrack.app/admin/payments"
                                                data-label="Payment Ledger & Invoices">
                                                <img src="assets/landing/slide_payments.png"
                                                    alt="FitTrack Payment Transactions & Invoices" loading="lazy">
                                            </div>

                                            <!-- Image Slide 4: Equipment Inventory -->
                                            <div class="hero-laptop-slide" data-url="fittrack.app/admin/equipment"
                                                data-label="Equipment Inventory & Maintenance">
                                                <img src="assets/landing/slide_equipment.png"
                                                    alt="FitTrack Equipment Inventory" loading="lazy">
                                            </div>

                                            <!-- Image Slide 5: Membership Plans -->
                                            <div class="hero-laptop-slide" data-url="fittrack.app/admin/plans"
                                                data-label="Membership Tier Configurations">
                                                <img src="assets/landing/slide_plans.png"
                                                    alt="FitTrack Membership Plan Configuration" loading="lazy">
                                            </div>

                                            <!-- Image Slide 6: Trainer & Workout Management -->
                                            <div class="hero-laptop-slide" data-url="fittrack.app/admin/trainers"
                                                data-label="Trainer & Workout Coaching">
                                                <img src="assets/landing/slide_trainers.png"
                                                    alt="FitTrack Trainer Management & Workloads" loading="lazy">
                                            </div>
                                        </div>

                                        <!-- Feature Label Overlay -->
                                        <div class="hero-feature-label-overlay" id="heroFeatureOverlay">
                                            <span class="hflo-pulse"></span>
                                            <span class="hflo-text">Gym Operations Dashboard</span>
                                        </div>
                                    </div>

                                    <!-- Laptop Base & Hinge -->
                                    <div class="hero-laptop-hinge"></div>
                                    <div class="hero-laptop-base">
                                        <div class="hero-laptop-notch"></div>
                                    </div>
                                </div>

                                <!-- Laptop Navigation Dots -->
                                <div class="hero-laptop-dots" id="heroLaptopDots">
                                    <span class="hero-dot active" onclick="goToHeroLaptopSlide(0)"></span>
                                    <span class="hero-dot" onclick="goToHeroLaptopSlide(1)"></span>
                                    <span class="hero-dot" onclick="goToHeroLaptopSlide(2)"></span>
                                    <span class="hero-dot" onclick="goToHeroLaptopSlide(3)"></span>
                                    <span class="hero-dot" onclick="goToHeroLaptopSlide(4)"></span>
                                    <span class="hero-dot" onclick="goToHeroLaptopSlide(5)"></span>
                                    <span class="hero-dot" onclick="goToHeroLaptopSlide(6)"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Scroll to Explore Indicator in Bottom Center -->
                <div class="scroll-explore-wrap">
                    <span class="scroll-explore-text">SCROLL TO EXPLORE</span>
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

                <div class="problem-summary-card">
                    <span class="summary-tag">The FitTrack Engine</span>
                    <h3 class="summary-headline">FitTrack brings every operational thread together into a single live
                        system.</h3>
                    <p class="summary-body">
                        From automated turnstile QR scanning to live trainer assignments and financial reporting, FitTrack
                        eliminates administrative friction so your team can focus on member retention and growth.
                    </p>
                </div>
            </div>
        </section>

        <!-- ==========================================================================
       05. THE FITTRACK SOLUTION
       ========================================================================== -->
        <section class="solution-section reveal-on-scroll">
            <div class="container">
                <div class="section-header">
                    <span class="section-label">Unified Architecture</span>
                    <h2 class="section-title">One Platform. Total Connectivity.</h2>
                    <p class="section-desc">Observe how FitTrack unifies every aspect of your facility into one smooth
                        digital workflow.</p>
                </div>

                <div class="solution-flow">
                    <div class="flow-node">
                        <div class="flow-node-icon"><?= landing_icon('building', 'icon-svg') ?></div>
                        <div class="flow-node-title">Your Facility</div>
                        <div class="flow-node-desc">Front Desk & Floor</div>
                    </div>

                    <div class="flow-arrow"><?= landing_icon('arrow-right', 'arrow-svg') ?></div>

                    <div class="flow-node flow-hub">
                        <div class="flow-node-icon"><?= landing_icon('zap', 'icon-svg') ?></div>
                        <div class="flow-node-title">FITTRACK HUB</div>
                        <div class="flow-node-desc">Central Core Engine</div>
                    </div>

                    <div class="flow-arrow"><?= landing_icon('arrow-right', 'arrow-svg') ?></div>

                    <div class="flow-node">
                        <div class="flow-node-icon"><?= landing_icon('chart', 'icon-svg') ?></div>
                        <div class="flow-node-title">Live Insights</div>
                        <div class="flow-node-desc">Operations & Revenue</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ==========================================================================
       06. CORE FEATURES
       ========================================================================== -->
        <section class="features-section reveal-on-scroll" id="features">
            <div class="container">
                <div class="section-header">
                    <span class="section-label">Platform Capabilities</span>
                    <h2 class="section-title">Comprehensive Tools Built for Gym Growth</h2>
                    <p class="section-desc">Designed to handle real commercial gym workloads with precision and reliability.
                    </p>
                </div>

                <div class="features-grid">
                    <div class="feature-card">
                        <div class="feature-icon-box"><?= landing_icon('users', 'icon-svg') ?></div>
                        <h3 class="feature-title">Member Management</h3>
                        <p class="feature-desc">Complete digital records, membership plan statuses, profiles, and attendance
                            history.</p>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon-box"><?= landing_icon('qr', 'icon-svg') ?></div>
                        <h3 class="feature-title">Dynamic QR Attendance</h3>
                        <p class="feature-desc">Secure time-stamped check-ins using rotating dynamic QR codes to prevent
                            pass-sharing.</p>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon-box"><?= landing_icon('card', 'icon-svg') ?></div>
                        <h3 class="feature-title">Online Payments</h3>
                        <p class="feature-desc">Streamlined membership billing, recurring payments, walk-in fees, and audit
                            logging.</p>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon-box"><?= landing_icon('dumbbell', 'icon-svg') ?></div>
                        <h3 class="feature-title">Trainer Management</h3>
                        <p class="feature-desc">Assign coaches to members, manage training schedules, and monitor client
                            progression.</p>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon-box"><?= landing_icon('clipboard', 'icon-svg') ?></div>
                        <h3 class="feature-title">Workout & Dietary Plans</h3>
                        <p class="feature-desc">Custom routine builders with automated calorie and macro calculation
                            engines.</p>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon-box"><?= landing_icon('zap', 'icon-svg') ?></div>
                        <h3 class="feature-title">Equipment Availability</h3>
                        <p class="feature-desc">Live equipment queue monitoring and maintenance schedule tracking.</p>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon-box"><?= landing_icon('trend-up', 'icon-svg') ?></div>
                        <h3 class="feature-title">Fitness Progress</h3>
                        <p class="feature-desc">Track weight, body measurement changes, strength records, and milestone
                            achievements.</p>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon-box"><?= landing_icon('bell', 'icon-svg') ?></div>
                        <h3 class="feature-title">Engagement Monitoring</h3>
                        <p class="feature-desc">Automated alerts flag inactive members early so staff can re-engage them
                            before churn.</p>
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
                        <img src="assets/landing/gym_member.png" alt="Member Training" class="feature-media-photo">
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
                            class="feature-media-photo">
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
                        <img src="assets/landing/gym_trainer.png" alt="Trainer Guidance" class="feature-media-photo">
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
                                Predictive member churn risk alerts
                            </li>
                        </ul>
                    </div>
                    <div class="feature-media-frame">
                        <img src="assets/landing/gym_analytics_tablet.png" alt="Gym Operations Analytics Tablet"
                            class="feature-media-photo">
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

                    <!-- Slide Labels Row -->
                    <div class="laptop-slide-labels">
                        <button class="lsl-btn active" onclick="goToLaptopSlide(0)">Dashboard</button>
                        <button class="lsl-btn" onclick="goToLaptopSlide(1)">Members</button>
                        <button class="lsl-btn" onclick="goToLaptopSlide(2)">Attendance</button>
                        <button class="lsl-btn" onclick="goToLaptopSlide(3)">Payments</button>
                        <button class="lsl-btn" onclick="goToLaptopSlide(4)">Equipment</button>
                        <button class="lsl-btn" onclick="goToLaptopSlide(5)">Plans</button>
                        <button class="lsl-btn" onclick="goToLaptopSlide(6)">Trainers</button>
                    </div>
                </div>
            </div>
        </section>

        <!-- ==========================================================================
       09. HOW FITTRACK WORKS
       ========================================================================== -->
        <section class="how-section reveal-on-scroll" id="how-it-works">
            <div class="container">
                <div class="section-header">
                    <span class="section-label">Simple Onboarding</span>
                    <h2 class="section-title">How FitTrack Works</h2>
                    <p class="section-desc">Get your commercial facility up and running in 4 clear steps.</p>
                </div>

                <div class="steps-grid">
                    <div class="step-card">
                        <div class="step-num">01</div>
                        <h3 class="step-title">Register Your Gym</h3>
                        <p class="step-desc">Submit your facility details, select your operational plan, and set up your
                            workspace.</p>
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
                        <p class="step-desc">Track member attendance trends and receive automatic churn risk notifications.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ==========================================================================
       10. USER EXPERIENCE BY USER TYPE
       ========================================================================== -->
        <section class="users-section reveal-on-scroll">
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
                    <span class="section-label">Proven Results</span>
                    <h2 class="section-title">Trusted by Commercial Fitness Leaders</h2>
                    <p class="section-desc">Hear how FitTrack transformed operations for real gym operators across the
                        country.</p>
                </div>
            </div>

            <!-- Infinite Marquee Ticker Track -->
            <div class="ticker-container">
                <div class="ticker-track">
                    <!-- Review Card 1 -->
                    <div class="testimonial-card ticker-card">
                        <div>
                            <div class="quote-icon"><?= landing_icon('quote', 'quote-svg') ?></div>
                            <p class="quote-text">
                                FitTrack gave us total visibility over member attendance trends. Our turnstile lines
                                disappeared after adopting dynamic QR verification.
                            </p>
                        </div>
                        <div class="author-info">
                            <div class="author-avatar">MR</div>
                            <div>
                                <div class="author-name">Mark Ramirez</div>
                                <div class="author-role">Owner • Titan Fitness Center</div>
                            </div>
                        </div>
                    </div>

                    <!-- Review Card 2 -->
                    <div class="testimonial-card ticker-card">
                        <div>
                            <div class="quote-icon"><?= landing_icon('quote', 'quote-svg') ?></div>
                            <p class="quote-text">
                                The automated engagement monitoring flagged 45 inactive members last month. We re-engaged
                                over 70% of them before their subscriptions lapsed.
                            </p>
                        </div>
                        <div class="author-info">
                            <div class="author-avatar">JC</div>
                            <div>
                                <div class="author-name">Jessica Cruz</div>
                                <div class="author-role">Operations Manager • Apex Athletics</div>
                            </div>
                        </div>
                    </div>

                    <!-- Review Card 3 -->
                    <div class="testimonial-card ticker-card">
                        <div>
                            <div class="quote-icon"><?= landing_icon('quote', 'quote-svg') ?></div>
                            <p class="quote-text">
                                Assigning customized workout programs and dietary routines to my clients takes half the time
                                now. It’s the cleanest tool I’ve used.
                            </p>
                        </div>
                        <div class="author-info">
                            <div class="author-avatar">DC</div>
                            <div>
                                <div class="author-name">David Castillo</div>
                                <div class="author-role">Head Coach • Elevate Performance</div>
                            </div>
                        </div>
                    </div>

                    <!-- Review Card 4 -->
                    <div class="testimonial-card ticker-card">
                        <div>
                            <div class="quote-icon"><?= landing_icon('quote', 'quote-svg') ?></div>
                            <p class="quote-text">
                                Managing multi-branch gym locations used to be a nightmare of disconnected spreadsheets.
                                FitTrack synchronized our staff and billing in 24 hours.
                            </p>
                        </div>
                        <div class="author-info">
                            <div class="author-avatar">SL</div>
                            <div>
                                <div class="author-name">Samantha Lim</div>
                                <div class="author-role">General Manager • MetroFit Philippines</div>
                            </div>
                        </div>
                    </div>

                    <!-- Review Card 5 -->
                    <div class="testimonial-card ticker-card">
                        <div>
                            <div class="quote-icon"><?= landing_icon('quote', 'quote-svg') ?></div>
                            <p class="quote-text">
                                Online payment tracking and automated renewal reminders boosted our monthly cash flow
                                predictability by 35%. Highly recommended!
                            </p>
                        </div>
                        <div class="author-info">
                            <div class="author-avatar">RT</div>
                            <div>
                                <div class="author-name">Ramon Torralba</div>
                                <div class="author-role">Managing Partner • Ironclad Gyms</div>
                            </div>
                        </div>
                    </div>

                    <!-- Review Card 6 -->
                    <div class="testimonial-card ticker-card">
                        <div>
                            <div class="quote-icon"><?= landing_icon('quote', 'quote-svg') ?></div>
                            <p class="quote-text">
                                Our members love the dynamic QR mobile check-in. It feels ultra-modern, secure, and our
                                front desk staff can focus on member service.
                            </p>
                        </div>
                        <div class="author-info">
                            <div class="author-avatar">AB</div>
                            <div>
                                <div class="author-name">Angela Bernardo</div>
                                <div class="author-role">Customer Success Lead • Pulse Fitness Studio</div>
                            </div>
                        </div>
                    </div>

                    <!-- Duplicate Set for Seamless 100% Infinite Auto-Scroll Loop -->
                    <div class="testimonial-card ticker-card">
                        <div>
                            <div class="quote-icon"><?= landing_icon('quote', 'quote-svg') ?></div>
                            <p class="quote-text">
                                FitTrack gave us total visibility over member attendance trends. Our turnstile lines
                                disappeared after adopting dynamic QR verification.
                            </p>
                        </div>
                        <div class="author-info">
                            <div class="author-avatar">MR</div>
                            <div>
                                <div class="author-name">Mark Ramirez</div>
                                <div class="author-role">Owner • Titan Fitness Center</div>
                            </div>
                        </div>
                    </div>

                    <div class="testimonial-card ticker-card">
                        <div>
                            <div class="quote-icon"><?= landing_icon('quote', 'quote-svg') ?></div>
                            <p class="quote-text">
                                The automated engagement monitoring flagged 45 inactive members last month. We re-engaged
                                over 70% of them before their subscriptions lapsed.
                            </p>
                        </div>
                        <div class="author-info">
                            <div class="author-avatar">JC</div>
                            <div>
                                <div class="author-name">Jessica Cruz</div>
                                <div class="author-role">Operations Manager • Apex Athletics</div>
                            </div>
                        </div>
                    </div>

                    <div class="testimonial-card ticker-card">
                        <div>
                            <div class="quote-icon"><?= landing_icon('quote', 'quote-svg') ?></div>
                            <p class="quote-text">
                                Assigning customized workout programs and dietary routines to my clients takes half the time
                                now. It’s the cleanest tool I’ve used.
                            </p>
                        </div>
                        <div class="author-info">
                            <div class="author-avatar">DC</div>
                            <div>
                                <div class="author-name">David Castillo</div>
                                <div class="author-role">Head Coach • Elevate Performance</div>
                            </div>
                        </div>
                    </div>

                    <div class="testimonial-card ticker-card">
                        <div>
                            <div class="quote-icon"><?= landing_icon('quote', 'quote-svg') ?></div>
                            <p class="quote-text">
                                Managing multi-branch gym locations used to be a nightmare of disconnected spreadsheets.
                                FitTrack synchronized our staff and billing in 24 hours.
                            </p>
                        </div>
                        <div class="author-info">
                            <div class="author-avatar">SL</div>
                            <div>
                                <div class="author-name">Samantha Lim</div>
                                <div class="author-role">General Manager • MetroFit Philippines</div>
                            </div>
                        </div>
                    </div>

                    <div class="testimonial-card ticker-card">
                        <div>
                            <div class="quote-icon"><?= landing_icon('quote', 'quote-svg') ?></div>
                            <p class="quote-text">
                                Online payment tracking and automated renewal reminders boosted our monthly cash flow
                                predictability by 35%. Highly recommended!
                            </p>
                        </div>
                        <div class="author-info">
                            <div class="author-avatar">RT</div>
                            <div>
                                <div class="author-name">Ramon Torralba</div>
                                <div class="author-role">Managing Partner • Ironclad Gyms</div>
                            </div>
                        </div>
                    </div>

                    <div class="testimonial-card ticker-card">
                        <div>
                            <div class="quote-icon"><?= landing_icon('quote', 'quote-svg') ?></div>
                            <p class="quote-text">
                                Our members love the dynamic QR mobile check-in. It feels ultra-modern, secure, and our
                                front desk staff can focus on member service.
                            </p>
                        </div>
                        <div class="author-info">
                            <div class="author-avatar">AB</div>
                            <div>
                                <div class="author-name">Angela Bernardo</div>
                                <div class="author-role">Customer Success Lead • Pulse Fitness Studio</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
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
                            <p>Unlike generic gym software, FitTrack combines real-time QR turnstile gate verification, automated churn-risk prediction algorithms, equipment inventory maintenance, and dedicated trainer coaching workflows into a unified, dark-mode commercial platform.</p>
                        </div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question-btn" onclick="toggleFaq(this)">
                            <span>How does FitTrack detect members at risk of dropping out?</span>
                            <span class="faq-icon">+</span>
                        </button>
                        <div class="faq-answer">
                            <p>FitTrack’s Predictive Engagement Engine monitors attendance frequency and workout consistency. If a member hasn't checked in for 7–14 days compared to their baseline, the system automatically flags them on your admin dashboard and triggers re-engagement alerts.</p>
                        </div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question-btn" onclick="toggleFaq(this)">
                            <span>Is there a free trial or contract lock-in?</span>
                            <span class="faq-icon">+</span>
                        </button>
                        <div class="faq-answer">
                            <p>None at all. All FitTrack commercial plans run on a transparent month-to-month subscription. You can upgrade, downgrade, cancel, or request a live 1-on-1 demo walkthrough anytime without cancellation penalties.</p>
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
        <section class="pricing-section reveal-on-scroll" id="pricing">
            <div class="container">
                <div class="section-header">
                    <span class="section-label">Transparent Commercial Plans</span>
                    <h2 class="section-title">Plans Built to Scale With Your Gym</h2>
                    <p class="section-desc">Select the right operational tier for your facility.</p>
                </div>

                <div class="pricing-grid" id="pricingGrid">
                    <?php foreach ($platformPlans as $pKey => $p): 
                        $isPop = !empty($p['popular']);
                    ?>
                    <!-- <?= h($p['name']) ?> -->
                    <div class="pricing-card <?= $isPop ? 'popular' : '' ?>">
                        <?php if ($isPop): ?>
                            <span class="popular-badge">★ Most Popular</span>
                        <?php endif; ?>
                        <h3 class="plan-name"><?= h($p['name']) ?></h3>
                        <p class="plan-desc"><?= h($p['desc']) ?></p>
                        <div class="plan-price-box">
                            <span class="price-currency">₱</span>
                            <span class="price-val"><?= number_format((float)$p['price']) ?></span>
                            <span class="price-period">/ month</span>
                        </div>
                        <ul class="plan-features-list">
                            <?php foreach ($p['features'] as $feat): ?>
                                <li class="plan-feature-item">
                                    <span class="bullet-check"><?= landing_icon('check', 'check-svg') ?></span>
                                    <?= h($feat) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="index.php?page=gym_onboarding" class="btn <?= $isPop ? 'btn-lime' : 'btn-outline' ?> btn-lg" style="width: 100%;">
                            Register Gym
                        </a>
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
                    <h2 class="cta-title">Ready to bring your gym operations together?</h2>
                    <p class="cta-desc">
                        Manage your gym, members, trainers, payments, and engagement with FitTrack.
                    </p>
                    <div class="cta-btn-group">
                        <a href="index.php?page=gym_onboarding" class="btn btn-lime btn-lg">
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
                            Free setup in under 2 minutes
                        </span>
                        <span class="cta-cue-bullet">·</span>
                        <span class="cta-cue-item">
                            <span class="cue-check"><?= landing_icon('check', 'check-svg') ?></span>
                            No credit card required
                        </span>
                        <span class="cta-cue-bullet">·</span>
                        <span class="cta-cue-item">
                            <span class="cue-check"><?= landing_icon('check', 'check-svg') ?></span>
                            Cancel anytime
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
                            <li><a href="#features" class="footer-link">Engagement Engine</a></li>
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
                            <li><a href="index.php?page=gym_onboarding" class="footer-link">Register Gym</a></li>
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
                        Review the exact capability distribution across our commercial gym tiers. Choose the plan that aligns with your facility scale and operations.
                    </p>

                    <div class="distribution-table-wrap">
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
                                    <td><span class="dist-pill purple">Multi-Branch / Enterprise</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Active Member Capacity</strong></td>
                                    <td><span class="dist-text-highlight">Up to 150 Members</span></td>
                                    <td class="col-popular"><span class="dist-text-lime">Up to 500 Members</span></td>
                                    <td><span class="dist-text-purple">Unlimited Members</span></td>
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
                                    <td><span class="status-locked">🔒 Locked</span></td>
                                    <td class="col-popular"><span class="status-access">✓ Full Access</span></td>
                                    <td><span class="status-access">✓ Full Access</span></td>
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
                                    <td><span class="status-access">✓ Multi-Branch Booking</span></td>
                                </tr>

                                <!-- Category 5 -->
                                <tr class="dist-cat-row">
                                    <td colspan="4">05 — Member Retention & Automated Reminders</td>
                                </tr>
                                <tr>
                                    <td>Automated Renewal Reminders</td>
                                    <td><span class="dist-pill muted">7-Day Alert</span></td>
                                    <td class="col-popular"><span class="dist-pill sky">Automated (30d/14d/7d/1d)</span></td>
                                    <td><span class="dist-pill purple">Custom Workflows</span></td>
                                </tr>
                                <tr>
                                    <td>Member Engagement & Churn Risk Alerts</td>
                                    <td><span class="status-locked">🔒 Locked</span></td>
                                    <td class="col-popular"><span class="status-access">✓ Churn Risk Alerts</span></td>
                                    <td><span class="status-access">✓ Predictive Risk AI</span></td>
                                </tr>

                                <!-- Category 6 -->
                                <tr class="dist-cat-row">
                                    <td colspan="4">06 — Financial Reports & Governance</td>
                                </tr>
                                <tr>
                                    <td>Dashboard Analytics & Operational KPIs</td>
                                    <td><span class="dist-pill muted">Basic KPIs</span></td>
                                    <td class="col-popular"><span class="dist-pill sky">Advanced Charts & Trends</span></td>
                                    <td><span class="dist-pill purple">Branch Comparisons</span></td>
                                </tr>
                                <tr>
                                    <td>Financial Reports & Data CSV Export</td>
                                    <td><span class="dist-pill muted">Summary Export</span></td>
                                    <td class="col-popular"><span class="dist-pill sky">Advanced Trends & CSV</span></td>
                                    <td><span class="dist-pill purple">Consolidated Multi-Branch</span></td>
                                </tr>
                                <tr>
                                    <td>Activity & Security Audit History</td>
                                    <td><span class="dist-pill muted">Basic Activity Log</span></td>
                                    <td class="col-popular"><span class="dist-pill muted">Staff Action History</span></td>
                                    <td><span class="status-access">✓ Full Immutable Trail</span></td>
                                </tr>
                                <tr>
                                    <td>Custom App Brand Accent & Theme</td>
                                    <td><span class="status-locked">🔒 Locked</span></td>
                                    <td class="col-popular"><span class="status-locked">🔒 Locked</span></td>
                                    <td><span class="status-access">✓ Full Access</span></td>
                                </tr>
                                <tr>
                                    <td>Multi-Branch Centralized Portal</td>
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
                        <a href="index.php?page=gym_onboarding" class="btn btn-outline btn-sm">Get <?= h($starterPlan['name']) ?> (<?= h($starterPlan['price_label']) ?>)</a>
                        <a href="index.php?page=gym_onboarding" class="btn btn-lime btn-sm">Get <?= h($proPlan['name']) ?> (<?= h($proPlan['price_label']) ?>)</a>
                        <a href="index.php?page=gym_onboarding" class="btn btn-outline btn-sm">Get <?= h($businessPlan['name']) ?> (<?= h($businessPlan['price_label']) ?>)</a>
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
                                <label class="form-label">Phone Number</label>
                                <input type="tel" name="phone" class="form-input" placeholder="+63 917 123 4567">
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
        <script>
            // 1. Mousemove 3D Card Tilt Effect (Only on devices that support hover)
            const tiltCard = document.getElementById('heroTiltCard');
            if (tiltCard && tiltCard.parentElement && window.matchMedia('(hover: hover)').matches) {
                const container = tiltCard.parentElement;
                container.addEventListener('mousemove', (e) => {
                    const rect = container.getBoundingClientRect();
                    const x = e.clientX - rect.left - rect.width / 2;
                    const y = e.clientY - rect.top - rect.height / 2;
                    const rotateX = (-y / rect.height) * 14;
                    const rotateY = (x / rect.width) * 14;
                    tiltCard.style.transform = `rotateX(${rotateX}deg) rotateY(${rotateY}deg)`;
                });
                container.addEventListener('mouseleave', () => {
                    tiltCard.style.transform = `rotateX(0deg) rotateY(0deg)`;
                });
            }

            // 2. Sticky Navigation Blur
            const nav = document.getElementById('mainNav');
            window.addEventListener('scroll', () => {
                if (window.scrollY > 40) {
                    nav.classList.add('scrolled');
                } else {
                    nav.classList.remove('scrolled');
                }
            }, { passive: true });

            // 3. Mobile Menu Toggle & Body Scroll Lock
            const mobileBtn = document.getElementById('mobileMenuBtn');
            const mobileDrawer = document.getElementById('mobileDrawer');
            if (mobileBtn && mobileDrawer) {
                mobileBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isOpen = mobileDrawer.classList.toggle('open');
                    document.body.style.overflow = isOpen ? 'hidden' : '';
                });
            }
            function closeMobileMenu() {
                if (mobileDrawer) {
                    mobileDrawer.classList.remove('open');
                    document.body.style.overflow = '';
                }
            }

            // Close mobile menu on outside click or escape key
            document.addEventListener('click', (e) => {
                if (mobileDrawer && mobileDrawer.classList.contains('open')) {
                    if (!mobileDrawer.contains(e.target) && !mobileBtn.contains(e.target)) {
                        closeMobileMenu();
                    }
                }
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    closeMobileMenu();
                    closeDemoModal();
                }
            });

            // 3.5 Hero Laptop Mockup Carousel JS
            let heroLaptopCurrentIndex = 0;
            const heroLaptopSlides = document.querySelectorAll('.hero-laptop-slide');
            const heroLaptopTotalSlides = heroLaptopSlides.length;
            const heroLaptopDots = document.querySelectorAll('#heroLaptopDots .hero-dot');
            const heroLaptopUrl = document.getElementById('heroLaptopUrl');
            const heroFeatureOverlayText = document.querySelector('#heroFeatureOverlay .hflo-text');
            let heroLaptopAutoTimer = null;

            function goToHeroLaptopSlide(index) {
                if (index < 0) index = heroLaptopTotalSlides - 1;
                if (index >= heroLaptopTotalSlides) index = 0;

                heroLaptopSlides.forEach((slide, i) => {
                    slide.classList.remove('active');
                    if (i === index) {
                        slide.classList.add('active');
                    }
                });

                heroLaptopDots.forEach((dot, i) => {
                    dot.classList.toggle('active', i === index);
                });

                const activeSlide = heroLaptopSlides[index];
                if (activeSlide && heroLaptopUrl) {
                    heroLaptopUrl.textContent = activeSlide.dataset.url || 'fittrack.app/admin/dashboard';
                }
                if (activeSlide && heroFeatureOverlayText) {
                    heroFeatureOverlayText.textContent = activeSlide.dataset.label || 'Gym Operations Dashboard';
                }

                heroLaptopCurrentIndex = index;
            }

            function changeHeroLaptopSlide(direction) {
                goToHeroLaptopSlide(heroLaptopCurrentIndex + direction);
                resetHeroLaptopAutoSlide();
            }

            function startHeroLaptopAutoSlide() {
                heroLaptopAutoTimer = setInterval(() => {
                    goToHeroLaptopSlide(heroLaptopCurrentIndex + 1);
                }, 4500);
            }

            function resetHeroLaptopAutoSlide() {
                clearInterval(heroLaptopAutoTimer);
                startHeroLaptopAutoSlide();
            }

            const heroLaptopContainer = document.getElementById('heroLaptopContainer');
            if (heroLaptopContainer) {
                heroLaptopContainer.addEventListener('mouseenter', () => {
                    clearInterval(heroLaptopAutoTimer);
                });
                heroLaptopContainer.addEventListener('mouseleave', () => {
                    startHeroLaptopAutoSlide();
                });

                // Touch swipe support for mobile
                let touchStartX = 0;
                let touchEndX = 0;
                heroLaptopContainer.addEventListener('touchstart', (e) => {
                    touchStartX = e.changedTouches[0].screenX;
                }, { passive: true });
                heroLaptopContainer.addEventListener('touchend', (e) => {
                    touchEndX = e.changedTouches[0].screenX;
                    if (touchStartX - touchEndX > 40) {
                        changeHeroLaptopSlide(1);
                    } else if (touchEndX - touchStartX > 40) {
                        changeHeroLaptopSlide(-1);
                    }
                }, { passive: true });
            }

            if (heroLaptopTotalSlides > 0) {
                startHeroLaptopAutoSlide();
            }

            // 4. Laptop Mockup Carousel (Section 08)
            let laptopCurrentIndex = 0;
            const laptopSlides = document.querySelectorAll('.laptop-slide');
            const laptopTotalSlides = laptopSlides.length;
            const laptopDots = document.querySelectorAll('.laptop-dot');
            const laptopLabelBtns = document.querySelectorAll('.lsl-btn');
            const laptopAddressBar = document.querySelector('#laptopAddressBar span');
            const laptopFeatureLabel = document.querySelector('#laptopFeatureLabel .lfl-text');
            let laptopAutoTimer = null;

            function goToLaptopSlide(index) {
                if (index < 0) index = laptopTotalSlides - 1;
                if (index >= laptopTotalSlides) index = 0;

                laptopSlides.forEach((slide, i) => {
                    slide.classList.remove('active');
                    if (i === index) {
                        slide.classList.add('active');
                    }
                });

                laptopDots.forEach((dot, i) => {
                    dot.classList.toggle('active', i === index);
                });

                laptopLabelBtns.forEach((btn, i) => {
                    btn.classList.toggle('active', i === index);
                });

                const activeSlide = laptopSlides[index];
                if (activeSlide && laptopAddressBar) {
                    laptopAddressBar.textContent = activeSlide.dataset.slideUrl || '';
                }
                if (activeSlide && laptopFeatureLabel) {
                    laptopFeatureLabel.textContent = activeSlide.dataset.slideLabel || '';
                }

                laptopCurrentIndex = index;
            }

            function changeLaptopSlide(direction) {
                goToLaptopSlide(laptopCurrentIndex + direction);
                resetLaptopAutoSlide();
            }

            function startLaptopAutoSlide() {
                laptopAutoTimer = setInterval(() => {
                    goToLaptopSlide(laptopCurrentIndex + 1);
                }, 4500);
            }

            function resetLaptopAutoSlide() {
                clearInterval(laptopAutoTimer);
                startLaptopAutoSlide();
            }

            // Pause on hover, resume on leave & touch swipe support
            const laptopWrapper = document.getElementById('laptopCarousel');
            if (laptopWrapper) {
                laptopWrapper.addEventListener('mouseenter', () => {
                    clearInterval(laptopAutoTimer);
                });
                laptopWrapper.addEventListener('mouseleave', () => {
                    startLaptopAutoSlide();
                });

                // Touch swipe support for mobile
                let laptopTouchStartX = 0;
                let laptopTouchEndX = 0;
                laptopWrapper.addEventListener('touchstart', (e) => {
                    laptopTouchStartX = e.changedTouches[0].screenX;
                }, { passive: true });
                laptopWrapper.addEventListener('touchend', (e) => {
                    laptopTouchEndX = e.changedTouches[0].screenX;
                    if (laptopTouchStartX - laptopTouchEndX > 40) {
                        changeLaptopSlide(1);
                    } else if (laptopTouchEndX - laptopTouchStartX > 40) {
                        changeLaptopSlide(-1);
                    }
                }, { passive: true });
            }

            startLaptopAutoSlide();

            // 5. User Experience Tab Switcher
            const userTabBtns = document.querySelectorAll('.user-tab-btn');
            const userPanes = document.querySelectorAll('.user-pane-content');
            userTabBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    const targetId = btn.getAttribute('data-user');
                    userTabBtns.forEach(b => b.classList.remove('active'));
                    userPanes.forEach(p => p.classList.remove('active'));

                    btn.classList.add('active');
                    const targetPane = document.getElementById(targetId);
                    if (targetPane) targetPane.classList.add('active');
                });
            });

            // 5.5 Mobile Pricing Horizontal Carousel Scroll & Dot Sync
            const pricingGrid = document.getElementById('pricingGrid');
            const pricingDots = document.querySelectorAll('.pricing-dot');
            const pricingSection = document.getElementById('pricing');
            let userInteractedWithPricing = false;

            function scrollToPricingPlan(index, smooth = true) {
                if (!pricingGrid) return;
                const cards = pricingGrid.querySelectorAll('.pricing-card');
                if (cards[index]) {
                    const card = cards[index];
                    const targetLeft = card.offsetLeft - (pricingGrid.clientWidth - card.clientWidth) / 2;
                    pricingGrid.scrollTo({ left: targetLeft, behavior: smooth ? 'smooth' : 'auto' });
                }
            }

            function centerPopularPlan(smooth = false) {
                if (!pricingGrid || window.innerWidth > 768) return;
                // Index 1 is the Professional (Most Popular) plan
                scrollToPricingPlan(1, smooth);
                pricingDots.forEach((dot, idx) => {
                    dot.classList.toggle('active', idx === 1);
                });
            }

            if (pricingGrid && pricingDots.length > 0) {
                // Initialize Most Popular plan centered on mobile
                const initPopularCenter = () => {
                    if (window.innerWidth <= 768 && !userInteractedWithPricing) {
                        centerPopularPlan(false);
                    }
                };

                // Trigger on DOM ready, load, and layout stabilization
                initPopularCenter();
                setTimeout(initPopularCenter, 60);
                setTimeout(initPopularCenter, 300);
                window.addEventListener('load', initPopularCenter);

                // Re-center on window resize if user hasn't scrolled manually
                window.addEventListener('resize', () => {
                    if (!userInteractedWithPricing) {
                        initPopularCenter();
                    }
                });

                // When user navigates to #pricing via nav links, smooth center the Popular plan
                document.querySelectorAll('a[href="#pricing"], a[href$="#pricing"]').forEach(link => {
                    link.addEventListener('click', () => {
                        userInteractedWithPricing = false;
                        setTimeout(() => {
                            centerPopularPlan(true);
                        }, 250);
                    });
                });

                // Intersection observer: ensures the Popular plan is centered when user scrolls to pricing
                if (pricingSection && 'IntersectionObserver' in window) {
                    const pricingObserver = new IntersectionObserver((entries) => {
                        entries.forEach(entry => {
                            if (entry.isIntersecting && !userInteractedWithPricing) {
                                centerPopularPlan(false);
                            }
                        });
                    }, { threshold: 0.15 });
                    pricingObserver.observe(pricingSection);
                }

                // Dot sync on carousel scroll
                pricingGrid.addEventListener('scroll', () => {
                    const cards = pricingGrid.querySelectorAll('.pricing-card');
                    const scrollCenter = pricingGrid.scrollLeft + (pricingGrid.clientWidth / 2);
                    let closestIndex = 0;
                    let minDiff = Infinity;
                    cards.forEach((card, idx) => {
                        const cardCenter = card.offsetLeft + (card.offsetWidth / 2);
                        const diff = Math.abs(cardCenter - scrollCenter);
                        if (diff < minDiff) {
                            minDiff = diff;
                            closestIndex = idx;
                        }
                    });
                    pricingDots.forEach((dot, idx) => {
                        dot.classList.toggle('active', idx === closestIndex);
                    });
                }, { passive: true });

                // Detect touch interaction
                pricingGrid.addEventListener('touchstart', () => {
                    userInteractedWithPricing = true;
                }, { passive: true });

                // Mouse drag-to-scroll support for responsive simulation
                let isDragging = false;
                let startX = 0;
                let startScrollLeft = 0;

                pricingGrid.addEventListener('mousedown', (e) => {
                    if (window.innerWidth > 768) return;
                    userInteractedWithPricing = true;
                    isDragging = true;
                    pricingGrid.style.scrollSnapType = 'none';
                    startX = e.pageX - pricingGrid.offsetLeft;
                    startScrollLeft = pricingGrid.scrollLeft;
                });

                window.addEventListener('mouseup', () => {
                    if (!isDragging) return;
                    isDragging = false;
                    pricingGrid.style.scrollSnapType = 'x mandatory';
                });

                pricingGrid.addEventListener('mousemove', (e) => {
                    if (!isDragging) return;
                    e.preventDefault();
                    const x = e.pageX - pricingGrid.offsetLeft;
                    const walk = (x - startX) * 1.1;
                    pricingGrid.scrollLeft = startScrollLeft - walk;
                });
            }

            // 5.6 Mobile Feature Deep Dives Synchronized Carousel & Pill Tabs
            const deepDiveTrack = document.getElementById('featurePresentationTrack');
            const deepDivePills = document.querySelectorAll('.deepdive-pill-btn');
            const deepDiveDots = document.querySelectorAll('.deepdive-dot');

            function switchDeepDiveTab(index, smooth = true) {
                if (!deepDiveTrack) return;
                const cards = deepDiveTrack.querySelectorAll('.feature-row');
                if (cards[index]) {
                    deepDiveTrack.scrollTo({ left: cards[index].offsetLeft, behavior: smooth ? 'smooth' : 'auto' });
                }
                updateDeepDiveActiveState(index);
            }

            function updateDeepDiveActiveState(index) {
                deepDivePills.forEach((pill, idx) => {
                    pill.classList.toggle('active', idx === index);
                    if (idx === index) {
                        pill.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                    }
                });
                deepDiveDots.forEach((dot, idx) => {
                    dot.classList.toggle('active', idx === index);
                });
            }

            if (deepDiveTrack && deepDivePills.length > 0) {
                let deepDiveScrollTimer = null;
                deepDiveTrack.addEventListener('scroll', () => {
                    clearTimeout(deepDiveScrollTimer);
                    deepDiveScrollTimer = setTimeout(() => {
                        const cards = deepDiveTrack.querySelectorAll('.feature-row');
                        if (!cards.length) return;
                        const scrollLeft = deepDiveTrack.scrollLeft;
                        let closestIndex = 0;
                        let minDiff = Infinity;
                        cards.forEach((card, idx) => {
                            const diff = Math.abs(card.offsetLeft - scrollLeft);
                            if (diff < minDiff) {
                                minDiff = diff;
                                closestIndex = idx;
                            }
                        });
                        updateDeepDiveActiveState(closestIndex);
                    }, 30);
                }, { passive: true });
            }

            // 6. Demo Modal Logic
            const demoModalBackdrop = document.getElementById('demoModalBackdrop');
            function openDemoModal() {
                if (demoModalBackdrop) {
                    demoModalBackdrop.classList.add('open');
                    document.body.style.overflow = 'hidden';
                }
            }

            function closeDemoModal() {
                if (demoModalBackdrop) {
                    demoModalBackdrop.classList.remove('open');
                    document.body.style.overflow = '';
                }
            }

            function handleBackdropClick(e) {
                if (e.target === demoModalBackdrop) {
                    closeDemoModal();
                }
            }

            // 6.5 Subscription Plan Distribution Modal Logic
            const planDistributionModalBackdrop = document.getElementById('planDistributionModalBackdrop');
            function openPlanDistributionModal() {
                if (planDistributionModalBackdrop) {
                    planDistributionModalBackdrop.classList.add('open');
                    document.body.style.overflow = 'hidden';
                }
            }

            function closePlanDistributionModal() {
                if (planDistributionModalBackdrop) {
                    planDistributionModalBackdrop.classList.remove('open');
                    document.body.style.overflow = '';
                }
            }

            function handleDistributionBackdropClick(e) {
                if (e.target === planDistributionModalBackdrop) {
                    closePlanDistributionModal();
                }
            }

            // Global Escape key support for modals
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeDemoModal();
                    closePlanDistributionModal();
                }
            });

            // 7. Demo Form AJAX Submission
            async function submitDemoForm(e) {
                e.preventDefault();
                const form = document.getElementById('demoForm');
                const alertBox = document.getElementById('modalAlert');
                const submitBtn = document.getElementById('submitDemoBtn');

                submitBtn.disabled = true;
                submitBtn.innerText = 'Submitting...';
                alertBox.style.display = 'none';

                try {
                    const formData = new FormData(form);
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        alertBox.style.display = 'block';
                        alertBox.style.background = 'rgba(132, 204, 22, 0.15)';
                        alertBox.style.border = '1px solid #84cc16';
                        alertBox.style.color = '#a3e635';
                        alertBox.innerText = '✓ Thank you! Your demo request has been received. Our team will contact you shortly.';
                        form.reset();
                        setTimeout(() => {
                            closeDemoModal();
                            alertBox.style.display = 'none';
                        }, 3500);
                    } else {
                        alertBox.style.display = 'block';
                        alertBox.style.background = 'rgba(239, 68, 68, 0.15)';
                        alertBox.style.border = '1px solid #ef4444';
                        alertBox.style.color = '#f87171';
                        alertBox.innerText = (result.errors && result.errors.length) ? result.errors.join(', ') : 'Failed to submit request. Please try again.';
                    }
                } catch (err) {
                    alertBox.style.display = 'block';
                    alertBox.style.background = 'rgba(239, 68, 68, 0.15)';
                    alertBox.style.border = '1px solid #ef4444';
                    alertBox.style.color = '#f87171';
                    alertBox.innerText = 'Network error. Please try again.';
                } finally {
                    submitBtn.disabled = false;
                    submitBtn.innerText = 'Submit Demo Request';
                }
            }

            // 7.5 FAQ Accordion Toggle
            function toggleFaq(btn) {
                const item = btn.closest('.faq-item');
                if (!item) return;
                const isAlreadyOpen = item.classList.contains('active');
                
                // Close all sibling FAQ items for accordion toggle experience
                document.querySelectorAll('.faq-item.active').forEach(el => {
                    el.classList.remove('active');
                    const icon = el.querySelector('.faq-icon');
                    if (icon) icon.textContent = '+';
                });

                if (!isAlreadyOpen) {
                    item.classList.add('active');
                    const icon = item.querySelector('.faq-icon');
                    if (icon) icon.textContent = '−';
                }
            }

            // 8. Scroll Reveal Observer & Visibility Failsafe
            function initScrollReveal() {
                const elements = document.querySelectorAll('.reveal-on-scroll');
                if ('IntersectionObserver' in window) {
                    const observerOptions = {
                        threshold: 0.05,
                        rootMargin: '50px 0px 50px 0px'
                    };
                    const revealObserver = new IntersectionObserver((entries) => {
                        entries.forEach(entry => {
                            if (entry.isIntersecting) {
                                entry.target.classList.add('is-visible');
                                revealObserver.unobserve(entry.target);
                            }
                        });
                    }, observerOptions);

                    elements.forEach(el => revealObserver.observe(el));
                } else {
                    elements.forEach(el => el.classList.add('is-visible'));
                }

                // Failsafe: reveal top elements immediately after 100ms
                setTimeout(() => {
                    elements.forEach(el => {
                        const rect = el.getBoundingClientRect();
                        if (rect.top < window.innerHeight) {
                            el.classList.add('is-visible');
                        }
                    });
                }, 100);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initScrollReveal);
            } else {
                initScrollReveal();
            }
        </script>

    </body>

    </html>
    <?php
}