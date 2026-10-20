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
    const btnRecenterUser = document.getElementById('btn-recenter-user');
    const btnFitAll = document.getElementById('btn-fit-all-gyms');
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

    function getImageUrl(path) {
        if (!path) return '';
        if (path.startsWith('http://') || path.startsWith('https://')) return path;
        return path;
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
    // Modal Helpers
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

    function openGymModal(index) {
        const gym = gymData[index];
        if (!gym || !modal || !modalBody) return;

        const classCount = gym.classes ? gym.classes.length : 0;
        const planCount = gym.plans ? gym.plans.length : 0;
        const hasGallery = Array.isArray(gym.images) && gym.images.length > 0;
        const imageCount = hasGallery ? gym.images.length : 0;

        let logoHtml = gym.logo_url 
            ? `<img src="${getImageUrl(gym.logo_url)}" alt="Logo" class="modal-gym-logo">` 
            : `<div class="modal-gym-logo-placeholder">${escapeHtml(gym.name.charAt(0))}</div>`;

        let html = `
            <div class="modal-header-sticky">
                <div class="modal-header-top">
                    ${logoHtml}
                    <div style="flex: 1; min-width: 0;">
                        <h3>${escapeHtml(gym.name)}</h3>
                        <p class="modal-address">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                            <span>${escapeHtml(gym.address)}</span>
                        </p>
                    </div>
                    <form method="post" action="index.php?page=gym_map" class="desktop-only" style="margin: 0; flex-shrink: 0;">
                        <input type="hidden" name="csrf_token" value="${FT_CSRF_TOKEN}">
                        <input type="hidden" name="action" value="select_gym">
                        <input type="hidden" name="gym_id" value="${gym.gym_id}">
                        <button type="submit" class="modal-select-btn">Select Gym</button>
                    </form>
                </div>
                <div class="modal-rating-row" style="display:flex;align-items:center;gap:8px;padding:4px 0 10px;margin-bottom:8px;border-bottom:1px solid rgba(255,255,255,0.06);flex-wrap:wrap;">
                    <div style="display:inline-flex;align-items:center;gap:4px;background:rgba(199,255,34,0.12);padding:3px 8px;border-radius:6px;border:1px solid rgba(199,255,34,0.3);font-size:12px;font-weight:700;color:var(--lime);">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        <span>${gym.rating_stats ? parseFloat(gym.rating_stats.avg_rating).toFixed(1) : '0.0'}</span>
                    </div>
                    <span style="font-size:12px;color:var(--muted);">${gym.rating_stats ? parseInt(gym.rating_stats.total_reviews, 10) : 0} reviews</span>
                    <a href="index.php?page=view_gym&gym_id=${gym.gym_id}#gym-ratings-section" class="modal-reviews-link" style="color:var(--lime);font-size:12px;text-decoration:none;font-weight:600;margin-left:auto;">View Full Page & Reviews →</a>
                </div>
                <div class="modal-tabs">
                    ${hasGallery ? `
                    <button type="button" class="modal-tab-btn is-active" id="tabbtn-gallery" data-tab="gallery">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                        <span>Gym Images</span>
                        <span class="modal-tab-badge">${imageCount}</span>
                    </button>
                    ` : ''}
                    <button type="button" class="modal-tab-btn ${!hasGallery ? 'is-active' : ''}" id="tabbtn-classes" data-tab="classes">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        <span>Classes</span>
                        <span class="modal-tab-badge">${classCount}</span>
                    </button>
                    <button type="button" class="modal-tab-btn" id="tabbtn-plans" data-tab="plans">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1"/></svg>
                        <span>Membership Plans</span>
                        <span class="modal-tab-badge">${planCount}</span>
                    </button>
                </div>
            </div>
            <div class="modal-body-inner">
        `;

        if (hasGallery) {
            let slidesHtml = '';
            gym.images.forEach(img => {
                slidesHtml += `
                    <div class="gallery-slide">
                        <img src="${getImageUrl(img)}" alt="Gym Image" loading="lazy" decoding="async">
                    </div>
                `;
            });

            html += `
                <div class="modal-tab-panel is-active" id="tab-gallery">
                    <div class="gym-gallery-carousel">
                        <div class="gallery-viewport">
                            <div class="gallery-track">
                                ${slidesHtml}
                            </div>
                        </div>
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
                            <h4>${escapeHtml(c.class_name)}</h4>
                            <p>${escapeHtml(c.description || 'No description available.')}</p>
                        </div>
                    </div>
                `;
            });
            html += `</div>`;
        } else {
            html += `<div class="empty-state">No classes scheduled at this time.</div>`;
        }
        html += `</div>`;

        html += `<div class="modal-tab-panel" id="tab-plans">`;
        if (planCount > 0) {
            html += `<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 250px), 1fr)); gap: 14px;">`;
            gym.plans.forEach(p => {
                const priceFormatted = parseFloat(p.price).toLocaleString('en-PH', { style: 'currency', currency: 'PHP' });
                html += `
                    <div class="plan-card">
                        <div class="plan-icon">${guessPlanIcon(p.plan_name)}</div>
                        <div>
                            <h4>${escapeHtml(p.plan_name)}</h4>
                            <div class="plan-price">${priceFormatted} <small style="font-size: 0.75rem; color: var(--muted); font-weight: 500;">/ ${escapeHtml(p.duration_days)} days</small></div>
                            <p>${escapeHtml(p.description || 'Full gym and floor access.')}</p>
                        </div>
                    </div>
                `;
            });
            html += `</div>`;
        } else {
            html += `<div class="empty-state">No membership plans currently available.</div>`;
        }
        html += `</div></div>`;

        modalBody.innerHTML = html;

        // Modal Tab Switching
        modalBody.querySelectorAll('.modal-tab-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const targetTab = this.getAttribute('data-tab');
                modalBody.querySelectorAll('.modal-tab-btn').forEach(b => b.classList.remove('is-active'));
                modalBody.querySelectorAll('.modal-tab-panel').forEach(p => p.classList.remove('is-active'));
                this.classList.add('is-active');
                const panel = modalBody.querySelector('#tab-' + targetTab);
                if (panel) panel.classList.add('is-active');
            });
        });

        modal.style.display = 'block';
        if (hasGSAP && modalContent && modalBackdrop) {
            gsap.set(modalBackdrop, { opacity: 0 });
            gsap.set(modalContent, { opacity: 0, scale: 0.95, y: 15 });
            gsap.to(modalBackdrop, { opacity: 1, duration: 0.25, ease: 'power2.out' });
            gsap.to(modalContent, { opacity: 1, scale: 1, y: 0, duration: 0.3, ease: 'back.out(1.4)' });
        }
    }

    function closeGymModal() {
        if (!modal) return;
        if (hasGSAP && modalContent && modalBackdrop) {
            gsap.to(modalBackdrop, { opacity: 0, duration: 0.2 });
            gsap.to(modalContent, { opacity: 0, scale: 0.96, y: 10, duration: 0.2, onComplete: () => { modal.style.display = 'none'; } });
        } else {
            modal.style.display = 'none';
        }
    }

    window.openGymModal = openGymModal;
    window.closeGymModal = closeGymModal;

    if (modalCloseBtn) modalCloseBtn.addEventListener('click', closeGymModal);
    if (modalBackdrop) modalBackdrop.addEventListener('click', closeGymModal);
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && modal && modal.style.display !== 'none') {
            closeGymModal();
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
                    <button type="button" class="map-popup-btn secondary" onclick="openGymModal(${index})">Details</button>
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

        if (btnRecenterUser) {
            btnRecenterUser.style.display = 'inline-flex';
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

        renderGymMarkers();
        fitAllGyms();
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

    // "Near Me" GPS Button
    if (btnNearMe) {
        btnNearMe.addEventListener('click', function () {
            if (!navigator.geolocation) {
                alert('Geolocation is not supported by your current browser.');
                return;
            }

            btnNearMe.classList.add('is-locating');
            const btnText = btnNearMe.querySelector('.btn-text');
            if (btnText) btnText.textContent = 'Locating...';

            navigator.geolocation.getCurrentPosition(
                applyUserLocation,
                function (err) {
                    btnNearMe.classList.remove('is-locating');
                    let msg = 'Unable to retrieve your location.';
                    if (err.code === 1) msg = 'Location access was declined in your browser settings.';
                    else if (err.code === 2) msg = 'Location signal unavailable.';
                    else if (err.code === 3) msg = 'Location request timed out.';
                    alert(msg);
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
            );
        });
    }

    // Recenter & Fit Buttons
    if (btnRecenterUser) {
        btnRecenterUser.addEventListener('click', function () {
            if (userCoords && map) {
                map.flyTo([userCoords.lat, userCoords.lng], 15, { duration: 1.2 });
            }
        });
    }

    if (btnFitAll) {
        btnFitAll.addEventListener('click', function () {
            fitAllGyms();
        });
    }

    // Initialize map on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMap);
    } else {
        initMap();
    }
})();
