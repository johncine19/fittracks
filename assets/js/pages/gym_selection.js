/**
 * Member Gym Selection & Showcase Controller
 * Extracted from member/gym_selection.php
 */

const FT_CONFIG = window.GYM_SELECTION_CONFIG || {};
const gymData = FT_CONFIG.gymData || [];
const FT_CSRF_TOKEN = FT_CONFIG.csrfToken || (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');

    // gymData initialized from FT_CONFIG at top
    const hasGSAP = typeof gsap !== 'undefined';

    const carousel = document.getElementById('carousel');
    const modal = document.getElementById('gymDetailsModal');
    const modalBackdrop = document.getElementById('modal-backdrop');
    const modalContent = document.getElementById('modal-content');
    const modalBody = document.getElementById('modalBody');

    // ---------------------------------------------------------------
    // Entrance animation
    // ---------------------------------------------------------------
    (function initEntrance() {
        const header = document.getElementById('gym-header');
        const searchWrap = document.querySelector('.gym-search-wrap');
        const cards = document.querySelectorAll('.gym-card');
        if (!hasGSAP) return;

        gsap.set(header, { opacity: 0, y: 16 });
        if (searchWrap) gsap.set(searchWrap, { opacity: 0, y: 12 });
        gsap.set(cards, { opacity: 0, y: 24 });

        const tl = gsap.timeline({ defaults: { ease: 'power3.out' } });
        tl.to(header, { opacity: 1, y: 0, duration: 0.45 });
        if (searchWrap) tl.to(searchWrap, { opacity: 1, y: 0, duration: 0.35 }, '-=0.2');
        tl.to(cards, { opacity: 1, y: 0, duration: 0.4, stagger: 0.08 }, '-=0.15');

        cards.forEach(function (c) {
            c.addEventListener('mouseenter', function () {
                gsap.to(c, { y: -6, boxShadow: '0 14px 30px rgba(0,0,0,0.3), 0 0 0 1px rgba(199,255,34,0.25)', borderColor: 'var(--lime)', duration: 0.25, ease: 'power2.out' });
            });
            c.addEventListener('mouseleave', function () {
                gsap.to(c, { y: 0, boxShadow: '0 0px 0px rgba(0,0,0,0)', borderColor: 'var(--line)', duration: 0.3, ease: 'power2.out' });
            });
        });
    })();

    // ---------------------------------------------------------------
    // Carousel navigation
    // ---------------------------------------------------------------
    (function initCarousel() {
        if (!carousel) return;
        const prevBtn = document.getElementById('carousel-prev');
        const nextBtn = document.getElementById('carousel-next');
        const dotsWrap = document.getElementById('carousel-dots');
        const cards = Array.prototype.slice.call(carousel.querySelectorAll('.gym-card'));

        cards.forEach(function (_, i) {
            const dot = document.createElement('div');
            dot.className = 'carousel-dot' + (i === 0 ? ' is-active' : '');
            dot.addEventListener('click', function () {
                cards[i].scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' });
            });
            dotsWrap.appendChild(dot);
        });
        const dots = Array.prototype.slice.call(dotsWrap.children);

        function scrollByCard(direction) {
            const cardWidth = cards[0] ? cards[0].getBoundingClientRect().width + 20 : 320;
            carousel.scrollBy({ left: direction * cardWidth, behavior: 'smooth' });
        }
        if (prevBtn) prevBtn.addEventListener('click', function () { scrollByCard(-1); });
        if (nextBtn) nextBtn.addEventListener('click', function () { scrollByCard(1); });

        function updateNavState() {
            const maxScroll = carousel.scrollWidth - carousel.clientWidth - 4;
            if (prevBtn) prevBtn.classList.toggle('is-disabled', carousel.scrollLeft <= 4);
            if (nextBtn) nextBtn.classList.toggle('is-disabled', carousel.scrollLeft >= maxScroll);

            let closestIndex = 0;
            let closestDist = Infinity;
            cards.forEach(function (c, i) {
                const dist = Math.abs(c.offsetLeft - carousel.scrollLeft);
                if (dist < closestDist) { closestDist = dist; closestIndex = i; }
            });
            dots.forEach(function (d, i) { d.classList.toggle('is-active', i === closestIndex); });
        }

        carousel.addEventListener('scroll', function () { window.requestAnimationFrame(updateNavState); });
        updateNavState();
    })();

    // ---------------------------------------------------------------
    // Search / filter
    // ---------------------------------------------------------------
    (function initSearch() {
        const input = document.getElementById('gym-search');
        const noResults = document.getElementById('gym-no-results');
        if (!input || !carousel) return;

        input.addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();
            let anyVisible = false;
            carousel.querySelectorAll('.gym-card').forEach(function (card) {
                const matches = !q || card.dataset.search.indexOf(q) !== -1;
                if (matches) anyVisible = true;
                if (matches && card.classList.contains('is-filtered-out')) {
                    card.classList.remove('is-filtered-out');
                    if (hasGSAP) gsap.fromTo(card, { opacity: 0, y: -6 }, { opacity: 1, y: 0, duration: 0.25 });
                } else if (!matches) {
                    card.classList.add('is-filtered-out');
                }
            });
            if (noResults) noResults.classList.toggle('show', !anyVisible);
        });
    })();

    // ---------------------------------------------------------------
    // Modal helpers
    // ---------------------------------------------------------------
    function guessClassIcon(name, desc) {
        const text = (name + ' ' + (desc || '')).toLowerCase();
        if (/(yoga|stretch|mobility|flex)/.test(text)) return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>';
        if (/(cycle|spin|bike)/.test(text)) return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/></svg>';
        if (/(crossfit|power|strength|lift|weight)/.test(text)) return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>';
        if (/(hiit|burn|cardio|zumba|dance)/.test(text)) return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8.5 14.5A2.5 2.5 0 0011 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 11-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 002.5 2.5z"/></svg>';
        if (/(core|abs|pilates)/.test(text)) return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>';
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
    }

    function getImageUrl(filename, folder = 'uploads') {
        if (!filename) return '';
        if (filename.startsWith('http://') || filename.startsWith('https://')) return escapeHtml(filename);
        return `assets/${folder}/${escapeHtml(filename)}`;
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
            
            // Respect the gym owner's configured "Most Popular" plan from page=plans
            const popularPlan = gym.plans.find(p => p.is_popular === true || p.is_popular === 1 || p.is_popular === '1');
            if (popularPlan) {
                highlightedPlanId = popularPlan.plan_id;
                highlightBadgeText = 'Most Popular';
            } else {
                // Fallback: check for quarterly plan or lowest per-day price
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
                    <form method="post" action="index.php?page=gym_selection" class="desktop-only" style="margin: 0; flex-shrink: 0;">
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
                    <form method="post" action="index.php?page=gym_selection" class="mobile-only" style="margin: 0; flex-shrink: 0;">
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
            html += `<div class="gym-plans-grid">`;
            gym.plans.forEach(p => {
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
                            <input type="hidden" name="payment_method" value="gcash">
                            <button type="submit" class="btn btn-primary" style="width: 100%;">Subscribe</button>
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
        modalBody.innerHTML = buildModalHtml(gym);
        wireModalInteractions();

        if (gym.brand_color) {
            modalContent.style.setProperty('--lime', gym.brand_color);
        } else {
            modalContent.style.removeProperty('--lime');
        }

        modal.style.visibility = 'visible';
        modal.style.pointerEvents = 'auto';

        if (hasGSAP) {
            gsap.killTweensOf([modalBackdrop, modalContent]);
            // Backdrop and content animate independently (siblings) so their
            // opacities never multiply together -- this is what fixes the
            // "page bleeding through" issue.
            gsap.to(modalBackdrop, { opacity: 1, duration: 0.25, ease: 'power2.out' });
            gsap.fromTo(modalContent,
                { scale: 0.95, opacity: 0, y: 12 },
                { scale: 1, opacity: 1, y: 0, duration: 0.35, ease: 'power3.out' }
            );
            const items = modalBody.querySelectorAll('.class-card');
            gsap.fromTo(items, { opacity: 0, y: 14 }, { opacity: 1, y: 0, duration: 0.3, stagger: 0.03, delay: 0.15, ease: 'power2.out' });
        } else {
            modalBackdrop.style.opacity = '1';
            modalContent.style.opacity = '1';
        }

        document.addEventListener('keydown', onModalKeydown);
    }

    function closeGymModal() {
        document.removeEventListener('keydown', onModalKeydown);
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
                }
            });
        } else {
            modalBackdrop.style.opacity = '0';
            modalContent.style.opacity = '0';
            modal.style.visibility = 'hidden';
            modal.style.pointerEvents = 'none';
        }
    }

    function onModalKeydown(e) {
        if (e.key === 'Escape') closeGymModal();
    }

    document.getElementById('modal-close-btn').addEventListener('click', closeGymModal);
    modalBackdrop.addEventListener('click', closeGymModal);

    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return unsafe
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }
