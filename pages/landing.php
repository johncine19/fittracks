<?php
declare(strict_types=1);

function landing_page(): void
{
    // If user is already logged in, send straight to dashboard
    if (current_user()) {
        redirect('dashboard');
    }
    // ── Live landing-page stats ──────────────────────────────────────────
    $stat_members  = (int) scalar('SELECT COUNT(*) FROM users WHERE role = "member" AND status = "active"');
    $stat_trainers = (int) scalar('SELECT COUNT(*) FROM users WHERE role = "trainer" AND status = "active"');
    $stat_classes  = (int) scalar(
        'SELECT COUNT(*) FROM class_schedules
         WHERE YEARWEEK(start_datetime, 1) = YEARWEEK(NOW(), 1)'
    );

    // Satisfaction rate from checkout_ratings (requires table to exist)
    $stat_satisfaction     = null;   // null = show "--"
    $live_testimonials     = [];
    try {
        $ratingRow = db()->query(
            'SELECT COUNT(*) AS total, SUM(rating >= 4) AS positive FROM checkout_ratings'
        )->fetch(PDO::FETCH_ASSOC);
        $totalRatings = (int) ($ratingRow['total'] ?? 0);
        if ($totalRatings >= 5) {
            $stat_satisfaction = (int) round(((int) $ratingRow['positive']) / $totalRatings * 100);
        }

        // Real testimonials: 4★ or 5★, with a non-empty comment
        $live_testimonials = query_all(
            'SELECT r.rating, r.comment, u.first_name, u.last_name, u.profile_picture
             FROM checkout_ratings r
             JOIN users u ON u.user_id = r.user_id
             WHERE r.rating >= 4 AND r.comment IS NOT NULL AND r.comment != ""
             ORDER BY r.created_at DESC
             LIMIT 8'
        );
    } catch (Throwable) {
        // Table doesn't exist yet — fall back to placeholders below
    }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FitTrack — Transform Your Fitness Journey</title>
    <meta name="description" content="FitTrack is your all-in-one fitness management platform. Track workouts, connect with expert coaches, and monitor your progress in real time.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400..800&family=IBM+Plex+Mono:wght@400;500&family=Inter:wght@400;500;600;700;800;900&family=Oswald:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/landing.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" />
</head>
<body class="landing-page">

<!-- Grain texture overlay -->
<svg class="grain-overlay" aria-hidden="true">
    <filter id="grain-filter">
        <feTurbulence type="fractalNoise" baseFrequency="0.85" numOctaves="3" stitchTiles="stitch"></feTurbulence>
        <feColorMatrix type="saturate" values="0"></feColorMatrix>
    </filter>
    <rect width="100%" height="100%" filter="url(#grain-filter)"></rect>
</svg>

<!-- Custom cursor -->
<div class="cursor-dot" id="cursor-dot"></div>
<div class="cursor-ring" id="cursor-ring"></div>

<!-- Scroll progress bar -->
<div class="scroll-progress" id="scroll-progress"></div>

<!-- ═══════════════ NAVBAR ═══════════════ -->
<nav class="landing-nav" id="landing-nav">
    <a href="index.php" class="landing-nav-brand">
        <span class="brand-icon">FT</span>
        <span>FitTrack</span>
    </a>
    <div class="landing-nav-links">
        <a href="#features" class="nav-link">Features</a>
        <a href="#how-it-works" class="nav-link">How It Works</a>
        <a href="#what-you-get" class="nav-link">What You Get</a>
        <a href="#engagement-score" class="nav-link">Engagement</a>
        <a href="#fitness-tiers" class="nav-link">Tiers</a>
        <a href="#pricing" class="nav-link">Pricing</a>
        <a href="#faq" class="nav-link">FAQ</a>
        <a href="index.php?page=login" class="btn-landing btn-landing-outline">Sign In</a>
        <a href="index.php?page=register" class="btn-landing btn-landing-primary">Get Started</a>
    </div>

    <!-- Mobile actions & hamburger button -->
    <div class="landing-nav-mobile">
        <a href="index.php?page=login" class="btn-landing btn-landing-outline mobile-auth-btn">Sign In</a>
        <a href="index.php?page=register" class="btn-landing btn-landing-primary mobile-auth-btn">Get Started</a>
        <button class="landing-hamburger" id="landing-hamburger" type="button" aria-label="Toggle Navigation" aria-expanded="false">
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
        </button>
    </div>
</nav>

<!-- Mobile Navigation Menu Overlay -->
<div class="landing-mobile-menu" id="landing-mobile-menu">
    <div class="mobile-menu-inner">
        <div class="mobile-menu-links">
            <a href="#features" class="mobile-nav-link">
                <span>Features</span>
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </a>
            <a href="#how-it-works" class="mobile-nav-link">
                <span>How It Works</span>
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </a>
            <a href="#what-you-get" class="mobile-nav-link">
                <span>What You Get</span>
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </a>
            <a href="#engagement-score" class="mobile-nav-link">
                <span>Engagement</span>
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </a>
            <a href="#fitness-tiers" class="mobile-nav-link">
                <span>Tiers</span>
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </a>
            <a href="#pricing" class="mobile-nav-link">
                <span>Pricing</span>
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </a>
            <a href="#faq" class="mobile-nav-link">
                <span>FAQ</span>
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </a>
        </div>
        <div class="mobile-menu-footer">
            <a href="index.php?page=login" class="btn-landing btn-landing-outline" style="width: 100%; justify-content: center;">Sign In</a>
            <a href="index.php?page=register" class="btn-landing btn-landing-primary" style="width: 100%; justify-content: center;">Get Started</a>
        </div>
    </div>
</div>

<!-- ═══════════════ HERO ═══════════════ -->
<section class="landing-hero" id="hero">
    <!-- Ambient glows -->
    <div class="hero-glow hero-glow-1"></div>
    <div class="hero-glow hero-glow-2"></div>
    <div class="hero-glow hero-glow-3"></div>

    <!-- Grid pattern -->
    <div class="hero-grid"></div>

    <!-- Floating geometric shapes -->
    <div class="floating-shapes">
        <div class="floating-shape shape-1">
            <svg viewBox="0 0 80 80"><polygon points="40,4 76,24 76,64 40,84 4,64 4,24" fill="none" stroke="currentColor" stroke-width="1" opacity="0.4"/></svg>
        </div>
        <div class="floating-shape shape-2">
            <svg viewBox="0 0 60 60"><circle cx="30" cy="30" r="28" fill="none" stroke="currentColor" stroke-width="1" opacity="0.3"/></svg>
        </div>
        <div class="floating-shape shape-3">
            <svg viewBox="0 0 100 100"><rect x="10" y="10" width="80" height="80" rx="8" fill="none" stroke="currentColor" stroke-width="1" opacity="0.25" transform="rotate(15 50 50)"/></svg>
        </div>
        <div class="floating-shape shape-4">
            <svg viewBox="0 0 50 50"><polygon points="25,2 48,38 2,38" fill="none" stroke="currentColor" stroke-width="1" opacity="0.35"/></svg>
        </div>
        <div class="floating-shape shape-5">
            <svg viewBox="0 0 70 70"><circle cx="35" cy="35" r="32" fill="none" stroke="currentColor" stroke-width="1" opacity="0.2"/></svg>
        </div>
        <div class="floating-shape shape-6">
            <svg viewBox="0 0 40 40"><line x1="0" y1="20" x2="40" y2="20" stroke="currentColor" stroke-width="1" opacity="0.4"/><line x1="20" y1="0" x2="20" y2="40" stroke="currentColor" stroke-width="1" opacity="0.4"/></svg>
        </div>
        <div class="floating-shape shape-7">
            <svg viewBox="0 0 90 90"><polygon points="45,5 85,25 85,65 45,85 5,65 5,25" fill="none" stroke="currentColor" stroke-width="0.8" opacity="0.2"/></svg>
        </div>
        <div class="floating-shape shape-8">
            <svg viewBox="0 0 55 55"><rect x="5" y="5" width="45" height="45" fill="none" stroke="currentColor" stroke-width="1" opacity="0.25" transform="rotate(30 27 27)"/></svg>
        </div>
    </div>


    <!-- Hero content -->
    <div class="hero-content">
        <div class="hero-eyebrow gs-reveal">
            <span class="dot"></span>
            YOUR FITNESS, ELEVATED
        </div>

        <h1 class="hero-title gs-reveal">
            <span class="word">TRANSFORM </span>
            <span class="word">YOUR </span>
            <br>
            <span class="word highlight">FITNESS </span>
            <span class="word highlight">JOURNEY</span>
        </h1>

        <p class="hero-subtitle gs-reveal">
            Track workouts, connect with expert trainers, and watch your progress unfold — all in one powerful platform built to push your limits.
        </p>

        <div class="hero-cta gs-reveal">
            <a href="index.php?page=register" class="btn-landing btn-landing-primary">
                Start Training
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M5 12h14"></path>
                    <path d="M12 5l7 7-7 7"></path>
                </svg>
            </a>
            <a href="index.php?page=login" class="btn-landing btn-landing-outline">
                Sign In
            </a>
        </div>

    </div>

    

    <!-- Accent line -->
    <div class="hero-accent-line gs-reveal">
        <svg preserveAspectRatio="none" viewBox="0 0 1440 2">
            <line x1="0" y1="1" x2="1440" y2="1" />
        </svg>
    </div>

    <!-- Scroll indicator -->
    <div class="scroll-indicator gs-reveal">
        <span>Scroll</span>
        <div class="scroll-line"></div>
    </div>
</section>

<!-- ═══════════════ CAROUSEL SHOWCASE ═══════════════ -->
<section class="landing-carousel-section" style="padding: 60px 0 120px; background: var(--bg-body); position: relative; z-index: 10; overflow: hidden;">
    <div class="carousel-aura"></div>
    <div class="carousel-grid-overlay"></div>
    <div class="hero-visual gs-reveal" style="margin-top: 0;">
        <div class="hero-visual-glow"></div>
        <div class="swiper hero-swiper" id="hero-carousel">
            <div class="swiper-wrapper" style="padding: 40px 0;">
                <div class="swiper-slide hero-visual-frame">
                    <img src="assets/images/gym.avif" alt="Gym 1" loading="lazy">
                    <div class="hero-visual-overlay"></div>
                </div>
                <div class="swiper-slide hero-visual-frame">
                    <img src="assets/images/guts.png" alt="Gym 2" loading="lazy">
                    <div class="hero-visual-overlay"></div>
                </div>
                <div class="swiper-slide hero-visual-frame">
                    <img src="assets/images/green.png" alt="Gym 3" loading="lazy">
                    <div class="hero-visual-overlay"></div>
                </div>
                <div class="swiper-slide hero-visual-frame">
                    <img src="assets/images/ichigo.png" alt="Gym 4" loading="lazy">
                    <div class="hero-visual-overlay"></div>
                </div>
                <div class="swiper-slide hero-visual-frame">
                    <img src="assets/images/reze.png" alt="Gym 5" loading="lazy">
                    <div class="hero-visual-overlay"></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════ FEATURE STORY (pinned scroll, no 3D/video — CSS + text only) ═══════════════ -->
<section class="feature-story" id="features">
    <div class="feature-story-pin">
        <div class="fs-aura"></div>
        <div class="fs-grid-overlay"></div>

        <div class="fs-kicker">
            <span class="fs-kicker-label">Features</span>
            <div class="fs-progress">
                <span class="fs-dot active" data-i="0"></span>
                <span class="fs-dot" data-i="1"></span>
                <span class="fs-dot" data-i="2"></span>
                <span class="fs-dot" data-i="3"></span>
                <span class="fs-dot" data-i="4"></span>
                <span class="fs-dot" data-i="5"></span>
            </div>
        </div>

        <div class="fs-panels">
            <div class="fs-panel active" data-i="0" data-color="#ff4d24">
                <span class="fs-count">01</span>
                <h2 class="fs-title">Track Every<br><span class="fs-highlight">Rep.</span></h2>
                <p class="fs-desc">Log every set, rep, and mile. Your plan adapts as you grow stronger and push new limits.</p>
            </div>
            <div class="fs-panel" data-i="1" data-color="#e8622c">
                <span class="fs-count">02</span>
                <h2 class="fs-title">A Trainer In<br><span class="fs-highlight">Your Pocket.</span></h2>
                <p class="fs-desc">Message certified trainers in real time. Get guidance, feedback, and plans built around you.</p>
            </div>
            <div class="fs-panel" data-i="2" data-color="#d1712f">
                <span class="fs-count">03</span>
                <h2 class="fs-title">Watch The<br><span class="fs-highlight">Numbers Climb.</span></h2>
                <p class="fs-desc">Visualise your transformation with charts and milestones. Celebrate every win, every week.</p>
            </div>
            <div class="fs-panel" data-i="3" data-color="#b3320f">
                <span class="fs-count">04</span>
                <h2 class="fs-title">Book It In<br><span class="fs-highlight">Seconds.</span></h2>
                <p class="fs-desc">Browse and reserve group classes instantly, with smart reminders so you never miss a session.</p>
            </div>
            <div class="fs-panel" data-i="4" data-color="#ff7a45">
                <span class="fs-count">05</span>
                <h2 class="fs-title">Scan In.<br><span class="fs-highlight">No Cards.</span></h2>
                <p class="fs-desc">Your personal QR code makes attendance instant and contactless — no lines, no hassle.</p>
            </div>
            <div class="fs-panel" data-i="5" data-color="#ffb27a">
                <span class="fs-count">06</span>
                <h2 class="fs-title">One Membership.<br><span class="fs-highlight">Zero Friction.</span></h2>
                <p class="fs-desc">Flexible plans, seamless renewals, transparent payments. Your membership, fully digital.</p>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════ SHOWCASE ═══════════════ -->
<section class="landing-showcase" id="showcase">
    <div class="section-header">
        <div class="section-label">Inside FitTrack</div>
        <h2 class="section-title">Built for the <span style="color:var(--lime)">Grind</span></h2>
        <p class="section-desc">Real training, real coaching, real results — see the community you'll be joining.</p>
    </div>

    <div class="showcase-grid">
        <div class="showcase-item tall">
            <div class="img-wrap">
                <img src="assets/images/sky.png" alt="assets/images/gym.avif" loading="lazy">
            </div>
            <div class="showcase-caption">
                <span class="tag">Strength</span>
                <h4>Guided Strength Training</h4>
            </div>
        </div>
        <div class="showcase-item short">
            <div class="img-wrap">
                <img src="assets/images/violet.png" alt="assets/images/gym.avif" loading="lazy">
            </div>
            <div class="showcase-caption">
                <span class="tag">Group Classes</span>
                <h4>Book a Session in Seconds</h4>
            </div>
        </div>
        <div class="showcase-item short">
            <div class="img-wrap">
                <img src="assets/images/green.png" alt="assets/images/gym.avif" loading="lazy">
            </div>
            <div class="showcase-caption">
                <span class="tag">Cardio</span>
                <h4>Track Every Mile</h4>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════ STATS ═══════════════ -->
<section class="landing-stats" id="stats">
    <div class="stats-grid">
        <div class="stat-item gs-reveal">
            <div class="stat-number"><span data-count="<?= $stat_members ?>"><?= $stat_members ?></span><span class="suffix">+</span></div>
            <div class="stat-label">Active Members</div>
        </div>
        <div class="stat-item gs-reveal">
            <div class="stat-number"><span data-count="<?= $stat_classes ?>"><?= $stat_classes ?></span><span class="suffix">+</span></div>
            <div class="stat-label">Weekly Classes</div>
        </div>
        <div class="stat-item gs-reveal">
            <div class="stat-number"><span data-count="<?= $stat_trainers ?>"><?= $stat_trainers ?></span><span class="suffix">+</span></div>
            <div class="stat-label">Expert Trainers</div>
        </div>
        <div class="stat-item gs-reveal">
            <?php if ($stat_satisfaction !== null): ?>
                <div class="stat-number"><span data-count="<?= $stat_satisfaction ?>"><?= $stat_satisfaction ?></span><span class="suffix">%</span></div>
            <?php else: ?>
                <div class="stat-number" style="font-size:clamp(1.8rem,3vw,2.6rem);color:var(--muted)">--</div>
            <?php endif; ?>
            <div class="stat-label">Satisfaction Rate</div>
        </div>
    </div>
</section>


<!-- ═══════════════ HOW IT WORKS ═══════════════ -->
<section class="landing-steps" id="how-it-works">
    <div class="section-header">
        <div class="section-label">How It Works</div>
        <h2 class="section-title">Get Started in <span style="color:var(--lime)">3 Simple Steps</span></h2>
        <p class="section-desc">From sign-up to your first workout, FitTrack makes it effortless.</p>
    </div>

    <div class="steps-grid">
        <div class="step-card gs-reveal">
            <div class="step-number">1</div>
            <h3>Create Your Account</h3>
            <p>Sign up in under a minute. Tell us about your fitness goals and we will build your profile.</p>
        </div>
        <div class="step-card gs-reveal">
            <div class="step-number">2</div>
            <h3>Get Your Workout Plan</h3>
            <p>Receive a personalised exercise routine tailored to your body, goals, and available equipment.</p>
        </div>
        <div class="step-card gs-reveal">
            <div class="step-number">3</div>
            <h3>Track & Crush Goals</h3>
            <p>Log workouts, book classes, message your coach, and watch your progress climb every week.</p>
        </div>
    </div>
</section>

<!-- ═══════════════ WHAT YOU GET ═══════════════ -->
<section class="landing-wyg" id="what-you-get">
    <div class="wyg-header">
        <div>
            <div class="wyg-kicker">WHAT YOU GET</div>
            <h2 class="wyg-title">SIX THINGS,<br>NO CLUTTER.</h2>
        </div>
        <p class="wyg-desc">
            Each one exists because a member touches it at a specific moment. Nothing here is a dashboard for its own sake.
        </p>
    </div>

    <div class="wyg-list">
        <!-- 1. AT THE DOOR -->
        <div class="wyg-row">
            <div class="wyg-timing">AT THE DOOR</div>
            <div class="wyg-action">SCAN IN</div>
            <p class="wyg-detail">
                Your personal QR opens the turnstile. No card to lose, no queue at the desk, and attendance lands in your record before you've reached the changing room.
            </p>
        </div>

        <!-- 2. BETWEEN SETS -->
        <div class="wyg-row">
            <div class="wyg-timing">BETWEEN SETS</div>
            <div class="wyg-action">LOG THE WORK</div>
            <p class="wyg-detail">
                Sets, reps, load, distance. Your plan adjusts to what you actually completed rather than what was planned three weeks ago.
            </p>
        </div>

        <!-- 3. WHENEVER -->
        <div class="wyg-row">
            <div class="wyg-timing">WHENEVER</div>
            <div class="wyg-action">MESSAGE YOUR COACH</div>
            <p class="wyg-detail">
                Certified trainers, in a thread, with your full log next to them. They can see the sets you skipped before you explain them.
            </p>
        </div>

        <!-- 4. SUNDAY NIGHT -->
        <div class="wyg-row">
            <div class="wyg-timing">SUNDAY NIGHT</div>
            <div class="wyg-action">BOOK THE WEEK</div>
            <p class="wyg-detail">
                Reserve group classes and hold your spot. Reminders arrive before the session, and a cancellation frees the slot for someone on the waitlist.
            </p>
        </div>

        <!-- 5. EVERY 30 DAYS -->
        <div class="wyg-row">
            <div class="wyg-timing">EVERY 30 DAYS</div>
            <div class="wyg-action">ENGAGEMENT SCORE</div>
            <p class="wyg-detail">
                A single 0–100 figure built from attendance, classes, consistency, completed sessions, and logged progress. It moves when your habits move.
            </p>
        </div>

        <!-- 6. EVERY 4 WEEKS -->
        <div class="wyg-row">
            <div class="wyg-timing">EVERY 4 WEEKS</div>
            <div class="wyg-action">BODY COMPOSITION</div>
            <p class="wyg-detail">
                The U.S. Navy circumference method from tape measurements. An estimate within roughly ±3–4%, useful as a trend, never as a diagnosis.
            </p>
        </div>
    </div>
</section>

<!-- ═══════════════ ENGAGEMENT SCORE BREAKDOWN ═══════════════ -->
<section class="landing-engagement" id="engagement-score">
    <div class="eng-header">
        <div>
            <div class="eng-kicker">ENGAGEMENT SCORE</div>
            <h2 class="eng-title">WHAT THE<br>NUMBER IS MADE OF.</h2>
        </div>
        <p class="eng-desc">
            Published in full, because a score you can't inspect is a score you can't trust.
        </p>
    </div>

    <div class="eng-grid">
        <!-- Left: Metrics Breakdown -->
        <div class="eng-metrics-list">
            <!-- Attendance frequency (40% weight -> 80% bar) -->
            <div class="eng-metric-row">
                <span class="eng-metric-label">Attendance frequency</span>
                <div class="eng-bar-track">
                    <div class="eng-bar-fill eng-bar-red" data-width="80%"></div>
                </div>
                <span class="eng-metric-val">40%</span>
            </div>

            <!-- Class participation (20% weight -> 40% bar) -->
            <div class="eng-metric-row">
                <span class="eng-metric-label">Class participation</span>
                <div class="eng-bar-track">
                    <div class="eng-bar-fill eng-bar-blue" data-width="40%"></div>
                </div>
                <span class="eng-metric-val">20%</span>
            </div>

            <!-- Consistency (20% weight -> 40% bar) -->
            <div class="eng-metric-row">
                <span class="eng-metric-label">Consistency</span>
                <div class="eng-bar-track">
                    <div class="eng-bar-fill eng-bar-blue" data-width="40%"></div>
                </div>
                <span class="eng-metric-val">20%</span>
            </div>

            <!-- Completed workouts (10% weight -> 20% bar) -->
            <div class="eng-metric-row">
                <span class="eng-metric-label">Completed workouts</span>
                <div class="eng-bar-track">
                    <div class="eng-bar-fill eng-bar-yellow" data-width="20%"></div>
                </div>
                <span class="eng-metric-val">10%</span>
            </div>

            <!-- Progress updates (10% weight -> 20% bar) -->
            <div class="eng-metric-row">
                <span class="eng-metric-label">Progress updates</span>
                <div class="eng-bar-track">
                    <div class="eng-bar-fill eng-bar-yellow" data-width="20%"></div>
                </div>
                <span class="eng-metric-val">10%</span>
            </div>
        </div>

        <!-- Right: Where You Land Card -->
        <div class="eng-land-wrap">
            <div class="eng-land-kicker">WHERE YOU LAND</div>
            <div class="eng-land-card">
                <!-- Highly Engaged -->
                <div class="eng-tier-row">
                    <div class="eng-tier-indicator red"></div>
                    <span class="eng-tier-name">HIGHLY ENGAGED</span>
                    <span class="eng-tier-range">75 – 100</span>
                </div>
                <!-- Moderately Engaged -->
                <div class="eng-tier-row">
                    <div class="eng-tier-indicator blue"></div>
                    <span class="eng-tier-name">MODERATELY ENGAGED</span>
                    <span class="eng-tier-range">40 – 74</span>
                </div>
                <!-- At Risk -->
                <div class="eng-tier-row">
                    <div class="eng-tier-indicator yellow"></div>
                    <span class="eng-tier-name">AT RISK</span>
                    <span class="eng-tier-range">0 – 39</span>
                </div>
            </div>
            <p class="eng-land-note">
                Drop below 40 and your coach gets a prompt to check in. That's the whole point of measuring it.
            </p>
        </div>
    </div>
</section>

<!-- ═══════════════ FITNESS TIERS ═══════════════ -->
<section class="landing-tiers" id="fitness-tiers">
    <div class="tiers-header">
        <div>
            <div class="tiers-kicker">FITNESS TIERS</div>
            <h2 class="tiers-title">EARNED IN WEEKS,<br>NOT WORKOUTS.</h2>
        </div>
        <p class="tiers-desc">
            A tier moves when you complete a full training week. One heroic session doesn't count, and it shouldn't.
        </p>
    </div>

    <div class="tiers-block">
        <!-- Level 01 -->
        <div class="tier-col" data-tier="01">
            <div class="tier-accent-bar"></div>
            <div class="tier-level">Level 01</div>
            <div class="tier-name">NEWBIE</div>
            <div class="tier-duration">Week 0</div>
        </div>

        <!-- Level 02 -->
        <div class="tier-col" data-tier="02">
            <div class="tier-accent-bar"></div>
            <div class="tier-level">Level 02</div>
            <div class="tier-name">IRON RECRUIT</div>
            <div class="tier-duration">1+ weeks</div>
        </div>

        <!-- Level 03 -->
        <div class="tier-col" data-tier="03">
            <div class="tier-accent-bar"></div>
            <div class="tier-level">Level 03</div>
            <div class="tier-name">BRONZE BEAST</div>
            <div class="tier-duration">4+ weeks</div>
        </div>

        <!-- Level 04 -->
        <div class="tier-col" data-tier="04">
            <div class="tier-accent-bar"></div>
            <div class="tier-level">Level 04</div>
            <div class="tier-name">SILVER SPARTAN</div>
            <div class="tier-duration">12+ weeks</div>
        </div>

        <!-- Level 05 -->
        <div class="tier-col" data-tier="05">
            <div class="tier-accent-bar"></div>
            <div class="tier-level">Level 05</div>
            <div class="tier-name">GOLD GLADIATOR</div>
            <div class="tier-duration">24+ weeks</div>
        </div>

        <!-- Level 06 -->
        <div class="tier-col" data-tier="06">
            <div class="tier-accent-bar"></div>
            <div class="tier-level">Level 06</div>
            <div class="tier-name">APEX LEGEND</div>
            <div class="tier-duration">52+ weeks</div>
        </div>
    </div>
</section>

<!-- ═══════════════ TESTIMONIALS ═══════════════ -->
<section class="landing-testimonials" id="testimonials">
    <div class="section-header">
        <div class="section-label">Member Stories</div>
        <h2 class="section-title">Trusted by <span style="color:var(--lime)">Real Members</span></h2>
    </div>

    <div class="fade-edge left"></div>
    <div class="fade-edge right"></div>
    <div class="testimonials-marquee" id="testimonials-track">
        <?php
        // Use live testimonials if we have at least 3 real comments, otherwise show placeholders
        $placeholders = [
            ['quote' => 'The QR check-in alone saved me so much time. I actually look forward to logging my workouts now.', 'name' => 'Mika R.', 'role' => 'Member since 2024', 'rating' => 5, 'avatar' => null],
            ['quote' => 'My coach messages me directly through the app. It genuinely feels like personal training, not a gym membership.', 'name' => 'Josh T.', 'role' => 'Strength Program', 'rating' => 5, 'avatar' => null],
            ['quote' => 'Booking classes used to be a headache. Now it takes ten seconds and I never lose my spot.', 'name' => 'Anna L.', 'role' => 'Group Fitness', 'rating' => 5, 'avatar' => null],
            ['quote' => 'Watching the progress chart climb every week keeps me way more motivated than a paper logbook ever did.', 'name' => 'Carlo D.', 'role' => 'Member since 2023', 'rating' => 5, 'avatar' => null],
        ];
        $cards = count($live_testimonials) >= 3 ? $live_testimonials : $placeholders;
        foreach ($cards as $t):
            $isLive   = isset($t['first_name']);
            $name     = $isLive ? h($t['first_name'] . ' ' . mb_substr($t['last_name'], 0, 1) . '.') : h($t['name']);
            $quote    = $isLive ? h($t['comment']) : h($t['quote']);
            $roleText = $isLive ? 'FitTrack Member' : h($t['role']);
            $rating   = (int) $t['rating'];
            $stars    = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
            $avatarSrc = ($isLive && !empty($t['profile_picture']))
                ? upload_url($t['profile_picture'])
                : null;
        ?>
        <div class="testimonial-card" style="display: flex; flex-direction: column;">
            <div class="testimonial-person" style="margin-bottom: 20px;">
                <?php if ($avatarSrc): ?>
                    <img src="<?= $avatarSrc ?>" alt="Member avatar" loading="lazy">
                <?php else: ?>
                    <div style="width:42px;height:42px;border-radius:50%;background:rgba(199,255,34,0.12);border:1px solid rgba(199,255,34,0.3);display:grid;place-items:center;font-weight:800;font-size:15px;color:var(--lime);flex-shrink:0;">
                        <?= mb_strtoupper(mb_substr($isLive ? $t['first_name'] : $t['name'], 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div>
                    <div class="name"><?= $name ?></div>
                    <div class="role"><?= $roleText ?></div>
                </div>
            </div>
            <div class="testimonial-stars" style="margin-bottom: 12px;"><?= $stars ?></div>
            <p class="testimonial-quote" style="margin: 0;">"<?= $quote ?>"</p>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- ═══════════════ FAQ ═══════════════ -->
<section class="landing-faq" id="faq" style="padding: 100px 5%; max-width: 1200px; margin: 0 auto;">
    <div class="section-header" style="text-align: center; margin-bottom: 50px;">
        <div class="section-label">FAQ</div>
        <h2 class="section-title">Frequently Asked <span style="color:var(--lime)">Questions</span></h2>
    </div>

    <div class="faq-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr)); gap: 30px;">
        <div class="faq-card" style="background: rgba(255, 255, 255, 0.03); border: 1px solid var(--line); border-radius: 16px; padding: 30px;">
            <h3 style="font-size: 1.25rem; margin-bottom: 12px; color: var(--ink);">How does the Engagement Score work?</h3>
            <p style="color: var(--muted); line-height: 1.6; font-size: 0.95rem; margin: 0;">
                Your Engagement Score (0-100) measures your gym activity using five factors: 
                <strong>Attendance Frequency</strong> (last 30 days, <?= (int) get_setting('engagement_weight_attendance', '40') ?>%), 
                <strong>Class Participation</strong> (last 30 days, <?= (int) get_setting('engagement_weight_classes', '20') ?>%), 
                <strong>Consistency</strong> (active weeks in the past month, <?= (int) get_setting('engagement_weight_consistency', '20') ?>%), 
                <strong>Daily Completed Workout</strong> (completed exercises, <?= (int) get_setting('engagement_weight_workouts', '10') ?>%), and 
                <strong>Progress Updates</strong> (logging workouts in the last 60 days, <?= (int) get_setting('engagement_weight_progress', '10') ?>%).
            </p>
        </div>
        <div class="faq-card" style="background: rgba(255, 255, 255, 0.03); border: 1px solid var(--line); border-radius: 16px; padding: 30px;">
            <h3 style="font-size: 1.25rem; margin-bottom: 12px; color: var(--ink);">What are the engagement categories?</h3>
            <p style="color: var(--muted); line-height: 1.6; font-size: 0.95rem; margin: 0;">
                <?php 
                $high = (int) get_setting('engagement_threshold_high', '75');
                $mod = (int) get_setting('engagement_threshold_moderate', '40');
                ?>
                Based on your score, you are placed into one of three categories:
                <br>• <strong>Highly Engaged:</strong> Score of <?= $high ?> or higher.
                <br>• <strong>Moderately Engaged:</strong> Score between <?= $mod ?> and <?= $high - 1 ?>.
                <br>• <strong>At-Risk:</strong> Score below <?= $mod ?>. We will reach out to help you get back on track!
            </p>
        </div>
        <div class="faq-card" style="background: rgba(255, 255, 255, 0.03); border: 1px solid var(--line); border-radius: 16px; padding: 30px;">
            <h3 style="font-size: 1.25rem; margin-bottom: 12px; color: var(--ink);">What are the fitness tiers?</h3>
            <p style="color: var(--muted); line-height: 1.6; font-size: 0.95rem; margin: 0;">
                Fitness Tiers reflect your long-term dedication to completing workout plans. You level up based on completed weeks:
                <br>• Level 1: <strong>Newbie</strong>
                <br>• Level 2: <strong>Iron Recruit</strong> (1+ weeks)
                <br>• Level 3: <strong>Bronze Beast</strong> (4+ weeks)
                <br>• Level 4: <strong>Silver Spartan</strong> (12+ weeks)
                <br>• Level 5: <strong>Gold Gladiator</strong> (24+ weeks)
                <br>• Level 6: <strong>Apex Legend</strong> (52+ weeks)
            </p>
        </div>
        <div class="faq-card" style="background: rgba(255, 255, 255, 0.03); border: 1px solid var(--line); border-radius: 16px; padding: 30px;">
            <h3 style="font-size: 1.25rem; margin-bottom: 12px; color: var(--ink);">How do you calculate body fat?</h3>
            <p style="color: var(--muted); line-height: 1.6; font-size: 0.95rem; margin: 0;">
                We use the standard <strong>U.S. Navy Method</strong>. When you log your progress, you provide measurements for your height, neck, waist (and hip for females) in centimeters. Our system calculates an estimated body fat percentage using these values.
                <br><br>
                <em style="font-size: 0.85rem; opacity: 0.8;">Note: This method provides a practical estimate with a general accuracy margin of <strong>±3% to 4%</strong>. These results are not clinical and should be used primarily to track your body fat trend over time. (<a href="https://en.wikipedia.org/wiki/Body_fat_percentage#U.S._Navy_circumference_method" target="_blank" style="color: var(--lime); text-decoration: underline;">Source: U.S. Navy Circumference Method</a>) (<a href="https://pmc.ncbi.nlm.nih.gov/articles/PMC6650177/" target="_blank" style="color: var(--lime); text-decoration: underline;">Source: National Library of Medicine</a>)</em>

            </p>
        </div>
    </div>
</section>

<!-- ═══════════════ PRICING (FOR GYM OWNERS) ═══════════════ -->
<section class="landing-section" id="pricing" style="position: relative; z-index: 10;">
    <div class="section-header text-center pricing-reveal" style="text-align: center; margin-bottom: 40px;">
        <h2 style="font-size: 2.5rem; margin-bottom: 16px;">Scale Your Gym, Automate Your Operations</h2>
        <p style="font-size: 1.125rem; color: var(--muted); max-width: 600px; margin: 0 auto;">
            Choose the platform built specifically for ambitious gym owners. 
            All plans include a simple <strong style="color:var(--lime);">1% transaction fee</strong> on recorded revenue.
        </p>
    </div>
    
    <div class="pricing-cards-container" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; max-width: 1000px; margin: 0 auto; padding: 0 20px;">
        
        <!-- Starter Plan -->
        <div class="pricing-card" style="background: rgba(255, 255, 255, 0.02); border: 1px solid var(--line); border-radius: 16px; padding: 32px; display: flex; flex-direction: column; visibility: hidden;">
            <h3 style="font-size: 1.5rem; margin: 0 0 8px 0;">Starter</h3>
            <p style="color: var(--muted); font-size: 0.9rem; margin-bottom: 24px;">Best for small, independent gyms.</p>
            <div style="font-size: 2.5rem; font-weight: 700; margin-bottom: 24px;">
                ₱499<span style="font-size: 1rem; font-weight: 400; color: var(--muted);">/mo</span>
            </div>
            <ul style="list-style: none; padding: 0; margin: 0 0 32px 0; color: var(--muted); font-size: 0.95rem; display: flex; flex-direction: column; gap: 12px; flex: 1;">
                <li>✓ Up to 100 Active Members</li>
                <li>✓ Basic Analytics & Reports</li>
                <li>✓ Walk-in Management</li>
                <li>✓ Online Member Registration</li>
            </ul>
            <a href="index.php?page=register&role=gym_owner" class="btn-landing btn-landing-outline" style="text-align: center; width: 100%;">Get Started</a>
        </div>
        
        <!-- Professional Plan (Highlighted) -->
        <div class="pricing-card" style="background: color-mix(in srgb, var(--lime) 5%, rgba(0,0,0,0.4)); border: 1px solid var(--lime); border-radius: 16px; padding: 32px; display: flex; flex-direction: column; position: relative; transform: scale(1.05); z-index: 2; box-shadow: 0 20px 40px rgba(0,0,0,0.4); visibility: hidden;">
            <div style="position: absolute; top: -12px; left: 50%; transform: translateX(-50%); background: var(--lime); color: #000; font-size: 0.75rem; font-weight: 700; padding: 4px 12px; border-radius: 999px; text-transform: uppercase; letter-spacing: 1px;">Most Popular</div>
            <h3 style="font-size: 1.5rem; margin: 0 0 8px 0; color: var(--lime);">Professional</h3>
            <p style="color: var(--muted); font-size: 0.9rem; margin-bottom: 24px;">Best for growing, high-traffic gyms.</p>
            <div style="font-size: 2.5rem; font-weight: 700; margin-bottom: 24px;">
                ₱999<span style="font-size: 1rem; font-weight: 400; color: var(--muted);">/mo</span>
            </div>
            <ul style="list-style: none; padding: 0; margin: 0 0 32px 0; color: var(--ink); font-size: 0.95rem; display: flex; flex-direction: column; gap: 12px; flex: 1;">
                <li>✓ Up to 500 Active Members</li>
                <li>✓ Advanced Dashboard & Charts</li>
                <li>✓ Automated Renewal Reminders</li>
                <li>✓ Class Scheduling & Booking</li>
                <li>✓ Member Engagement Tracking</li>
            </ul>
            <a href="index.php?page=register&role=gym_owner" class="btn-landing btn-landing-primary" style="text-align: center; width: 100%;">Get Started</a>
        </div>
        
        <!-- Business Plan -->
        <div class="pricing-card" style="background: rgba(255, 255, 255, 0.02); border: 1px solid var(--line); border-radius: 16px; padding: 32px; display: flex; flex-direction: column; visibility: hidden;">
            <h3 style="font-size: 1.5rem; margin: 0 0 8px 0;">Business</h3>
            <p style="color: var(--muted); font-size: 0.9rem; margin-bottom: 24px;">Best for large or multi-branch facilities.</p>
            <div style="font-size: 2.5rem; font-weight: 700; margin-bottom: 24px;">
                ₱1,999<span style="font-size: 1rem; font-weight: 400; color: var(--muted);">/mo</span>
            </div>
            <ul style="list-style: none; padding: 0; margin: 0 0 32px 0; color: var(--muted); font-size: 0.95rem; display: flex; flex-direction: column; gap: 12px; flex: 1;">
                <li>✓ Unlimited Members</li>
                <li>✓ Multi-Branch Support</li>
                <li>✓ Dedicated Account Manager</li>
                <li>✓ Custom Branded App Theme</li>
                <li>✓ Priority 24/7 Support</li>
            </ul>
            <a href="index.php?page=register&role=gym_owner" class="btn-landing btn-landing-outline" style="text-align: center; width: 100%;">Get Started</a>
        </div>
        
    </div>
</section>

<!-- ═══════════════ CTA ═══════════════ -->
<section class="landing-cta" id="cta">
    <div class="cta-glow"></div>
    <div class="cta-content gs-reveal">
        <h2>Ready to Start Your <span style="color:var(--lime)">Transformation</span>?</h2>
        <p>Join hundreds of members who are already hitting their goals with FitTrack. Your first step starts here.</p>
        <div class="hero-cta">
            <a href="index.php?page=register" class="btn-landing btn-landing-primary">
                Create Free Account
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                    <path d="M5 12h14"></path>
                    <path d="M12 5l7 7-7 7"></path>
                </svg>
            </a>
        </div>
    </div>
</section>

<!-- ═══════════════ FOOTER ═══════════════ -->
<footer class="landing-footer">
    <span>&copy; <?= date('Y') ?> FitTrack. All rights reserved.</span>
    <span>
        <a href="index.php?page=login">Sign In</a> &middot;
        <a href="index.php?page=register">Register</a>
    </span>
</footer>

<!-- ═══════════════ GSAP + Lenis + Three.js ═══════════════ -->
<script src="https://cdn.jsdelivr.net/npm/gsap@3/dist/gsap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3/dist/ScrollTrigger.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3/dist/ScrollToPlugin.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/lenis@1/dist/lenis.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    gsap.registerPlugin(ScrollTrigger, ScrollToPlugin);

    // ─── LENIS — smooth inertia scroll, wired into GSAP's ticker/ScrollTrigger ───
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let lenis = null;

    if (!prefersReducedMotion && window.Lenis) {
        lenis = new Lenis({
            duration: 1.1,
            easing: t => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
            smoothWheel: true
        });

        lenis.on('scroll', ScrollTrigger.update);

        gsap.ticker.add(time => lenis.raf(time * 1000));
        gsap.ticker.lagSmoothing(0);

        // Let ScrollTrigger drive scroll position through Lenis instead of the native scrollbar
        ScrollTrigger.scrollerProxy(document.body, {
            scrollTop(value) {
                if (arguments.length) { lenis.scrollTo(value, { immediate: true }); }
                return lenis.scroll;
            },
            getBoundingClientRect() {
                return { top: 0, left: 0, width: window.innerWidth, height: window.innerHeight };
            }
        });
    }

    // ─── CUSTOM CURSOR ───
    const cursorDot = document.getElementById('cursor-dot');
    const cursorRing = document.getElementById('cursor-ring');
    const isTouch = window.matchMedia('(hover: none), (pointer: coarse)').matches;

    if (cursorDot && cursorRing && !isTouch) {
        document.body.classList.add('has-custom-cursor');
        let ringX = 0, ringY = 0, mouseX = 0, mouseY = 0;

        window.addEventListener('mousemove', e => {
            mouseX = e.clientX;
            mouseY = e.clientY;
            cursorDot.style.left = mouseX + 'px';
            cursorDot.style.top = mouseY + 'px';
        });

        gsap.ticker.add(() => {
            ringX += (mouseX - ringX) * 0.18;
            ringY += (mouseY - ringY) * 0.18;
            cursorRing.style.left = ringX + 'px';
            cursorRing.style.top = ringY + 'px';
        });

        document.querySelectorAll('a, button, .btn-landing, .fs-dot, .hero-3d-scene').forEach(el => {
            el.addEventListener('mouseenter', () => document.body.classList.add('cursor-hover'));
            el.addEventListener('mouseleave', () => document.body.classList.remove('cursor-hover'));
        });

        document.addEventListener('mouseleave', () => document.body.classList.add('cursor-hidden'));
        document.addEventListener('mouseenter', () => document.body.classList.remove('cursor-hidden'));
    }

    // ─── MAGNETIC BUTTONS ───
    if (!isTouch) {
        document.querySelectorAll('.btn-landing').forEach(btn => {
            const strength = 0.35;

            btn.addEventListener('mousemove', e => {
                const rect = btn.getBoundingClientRect();
                const relX = e.clientX - rect.left - rect.width / 2;
                const relY = e.clientY - rect.top - rect.height / 2;
                gsap.to(btn, { x: relX * strength, y: relY * strength, duration: 0.4, ease: 'power2.out' });
            });

            btn.addEventListener('mouseleave', () => {
                gsap.to(btn, { x: 0, y: 0, duration: 0.5, ease: 'elastic.out(1, 0.4)' });
            });
        });
    }

    // ─── SCROLL PROGRESS BAR ───
    gsap.to('#scroll-progress', {
        scaleX: 1,
        ease: 'none',
        scrollTrigger: {
            trigger: document.body,
            start: 'top top',
            end: 'bottom bottom',
            scrub: 0.3
        }
    });

    // ─── HERO TIMELINE ───
    const heroTl = gsap.timeline({ defaults: { ease: 'power3.out' } });

    // Eyebrow pill
    heroTl.from('.hero-eyebrow', {
        y: 30, opacity: 0, duration: 0.8,
        onStart() { this.targets()[0].style.visibility = 'visible'; }
    });

    // Title words — stagger with a clip effect
    heroTl.from('.hero-title .word', {
        y: 80, opacity: 0, duration: 0.9, stagger: 0.12,
        onStart() { document.querySelector('.hero-title').style.visibility = 'visible'; }
    }, '-=0.4');

    // Subtitle
    heroTl.from('.hero-subtitle', {
        y: 30, opacity: 0, duration: 0.7,
        onStart() { this.targets()[0].style.visibility = 'visible'; }
    }, '-=0.4');

    // CTA buttons
    heroTl.from('.hero-cta', {
        y: 30, opacity: 0, duration: 0.7,
        onStart() { this.targets()[0].style.visibility = 'visible'; }
    }, '-=0.35');

    // Accent line draws in
    heroTl.from('.hero-accent-line line', {
        scaleX: 0, transformOrigin: 'center', duration: 1.2, ease: 'power2.inOut',
        onStart() { document.querySelector('.hero-accent-line').style.visibility = 'visible'; }
    }, '-=0.5');

    // Scroll indicator
    heroTl.from('.scroll-indicator', {
        opacity: 0, y: 20, duration: 0.6,
        onStart() { this.targets()[0].style.visibility = 'visible'; }
    }, '-=0.3');

    // Hero visual card — clip reveal + rise
    heroTl.from('.hero-visual', {
        y: 60, opacity: 0, scale: 0.96, duration: 1,
        onStart() { document.querySelector('.hero-visual').style.visibility = 'visible'; }
    }, '-=0.4');

    // Hero visual — parallax drift while scrolling past the hero
    gsap.to('.hero-visual-frame img', {
        yPercent: 12,
        ease: 'none',
        scrollTrigger: {
            trigger: '.landing-hero',
            start: 'top top',
            end: 'bottom top',
            scrub: true
        }
    });

    // ─── FLOATING SHAPES — continuous animation ───
    document.querySelectorAll('.floating-shape').forEach((shape, i) => {
        gsap.to(shape, {
            y: `random(-40, 40)`,
            x: `random(-20, 20)`,
            rotation: `random(-25, 25)`,
            duration: `random(4, 8)`,
            repeat: -1,
            yoyo: true,
            ease: 'sine.inOut',
            delay: i * 0.3
        });
    });

    // ─── NAVBAR background on scroll ───
    ScrollTrigger.create({
        start: 'top -80',
        onEnter: () => document.getElementById('landing-nav').style.background = 'rgba(9, 11, 16, 0.92)',
        onLeaveBack: () => document.getElementById('landing-nav').style.background = 'rgba(9, 11, 16, 0.6)'
    });

    // ─── FEATURE STORY — pinned typographic scroll (CSS + text only, no 3D/video) ───
    const fsPanels = gsap.utils.toArray('.fs-panel');
    const fsDots = gsap.utils.toArray('.fs-dot');

    if (fsPanels.length) {
        let fsCurrent = 0;

        function setFsActive(i) {
            if (i === fsCurrent) return;
            const prev = fsPanels[fsCurrent];
            const next = fsPanels[i];

            gsap.to(prev, { opacity: 0, y: -24, duration: 0.45, ease: 'power2.inOut' });
            gsap.fromTo(next, { opacity: 0, y: 24 }, { opacity: 1, y: 0, duration: 0.45, ease: 'power2.inOut' });

            prev.classList.remove('active');
            next.classList.add('active');
            fsDots[fsCurrent].classList.remove('active');
            fsDots[i].classList.add('active');

            document.documentElement.style.setProperty('--fs-color', next.dataset.color);
            fsCurrent = i;
        }

        ScrollTrigger.create({
            trigger: '.feature-story',
            start: 'top top',
            end: () => '+=' + (fsPanels.length * window.innerHeight),
            pin: '.feature-story-pin',
            pinSpacing: true,
            onUpdate(self) {
                const idx = Math.min(fsPanels.length - 1, Math.floor(self.progress * fsPanels.length));
                setFsActive(idx);
            }
        });
    }

    // Section headers
    document.querySelectorAll('.section-header').forEach(header => {
        gsap.from(header.children, {
            scrollTrigger: { trigger: header, start: 'top 85%' },
            y: 40, opacity: 0, duration: 0.7, stagger: 0.1, ease: 'power2.out'
        });
    });

    // ─── SHOWCASE — clip reveal + parallax images ───
    document.querySelectorAll('.showcase-item').forEach((item, i) => {
        gsap.from(item, {
            scrollTrigger: { trigger: item, start: 'top 85%' },
            clipPath: 'inset(100% 0% 0% 0%)',
            duration: 1,
            ease: 'power3.inOut',
            delay: i * 0.08,
            onStart() { item.style.visibility = 'visible'; }
        });

        // Each image drifts at a slightly different speed for a parallax feel
        gsap.to(item.querySelector('img'), {
            yPercent: item.classList.contains('tall') ? 10 : 16,
            ease: 'none',
            scrollTrigger: {
                trigger: item,
                start: 'top bottom',
                end: 'bottom top',
                scrub: true
            }
        });

        gsap.from(item.querySelector('.showcase-caption'), {
            scrollTrigger: { trigger: item, start: 'top 70%' },
            y: 24, opacity: 0, duration: 0.6, delay: i * 0.08 + 0.3, ease: 'power2.out'
        });
    });

    // ─── TESTIMONIALS — infinite scrolling marquee ───
    const track = document.getElementById('testimonials-track');
    if (track) {
        // Duplicate the cards so the loop is seamless
        track.innerHTML += track.innerHTML;

        const marqueeTween = gsap.to(track, {
            xPercent: -50,
            ease: 'none',
            duration: 30,
            repeat: -1
        });

        track.addEventListener('mouseenter', () => marqueeTween.timeScale(0.15));
        track.addEventListener('mouseleave', () => marqueeTween.timeScale(1));

        gsap.from('.landing-testimonials .section-header', {
            scrollTrigger: { trigger: '.landing-testimonials', start: 'top 85%' },
            y: 30, opacity: 0, duration: 0.7, ease: 'power2.out'
        });
    }

    // ─── STATS — count-up animation ───
    document.querySelectorAll('[data-count]').forEach(el => {
        const target = parseInt(el.dataset.count, 10);
        const obj = { val: 0 };

        ScrollTrigger.create({
            trigger: el,
            start: 'top 90%',
            once: true,
            onEnter() {
                el.closest('.stat-item').style.visibility = 'visible';
                gsap.to(obj, {
                    val: target,
                    duration: 2,
                    ease: 'power1.out',
                    snap: { val: 1 },
                    onUpdate() { el.textContent = Math.round(obj.val); }
                });
            }
        });
    });

    // Stat items fade in
    gsap.from('.stat-item', {
        scrollTrigger: { trigger: '.stats-grid', start: 'top 85%' },
        y: 30, opacity: 0, duration: 0.6, stagger: 0.1,
        onStart() {
            document.querySelectorAll('.stat-item').forEach(s => s.style.visibility = 'visible');
        }
    });

    // ─── STEPS — scroll-triggered stagger ───
    gsap.from('.step-card', {
        scrollTrigger: { trigger: '.steps-grid', start: 'top 80%' },
        y: 50, opacity: 0, duration: 0.7, stagger: 0.15, ease: 'power2.out',
        onStart() {
            document.querySelectorAll('.step-card').forEach(c => c.style.visibility = 'visible');
        }
    });

    // ─── WHAT YOU GET — stagger rows ───
    gsap.from('.wyg-row', {
        scrollTrigger: { trigger: '#what-you-get', start: 'top 80%' },
        y: 30, opacity: 0, duration: 0.6, stagger: 0.1, ease: 'power2.out'
    });

    // ─── ENGAGEMENT SCORE — animated bar fill ───
    ScrollTrigger.create({
        trigger: '#engagement-score',
        start: 'top 75%',
        once: true,
        onEnter() {
            document.querySelectorAll('.eng-bar-fill').forEach(bar => {
                bar.style.width = bar.dataset.width;
            });
        }
    });

    // ─── FITNESS TIERS — stagger columns ───
    gsap.from('.tier-col', {
        scrollTrigger: { trigger: '#fitness-tiers', start: 'top 80%' },
        y: 24, opacity: 0, duration: 0.5, stagger: 0.08, ease: 'power2.out'
    });

    // ─── CTA — zoom-in entrance ───
    gsap.from('.cta-content', {
        scrollTrigger: { trigger: '.landing-cta', start: 'top 80%' },
        scale: 0.9, opacity: 0, duration: 0.8, ease: 'power2.out',
        onStart() { document.querySelector('.cta-content').style.visibility = 'visible'; }
    });

    // ─── PRICING — stagger cards ───
    gsap.from('.pricing-card', {
        scrollTrigger: { trigger: '#pricing', start: 'top 75%' },
        y: 40, opacity: 0, duration: 0.6, stagger: 0.15, ease: 'power2.out',
        onStart() {
            document.querySelectorAll('.pricing-card').forEach(c => c.style.visibility = 'visible');
        }
    });

    // ─── SMOOTH SCROLL for anchor links ───
    document.querySelectorAll('a[href^="#"]').forEach(link => {
        link.addEventListener('click', e => {
            e.preventDefault();
            const target = document.querySelector(link.getAttribute('href'));
            if (!target) return;

            if (lenis) {
                lenis.scrollTo(target, { offset: -60, duration: 1.1 });
            } else {
                gsap.to(window, { duration: 1, scrollTo: { y: target, offsetY: 60 }, ease: 'power2.inOut' });
            }
        });
    });

    // ─── HERO CAROUSEL (SWIPER) ───
    if (typeof Swiper !== 'undefined') {
        new Swiper('.hero-swiper', {
            effect: 'coverflow',
            grabCursor: true,
            centeredSlides: true,
            slidesPerView: 'auto',
            loop: true,
            autoplay: {
                delay: 3500,
                disableOnInteraction: false,
            },
            coverflowEffect: {
                rotate: 15,
                stretch: 0,
                depth: 250,
                modifier: 1,
                slideShadows: true,
            },
        });
    }

    // ─── MOBILE HAMBURGER MENU ───
    const hamburgerBtn = document.getElementById('landing-hamburger');
    const mobileMenu = document.getElementById('landing-mobile-menu');

    if (hamburgerBtn && mobileMenu) {
        function toggleMobileMenu(forceClose = false) {
            const isOpen = forceClose ? false : !mobileMenu.classList.contains('open');
            hamburgerBtn.classList.toggle('active', isOpen);
            mobileMenu.classList.toggle('open', isOpen);
            hamburgerBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            document.body.style.overflow = isOpen ? 'hidden' : '';
        }

        hamburgerBtn.addEventListener('click', () => toggleMobileMenu());

        mobileMenu.querySelectorAll('.mobile-nav-link, .mobile-menu-footer a').forEach(link => {
            link.addEventListener('click', e => {
                const href = link.getAttribute('href');
                toggleMobileMenu(true);
                if (href && href.startsWith('#')) {
                    e.preventDefault();
                    const target = document.querySelector(href);
                    if (target) {
                        if (lenis) {
                            lenis.scrollTo(target, { offset: -60, duration: 1.1 });
                        } else {
                            gsap.to(window, { duration: 1, scrollTo: { y: target, offsetY: 60 }, ease: 'power2.inOut' });
                        }
                    }
                }
            });
        });

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && mobileMenu.classList.contains('open')) {
                toggleMobileMenu(true);
            }
        });
    }
});

</script>
</body>
</html>
<?php
}