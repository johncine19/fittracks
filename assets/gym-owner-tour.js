/**
 * FitTrack - Minimalist Spotlight Tour & Feature Discovery Engine
 * Automatically respects the Gym Owner's dynamic brand color theme
 */
(function () {
    'use strict';

    const TOUR_STORAGE_KEY = 'fittrack_gym_tour_completed';
    const FEATURE_STORAGE_PREFIX = 'ft_feature_seen_';

    // ── CONCISE, BENEFIT-FOCUSED TOUR STEPS (Mode B) ──────────────────
    const TOUR_STEPS = [
        {
            stepNumber: 1,
            badge: '1 of 7',
            title: 'Live Operations Hub',
            description: 'Real-time check-ins, monthly revenue splits, and active membership trends updated on every scan.',
            targetSelector: '.skeleton-content.stats-row, section.dash-grid.stats-row:not(.skeleton-wrapper *), .stats-row',
            preferredPlacement: 'bottom'
        },
        {
            stepNumber: 2,
            badge: '2 of 7',
            title: 'Front Desk Scanner',
            description: 'Turn any camera into a hands-free QR attendance terminal with cash walk-in day pass logging.',
            targetSelector: '.side-nav a[href$="page=scanner"], .side-nav a[href*="page=scanner"]',
            preferredPlacement: 'right'
        },
        {
            stepNumber: 3,
            badge: '3 of 7',
            title: 'Staff & Trainers',
            description: 'Onboard front desk receptionists, assign coaches to members, and track automated commissions.',
            targetSelector: '.side-nav a[href$="page=users"], .side-nav a[href*="page=users"]',
            preferredPlacement: 'right'
        },
        {
            stepNumber: 4,
            badge: '4 of 7',
            title: 'Plans & Pricing',
            description: 'Configure passes, custom rates, and automated online checkout via GCash, Maya, and cards.',
            targetSelector: '.side-nav a[href$="page=plans"], .side-nav a[href*="page=plans"]',
            preferredPlacement: 'right'
        },
        {
            stepNumber: 5,
            badge: '5 of 7',
            title: 'Classes & Scheduling',
            description: 'Schedule instructor-led fitness sessions, manage room capacities, and handle attendee bookings.',
            targetSelector: '.side-nav a[href$="page=classes"], .side-nav a[href*="page=classes"]',
            preferredPlacement: 'right'
        },
        {
            stepNumber: 6,
            badge: '6 of 7',
            title: 'Gym Equipment',
            description: 'Monitor gym machinery status, track live member queues, and prevent maintenance bottlenecks.',
            targetSelector: '.side-nav a[href$="page=gym_equipment"], .side-nav a[href*="gym_equipment"], .side-nav a[href*="page=equipment"]',
            preferredPlacement: 'right'
        },
        {
            stepNumber: 7,
            badge: '7 of 7',
            title: 'Subscription & Safeguards',
            description: 'Manage your FitTrack SaaS tier and 7-day advance renewal window to protect uninterrupted service.',
            targetSelector: '.sidebar-bottom a[href*="profile"], a[href*="page=profile"]',
            preferredPlacement: 'right'
        }
    ];

    // ── CORE ENGINE ──────────────────────────────────────────────────
    const FitTrackSpotlight = {
        overlayEl: null,
        popoverEl: null,
        beaconRingEl: null,
        currentTargetEl: null,
        activeTourIndex: -1,
        activeTourSteps: null,
        repositionRaf: null,

        init() {
            if (this.overlayEl) return;

            // 1. Create Spotlight Backdrop (SVG Mask)
            this.overlayEl = document.createElement('div');
            this.overlayEl.className = 'ft-spotlight-overlay';
            this.overlayEl.id = 'ft-spotlight-overlay';
            this.overlayEl.innerHTML = `
                <svg class="ft-spotlight-svg" xmlns="http://www.w3.org/2000/svg">
                    <defs>
                        <mask id="ft-spotlight-mask">
                            <rect width="100%" height="100%" fill="#ffffff" />
                            <rect id="ft-spotlight-hole" class="ft-spotlight-mask-rect" x="0" y="0" width="0" height="0" rx="10" ry="10" />
                        </mask>
                    </defs>
                    <rect width="100%" height="100%" fill="rgba(4, 7, 5, 0.76)" mask="url(#ft-spotlight-mask)" />
                </svg>
                <div class="ft-spotlight-beacon-ring" id="ft-spotlight-ring"></div>
            `;
            document.body.appendChild(this.overlayEl);

            this.beaconRingEl = document.getElementById('ft-spotlight-ring');

            // 2. Create Minimalist Anchored Popover
            this.popoverEl = document.createElement('div');
            this.popoverEl.className = 'ft-tour-popover';
            this.popoverEl.id = 'ft-tour-popover';
            this.popoverEl.setAttribute('role', 'dialog');
            this.popoverEl.setAttribute('aria-modal', 'true');
            this.popoverEl.innerHTML = `
                <div class="ft-popover-arrow" id="ft-popover-arrow"></div>
                <div class="ft-popover-header">
                    <span class="ft-popover-badge" id="ft-popover-badge"></span>
                    <button type="button" class="ft-popover-close" id="ft-popover-close" aria-label="Close walkthrough">&times;</button>
                </div>
                <h3 class="ft-popover-title" id="ft-popover-title"></h3>
                <p class="ft-popover-desc" id="ft-popover-desc"></p>
                <div class="ft-popover-footer" id="ft-popover-actions"></div>
            `;
            document.body.appendChild(this.popoverEl);

            // Bind Event Listeners
            document.getElementById('ft-popover-close').addEventListener('click', () => {
                this.close(true);
            });

            this.overlayEl.addEventListener('click', (e) => {
                if (e.target === this.overlayEl) {
                    this.close(true);
                }
            });

            document.addEventListener('keydown', (e) => {
                if (!this.popoverEl.classList.contains('is-visible')) return;
                if (e.key === 'Escape') {
                    this.close(true);
                } else if (e.key === 'ArrowRight' && this.activeTourSteps) {
                    if (this.activeTourIndex < this.activeTourSteps.length - 1) {
                        this.renderTourStep(this.activeTourIndex + 1);
                    }
                } else if (e.key === 'ArrowLeft' && this.activeTourSteps) {
                    if (this.activeTourIndex > 0) {
                        this.renderTourStep(this.activeTourIndex - 1);
                    }
                }
            });

            const updatePos = () => {
                if (this.currentTargetEl && this.popoverEl.classList.contains('is-visible')) {
                    cancelAnimationFrame(this.repositionRaf);
                    this.repositionRaf = requestAnimationFrame(() => {
                        this.positionAroundTarget(this.currentTargetEl);
                    });
                }
            };

            window.addEventListener('resize', updatePos, { passive: true });
            window.addEventListener('scroll', updatePos, { passive: true, capture: true });
        },

        positionAroundTarget(targetEl) {
            if (!targetEl) return;
            const rect = targetEl.getBoundingClientRect();
            const isInsideSidebar = !!(targetEl && targetEl.closest && targetEl.closest('#main-sidebar, .sidebar'));
            const padding = 5;

            // Update SVG spotlight hole & focus ring
            const holeEl = document.getElementById('ft-spotlight-hole');
            if (holeEl && this.overlayEl.classList.contains('is-active')) {
                const hx = Math.max(0, rect.left - padding);
                const hy = Math.max(0, rect.top - padding);
                const hw = Math.max(20, rect.width + padding * 2);
                const hh = Math.max(20, rect.height + padding * 2);

                holeEl.setAttribute('x', hx);
                holeEl.setAttribute('y', hy);
                holeEl.setAttribute('width', hw);
                holeEl.setAttribute('height', hh);

                if (this.beaconRingEl) {
                    this.beaconRingEl.style.left = `${hx}px`;
                    this.beaconRingEl.style.top = `${hy}px`;
                    this.beaconRingEl.style.width = `${hw}px`;
                    this.beaconRingEl.style.height = `${hh}px`;
                }
            }

            // Position the Popover Card
            const popover = this.popoverEl;
            const arrow = document.getElementById('ft-popover-arrow');
            const winWidth = window.innerWidth;
            const winHeight = window.innerHeight;
            const margin = 12;

            if (winWidth <= 640 || (rect.right + 330 > winWidth && isInsideSidebar)) {
                // If target is in the lower half (like Profile in sidebar-bottom), dock at the TOP so the target is completely visible!
                const targetIsLow = rect.bottom > (winHeight - 240) || rect.top > (winHeight / 2);

                if (targetIsLow) {
                    popover.setAttribute('data-placement', 'dock-top');
                    popover.style.left = '12px';
                    popover.style.right = '12px';
                    popover.style.top = '16px';
                    popover.style.bottom = 'auto';
                } else {
                    popover.setAttribute('data-placement', 'dock-bottom');
                    popover.style.left = '12px';
                    popover.style.right = '12px';
                    popover.style.top = 'auto';
                    popover.style.bottom = '16px';
                }
                popover.style.width = 'auto';
                if (arrow) arrow.style.display = 'none';
                return;
            }

            // Reset mobile dock overrides if window is desktop size
            popover.style.right = '';
            popover.style.bottom = '';
            popover.style.width = '';
            if (arrow) arrow.style.display = '';

            const popWidth = popover.offsetWidth || 320;
            const popHeight = popover.offsetHeight || 150;

            let placement = 'bottom';
            let top = 0;
            let left = 0;

            // Strategy: Respect preferred placement if it fits
            const pref = (this.activeTourSteps && this.activeTourSteps[this.activeTourIndex]?.preferredPlacement) || 'auto';
            if (pref === 'bottom' && rect.bottom + popHeight + margin <= winHeight) {
                placement = 'bottom';
                top = rect.bottom + margin;
                left = rect.left + (rect.width - popWidth) / 2;
            } else if (pref === 'right' && rect.right + popWidth + margin <= winWidth) {
                placement = 'right';
                left = rect.right + margin;
                top = rect.top + (rect.height - popHeight) / 2;
            } else if (rect.right + popWidth + margin <= winWidth && rect.width < 320) {
                placement = 'right';
                left = rect.right + margin;
                top = rect.top + (rect.height - popHeight) / 2;
            } else if (rect.bottom + popHeight + margin <= winHeight) {
                placement = 'bottom';
                top = rect.bottom + margin;
                left = rect.left + (rect.width - popWidth) / 2;
            } else if (rect.top - popHeight - margin >= 0) {
                placement = 'top';
                top = rect.top - popHeight - margin;
                left = rect.left + (rect.width - popWidth) / 2;
            } else if (rect.left - popWidth - margin >= 0) {
                placement = 'left';
                left = rect.left - popWidth - margin;
                top = rect.top + (rect.height - popHeight) / 2;
            } else {
                placement = 'bottom';
                left = Math.max(margin, (winWidth - popWidth) / 2);
                top = Math.min(winHeight - popHeight - margin, Math.max(margin, rect.bottom + margin));
            }

            // Clamp inside viewport
            left = Math.max(margin, Math.min(winWidth - popWidth - margin, left));
            top = Math.max(margin, Math.min(winHeight - popHeight - margin, top));

            popover.setAttribute('data-placement', placement);
            popover.style.left = `${Math.round(left)}px`;
            popover.style.top = `${Math.round(top)}px`;

            // Position Arrow
            if (arrow) {
                if (placement === 'bottom' || placement === 'top') {
                    const arrowLeft = Math.max(14, Math.min(popWidth - 24, (rect.left + rect.width / 2) - left));
                    arrow.style.left = `${Math.round(arrowLeft)}px`;
                    arrow.style.top = '';
                } else {
                    const arrowTop = Math.max(14, Math.min(popHeight - 24, (rect.top + rect.height / 2) - top));
                    arrow.style.top = `${Math.round(arrowTop)}px`;
                    arrow.style.left = '';
                }
            }
        },

        /**
         * Contextual Feature Discovery Popover (Mode A)
         */
        showContextualPopover(options) {
            this.init();
            const target = typeof options.target === 'string' ? document.querySelector(options.target) : options.target;
            if (!target) return;

            this.currentTargetEl = target;
            this.activeTourSteps = null;
            this.activeTourIndex = -1;

            document.getElementById('ft-popover-badge').textContent = options.badge || 'Feature Spotlight';
            document.getElementById('ft-popover-title').textContent = options.title || 'Feature';
            document.getElementById('ft-popover-desc').textContent = options.description || '';

            const actionsEl = document.getElementById('ft-popover-actions');
            actionsEl.innerHTML = '';

            const gotItBtn = document.createElement('button');
            gotItBtn.type = 'button';
            gotItBtn.className = 'btn-ft-primary';
            gotItBtn.textContent = 'Got It';
            gotItBtn.addEventListener('click', () => {
                if (options.featureId) {
                    try {
                        localStorage.setItem(FEATURE_STORAGE_PREFIX + options.featureId, '1');
                    } catch (e) {}
                }
                this.close();
            });
            actionsEl.appendChild(gotItBtn);

            if (options.actionUrl && options.actionText) {
                const actionBtn = document.createElement('a');
                actionBtn.href = options.actionUrl;
                actionBtn.className = 'btn-ft-skip';
                actionBtn.textContent = options.actionText;
                actionsEl.insertBefore(actionBtn, gotItBtn);
            }

            // Standalone discovery does not block background
            this.overlayEl.classList.remove('is-active');

            target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

            setTimeout(() => {
                this.positionAroundTarget(target);
                this.popoverEl.classList.add('is-visible');
            }, 40);
        },

        /**
         * Render Guided Tour Step (Mode B)
         */
        renderTourStep(index) {
            if (!this.activeTourSteps || index < 0 || index >= this.activeTourSteps.length) return;
            this.activeTourIndex = index;
            const step = this.activeTourSteps[index];

            const candidates = Array.from(document.querySelectorAll(step.targetSelector));
            let target = candidates.find(el => {
                const r = el.getBoundingClientRect();
                const style = window.getComputedStyle(el);
                return r.width > 30 && r.height > 20 && style.display !== 'none' && style.visibility !== 'hidden';
            }) || candidates[0];

            if (!target && step.fallbackSelector) {
                target = document.querySelector(step.fallbackSelector);
            }
            if (!target) target = document.body;

            this.currentTargetEl = target;

            document.getElementById('ft-popover-badge').textContent = step.badge || `${index + 1} of ${this.activeTourSteps.length}`;
            document.getElementById('ft-popover-title').textContent = step.title;
            document.getElementById('ft-popover-desc').textContent = step.description;

            // Actions
            const actionsEl = document.getElementById('ft-popover-actions');
            actionsEl.innerHTML = '';

            const skipBtn = document.createElement('button');
            skipBtn.type = 'button';
            skipBtn.className = 'btn-ft-skip';
            skipBtn.style.marginRight = 'auto';
            skipBtn.textContent = 'Skip';
            skipBtn.addEventListener('click', () => {
                this.close(true);
            });
            actionsEl.appendChild(skipBtn);

            if (index > 0) {
                const backBtn = document.createElement('button');
                backBtn.type = 'button';
                backBtn.className = 'btn-ft-back';
                backBtn.innerHTML = '&#8592; Back';
                backBtn.addEventListener('click', () => {
                    this.renderTourStep(index - 1);
                });
                actionsEl.appendChild(backBtn);
            }

            const nextBtn = document.createElement('button');
            nextBtn.type = 'button';
            nextBtn.className = 'btn-ft-primary';
            const isLast = index === this.activeTourSteps.length - 1;
            nextBtn.innerHTML = isLast ? 'Done &#10003;' : 'Next &#8594;';
            nextBtn.addEventListener('click', () => {
                if (isLast) {
                    this.close(true);
                } else {
                    this.renderTourStep(index + 1);
                }
            });
            actionsEl.appendChild(nextBtn);

            const isMobile = window.innerWidth <= 980;
            const sidebar = document.getElementById('main-sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            const isInsideSidebar = !!(target && target.closest && target.closest('#main-sidebar, .sidebar'));

            let needsDrawerWait = false;
            if (isMobile && sidebar) {
                if (isInsideSidebar) {
                    if (!sidebar.classList.contains('open')) {
                        sidebar.classList.add('open');
                        overlay?.classList.add('open');
                        needsDrawerWait = true;
                    }
                } else {
                    if (sidebar.classList.contains('open')) {
                        sidebar.classList.remove('open');
                        overlay?.classList.remove('open');
                    }
                }
            }

            const applyStepPosition = () => {
                if (!this.currentTargetEl || this.activeTourIndex !== index) return;

                if (isMobile) {
                    // Center the element vertically in the drawer so it's not hidden behind the bottom popover card
                    this.currentTargetEl.scrollIntoView({ behavior: 'auto', block: 'center' });
                } else {
                    const rect = this.currentTargetEl.getBoundingClientRect();
                    const inView = (
                        rect.top >= 40 &&
                        rect.bottom <= (window.innerHeight - 40) &&
                        rect.left >= 0 &&
                        rect.right <= window.innerWidth
                    );
                    if (!inView) {
                        this.currentTargetEl.scrollIntoView({ behavior: 'auto', block: 'nearest' });
                    }
                }

                this.positionAroundTarget(this.currentTargetEl);
                this.popoverEl.classList.add('is-visible');
                this.overlayEl.classList.add('is-active');

                requestAnimationFrame(() => {
                    this.positionAroundTarget(this.currentTargetEl);
                });
            };

            if (needsDrawerWait) {
                setTimeout(applyStepPosition, 260);
            } else {
                applyStepPosition();
            }
        },

        startTour(steps = TOUR_STEPS) {
            this.init();
            this.activeTourSteps = steps;
            this.renderTourStep(0);
        },

        close(markCompleted = false) {
            if (this.popoverEl) {
                this.popoverEl.classList.remove('is-visible');
            }
            if (this.overlayEl) {
                this.overlayEl.classList.remove('is-active');
            }
            this.currentTargetEl = null;

            // Close mobile sidebar if it was opened by the tour
            if (window.innerWidth <= 980) {
                const sidebar = document.getElementById('main-sidebar');
                const overlay = document.getElementById('sidebar-overlay');
                sidebar?.classList.remove('open');
                overlay?.classList.remove('open');
            }

            if (markCompleted) {
                try {
                    localStorage.setItem(TOUR_STORAGE_KEY, '1');
                } catch (e) {}

                const banner = document.getElementById('gym-tour-welcome-banner');
                if (banner) {
                    banner.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
                    banner.style.opacity = '0';
                    banner.style.transform = 'translateY(-6px)';
                    setTimeout(() => banner.remove(), 250);
                }
            }
        }
    };

    // ── GLOBAL SHORTCUTS ─────────────────────────────────────────────
    window.FitTrackSpotlight = FitTrackSpotlight;

    window.startGymOwnerTour = function () {
        const isDashboard = window.location.search.includes('page=dashboard') ||
                            window.location.search === '' ||
                            window.location.search === '?' ||
                            (window.location.pathname.endsWith('/index.php') && !window.location.search);

        if (!isDashboard) {
            window.location.href = 'index.php?page=dashboard&start_tour=1';
            return;
        }

        FitTrackSpotlight.startTour(TOUR_STEPS);
    };

    window.closeGymOwnerTour = function (markCompleted = true) {
        FitTrackSpotlight.close(markCompleted);
    };

    window.dismissGymOwnerTourBanner = function () {
        try {
            localStorage.setItem(TOUR_STORAGE_KEY, '1');
        } catch (e) {}
        const banner = document.getElementById('gym-tour-welcome-banner');
        if (banner) {
            banner.remove();
        }
    };

    window.showFeatureDiscovery = function (featureId, triggerEl, title, description, actionUrl = null, actionText = null) {
        FitTrackSpotlight.showContextualPopover({
            target: triggerEl,
            featureId: featureId,
            title: title,
            description: description,
            actionUrl: actionUrl,
            actionText: actionText,
            badge: 'Feature Spotlight'
        });
    };

    // ── AUTO-INITIALIZATION ──────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        FitTrackSpotlight.init();

        const hasCompletedTour = localStorage.getItem(TOUR_STORAGE_KEY) === '1';
        const banner = document.getElementById('gym-tour-welcome-banner');

        if (hasCompletedTour && banner) {
            banner.style.display = 'none';
        }

        // Handle Quick Tour launched from another page (?start_tour=1)
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('start_tour') === '1') {
            const url = new URL(window.location.href);
            url.searchParams.delete('start_tour');
            window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : ''));
            setTimeout(function () {
                FitTrackSpotlight.startTour(TOUR_STEPS);
            }, 350);
            return;
        }

        // Auto-launch Guided Tour for First-Time Gym Owners on Dashboard
        const isDashboard = window.location.search.includes('page=dashboard') || window.location.search === '' || window.location.search === '?';
        const isGymOwner = document.body.classList.contains('app-body') && (
            document.querySelector('.role-badge')?.textContent?.toLowerCase()?.includes('owner') ||
            document.querySelector('.user-details small')?.textContent?.toLowerCase()?.includes('gym_owner') ||
            document.querySelector('.user-details small')?.textContent?.toLowerCase()?.includes('owner')
        );

        if (isDashboard && isGymOwner && !hasCompletedTour) {
            setTimeout(function () {
                if (localStorage.getItem(TOUR_STORAGE_KEY) !== '1') {
                    FitTrackSpotlight.startTour(TOUR_STEPS);
                }
            }, 1000);
        }

        // Auto-bind feature discovery sparkle buttons
        document.querySelectorAll('[data-ft-feature]').forEach(el => {
            const featureId = el.getAttribute('data-ft-feature');
            const title = el.getAttribute('data-ft-title') || 'Feature Discovery';
            const desc = el.getAttribute('data-ft-desc') || '';
            const actionUrl = el.getAttribute('data-ft-url') || null;
            const actionText = el.getAttribute('data-ft-action') || null;

            el.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                window.showFeatureDiscovery(featureId, el, title, desc, actionUrl, actionText);
            });
        });
    });
})();
