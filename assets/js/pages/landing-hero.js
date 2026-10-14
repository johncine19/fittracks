/**
 * FitTrack Landing — Immersive Hero Controller
 *
 * Drives everything that moves in the hero (#hero):
 *   1. Entrance choreography (waits for the splash screen to clear)
 *   2. Rotating headline verb
 *   3. Simulated live product HUD (check-in feed ↔ QR scan card ↔ counter)
 *   4. One rAF loop for: cursor "flashlight", photo drift, card depth
 *      parallax, scroll dolly / scroll-out, and the dust particles
 *   5. Magnetic CTA buttons
 *
 * The loop only runs while the hero is on screen and the tab is visible.
 * Under prefers-reduced-motion the hero is composed statically.
 */
(() => {
    'use strict';

    const hero = document.getElementById('hero');
    if (!hero) return;

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

    const stage = hero.querySelector('.hx-stage');
    const dolly = hero.querySelector('.hx-dolly');
    const drift = hero.querySelector('.hx-drift');
    const copy = hero.querySelector('.hx-copy');
    const floats = Array.from(hero.querySelectorAll('.hx-float[data-depth]'));
    const canvas = hero.querySelector('.hx-particles');

    const clamp = (v, min, max) => Math.min(max, Math.max(min, v));
    const lerp = (a, b, t) => a + (b - a) * t;

    let heroVisible = true;
    let started = false;
    const onStart = [];

    /* ── 1. Entrance ─────────────────────────────────────────────────── */
    function start() {
        if (started) return;
        started = true;
        hero.classList.remove('is-pending');
        onStart.forEach((fn) => fn());
    }

    function startWhenSplashClears() {
        const splash = document.getElementById('ft-splash-screen');
        const splashGone = !splash
            || document.documentElement.classList.contains('ft-no-splash')
            || splash.classList.contains('ft-splash-hidden');

        if (splashGone) {
            // Two frames so the pending pose is painted before transitions fire.
            requestAnimationFrame(() => requestAnimationFrame(start));
            return;
        }

        const observer = new MutationObserver(() => {
            if (splash.classList.contains('ft-splash-hidden') || !splash.isConnected) {
                observer.disconnect();
                start();
            }
        });
        observer.observe(splash, { attributes: true, attributeFilter: ['class'] });
        setTimeout(start, 2800); // failsafe, matches splash.js max timeout
    }

    /* ── 2. Rotating headline verb ───────────────────────────────────── */
    function initRotator() {
        const rotator = hero.querySelector('[data-hx-rotator]');
        if (!rotator) return;

        const words = Array.from(rotator.querySelectorAll('.hx-word'));
        let index = Math.max(0, words.findIndex((w) => w.classList.contains('is-active')));

        const fit = () => { rotator.style.width = words[index].offsetWidth + 'px'; };
        fit();
        window.addEventListener('resize', fit, { passive: true });
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);

        if (reduceMotion || words.length < 2) return;

        onStart.push(() => {
            setTimeout(() => {
                setInterval(() => {
                    if (document.hidden || !heroVisible) return;
                    const prev = words[index];
                    index = (index + 1) % words.length;
                    const next = words[index];

                    prev.classList.remove('is-active');
                    prev.classList.add('is-leaving');
                    next.classList.remove('is-leaving');
                    next.classList.add('is-active');
                    fit();
                    setTimeout(() => prev.classList.remove('is-leaving'), 750);
                }, 2600);
            }, 1400);
        });
    }

    /* ── 3. Live product HUD ─────────────────────────────────────────── */
    const ARRIVALS = [
        ['Miguel Reyes', 'Annual', 'Active', false],
        ['Bea Tolentino', 'PT · Coach Mia', 'Active', false],
        ['Carlo Dizon', 'Monthly', 'Renews in 3d', true],
        ['Jasmine Lim', 'Quarterly', 'Active', false],
        ['Paolo Villanueva', 'Day pass', 'Walk-in', false],
        ['Trisha Mendoza', 'Monthly', 'Active', false],
        ['Nico Aquino', 'Annual', 'Renews in 6d', true],
        ['Kaye Panganiban', 'PT · Coach Leo', 'Active', false],
        ['Rafael Cruz', 'Monthly', 'Active', false],
        ['Andrea Santos', 'Quarterly', 'Active', false],
    ];

    const initials = (name) => name.split(' ').map((p) => p[0]).join('').slice(0, 2).toUpperCase();
    const shortName = (name) => {
        const [first, last] = name.split(' ');
        return last ? `${first} ${last[0]}.` : first;
    };
    const hueFor = (name) => {
        let h = 0;
        for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) % 360;
        return h;
    };
    const clockNow = () => new Date().toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });

    function buildRow([name, plan, status, warn]) {
        const li = document.createElement('li');
        li.className = 'hx-feed-row is-new';

        const avatar = document.createElement('span');
        avatar.className = 'hx-avatar';
        avatar.style.setProperty('--h', hueFor(name));
        avatar.textContent = initials(name);

        const meta = document.createElement('span');
        meta.className = 'hx-feed-meta';
        const b = document.createElement('b');
        b.textContent = shortName(name);
        const small = document.createElement('small');
        small.textContent = plan + ' · ';
        const em = document.createElement('em');
        em.className = 'hx-status' + (warn ? ' is-warn' : '');
        em.textContent = status;
        small.appendChild(em);
        meta.append(b, small);

        const time = document.createElement('span');
        time.className = 'hx-feed-time';
        time.dataset.min = '0';
        time.textContent = 'now';

        li.append(avatar, meta, time);
        return li;
    }

    function initHud() {
        const list = hero.querySelector('[data-hx-feed]');
        const countEl = hero.querySelector('[data-hx-count]');
        const scanCard = hero.querySelector('.hx-scan');
        const scanSub = hero.querySelector('[data-hx-scan-sub]');
        const revenueEl = hero.querySelector('[data-hx-revenue]');

        // Revenue count-up
        if (revenueEl) {
            const target = parseInt(revenueEl.dataset.target, 10) || 0;
            const format = (n) => Math.round(n).toLocaleString('en-PH');
            if (reduceMotion) {
                revenueEl.textContent = format(target);
            } else {
                revenueEl.textContent = '0';
                onStart.push(() => {
                    const t0 = performance.now() + 900;
                    const dur = 1900;
                    const step = (now) => {
                        const t = clamp((now - t0) / dur, 0, 1);
                        const eased = t === 1 ? 1 : 1 - Math.pow(2, -10 * t);
                        revenueEl.textContent = format(target * eased);
                        if (t < 1) requestAnimationFrame(step);
                    };
                    requestAnimationFrame(step);
                });
            }
        }

        if (!list || reduceMotion) return;

        let count = parseInt(countEl ? countEl.textContent : '0', 10) || 0;
        let cursor = 0;

        const tick = () => {
            if (document.hidden || !heroVisible) return;
            const person = ARRIVALS[cursor++ % ARRIVALS.length];

            list.querySelectorAll('.hx-feed-row').forEach((row) => row.classList.remove('is-new'));
            list.querySelectorAll('.hx-feed-time').forEach((el) => {
                const min = (parseInt(el.dataset.min || '0', 10) || 0) + 2;
                el.dataset.min = String(min);
                el.textContent = min + 'm';
            });

            list.prepend(buildRow(person));

            const rows = list.querySelectorAll('.hx-feed-row:not(.is-out)');
            if (rows.length > 4) {
                const last = rows[rows.length - 1];
                last.classList.add('is-out');
                setTimeout(() => last.remove(), 480);
            }

            if (countEl) {
                count += 1;
                countEl.textContent = String(count);
                countEl.classList.remove('is-bump');
                void countEl.offsetWidth;
                countEl.classList.add('is-bump');
            }

            if (scanCard && scanSub) {
                scanSub.textContent = `${shortName(person[0])} · ${clockNow()}`;
                scanCard.classList.remove('is-flash');
                void scanCard.offsetWidth;
                scanCard.classList.add('is-flash');
            }
        };

        onStart.push(() => {
            setTimeout(() => {
                tick();
                setInterval(tick, 3400);
            }, 2600);
        });
    }

    /* ── 4. Dust particles ───────────────────────────────────────────── */
    function createParticles(el) {
        const ctx = el && el.getContext ? el.getContext('2d') : null;
        if (!ctx) return null;

        let w = 0;
        let h = 0;
        let motes = [];

        const spawn = (anywhere) => ({
            x: Math.random() * w,
            y: anywhere ? Math.random() * h : h + 8,
            r: Math.random() * 1.5 + 0.35,
            vx: (Math.random() - 0.5) * 0.12,
            vy: -(Math.random() * 0.22 + 0.04),
            phase: Math.random() * Math.PI * 2,
            alpha: Math.random() * 0.28 + 0.06,
            lime: Math.random() < 0.35,
        });

        return {
            resize(width, height) {
                const dpr = Math.min(window.devicePixelRatio || 1, 2);
                w = width;
                h = height;
                el.width = Math.round(width * dpr);
                el.height = Math.round(height * dpr);
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
                const n = Math.round(clamp((width * height) / 15000, 24, 120));
                motes = Array.from({ length: n }, () => spawn(true));
            },
            step(now, lx, ly, radius) {
                ctx.clearRect(0, 0, w, h);
                for (const m of motes) {
                    m.x += m.vx + Math.sin(now * 0.0005 + m.phase) * 0.1;
                    m.y += m.vy;
                    if (m.y < -8 || m.x < -8 || m.x > w + 8) Object.assign(m, spawn(false));

                    // Motes catch the light when they drift through the beam.
                    const dx = m.x - lx;
                    const dy = m.y - ly;
                    const d = Math.sqrt(dx * dx + dy * dy);
                    const lit = d < radius ? 1 - d / radius : 0;
                    const twinkle = 0.65 + 0.35 * Math.sin(now * 0.0021 + m.phase);
                    const a = Math.min(1, m.alpha * twinkle + lit * lit * 0.85);

                    ctx.fillStyle = m.lime ? `rgba(190,242,100,${a})` : `rgba(236,240,245,${a})`;
                    ctx.beginPath();
                    ctx.arc(m.x, m.y, m.r * (1 + lit * 0.9), 0, Math.PI * 2);
                    ctx.fill();
                }
            },
        };
    }

    /* ── 5. Main loop: light, depth, scroll ──────────────────────────── */
    function initMotion() {
        if (reduceMotion || !stage) return;

        const particles = createParticles(canvas);
        let w = 0;
        let h = 0;
        let radius = 460;
        let rafId = 0;
        let running = false;
        let lastPointer = -Infinity;

        // Light position, normalised 0..1 within the hero.
        let tx = 0.66;
        let ty = 0.46;
        let x = tx;
        let y = ty;

        const measure = () => {
            w = hero.clientWidth;
            h = hero.clientHeight;
            radius = clamp(w * 0.3, 240, 540);
            stage.style.setProperty('--hx-r', radius.toFixed(0) + 'px');
            if (particles) particles.resize(w, h);
        };

        const frame = (now) => {
            rafId = 0;
            if (!running) return;

            // Follow the cursor; when idle (or on touch) the light wanders.
            const idle = !finePointer || now - lastPointer > 3500;
            if (idle) {
                tx = 0.64 + 0.2 * Math.sin(now * 0.00021);
                ty = 0.48 + 0.16 * Math.sin(now * 0.00029 + 1.3);
            }
            const ease = idle ? 0.025 : 0.1;
            x = lerp(x, tx, ease);
            y = lerp(y, ty, ease);

            const lx = x * w;
            const ly = y * h;
            stage.style.setProperty('--mx', lx.toFixed(1) + 'px');
            stage.style.setProperty('--my', ly.toFixed(1) + 'px');

            const nx = (x - 0.5) * 2;
            const ny = (y - 0.5) * 2;
            const progress = clamp((window.scrollY || 0) / (h * 0.9), 0, 1);

            if (drift) drift.style.transform = `translate3d(${(-nx * 18).toFixed(2)}px, ${(-ny * 12).toFixed(2)}px, 0)`;
            // Scrolling pushes the camera down the gym floor.
            if (dolly) dolly.style.transform = `scale(${(1 + progress * 0.26).toFixed(4)})`;

            if (copy) {
                copy.style.transform = `translate3d(0, ${(-progress * 70).toFixed(1)}px, 0)`;
                copy.style.opacity = clamp(1 - progress * 1.15, 0, 1).toFixed(3);
            }

            const parallaxOn = finePointer && w > 1080;
            floats.forEach((el) => {
                const depth = parseFloat(el.dataset.depth) || 1;
                const px = parallaxOn ? nx * depth * 16 : 0;
                const py = (parallaxOn ? ny * depth * 12 : 0) - progress * depth * 130;
                const rx = parallaxOn ? -ny * 5 : 0;
                const ry = parallaxOn ? nx * 7 : 0;
                el.style.transform = `translate3d(${px.toFixed(2)}px, ${py.toFixed(2)}px, 0) rotateX(${rx.toFixed(2)}deg) rotateY(${ry.toFixed(2)}deg)`;
            });

            if (particles) particles.step(now, lx, ly, radius * 0.75);

            rafId = requestAnimationFrame(frame);
        };

        const play = () => {
            if (running) return;
            running = true;
            if (!rafId) rafId = requestAnimationFrame(frame);
        };
        const pause = () => {
            running = false;
            if (rafId) cancelAnimationFrame(rafId);
            rafId = 0;
        };
        const sync = () => (heroVisible && !document.hidden ? play() : pause());

        if (finePointer) {
            hero.addEventListener('pointermove', (e) => {
                const rect = hero.getBoundingClientRect();
                tx = clamp((e.clientX - rect.left) / rect.width, 0, 1);
                ty = clamp((e.clientY - rect.top) / rect.height, 0, 1);
                lastPointer = performance.now();
            }, { passive: true });
        }

        let resizeTimer = 0;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(measure, 120);
        }, { passive: true });

        if ('IntersectionObserver' in window) {
            new IntersectionObserver(([entry]) => {
                heroVisible = entry.isIntersecting;
                sync();
            }, { threshold: 0 }).observe(hero);
        }
        document.addEventListener('visibilitychange', sync);

        measure();
        sync();
    }

    /* ── 6. Magnetic CTAs & scroll cue ───────────────────────────────── */
    function initButtons() {
        if (finePointer && !reduceMotion) {
            hero.querySelectorAll('.hx-magnetic').forEach((btn) => {
                btn.addEventListener('pointermove', (e) => {
                    const r = btn.getBoundingClientRect();
                    const dx = (e.clientX - (r.left + r.width / 2)) * 0.22;
                    const dy = (e.clientY - (r.top + r.height / 2)) * 0.32;
                    btn.style.setProperty('--tx', dx.toFixed(1) + 'px');
                    btn.style.setProperty('--ty', dy.toFixed(1) + 'px');
                });
                btn.addEventListener('pointerleave', () => {
                    btn.style.setProperty('--tx', '0px');
                    btn.style.setProperty('--ty', '0px');
                });
            });
        }

        const cue = hero.querySelector('[data-hx-scroll]');
        if (cue) {
            cue.addEventListener('click', () => {
                const next = hero.nextElementSibling;
                if (next) next.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
            });
        }
    }

    initRotator();
    initHud();
    initMotion();
    initButtons();
    startWhenSplashClears();
})();
