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

        // Observe card resizes
        if (typeof ResizeObserver !== 'undefined') {
            const ro = new ResizeObserver(() => {
                updatePaths();
            });
            cards.forEach(card => ro.observe(card));
        }

        window.addEventListener('resize', updatePaths, { passive: true });
        window.addEventListener('load', updatePaths, { passive: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initArchitecturePaths);
    } else {
        initArchitecturePaths();
    }
})();
