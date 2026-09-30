/**
 * FitTrack In-App Splash Controller
 * Ensures a crisp, silky-smooth loading transition on every visit
 */
(() => {
    const minDisplayMs = 700; // Visible long enough to admire branding
    const maxTimeoutMs = 2500; // Failsafe so user is never stuck
    const startTime = performance.now();

    function dismissSplash() {
        const splash = document.getElementById('ft-splash-screen');
        if (!splash || splash.classList.contains('ft-splash-hidden')) return;

        const elapsed = performance.now() - startTime;
        const remaining = Math.max(0, minDisplayMs - elapsed);

        setTimeout(() => {
            splash.classList.add('ft-splash-hidden');
            // Remove from DOM entirely after CSS transition completes
            setTimeout(() => {
                if (splash && splash.parentNode) {
                    splash.parentNode.removeChild(splash);
                }
            }, 500);
        }, remaining);
    }

    if (document.readyState === 'complete') {
        dismissSplash();
    } else {
        window.addEventListener('load', dismissSplash);
        // Safety failsafe
        setTimeout(dismissSplash, maxTimeoutMs);
    }
})();
