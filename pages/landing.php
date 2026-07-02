<?php
declare(strict_types=1);

function landing_page(): void
{
    // If user is already logged in, send straight to dashboard
    if (current_user()) {
        redirect('dashboard');
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/landing.css">
</head>
<body class="landing-page">

<!-- ═══════════════ NAVBAR ═══════════════ -->
<nav class="landing-nav" id="landing-nav">
    <a href="index.php" class="landing-nav-brand">
        <span class="brand-icon">FT</span>
        <span>FitTrack</span>
    </a>
    <div class="landing-nav-links">
        <a href="#features" class="nav-link">Features</a>
        <a href="#how-it-works" class="nav-link">How It Works</a>
        <a href="index.php?page=login" class="btn-landing btn-landing-outline">Sign In</a>
        <a href="index.php?page=register" class="btn-landing btn-landing-primary">Get Started</a>
    </div>
</nav>

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
            Track workouts, connect with expert coaches, and watch your progress unfold — all in one powerful platform built to push your limits.
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

<!-- ═══════════════ FEATURES ═══════════════ -->
<section class="landing-features" id="features">
    <div class="section-header">
        <div class="section-label">Features</div>
        <h2 class="section-title">Everything You Need to <span style="color:var(--lime)">Level Up</span></h2>
        <p class="section-desc">From personalised workouts to real-time progress tracking — FitTrack gives you and your coaches the tools to win.</p>
    </div>

    <div class="features-grid">
        <!-- Card 1 -->
        <div class="feature-card gs-reveal">
            <div class="feature-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 20V10"/>
                    <path d="M12 20V4"/>
                    <path d="M6 20v-6"/>
                </svg>
            </div>
            <h3>Track Workouts</h3>
            <p>Log every set, rep, and mile. Your personalised workout plan adapts as you grow stronger and push new limits.</p>
        </div>

        <!-- Card 2 -->
        <div class="feature-card gs-reveal">
            <div class="feature-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
            </div>
            <h3>Expert Coaching</h3>
            <p>Connect with certified trainers through real-time messaging. Get guidance, feedback, and custom training plans tailored to you.</p>
        </div>

        <!-- Card 3 -->
        <div class="feature-card gs-reveal">
            <div class="feature-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                </svg>
            </div>
            <h3>Progress Analytics</h3>
            <p>Visualise your transformation with detailed charts and milestone tracking. Celebrate every win on the road to your goal.</p>
        </div>

        <!-- Card 4 -->
        <div class="feature-card gs-reveal">
            <div class="feature-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/>
                    <line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
            </div>
            <h3>Class Booking</h3>
            <p>Browse and book group fitness classes in seconds. Never miss a session with smart schedule management and reminders.</p>
        </div>

        <!-- Card 5 -->
        <div class="feature-card gs-reveal">
            <div class="feature-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="2" width="20" height="20" rx="5" ry="5"/>
                    <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/>
                    <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/>
                </svg>
            </div>
            <h3>QR Check-in</h3>
            <p>Scan your personal QR code at the door for instant, contactless attendance tracking. No cards, no hassle.</p>
        </div>

        <!-- Card 6 -->
        <div class="feature-card gs-reveal">
            <div class="feature-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
            </div>
            <h3>Membership Management</h3>
            <p>Flexible plans, seamless renewals, and transparent payment tracking. Your membership, your way — fully digital.</p>
        </div>
    </div>
</section>

<!-- ═══════════════ STATS ═══════════════ -->
<section class="landing-stats" id="stats">
    <div class="stats-grid">
        <div class="stat-item gs-reveal">
            <div class="stat-number"><span data-count="500">0</span><span class="suffix">+</span></div>
            <div class="stat-label">Active Members</div>
        </div>
        <div class="stat-item gs-reveal">
            <div class="stat-number"><span data-count="50">0</span><span class="suffix">+</span></div>
            <div class="stat-label">Weekly Classes</div>
        </div>
        <div class="stat-item gs-reveal">
            <div class="stat-number"><span data-count="20">0</span><span class="suffix">+</span></div>
            <div class="stat-label">Expert Trainers</div>
        </div>
        <div class="stat-item gs-reveal">
            <div class="stat-number"><span data-count="98">0</span><span class="suffix">%</span></div>
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

<!-- ═══════════════ GSAP ═══════════════ -->
<script src="https://cdn.jsdelivr.net/npm/gsap@3/dist/gsap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3/dist/ScrollTrigger.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3/dist/ScrollToPlugin.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    gsap.registerPlugin(ScrollTrigger, ScrollToPlugin);

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

    // ─── FEATURE CARDS — scroll-triggered stagger ───
    gsap.from('.feature-card', {
        scrollTrigger: {
            trigger: '.features-grid',
            start: 'top 80%',
        },
        y: 60,
        opacity: 0,
        duration: 0.7,
        stagger: 0.12,
        ease: 'power2.out',
        onStart() {
            document.querySelectorAll('.feature-card').forEach(c => c.style.visibility = 'visible');
        }
    });

    // Section headers
    document.querySelectorAll('.section-header').forEach(header => {
        gsap.from(header.children, {
            scrollTrigger: { trigger: header, start: 'top 85%' },
            y: 40, opacity: 0, duration: 0.7, stagger: 0.1, ease: 'power2.out'
        });
    });

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

    // ─── CTA — zoom-in entrance ───
    gsap.from('.cta-content', {
        scrollTrigger: { trigger: '.landing-cta', start: 'top 80%' },
        scale: 0.9, opacity: 0, duration: 0.8, ease: 'power2.out',
        onStart() { document.querySelector('.cta-content').style.visibility = 'visible'; }
    });

    // ─── SMOOTH SCROLL for anchor links ───
    document.querySelectorAll('a[href^="#"]').forEach(link => {
        link.addEventListener('click', e => {
            e.preventDefault();
            const target = document.querySelector(link.getAttribute('href'));
            if (target) {
                gsap.to(window, { duration: 1, scrollTo: { y: target, offsetY: 60 }, ease: 'power2.inOut' });
            }
        });
    });
});
</script>
</body>
</html>
<?php
}
