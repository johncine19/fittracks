/**
 * FitTracks Dedicated Interactive Gym Map Controller
 * Provides full-page interactive map browsing, geolocation, pin popups, and gym preview modals.
 */

(function () {
    const FT_CONFIG = window.GYM_SELECTION_CONFIG || {};
    const gymData = FT_CONFIG.gymData || [];
    const FT_CSRF_TOKEN = FT_CONFIG.csrfToken || (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
    const hasGSAP = typeof gsap !== 'undefined';

    const modal = document.getElementById('gymDetailsModal');
    const modalBackdrop = document.getElementById('modal-backdrop');
    const modalContent = document.getElementById('modal-content');
    const modalBody = document.getElementById('modalBody');
    const modalCloseBtn = document.getElementById('modal-close-btn');

    const btnNearMe = document.getElementById('btn-near-me');
    const mapStatusText = document.getElementById('map-status-text');
    const searchInput = document.getElementById('gym-search');
    const noResultsEl = document.getElementById('gym-no-results');

    let map = null;
    let markersGroup = null;
    const gymMarkersMap = new Map(); // gym_id => marker
    let userMarker = null;
    let userCoords = null; // { lat, lng }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getImageUrl(filename, folder = 'uploads') {
        if (!filename) return '';
        if (filename.startsWith('http://') || filename.startsWith('https://')) return escapeHtml(filename);
        if (filename.startsWith('assets/')) return escapeHtml(filename);
        return `assets/${folder}/${escapeHtml(filename)}`;
    }

    // Haversine Great-Circle Distance (in km)
    function haversineDistance(lat1, lon1, lat2, lon2) {
        const R = 6371; // Earth's mean radius in km
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a =
            Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
            Math.sin(dLon / 2) * Math.sin(dLon / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
    }

    function formatDistance(distKm) {
        if (distKm === null || isNaN(distKm)) return '';
        if (distKm < 1) {
            return Math.round(distKm * 1000) + ' m away';
        }
        return distKm.toFixed(1) + ' km away';
    }

    // ---------------------------------------------------------------
    // Modal Helpers & Carousel
    // ---------------------------------------------------------------
    function guessClassIcon(name, desc) {
        const text = (name + ' ' + (desc || '')).toLowerCase();
        if (text.includes('spin') || text.includes('cycle') || text.includes('bike')) {
            return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18.5" cy="17.5" r="3.5"/><circle cx="5.5" cy="17.5" r="3.5"/><circle cx="15" cy="5" r="1"/><path d="M12 17.5V14l-3-3 4-3 2 3h2"/></svg>';
        }
        if (text.includes('yoga') || text.includes('stretch') || text.includes('pilates')) {
            return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20a6 6 0 0 0-12 0"/><circle cx="12" cy="10" r="4"/><circle cx="12" cy="2" r="1"/></svg>';
        }
        if (text.includes('box') || text.includes('combat') || text.includes('mma') || text.includes('fight')) {
            return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 11V6a2 2 0 0 0-2-2v0a2 2 0 0 0-2 2v0"/><path d="M14 10V4a2 2 0 0 0-2-2v0a2 2 0 0 0-2 2v2"/><path d="M10 10.5V6a2 2 0 0 0-2-2v0a2 2 0 0 0-2 2v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"/></svg>';
        }
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 4v16M18 4v16M2 8h4M2 16h4M18 8h4M18 16h4M6 12h12"/></svg>';
    }

    function guessPlanIcon(name) {
        const text = (name || '').toLowerCase();
        if (text.includes('vip') || text.includes('platinum') || text.includes('premium')) {
            return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';
        }
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
    }

    function buildModalHtml(gym) {
        const classCount = gym.classes ? gym.classes.length : 0;
        const planCount = gym.plans ? gym.plans.length : 0;
        const imageCount = gym.images ? gym.images.length : 0;
        let minPrice = null;
        let highlightedPlanId = null;
        let highlightBadgeText = 'Most Popular';
        if (gym.plans && gym.plans.length > 0) {
            minPrice = Math.min.apply(null, gym.plans.map(p => parseFloat(p.price)));
            
            const popularPlan = gym.plans.find(p => p.is_popular === true || p.is_popular === 1 || p.is_popular === '1');
            if (popularPlan) {
                highlightedPlanId = popularPlan.plan_id;
                highlightBadgeText = 'Most Popular';
            } else {
                const quarterlyPlan = gym.plans.find(p => parseInt(p.duration_days, 10) === 90 || (p.plan_type && p.plan_type.toLowerCase() === 'quarterly'));
                if (quarterlyPlan && gym.plans.length > 1) {
                    highlightedPlanId = quarterlyPlan.plan_id;
                    highlightBadgeText = 'Most Popular';
                } else {
                    let bestPerDay = Infinity;
                    gym.plans.forEach(function (p) {
                        const perDay = parseFloat(p.price) / Math.max(1, parseInt(p.duration_days, 10));
                        if (perDay < bestPerDay) {
                            bestPerDay = perDay;
                            highlightedPlanId = p.plan_id;
                            highlightBadgeText = 'Best Value';
                        }
                    });
                }
            }
        }

        let logoHtml = gym.logo_url
            ? `<img src="${getImageUrl(gym.logo_url)}" alt="Logo" loading="lazy" decoding="async" style="width: 100%; height: 100%; object-fit: contain; border-radius: 10px; background: white;" onerror="this.onerror=null; this.outerHTML='<span style=\\'color: var(--lime); font-size: 1.5rem;\\'>'+escapeHtml(gym.name.charAt(0))+'</span>';">`
            : `<span style="color: var(--lime); font-size: 1.5rem;">${escapeHtml(gym.name.charAt(0))}</span>`;

        const hasGallery = gym.images && gym.images.length > 0;
        const ratingScore = gym.rating_stats ? parseFloat(gym.rating_stats.avg_rating) : 0;
        const reviewCount = gym.rating_stats ? parseInt(gym.rating_stats.total_reviews, 10) : 0;
        let html = `
            <div class="modal-hero">
                <div class="modal-hero-top">
                    <div class="modal-gym-icon" style="overflow: hidden; padding: ${gym.logo_url ? '0' : '8px'};">${logoHtml}</div>
                    <div class="modal-hero-meta">
                        <h2>${escapeHtml(gym.name)}</h2>
                        <p class="addr">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                            <span>${escapeHtml(gym.address)}</span>
                        </p>
                    </div>
                    <form method="post" action="index.php?page=gym_map" class="desktop-only" style="margin: 0; flex-shrink: 0;">
                        <input type="hidden" name="csrf_token" value="${FT_CSRF_TOKEN}">
                        <input type="hidden" name="action" value="select_gym">
                        <input type="hidden" name="gym_id" value="${gym.gym_id}">
                        <button type="submit" class="btn btn-primary" style="padding: 10px 20px; font-size: 0.95rem; white-space: nowrap; box-shadow: 0 4px 12px rgba(199, 255, 34, 0.2);">Select Gym</button>
                    </form>
                </div>
                <div class="modal-hero-sub">
                    <div class="modal-quickstats">
                        <span style="color:#fbbf24;"><strong style="color:#fbbf24;">★ ${ratingScore.toFixed(1)}</strong> (${reviewCount})</span>
                        ${minPrice !== null ? `<span>From <strong>₱${minPrice.toFixed(0)}</strong></span>` : ''}
                        <a href="index.php?page=view_gym&gym_id=${gym.gym_id}#gym-ratings-section" class="modal-reviews-link" style="color:var(--lime);font-size:12px;text-decoration:none;font-weight:600;margin-left:auto;">View Full Page & Reviews →</a>
                    </div>
                    <form method="post" action="index.php?page=gym_map" class="mobile-only" style="margin: 0; flex-shrink: 0;">
                        <input type="hidden" name="csrf_token" value="${FT_CSRF_TOKEN}">
                        <input type="hidden" name="action" value="select_gym">
                        <input type="hidden" name="gym_id" value="${gym.gym_id}">
                        <button type="submit" class="modal-select-btn-sm">Select Gym</button>
                    </form>
                </div>
                <svg class="modal-pulse-line" viewBox="0 0 500 16" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0,8 L200,8 L212,2 L224,14 L236,8 L500,8" />
                </svg>
                <div class="modal-tabs">
                    ${hasGallery ? `
                    <button type="button" class="modal-tab-btn is-active" id="tabbtn-gallery" data-tab="gallery">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                        <span>Gym Images</span>
                        <span class="modal-tab-badge">${imageCount}</span>
                        <span class="tab-underline"></span>
                    </button>
                    ` : ''}
                    <button type="button" class="modal-tab-btn ${!hasGallery ? 'is-active' : ''}" id="tabbtn-classes" data-tab="classes">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        <span>Classes</span>
                        <span class="modal-tab-badge">${classCount}</span>
                        <span class="tab-underline"></span>
                    </button>
                    <button type="button" class="modal-tab-btn" id="tabbtn-plans" data-tab="plans">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1"/></svg>
                        <span>Membership Plans</span>
                        <span class="modal-tab-badge">${planCount}</span>
                        <span class="tab-underline"></span>
                    </button>
                </div>
            </div>
            <div class="modal-body-inner">
        `;
        
        const fallbackGallerySvg = "data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='800' height='500' viewBox='0 0 800 500'%3E%3Crect width='800' height='500' fill='%23111715'/%3E%3Cpath d='M160 380 L280 200 L380 290 L520 120 L660 380 Z' fill='none' stroke='%23223028' stroke-width='6' stroke-linejoin='round'/%3E%3Ccircle cx='600' cy='150' r='40' fill='%23223028'/%3E%3Crect x='300' y='220' width='200' height='50' rx='25' fill='rgba(199,255,34,0.1)' stroke='%23c7ff22' stroke-width='2'/%3E%3Ctext x='400' y='252' fill='%23c7ff22' font-family='system-ui,sans-serif' font-size='16' font-weight='800' letter-spacing='2' text-anchor='middle'%3EFITTRACK%3C/text%3E%3Ctext x='400' y='320' fill='%23ffffff' font-family='system-ui,sans-serif' font-size='20' font-weight='700' text-anchor='middle'%3EGym Gallery Preview%3C/text%3E%3Ctext x='400' y='348' fill='%2364748b' font-family='system-ui,sans-serif' font-size='14' text-anchor='middle'%3EVisit gym for full facility tour%3C/text%3E%3C/svg%3E";

        if (hasGallery) {
            let slidesHtml = '';
            gym.images.forEach(img => {
                slidesHtml += `
                    <div class="gallery-slide">
                        <img src="${getImageUrl(img)}" alt="Gym Image" loading="lazy" decoding="async" onerror="this.onerror=null; this.src='${fallbackGallerySvg}';">
                    </div>
                `;
            });

            let dotsHtml = '';
            let thumbsHtml = '';
            if (gym.images.length > 1) {
                dotsHtml = `<div class="gallery-dots">` + 
                    gym.images.map((_, i) => `<button type="button" class="gallery-dot${i === 0 ? ' is-active' : ''}" data-index="${i}" aria-label="Go to image ${i + 1}"></button>`).join('') + 
                `</div>`;

                thumbsHtml = `<div class="gallery-thumbs">` +
                    gym.images.map((img, i) => `
                        <button type="button" class="gallery-thumb-btn${i === 0 ? ' is-active' : ''}" data-index="${i}" aria-label="Thumbnail ${i + 1}">
                            <img src="${getImageUrl(img)}" alt="Thumbnail" loading="lazy" decoding="async" onerror="this.onerror=null; this.src='${fallbackGallerySvg}';">
                        </button>
                    `).join('') + 
                `</div>`;
            }

            html += `
                <div class="modal-tab-panel is-active" id="tab-gallery">
                    <div class="gym-gallery-carousel">
                        <div class="gallery-viewport">
                            <div class="gallery-track">
                                ${slidesHtml}
                            </div>
                            ${gym.images.length > 1 ? `
                                <div class="gallery-counter">
                                    <span class="gallery-curr">1</span> / ${gym.images.length}
                                </div>
                                <button type="button" class="gallery-arrow prev" aria-label="Previous photo">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                                </button>
                                <button type="button" class="gallery-arrow next" aria-label="Next photo">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
                                </button>
                            ` : ''}
                        </div>
                        ${dotsHtml}
                        ${thumbsHtml}
                    </div>
                </div>
            `;
        }
        
        html += `<div class="modal-tab-panel ${!hasGallery ? 'is-active' : ''}" id="tab-classes">`;

        if (classCount > 0) {
            html += `<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 230px), 1fr)); gap: 14px;">`;
            gym.classes.forEach(c => {
                html += `
                    <div class="class-card">
                        <div class="class-icon">${guessClassIcon(c.class_name, c.description)}</div>
                        <div>
                            <strong>${escapeHtml(c.class_name)}</strong>
                            <div class="desc">${escapeHtml(c.description || '')}</div>
                        </div>
                    </div>
                `;
            });
            html += `</div>`;
        } else {
            html += `<p style="color: var(--muted);">No classes currently offered.</p>`;
        }

        html += `</div><div class="modal-tab-panel" id="tab-plans">`;

        if (planCount > 0) {
            html += `<div class="gym-plans-grid${planCount === 1 ? ' has-single-plan' : ''}">`;
            gym.plans.forEach(p => {
                const xenditEnabled = gym.xendit_enabled === true || gym.xendit_enabled === 1;
                const isHighlighted = p.plan_id === highlightedPlanId && gym.plans.length > 1;
                const perDay = parseFloat(p.price) / Math.max(1, parseInt(p.duration_days, 10));
                const features = p.features && p.features.length > 0
                    ? p.features
                    : (p.description ? p.description.split('\n').map(s => s.trim()).filter(Boolean) : []);

                let featuresHtml = '';
                if (features.length > 0) {
                    featuresHtml = `<ul class="plan-features-list">`;
                    features.forEach(f => {
                        featuresHtml += `
                            <li class="plan-feature-item">
                                <span class="plan-check">✓</span>
                                <span>${escapeHtml(f)}</span>
                            </li>
                        `;
                    });
                    featuresHtml += `</ul>`;
                } else {
                    featuresHtml = `<p style="color: #94a3b8; font-size: 0.88rem; flex-grow: 1; margin-bottom: 18px; line-height: 1.45;">${escapeHtml(p.description || '')}</p>`;
                }

                html += `
                    <div class="plan-card${isHighlighted ? ' is-best-value' : ''}">
                        ${isHighlighted ? `<span class="plan-best-badge">${escapeHtml(highlightBadgeText)}</span>` : ''}
                        <div style="margin-bottom: 8px; margin-top: ${isHighlighted ? '6px' : '0'};">
                            <strong class="plan-title">${escapeHtml(p.plan_name)}</strong>
                        </div>
                        <div class="plan-price-row">
                            <span class="plan-price">₱${parseFloat(p.price).toFixed(2)}</span>
                        </div>
                        <div class="plan-per-day">~₱${perDay.toFixed(2)}/day &middot; ${p.duration_days} days</div>
                        ${featuresHtml}
                        <form method="post" action="index.php?page=memberships" style="margin-top: auto;">
                            <input type="hidden" name="csrf_token" value="${FT_CSRF_TOKEN}">
                            <input type="hidden" name="action" value="subscribe">
                            <input type="hidden" name="subscribe_plan_id" value="${p.plan_id}">
                            <input type="hidden" name="payment_method" value="xendit">
                            <button type="submit" class="btn btn-primary" style="width: 100%;" ${xenditEnabled ? '' : 'disabled'}>${xenditEnabled ? 'Subscribe with Xendit' : 'Online checkout unavailable'}</button>
                            ${!xenditEnabled ? `
                                <div style="font-size: 11.5px; color: #94a3b8; text-align: center; margin-top: 8px; line-height: 1.4;">
                                    Online checkout is inactive for this gym. You can click <strong style="color: var(--lime);">Select Gym</strong> above to join and settle payment in-person.
                                </div>
                            ` : ''}
                        </form>
                    </div>
                `;
            });
            html += `</div>`;

            if (planCount > 1) {
                html += `
                    <div class="plan-carousel-indicator">
                        <button type="button" class="plan-nav-arrow prev" aria-label="Previous plan">‹</button>
                        <div class="plan-carousel-dots">
                            ${gym.plans.map((_, i) => `<button type="button" class="plan-carousel-dot${i === 0 ? ' is-active' : ''}" data-index="${i}" aria-label="Go to plan ${i + 1}"></button>`).join('')}
                        </div>
                        <button type="button" class="plan-nav-arrow next" aria-label="Next plan">›</button>
                    </div>
                    <div class="plan-swipe-hint">
                        <span>&larr; Swipe to view all plans &rarr;</span>
                    </div>
                `;
            }
        } else {
            html += `<p style="color: #94a3b8;">No membership plans currently available.</p>`;
        }

        html += `</div>`;
        return html;
    }

    function wireGalleryCarousel(container) {
        const carousel = container.querySelector('.gym-gallery-carousel');
        if (!carousel) return;

        const track = carousel.querySelector('.gallery-track');
        const slides = carousel.querySelectorAll('.gallery-slide');
        const counterCurr = carousel.querySelector('.gallery-counter .gallery-curr');
        const dots = carousel.querySelectorAll('.gallery-dot');
        const thumbs = carousel.querySelectorAll('.gallery-thumb-btn');
        const prevBtn = carousel.querySelector('.gallery-arrow.prev');
        const nextBtn = carousel.querySelector('.gallery-arrow.next');

        if (!track || slides.length <= 1) return;

        function updateActive(index) {
            if (counterCurr) counterCurr.textContent = index + 1;
            dots.forEach((dot, i) => dot.classList.toggle('is-active', i === index));
            thumbs.forEach((thumb, i) => {
                thumb.classList.toggle('is-active', i === index);
                if (i === index) {
                    thumb.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                }
            });
        }

        let isScrolling = null;
        track.addEventListener('scroll', function () {
            clearTimeout(isScrolling);
            isScrolling = setTimeout(function () {
                const scrollLeft = track.scrollLeft;
                const slideWidth = track.clientWidth || track.offsetWidth || 1;
                const activeIndex = Math.min(slides.length - 1, Math.max(0, Math.round(scrollLeft / slideWidth)));
                updateActive(activeIndex);
            }, 40);
        }, { passive: true });

        if (prevBtn) {
            prevBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                const slideWidth = track.clientWidth;
                track.scrollBy({ left: -slideWidth, behavior: 'smooth' });
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                const slideWidth = track.clientWidth;
                track.scrollBy({ left: slideWidth, behavior: 'smooth' });
            });
        }

        dots.forEach(function (dot) {
            dot.addEventListener('click', function () {
                const idx = parseInt(dot.dataset.index, 10);
                const slideWidth = track.clientWidth;
                track.scrollTo({ left: idx * slideWidth, behavior: 'smooth' });
                updateActive(idx);
            });
        });

        thumbs.forEach(function (thumb) {
            thumb.addEventListener('click', function () {
                const idx = parseInt(thumb.dataset.index, 10);
                const slideWidth = track.clientWidth;
                track.scrollTo({ left: idx * slideWidth, behavior: 'smooth' });
                updateActive(idx);
            });
        });
    }

    function wirePlansCarousel(container) {
        const grid = container.querySelector('.gym-plans-grid');
        const indicator = container.querySelector('.plan-carousel-indicator');
        if (!grid || !indicator) return;

        const cards = grid.querySelectorAll('.plan-card');
        const dots = indicator.querySelectorAll('.plan-carousel-dot');
        const prevBtn = indicator.querySelector('.plan-nav-arrow.prev');
        const nextBtn = indicator.querySelector('.plan-nav-arrow.next');

        if (cards.length <= 1) return;

        function updateActivePlan(index) {
            dots.forEach((dot, i) => dot.classList.toggle('is-active', i === index));
        }

        let planScrollTimeout = null;
        grid.addEventListener('scroll', function () {
            clearTimeout(planScrollTimeout);
            planScrollTimeout = setTimeout(function () {
                const gridLeft = grid.scrollLeft;
                const cardWidth = cards[0] ? cards[0].offsetWidth + 14 : grid.clientWidth;
                const activeIndex = Math.min(cards.length - 1, Math.max(0, Math.round(gridLeft / cardWidth)));
                updateActivePlan(activeIndex);
            }, 40);
        }, { passive: true });

        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                const cardWidth = cards[0] ? cards[0].offsetWidth + 14 : 280;
                grid.scrollBy({ left: -cardWidth, behavior: 'smooth' });
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                const cardWidth = cards[0] ? cards[0].offsetWidth + 14 : 280;
                grid.scrollBy({ left: cardWidth, behavior: 'smooth' });
            });
        }

        dots.forEach(function (dot) {
            dot.addEventListener('click', function () {
                const idx = parseInt(dot.dataset.index, 10);
                if (cards[idx]) {
                    cards[idx].scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                    updateActivePlan(idx);
                }
            });
        });
    }

    function wireModalInteractions() {
        const tabBtns = modalBody.querySelectorAll('.modal-tab-btn');
        tabBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const target = btn.dataset.tab;
                tabBtns.forEach(b => b.classList.toggle('is-active', b === btn));
                const panels = modalBody.querySelectorAll('.modal-tab-panel');
                panels.forEach(function (panel) {
                    const isTarget = panel.id === 'tab-' + target;
                    if (isTarget) {
                        panel.classList.add('is-active');
                        if (hasGSAP) {
                            gsap.fromTo(panel, { opacity: 0, y: 8 }, { opacity: 1, y: 0, duration: 0.25, ease: 'power2.out' });
                            const items = panel.querySelectorAll('.class-card, .plan-card');
                            gsap.fromTo(items, { opacity: 0, y: 10 }, { opacity: 1, y: 0, duration: 0.25, stagger: 0.03, delay: 0.05, ease: 'power2.out' });
                        }
                    } else {
                        panel.classList.remove('is-active');
                    }
                });
            });
        });

        wireGalleryCarousel(modalBody);
        wirePlansCarousel(modalBody);

        modalBody.querySelectorAll('.plan-card .btn').forEach(function (btn) {
            btn.addEventListener('mouseenter', function () { if (hasGSAP) gsap.to(btn, { scale: 1.03, duration: 0.2, ease: 'power2.out' }); });
            btn.addEventListener('mouseleave', function () { if (hasGSAP) gsap.to(btn, { scale: 1, duration: 0.2, ease: 'power2.out' }); });
        });
    }

    function openGymModal(index) {
        const gym = gymData[index];
        if (!gym || !modal || !modalBody) return;

        modalBody.innerHTML = buildModalHtml(gym);
        wireModalInteractions();

        if (gym.brand_color) {
            modalContent.style.setProperty('--lime', gym.brand_color);
        } else {
            modalContent.style.removeProperty('--lime');
        }

        modal.style.display = 'flex';
        modal.style.visibility = 'visible';
        modal.style.pointerEvents = 'auto';

        if (hasGSAP) {
            gsap.killTweensOf([modalBackdrop, modalContent]);
            gsap.to(modalBackdrop, { opacity: 1, duration: 0.25, ease: 'power2.out' });
            gsap.fromTo(modalContent,
                { scale: 0.95, opacity: 0, y: 12 },
                { scale: 1, opacity: 1, y: 0, duration: 0.35, ease: 'power3.out' }
            );
            const items = modalBody.querySelectorAll('.class-card, .plan-card');
            gsap.fromTo(items, { opacity: 0, y: 14 }, { opacity: 1, y: 0, duration: 0.3, stagger: 0.03, delay: 0.15, ease: 'power2.out' });
        } else {
            if (modalBackdrop) modalBackdrop.style.opacity = '1';
            if (modalContent) modalContent.style.opacity = '1';
        }
    }

    function closeGymModal() {
        if (!modal) return;
        if (hasGSAP) {
            gsap.to(modalContent, { scale: 0.96, opacity: 0, y: 8, duration: 0.2, ease: 'power2.in' });
            gsap.to(modalBackdrop, {
                opacity: 0,
                duration: 0.25,
                delay: 0.05,
                ease: 'power2.in',
                onComplete: function () {
                    modal.style.visibility = 'hidden';
                    modal.style.pointerEvents = 'none';
                    modal.style.display = 'none';
                }
            });
        } else {
            if (modalBackdrop) modalBackdrop.style.opacity = '0';
            if (modalContent) modalContent.style.opacity = '0';
            modal.style.visibility = 'hidden';
            modal.style.pointerEvents = 'none';
            modal.style.display = 'none';
        }
    }

    window.openGymModal = openGymModal;
    window.closeGymModal = closeGymModal;

    if (modalCloseBtn) modalCloseBtn.addEventListener('click', closeGymModal);
    if (modalBackdrop) modalBackdrop.addEventListener('click', closeGymModal);
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && modal && (modal.style.visibility === 'visible' || modal.style.display !== 'none')) {
            closeGymModal();
        }
    });

    // Delegated click listener for popup Details buttons (catches any bubbled clicks)
    document.addEventListener('click', function (ev) {
        const detailsBtn = ev.target.closest('.map-popup-btn.secondary[data-gym-index]');
        if (detailsBtn) {
            ev.preventDefault();
            ev.stopPropagation();
            const gymIdx = parseInt(detailsBtn.getAttribute('data-gym-index'), 10);
            if (!isNaN(gymIdx)) {
                openGymModal(gymIdx);
            }
        }
    });

    // ---------------------------------------------------------------
    // Leaflet Interactive Map
    // ---------------------------------------------------------------
    function buildPopupContent(gym, index) {
        const brandColor = gym.brand_color || '#c7ff22';
        const ratingScore = gym.rating_stats ? parseFloat(gym.rating_stats.avg_rating).toFixed(1) : '0.0';
        const reviewCount = gym.rating_stats ? parseInt(gym.rating_stats.total_reviews, 10) : 0;
        const distText = gym._distanceKm ? formatDistance(gym._distanceKm) : '';

        let logoHtml = gym.logo_url
            ? `<img src="${getImageUrl(gym.logo_url)}" alt="Logo" loading="lazy" onerror="this.outerHTML='<span>${escapeHtml(gym.name.charAt(0))}</span>';">`
            : `<span>${escapeHtml(gym.name.charAt(0))}</span>`;

        return `
            <div class="map-popup-card">
                <div class="map-popup-top">
                    <div class="map-popup-logo" style="border-color: ${brandColor}40;">${logoHtml}</div>
                    <div class="map-popup-meta">
                        <h4>${escapeHtml(gym.name)}</h4>
                        <p class="addr">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                            <span>${escapeHtml(gym.address)}</span>
                        </p>
                    </div>
                </div>
                <div class="map-popup-stats">
                    <span class="map-popup-chip rating">★ ${ratingScore} (${reviewCount})</span>
                    <span class="map-popup-chip">${gym.classes ? gym.classes.length : 0} Classes</span>
                    ${distText ? `<span class="map-popup-chip dist">📍 ${distText}</span>` : ''}
                </div>
                <div class="map-popup-actions">
                    <form method="post" action="index.php?page=gym_map" style="margin: 0; flex: 1;">
                        <input type="hidden" name="csrf_token" value="${FT_CSRF_TOKEN}">
                        <input type="hidden" name="action" value="select_gym">
                        <input type="hidden" name="gym_id" value="${gym.gym_id}">
                        <button type="submit" class="map-popup-btn primary" style="width: 100%;">Select Gym</button>
                    </form>
                    <button type="button" class="map-popup-btn secondary" data-gym-index="${index}" onclick="window.openGymModal && window.openGymModal(${index}); return false;">Details</button>
                </div>
            </div>
        `;
    }

    function renderGymMarkers() {
        if (!map || !markersGroup) return;
        markersGroup.clearLayers();
        gymMarkersMap.clear();

        gymData.forEach(function (gym, idx) {
            if (!gym.latitude || !gym.longitude) return;

            const lat = parseFloat(gym.latitude);
            const lng = parseFloat(gym.longitude);
            if (isNaN(lat) || isNaN(lng)) return;

            const brandColor = gym.brand_color || '#c7ff22';
            const initial = gym.name ? gym.name.charAt(0).toUpperCase() : 'G';

            const icon = L.divIcon({
                className: 'gym-marker-wrap',
                html: `
                    <div class="gym-map-pin" style="--lime: ${brandColor};" title="${escapeHtml(gym.name)}">
                        <div class="pin-content">${escapeHtml(initial)}</div>
                    </div>
                `,
                iconSize: [38, 38],
                iconAnchor: [19, 38],
                popupAnchor: [0, -38]
            });

            const marker = L.marker([lat, lng], { icon: icon, title: gym.name });
            marker.bindPopup(buildPopupContent(gym, idx), {
                maxWidth: 320,
                minWidth: 260,
                className: 'gym-dark-popup'
            });

            markersGroup.addLayer(marker);
            gymMarkersMap.set(gym.gym_id, marker);

            // Attach click handler for Details button when popup opens
            marker.on('popupopen', function (e) {
                const popupEl = e.popup.getElement();
                if (!popupEl) return;
                const detailsBtn = popupEl.querySelector('.map-popup-btn.secondary[data-gym-index]');
                if (detailsBtn) {
                    detailsBtn.onclick = function (ev) {
                        ev.stopPropagation();
                        ev.preventDefault();
                        const gymIdx = parseInt(this.getAttribute('data-gym-index'), 10);
                        openGymModal(gymIdx);
                    };
                }
            });
        });
    }

    function fitAllGyms() {
        if (!map || !markersGroup || markersGroup.getLayers().length === 0) return;
        const boundsGroup = L.featureGroup(markersGroup.getLayers());
        if (userMarker) {
            boundsGroup.addLayer(userMarker);
        }
        map.fitBounds(boundsGroup.getBounds().pad(0.18));
    }

    function updateUserMarkerOnMap(lat, lng) {
        if (!map) return;

        const userIcon = L.divIcon({
            className: 'user-marker-wrap',
            html: `
                <div class="user-pulse-marker">
                    <div class="user-pulse-ring"></div>
                    <div class="user-pulse-dot"></div>
                </div>
            `,
            iconSize: [24, 24],
            iconAnchor: [12, 12]
        });

        if (userMarker) {
            userMarker.setLatLng([lat, lng]);
        } else {
            userMarker = L.marker([lat, lng], { icon: userIcon, zIndexOffset: 1000 }).addTo(map);
            userMarker.bindTooltip("Your Location", { permanent: false, direction: 'top', offset: [0, -12] });
        }

        const recenterBtn = document.getElementById('btn-recenter-user');
        if (recenterBtn) {
            recenterBtn.classList.remove('is-locating');
            recenterBtn.classList.add('is-active');
            recenterBtn.setAttribute('title', 'Center on my location');
        }
    }

    function applyUserLocation(pos) {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        userCoords = { lat, lng };

        if (btnNearMe) {
            btnNearMe.classList.remove('is-locating');
            btnNearMe.classList.add('is-active');
            const btnText = btnNearMe.querySelector('.btn-text');
            if (btnText) btnText.textContent = 'Located';
        }

        const recenterBtn = document.getElementById('btn-recenter-user');
        if (recenterBtn) {
            recenterBtn.classList.remove('is-locating');
            recenterBtn.classList.add('is-active');
            recenterBtn.setAttribute('title', 'Center on my location');
        }

        // Calculate distance to each gym
        gymData.forEach(function (gym) {
            if (gym.latitude && gym.longitude) {
                const gLat = parseFloat(gym.latitude);
                const gLng = parseFloat(gym.longitude);
                if (!isNaN(gLat) && !isNaN(gLng)) {
                    gym._distanceKm = haversineDistance(lat, lng, gLat, gLng);
                }
            }
        });

        // Re-render markers with updated distances in popups
        renderGymMarkers();
        updateUserMarkerOnMap(lat, lng);
        fitAllGyms();

        if (mapStatusText) {
            mapStatusText.textContent = 'Nearest gyms sorted by your GPS location';
        }
    }

    function requestDeviceLocation() {
        if (!navigator.geolocation) {
            alert('Geolocation is not supported by your current browser.');
            return;
        }

        if (btnNearMe) {
            btnNearMe.classList.add('is-locating');
            const btnText = btnNearMe.querySelector('.btn-text');
            if (btnText) btnText.textContent = 'Locating...';
        }

        const recenterBtn = document.getElementById('btn-recenter-user');
        if (recenterBtn) {
            recenterBtn.classList.add('is-locating');
            recenterBtn.setAttribute('title', 'Locating your GPS position...');
        }

        navigator.geolocation.getCurrentPosition(
            applyUserLocation,
            function (err) {
                if (btnNearMe) {
                    btnNearMe.classList.remove('is-locating');
                    const btnText = btnNearMe.querySelector('.btn-text');
                    if (btnText) btnText.textContent = 'Near Me';
                }
                const btn = document.getElementById('btn-recenter-user');
                if (btn) {
                    btn.classList.remove('is-locating');
                    btn.setAttribute('title', 'Center on my location');
                }

                let msg = 'Unable to retrieve your location.';
                if (err.code === 1) msg = 'Location access was declined in your browser settings.';
                else if (err.code === 2) msg = 'Location signal unavailable.';
                else if (err.code === 3) msg = 'Location request timed out.';
                alert(msg);
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
        );
    }

    function initMap() {
        if (map || typeof L === 'undefined') return;

        let defaultCenter = [14.5995, 120.9842];
        const firstWithCoords = gymData.find(g => g.latitude && g.longitude);
        if (firstWithCoords) {
            defaultCenter = [parseFloat(firstWithCoords.latitude), parseFloat(firstWithCoords.longitude)];
        }

        map = L.map('gym-map', {
            center: defaultCenter,
            zoom: 13,
            zoomControl: true,
            attributionControl: true
        });

        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors',
            maxZoom: 19
        }).addTo(map);

        markersGroup = L.featureGroup().addTo(map);

        // Add custom Map Action Controls (My Location & Fit All) inside the map just like zoom controls
        const MapActionsControl = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function () {
                const container = L.DomUtil.create('div', 'leaflet-bar leaflet-control leaflet-control-map-tools');

                // Recenter / Locate User Button (icon only)
                const userBtn = L.DomUtil.create('a', 'map-control-btn btn-recenter-user', container);
                userBtn.id = 'btn-recenter-user';
                userBtn.href = '#';
                userBtn.setAttribute('role', 'button');
                userBtn.title = 'My Location';
                userBtn.setAttribute('aria-label', 'My Location');
                userBtn.innerHTML = `
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                        <polygon points="3 11 22 2 13 21 11 13 3 11"/>
                    </svg>
                `;

                // Fit All Gyms Button (icon only)
                const fitBtn = L.DomUtil.create('a', 'map-control-btn btn-fit-all', container);
                fitBtn.id = 'btn-fit-all-gyms';
                fitBtn.href = '#';
                fitBtn.setAttribute('role', 'button');
                fitBtn.title = 'Fit All Gyms';
                fitBtn.setAttribute('aria-label', 'Fit All Gyms');
                fitBtn.innerHTML = `
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M15 3h6v6"/><path d="M9 21H3v-6"/><path d="M21 3l-7 7"/><path d="M3 21l7-7"/>
                    </svg>
                `;

                L.DomEvent.disableClickPropagation(container);
                L.DomEvent.disableScrollPropagation(container);

                userBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (userCoords && map) {
                        map.flyTo([userCoords.lat, userCoords.lng], 15, { duration: 1.2 });
                    } else {
                        requestDeviceLocation();
                    }
                });

                fitBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    fitAllGyms();
                });

                return container;
            }
        });

        map.addControl(new MapActionsControl());

        renderGymMarkers();
        fitAllGyms();

        // Responsive ResizeObserver to ensure tiles fill the container dynamically
        const mapEl = document.getElementById('gym-map');
        if (window.ResizeObserver && mapEl) {
            let roTimer = null;
            const ro = new ResizeObserver(entries => {
                for (const entry of entries) {
                    if (entry.contentRect.width > 0 && entry.contentRect.height > 0) {
                        clearTimeout(roTimer);
                        roTimer = setTimeout(() => {
                            if (map) map.invalidateSize({ pan: false });
                        }, 60);
                    }
                }
            });
            ro.observe(mapEl);
        }
        window.addEventListener('resize', () => {
            if (map) map.invalidateSize({ pan: false });
        });
        [100, 300, 700].forEach(delay => {
            setTimeout(() => { if (map) map.invalidateSize({ pan: false }); }, delay);
        });
    }

    // ---------------------------------------------------------------
    // Search Filtering
    // ---------------------------------------------------------------
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const query = this.value.trim().toLowerCase();
            let matchesCount = 0;

            gymData.forEach(function (gym, idx) {
                const marker = gymMarkersMap.get(gym.gym_id);
                if (!marker) return;

                const nameMatch = gym.name.toLowerCase().includes(query);
                const addrMatch = (gym.address || '').toLowerCase().includes(query);
                const isMatch = !query || nameMatch || addrMatch;

                if (isMatch) {
                    matchesCount++;
                    if (!markersGroup.hasLayer(marker)) {
                        markersGroup.addLayer(marker);
                    }
                } else {
                    if (markersGroup.hasLayer(marker)) {
                        markersGroup.removeLayer(marker);
                    }
                }
            });

            if (noResultsEl) {
                noResultsEl.style.display = matchesCount === 0 ? 'block' : 'none';
            }

            if (matchesCount > 0) {
                fitAllGyms();
            }
        });
    }

    // "Near Me" GPS Button in Toolbar
    if (btnNearMe) {
        btnNearMe.addEventListener('click', requestDeviceLocation);
    }

    // Initialize map on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMap);
    } else {
        initMap();
    }
})();
