/**
 * FitTrack Architecture Pipeline — Split, Merge, and Transfer Animation Engine
 * Dynamically computes pixel-perfect SVG perimeter paths (top/bottom or left/right halves)
 * matching the card's exact dimensions and border-radius on any viewport.
 */
(function () {
    'use strict';

    function initArchitecturePaths() {
        const pipeline = document.querySelector('.arch-pipeline');
        if (!pipeline) return;

        const cards = pipeline.querySelectorAll('.arch-card');
        if (!cards.length) return;

        function updatePaths() {
            const isMobile = window.innerWidth <= 900;
            const r = window.innerWidth <= 640 ? 16 : 20;

            cards.forEach(card => {
                const svg = card.querySelector('.arch-border-svg');
                if (!svg) return;

                const topPath = svg.querySelector('.arch-path-top');
                const btmPath = svg.querySelector('.arch-path-bottom');
                if (!topPath || !btmPath) return;

                const w = Math.round(card.offsetWidth);
                const h = Math.round(card.offsetHeight);
                if (!w || !h) return;

                if (!isMobile) {
                    // DESKTOP: Start at Left-Center (1, midY), split top & bottom, merge at Right-Center (w-1, midY)
                    const midY = Math.round(h / 2);

                    // Top path: starts at (1, midY), goes up to (1, r+1), arcs around top-left, lines across top, arcs around top-right, lines down to (w-1, midY)
                    const topD = `M 1,${midY} L 1,${r + 1} A ${r},${r} 0 0 1 ${r + 1},1 L ${w - 1 - r},1 A ${r},${r} 0 0 1 ${w - 1},${r + 1} L ${w - 1},${midY}`;

                    // Bottom path: starts at (1, midY), goes down to (1, h-1-r), arcs around bottom-left, lines across bottom, arcs around bottom-right, lines up to (w-1, midY)
                    const btmD = `M 1,${midY} L 1,${h - 1 - r} A ${r},${r} 0 0 0 ${r + 1},${h - 1} L ${w - 1 - r},${h - 1} A ${r},${r} 0 0 0 ${w - 1},${h - 1 - r} L ${w - 1},${midY}`;

                    topPath.setAttribute('d', topD);
                    btmPath.setAttribute('d', btmD);
                } else {
                    // MOBILE: Start at Top-Center (midX, 1), split left & right, merge at Bottom-Center (midX, h-1)
                    const midX = Math.round(w / 2);

                    // Left half path: starts at (midX, 1), lines left, arcs around top-left, lines down left edge, arcs around bottom-left, lines to (midX, h-1)
                    const leftD = `M ${midX},1 L ${r + 1},1 A ${r},${r} 0 0 0 1,${r + 1} L 1,${h - 1 - r} A ${r},${r} 0 0 0 ${r + 1},${h - 1} L ${midX},${h - 1}`;

                    // Right half path: starts at (midX, 1), lines right, arcs around top-right, lines down right edge, arcs around bottom-right, lines to (midX, h-1)
                    const rightD = `M ${midX},1 L ${w - 1 - r},1 A ${r},${r} 0 0 1 ${w - 1},${r + 1} L ${w - 1},${h - 1 - r} A ${r},${r} 0 0 1 ${w - 1 - r},${h - 1} L ${midX},${h - 1}`;

                    topPath.setAttribute('d', leftD);
                    btmPath.setAttribute('d', rightD);
                }
            });
        }

        // Initialize immediately
        updatePaths();

        // Perf: coalesce bursts of resize notifications into a single update per frame
        let rafId = 0;
        const scheduleUpdate = () => {
            if (rafId) return;
            rafId = requestAnimationFrame(() => {
                rafId = 0;
                updatePaths();
            });
        };

        // Observe card resizes (ResizeObserver already covers viewport resizes,
        // so the window listener is only a fallback for very old browsers)
        if (typeof ResizeObserver !== 'undefined') {
            const ro = new ResizeObserver(scheduleUpdate);
            cards.forEach(card => ro.observe(card));
        } else {
            window.addEventListener('resize', scheduleUpdate, { passive: true });
        }

        window.addEventListener('load', scheduleUpdate, { passive: true });

        // Perf: freeze the ~20 looping CSS animations while the section is off-screen
        const section = pipeline.closest('.arch-section');
        if (section && typeof IntersectionObserver !== 'undefined') {
            section.classList.add('arch-offscreen');
            const io = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    section.classList.toggle('arch-offscreen', !entry.isIntersecting);
                });
            }, { rootMargin: '100px 0px' });
            io.observe(section);
        }
    }

    // ==========================================================================
    // FITTRACK ENGINE — CUSTOM NOTCHED CARD & COMPANION PILL GEOMETRY
    // ==========================================================================
    function initFitTrackNotchedCard() {
        const card = document.getElementById('ftProblemSummaryCard');
        if (!card) return;

        function updateNotchPaths() {
            const w = card.offsetWidth;
            const h = card.offsetHeight;
            if (w === 0 || h === 0) return;

            const r = Math.min(28, Math.max(16, Math.floor(w * 0.07)));
            const isMobile = w < 540;

            const notchH = isMobile ? 54 : 74;
            const notchW = isMobile ? Math.min(220, Math.max(190, Math.floor(w * 0.54))) : Math.min(235, Math.floor(w * 0.46));
            const chamferW = isMobile ? 36 : 52;
            const cr = isMobile ? 12 : 18;

            const x0 = w - notchW;
            const x1 = w - notchW + chamferW;
            const yNotch = h - notchH;

            // Clockwise perimeter with smooth S-curve chamfer
            const cardD = `
                M ${r}, 1
                H ${w - r}
                A ${r},${r} 0 0 1 ${w - 1},${r}
                V ${yNotch - cr}
                A ${cr},${cr} 0 0 1 ${w - 1 - cr},${yNotch}
                H ${x1}
                C ${x1 - (isMobile ? 18 : 24)},${yNotch} ${x0 + (isMobile ? 18 : 24)},${h - 1} ${x0},${h - 1}
                H ${r}
                A ${r},${r} 0 0 1 1,${h - 1 - r}
                V ${r}
                A ${r},${r} 0 0 1 ${r},1
                Z
            `;

            const bgPath = card.querySelector('#ftCardBgPath');
            const specPath = card.querySelector('#ftCardSpecularPath');
            const clipPath = card.querySelector('#ftCardClipPath');

            if (bgPath) bgPath.setAttribute('d', cardD);
            if (specPath) specPath.setAttribute('d', cardD);
            if (clipPath) clipPath.setAttribute('d', cardD);

            // Companion Pill Geometry
            const pillWrap = card.querySelector('#ftFloatingPill');
            if (pillWrap) {
                const pw = isMobile ? notchW - 8 : notchW - 14;
                const ph = isMobile ? 42 : 54;
                const pr = isMobile ? 14 : 18;
                const pSlope = isMobile ? 26 : 44;

                pillWrap.style.width = `${pw}px`;
                pillWrap.style.height = `${ph}px`;

                const pillD = `
                    M ${pSlope + 4}, 1
                    H ${pw - pr}
                    A ${pr},${pr} 0 0 1 ${pw - 1},${pr}
                    V ${ph - 1 - pr}
                    A ${pr},${pr} 0 0 1 ${pw - pr},${ph - 1}
                    H 14
                    C 8,${ph - 1} 4,${ph - 5} 7,${ph - 10}
                    L ${pSlope - 5}, 7
                    C ${pSlope - 2}, 3 ${pSlope + 1}, 1 ${pSlope + 4}, 1
                    Z
                `;

                const pillBgPath = pillWrap.querySelector('#ftPillBgPath');
                const pillSpecPath = pillWrap.querySelector('#ftPillSpecularPath');

                if (pillBgPath) pillBgPath.setAttribute('d', pillD);
                if (pillSpecPath) pillSpecPath.setAttribute('d', pillD);
            }
        }

        updateNotchPaths();

        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(updateNotchPaths).observe(card);
        }

        window.addEventListener('resize', updateNotchPaths, { passive: true });
        window.addEventListener('load', updateNotchPaths, { passive: true });
    }

    // ==========================================================================
    // PLATFORM CAPABILITIES — SERPENTINE DOTTED TRACK GENERATOR
    // ==========================================================================
    function initCapabilitiesFlashlight() {
        const section = document.getElementById('features');
        if (!section || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
        const ripple = section.querySelector('.capabilities-flashlight-ripple');

        const moveLight = (event) => {
            const rect = section.getBoundingClientRect();
            section.style.setProperty('--cap-light-x', `${event.clientX - rect.left}px`);
            section.style.setProperty('--cap-light-y', `${event.clientY - rect.top}px`);
        };

        if (finePointer) {
            section.addEventListener('pointermove', moveLight, { passive: true });
            section.addEventListener('pointerleave', () => {
                section.style.setProperty('--cap-light-x', '50%');
                section.style.setProperty('--cap-light-y', '50%');
            }, { passive: true });
        }

        section.addEventListener('pointerdown', (event) => {
            if (event.pointerType === 'mouse' || !ripple) return;
            moveLight(event);
            ripple.classList.remove('is-active');
            void ripple.offsetWidth;
            ripple.classList.add('is-active');
        }, { passive: true });
    }

    function initCapabilitiesSerpentineTrack() {
        const container = document.getElementById('capabilitiesSerpentineTimeline');
        if (!container) return;

        function updateTrack() {
            const nodes = container.querySelectorAll('.cap-node');
            if (nodes.length < 2) return;

            const containerRect = container.getBoundingClientRect();
            const points = [];

            nodes.forEach(node => {
                const r = node.getBoundingClientRect();
                points.push({
                    x: Math.round(r.left - containerRect.left + r.width / 2),
                    y: Math.round(r.top - containerRect.top + r.height / 2)
                });
            });

            let d = `M ${points[0].x},${points[0].y}`;
            for (let i = 0; i < points.length - 1; i++) {
                const p1 = points[i];
                const p2 = points[i + 1];
                const midY = (p1.y + p2.y) / 2;
                d += ` C ${p1.x},${midY} ${p2.x},${midY} ${p2.x},${p2.y}`;
            }

            const trackPath = document.getElementById('capabilitiesTrackPath');
            if (trackPath) trackPath.setAttribute('d', d);
        }

        updateTrack();

        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(updateTrack).observe(container);
        }

        window.addEventListener('resize', updateTrack, { passive: true });
        window.addEventListener('load', updateTrack, { passive: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            initArchitecturePaths();
            initFitTrackNotchedCard();
            initCapabilitiesFlashlight();
            initCapabilitiesSerpentineTrack();
        });
    } else {
        initArchitecturePaths();
        initFitTrackNotchedCard();
        initCapabilitiesFlashlight();
        initCapabilitiesSerpentineTrack();
    }
})();
