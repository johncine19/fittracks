/**
 * Member Profile & Settings Controller
 * Extracted from profile.php
 */

    function togglePlatformReviewEdit(showEdit) {
        const form = document.getElementById('platform-review-form');
        const view = document.getElementById('platform-review-view');
        if (form) form.style.display = showEdit ? 'flex' : 'none';
        if (view) view.style.display = showEdit ? 'none' : 'block';
    }

    function setPlatformRating(aspect, val) {
        const inp = document.getElementById('owner-overall-rating');
        if (inp) inp.value = val;
        const btns = document.querySelectorAll('#owner-star-picker .owner-star-btn');
        btns.forEach((btn, idx) => {
            const starVal = idx + 1;
            btn.style.color = starVal <= val ? '#f59e0b' : 'var(--star-unfilled, #4b5563)';
        });
        const textEl = document.getElementById('owner-rating-text');
        if (textEl) textEl.textContent = val + ' / 5 Stars';
    }

    function setSubRating(targetInputId, val, clickedEl) {
        const inp = document.getElementById(targetInputId);
        if (inp) inp.value = val;
        const parent = clickedEl.parentElement;
        if (parent) {
            const stars = parent.querySelectorAll('.sub-star');
            stars.forEach((s, idx) => {
                s.style.color = (idx + 1) <= val ? '#f59e0b' : 'var(--star-unfilled, #4b5563)';
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        // ── Tab Navigation ──
        const tabs = document.querySelectorAll('.settings-tab');
        const panels = document.querySelectorAll('.settings-panel');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const target = tab.dataset.tab;
                tabs.forEach(t => t.classList.remove('active'));
                panels.forEach(p => { p.classList.remove('active'); p.style.display = 'none'; });
                tab.classList.add('active');
                const panel = document.querySelector('[data-panel="' + target + '"]');
                if (panel) { 
                    panel.style.display = 'block';
                    // Trigger entrance animation
                    requestAnimationFrame(() => panel.classList.add('active'));
                }
            });
        });
        // Show first panel
        panels.forEach((p, i) => { if (i > 0) p.style.display = 'none'; });

        // URL hash or tab query parameter activation
        const urlParams = new URLSearchParams(window.location.search);
        let initialTab = (urlParams.get('tab') || window.location.hash.replace('#', '') || '').toLowerCase();
        if (window.location.hash === '#platform-feedback-card' || window.location.hash === '#platform-review-form') {
            initialTab = 'ratings_feedback';
        }
        if (initialTab) {
            const matchedTab = document.querySelector(`.settings-tab[data-tab="${initialTab}"]`);
            if (matchedTab) {
                matchedTab.click();
            }
        }

        const presetRating = parseInt(urlParams.get('rating'), 10);
        if (presetRating >= 1 && presetRating <= 5) {
            setPlatformRating('overall', presetRating);
        }
        if (window.location.hash === '#platform-feedback-card' || window.location.hash === '#platform-review-form') {
            togglePlatformReviewEdit(true);
            const target = document.getElementById('platform-feedback-card');
            if (target) {
                setTimeout(() => target.scrollIntoView({ behavior: 'smooth', block: 'center' }), 200);
            }
        }

        // ── Video BG Toggle ──
        const toggleVideoBg = document.getElementById('toggleVideoBg');
        if (toggleVideoBg) {
            toggleVideoBg.addEventListener('change', function(e) {
                const isEnabled = e.target.checked;
                document.cookie = "fittracks_video_bg=" + (isEnabled ? "on" : "off") + "; path=/; max-age=31536000";
                const video = document.getElementById('app-bg-video');
                if (!isEnabled && video) {
                    video.pause();
                    video.style.display = 'none';
                } else if (isEnabled) {
                    if (video) { video.style.display = 'block'; video.play(); }
                    else { location.reload(); }
                }
            });
        }

        // ── Home Gym Dropdown (Mobile-responsive, overflow-free) ──
        const gymWrap = document.getElementById('switchHomeGymDropdownWrap');
        if (gymWrap) {
            const gymOptions = (window.PROFILE_CONFIG && window.PROFILE_CONFIG.gymOptions) ? window.PROFILE_CONFIG.gymOptions : [];
            if (typeof FitDropdown !== 'undefined') {
                window.switchHomeGymDropdown = new FitDropdown({
                    container: '#switchHomeGymDropdownWrap',
                    name: 'new_gym_id',
                    id: 'new_gym_id',
                    placeholder: 'Choose a gym...',
                    searchable: false,
                    zIndex: 50,
                    items: gymOptions
                });
            } else {
                const fallbackSelect = document.createElement('select');
                fallbackSelect.name = 'new_gym_id';
                fallbackSelect.className = 'settings-select';
                fallbackSelect.required = true;
                fallbackSelect.innerHTML = '<option value="">Choose a gym...</option>' + 
                    gymOptions.map(g => `<option value="${g.id}">${g.label}</option>`).join('');
                gymWrap.appendChild(fallbackSelect);
            }
        }
    });
