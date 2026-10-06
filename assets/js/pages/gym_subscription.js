const subscriptionPlansData = window.subscriptionPlansData || {};
        let activeCycle = 'monthly';

        function setBillingCycle(cycle) {
            activeCycle = (cycle === 'yearly') ? 'yearly' : 'monthly';
            const monthlyBtn = document.getElementById('btn-cycle-monthly');
            const yearlyBtn = document.getElementById('btn-cycle-yearly');
            if (monthlyBtn && yearlyBtn) {
                monthlyBtn.classList.toggle('active', activeCycle === 'monthly');
                yearlyBtn.classList.toggle('active', activeCycle === 'yearly');
            }

            for (const [key, plan] of Object.entries(subscriptionPlansData)) {
                const priceEl = document.getElementById('price-' + key);
                const periodEl = document.getElementById('period-' + key);
                const savingsEl = document.getElementById('savings-' + key);
                if (priceEl && periodEl) {
                    if (activeCycle === 'yearly') {
                        priceEl.textContent = plan.annual_price_label || ('₱' + Math.round(plan.annual_price).toLocaleString());
                        periodEl.textContent = '/yr';
                        if (savingsEl) savingsEl.style.display = 'block';
                    } else {
                        priceEl.textContent = plan.price_label;
                        periodEl.textContent = '/mo';
                        if (savingsEl) savingsEl.style.display = 'none';
                    }
                }
            }
        }

        function togglePlanFeatures(key, btn) {
            const el = document.getElementById('extra-features-' + key);
            if (!el) return;
            const isHidden = el.style.display === 'none' || el.style.display === '';
            const plan = subscriptionPlansData[key];
            const extraCount = plan && plan.features ? Math.max(0, plan.features.length - 5) : '';

            if (isHidden) {
                el.style.display = 'block';
                btn.classList.add('is-expanded');
                const textEl = btn.querySelector('.toggle-text');
                if (textEl) textEl.textContent = 'Show fewer features';
            } else {
                el.style.display = 'none';
                btn.classList.remove('is-expanded');
                const textEl = btn.querySelector('.toggle-text');
                if (textEl) textEl.textContent = '+ ' + extraCount + ' more features';
            }
        }

        function openPaymentModal(key, name) {
            const plan = subscriptionPlansData[key];
            if (!plan) return;
            const price = (activeCycle === 'yearly') 
                ? (plan.annual_price_label || ('₱' + Math.round(plan.annual_price).toLocaleString())) 
                : plan.price_label;
            const periodText = (activeCycle === 'yearly') ? 'per year (Annual)' : 'per month (Monthly)';

            document.getElementById('form-plan-key').value = key;
            document.getElementById('form-billing-cycle').value = activeCycle;
            document.getElementById('modal-plan-title').textContent = 'Subscribe to ' + name;
            document.getElementById('modal-plan-name').textContent = name;
            document.getElementById('modal-plan-price').textContent = price;
            const periodEl = document.getElementById('modal-plan-period');
            if (periodEl) periodEl.textContent = periodText;
            const modal = document.getElementById('payment-modal');
            if (modal) modal.style.display = 'flex';
        }

        function closePaymentModal() {
            document.getElementById('payment-modal').style.display = 'none';
        }

        function openPlanDistributionModal() {
            const modal = document.getElementById('planDistributionModalBackdrop');
            if (modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
        }

        function closePlanDistributionModal() {
            const modal = document.getElementById('planDistributionModalBackdrop');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
        }

        function jumpToDistTier(tierIndex, btn) {
            const wrap = document.getElementById('distTableWrap');
            if (!wrap) return;

            document.querySelectorAll('.dist-mobile-tab-btn').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');

            if (tierIndex === 0) {
                wrap.scrollTo({ left: 0, behavior: 'smooth' });
                return;
            }

            const headerCells = wrap.querySelectorAll('.distribution-table thead th');
            if (headerCells && headerCells[tierIndex]) {
                const targetTh = headerCells[tierIndex];
                const capColWidth = headerCells[0].offsetWidth || 140;
                const scrollTarget = targetTh.offsetLeft - capColWidth;
                wrap.scrollTo({ left: Math.max(0, scrollTarget), behavior: 'smooth' });
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const wrap = document.getElementById('distTableWrap');
            if (!wrap) return;

            let isScrolling = false;
            wrap.addEventListener('scroll', function() {
                if (window.innerWidth > 768 || isScrolling) return;
                const sl = wrap.scrollLeft;
                const tabs = document.querySelectorAll('.dist-mobile-tab-btn');
                if (!tabs || tabs.length < 4) return;

                if (sl < 40) {
                    tabs.forEach((t, i) => t.classList.toggle('active', i === 0));
                } else if (sl < 160) {
                    tabs.forEach((t, i) => t.classList.toggle('active', i === 1));
                } else if (sl < 280) {
                    tabs.forEach((t, i) => t.classList.toggle('active', i === 2));
                } else {
                    tabs.forEach((t, i) => t.classList.toggle('active', i === 3));
                }
            }, { passive: true });
        });

        function handleDistributionBackdropClick(event) {
            if (event.target === document.getElementById('planDistributionModalBackdrop')) {
                closePlanDistributionModal();
            }
        }

        window.onclick = function(event) {
            const paymentModal = document.getElementById('payment-modal');
            const distModal = document.getElementById('planDistributionModalBackdrop');
            if (event.target === paymentModal) {
                closePaymentModal();
            }
            if (event.target === distModal) {
                closePlanDistributionModal();
            }
        };
