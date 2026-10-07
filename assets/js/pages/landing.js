/**
 * FitTrack Landing Page Controller
 * Extracted from pages/landing.php
 */

const FT_LANDING_CONFIG = window.LANDING_CONFIG || {};
const landingPlansData = FT_LANDING_CONFIG.plansData || {};

            // 1. Mousemove 3D Card Tilt Effect (Only on devices that support hover)
            const tiltCard = document.getElementById('heroTiltCard');
            if (tiltCard && tiltCard.parentElement && window.matchMedia('(hover: hover)').matches) {
                const container = tiltCard.parentElement;
                container.addEventListener('mousemove', (e) => {
                    const rect = container.getBoundingClientRect();
                    const x = e.clientX - rect.left - rect.width / 2;
                    const y = e.clientY - rect.top - rect.height / 2;
                    const rotateX = (-y / rect.height) * 14;
                    const rotateY = (x / rect.width) * 14;
                    tiltCard.style.transform = `rotateX(${rotateX}deg) rotateY(${rotateY}deg)`;
                });
                container.addEventListener('mouseleave', () => {
                    tiltCard.style.transform = `rotateX(0deg) rotateY(0deg)`;
                });
            }

            // 2. Sticky Navigation Blur
            const nav = document.getElementById('mainNav');
            window.addEventListener('scroll', () => {
                if (window.scrollY > 40) {
                    nav.classList.add('scrolled');
                } else {
                    nav.classList.remove('scrolled');
                }
            }, { passive: true });

            // Highlight the landing section currently nearest the top of the viewport.
            const sectionNavLinks = Array.from(document.querySelectorAll(
                '.nav-menu .nav-link[href^="#"], .mobile-drawer .mobile-drawer-link[href^="#"]'
            ));
            const sectionNavTargets = Array.from(new Set(sectionNavLinks
                .map(link => document.getElementById(link.getAttribute('href').slice(1)))
                .filter(Boolean)))
                .sort((a, b) => a.getBoundingClientRect().top - b.getBoundingClientRect().top);

            function updateActiveSectionLink() {
                if (!sectionNavTargets.length) return;
                const activationLine = Math.min(180, window.innerHeight * 0.3);
                let activeSection = null;

                sectionNavTargets.forEach(section => {
                    if (section.getBoundingClientRect().top <= activationLine) activeSection = section;
                });

                if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2) {
                    activeSection = sectionNavTargets[sectionNavTargets.length - 1];
                }

                sectionNavLinks.forEach(link => {
                    const isActive = activeSection && link.getAttribute('href') === `#${activeSection.id}`;
                    link.classList.toggle('active', Boolean(isActive));
                    if (isActive) link.setAttribute('aria-current', 'location');
                    else link.removeAttribute('aria-current');
                });
            }

            window.addEventListener('scroll', updateActiveSectionLink, { passive: true });
            window.addEventListener('resize', updateActiveSectionLink, { passive: true });
            updateActiveSectionLink();

            // 3. Mobile Menu Toggle & Body Scroll Lock
            const mobileBtn = document.getElementById('mobileMenuBtn');
            const mobileDrawer = document.getElementById('mobileDrawer');
            if (mobileBtn && mobileDrawer) {
                mobileBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isOpen = mobileDrawer.classList.toggle('open');
                    document.body.style.overflow = isOpen ? 'hidden' : '';
                });
            }
            function closeMobileMenu() {
                if (mobileDrawer) {
                    mobileDrawer.classList.remove('open');
                    document.body.style.overflow = '';
                }
            }

            // Close mobile menu on outside click or escape key
            document.addEventListener('click', (e) => {
                if (mobileDrawer && mobileDrawer.classList.contains('open')) {
                    if (!mobileDrawer.contains(e.target) && !mobileBtn.contains(e.target)) {
                        closeMobileMenu();
                    }
                }
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    closeMobileMenu();
                    closeDemoModal();
                }
            });

            // 3.5 Hero Laptop Mockup Carousel JS
            let heroLaptopCurrentIndex = 0;
            const heroLaptopSlides = document.querySelectorAll('.hero-laptop-slide');
            const heroLaptopTotalSlides = heroLaptopSlides.length;
            const heroLaptopDots = document.querySelectorAll('#heroLaptopDots .hero-dot');
            const heroLaptopUrl = document.getElementById('heroLaptopUrl');
            const heroFeatureOverlayText = document.querySelector('#heroFeatureOverlay .hflo-text');
            let heroLaptopAutoTimer = null;

            function goToHeroLaptopSlide(index) {
                if (index < 0) index = heroLaptopTotalSlides - 1;
                if (index >= heroLaptopTotalSlides) index = 0;

                heroLaptopSlides.forEach((slide, i) => {
                    slide.classList.remove('active');
                    if (i === index) {
                        slide.classList.add('active');
                    }
                });

                heroLaptopDots.forEach((dot, i) => {
                    dot.classList.toggle('active', i === index);
                });

                const activeSlide = heroLaptopSlides[index];
                if (activeSlide && heroLaptopUrl) {
                    heroLaptopUrl.textContent = activeSlide.dataset.url || 'fittrack.app/admin/dashboard';
                }
                if (activeSlide && heroFeatureOverlayText) {
                    heroFeatureOverlayText.textContent = activeSlide.dataset.label || 'Gym Operations Dashboard';
                }

                heroLaptopCurrentIndex = index;
            }

            function changeHeroLaptopSlide(direction) {
                goToHeroLaptopSlide(heroLaptopCurrentIndex + direction);
                resetHeroLaptopAutoSlide();
            }

            function startHeroLaptopAutoSlide() {
                heroLaptopAutoTimer = setInterval(() => {
                    goToHeroLaptopSlide(heroLaptopCurrentIndex + 1);
                }, 4500);
            }

            function resetHeroLaptopAutoSlide() {
                clearInterval(heroLaptopAutoTimer);
                startHeroLaptopAutoSlide();
            }

            const heroLaptopContainer = document.getElementById('heroLaptopContainer');
            if (heroLaptopContainer) {
                heroLaptopContainer.addEventListener('mouseenter', () => {
                    clearInterval(heroLaptopAutoTimer);
                });
                heroLaptopContainer.addEventListener('mouseleave', () => {
                    startHeroLaptopAutoSlide();
                });

                // Touch swipe support for mobile
                let touchStartX = 0;
                let touchEndX = 0;
                heroLaptopContainer.addEventListener('touchstart', (e) => {
                    touchStartX = e.changedTouches[0].screenX;
                }, { passive: true });
                heroLaptopContainer.addEventListener('touchend', (e) => {
                    touchEndX = e.changedTouches[0].screenX;
                    if (touchStartX - touchEndX > 40) {
                        changeHeroLaptopSlide(1);
                    } else if (touchEndX - touchStartX > 40) {
                        changeHeroLaptopSlide(-1);
                    }
                }, { passive: true });
            }

            if (heroLaptopTotalSlides > 0) {
                startHeroLaptopAutoSlide();
            }

            // 4. Laptop Mockup Carousel (Section 08)
            let laptopCurrentIndex = 0;
            const laptopSlides = document.querySelectorAll('.laptop-slide');
            const laptopTotalSlides = laptopSlides.length;
            const laptopDots = document.querySelectorAll('.laptop-dot');
            const laptopLabelBtns = document.querySelectorAll('.lsl-btn');
            const laptopAddressBar = document.querySelector('#laptopAddressBar span');
            const laptopFeatureLabel = document.querySelector('#laptopFeatureLabel .lfl-text');
            let laptopAutoTimer = null;

            function goToLaptopSlide(index) {
                if (index < 0) index = laptopTotalSlides - 1;
                if (index >= laptopTotalSlides) index = 0;

                laptopSlides.forEach((slide, i) => {
                    slide.classList.remove('active');
                    if (i === index) {
                        slide.classList.add('active');
                    }
                });

                laptopDots.forEach((dot, i) => {
                    dot.classList.toggle('active', i === index);
                });

                laptopLabelBtns.forEach((btn, i) => {
                    btn.classList.toggle('active', i === index);
                });

                const activeSlide = laptopSlides[index];
                if (activeSlide && laptopAddressBar) {
                    laptopAddressBar.textContent = activeSlide.dataset.slideUrl || '';
                }
                if (activeSlide && laptopFeatureLabel) {
                    laptopFeatureLabel.textContent = activeSlide.dataset.slideLabel || '';
                }

                laptopCurrentIndex = index;
            }

            function changeLaptopSlide(direction) {
                goToLaptopSlide(laptopCurrentIndex + direction);
                resetLaptopAutoSlide();
            }

            function startLaptopAutoSlide() {
                laptopAutoTimer = setInterval(() => {
                    goToLaptopSlide(laptopCurrentIndex + 1);
                }, 4500);
            }

            function resetLaptopAutoSlide() {
                clearInterval(laptopAutoTimer);
                startLaptopAutoSlide();
            }

            // Pause on hover, resume on leave & touch swipe support
            const laptopWrapper = document.getElementById('laptopCarousel');
            if (laptopWrapper) {
                laptopWrapper.addEventListener('mouseenter', () => {
                    clearInterval(laptopAutoTimer);
                });
                laptopWrapper.addEventListener('mouseleave', () => {
                    startLaptopAutoSlide();
                });

                // Touch swipe support for mobile
                let laptopTouchStartX = 0;
                let laptopTouchEndX = 0;
                laptopWrapper.addEventListener('touchstart', (e) => {
                    laptopTouchStartX = e.changedTouches[0].screenX;
                }, { passive: true });
                laptopWrapper.addEventListener('touchend', (e) => {
                    laptopTouchEndX = e.changedTouches[0].screenX;
                    if (laptopTouchStartX - laptopTouchEndX > 40) {
                        changeLaptopSlide(1);
                    } else if (laptopTouchEndX - laptopTouchStartX > 40) {
                        changeLaptopSlide(-1);
                    }
                }, { passive: true });
            }

            startLaptopAutoSlide();

            // 5. User Experience Tab Switcher
            const userTabBtns = document.querySelectorAll('.user-tab-btn');
            const userPanes = document.querySelectorAll('.user-pane-content');
            userTabBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    const targetId = btn.getAttribute('data-user');
                    userTabBtns.forEach(b => b.classList.remove('active'));
                    userPanes.forEach(p => p.classList.remove('active'));

                    btn.classList.add('active');
                    const targetPane = document.getElementById(targetId);
                    if (targetPane) targetPane.classList.add('active');
                });
            });

            // 5.5 Mobile Pricing Horizontal Carousel Scroll & Dot Sync
            const pricingGrid = document.getElementById('pricingGrid');
            const pricingDots = document.querySelectorAll('.pricing-dot');
            const pricingSection = document.getElementById('pricing');
            let userInteractedWithPricing = false;

            function scrollToPricingPlan(index, smooth = true) {
                if (!pricingGrid) return;
                const cards = pricingGrid.querySelectorAll('.pricing-card');
                if (cards[index]) {
                    const card = cards[index];
                    const targetLeft = card.offsetLeft - (pricingGrid.clientWidth - card.clientWidth) / 2;
                    pricingGrid.scrollTo({ left: targetLeft, behavior: smooth ? 'smooth' : 'auto' });
                }
            }

            function centerPopularPlan(smooth = false) {
                if (!pricingGrid || window.innerWidth > 768) return;
                // Index 1 is the Professional (Most Popular) plan
                scrollToPricingPlan(1, smooth);
                pricingDots.forEach((dot, idx) => {
                    dot.classList.toggle('active', idx === 1);
                });
            }

            if (pricingGrid && pricingDots.length > 0) {
                // Initialize Most Popular plan centered on mobile
                const initPopularCenter = () => {
                    if (window.innerWidth <= 768 && !userInteractedWithPricing) {
                        centerPopularPlan(false);
                    }
                };

                // Trigger on DOM ready, load, and layout stabilization
                initPopularCenter();
                setTimeout(initPopularCenter, 60);
                setTimeout(initPopularCenter, 300);
                window.addEventListener('load', initPopularCenter);

                // Re-center on window resize if user hasn't scrolled manually
                window.addEventListener('resize', () => {
                    if (!userInteractedWithPricing) {
                        initPopularCenter();
                    }
                });

                // When user navigates to #pricing via nav links, smooth center the Popular plan
                document.querySelectorAll('a[href="#pricing"], a[href$="#pricing"]').forEach(link => {
                    link.addEventListener('click', () => {
                        userInteractedWithPricing = false;
                        setTimeout(() => {
                            centerPopularPlan(true);
                        }, 250);
                    });
                });

                // Intersection observer: ensures the Popular plan is centered when user scrolls to pricing
                if (pricingSection && 'IntersectionObserver' in window) {
                    const pricingObserver = new IntersectionObserver((entries) => {
                        entries.forEach(entry => {
                            if (entry.isIntersecting && !userInteractedWithPricing) {
                                centerPopularPlan(false);
                            }
                        });
                    }, { threshold: 0.15 });
                    pricingObserver.observe(pricingSection);
                }

                // Dot sync on carousel scroll
                pricingGrid.addEventListener('scroll', () => {
                    const cards = pricingGrid.querySelectorAll('.pricing-card');
                    const scrollCenter = pricingGrid.scrollLeft + (pricingGrid.clientWidth / 2);
                    let closestIndex = 0;
                    let minDiff = Infinity;
                    cards.forEach((card, idx) => {
                        const cardCenter = card.offsetLeft + (card.offsetWidth / 2);
                        const diff = Math.abs(cardCenter - scrollCenter);
                        if (diff < minDiff) {
                            minDiff = diff;
                            closestIndex = idx;
                        }
                    });
                    pricingDots.forEach((dot, idx) => {
                        dot.classList.toggle('active', idx === closestIndex);
                    });
                }, { passive: true });

                // Detect touch interaction
                pricingGrid.addEventListener('touchstart', () => {
                    userInteractedWithPricing = true;
                }, { passive: true });

                // Mouse drag-to-scroll support for responsive simulation
                let isDragging = false;
                let startX = 0;
                let startScrollLeft = 0;

                pricingGrid.addEventListener('mousedown', (e) => {
                    if (window.innerWidth > 768) return;
                    userInteractedWithPricing = true;
                    isDragging = true;
                    pricingGrid.style.scrollSnapType = 'none';
                    startX = e.pageX - pricingGrid.offsetLeft;
                    startScrollLeft = pricingGrid.scrollLeft;
                });

                window.addEventListener('mouseup', () => {
                    if (!isDragging) return;
                    isDragging = false;
                    pricingGrid.style.scrollSnapType = 'x mandatory';
                });

                pricingGrid.addEventListener('mousemove', (e) => {
                    if (!isDragging) return;
                    e.preventDefault();
                    const x = e.pageX - pricingGrid.offsetLeft;
                    const walk = (x - startX) * 1.1;
                    pricingGrid.scrollLeft = startScrollLeft - walk;
                });
            }

            // 5.6 Mobile Feature Deep Dives Synchronized Carousel & Pill Tabs
            const deepDiveTrack = document.getElementById('featurePresentationTrack');
            const deepDivePills = document.querySelectorAll('.deepdive-pill-btn');
            const deepDiveDots = document.querySelectorAll('.deepdive-dot');

            function switchDeepDiveTab(index, smooth = true) {
                if (!deepDiveTrack) return;
                const cards = deepDiveTrack.querySelectorAll('.feature-row');
                if (cards[index]) {
                    deepDiveTrack.scrollTo({ left: cards[index].offsetLeft, behavior: smooth ? 'smooth' : 'auto' });
                }
                updateDeepDiveActiveState(index);
            }

            function updateDeepDiveActiveState(index) {
                deepDivePills.forEach((pill, idx) => {
                    pill.classList.toggle('active', idx === index);
                    if (idx === index) {
                        pill.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                    }
                });
                deepDiveDots.forEach((dot, idx) => {
                    dot.classList.toggle('active', idx === index);
                });
            }

            if (deepDiveTrack && deepDivePills.length > 0) {
                let deepDiveScrollTimer = null;
                deepDiveTrack.addEventListener('scroll', () => {
                    clearTimeout(deepDiveScrollTimer);
                    deepDiveScrollTimer = setTimeout(() => {
                        const cards = deepDiveTrack.querySelectorAll('.feature-row');
                        if (!cards.length) return;
                        const scrollLeft = deepDiveTrack.scrollLeft;
                        let closestIndex = 0;
                        let minDiff = Infinity;
                        cards.forEach((card, idx) => {
                            const diff = Math.abs(card.offsetLeft - scrollLeft);
                            if (diff < minDiff) {
                                minDiff = diff;
                                closestIndex = idx;
                            }
                        });
                        updateDeepDiveActiveState(closestIndex);
                    }, 30);
                }, { passive: true });
            }

            // 6. Demo Modal Logic
            const demoModalBackdrop = document.getElementById('demoModalBackdrop');
            function openDemoModal() {
                if (demoModalBackdrop) {
                    demoModalBackdrop.classList.add('open');
                    document.body.style.overflow = 'hidden';
                }
            }

            function closeDemoModal() {
                if (demoModalBackdrop) {
                    demoModalBackdrop.classList.remove('open');
                    document.body.style.overflow = '';
                }
            }

            function handleBackdropClick(e) {
                if (e.target === demoModalBackdrop) {
                    closeDemoModal();
                }
            }

            // 6.5 Subscription Plan Distribution Modal Logic
            const planDistributionModalBackdrop = document.getElementById('planDistributionModalBackdrop');
            function openPlanDistributionModal() {
                if (planDistributionModalBackdrop) {
                    planDistributionModalBackdrop.classList.add('open');
                    document.body.style.overflow = 'hidden';
                }
            }

            function closePlanDistributionModal() {
                if (planDistributionModalBackdrop) {
                    planDistributionModalBackdrop.classList.remove('open');
                    document.body.style.overflow = '';
                }
            }

            function handleDistributionBackdropClick(e) {
                if (e.target === planDistributionModalBackdrop) {
                    closePlanDistributionModal();
                }
            }

            // Global Escape key support for modals
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeDemoModal();
                    closePlanDistributionModal();
                }
            });

            // 7. Demo Form AJAX Submission
            async function submitDemoForm(e) {
                e.preventDefault();
                const form = document.getElementById('demoForm');
                const alertBox = document.getElementById('modalAlert');
                const submitBtn = document.getElementById('submitDemoBtn');

                submitBtn.disabled = true;
                submitBtn.innerText = 'Submitting...';
                alertBox.style.display = 'none';

                try {
                    const formData = new FormData(form);
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        alertBox.style.display = 'block';
                        alertBox.style.background = 'rgba(132, 204, 22, 0.15)';
                        alertBox.style.border = '1px solid #84cc16';
                        alertBox.style.color = '#a3e635';
                        alertBox.innerText = '✓ Thank you! Your demo request has been received. Our team will contact you shortly.';
                        form.reset();
                        setTimeout(() => {
                            closeDemoModal();
                            alertBox.style.display = 'none';
                        }, 3500);
                    } else {
                        alertBox.style.display = 'block';
                        alertBox.style.background = 'rgba(239, 68, 68, 0.15)';
                        alertBox.style.border = '1px solid #ef4444';
                        alertBox.style.color = '#f87171';
                        alertBox.innerText = (result.errors && result.errors.length) ? result.errors.join(', ') : 'Failed to submit request. Please try again.';
                    }
                } catch (err) {
                    alertBox.style.display = 'block';
                    alertBox.style.background = 'rgba(239, 68, 68, 0.15)';
                    alertBox.style.border = '1px solid #ef4444';
                    alertBox.style.color = '#f87171';
                    alertBox.innerText = 'Network error. Please try again.';
                } finally {
                    submitBtn.disabled = false;
                    submitBtn.innerText = 'Submit Demo Request';
                }
            }

            // 7.5 FAQ Accordion Toggle
            function toggleFaq(btn) {
                const item = btn.closest('.faq-item');
                if (!item) return;
                const isAlreadyOpen = item.classList.contains('active');
                
                // Close all sibling FAQ items for accordion toggle experience
                document.querySelectorAll('.faq-item.active').forEach(el => {
                    el.classList.remove('active');
                    const icon = el.querySelector('.faq-icon');
                    if (icon) icon.textContent = '+';
                });

                if (!isAlreadyOpen) {
                    item.classList.add('active');
                    const icon = item.querySelector('.faq-icon');
                    if (icon) icon.textContent = '−';
                }
            }

            // 8. Scroll Reveal Observer & Visibility Failsafe
            function initScrollReveal() {
                const elements = document.querySelectorAll('.reveal-on-scroll');
                if ('IntersectionObserver' in window) {
                    const observerOptions = {
                        threshold: 0.05,
                        rootMargin: '50px 0px 50px 0px'
                    };
                    const revealObserver = new IntersectionObserver((entries) => {
                        entries.forEach(entry => {
                            if (entry.isIntersecting) {
                                entry.target.classList.add('is-visible');
                                revealObserver.unobserve(entry.target);
                            }
                        });
                    }, observerOptions);

                    elements.forEach(el => revealObserver.observe(el));
                } else {
                    elements.forEach(el => el.classList.add('is-visible'));
                }

                // Failsafe: reveal top elements immediately after 100ms
                setTimeout(() => {
                    elements.forEach(el => {
                        const rect = el.getBoundingClientRect();
                        if (rect.top < window.innerHeight) {
                            el.classList.add('is-visible');
                        }
                    });
                }, 100);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initScrollReveal);
            } else {
                initScrollReveal();
            }

            // Landing Page Pricing Billing Switch (Monthly / Annual)
            // landingPlansData initialized from FT_LANDING_CONFIG at top
            let currentLandingCycle = 'monthly';

            function setLandingBillingCycle(cycle) {
                currentLandingCycle = (cycle === 'yearly') ? 'yearly' : 'monthly';
                const monthlyBtn = document.getElementById('landingBillingMonthly');
                const yearlyBtn = document.getElementById('landingBillingYearly');
                if (monthlyBtn && yearlyBtn) {
                    monthlyBtn.classList.toggle('active', currentLandingCycle === 'monthly');
                    yearlyBtn.classList.toggle('active', currentLandingCycle === 'yearly');
                }

                for (const [key, plan] of Object.entries(landingPlansData)) {
                    const priceEl = document.getElementById('landing-price-' + key);
                    const periodEl = document.getElementById('landing-period-' + key);
                    const savingsEl = document.getElementById('landing-savings-' + key);
                    if (priceEl && periodEl) {
                        if (currentLandingCycle === 'yearly') {
                            const annPrice = plan.annual_price || (plan.price * 10);
                            priceEl.textContent = Number(annPrice).toLocaleString();
                            periodEl.textContent = '/ year';
                            if (savingsEl) savingsEl.style.display = 'flex';
                        } else {
                            priceEl.textContent = Number(plan.price).toLocaleString();
                            periodEl.textContent = '/ month';
                            if (savingsEl) savingsEl.style.display = 'none';
                        }
                    }
                }
            }

            // Expand / Collapse Extra Features per card
            function togglePlanFeatures(key, btn) {
                const el = document.getElementById('extra-features-' + key);
                if (!el) return;
                const isHidden = (el.style.display === 'none' || el.style.display === '');
                const plan = landingPlansData[key];
                const extraCount = plan && plan.features ? Math.max(0, plan.features.length - 4) : '';

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

            // Mobile Plan Distribution Jump Tab Handler
            function jumpToDistTier(tierIndex, btn) {
                const wrap = document.getElementById('distTableWrap');
                if (!wrap) return;
                const btns = document.querySelectorAll('.dist-mobile-tab-btn');
                btns.forEach(b => b.classList.remove('active'));
                if (btn) btn.classList.add('active');

                if (tierIndex === 0) {
                    wrap.scrollTo({ left: 0, behavior: 'smooth' });
                } else {
                    const tiers = wrap.querySelectorAll('th.col-tier');
                    if (tiers[tierIndex - 1]) {
                        const targetLeft = Math.max(0, tiers[tierIndex - 1].offsetLeft - 130);
                        wrap.scrollTo({ left: targetLeft, behavior: 'smooth' });
                    }
                }
            }

            // Auto-update active tab on horizontal scroll
            document.addEventListener('DOMContentLoaded', () => {
                const distWrap = document.getElementById('distTableWrap');
                if (distWrap) {
                    distWrap.addEventListener('scroll', () => {
                        const scrollLeft = distWrap.scrollLeft;
                        const btns = document.querySelectorAll('.dist-mobile-tab-btn');
                        if (btns.length >= 4) {
                            if (scrollLeft < 30) {
                                btns.forEach((b, i) => b.classList.toggle('active', i === 0));
                            } else if (scrollLeft < 140) {
                                btns.forEach((b, i) => b.classList.toggle('active', i === 1));
                            } else if (scrollLeft < 260) {
                                btns.forEach((b, i) => b.classList.toggle('active', i === 2));
                            } else {
                                btns.forEach((b, i) => b.classList.toggle('active', i === 3));
                            }
                        }
                    }, { passive: true });
                }
            });


            // ── Back to Top Floating Button Handler ─────────────────────────────
            (() => {
                function initBackToTop() {
                    const backToTopBtn = document.getElementById('backToTopBtn');
                    if (!backToTopBtn) return;

                    function checkScroll() {
                        const currentScroll = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
                        if (currentScroll > 300) {
                            backToTopBtn.classList.add('visible');
                        } else {
                            backToTopBtn.classList.remove('visible');
                        }
                    }

                    // Check immediately on load in case page is already scrolled
                    checkScroll();

                    let ticking = false;
                    window.addEventListener('scroll', () => {
                        if (!ticking) {
                            window.requestAnimationFrame(() => {
                                checkScroll();
                                ticking = false;
                            });
                            ticking = true;
                        }
                    }, { passive: true });

                    backToTopBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        window.scrollTo({
                            top: 0,
                            behavior: 'smooth'
                        });
                    });
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initBackToTop);
                } else {
                    initBackToTop();
                }
            })();
