/**
 * Memberships & Payments Controller
 * Extracted from memberships.php
 */

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

                        if (!cards.length) return;

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
                        subscribePlan(
                            parseInt(btn.getAttribute('data-plan-id'), 10),
                            btn.getAttribute('data-plan-name') || '',
                            btn.getAttribute('data-plan-price') || '',
                            btn.getAttribute('data-is-current') === '1'
                        );
                    }
                });

                function subscribePlan(planId, planName, planPrice, isCurrent) {
                    const actionWord = isCurrent ? 'Renew' : 'Subscribe to';
                    const btnWord = isCurrent ? 'Confirm & Renew' : 'Confirm Subscription';
                    const safeName = typeof escapeHtml === 'function' ? escapeHtml(planName) : planName;
                    const safePrice = typeof escapeHtml === 'function' ? escapeHtml(planPrice) : planPrice;

                    Swal.fire({
                        title: `<span style="font-size:1.35rem;font-weight:800;letter-spacing:-0.3px;">${actionWord} ${safeName}</span>`,
                        html: `
                        <p style="margin: 0 0 18px 0; font-size: 14px; color: var(--muted); line-height: 1.5;">
                            You are selecting the <strong style="color:var(--ink);">${safeName}</strong> plan for <strong style="color:var(--lime, #22c55e); font-size: 16px;">${safePrice}</strong>.
                        </p>
                        <form id="subscribeForm" method="post" action="index.php?page=memberships" style="text-align: left; display: flex; flex-direction: column; gap: 12px;">
                            <input type="hidden" name="csrf_token" value="${window.MEMBERSHIPS_CONFIG?.csrfToken || ''}">
                            <input type="hidden" name="subscribe_plan_id" value="${planId}">
                            
                            <label style="display:block; font-size:11.5px; font-weight:700; letter-spacing:0.5px; color:var(--muted); text-transform:uppercase;">
                                Select Payment Method
                            </label>
                            
                            <div style="display:flex; flex-direction:column; gap:10px;">
                                <label style="display:flex; align-items:center; gap:12px; padding:12px 14px; border-radius:10px; border:1px solid var(--line); background:rgba(128,128,128,0.05); cursor:pointer;">
                                    <input type="radio" name="payment_method" value="gcash" checked style="accent-color:var(--lime, #22c55e); width:18px; height:18px;">
                                    <div style="flex:1;">
                                        <div style="font-weight:700; font-size:14px; color:var(--ink);">GCash</div>
                                        <div style="font-size:12px; color:var(--muted);">Instant activation via e-wallet</div>
                                    </div>
                                    <span style="font-size:11px; font-weight:700; background:rgba(34,197,94,0.15); color:var(--lime,#22c55e); padding:3px 8px; border-radius:6px;">Instant</span>
                                </label>
                                
                                <label style="display:flex; align-items:center; gap:12px; padding:12px 14px; border-radius:10px; border:1px solid var(--line); background:rgba(128,128,128,0.05); cursor:pointer;">
                                    <input type="radio" name="payment_method" value="cash" style="accent-color:var(--lime, #22c55e); width:18px; height:18px;">
                                    <div style="flex:1;">
                                        <div style="font-weight:700; font-size:14px; color:var(--ink);">Cash / Over-the-Counter</div>
                                        <div style="font-size:12px; color:var(--muted);">Pay at the gym reception desk</div>
                                    </div>
                                    <span style="font-size:11px; font-weight:700; background:rgba(148,163,184,0.15); color:var(--muted); padding:3px 8px; border-radius:6px;">Front Desk</span>
                                </label>
                            </div>
                            
                            <div style="font-size: 12.5px; color: var(--muted); margin-top: 4px; line-height: 1.4; padding: 10px 12px; background: rgba(128,128,128,0.06); border-radius: 8px;">
                                ${isCurrent 
                                    ? 'Your membership validity will be automatically extended from your current expiration date.' 
                                    : 'Your subscription will be recorded and activated upon payment confirmation.'}
                            </div>
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

// --- Section 2: Membership Status Editor ---
            function editStatus(membershipId, currentStatus) {
                const statusOptions = [
                    { id: 'active', label: 'Active', dotClass: 'status-dot-active' },
                    { id: 'pending', label: 'Pending', dotClass: 'status-dot-pending' },
                    { id: 'expired', label: 'Expired', dotClass: 'status-dot-expired' },
                    { id: 'cancelled', label: 'Cancelled', dotClass: 'status-dot-cancelled' }
                ];

                Swal.fire({
                    title: 'Update Status',
                    width: '400px',
                    html: `
                    <form id="editStatusForm" method="post" style="text-align:left;display:flex;flex-direction:column;gap:12px;margin-top:15px;min-height:120px;position:relative;">
                        <input type="hidden" name="csrf_token" value="${window.MEMBERSHIPS_CONFIG?.csrfToken || ''}">
                        <input type="hidden" name="update_status_id" value="${membershipId}">
                        <label style="display:block;color:var(--muted);font-size:13.5px;font-weight:500;">Status</label>
                        <div id="editStatusDropdownWrap"></div>
                    </form>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Save',
                    confirmButtonColor: 'var(--lime-dark)',
                    cancelButtonColor: 'var(--line)',
                    background: 'var(--bg)',
                    color: 'var(--ink)',
                    didOpen: () => {
                        new FitDropdown({
                            container: '#editStatusDropdownWrap',
                            name: 'status',
                            id: 'editStatusInput',
                            value: currentStatus || 'active',
                            zIndex: 90,
                            items: statusOptions
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
                <form id="addMembershipForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 14px; margin-top: 15px; min-height: 285px; position: relative;">
                    <input type="hidden" name="csrf_token" value="${window.MEMBERSHIPS_CONFIG?.csrfToken || ''}">
                    
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

            function recordPayment() {
                const members = Array.isArray(window.paymentMembersData) ? window.paymentMembersData : [];
                const plans = Array.isArray(window.paymentPlansData) ? window.paymentPlansData : [];
                const memberships = Array.isArray(window.paymentMembershipsData) ? window.paymentMembershipsData : [];

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

                Swal.fire({
                    title: 'Record Payment',
                    width: '480px',
                    html: `
                        <form id="recordPaymentForm" method="post" action="index.php?page=memberships&view=payments" style="text-align: left; display: flex; flex-direction: column; gap: 14px; margin-top: 15px; min-height: 380px; position: relative;">
                            <input type="hidden" name="csrf_token" value="${window.MEMBERSHIPS_CONFIG?.csrfToken || ''}">
                            <input type="hidden" name="action" value="record_payment">
                            <input type="hidden" name="user_id" id="modalPaymentUserId" value="">
                            <input type="hidden" name="membership_id" id="modalPaymentMembershipId" value="">
                            <input type="hidden" name="plan_id" id="modalPaymentPlanId" value="">
                            
                            <!-- 1. Member Selector (Hybrid Live Search) -->
                            <div>
                                <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Member *</label>
                                <div id="paymentMemberWrap"></div>
                            </div>

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
                    confirmButtonText: 'Record Payment',
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
                            // 1. Existing memberships for this user
                            const userMemberships = memberships.filter(m => String(m.user_id) === String(userId));
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
                                items.push({
                                    id: 'p_' + p.id,
                                    membershipId: null,
                                    planId: p.id,
                                    label: `${p.name} (New Subscription)`,
                                    subtitle: `${p.duration} days`,
                                    price: p.formatted_price,
                                    rawPrice: p.price,
                                    icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="3"></rect><line x1="3" y1="10" x2="21" y2="10"></line></svg>'
                                });
                            });

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
                            <input type="hidden" name="csrf_token" value="${window.MEMBERSHIPS_CONFIG?.csrfToken || ''}">
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
                                    <label style="display:block; color: var(--muted); font-size: 13px; margin-bottom: 6px; font-weight: 500;">Payment Date *</label>
                                    <input name="payment_date" type="date" class="wb-modal-date-input" value="${window.MEMBERSHIPS_CONFIG?.today || ''}" required>
                                </div>
                                <div style="flex:1;">
                                    <label style="display:block; color: var(--muted); font-size: 13px; margin-bottom: 6px; font-weight: 500;">Receipt Number</label>
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

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        toggleMembershipsFab(false);
    }
});

