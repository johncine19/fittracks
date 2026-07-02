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
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400..800&family=IBM+Plex+Mono:wght@400;500&family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/landing.css">
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

    <!-- Interactive 3D scene -->
    <div class="hero-3d-scene" id="hero-3d-scene">
        <div class="hero-3d-hint">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M9 12l-4 0m0 0l3-3m-3 3l3 3"/><path d="M15 12l4 0m0 0l-3-3m3 3l-3 3"/></svg>
            <span>Drag to rotate</span>
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

        <!-- Hero visual — app preview card -->
        <div class="hero-visual gs-reveal">
            <div class="hero-visual-glow"></div>
            <div class="hero-visual-frame">
                <img src="assets/images/gym.avif" alt="Members training at FitTrack" loading="lazy">
                <div class="hero-visual-overlay"></div>
            </div>
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
                <h2 class="fs-title">A Coach In<br><span class="fs-highlight">Your Pocket.</span></h2>
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
                <img src="assets/images/sky.png" alt="Member weight training" loading="lazy">
            </div>
            <div class="showcase-caption">
                <span class="tag">Strength</span>
                <h4>Guided Strength Training</h4>
            </div>
        </div>
        <div class="showcase-item short">
            <div class="img-wrap">
                <img src="assets/images/violet.png" alt="Group fitness class" loading="lazy">
            </div>
            <div class="showcase-caption">
                <span class="tag">Group Classes</span>
                <h4>Book a Session in Seconds</h4>
            </div>
        </div>
        <div class="showcase-item short">
            <div class="img-wrap">
                <img src="assets/images/green.png" alt="Cardio training session" loading="lazy">
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

<!-- ═══════════════ TESTIMONIALS ═══════════════ -->
<section class="landing-testimonials" id="testimonials">
    <div class="section-header">
        <div class="section-label">Member Stories</div>
        <h2 class="section-title">Trusted by <span style="color:var(--lime)">Real Members</span></h2>
    </div>

    <div class="fade-edge left"></div>
    <div class="fade-edge right"></div>
    <div class="testimonials-marquee" id="testimonials-track">
        <div class="testimonial-card">
            <div class="testimonial-stars">★★★★★</div>
            <p class="testimonial-quote">"The QR check-in alone saved me so much time. I actually look forward to logging my workouts now."</p>
            <div class="testimonial-person">
                <img src="https://i.pravatar.cc/150?img=12" alt="Member avatar" loading="lazy">
                <div>
                    <div class="name">Mika R.</div>
                    <div class="role">Member since 2024</div>
                </div>
            </div>
        </div>
        <div class="testimonial-card">
            <div class="testimonial-stars">★★★★★</div>
            <p class="testimonial-quote">"My coach messages me directly through the app. It genuinely feels like personal training, not a gym membership."</p>
            <div class="testimonial-person">
                <img src="https://i.pravatar.cc/150?img=32" alt="Member avatar" loading="lazy">
                <div>
                    <div class="name">Josh T.</div>
                    <div class="role">Strength Program</div>
                </div>
            </div>
        </div>
        <div class="testimonial-card">
            <div class="testimonial-stars">★★★★★</div>
            <p class="testimonial-quote">"Booking classes used to be a headache. Now it takes ten seconds and I never lose my spot."</p>
            <div class="testimonial-person">
                <img src="https://i.pravatar.cc/150?img=45" alt="Member avatar" loading="lazy">
                <div>
                    <div class="name">Anna L.</div>
                    <div class="role">Group Fitness</div>
                </div>
            </div>
        </div>
        <div class="testimonial-card">
            <div class="testimonial-stars">★★★★★</div>
            <p class="testimonial-quote">"Watching the progress chart climb every week keeps me way more motivated than a paper logbook ever did."</p>
            <div class="testimonial-person">
                <img src="https://i.pravatar.cc/150?img=5" alt="Member avatar" loading="lazy">
                <div>
                    <div class="name">Carlo D.</div>
                    <div class="role">Member since 2023</div>
                </div>
            </div>
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
<script src="https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.min.js"></script>
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
            if (!target) return;

            if (lenis) {
                lenis.scrollTo(target, { offset: -60, duration: 1.1 });
            } else {
                gsap.to(window, { duration: 1, scrollTo: { y: target, offsetY: 60 }, ease: 'power2.inOut' });
            }
        });
    });

    // ─── HERO 3D SCENE — interactive low-poly shape, drag to rotate ───
    initHero3D();
});

function initHero3D() {
    const container = document.getElementById('hero-3d-scene');
    if (!container || typeof THREE === 'undefined') return;

    // Skip the WebGL scene on small screens and reduced-motion — keep the hero light
    const isSmallScreen = window.matchMedia('(max-width: 900px)').matches;
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (isSmallScreen) { container.style.display = 'none'; return; }

    const LIME = 0xff4d24; // ember accent, kept var name for minimal diff
    let width = container.clientWidth;
    let height = container.clientHeight;

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(42, width / height, 0.1, 100);
    camera.position.set(0, 0, 7);

    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.setSize(width, height);
    container.appendChild(renderer.domElement);

    // Lighting — dark scene, single lime key light for that "gym at night" feel
    scene.add(new THREE.AmbientLight(0x404050, 1.2));
    const keyLight = new THREE.PointLight(LIME, 14, 20);
    keyLight.position.set(3, 3, 4);
    scene.add(keyLight);
    const fillLight = new THREE.PointLight(0x4a4038, 4, 20);
    fillLight.position.set(-4, -2, -3);
    scene.add(fillLight);

    // Rig — everything rotates together
    const rig = new THREE.Group();
    scene.add(rig);

    // Outer wireframe icosahedron — the "energy shell"
    const outerGeo = new THREE.IcosahedronGeometry(2.1, 1);
    const outerEdges = new THREE.EdgesGeometry(outerGeo);
    const outerWire = new THREE.LineSegments(
        outerEdges,
        new THREE.LineBasicMaterial({ color: LIME, transparent: true, opacity: 0.55 })
    );
    rig.add(outerWire);

    // Inner solid core
    const coreGeo = new THREE.IcosahedronGeometry(1.15, 1);
    const coreMat = new THREE.MeshStandardMaterial({
        color: 0x10131b,
        emissive: LIME,
        emissiveIntensity: 0.15,
        roughness: 0.35,
        metalness: 0.6,
        flatShading: true
    });
    const core = new THREE.Mesh(coreGeo, coreMat);
    rig.add(core);

    // Orbiting particles — reps / progress dots
    const particleCount = 60;
    const particlePositions = new Float32Array(particleCount * 3);
    for (let i = 0; i < particleCount; i++) {
        const r = 2.6 + Math.random() * 0.6;
        const theta = Math.random() * Math.PI * 2;
        const phi = Math.acos((Math.random() * 2) - 1);
        particlePositions[i * 3] = r * Math.sin(phi) * Math.cos(theta);
        particlePositions[i * 3 + 1] = r * Math.sin(phi) * Math.sin(theta);
        particlePositions[i * 3 + 2] = r * Math.cos(phi);
    }
    const particleGeo = new THREE.BufferGeometry();
    particleGeo.setAttribute('position', new THREE.BufferAttribute(particlePositions, 3));
    const particles = new THREE.Points(particleGeo, new THREE.PointsMaterial({
        color: LIME, size: 0.035, transparent: true, opacity: 0.7
    }));
    rig.add(particles);

    // ─── Interaction: drag to rotate, momentum on release, gentle auto-spin when idle ───
    let isDragging = false;
    let prevX = 0, prevY = 0;
    let velX = 0, velY = 0;
    let idleSpin = prefersReducedMotion ? 0 : 0.0022;

    const onPointerDown = e => {
        isDragging = true;
        prevX = e.clientX; prevY = e.clientY;
        container.classList.add('dragging');
    };
    const onPointerMove = e => {
        if (!isDragging) return;
        const dx = e.clientX - prevX;
        const dy = e.clientY - prevY;
        velX = dx * 0.005;
        velY = dy * 0.005;
        rig.rotation.y += velX;
        rig.rotation.x += velY;
        prevX = e.clientX; prevY = e.clientY;
    };
    const onPointerUp = () => {
        isDragging = false;
        container.classList.remove('dragging');
    };

    container.addEventListener('pointerdown', onPointerDown);
    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
    container.style.touchAction = 'none';

    // ─── Pause rendering when off-screen or tab hidden — saves battery ───
    let isVisible = true;
    new IntersectionObserver(entries => {
        isVisible = entries[0].isIntersecting;
    }, { threshold: 0.05 }).observe(container);

    function animate() {
        requestAnimationFrame(animate);
        if (!isVisible || document.hidden) return;

        if (!isDragging) {
            rig.rotation.y += idleSpin + Math.abs(velX) * 0.02;
            rig.rotation.x += velY * 0.02;
            velX *= 0.92;
            velY *= 0.92;
        }
        particles.rotation.y -= 0.0009;

        renderer.render(scene, camera);
    }
    animate();

    // ─── Resize handling ───
    const resizeObserver = new ResizeObserver(() => {
        width = container.clientWidth;
        height = container.clientHeight;
        if (!width || !height) return;
        camera.aspect = width / height;
        camera.updateProjectionMatrix();
        renderer.setSize(width, height);
    });
    resizeObserver.observe(container);
}
</script>
</body>
</html>
<?php
}