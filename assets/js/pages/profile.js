/**
 * Member Profile & Settings Controller
 * Extracted from profile.php
 */

    let deleteOtpCooldownTimer = null;

    function showDeleteAccountAlert(message, type = 'error') {
        const alertEl = document.getElementById('deleteAccountAlert');
        if (!alertEl) return;
        alertEl.textContent = message;
        alertEl.className = 'danger-modal-alert ' + (type === 'success' ? 'is-success' : 'is-error');
        alertEl.style.display = 'block';
    }

    function clearDeleteAccountAlert() {
        const alertEl = document.getElementById('deleteAccountAlert');
        if (!alertEl) return;
        alertEl.textContent = '';
        alertEl.style.display = 'none';
    }

    function startDeleteOtpCooldown(seconds) {
        const resendBtn = document.getElementById('btnResendDeleteOtp');
        if (!resendBtn) return;
        if (deleteOtpCooldownTimer) clearInterval(deleteOtpCooldownTimer);

        let remaining = seconds;
        resendBtn.disabled = true;
        resendBtn.textContent = `Resend Code (${remaining}s)`;

        deleteOtpCooldownTimer = setInterval(() => {
            remaining -= 1;
            if (remaining <= 0) {
                clearInterval(deleteOtpCooldownTimer);
                deleteOtpCooldownTimer = null;
                resendBtn.disabled = false;
                resendBtn.textContent = 'Resend Code';
            } else {
                resendBtn.textContent = `Resend Code (${remaining}s)`;
            }
        }, 1000);
    }

    function openLeaveGymModal(gymId, gymName) {
        const modal = document.getElementById('leaveGymModal');
        if (!modal) return;
        const idInput = document.getElementById('leaveGymIdInput');
        const titleEl = document.getElementById('leaveGymModalTitle');
        const nameEl = document.getElementById('leaveGymModalName');
        if (idInput) idInput.value = gymId;
        if (titleEl) titleEl.textContent = `Leave ${gymName}?`;
        if (nameEl) nameEl.textContent = gymName;
        modal.showModal();
    }

    function openDeleteAccountModal() {
        const modal = document.getElementById('deleteAccountModal');
        if (!modal) return;
        clearDeleteAccountAlert();
        const step1 = document.getElementById('deleteAccountStep1');
        const step2 = document.getElementById('deleteAccountStep2');
        const pwdInput = document.getElementById('deleteCurrentPasswordInput');
        if (pwdInput) pwdInput.value = '';
        if (step1) step1.style.display = 'block';
        if (step2) {
            step2.style.display = 'none';
            step2.reset();
        }
        modal.showModal();
    }

    async function requestDeleteAccountOtp() {
        clearDeleteAccountAlert();
        const sendBtn = document.getElementById('btnSendDeleteOtp');
        const resendBtn = document.getElementById('btnResendDeleteOtp');
        const step2Form = document.getElementById('deleteAccountStep2');
        const pwdInput = document.getElementById('deleteCurrentPasswordInput');
        const csrfInput = step2Form ? step2Form.querySelector('input[name="csrf_token"]') : document.querySelector('input[name="csrf_token"]');
        const csrfToken = csrfInput ? csrfInput.value : '';

        if (pwdInput && pwdInput.value.trim() === '') {
            showDeleteAccountAlert('Please enter your current password to request a verification code.', 'error');
            pwdInput.focus();
            return;
        }

        if (sendBtn) {
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<span class="btn-text-full">Sending Code...</span><span class="btn-text-short">Sending...</span>';
        }
        if (resendBtn) {
            resendBtn.disabled = true;
        }

        try {
            const fd = new FormData();
            fd.append('action', 'send_delete_account_otp');
            fd.append('csrf_token', csrfToken);
            if (pwdInput) {
                fd.append('current_password', pwdInput.value);
            }

            const res = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: fd
            });
            const data = await res.json();

            if (!res.ok || !data.ok) {
                showDeleteAccountAlert(data.error || 'Unable to send verification code. Please try again.', 'error');
                if (data.retry_after) {
                    startDeleteOtpCooldown(parseInt(data.retry_after, 10) || 60);
                } else if (resendBtn) {
                    resendBtn.disabled = false;
                }
                return;
            }

            const step1 = document.getElementById('deleteAccountStep1');
            const step2 = document.getElementById('deleteAccountStep2');
            if (step1) step1.style.display = 'none';
            if (step2) step2.style.display = 'block';

            showDeleteAccountAlert(data.message || 'Verification code sent to your email.', 'success');
            startDeleteOtpCooldown(parseInt(data.cooldown, 10) || 60);

            const otpInput = document.getElementById('deleteOtpInput');
            if (otpInput) setTimeout(() => otpInput.focus(), 80);
        } catch (err) {
            showDeleteAccountAlert('Network error while requesting verification code. Please try again.', 'error');
            if (resendBtn) resendBtn.disabled = false;
        } finally {
            if (sendBtn) {
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<span class="btn-text-full">Send Verification Code</span><span class="btn-text-short">Send Code</span>';
            }
        }
    }

    async function submitDeleteAccountConfirm(e) {
        e.preventDefault();
        clearDeleteAccountAlert();

        const form = document.getElementById('deleteAccountStep2');
        const submitBtn = document.getElementById('btnConfirmDeleteAccount');
        if (!form) return;

        const otpInput = document.getElementById('deleteOtpInput');
        const confirmText = document.getElementById('deleteConfirmTextInput');
        if (!otpInput || otpInput.value.trim().length !== 6) {
            showDeleteAccountAlert('Please enter the 6-digit verification code from your email.', 'error');
            return;
        }
        if (!confirmText || confirmText.value.trim().toUpperCase() !== 'DELETE') {
            showDeleteAccountAlert('Please type DELETE in capital letters to confirm.', 'error');
            return;
        }

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="btn-text-full">Verifying &amp; Deleting...</span><span class="btn-text-short">Deleting...</span>';
        }

        try {
            const fd = new FormData(form);
            const res = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: fd
            });
            const data = await res.json();

            if (!res.ok || !data.ok) {
                showDeleteAccountAlert(data.error || 'Failed to verify code. Please try again.', 'error');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<span class="btn-text-full">Verify &amp; Permanently Delete</span><span class="btn-text-short">Confirm Delete</span>';
                }
                return;
            }

            window.location.href = data.redirect || '?page=login';
        } catch (err) {
            showDeleteAccountAlert('Network error while verifying deletion. Please try again.', 'error');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<span class="btn-text-full">Verify &amp; Permanently Delete</span><span class="btn-text-short">Confirm Delete</span>';
            }
        }
    }

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

        if (urlParams.get('open') === 'physical' || window.location.hash === '#edit-physical') {
            const physModal = document.getElementById('physicalProfileModal');
            if (physModal && typeof physModal.showModal === 'function') {
                setTimeout(() => physModal.showModal(), 150);
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
