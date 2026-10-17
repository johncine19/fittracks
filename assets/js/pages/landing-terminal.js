/**
 * FITTRACK — ATTENDANCE TERMINAL INTERACTIVE SPOTLIGHT & PWA INSTALLATION ENGINE
 * Handles live clock, outage simulation, interactive QR scan triggers,
 * PWA beforeinstallprompt capture, and multi-platform installation guide modals.
 */
(function () {
    'use strict';

    var deferredPrompt = null;

    // ── 0. Service Worker Registration ─────────────────────────────────────
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('sw.js').catch(function (err) {
                console.warn('[PWA] Service Worker registration failed:', err);
            });
        });
    }

    // ── 1. Capture PWA beforeinstallprompt ──────────────────────────────────
    window.addEventListener('beforeinstallprompt', function (e) {
        // Prevent Chrome 67 and earlier from automatically showing the prompt
        e.preventDefault();
        deferredPrompt = e;
        
        // Show floating install banner on mobile if not dismissed
        var floatingBanner = document.getElementById('mobileFloatingInstall');
        if (floatingBanner && !sessionStorage.getItem('fittrack_install_dismissed') && !isRunningStandalone()) {
            floatingBanner.classList.remove('is-hidden');
        }

        // Highlight install buttons
        var triggers = document.querySelectorAll('.pwa-install-trigger');
        triggers.forEach(function (btn) {
            btn.classList.add('has-native-prompt');
        });
    });

    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        var floatingBanner = document.getElementById('mobileFloatingInstall');
        if (floatingBanner) floatingBanner.classList.add('is-hidden');
        showTerminalNotice('✓ FitTrack Terminal installed to your home screen / desktop!');
    });

    function isRunningStandalone() {
        return (window.matchMedia('(display-mode: standalone)').matches) || 
               (window.navigator.standalone === true) || 
               document.referrer.includes('android-app://');
    }

    // ── 2. Global PWA Trigger ───────────────────────────────────────────────
    window.triggerPWAInstall = function () {
        if (isRunningStandalone()) {
            showTerminalNotice('✓ You are already running the installed FitTrack Terminal app!');
            return;
        }

        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function (choiceResult) {
                if (choiceResult.outcome === 'accepted') {
                    showTerminalNotice('✓ Installing FitTrack Terminal... Welcome!');
                    var floatingBanner = document.getElementById('mobileFloatingInstall');
                    if (floatingBanner) floatingBanner.classList.add('is-hidden');
                }
                deferredPrompt = null;
            });
        } else {
            // No native prompt available (iOS Safari, desktop Firefox, or already dismissed) -> Open Guide Modal
            window.openPWAInstallModal();
        }
    };

    // ── 3. Installation Guide Modal & Platform Detection ────────────────────
    window.openPWAInstallModal = function () {
        var modal = document.getElementById('pwaInstallModal');
        if (!modal) return;

        // Auto-detect user platform
        var ua = navigator.userAgent || navigator.vendor || window.opera;
        var defaultTab = 'android';

        if (/iPad|iPhone|iPod/.test(ua) && !window.MSStream) {
            defaultTab = 'ios';
        } else if (/Android/.test(ua)) {
            defaultTab = 'android';
        } else if (!/Mobile|Android|iPhone|iPad/.test(ua)) {
            defaultTab = 'desktop';
        }

        window.switchPwaModalTab(defaultTab);
        modal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    };

    window.closePWAInstallModal = function () {
        var modal = document.getElementById('pwaInstallModal');
        if (modal) {
            modal.classList.remove('is-open');
            document.body.style.overflow = '';
        }
    };

    window.switchPwaModalTab = function (tabName) {
        var tabs = document.querySelectorAll('.pwa-modal-tab-btn');
        var panes = document.querySelectorAll('.pwa-modal-pane');

        tabs.forEach(function (tab) {
            tab.classList.toggle('is-active', tab.getAttribute('data-tab') === tabName);
        });

        panes.forEach(function (pane) {
            pane.classList.toggle('is-active', pane.getAttribute('id') === 'pwa-pane-' + tabName);
        });
    };

    window.dismissFloatingInstall = function (e) {
        if (e) e.stopPropagation();
        var banner = document.getElementById('mobileFloatingInstall');
        if (banner) banner.classList.add('is-hidden');
        sessionStorage.setItem('fittrack_install_dismissed', '1');
    };

    // ── 4. Terminal Interactive Spotlight Simulation ─────────────────────────
    function initTerminalSpotlight() {
        var clockEl = document.getElementById('termDemoClock');
        var simBtn = document.getElementById('termSimulateOutageBtn');
        var netPill = document.getElementById('termDemoNetStatus');
        var netText = document.getElementById('termDemoNetText');
        var offlineCard = document.getElementById('termDemoOfflineCard');
        var offlineHead = document.getElementById('termDemoOfflineHead');
        var offlineDesc = document.getElementById('termDemoOfflineDesc');
        var scanBtn = document.getElementById('termTriggerScanBtn');
        var walkinBtn = document.getElementById('termTriggerWalkinBtn');
        var checkinCountEl = document.getElementById('termStatCheckins');
        var activeCountEl = document.getElementById('termStatActive');
        var walkinCountEl = document.getElementById('termStatWalkins');
        var toastNameEl = document.getElementById('termToastName');
        var toastPlanEl = document.getElementById('termToastPlan');
        var toastAvatarEl = document.getElementById('termToastAvatar');
        var toastCardEl = document.getElementById('termToastCard');
        var laserBeam = document.getElementById('termLaserBeam');
        var queueTextEl = document.getElementById('termQueueText');

        var isOffline = false;
        var pendingScans = 0;
        var totalCheckins = 142;
        var totalActive = 28;
        var totalWalkins = 14;

        // Sample member roster for interactive scan demo
        var sampleMembers = [
            { name: 'Andrea Salazar', id: '#240018', initials: 'AS', plan: 'Monthly Premium • Active' },
            { name: 'Marcus Cruz', id: '#240023', initials: 'MC', plan: 'Annual VIP • Active' },
            { name: 'Sophia Reyes', id: '#240045', initials: 'SR', plan: 'Student Plan • Active' },
            { name: 'David Lee', id: '#240089', initials: 'DL', plan: 'Monthly Pass • Active' }
        ];
        var memberIndex = 0;

        // Digital Clock
        function updateClock() {
            if (!clockEl) return;
            var now = new Date();
            var hours = now.getHours();
            var minutes = now.getMinutes();
            var seconds = now.getSeconds();
            var ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            hours = hours ? hours : 12;
            var strHours = hours < 10 ? '0' + hours : hours;
            var strMin = minutes < 10 ? '0' + minutes : minutes;
            var strSec = seconds < 10 ? '0' + seconds : seconds;
            clockEl.textContent = strHours + ':' + strMin + ':' + strSec + ' ' + ampm;
        }
        updateClock();
        setInterval(updateClock, 1000);

        // Outage Simulation Toggle
        if (simBtn) {
            simBtn.addEventListener('click', function () {
                isOffline = !isOffline;

                if (isOffline) {
                    simBtn.classList.add('is-active-offline');
                    simBtn.innerHTML = '<span>Reconnect Internet</span>';
                    
                    if (netPill) netPill.className = 'term-status-pill is-offline';
                    if (netText) netText.textContent = 'OFFLINE MODE';
                    if (offlineCard) offlineCard.classList.add('is-offline');
                    if (offlineHead) {
                        offlineHead.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>  Local IndexedDB Queue Active';
                    }
                    if (offlineDesc) {
                        offlineDesc.innerHTML = 'Gym Wi-Fi disconnected! Scans validate instantly against cached offline roster. Queueing timestamps locally.';
                    }
                    if (queueTextEl) {
                        queueTextEl.textContent = pendingScans > 0 ? (pendingScans + ' Queued locally') : 'Ready for offline scans';
                    }
                    showTerminalNotice(' Outage Mode Active: Terminal running offline via local cache');
                } else {
                    simBtn.classList.remove('is-active-offline');
                    simBtn.innerHTML = '<span> Simulate Outage</span>';
                    
                    if (netPill) netPill.className = 'term-status-pill is-online';
                    if (netText) netText.textContent = 'ONLINE';
                    if (offlineCard) offlineCard.classList.remove('is-offline');
                    if (offlineHead) {
                        offlineHead.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg> Cloud Sync Worker Active';
                    }
                    var syncedCount = pendingScans;
                    pendingScans = 0;
                    if (offlineDesc) {
                        offlineDesc.innerHTML = 'Central cloud connected. Offline scans batch-synced with original timestamps preserved. 0 members interrupted.';
                    }
                    if (queueTextEl) queueTextEl.textContent = '480 active cached';

                    if (syncedCount > 0) {
                        showTerminalNotice('✓ Back Online: Batch-synced ' + syncedCount + ' offline check-in' + (syncedCount > 1 ? 's' : '') + ' to cloud');
                    } else {
                        showTerminalNotice('✓ Central Sync Active: Live cloud connection restored');
                    }
                }
            });
        }

        // Interactive Member Scan Trigger
        if (scanBtn) {
            scanBtn.addEventListener('click', function () {
                if (laserBeam) {
                    laserBeam.style.animationDuration = '0.5s';
                    setTimeout(function () {
                        laserBeam.style.animationDuration = '2.8s';
                    }, 1000);
                }

                totalCheckins++;
                totalActive++;
                memberIndex = (memberIndex + 1) % sampleMembers.length;
                var currentMember = sampleMembers[memberIndex];

                if (checkinCountEl) checkinCountEl.textContent = totalCheckins;
                if (activeCountEl) activeCountEl.textContent = totalActive;

                if (toastNameEl) toastNameEl.textContent = currentMember.name + ' (' + currentMember.id + ')';
                if (toastPlanEl) toastPlanEl.textContent = currentMember.plan;
                if (toastAvatarEl) toastAvatarEl.textContent = currentMember.initials;

                if (toastCardEl) {
                    toastCardEl.style.transform = 'scale(1.03)';
                    toastCardEl.style.borderColor = '#84cc16';
                    setTimeout(function () {
                        toastCardEl.style.transform = 'scale(1)';
                    }, 250);
                }

                if (isOffline) {
                    pendingScans++;
                    if (queueTextEl) queueTextEl.textContent = pendingScans + ' Queued locally';
                    showTerminalNotice('Offline Verified: ' + currentMember.name + ' (Saved to local queue)');
                } else {
                    showTerminalNotice('✓ Cloud Verified: ' + currentMember.name + ' checked in successfully!');
                }
            });
        }

        // Interactive Walk-in Day Pass Trigger
        if (walkinBtn) {
            walkinBtn.addEventListener('click', function () {
                totalWalkins++;
                totalCheckins++;
                totalActive++;
                if (walkinCountEl) walkinCountEl.textContent = totalWalkins;
                if (checkinCountEl) checkinCountEl.textContent = totalCheckins;
                if (activeCountEl) activeCountEl.textContent = totalActive;

                if (toastNameEl) toastNameEl.textContent = 'Guest Walk-in #W-' + totalWalkins;
                if (toastPlanEl) toastPlanEl.textContent = 'Day Pass • ₱100.00 Paid (Counter)';
                if (toastAvatarEl) toastAvatarEl.textContent = 'W';

                showTerminalNotice(' Walk-in Logged: Day pass recorded & payment collected.');
            });
        }

        // Floating banner auto-appear on mobile scroll if not dismissed
        var floatingBanner = document.getElementById('mobileFloatingInstall');
        if (floatingBanner && !sessionStorage.getItem('fittrack_install_dismissed') && !isRunningStandalone()) {
            var scrollThreshold = 180;
            window.addEventListener('scroll', function () {
                if (window.scrollY > scrollThreshold && !sessionStorage.getItem('fittrack_install_dismissed')) {
                    floatingBanner.classList.remove('is-hidden');
                }
            }, { passive: true });
        }
    }

    // Notice Toast Utility
    function showTerminalNotice(msg) {
        var existing = document.getElementById('termFloatingNotice');
        if (existing) existing.remove();

        var notice = document.createElement('div');
        notice.id = 'termFloatingNotice';
        notice.className = 'term-floating-toast';
        notice.textContent = msg;

        document.body.appendChild(notice);

        requestAnimationFrame(function () {
            notice.classList.add('is-visible');
        });

        setTimeout(function () {
            notice.classList.remove('is-visible');
            setTimeout(function () {
                if (notice.parentNode) notice.parentNode.removeChild(notice);
            }, 300);
        }, 3000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTerminalSpotlight);
    } else {
        initTerminalSpotlight();
    }
})();
