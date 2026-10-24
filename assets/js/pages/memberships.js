/**
 * Memberships & Payments Controller
 * Extracted from memberships.php
 */

function getCsrfToken() {
    return window.MEMBERSHIPS_CONFIG?.csrfToken
        || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || document.querySelector('input[name="csrf_token"]')?.value
        || '';
}

// --- Section 1: Member Pricing & View Switcher ---
                function switchMembershipView(viewName) {
                    const plansBtn = document.getElementById('tab-btn-plans');
                    const recordsBtn = document.getElementById('tab-btn-records');
                    const plansPane = document.getElementById('view-pane-plans');
                    const recordsPane = document.getElementById('view-pane-records');

                    if (viewName === 'records') {
                        if (plansBtn) plansBtn.classList.remove('active');
                        if (recordsBtn) recordsBtn.classList.add('active');
                        if (plansPane) plansPane.style.display = 'none';
                        if (recordsPane) recordsPane.style.display = 'block';
                        try {
                            history.replaceState(null, null, '#records');
                        } catch (e) {}
                    } else {
                        if (plansBtn) plansBtn.classList.add('active');
                        if (recordsBtn) recordsBtn.classList.remove('active');
                        if (plansPane) plansPane.style.display = 'block';
                        if (recordsPane) recordsPane.style.display = 'none';
                        try {
                            history.replaceState(null, null, '#plans');
                        } catch (e) {}
                        if (typeof window.centerMemberPopularPlan === 'function') {
                            setTimeout(window.centerMemberPopularPlan, 60);
                        }
                    }
                }

                if (window.location.hash === '#records') {
                    switchMembershipView('records');
                }

                // Mobile Subscription Plan Carousel Controller
                (function() {
                    function initMemberPricingCarousel() {
                        const grid = document.getElementById('memberPricingGrid');
                        if (!grid) return;

                        const cards = Array.from(grid.querySelectorAll('.member-pricing-card'));
                        const dots = Array.from(document.querySelectorAll('.member-carousel-dot'));
                        const prevBtn = document.getElementById('memberCarouselPrev');
                        const nextBtn = document.getElementById('memberCarouselNext');

                        if (cards.length <= 1) return;

                        function isMobile() {
                            return window.innerWidth <= 768;
                        }

                        function getCenterIndex() {
                            const gridRect = grid.getBoundingClientRect();
                            const gridCenter = gridRect.left + gridRect.width / 2;
                            let closestIdx = 0;
                            let minDiff = Infinity;
                            cards.forEach((card, idx) => {
                                const cardRect = card.getBoundingClientRect();
                                const cardCenter = cardRect.left + cardRect.width / 2;
                                const diff = Math.abs(gridCenter - cardCenter);
                                if (diff < minDiff) {
                                    minDiff = diff;
                                    closestIdx = idx;
                                }
                            });
                            return closestIdx;
                        }

                        function scrollToCard(index, smooth) {
                            if (index < 0 || index >= cards.length) return;
                            const card = cards[index];
                            const scrollLeft = card.offsetLeft - (grid.clientWidth - card.offsetWidth) / 2;
                            grid.scrollTo({
                                left: Math.max(0, scrollLeft),
                                behavior: smooth ? 'smooth' : 'auto'
                            });
                            updateDots(index);
                        }

                        function updateDots(activeIdx) {
                            dots.forEach((dot, idx) => {
                                dot.classList.toggle('active', idx === activeIdx);
                            });
                        }

                        function centerPopular(smooth) {
                            if (!isMobile()) return;
                            let targetIdx = cards.findIndex(c => c.classList.contains('popular') || c.getAttribute('data-popular') === '1');
                            if (targetIdx === -1) {
                                targetIdx = Math.floor(cards.length / 2);
                            }
                            scrollToCard(targetIdx, smooth === true);
                        }

                        // Dot clicks
                        dots.forEach((dot, idx) => {
                            dot.addEventListener('click', () => scrollToCard(idx, true));
                        });

                        // Arrow clicks
                        if (prevBtn) {
                            prevBtn.addEventListener('click', () => {
                                const current = getCenterIndex();
                                scrollToCard(Math.max(0, current - 1), true);
                            });
                        }
                        if (nextBtn) {
                            nextBtn.addEventListener('click', () => {
                                const current = getCenterIndex();
                                scrollToCard(Math.min(cards.length - 1, current + 1), true);
                            });
                        }

                        // Tapping a side-peeked card centers it
                        cards.forEach((card, idx) => {
                            card.addEventListener('click', (e) => {
                                if (isMobile() && !e.target.closest('button, a')) {
                                    scrollToCard(idx, true);
                                }
                            });
                        });

                        // Scroll listener for dot sync
                        let scrollTimeout;
                        grid.addEventListener('scroll', () => {
                            if (!isMobile()) return;
                            clearTimeout(scrollTimeout);
                            scrollTimeout = setTimeout(() => {
                                updateDots(getCenterIndex());
                            }, 50);
                        }, { passive: true });

                        // Initial centering
                        requestAnimationFrame(() => {
                            centerPopular(false);
                            setTimeout(() => centerPopular(false), 120);
                        });

                        // Resize listener
                        let resizeTimeout;
                        window.addEventListener('resize', () => {
                            clearTimeout(resizeTimeout);
                            resizeTimeout = setTimeout(() => {
                                if (isMobile()) {
                                    centerPopular(false);
                                }
                            }, 150);
                        });

                        window.centerMemberPopularPlan = centerPopular;
                    }

                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', initMemberPricingCarousel);
                    } else {
                        initMemberPricingCarousel();
                    }
                })();

                document.addEventListener('click', function(e) {
                    const btn = e.target.closest('.btn-subscribe-plan');
                    if (btn) {
                        const planId = parseInt(btn.getAttribute('data-plan-id'), 10);
                        const isCurrent = btn.getAttribute('data-is-current') === '1';
                        const isUpgrade = btn.getAttribute('data-is-upgrade') === '1';

                        // Guardrail: Check if user already has a pending subscription at this facility
                        if (window.MEMBERSHIPS_CONFIG?.hasPending) {
                            const pending = window.MEMBERSHIPS_CONFIG.pendingPlan;
                            const refText = pending?.receipt ? ` (Ref: ${pending.receipt})` : '';
                            const msg = `You already have a pending subscription request${refText} for ${pending?.name || 'a membership plan'}. Please complete or cancel your pending request before subscribing to another plan.`;
                            if (typeof Swal !== 'undefined') {
                                Swal.fire({
                                    icon: 'warning',
                                    title: 'Pending Request Active',
                                    text: msg,
                                    confirmButtonColor: 'var(--lime, #22c55e)'
                                });
                            } else {
                                alert(msg);
                            }
                            return;
                        }

                        // Check if plan is locked by guardrails (e.g. premature renewal or downgrade)
                        if (window.MEMBERSHIPS_CONFIG?.lockedPlans && window.MEMBERSHIPS_CONFIG.lockedPlans[planId]) {
                            const reason = window.MEMBERSHIPS_CONFIG.lockedPlans[planId];
                            if (typeof Swal !== 'undefined') {
                                Swal.fire({
                                    icon: 'info',
                                    title: 'Plan Locked',
                                    text: reason,
                                    confirmButtonColor: 'var(--lime, #22c55e)'
                                });
                            } else {
                                alert(reason);
                            }
                            return;
                        }

                        subscribePlan(
                            planId,
                            btn.getAttribute('data-plan-name') || '',
                            btn.getAttribute('data-plan-price') || '',
                            isCurrent,
                            isUpgrade
                        );
                    }
                });

                function subscribePlan(planId, planName, planPrice, isCurrent, isUpgrade) {
                    const actionWord = isCurrent ? 'Renew' : (isUpgrade ? 'Upgrade to' : 'Subscribe to');
                    const btnWord = isCurrent ? 'Confirm & Renew' : (isUpgrade ? 'Confirm Upgrade' : 'Confirm Subscription');
                    const safeName = typeof escapeHtml === 'function' ? escapeHtml(planName) : planName;
                    const safePrice = typeof escapeHtml === 'function' ? escapeHtml(planPrice) : planPrice;
                    const cur = window.MEMBERSHIPS_CONFIG?.currentPlan;

                    let upgradeBannerHtml = '';
                    if (isUpgrade && cur && cur.daysLeft !== null) {
                        const curPlanSafe = typeof escapeHtml === 'function' ? escapeHtml(cur.name || 'current') : (cur.name || 'current');
                        const daysLeftLabel = cur.daysLeft === 1 ? '1 day' : `${cur.daysLeft} days`;
                        upgradeBannerHtml = `
                        <div style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 9px; padding: 7px 10px; margin-bottom: 8px; display: flex; gap: 8px; align-items: flex-start; text-align: left;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2.2" style="flex-shrink:0; margin-top:2px;">
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                                <line x1="12" y1="9" x2="12" y2="13"/>
                                <line x1="12" y1="17" x2="12.01" y2="17"/>
                            </svg>
                            <div style="font-size: 11.5px; line-height: 1.35; color: var(--ink);">
                                <strong style="color: #f59e0b; display: inline-block; margin-right: 3px;">Upgrade Notice:</strong>
                                Has <strong>${daysLeftLabel} left</strong> on <strong>${curPlanSafe}</strong>. Upgrades activate today; remaining days are superseded.
                            </div>
                        </div>
                        `;
                    }

                    Swal.fire({
                        customClass: { popup: 'membership-subscribe-swal' },
                        title: `<span style="font-size:1.15rem;font-weight:700;letter-spacing:-0.2px;">${actionWord} ${safeName}</span>`,
                        html: `
                        ${upgradeBannerHtml}
                        <div style="margin: 0 0 8px 0; font-size: 12.5px; color: var(--muted);">
                            Selected: <strong style="color:var(--ink);">${safeName}</strong> • <strong style="color:var(--lime, #22c55e); font-size: 14px;">${safePrice}</strong>
                        </div>
                        <form id="subscribeForm" method="post" action="index.php?page=memberships" style="text-align: left; display: flex; flex-direction: column; gap: 8px;">
                            <input type="hidden" name="csrf_token" value="${getCsrfToken()}">
                            <input type="hidden" name="subscribe_plan_id" value="${planId}">
                            
                            <label style="display:block; font-size:10.5px; font-weight:700; letter-spacing:0.4px; color:var(--muted); text-transform:uppercase; margin-bottom: -2px;">
                                Payment Method
                            </label>
                            
                            <div style="display:flex; flex-direction:column; gap:7px;">
                                <label style="display:flex; align-items:center; gap:10px; padding:8px 10px; border-radius:9px; border:1px solid var(--line); background:rgba(128,128,128,0.05); cursor:${window.MEMBERSHIPS_CONFIG?.xenditEnabled ? 'pointer' : 'not-allowed'}; opacity:${window.MEMBERSHIPS_CONFIG?.xenditEnabled ? '1' : '.55'};">
                                    <input type="radio" name="payment_method" value="xendit" ${window.MEMBERSHIPS_CONFIG?.xenditEnabled ? 'checked' : 'disabled'} style="accent-color:var(--lime, #22c55e); width:16px; height:16px; margin:0;">
                                    <div style="flex:1; min-width:0;">
                                        <div style="font-weight:700; font-size:13px; color:var(--ink); line-height:1.2;">Pay online with Xendit</div>
                                        <div style="font-size:11px; color:var(--muted); line-height:1.2; margin-top:1px;">Secure instant checkout</div>
                                    </div>
                                    <span style="font-size:10px; font-weight:700; background:rgba(34,197,94,0.15); color:var(--lime,#22c55e); padding:2px 6px; border-radius:5px;">Xendit</span>
                                </label>
                                
                                <label style="display:flex; align-items:center; gap:10px; padding:8px 10px; border-radius:9px; border:1px solid var(--line); background:rgba(128,128,128,0.05); cursor:pointer;">
                                    <input type="radio" name="payment_method" value="cash" style="accent-color:var(--lime, #22c55e); width:16px; height:16px; margin:0;">
                                    <div style="flex:1; min-width:0;">
                                        <div style="font-weight:700; font-size:13px; color:var(--ink); line-height:1.2;">Cash / Over-the-Counter</div>
                                        <div style="font-size:11px; color:var(--muted); line-height:1.2; margin-top:1px;">Pay at gym reception desk</div>
                                    </div>
                                    <span style="font-size:10px; font-weight:700; background:rgba(148,163,184,0.15); color:var(--muted); padding:2px 6px; border-radius:5px;">Front Desk</span>
                                </label>
                            </div>
                            ${window.MEMBERSHIPS_CONFIG?.xenditEnabled ? '' : '<div style="font-size:11px;color:#f59e0b;line-height:1.3;">Online payment is not enabled for this gym. Please select Cash.</div>'}
                            ${isCurrent ? '<div style="font-size:11px;color:var(--muted);text-align:center;line-height:1.3;margin-top:2px;">Validity extends seamlessly from your current expiration date.</div>' : ''}
                        </form>
                    `,
                        showCancelButton: true,
                        confirmButtonText: btnWord,
                        confirmButtonColor: 'var(--lime-dark, #22c55e)',
                        cancelButtonColor: 'var(--line)',
                        background: 'var(--bg)',
                        color: 'var(--ink)',
                        preConfirm: () => {
                            const form = document.getElementById('subscribeForm');
                            if (form) {
                                form.submit();
                            }
                        }
                    });
                }

                window.confirmCancelMembership = function(membershipId, planName, status) {
                    const isPending = (status || '').toLowerCase() === 'pending';
                    const title = isPending ? 'Cancel Request?' : 'Cancel Subscription?';
                    const text = isPending
                        ? `Are you sure you want to cancel your pending request for "${planName}"? This will allow you to choose and subscribe to another plan.`
                        : `Are you sure you want to cancel your active subscription for "${planName}"?`;
                    const confirmBtnText = isPending ? 'Yes, Cancel Request' : 'Yes, Cancel Plan';

                    const submitCancellation = () => {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'index.php?page=memberships';

                        const csrfInput = document.createElement('input');
                        csrfInput.type = 'hidden';
                        csrfInput.name = 'csrf_token';
                        csrfInput.value = (typeof getCsrfToken === 'function' ? getCsrfToken() : (window.MEMBERSHIPS_CONFIG?.csrfToken || ''));
                        form.appendChild(csrfInput);

                        const actionInput = document.createElement('input');
                        actionInput.type = 'hidden';
                        actionInput.name = 'action';
                        actionInput.value = 'cancel_membership';
                        form.appendChild(actionInput);

                        const idInput = document.createElement('input');
                        idInput.type = 'hidden';
                        idInput.name = 'membership_id';
                        idInput.value = membershipId;
                        form.appendChild(idInput);

                        document.body.appendChild(form);
                        form.submit();
                    };

                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            title: title,
                            text: text,
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonColor: '#ef4444',
                            cancelButtonColor: 'var(--line)',
                            confirmButtonText: confirmBtnText,
                            cancelButtonText: 'Keep Subscription',
                            background: 'var(--bg)',
                            color: 'var(--ink)'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                submitCancellation();
                            }
                        });
                    } else {
                        if (confirm(text)) {
                            submitCancellation();
                        }
                    }
                };

// --- Section 2: Membership Status Editor ---
            function editStatus(membershipId, currentStatus) {
                const normStatus = (currentStatus || '').toLowerCase();
                let statusOptions = [];
                let defaultVal = 'active';

                if (normStatus === 'pending') {
                    // Progressive step: from Pending, gym owner can select Active or Cancelled
                    statusOptions = [
                        { id: 'active', label: 'Active', dotClass: 'status-dot-active' },
                        { id: 'cancelled', label: 'Cancelled', dotClass: 'status-dot-cancelled' }
                    ];
                    defaultVal = 'active';
                } else if (normStatus === 'active') {
                    // Progressive step: from Active, gym owner can progress to Expired
                    statusOptions = [
                        { id: 'expired', label: 'Expired', dotClass: 'status-dot-expired' }
                    ];
                    defaultVal = 'expired';
                } else {
                    // Fallback for expired or cancelled records
                    statusOptions = [
                        { id: 'active', label: 'Active', dotClass: 'status-dot-active' }
                    ];
                    defaultVal = 'active';
                }

                const isPending = (normStatus === 'pending');
                const isActive = (normStatus === 'active');
                let noticeHtml = '';
                if (isPending) {
                    noticeHtml = `
                    <div id="pendingActivationNotice" style="font-size: 11.5px; color: var(--muted); line-height: 1.45; padding: 9px 12px; background: color-mix(in srgb, var(--lime) 8%, transparent); border-radius: 8px; border: 1px solid color-mix(in srgb, var(--lime) 25%, transparent); display: flex; gap: 8px; align-items: flex-start;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5" style="flex-shrink:0; margin-top:1px;"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <div>
                            <strong style="color: var(--lime);">Automatic Date Recalibration:</strong> When set to <strong>Active</strong>, the start date will be recorded as today and the full duration will be granted so the member gets their complete term.
                        </div>
                    </div>
                    `;
                } else if (isActive) {
                    noticeHtml = `
                    <div style="font-size: 11.5px; color: var(--muted); line-height: 1.45; padding: 9px 12px; background: rgba(148,163,184,0.08); border-radius: 8px; border: 1px solid var(--line); display: flex; gap: 8px; align-items: flex-start;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2.2" style="flex-shrink:0; margin-top:1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <div>
                            Progressing this active membership to <strong>Expired</strong> will conclude the member's current active cycle.
                        </div>
                    </div>
                    `;
                }

                Swal.fire({
                    title: 'Update Status',
                    width: '420px',
                    html: `
                    <form id="editStatusForm" method="post" action="index.php?page=memberships" style="text-align:left;display:flex;flex-direction:column;gap:12px;margin-top:15px;min-height:120px;position:relative;">
                        <input type="hidden" name="csrf_token" value="${getCsrfToken()}">
                        <input type="hidden" name="update_status_id" value="${membershipId}">
                        <label style="display:block;color:var(--muted);font-size:13.5px;font-weight:500;">Status</label>
                        <div id="editStatusDropdownWrap"></div>
                        ${noticeHtml}
                    </form>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Save',
                    confirmButtonColor: 'var(--lime)',
                    cancelButtonColor: 'var(--line)',
                    background: 'var(--bg)',
                    color: 'var(--ink)',
                    didOpen: () => {
                        new FitDropdown({
                            container: '#editStatusDropdownWrap',
                            name: 'status',
                            id: 'editStatusInput',
                            value: defaultVal,
                            zIndex: 90,
                            items: statusOptions,
                            onChange: (selected) => {
                                const notice = document.getElementById('pendingActivationNotice');
                                if (notice) {
                                    notice.style.display = (selected && selected.id === 'active' && isPending) ? 'flex' : 'none';
                                }
                            }
                        });
                    },
                    preConfirm: () => {
                        Swal.showLoading();
                        document.getElementById('editStatusForm').submit();
                    }
                });
            }

// --- Section 3: Admin Membership & Payment Modals ---
            window.membershipInitialMembers = window.MEMBERSHIPS_CONFIG?.initialMembers || [];
            window.membershipPlansData = window.MEMBERSHIPS_CONFIG?.plans || [];

            function addMembership() {
                Swal.fire({
                    title: 'Create Membership',
                    width: '460px',
                    html: `
                <form id="addMembershipForm" method="post" action="index.php?page=memberships" style="text-align: left; display: flex; flex-direction: column; gap: 14px; margin-top: 15px; min-height: 285px; position: relative;">
                    <input type="hidden" name="csrf_token" value="${getCsrfToken()}">
                    
                    <!-- Member Selector (Hybrid Live Search) -->
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Member *</label>
                        <div id="memberComboboxWrap"></div>
                    </div>
                    
                    <!-- Plan Selector (Custom Themed Dropdown UI) -->
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Plan *</label>
                        <div id="planComboboxWrap"></div>
                    </div>
                    
                    <!-- Dates & Status in Row -->
                    <div style="display:flex; gap:12px;">
                        <div style="flex:1;">
                            <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Start date *</label>
                            <input name="start_date" type="date" class="wb-modal-date-input" value="${window.MEMBERSHIPS_CONFIG?.today || ''}" required>
                        </div>
                        <div style="flex:1;">
                            <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Status *</label>
                            <div id="statusComboboxWrap"></div>
                        </div>
                    </div>
                </form>
            `,
                    showCancelButton: true,
                    confirmButtonText: 'Create',
                    confirmButtonColor: 'var(--lime-dark)',
                    cancelButtonColor: 'var(--line)',
                    background: 'var(--bg)',
                    color: 'var(--ink)',
                    didOpen: () => {
                        let memberSearchTimer = null;

                        const memberDrop = new FitDropdown({
                            container: '#memberComboboxWrap',
                            name: 'user_id',
                            id: 'membershipUserId',
                            placeholder: 'Select Member...',
                            searchable: true,
                            searchPlaceholder: 'Search members by name or email...',
                            allowClear: true,
                            zIndex: 60,
                            items: window.membershipInitialMembers || [],
                            onSearch: (q, updateBadge, setResults) => {
                                clearTimeout(memberSearchTimer);
                                if (!q.trim()) {
                                    updateBadge('');
                                    setResults(window.membershipInitialMembers || []);
                                    return;
                                }
                                updateBadge('Searching...');
                                memberSearchTimer = setTimeout(() => {
                                    fetch('index.php?page=memberships&action=search_members&q=' + encodeURIComponent(q.trim()))
                                        .then(r => r.json())
                                        .then(data => {
                                            if (data && data.success && Array.isArray(data.members)) {
                                                updateBadge(data.members.length + ' found');
                                                setResults(data.members);
                                            } else {
                                                updateBadge('');
                                            }
                                        })
                                        .catch(() => updateBadge(''));
                                }, 200);
                            }
                        });

                        const planDrop = new FitDropdown({
                            container: '#planComboboxWrap',
                            name: 'plan_id',
                            id: 'membershipPlanId',
                            placeholder: 'Select Plan...',
                            searchable: false,
                            zIndex: 50,
                            items: (window.membershipPlansData || []).map(p => ({
                                id: p.id,
                                label: p.name,
                                price: p.formatted_price || ('₱' + parseFloat(p.price || 0).toFixed(2)),
                                icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="3"></rect><line x1="3" y1="10" x2="21" y2="10"></line></svg>'
                            }))
                        });

                        const statusDrop = new FitDropdown({
                            container: '#statusComboboxWrap',
                            name: 'status',
                            id: 'membershipStatus',
                            value: 'active',
                            searchable: false,
                            zIndex: 40,
                            items: [
                                { id: 'active', label: 'Active', dotClass: 'status-dot-active' },
                                { id: 'pending', label: 'Pending', dotClass: 'status-dot-pending' },
                                { id: 'expired', label: 'Expired', dotClass: 'status-dot-expired' },
                                { id: 'cancelled', label: 'Cancelled', dotClass: 'status-dot-cancelled' }
                            ]
                        });

                        window._currentMembershipDrops = { memberDrop, planDrop, statusDrop };
                    },
                    preConfirm: () => {
                        const form = document.getElementById('addMembershipForm');
                        const drops = window._currentMembershipDrops;
                        const memberId = drops ? drops.memberDrop.getValue() : '';
                        const planId = drops ? drops.planDrop.getValue() : '';
                        const startDate = form.start_date.value;

                        let valid = true;
                        if (!memberId) {
                            if (drops) drops.memberDrop.setError(true);
                            valid = false;
                        }
                        if (!planId) {
                            if (drops) drops.planDrop.setError(true);
                            valid = false;
                        }
                        if (!valid || !startDate) {
                            Swal.showValidationMessage('Please fill all required fields');
                            return false;
                        }
                        form.submit();
                    }
                });
            }
            window.addMembership = addMembership;

            function switchAdminView(view) {
                const tabMemberships = document.getElementById('admin-tab-memberships');
                const tabPayments = document.getElementById('admin-tab-payments');
                const paneMemberships = document.getElementById('admin-pane-memberships');
                const panePayments = document.getElementById('admin-pane-payments');
                const titleEl = document.getElementById('admin-page-title');
                const descEl = document.getElementById('admin-page-desc');

                if (view === 'payments') {
                    if (tabMemberships) tabMemberships.classList.remove('active');
                    if (tabPayments) tabPayments.classList.add('active');
                    if (paneMemberships) paneMemberships.style.display = 'none';
                    if (panePayments) panePayments.style.display = 'block';
                    if (titleEl) titleEl.textContent = 'Payment History';
                    if (descEl) descEl.textContent = 'Record and track membership payment transactions.';
                    try {
                        history.replaceState(null, '', 'index.php?page=memberships&view=payments');
                    } catch (e) {}
                } else {
                    if (tabMemberships) tabMemberships.classList.add('active');
                    if (tabPayments) tabPayments.classList.remove('active');
                    if (paneMemberships) paneMemberships.style.display = 'block';
                    if (panePayments) panePayments.style.display = 'none';
                    if (titleEl) titleEl.textContent = 'Memberships';
                    if (descEl) descEl.textContent = 'Manage member subscription plans and their validity periods.';
                    try {
                        history.replaceState(null, '', 'index.php?page=memberships&view=memberships');
                    } catch (e) {}
                }
            }
            window.switchAdminView = switchAdminView;

            window.paymentMembersData = window.MEMBERSHIPS_CONFIG?.initialMembers || [];
            window.paymentPlansData = window.MEMBERSHIPS_CONFIG?.plans || [];
            window.paymentMembershipsData = window.MEMBERSHIPS_CONFIG?.memberships || [];

            function recordPayment(preselectUserId = null, preselectPlanId = null, preselectMemberName = '', preselectPlanName = '', preselectMemberEmail = '') {
                const members = Array.isArray(window.paymentMembersData) ? window.paymentMembersData : [];
                const plans = Array.isArray(window.paymentPlansData) ? window.paymentPlansData : [];
                const memberships = Array.isArray(window.paymentMembershipsData) ? window.paymentMembershipsData : [];
                const isRenewal = Boolean(preselectUserId);

                const methodOptions = [
                    { id: 'cash', label: 'Cash', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>' },
                    { id: 'gcash', label: 'GCash', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>' },
                    { id: 'card', label: 'Card', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>' },
                    { id: 'bank_transfer', label: 'Bank Transfer', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M3 10h18M5 10v11M19 10v11M9 10v11M15 10v11M12 2L2 7h20L12 2z"/></svg>' },
                    { id: 'online', label: 'Online', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>' },
                    { id: 'other', label: 'Other', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>' }
                ];

                const statusOptions = [
                    { id: 'paid', label: 'Paid', dotClass: 'status-dot-active' },
                    { id: 'pending', label: 'Pending', dotClass: 'status-dot-pending' },
                    { id: 'overdue', label: 'Overdue', dotClass: 'status-dot-cancelled' },
                    { id: 'refunded', label: 'Refunded', dotClass: 'status-dot-expired' }
                ];

                let memberDrop = null;
                let itemDrop = null;
                let methodDrop = null;
                let statusDrop = null;
                let searchTimer = null;

                const renewalNoticeHtml = isRenewal ? `
                    <div style="font-size: 11.5px; color: var(--muted); line-height: 1.45; padding: 9px 12px; background: color-mix(in srgb, var(--lime) 8%, transparent); border-radius: 8px; border: 1px solid color-mix(in srgb, var(--lime) 25%, transparent); display: flex; gap: 8px; align-items: flex-start;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5" style="flex-shrink:0; margin-top:1px;"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                        <div>
                            <strong style="color: var(--lime);">Renewing Plan:</strong> Recording this payment will start a fresh subscription cycle for <strong>${preselectMemberName || 'the member'}</strong> starting today.
                        </div>
                    </div>
                ` : '';

                const memberInitials = (preselectMemberName || 'M').split(' ').map(s=>s[0]).join('').slice(0,2).toUpperCase();

                Swal.fire({
                    title: isRenewal ? 'Renew Membership' : 'Record Payment',
                    width: '480px',
                    html: `
                        <form id="recordPaymentForm" method="post" action="index.php?page=memberships&view=payments" style="text-align: left; display: flex; flex-direction: column; gap: 14px; margin-top: 15px; min-height: 380px; position: relative;">
                            <input type="hidden" name="csrf_token" value="${getCsrfToken()}">
                            <input type="hidden" name="action" value="record_payment">
                            <input type="hidden" name="user_id" id="modalPaymentUserId" value="${preselectUserId || ''}">
                            <input type="hidden" name="membership_id" id="modalPaymentMembershipId" value="">
                            <input type="hidden" name="plan_id" id="modalPaymentPlanId" value="${preselectPlanId || ''}">
                            
                            ${renewalNoticeHtml}

                            <!-- 1. Member Selector / Display -->
                            ${isRenewal ? `
                                <div>
                                    <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Member</label>
                                    <div style="display:flex; align-items:center; gap:10px; padding:9px 12px; background: rgba(255,255,255,0.03); border: 1px solid var(--line); border-radius: 9px;">
                                        <div style="width:34px; height:34px; border-radius:50%; background:rgba(34,197,94,0.15); color:var(--lime); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:12.5px; flex-shrink:0;">
                                            ${memberInitials}
                                        </div>
                                        <div style="min-width:0; flex:1;">
                                            <div style="font-weight:600; color:var(--ink); font-size:13.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${preselectMemberName || 'Member'}</div>
                                            ${preselectMemberEmail ? `<div style="font-size:12px; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${preselectMemberEmail}</div>` : ''}
                                        </div>
                                        <span style="font-size:10.5px; padding:2px 7px; border-radius:6px; background:rgba(239,68,68,0.12); color:#ef4444; border:1px solid rgba(239,68,68,0.25); font-weight:700; text-transform:uppercase; letter-spacing:0.3px; flex-shrink:0;">Expired</span>
                                    </div>
                                </div>
                            ` : `
                                <div>
                                    <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Member *</label>
                                    <div id="paymentMemberWrap"></div>
                                </div>
                            `}

                            <!-- 2. Payment For / Plan Selector -->
                            <div>
                                <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Payment For / Plan *</label>
                                <div id="paymentItemWrap"></div>
                            </div>
                            
                            <!-- 3. Amount & Payment Date in Row -->
                            <div style="display:flex; gap:12px;">
                                <div style="flex:1;">
                                    <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Amount *</label>
                                    <input id="modalPaymentAmount" name="amount" type="number" step="0.01" class="wb-modal-input" placeholder="0.00" required>
                                </div>
                                <div style="flex:1;">
                                    <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Payment date *</label>
                                    <input name="payment_date" type="date" class="wb-modal-date-input" value="${window.MEMBERSHIPS_CONFIG?.today || ''}" required>
                                </div>
                            </div>
                            
                            <!-- 4. Method & Status in Row -->
                            <div style="display:flex; gap:12px;">
                                <div style="flex:1;">
                                    <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Method *</label>
                                    <div id="paymentMethodWrap"></div>
                                </div>

                                <div style="flex:1;">
                                    <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Status *</label>
                                    <div id="paymentStatusWrap"></div>
                                </div>
                            </div>
                            
                            <!-- 5. Optional Receipt Reference -->
                            <div>
                                <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Receipt Number (Optional)</label>
                                <input name="receipt_number" class="wb-modal-input" placeholder="Auto-generated if left blank">
                            </div>
                        </form>
                    `,
                    showCancelButton: true,
                    confirmButtonText: isRenewal ? 'Confirm & Renew' : 'Record Payment',
                    confirmButtonColor: 'var(--lime-dark)',
                    cancelButtonColor: 'var(--line)',
                    background: 'var(--bg)',
                    color: 'var(--ink)',
                    didOpen: () => {
                        const userIdInput = document.getElementById('modalPaymentUserId');
                        const membershipIdInput = document.getElementById('modalPaymentMembershipId');
                        const planIdInput = document.getElementById('modalPaymentPlanId');
                        const amountInput = document.getElementById('modalPaymentAmount');

                        function buildItemOptions(userId) {
                            if (!userId) return [];
                            const items = [];
                            // 1. Existing memberships for this user (only pending and active)
                            const userMemberships = memberships.filter(m => String(m.user_id) === String(userId) && (m.status === 'pending' || m.status === 'active'));
                            // Sort so pending memberships appear at the very top
                            userMemberships.sort((a, b) => {
                                if (a.status === 'pending' && b.status !== 'pending') return -1;
                                if (a.status !== 'pending' && b.status === 'pending') return 1;
                                return 0;
                            });

                            userMemberships.forEach(m => {
                                const isPending = (m.status === 'pending');
                                items.push({
                                    id: 'm_' + m.id,
                                    membershipId: m.id,
                                    planId: m.plan_id || null,
                                    label: isPending ? `⚡ ${m.plan_name} (Pending Request - Awaiting Payment)` : `${m.plan_name} (Existing Membership)`,
                                    subtitle: isPending ? `Status: PENDING • Action: Confirm & Activate` : `Status: ${m.status}${m.end_date ? ' • Exp: ' + m.end_date : ''}`,
                                    price: m.formatted_price,
                                    rawPrice: m.price,
                                    icon: isPending 
                                        ? '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>'
                                        : '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><polyline points="17 11 19 13 23 9"></polyline></svg>'
                                });
                            });

                            // 2. All available plans for this gym
                            plans.forEach(p => {
                                const isTargetRenewal = (isRenewal && Number(p.id) === Number(preselectPlanId));
                                items.push({
                                    id: 'p_' + p.id,
                                    membershipId: null,
                                    planId: p.id,
                                    label: isTargetRenewal ? `${p.name} (Renew)` : p.name,
                                    subtitle: isTargetRenewal ? `Renew • ${p.duration} days` : `${p.duration} days`,
                                    price: p.formatted_price,
                                    rawPrice: p.price,
                                    icon: isTargetRenewal ? '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>' : '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="3"></rect><line x1="3" y1="10" x2="21" y2="10"></line></svg>'
                                });
                            });

                            // Sort target renewal plan to very top if renewing
                            if (isRenewal && preselectPlanId) {
                                items.sort((a, b) => {
                                    if (a.id === 'p_' + preselectPlanId) return -1;
                                    if (b.id === 'p_' + preselectPlanId) return 1;
                                    return 0;
                                });
                            }

                            return items;
                        }

                        itemDrop = new FitDropdown({
                            container: '#paymentItemWrap',
                            placeholder: 'Select a member first...',
                            zIndex: 60,
                            items: [],
                            onChange: (selectedItem) => {
                                if (selectedItem) {
                                    membershipIdInput.value = selectedItem.membershipId || '';
                                    planIdInput.value = selectedItem.planId || '';
                                    if (selectedItem.rawPrice !== undefined) {
                                        amountInput.value = Number(selectedItem.rawPrice).toFixed(2);
                                    }
                                } else {
                                    membershipIdInput.value = '';
                                    planIdInput.value = '';
                                }
                            }
                        });

                        if (!isRenewal) {
                            memberDrop = new FitDropdown({
                                container: '#paymentMemberWrap',
                                placeholder: 'Search member by name or email...',
                                searchable: true,
                                searchPlaceholder: 'Search members...',
                                allowClear: true,
                                zIndex: 70,
                                items: members,
                                onSearch: (q, updateBadge, setResults) => {
                                    clearTimeout(searchTimer);
                                    if (!q.trim()) {
                                        updateBadge('');
                                        setResults(members);
                                        return;
                                    }
                                    updateBadge('Searching...');
                                    searchTimer = setTimeout(() => {
                                        fetch('index.php?page=memberships&action=search_members&q=' + encodeURIComponent(q.trim()))
                                            .then(r => r.json())
                                            .then(data => {
                                                if (data && data.results && Array.isArray(data.results)) {
                                                    updateBadge(data.results.length + ' found');
                                                    setResults(data.results);
                                                } else {
                                                    updateBadge('');
                                                }
                                            })
                                            .catch(() => updateBadge(''));
                                    }, 200);
                                },
                                onChange: (selectedMember) => {
                                    if (selectedMember) {
                                        userIdInput.value = selectedMember.id;
                                        const newItems = buildItemOptions(selectedMember.id);
                                        itemDrop.setItems(newItems);
                                        if (newItems.length > 0) {
                                            itemDrop.select(newItems[0].id, true);
                                        }
                                    } else {
                                        userIdInput.value = '';
                                        membershipIdInput.value = '';
                                        planIdInput.value = '';
                                        itemDrop.setItems([]);
                                        itemDrop.select(null);
                                        amountInput.value = '';
                                    }
                                }
                            });
                        }

                        methodDrop = new FitDropdown({
                            container: '#paymentMethodWrap',
                            name: 'payment_method',
                            value: 'cash',
                            zIndex: 50,
                            items: methodOptions
                        });

                        statusDrop = new FitDropdown({
                            container: '#paymentStatusWrap',
                            name: 'status',
                            value: 'paid',
                            zIndex: 40,
                            items: statusOptions
                        });

                        if (isRenewal && preselectUserId) {
                            userIdInput.value = preselectUserId;
                            const newItems = buildItemOptions(preselectUserId);
                            itemDrop.setItems(newItems);
                            if (preselectPlanId) {
                                const targetPlanItemId = 'p_' + preselectPlanId;
                                if (newItems.some(it => it.id === targetPlanItemId)) {
                                    itemDrop.select(targetPlanItemId, true);
                                } else if (newItems.length > 0) {
                                    itemDrop.select(newItems[0].id, true);
                                }
                            } else if (newItems.length > 0) {
                                itemDrop.select(newItems[0].id, true);
                            }
                        }
                    },
                    preConfirm: () => {
                        const form = document.getElementById('recordPaymentForm');
                        const userId = document.getElementById('modalPaymentUserId').value;
                        const membershipId = document.getElementById('modalPaymentMembershipId').value;
                        const planId = document.getElementById('modalPaymentPlanId').value;
                        const amount = form.amount.value;
                        const paymentDate = form.payment_date.value;

                        let valid = true;
                        if (!userId) {
                            if (memberDrop) memberDrop.setError(true);
                            valid = false;
                        }
                        if (!membershipId && !planId) {
                            if (itemDrop) itemDrop.setError(true);
                            valid = false;
                        }
                        if (!valid || !amount || !paymentDate) {
                            Swal.showValidationMessage('Please fill all required fields');
                            return false;
                        }
                        form.submit();
                    }
                });
            }
            window.recordPayment = recordPayment;

            function renewMembership(userId, planId, memberName, planName, memberEmail = '') {
                recordPayment(userId, planId, memberName, planName, memberEmail);
            }
            window.renewMembership = renewMembership;

            function confirmPayment(paymentId, memberName, planName, amount, currentMethod, receiptNumber) {
                const formattedAmount = '₱' + Number(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                const methodOptions = [
                    { id: 'cash', label: 'Cash', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>' },
                    { id: 'gcash', label: 'GCash', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>' },
                    { id: 'card', label: 'Card', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>' },
                    { id: 'bank_transfer', label: 'Bank Transfer', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M3 10h18M5 10v11M19 10v11M9 10v11M15 10v11M12 2L2 7h20L12 2z"/></svg>' },
                    { id: 'online', label: 'Online', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>' },
                    { id: 'other', label: 'Other', icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>' }
                ];

                const safeMember = typeof escapeHtml === 'function' ? escapeHtml(memberName) : memberName;
                const safePlan = typeof escapeHtml === 'function' ? escapeHtml(planName) : planName;
                const safeReceipt = typeof escapeHtml === 'function' ? escapeHtml(receiptNumber || '') : (receiptNumber || '');

                Swal.fire({
                    title: 'Confirm Payment',
                    width: '450px',
                    html: `
                        <form id="confirmPaymentForm" method="post" action="index.php?page=memberships&view=payments" style="text-align: left; display: flex; flex-direction: column; gap: 14px; margin-top: 15px; position: relative;">
                            <input type="hidden" name="csrf_token" value="${getCsrfToken()}">
                            <input type="hidden" name="action" value="confirm_payment">
                            <input type="hidden" name="payment_id" value="${paymentId}">

                            <div style="background: rgba(128,128,128,0.07); border: 1px solid var(--line); border-radius: 10px; padding: 14px;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                    <span style="color: var(--muted); font-size: 13px;">Member:</span>
                                    <strong style="color: var(--ink); font-size: 13.5px;">${safeMember}</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                    <span style="color: var(--muted); font-size: 13px;">Plan:</span>
                                    <strong style="color: var(--ink); font-size: 13.5px;">${safePlan}</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: baseline; border-top: 1px dashed var(--line); padding-top: 8px; margin-top: 4px;">
                                    <span style="color: var(--muted); font-size: 13px; font-weight: 600;">Amount Due:</span>
                                    <strong style="color: var(--lime); font-size: 19px;">${formattedAmount}</strong>
                                </div>
                            </div>

                            <div>
                                <label style="display:block; color: var(--muted); font-size: 13px; margin-bottom: 6px; font-weight: 500;">Payment Method *</label>
                                <div id="confirmMethodWrap"></div>
                            </div>

                            <div style="display:flex; gap:12px;">
                                <div style="flex:1;">
                                    <label style="display:block; color: var(--muted); font-size: 13px; margin-bottom: 6px; font-weight: 500; white-space: nowrap;">Payment Date *</label>
                                    <input name="payment_date" type="date" class="wb-modal-date-input" value="${window.MEMBERSHIPS_CONFIG?.today || ''}" required>
                                </div>
                                <div style="flex:1;">
                                    <label style="display:block; color: var(--muted); font-size: 13px; margin-bottom: 6px; font-weight: 500; white-space: nowrap;">Receipt No.</label>
                                    <input name="receipt_number" class="wb-modal-input" value="${safeReceipt}" placeholder="Auto if blank">
                                </div>
                            </div>

                            <div style="font-size: 12px; color: var(--muted); line-height: 1.4; padding: 10px 12px; background: rgba(34,197,94,0.08); border-radius: 8px; border: 1px solid rgba(34,197,94,0.25);">
                                <span style="color: var(--lime); font-weight: 700;">✓ Instant Activation:</span> Confirming will mark this payment as <strong>PAID</strong> and immediately activate the member's subscription without creating any duplicate records.
                            </div>
                        </form>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Confirm & Activate',
                    confirmButtonColor: 'var(--lime-dark)',
                    cancelButtonColor: 'var(--line)',
                    background: 'var(--bg)',
                    color: 'var(--ink)',
                    didOpen: () => {
                        new FitDropdown({
                            container: '#confirmMethodWrap',
                            name: 'payment_method',
                            value: currentMethod || 'cash',
                            zIndex: 60,
                            items: methodOptions
                        });
                    },
                    preConfirm: () => {
                        const form = document.getElementById('confirmPaymentForm');
                        if (form) {
                            form.submit();
                        }
                    }
                });
            }
            window.confirmPayment = confirmPayment;

// Toggle Mobile Floating Action Button (FAB) Speed Dial
function toggleMembershipsFab(forceState) {
    const container = document.getElementById('membershipsFabContainer');
    const backdrop = document.getElementById('membershipsFabBackdrop');
    if (!container || !backdrop) return;

    const isActive = (typeof forceState === 'boolean') ? forceState : !container.classList.contains('active');
    if (isActive) {
        container.classList.add('active');
        backdrop.classList.add('active');
        document.body.style.overflow = 'hidden';
    } else {
        container.classList.remove('active');
        backdrop.classList.remove('active');
        document.body.style.overflow = '';
    }
}
window.toggleMembershipsFab = toggleMembershipsFab;

function setMembershipStatusFilter(status) {
    const input = document.getElementById('membershipStatusFilterInput');
    const form = document.getElementById('membershipFilterForm');
    if (input && form) {
        input.value = status;
        form.submit();
    }
}
window.setMembershipStatusFilter = setMembershipStatusFilter;

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        toggleMembershipsFab(false);
    }
});


