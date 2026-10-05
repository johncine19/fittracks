/**
 * FitTrack In-App Splash & Transition Controller
 * 
 * Behavior:
 * 1. Initial Visit / Session Start: Shows the splash screen with branding animations.
 * 2. Genuine Browser Reload (F5 / Ctrl+R / Refresh Button): Shows the splash screen during re-initialization.
 * 3. Internal Page Navigation: Skips full-screen splash completely for zero-delay, instant page feel.
 * 4. System Loading / Heavy Operations: Exposes window.showSplashLoader() and window.hideSplashLoader().
 */
(() => {
    // 1. Detect if this is an explicit browser reload (F5 / Refresh button)
    let isReload = false;
    try {
        const navEntries = window.performance && typeof performance.getEntriesByType === 'function'
            ? performance.getEntriesByType('navigation')
            : [];
        if (navEntries.length > 0) {
            isReload = navEntries[0].type === 'reload';
        } else if (window.performance && window.performance.navigation) {
            isReload = window.performance.navigation.type === 1; // TYPE_RELOAD
        }
    } catch (e) {
        isReload = false;
    }

    // 2. Check if splash was already shown in this browsing session
    let hasSeenSplash = false;
    try {
        hasSeenSplash = !!sessionStorage.getItem('ft_splash_shown');
    } catch (e) {}

    // 3. If navigating internally (already seen & not a reload), suppress splash immediately
    if (hasSeenSplash && !isReload) {
        document.documentElement.classList.add('ft-no-splash');
    } else {
        try {
            sessionStorage.setItem('ft_splash_shown', '1');
        } catch (e) {}
    }

    const minDisplayMs = 500; // Snappy branding delay on fresh load
    const maxTimeoutMs = 2500; // Safety failsafe
    const startTime = performance.now();

    function dismissSplash() {
        const splash = document.getElementById('ft-splash-screen');
        if (!splash || splash.classList.contains('ft-splash-hidden')) return;

        const elapsed = performance.now() - startTime;
        const remaining = Math.max(0, minDisplayMs - elapsed);

        setTimeout(() => {
            splash.classList.add('ft-splash-hidden');
            setTimeout(() => {
                if (splash && splash.parentNode) {
                    splash.parentNode.removeChild(splash);
                }
            }, 450);
        }, remaining);
    }

    if (document.readyState === 'complete') {
        dismissSplash();
    } else {
        window.addEventListener('load', dismissSplash);
        setTimeout(dismissSplash, maxTimeoutMs);
    }

    // Subtle top progress bar on internal navigation
    document.addEventListener('click', (e) => {
        const link = e.target.closest('a');
        if (!link) return;
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:') || link.target === '_blank') return;
        if (link.hasAttribute('download')) return;

        let bar = document.getElementById('ft-top-progress-bar');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'ft-top-progress-bar';
            document.body.appendChild(bar);
        }
        setTimeout(() => {
            if (bar) bar.className = 'ft-progress-active';
        }, 80);
    });
})();

// Global programmatic helpers
window.showSplashLoader = function(customTitle) {
    let splash = document.getElementById('ft-splash-screen');
    if (!splash) {
        splash = document.createElement('div');
        splash.id = 'ft-splash-screen';
        splash.setAttribute('aria-hidden', 'true');
        splash.innerHTML = `
            <div class="ft-splash-glow"></div>
            <div class="ft-splash-content">
                <div class="ft-splash-logo-wrap">
                    <div class="ft-splash-ring"></div>
                    <div class="ft-splash-logo">FT</div>
                </div>
                <div class="ft-splash-brand-title">${customTitle ? customTitle : 'Fit<span>Track</span>'}</div>
                <div class="ft-splash-bar-track">
                    <div class="ft-splash-bar-fill"></div>
                </div>
            </div>
        `;
        document.body.appendChild(splash);
    }
    document.documentElement.classList.remove('ft-no-splash');
    splash.classList.remove('ft-splash-hidden');
};

window.hideSplashLoader = function() {
    const splash = document.getElementById('ft-splash-screen');
    if (splash) {
        splash.classList.add('ft-splash-hidden');
        setTimeout(() => {
            if (splash && splash.parentNode) splash.parentNode.removeChild(splash);
        }, 400);
    }
};
