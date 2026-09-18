<?php
declare(strict_types=1);

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
            $email     = trim((string) ($_POST['work_email'] ?? ''));
            $phone     = trim((string) ($_POST['phone'] ?? ''));
            $gym_name  = trim((string) ($_POST['gym_name'] ?? ''));
            $members   = trim((string) ($_POST['approx_members'] ?? ''));
            $role      = trim((string) ($_POST['role'] ?? ''));
            $message   = trim((string) ($_POST['message'] ?? ''));

            // Validate required fields
            $errors = [];
            if (!$full_name) $errors[] = 'Full Name is required';
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid work email is required';

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
                } catch (\Throwable $e) {}

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
        $stat_gyms     = (int) scalar("SELECT COUNT(*) FROM gyms WHERE status = 'approved'");
        $stat_members  = (int) scalar('SELECT COUNT(*) FROM users WHERE role = "member" AND status = "active"');
        $stat_trainers = (int) scalar('SELECT COUNT(*) FROM users WHERE role = "trainer" AND status = "active"');
    } catch (\Throwable $e) {}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FitTrack — Manage. Engage. Grow. Commercial Gym Operations Platform</title>
  <meta name="description" content="FitTrack helps gyms streamline operations, monitor member engagement, QR attendance, billing, and drive results.">
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,700;1,900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/landing.css">
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
      </ul>

      <div class="nav-actions">
        <a href="index.php?page=login" class="nav-auth-link">Log In</a>
        <a href="index.php?page=gym_onboarding" class="btn btn-outline btn-sm">Register Gym</a>
        <button class="btn btn-lime btn-sm" onclick="openDemoModal()">Request a Demo</button>
      </div>

      <button class="mobile-toggle" id="mobileMenuBtn" aria-label="Toggle Navigation">
        ☰
      </button>
    </div>
  </nav>

  <!-- Mobile Menu Drawer -->
  <div class="mobile-drawer" id="mobileDrawer">
    <a href="#features" class="mobile-drawer-link" onclick="closeMobileMenu()">Features</a>
    <a href="#how-it-works" class="mobile-drawer-link" onclick="closeMobileMenu()">How It Works</a>
    <a href="#testimonials" class="mobile-drawer-link" onclick="closeMobileMenu()">Testimonials</a>
    <a href="#pricing" class="mobile-drawer-link" onclick="closeMobileMenu()">Pricing</a>
    <a href="index.php?page=login" class="mobile-drawer-link" onclick="closeMobileMenu()">Log In</a>
    <a href="index.php?page=gym_onboarding" class="mobile-drawer-link" onclick="closeMobileMenu()">Register Your Gym</a>
    <button class="btn btn-lime btn-lg" onclick="closeMobileMenu(); openDemoModal();" style="margin-top: 1rem;">Request a Demo</button>
  </div>

  <!-- ==========================================================================
       02. HERO SECTION — DIRECT INLINE GYM PHOTO (MATCHING LOGIN PAGE SCREENSHOT)
       ========================================================================== -->
  <header class="hero-section">
    <div class="container">
      <div class="hero-split-card">
        <!-- Left Side: Brand Narrative & Feature Pills inside Square Glass Box over Gym Cover Photo -->
        <div class="hero-split-left">
          <div class="hero-square-box">
            <div class="hero-brand-header">
              <h1 class="hero-title">
                Smarter Gym Management.
                <span class="title-highlight">Stronger Community.</span>
              </h1>
              <p class="hero-subtitle">
                FitTrack helps gyms streamline operations, monitor member engagement, dynamic QR attendance, and drive growth.
              </p>
            </div>

            <div class="hero-feature-pills">
              <div class="hero-feature-pill">
                <div class="pill-icon">📊</div>
                <div>
                  <div class="pill-title">Track Attendance</div>
                  <div class="pill-sub">Monitor member check-ins and floor activity in real time.</div>
                </div>
              </div>

              <div class="hero-feature-pill">
                <div class="pill-icon">⚡</div>
                <div>
                  <div class="pill-title">Engage Members</div>
                  <div class="pill-sub">Automated churn warnings flag inactive members early.</div>
                </div>
              </div>
            </div>

            <div class="hero-ctas">
              <button class="btn btn-lime btn-lg" onclick="openDemoModal()">Request a Demo →</button>
              <a href="#features" class="btn btn-secondary btn-lg">Explore Platform</a>
            </div>
          </div>
        </div>

        <!-- Right Side: Interactive 3D Perspective Card Tilt -->
        <div class="hero-split-right">
          <div class="tilt-container">
            <div class="tilt-card" id="heroTiltCard">
              <div class="tilt-card-header">
                <span class="tilt-card-title">LIVE GYM OPERATIONS ENGINE</span>
                <span class="tilt-card-badge">SYSTEM ONLINE</span>
              </div>

              <div class="ui-metrics-row">
                <div class="ui-metric-box">
                  <div class="metric-lbl">Check-Ins Today</div>
                  <div class="metric-val"><?= number_format($stat_members > 0 ? $stat_members * 3 + 142 : 184) ?></div>
                  <div class="metric-trend">+14% vs avg</div>
                </div>
                <div class="ui-metric-box">
                  <div class="metric-lbl">Active Facilities</div>
                  <div class="metric-val"><?= number_format(max(12, $stat_gyms)) ?></div>
                  <div class="metric-trend">Verified</div>
                </div>
                <div class="ui-metric-box">
                  <div class="metric-lbl">Active Trainers</div>
                  <div class="metric-val"><?= number_format(max(48, $stat_trainers)) ?></div>
                  <div class="metric-trend">Coaching</div>
                </div>
              </div>

              <div class="mock-table-card" style="margin-top: 1rem; background: rgba(0,0,0,0.25);">
                <div class="mock-table-title" style="font-size: 0.85rem;">Recent Turnstile Access Events</div>
                <table class="mock-table" style="font-size: 0.8rem;">
                  <thead>
                    <tr>
                      <th>Member</th>
                      <th>Plan</th>
                      <th>Time</th>
                      <th>Gate Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td>Alex Rivera</td>
                      <td>Annual Elite</td>
                      <td>09:14 AM</td>
                      <td><span class="status-badge-active">QR Granted</span></td>
                    </tr>
                    <tr>
                      <td>Sarah Santos</td>
                      <td>Monthly Plus</td>
                      <td>09:02 AM</td>
                      <td><span class="status-badge-active">QR Granted</span></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </header>

  <!-- ==========================================================================
       03. TRUST / INTRODUCTION SECTION
       ========================================================================== -->
  <section class="trust-section">
    <div class="container">
      <div class="section-header">
        <span class="section-label">Built for Purpose</span>
        <h2 class="section-title">Built for the people who keep a gym moving.</h2>
        <p class="section-desc">FitTrack connects all key roles in your fitness ecosystem under one unified operation.</p>
      </div>

      <div class="trust-roles-grid">
        <div class="trust-role-card">
          <span class="role-tag">Business Leadership</span>
          <h3 class="role-title">Gym Owners</h3>
          <p class="role-desc">Manage revenue, member subscriptions, staff payroll, facility capacity, and business metrics from a centralized control panel.</p>
        </div>

        <div class="trust-role-card">
          <span class="role-tag">Coaching Staff</span>
          <h3 class="role-title">Trainers</h3>
          <p class="role-desc">Assign personalized workout routines, build dietary plans, track member assessments, and evaluate training compliance.</p>
        </div>

        <div class="trust-role-card">
          <span class="role-tag">Fitness Community</span>
          <h3 class="role-title">Members</h3>
          <p class="role-desc">Seamlessly check in via QR, track workout logs, monitor body metrics, view equipment queues, and maintain streak goals.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ==========================================================================
       04. THE GYM MANAGEMENT PROBLEM
       ========================================================================== -->
  <section class="problem-section">
    <div class="container problem-grid">
      <div class="problem-points">
        <div class="section-header" style="text-align: left; margin-bottom: 2rem;">
          <span class="section-label">The Operational Challenge</span>
          <h2 class="section-title">Everything Your Gym Needs. In One Place.</h2>
          <p class="section-desc">Traditional gym management often suffers from disconnected software tools, manual paperwork, and fragmented data.</p>
        </div>

        <div class="problem-point-card">
          <div class="problem-icon">✕</div>
          <div>
            <div class="problem-text-title">Fragmented Member Records</div>
            <div class="problem-text-desc">Membership status, medical forms, and payment histories scattered across spreadsheets.</div>
          </div>
        </div>

        <div class="problem-point-card">
          <div class="problem-icon">✕</div>
          <div>
            <div class="problem-text-title">Unmonitored Member Churn</div>
            <div class="problem-text-desc">No automated warnings when regular members drop off or stop attending classes.</div>
          </div>
        </div>

        <div class="problem-point-card">
          <div class="problem-icon">✕</div>
          <div>
            <div class="problem-text-title">Disconnected Trainer Workflows</div>
            <div class="problem-text-desc">Workout routines delivered on paper cards or unorganized instant messages.</div>
          </div>
        </div>
      </div>

      <div class="problem-summary-card">
        <span class="summary-tag">The FitTrack Engine</span>
        <h3 class="summary-headline">FitTrack brings every operational thread together into a single live system.</h3>
        <p class="summary-body">
          From automated turnstile QR scanning to live trainer assignments and financial reporting, FitTrack eliminates administrative friction so your team can focus on member retention and growth.
        </p>
      </div>
    </div>
  </section>

  <!-- ==========================================================================
       05. THE FITTRACK SOLUTION
       ========================================================================== -->
  <section class="solution-section">
    <div class="container">
      <div class="section-header">
        <span class="section-label">Unified Architecture</span>
        <h2 class="section-title">One Platform. Total Connectivity.</h2>
        <p class="section-desc">Observe how FitTrack unifies every aspect of your facility into one smooth digital workflow.</p>
      </div>

      <div class="solution-flow">
        <div class="flow-node">
          <div class="flow-node-icon">🏢</div>
          <div class="flow-node-title">Your Facility</div>
          <div class="flow-node-desc">Front Desk & Floor</div>
        </div>

        <div class="flow-arrow">➔</div>

        <div class="flow-node flow-hub">
          <div class="flow-node-icon">⚡</div>
          <div class="flow-node-title">FITTRACK HUB</div>
          <div class="flow-node-desc">Central Core Engine</div>
        </div>

        <div class="flow-arrow">➔</div>

        <div class="flow-node">
          <div class="flow-node-icon">📊</div>
          <div class="flow-node-title">Live Insights</div>
          <div class="flow-node-desc">Operations & Revenue</div>
        </div>
      </div>
    </div>
  </section>

  <!-- ==========================================================================
       06. CORE FEATURES
       ========================================================================== -->
  <section class="features-section" id="features">
    <div class="container">
      <div class="section-header">
        <span class="section-label">Platform Capabilities</span>
        <h2 class="section-title">Comprehensive Tools Built for Gym Growth</h2>
        <p class="section-desc">Designed to handle real commercial gym workloads with precision and reliability.</p>
      </div>

      <div class="features-grid">
        <div class="feature-card">
          <div class="feature-icon-box">👥</div>
          <h3 class="feature-title">Member Management</h3>
          <p class="feature-desc">Complete digital records, membership plan statuses, profiles, and attendance history.</p>
        </div>

        <div class="feature-card">
          <div class="feature-icon-box">📱</div>
          <h3 class="feature-title">Dynamic QR Attendance</h3>
          <p class="feature-desc">Secure time-stamped check-ins using rotating dynamic QR codes to prevent pass-sharing.</p>
        </div>

        <div class="feature-card">
          <div class="feature-icon-box">💳</div>
          <h3 class="feature-title">Online Payments</h3>
          <p class="feature-desc">Streamlined membership billing, recurring payments, walk-in fees, and audit logging.</p>
        </div>

        <div class="feature-card">
          <div class="feature-icon-box">🏋️</div>
          <h3 class="feature-title">Trainer Management</h3>
          <p class="feature-desc">Assign coaches to members, manage training schedules, and monitor client progression.</p>
        </div>

        <div class="feature-card">
          <div class="feature-icon-box">📋</div>
          <h3 class="feature-title">Workout & Dietary Plans</h3>
          <p class="feature-desc">Custom routine builders with automated calorie and macro calculation engines.</p>
        </div>

        <div class="feature-card">
          <div class="feature-icon-box">⚡</div>
          <h3 class="feature-title">Equipment Availability</h3>
          <p class="feature-desc">Live equipment queue monitoring and maintenance schedule tracking.</p>
        </div>

        <div class="feature-card">
          <div class="feature-icon-box">📈</div>
          <h3 class="feature-title">Fitness Progress</h3>
          <p class="feature-desc">Track weight, body measurement changes, strength records, and milestone achievements.</p>
        </div>

        <div class="feature-card">
          <div class="feature-icon-box">🔔</div>
          <h3 class="feature-title">Engagement Monitoring</h3>
          <p class="feature-desc">Automated alerts flag inactive members early so staff can re-engage them before churn.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ==========================================================================
       07. FEATURE PRESENTATION (ALTERNATING ROWS WITH ACCURATE GYM PHOTOS)
       ========================================================================== -->
  <section class="feature-presentation-section">
    <div class="container">
      <!-- Row 1: Member Management -->
      <div class="feature-row">
        <div class="feature-text-block">
          <span class="feature-label">01 — Administration</span>
          <h2 class="feature-row-title">Member Management & Records</h2>
          <p class="feature-row-desc">
            Keep every member record clean, organized, and accessible. Easily verify subscription renewals, attendance streaks, and account status in seconds.
          </p>
          <ul class="feature-bullets">
            <li class="feature-bullet-item"><span class="bullet-check">✓</span> Instant status verification (Active, Expiring, Pending)</li>
            <li class="feature-bullet-item"><span class="bullet-check">✓</span> Automated membership renewal notifications</li>
            <li class="feature-bullet-item"><span class="bullet-check">✓</span> Centralized profile storage and health assessments</li>
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

      <!-- Row 2: Dynamic QR Attendance (ACCURATE QR SCAN PHOTO) -->
      <div class="feature-row reverse">
        <div class="feature-text-block">
          <span class="feature-label">02 — Access Control</span>
          <h2 class="feature-row-title">Dynamic QR Turnstile Verification</h2>
          <p class="feature-row-desc">
            Replace legacy keycards with secure dynamic QR scanning. Members generate a fresh security code on their smartphone for instant turnstile check-in.
          </p>
          <ul class="feature-bullets">
            <li class="feature-bullet-item"><span class="bullet-check">✓</span> Prevents membership card sharing</li>
            <li class="feature-bullet-item"><span class="bullet-check">✓</span> Logs peak hours and floor utilization in real time</li>
            <li class="feature-bullet-item"><span class="bullet-check">✓</span> Automated self-checkout and session feedback</li>
          </ul>
        </div>
        <div class="feature-media-frame">
          <img src="assets/landing/gym_qr_scan.png" alt="Dynamic QR Turnstile Entrance" class="feature-media-photo">
          <div class="feature-media-ui-overlay">
            <div class="media-ui-title">DYNAMIC QR SCANNER</div>
            <div class="media-ui-val">QR Code Verified — Turnstile Unlocked (08:42 AM)</div>
          </div>
        </div>
      </div>

      <!-- Row 3: Trainer Coaching -->
      <div class="feature-row">
        <div class="feature-text-block">
          <span class="feature-label">03 — Coaching Staff</span>
          <h2 class="feature-row-title">Personalized Trainer Guidance</h2>
          <p class="feature-row-desc">
            Empower personal trainers to build customized workout programs, log client body assessments, and monitor training compliance directly within the system.
          </p>
          <ul class="feature-bullets">
            <li class="feature-bullet-item"><span class="bullet-check">✓</span> Custom routine templates and exercise libraries</li>
            <li class="feature-bullet-item"><span class="bullet-check">✓</span> Trainer commission tracking and session logging</li>
            <li class="feature-bullet-item"><span class="bullet-check">✓</span> Integrated client messaging and feedback</li>
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
      <div class="feature-row reverse">
        <div class="feature-text-block">
          <span class="feature-label">04 — Business Intelligence</span>
          <h2 class="feature-row-title">Live Facility Operations & Analytics</h2>
          <p class="feature-row-desc">
            Give gym management full real-time operational control. Monitor peak floor hours, staff schedules, and financial revenue stats on mobile or tablet devices.
          </p>
          <ul class="feature-bullets">
            <li class="feature-bullet-item"><span class="bullet-check">✓</span> Real-time occupancy & check-in metrics</li>
            <li class="feature-bullet-item"><span class="bullet-check">✓</span> Recurring revenue & payment audit logging</li>
            <li class="feature-bullet-item"><span class="bullet-check">✓</span> Predictive member churn risk alerts</li>
          </ul>
        </div>
        <div class="feature-media-frame">
          <img src="assets/landing/gym_analytics_tablet.png" alt="Gym Operations Analytics Tablet" class="feature-media-photo">
          <div class="feature-media-ui-overlay">
            <div class="media-ui-title">OPERATIONS CONTROL</div>
            <div class="media-ui-val">Live Facility Analytics • 100% System Synchronization</div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ==========================================================================
       08. PRODUCT SHOWCASE ("SEE FITTRACK IN ACTION")
       ========================================================================== -->
  <section class="showcase-section">
    <div class="container">
      <div class="section-header">
        <span class="section-label">Live Platform Demonstration</span>
        <h2 class="section-title">See FitTrack in Action</h2>
        <p class="section-desc">Explore how FitTrack’s interfaces present critical operational data cleanly and efficiently.</p>
      </div>

      <!-- Showcase Tabs Navigation -->
      <div class="showcase-tabs-nav">
        <button class="showcase-tab-btn active" data-tab="tab-dashboard">Dashboard</button>
        <button class="showcase-tab-btn" data-tab="tab-members">Members</button>
        <button class="showcase-tab-btn" data-tab="tab-attendance">Attendance</button>
        <button class="showcase-tab-btn" data-tab="tab-payments">Payments</button>
        <button class="showcase-tab-btn" data-tab="tab-trainers">Trainers</button>
        <button class="showcase-tab-btn" data-tab="tab-analytics">Analytics</button>
      </div>

      <!-- Showcase Window Mockup -->
      <div class="showcase-window">
        <div class="showcase-window-header">
          <div class="window-dots">
            <span class="dot"></span>
            <span class="dot"></span>
            <span class="dot"></span>
          </div>
          <span class="window-title">fittrack.app / workspace / control-panel</span>
          <span></span>
        </div>

        <div class="showcase-screen-content">
          <!-- Pane 1: Dashboard -->
          <div class="screen-pane active" id="tab-dashboard">
            <div class="mock-stat-cards">
              <div class="mock-stat-box">
                <div class="mock-stat-label">Total Active Members</div>
                <div class="mock-stat-num"><?= number_format(max(450, $stat_members * 5 + 320)) ?></div>
                <div class="mock-stat-badge">+8.4% this month</div>
              </div>
              <div class="mock-stat-box">
                <div class="mock-stat-label">Check-ins Today</div>
                <div class="mock-stat-num">164</div>
                <div class="mock-stat-badge">Peak Capacity: 74%</div>
              </div>
              <div class="mock-stat-box">
                <div class="mock-stat-label">Monthly Revenue</div>
                <div class="mock-stat-num">₱248,500</div>
                <div class="mock-stat-badge">On Track</div>
              </div>
            </div>

            <div class="mock-table-card">
              <div class="mock-table-title">Recent Gym Access Logs</div>
              <table class="mock-table">
                <thead>
                  <tr>
                    <th>Member Name</th>
                    <th>Plan</th>
                    <th>Check-In Time</th>
                    <th>Verification</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>Alex Rivera</td>
                    <td>Annual Elite</td>
                    <td>09:14 AM</td>
                    <td><span class="status-badge-active">QR Verified</span></td>
                  </tr>
                  <tr>
                    <td>Sarah Santos</td>
                    <td>Monthly Plus</td>
                    <td>09:02 AM</td>
                    <td><span class="status-badge-active">QR Verified</span></td>
                  </tr>
                  <tr>
                    <td>Marcus Reyes</td>
                    <td>Quarterly Starter</td>
                    <td>08:45 AM</td>
                    <td><span class="status-badge-active">QR Verified</span></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Pane 2: Members -->
          <div class="screen-pane" id="tab-members">
            <div class="mock-table-card">
              <div class="mock-table-title">Member Directory & Subscriptions</div>
              <table class="mock-table">
                <thead>
                  <tr>
                    <th>Member</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Renewal Date</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>Elena Cruz</td>
                    <td>elena.c@gmail.com</td>
                    <td><span class="status-badge-active">Active</span></td>
                    <td>Oct 15, 2026</td>
                  </tr>
                  <tr>
                    <td>David Miller</td>
                    <td>david.m@outlook.com</td>
                    <td><span class="status-badge-active">Active</span></td>
                    <td>Nov 02, 2026</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Pane 3: Attendance -->
          <div class="screen-pane" id="tab-attendance">
            <div class="mock-table-card">
              <div class="mock-table-title">Dynamic QR Verification Logs</div>
              <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1rem;">Real-time stream of turnstile scan events.</p>
              <table class="mock-table">
                <thead>
                  <tr>
                    <th>Session ID</th>
                    <th>User</th>
                    <th>Entry Point</th>
                    <th>Timestamp</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>#ATT-8942</td>
                    <td>John Dela Cruz</td>
                    <td>Main Entrance Gate 1</td>
                    <td>10:04:12 AM</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Pane 4: Payments -->
          <div class="screen-pane" id="tab-payments">
            <div class="mock-table-card">
              <div class="mock-table-title">Recent Transactions & Invoices</div>
              <table class="mock-table">
                <thead>
                  <tr>
                    <th>Transaction Ref</th>
                    <th>Description</th>
                    <th>Amount</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>TXN-90412</td>
                    <td>Quarterly Plus Membership</td>
                    <td>₱3,200.00</td>
                    <td><span class="status-badge-active">Paid</span></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Pane 5: Trainers -->
          <div class="screen-pane" id="tab-trainers">
            <div class="mock-table-card">
              <div class="mock-table-title">Coaching Roster & Client Allocation</div>
              <table class="mock-table">
                <thead>
                  <tr>
                    <th>Trainer</th>
                    <th>Specialty</th>
                    <th>Assigned Clients</th>
                    <th>Rating</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>Coach Carlos Tan</td>
                    <td>Strength & Conditioning</td>
                    <td>14 Members</td>
                    <td>4.9 ★</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Pane 6: Analytics -->
          <div class="screen-pane" id="tab-analytics">
            <div class="mock-table-card">
              <div class="mock-table-title">Retention & Engagement Insights</div>
              <p style="color: var(--text-muted); font-size: 0.9rem;">Automated predictive metrics identifying members needing proactive outreach.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ==========================================================================
       09. HOW FITTRACK WORKS
       ========================================================================== -->
  <section class="how-section" id="how-it-works">
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
          <p class="step-desc">Submit your facility details, select your operational plan, and set up your workspace.</p>
        </div>

        <div class="step-card">
          <div class="step-num">02</div>
          <h3 class="step-title">Set Up Workspace</h3>
          <p class="step-desc">Configure membership plans, add coaching staff, and define facility access rules.</p>
        </div>

        <div class="step-card">
          <div class="step-num">03</div>
          <h3 class="step-title">Manage Operations</h3>
          <p class="step-desc">Enable QR turnstile verification, record payments, and assign workout plans.</p>
        </div>

        <div class="step-card">
          <div class="step-num">04</div>
          <h3 class="step-title">Monitor Engagement</h3>
          <p class="step-desc">Track member attendance trends and receive automatic churn risk notifications.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ==========================================================================
       10. USER EXPERIENCE BY USER TYPE
       ========================================================================== -->
  <section class="users-section">
    <div class="container">
      <div class="section-header">
        <span class="section-label">Tailored Interfaces</span>
        <h2 class="section-title">Designed for Every User in Your Facility</h2>
        <p class="section-desc">Customized experiences optimized for the specific tasks of owners, coaches, and members.</p>
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
              Monitor peak occupancy, membership revenue, staff commissions, and churn metrics from a single executive dashboard.
            </p>
            <ul class="user-benefit-list">
              <li class="user-benefit-item"><span class="bullet-check">✓</span> Live turnstile capacity metrics</li>
              <li class="user-benefit-item"><span class="bullet-check">✓</span> Subscription billing reports</li>
              <li class="user-benefit-item"><span class="bullet-check">✓</span> Staff role permission management</li>
            </ul>
          </div>
          <img src="assets/landing/gym_management.png" alt="Gym Management" style="border-radius: var(--radius-lg); border: 1px solid var(--border-medium);">
        </div>

        <div class="user-pane-content" id="user-trainers">
          <div>
            <h3 class="user-pane-title">Professional Client Management</h3>
            <p class="user-pane-desc">
              Build workout templates, create dietary plans, track client assessment metrics, and communicate seamlessly.
            </p>
            <ul class="user-benefit-list">
              <li class="user-benefit-item"><span class="bullet-check">✓</span> Custom routine library builder</li>
              <li class="user-benefit-item"><span class="bullet-check">✓</span> Calorie & macro calculator integration</li>
              <li class="user-benefit-item"><span class="bullet-check">✓</span> Direct member progress tracking</li>
            </ul>
          </div>
          <img src="assets/landing/gym_trainer.png" alt="Trainer Workflows" style="border-radius: var(--radius-lg); border: 1px solid var(--border-medium);">
        </div>

        <div class="user-pane-content" id="user-members">
          <div>
            <h3 class="user-pane-title">Frictionless Member Mobile Experience</h3>
            <p class="user-pane-desc">
              Scan dynamic QR codes at the gate, track workout logs, follow diet plans, and view live equipment availability queues.
            </p>
            <ul class="user-benefit-list">
              <li class="user-benefit-item"><span class="bullet-check">✓</span> Instant QR check-in</li>
              <li class="user-benefit-item"><span class="bullet-check">✓</span> Goal achievement & streak logs</li>
              <li class="user-benefit-item"><span class="bullet-check">✓</span> Equipment queue status</li>
            </ul>
          </div>
          <img src="assets/landing/gym_member.png" alt="Member Mobile App" style="border-radius: var(--radius-lg); border: 1px solid var(--border-medium);">
        </div>
      </div>
    </div>
  </section>

  <!-- ==========================================================================
       11. TESTIMONIALS — INFINITE AUTO-SLIDING MARQUEE TICKER (6 COMMERCIAL REVIEWS)
       ========================================================================== -->
  <section class="testimonials-section" id="testimonials">
    <div class="container">
      <div class="section-header">
        <span class="section-label">Proven Results</span>
        <h2 class="section-title">Trusted by Commercial Fitness Leaders</h2>
        <p class="section-desc">Hear how FitTrack transformed operations for real gym operators across the country.</p>
      </div>
    </div>

    <!-- Infinite Marquee Ticker Track -->
    <div class="ticker-container">
      <div class="ticker-track">
        <!-- Review Card 1 -->
        <div class="testimonial-card ticker-card">
          <div>
            <div class="quote-icon">“</div>
            <p class="quote-text">
              FitTrack gave us total visibility over member attendance trends. Our turnstile lines disappeared after adopting dynamic QR verification.
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
            <div class="quote-icon">“</div>
            <p class="quote-text">
              The automated engagement monitoring flagged 45 inactive members last month. We re-engaged over 70% of them before their subscriptions lapsed.
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
            <div class="quote-icon">“</div>
            <p class="quote-text">
              Assigning customized workout programs and dietary routines to my clients takes half the time now. It’s the cleanest tool I’ve used.
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
            <div class="quote-icon">“</div>
            <p class="quote-text">
              Managing multi-branch gym locations used to be a nightmare of disconnected spreadsheets. FitTrack synchronized our staff and billing in 24 hours.
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
            <div class="quote-icon">“</div>
            <p class="quote-text">
              Online payment tracking and automated renewal reminders boosted our monthly cash flow predictability by 35%. Highly recommended!
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
            <div class="quote-icon">“</div>
            <p class="quote-text">
              Our members love the dynamic QR mobile check-in. It feels ultra-modern, secure, and our front desk staff can focus on member service.
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
            <div class="quote-icon">“</div>
            <p class="quote-text">
              FitTrack gave us total visibility over member attendance trends. Our turnstile lines disappeared after adopting dynamic QR verification.
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
            <div class="quote-icon">“</div>
            <p class="quote-text">
              The automated engagement monitoring flagged 45 inactive members last month. We re-engaged over 70% of them before their subscriptions lapsed.
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
            <div class="quote-icon">“</div>
            <p class="quote-text">
              Assigning customized workout programs and dietary routines to my clients takes half the time now. It’s the cleanest tool I’ve used.
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
            <div class="quote-icon">“</div>
            <p class="quote-text">
              Managing multi-branch gym locations used to be a nightmare of disconnected spreadsheets. FitTrack synchronized our staff and billing in 24 hours.
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
            <div class="quote-icon">“</div>
            <p class="quote-text">
              Online payment tracking and automated renewal reminders boosted our monthly cash flow predictability by 35%. Highly recommended!
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
            <div class="quote-icon">“</div>
            <p class="quote-text">
              Our members love the dynamic QR mobile check-in. It feels ultra-modern, secure, and our front desk staff can focus on member service.
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
       12. PRICING
       ========================================================================== -->
  <section class="pricing-section" id="pricing">
    <div class="container">
      <div class="section-header">
        <span class="section-label">Transparent Commercial Plans</span>
        <h2 class="section-title">Plans Built to Scale With Your Gym</h2>
        <p class="section-desc">Select the right operational tier for your facility.</p>
      </div>

      <div class="pricing-grid">
        <!-- Starter -->
        <div class="pricing-card">
          <h3 class="plan-name">Starter</h3>
          <p class="plan-desc">Ideal for boutique fitness studios and single-location facilities.</p>
          <div class="plan-price-box">
            <span class="price-currency">₱</span>
            <span class="price-val">499</span>
            <span class="price-period">/ month</span>
          </div>
          <ul class="plan-features-list">
            <li class="plan-feature-item"><span class="bullet-check">✓</span> Up to 150 Active Members</li>
            <li class="plan-feature-item"><span class="bullet-check">✓</span> Dynamic QR Attendance</li>
            <li class="plan-feature-item"><span class="bullet-check">✓</span> Basic Member Management</li>
            <li class="plan-feature-item"><span class="bullet-check">✓</span> Email Support</li>
          </ul>
          <a href="index.php?page=gym_onboarding" class="btn btn-outline btn-lg" style="width: 100%;">Register Gym</a>
        </div>

        <!-- Professional (Popular) -->
        <div class="pricing-card popular">
          <span class="popular-badge">Most Popular</span>
          <h3 class="plan-name">Professional</h3>
          <p class="plan-desc">Designed for growing commercial gyms with full coaching staff.</p>
          <div class="plan-price-box">
            <span class="price-currency">₱</span>
            <span class="price-val">999</span>
            <span class="price-period">/ month</span>
          </div>
          <ul class="plan-features-list">
            <li class="plan-feature-item"><span class="bullet-check">✓</span> Up to 500 Active Members</li>
            <li class="plan-feature-item"><span class="bullet-check">✓</span> Everything in Starter</li>
            <li class="plan-feature-item"><span class="bullet-check">✓</span> Trainer Management & Workouts</li>
            <li class="plan-feature-item"><span class="bullet-check">✓</span> Online Payment Gateway</li>
            <li class="plan-feature-item"><span class="bullet-check">✓</span> Engagement Churn Risk Alerts</li>
          </ul>
          <a href="index.php?page=gym_onboarding" class="btn btn-lime btn-lg" style="width: 100%;">Register Gym</a>
        </div>

        <!-- Business -->
        <div class="pricing-card">
          <h3 class="plan-name">Business</h3>
          <p class="plan-desc">For large enterprise facilities requiring custom scale.</p>
          <div class="plan-price-box">
            <span class="price-currency">₱</span>
            <span class="price-val">1,999</span>
            <span class="price-period">/ month</span>
          </div>
          <ul class="plan-features-list">
            <li class="plan-feature-item"><span class="bullet-check">✓</span> Unlimited Members</li>
            <li class="plan-feature-item"><span class="bullet-check">✓</span> Everything in Professional</li>
            <li class="plan-feature-item"><span class="bullet-check">✓</span> Multi-Branch Management</li>
            <li class="plan-feature-item"><span class="bullet-check">✓</span> Dedicated Account Manager</li>
            <li class="plan-feature-item"><span class="bullet-check">✓</span> Custom API Integrations</li>
          </ul>
          <a href="index.php?page=gym_onboarding" class="btn btn-outline btn-lg" style="width: 100%;">Register Gym</a>
        </div>
      </div>
    </div>
  </section>

  <!-- ==========================================================================
       13. FINAL CTA & FOOTER
       ========================================================================== -->
  <section class="cta-section">
    <div class="container">
      <div class="cta-box">
        <h2 class="cta-title">Ready to bring your gym operations together?</h2>
        <p class="cta-desc">
          Manage your gym, members, trainers, payments, and engagement with FitTrack.
        </p>
        <div class="cta-btn-group">
          <button class="btn btn-lime btn-lg" onclick="openDemoModal()">Request a Demo →</button>
          <a href="index.php?page=gym_onboarding" class="btn btn-secondary btn-lg">Register Your Gym</a>
        </div>
      </div>
    </div>
  </section>

  <!-- SaaS Footer -->
  <footer class="saas-footer">
    <div class="container">
      <div class="footer-grid">
        <div>
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
            A data-driven web application for gym operations and member engagement monitoring. Built for modern commercial fitness centers.
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
            <li><a href="javascript:void(0)" onclick="openDemoModal()" class="footer-link">Request Demo</a></li>
          </ul>
        </div>

        <div>
          <h4 class="footer-col-title">Legal</h4>
          <ul class="footer-links">
            <li><a href="#" class="footer-link">Privacy Policy</a></li>
            <li><a href="#" class="footer-link">Terms of Service</a></li>
            <li><a href="#" class="footer-link">Security Compliance</a></li>
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
       DEMO REQUEST MODAL
       ========================================================================== -->
  <div class="modal-backdrop" id="demoModalBackdrop" onclick="handleBackdropClick(event)">
    <div class="modal-card">
      <div class="modal-header">
        <h3 class="modal-title">Request a Live FitTrack Demo</h3>
        <button class="modal-close-btn" onclick="closeDemoModal()">×</button>
      </div>

      <div class="modal-body">
        <form id="demoForm" onsubmit="submitDemoForm(event)">
          <?= csrf_field() ?>
          <input type="hidden" name="demo_request" value="1">

          <div id="modalAlert" style="display: none; padding: 0.75rem; border-radius: 6px; font-size: 0.875rem; margin-bottom: 1rem;"></div>

          <div class="form-group">
            <label class="form-label">Full Name *</label>
            <input type="text" name="full_name" class="form-input" placeholder="e.g. Alex Rivera" required>
          </div>

          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Work Email *</label>
              <input type="email" name="work_email" class="form-input" placeholder="alex@yourgym.com" required>
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
            <textarea name="message" class="form-input" rows="3" placeholder="Tell us about your gym setup..."></textarea>
          </div>

          <button type="submit" class="btn btn-lime btn-lg" id="submitDemoBtn" style="width: 100%;">
            Submit Demo Request
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ==========================================================================
       VANILLA JAVASCRIPT LOGIC & 3D TILT EFFECT
       ========================================================================== -->
  <script>
    // 1. Mousemove 3D Card Tilt Effect
    const tiltCard = document.getElementById('heroTiltCard');
    if (tiltCard && tiltCard.parentElement) {
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
    });

    // 3. Mobile Menu Toggle
    const mobileBtn = document.getElementById('mobileMenuBtn');
    const mobileDrawer = document.getElementById('mobileDrawer');
    if (mobileBtn && mobileDrawer) {
      mobileBtn.addEventListener('click', () => {
        mobileDrawer.classList.toggle('open');
      });
    }
    function closeMobileMenu() {
      if (mobileDrawer) mobileDrawer.classList.remove('open');
    }

    // 4. Product Showcase Screen Switcher
    const showcaseTabBtns = document.querySelectorAll('.showcase-tab-btn');
    const showcasePanes = document.querySelectorAll('.screen-pane');
    showcaseTabBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        const targetId = btn.getAttribute('data-tab');
        showcaseTabBtns.forEach(b => b.classList.remove('active'));
        showcasePanes.forEach(p => p.classList.remove('active'));
        
        btn.classList.add('active');
        const targetPane = document.getElementById(targetId);
        if (targetPane) targetPane.classList.add('active');
      });
    });

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
  </script>

</body>
</html>
<?php
}