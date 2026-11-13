/**
 * Gym Profile & Settings Controller
 * Extracted from gym_profile.php
 */

        function openCustomModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.add('is-open');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeCustomModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.remove('is-open');
                document.body.style.overflow = '';
            }
        }

        function handleBackdropClick(event, id) {
            if (event.target && event.target.id === id) {
                closeCustomModal(id);
            }
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeCustomModal('modal-documents');
                closeCustomModal('modal-gallery');
                closeCustomModal('modal-xendit-kyc');
            }
        });

        // Nav Horizontal Scrolling & Overflow Cues
        function checkNavScrollState() {
            const nav = document.getElementById('settings-nav-sticky');
            const wrapper = document.getElementById('settings-nav-wrapper');
            if (!nav || !wrapper) return;

            const hasOverflow = nav.scrollWidth > nav.clientWidth + 4;
            const isAtStart = nav.scrollLeft <= 6;
            const isAtEnd = nav.scrollLeft + nav.clientWidth >= nav.scrollWidth - 8;

            wrapper.classList.toggle('has-overflow-left', hasOverflow && !isAtStart);
            wrapper.classList.toggle('has-overflow-right', hasOverflow && !isAtEnd);
        }

        const tabMeta = {
            general: {
                label: 'General Info',
                svg: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>'
            },
            branding: {
                label: 'Branding',
                svg: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M21 12H3M12 3v18"/></svg>'
            },
            documents: {
                label: 'Documents',
                svg: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>'
            },
            payout: {
                label: 'Online Payments',
                svg: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>'
            },
            reminders: {
                label: 'Reminders',
                svg: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>'
            },
            gallery: {
                label: 'Photo Gallery',
                svg: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>'
            },
            ratings: {
                label: 'Member Reviews',
                svg: '<svg width="17" height="17" viewBox="0 0 24 24" fill="#fbbf24" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>'
            },
            all: {
                label: 'Show All Sections',
                svg: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>'
            }
        };

        function toggleMobileNavDropdown() {
            const menu = document.getElementById('mobile-selector-menu');
            const trigger = document.getElementById('mobile-selector-trigger');
            if (!menu || !trigger) return;
            const isOpen = menu.classList.contains('is-open');
            if (isOpen) {
                closeMobileNavDropdown();
            } else {
                menu.classList.add('is-open');
                trigger.classList.add('is-open');
                trigger.setAttribute('aria-expanded', 'true');
            }
        }

        function closeMobileNavDropdown() {
            const menu = document.getElementById('mobile-selector-menu');
            const trigger = document.getElementById('mobile-selector-trigger');
            if (menu) menu.classList.remove('is-open');
            if (trigger) {
                trigger.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
            }
        }

        function selectMobileTab(tabName) {
            switchSettingsTab(tabName);
            closeMobileNavDropdown();
        }

        document.addEventListener('click', function(e) {
            const selector = document.getElementById('mobile-section-selector');
            if (selector && !selector.contains(e.target)) {
                closeMobileNavDropdown();
            }
        });

        // Tab Switching
        function switchSettingsTab(tabName, linkEl) {
            const panes = document.querySelectorAll('.settings-tab-pane');
            const links = document.querySelectorAll('.settings-nav-link');
            const contentFlow = document.getElementById('settings-content-flow');

            links.forEach(l => l.classList.remove('active'));
            let activeEl = linkEl;
            if (activeEl) {
                activeEl.classList.add('active');
            } else {
                activeEl = document.querySelector(`.settings-nav-link[data-tab="${tabName}"]`);
                if (activeEl) activeEl.classList.add('active');
            }

            // Sync Mobile Dropdown Trigger Display & Selection Checkmark
            const meta = tabMeta[tabName];
            if (meta) {
                const curLabel = document.getElementById('mobile-current-label');
                const curIcon = document.getElementById('mobile-current-icon');
                if (curLabel) curLabel.textContent = meta.label;
                if (curIcon) curIcon.innerHTML = meta.svg;
            }

            const mobileItems = document.querySelectorAll('.mobile-menu-item');
            mobileItems.forEach(item => {
                item.classList.toggle('active', item.dataset.tab === tabName);
            });

            if (tabName === 'all') {
                if (contentFlow) contentFlow.classList.add('view-all-grid');
                panes.forEach(p => {
                    p.style.display = 'block';
                });
            } else {
                if (contentFlow) contentFlow.classList.remove('view-all-grid');
                panes.forEach(p => {
                    if (p.id === 'sec-' + tabName) {
                        p.style.display = 'block';
                    } else {
                        p.style.display = 'none';
                    }
                });
            }

            try {
                localStorage.setItem('fittracks_gym_settings_tab', tabName);
            } catch (e) {}

            const activeTabInput = document.getElementById('active_tab_input');
            if (activeTabInput) {
                activeTabInput.value = tabName;
            }

            // Notify map and dynamic components of tab transition
            window.dispatchEvent(new CustomEvent('gym_profile_tab_changed', { detail: { tab: tabName } }));
        }

        function submitProfileForm(source) {
            window._lastSubmitSource = source || '';
            const form = document.getElementById('gym-profile-form');
            if (form) form.requestSubmit();
        }

        function handleFormSubmit(form, event) {
            const gateSelect = document.getElementById('eod_gate_time_select');
            if (gateSelect && !window._eodGateChangeConfirmed) {
                const currentGate = gateSelect.getAttribute('data-current-gate') || '17:00';
                const selectedGate = gateSelect.value;
                if (selectedGate !== currentGate) {
                    if (event && typeof event.preventDefault === 'function') {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                    
                    const currentOption = gateSelect.querySelector(`option[value="${currentGate}"]`);
                    const currentLabel = currentOption ? currentOption.textContent : currentGate;
                    const newLabel = gateSelect.options[gateSelect.selectedIndex]?.textContent || selectedGate;
                    
                    const tomorrow = new Date();
                    tomorrow.setDate(tomorrow.getDate() + 1);
                    const tomorrowFormatted = tomorrow.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

                    Swal.fire({
                        title: 'Confirm EOD Gate Opening Change',
                        icon: 'warning',
                        html: `
                            <div style="text-align: left; font-size: 13.5px; line-height: 1.6; color: var(--ink);">
                                <p style="margin: 0 0 10px;">You are changing the daily End-of-Day settlement cutoff from <strong>${window.escapeHtml ? window.escapeHtml(currentLabel) : currentLabel}</strong> to <strong>${window.escapeHtml ? window.escapeHtml(newLabel) : newLabel}</strong>.</p>
                                <div style="background: rgba(245, 158, 11, 0.12); border-left: 3px solid #f59e0b; padding: 10px 14px; border-radius: 6px; margin: 12px 0;">
                                    <strong style="color: #f59e0b;">Daily Settlement Freeze Notice:</strong><br>
                                    To safeguard daily financial shift integrity, this change will become active <strong>tomorrow (${window.escapeHtml ? window.escapeHtml(tomorrowFormatted) : tomorrowFormatted})</strong>.<br>
                                    Today's cutoff will remain at <strong>${window.escapeHtml ? window.escapeHtml(currentLabel) : currentLabel}</strong>.
                                </div>
                                <p style="margin: 0;">Do you accept and wish to schedule this change for tomorrow?</p>
                            </div>
                        `,
                        showCancelButton: true,
                        confirmButtonText: 'Yes, Accept & Apply Tomorrow',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#f59e0b',
                        cancelButtonColor: '#64748b',
                        reverseButtons: true,
                        background: 'var(--panel)',
                        color: 'var(--ink)'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window._eodGateChangeConfirmed = true;
                            if (form) form.requestSubmit();
                        } else {
                            gateSelect.value = currentGate;
                        }
                    });
                    return false;
                }
            }

            const source = window._lastSubmitSource || '';
            const diditActionVal = document.getElementById('didit_action_input')?.value || '';
            const isDiditSubmit = source === 'didit' || diditActionVal !== '';
            const isDocModalOpen = Boolean((document.getElementById('modal-documents') && document.getElementById('modal-documents').classList.contains('is-open')) || source === 'documents');
            const isGalleryModalOpen = Boolean((document.getElementById('modal-gallery') && document.getElementById('modal-gallery').classList.contains('is-open')) || source === 'gallery');

            // Restore unmasked raw values before submit so asterisks are never saved to database
            ['payout_account_number', 'payout_account_name'].forEach(id => {
                const el = document.getElementById(id);
                if (el && el.dataset.isMasked === '1' && el.dataset.raw) {
                    el.value = el.dataset.raw;
                }
            });

            const spinnerSvg = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:spin 0.8s linear infinite; display:inline-block; vertical-align:middle; margin-right:6px;"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"></path></svg>';

            if (isDiditSubmit) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: diditActionVal === 'refresh' ? 'Checking Didit Status…' : 'Connecting to Didit Verification…',
                        text: diditActionVal === 'refresh'
                            ? 'Fetching the latest verification decision from Didit.'
                            : 'Preparing your secure identity verification session.',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        background: 'var(--panel)',
                        color: 'var(--ink)',
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                }
            } else if (isDocModalOpen) {
                const docSaveBtn = document.getElementById('doc-modal-save-btn');
                if (docSaveBtn) {
                    docSaveBtn.disabled = true;
                    docSaveBtn.innerHTML = `${spinnerSvg} Saving Documents...`;
                }
                const docCancelBtn = document.querySelector('#modal-documents .btn-secondary');
                if (docCancelBtn) docCancelBtn.disabled = true;

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Saving Documents…',
                        text: 'Please wait while your compliance files are uploaded.',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        background: 'var(--panel)',
                        color: 'var(--ink)',
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                }
            } else if (isGalleryModalOpen) {
                const gallerySaveBtn = document.getElementById('gallery-modal-save-btn');
                if (gallerySaveBtn) {
                    gallerySaveBtn.disabled = true;
                    gallerySaveBtn.innerHTML = `${spinnerSvg} Uploading Photos...`;
                }
                const galleryCloseBtn = document.querySelector('#modal-gallery .btn-secondary');
                if (galleryCloseBtn) galleryCloseBtn.disabled = true;

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Uploading Photos…',
                        text: 'Please wait while your facility photos are being compressed and uploaded.',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        background: 'var(--panel)',
                        color: 'var(--ink)',
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                }
            } else if (isKycModalOpen) {
                const isAdmin = Boolean(window.GYM_PROFILE_CONFIG?.isPlatformAdmin);
                const kycBtn = document.getElementById('btn-submit-kyc-details');
                if (kycBtn) {
                    kycBtn.disabled = true;
                    kycBtn.innerHTML = isAdmin ? `${spinnerSvg} Saving Sub-Account...` : `${spinnerSvg} Submitting KYC Details...`;
                }
                const kycCancelBtn = document.querySelector('#modal-xendit-kyc .btn-secondary');
                if (kycCancelBtn) kycCancelBtn.disabled = true;

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: isAdmin ? 'Activating Sub-Account…' : 'Submitting KYC Verification…',
                        text: isAdmin
                            ? 'Please wait while the sub-account is verified and payment gateway is activated.'
                            : 'Please wait while your sub-account details are submitted and administrators are notified.',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        background: 'var(--panel)',
                        color: 'var(--ink)',
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                }
            } else {
                const saveBtn = document.getElementById('save-btn');
                if (saveBtn) {
                    saveBtn.disabled = true;
                    saveBtn.innerHTML = `${spinnerSvg} Saving Changes...`;
                }
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Saving Profile Changes…',
                        text: 'Please wait a moment while your changes are saved.',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        background: 'var(--panel)',
                        color: 'var(--ink)',
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                }
            }
            return true;
        }

        function submitDiditAction(action) {
            const actionInput = document.getElementById('didit_action_input');
            const form = document.getElementById('gym-profile-form');
            if (!actionInput || !form) return;
            actionInput.value = action;
            window._lastSubmitSource = 'didit';
            if (handleFormSubmit(form, null)) {
                form.submit();
            }
        }

        function confirmGalleryDelete(imageId) {
            Swal.fire({
                title: 'Delete this photo?',
                text: "This image will be removed from your gym gallery.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, delete it!',
                customClass: {
                    container: 'gym-gallery-delete-alert'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Deleting photo…',
                        text: 'Please wait while the photo is removed.',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        background: 'var(--panel)',
                        color: 'var(--ink)',
                        customClass: { container: 'gym-gallery-delete-alert' },
                        didOpen: () => {
                            Swal.showLoading();
                            window.setTimeout(() => {
                                const form = document.createElement('form');
                                form.method = 'POST';

                                const csrfInput = document.querySelector('input[name="csrf_token"]');
                                if (csrfInput) {
                                    const csrfClone = document.createElement('input');
                                    csrfClone.type = 'hidden';
                                    csrfClone.name = 'csrf_token';
                                    csrfClone.value = csrfInput.value;
                                    form.appendChild(csrfClone);
                                }

                                const actionInput = document.createElement('input');
                                actionInput.type = 'hidden';
                                actionInput.name = 'delete_gallery_image';
                                actionInput.value = '1';
                                form.appendChild(actionInput);

                                const idInput = document.createElement('input');
                                idInput.type = 'hidden';
                                idInput.name = 'image_id';
                                idInput.value = imageId;
                                form.appendChild(idInput);

                                document.body.appendChild(form);
                                form.submit();
                            }, 150);
                        }
                    });
                }
            });
        }

        function getInitialSettingsTab() {
            const urlParams = new URLSearchParams(window.location.search);
            const tabParam = urlParams.get('tab');
            if (tabParam && tabMeta[tabParam]) {
                return tabParam;
            }
            if (window.location.hash) {
                const cleanHash = window.location.hash.replace(/^#sec-/, '').replace(/^#/, '');
                if (tabMeta[cleanHash]) {
                    return cleanHash;
                }
            }
            try {
                const saved = localStorage.getItem('fittracks_gym_settings_tab');
                if (saved && tabMeta[saved]) {
                    return saved;
                }
            } catch (e) {}
            return 'general';
        }

        document.addEventListener('DOMContentLoaded', function() {
            const activeTab = getInitialSettingsTab();
            switchSettingsTab(activeTab);

            window.addEventListener('hashchange', function() {
                if (window.location.hash) {
                    const cleanHash = window.location.hash.replace(/^#sec-/, '').replace(/^#/, '');
                    if (tabMeta[cleanHash]) {
                        switchSettingsTab(cleanHash);
                    }
                }
            });

            if (new URLSearchParams(window.location.search).get('open_gallery') === '1') {
                openCustomModal('modal-gallery');
                const cleanUrl = new URL(window.location.href);
                cleanUrl.searchParams.delete('open_gallery');
                const photoDeleted = cleanUrl.searchParams.get('gallery_deleted') === '1';
                cleanUrl.searchParams.delete('gallery_deleted');
                window.history.replaceState({}, '', cleanUrl);

                if (photoDeleted && typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Photo deleted',
                        text: 'The photo was removed from your gallery.',
                        confirmButtonText: 'OK',
                        confirmButtonColor: 'var(--lime-dark)',
                        background: 'var(--panel)',
                        color: 'var(--ink)',
                        customClass: { container: 'gym-gallery-delete-alert' }
                    });
                }
            }

            const form = document.getElementById('gym-profile-form');
            const unsavedDot = document.getElementById('unsaved-indicator');
            const saveStatus = document.getElementById('save-bar-status');
            let isDirty = false;

            function markDirty() {
                if (!isDirty) {
                    isDirty = true;
                    if (unsavedDot) unsavedDot.style.background = 'var(--orange, #d97706)';
                    if (saveStatus) {
                        saveStatus.innerHTML = '<strong style="color:var(--orange, #d97706);">● Unsaved changes</strong> — click Save to apply';
                    }
                }
            }

            if (form) {
                form.querySelectorAll('input, select, textarea').forEach(el => {
                    el.addEventListener('input', markDirty);
                    el.addEventListener('change', markDirty);
                });
            }

            // Logo preview
            const logoWrap = document.getElementById('logo-avatar-wrap');
            const logoInput = document.getElementById('logo-file-input');
            const logoImg = document.getElementById('logo-preview-img');

            if (logoWrap && logoInput) {
                logoWrap.addEventListener('click', () => logoInput.click());
            }

            if (logoInput && logoImg) {
                logoInput.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        const file = this.files[0];
                        if (file.size > 5 * 1024 * 1024) {
                            Swal.fire('File Too Large', 'Please select a logo image under 5MB.', 'warning');
                            this.value = '';
                            return;
                        }
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            logoImg.src = e.target.result;
                        };
                        reader.readAsDataURL(file);
                        markDirty();
                    }
                });
            }

            const coverInput = document.getElementById('cover-image-input');
            const coverPreview = document.getElementById('public-cover-preview-img');
            const coverPlaceholder = document.getElementById('public-cover-placeholder');
            const coverChangeButton = document.getElementById('public-cover-change');
            if (coverChangeButton && coverInput) {
                coverChangeButton.addEventListener('click', function() {
                    coverInput.click();
                });
            }
            if (coverInput && coverPreview) {
                coverInput.addEventListener('change', function() {
                    const file = this.files && this.files[0];
                    if (!file) return;
                    if (file.size > 10 * 1024 * 1024) {
                        Swal.fire('File Too Large', 'Please select a cover image under 10MB.', 'warning');
                        this.value = '';
                        return;
                    }
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        coverPreview.src = event.target.result;
                        coverPreview.hidden = false;
                        if (coverPlaceholder) coverPlaceholder.hidden = true;
                    };
                    reader.readAsDataURL(file);
                    markDirty();
                });
            }

            function setupDragDrop(zone, input) {
                if (!zone || !input) return;
                ['dragenter', 'dragover'].forEach(eventName => {
                    zone.addEventListener(eventName, (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        zone.classList.add('drag-over');
                    }, false);
                });
                ['dragleave', 'drop'].forEach(eventName => {
                    zone.addEventListener(eventName, (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        zone.classList.remove('drag-over');
                    }, false);
                });
                zone.addEventListener('drop', (e) => {
                    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                        input.files = e.dataTransfer.files;
                        input.dispatchEvent(new Event('change'));
                    }
                }, false);
            }

            function bindFileInput(zoneId, inputId, labelId, maxMb, docTitle) {
                const zone = document.getElementById(zoneId);
                const input = document.getElementById(inputId);
                const label = document.getElementById(labelId);
                if (!input) return;

                setupDragDrop(zone, input);

                input.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        const file = this.files[0];
                        if (file.size > maxMb * 1024 * 1024) {
                            Swal.fire('File Too Large', `${docTitle} file must be ${maxMb}MB or less.`, 'warning');
                            this.value = '';
                            return;
                        }
                        if (label) {
                            label.textContent = 'Selected: ' + file.name;
                            label.style.color = 'var(--lime, #84cc16)';
                        }
                        markDirty();
                    }
                });
            }

            bindFileInput('permit-dropzone', 'permit-file-input', 'permit-label-text', 10, 'Business Permit');
            bindFileInput('valid-id-dropzone', 'valid-id-file-input', 'valid-id-label-text', 10, "Owner's Valid ID");
            bindFileInput('brgy-dropzone', 'brgy-file-input', 'brgy-label-text', 10, 'Barangay Clearance');
            bindFileInput('fire-dropzone', 'fire-file-input', 'fire-label-text', 10, 'Fire Safety Certificate');

            const galleryDropzone = document.getElementById('gallery-dropzone');
            const galleryInput = document.getElementById('gallery-file-input');
            const galleryLabel = document.getElementById('gallery-label-text');
            const queuedContainer = document.getElementById('gallery-queued-container');
            const remainingSlots = (window.GYM_PROFILE_CONFIG && typeof window.GYM_PROFILE_CONFIG.remainingSlots === 'number') ? window.GYM_PROFILE_CONFIG.remainingSlots : 10;

            setupDragDrop(galleryDropzone, galleryInput);

            if (galleryInput) {
                galleryInput.addEventListener('change', function() {
                    if (queuedContainer) queuedContainer.innerHTML = '';
                    if (this.files && this.files.length > 0) {
                        if (this.files.length > remainingSlots) {
                            Swal.fire('Upload Limit', `You can only upload up to ${remainingSlots} more photo(s).`, 'warning');
                            this.value = '';
                            if (galleryLabel) {
                                galleryLabel.textContent = 'Click or Drag Photos to Upload';
                                galleryLabel.style.color = 'var(--ink)';
                            }
                            return;
                        }

                        if (galleryLabel) {
                            galleryLabel.textContent = this.files.length + ' photo(s) queued';
                            galleryLabel.style.color = 'var(--lime, #84cc16)';
                        }
                        for (let i = 0; i < this.files.length; i++) {
                            const chip = document.createElement('span');
                            chip.className = 'queued-chip';
                            chip.textContent = '✓ ' + this.files[i].name;
                            queuedContainer.appendChild(chip);
                        }
                        markDirty();
                    }
                });
            }

            const colorPicker = document.getElementById('brand_color_picker');
            const hexLabel = document.getElementById('hex-label');
            const accentChip = document.getElementById('live-accent-chip');
            const swatches = document.querySelectorAll('.color-swatch');

            function applyColor(hex) {
                if (colorPicker) colorPicker.value = hex;
                if (hexLabel) hexLabel.textContent = hex.toUpperCase();
                if (accentChip) accentChip.style.backgroundColor = hex;
                swatches.forEach(s => {
                    if (s.dataset.color.toLowerCase() === hex.toLowerCase()) {
                        s.classList.add('active');
                    } else {
                        s.classList.remove('active');
                    }
                });
                markDirty();
            }

            if (colorPicker) {
                colorPicker.addEventListener('input', function() {
                    applyColor(this.value);
                });
            }

            swatches.forEach(swatch => {
                swatch.addEventListener('click', function() {
                    applyColor(this.dataset.color);
                });
            });

            // Synchronize Facebook / Social Page inputs across General Info & Documents Modal
            const fbGeneralInput = document.getElementById('fb_page_url');
            const fbModalInput = document.getElementById('modal_fb_page_url');
            if (fbGeneralInput && fbModalInput) {
                fbGeneralInput.addEventListener('input', function() {
                    fbModalInput.value = this.value;
                    if (typeof markDirty === 'function') markDirty();
                });
                fbModalInput.addEventListener('input', function() {
                    fbGeneralInput.value = this.value;
                    if (typeof markDirty === 'function') markDirty();
                });
            }

            // Payout masked fields interactive behavior (09******123 and J*****l)
            ['payout_account_number', 'payout_account_name'].forEach(id => {
                const input = document.getElementById(id);
                if (!input) return;
                const btn = input.parentElement ? input.parentElement.querySelector('.field-eye-toggle') : null;

                input.addEventListener('focus', function () {
                    // Reveal real raw value on focus so the user can easily edit without typing into asterisks
                    if (this.dataset.isMasked === '1') {
                        this.value = this.dataset.raw || '';
                        this.dataset.isMasked = '0';
                        if (btn) {
                            const eyeShow = btn.querySelector('.eye-show');
                            const eyeHide = btn.querySelector('.eye-hide');
                            if (eyeShow && eyeHide) {
                                eyeShow.style.display = 'none';
                                eyeHide.style.display = 'inline-block';
                            }
                        }
                    }
                });

                input.addEventListener('input', function () {
                    if (id === 'payout_account_number') {
                        const chan = document.getElementById('payout_channel')?.value || 'gcash';
                        const maxLen = chan === 'bank_transfer' ? 18 : 11;
                        this.value = this.value.replace(/\D/g, '').slice(0, maxLen);
                    }
                    this.dataset.raw = this.value;
                    this.dataset.masked = (id === 'payout_account_number')
                        ? maskAccountNumber(this.value)
                        : maskAccountName(this.value);
                    this.dataset.isMasked = '0';
                    if (typeof markDirty === 'function') markDirty();
                });

                input.addEventListener('blur', function () {
                    // Re-mask on blur unless the user explicitly toggled eye to stay revealed
                    if (!this.dataset.userRevealed && this.value.trim() !== '') {
                        this.dataset.raw = this.value;
                        this.dataset.masked = (id === 'payout_account_number')
                            ? maskAccountNumber(this.value)
                            : maskAccountName(this.value);
                        this.value = this.dataset.masked;
                        this.dataset.isMasked = '1';
                        if (btn) {
                            const eyeShow = btn.querySelector('.eye-show');
                            const eyeHide = btn.querySelector('.eye-hide');
                            if (eyeShow && eyeHide) {
                                eyeShow.style.display = 'inline-block';
                                eyeHide.style.display = 'none';
                            }
                        }
                    }
                });
            });

            // Validate Xendit KYC Modal submission
            const kycSubmitBtn = document.getElementById('btn-submit-kyc-details');
            if (kycSubmitBtn) {
                kycSubmitBtn.addEventListener('click', function (e) {
                    const emailInput = document.getElementById('xendit_kyc_email');
                    const subInput = document.getElementById('xendit_subaccount_id_submission');
                    const emailVal = emailInput ? emailInput.value.trim() : '';
                    const subVal = subInput ? subInput.value.trim() : '';

                    if (!emailVal || !emailVal.includes('@')) {
                        e.preventDefault();
                        e.stopPropagation();
                        alert('Please enter a valid email address.');
                        emailInput?.focus();
                        return false;
                    }
                    if (!subVal) {
                        e.preventDefault();
                        e.stopPropagation();
                        alert('Please enter the XenPlatform Sub-Account ID.');
                        subInput?.focus();
                        return false;
                    }
                });
            }
        });

        function maskAccountNumber(num) {
            if (!num) return '';
            const str = String(num).trim();
            if (str.length <= 5) return str;
            const first2 = str.slice(0, 2);
            const last3 = str.slice(-3);
            const middleLen = Math.max(1, str.length - 5);
            return first2 + '*'.repeat(middleLen) + last3;
        }

        function maskAccountName(name) {
            if (!name) return '';
            const trimmed = String(name).trim();
            if (trimmed.length <= 2) return trimmed;
            const first = trimmed.charAt(0);
            const last = trimmed.charAt(trimmed.length - 1);
            const middleLen = Math.max(1, trimmed.length - 2);
            return first + '*'.repeat(middleLen) + last;
        }

        function togglePayoutFieldMask(fieldId, btnEl) {
            const input = document.getElementById(fieldId);
            if (!input) return;

            const isCurrentlyMasked = input.dataset.isMasked === '1';

            if (isCurrentlyMasked) {
                // User wants to reveal raw value
                input.value = input.dataset.raw || '';
                input.dataset.isMasked = '0';
                input.dataset.userRevealed = '1';
            } else {
                // User wants to mask value
                if (!input.value.includes('*')) {
                    input.dataset.raw = input.value;
                    input.dataset.masked = (fieldId === 'payout_account_number')
                        ? maskAccountNumber(input.value)
                        : maskAccountName(input.value);
                }
                input.value = input.dataset.masked || '';
                input.dataset.isMasked = '1';
                delete input.dataset.userRevealed;
            }

            if (btnEl) {
                const eyeShow = btnEl.querySelector('.eye-show');
                const eyeHide = btnEl.querySelector('.eye-hide');
                if (eyeShow && eyeHide) {
                    eyeShow.style.display = input.dataset.isMasked === '1' ? 'inline-block' : 'none';
                    eyeHide.style.display = input.dataset.isMasked === '1' ? 'none' : 'inline-block';
                }
                btnEl.setAttribute('aria-label', input.dataset.isMasked === '1' ? 'Show' : 'Hide');
                btnEl.setAttribute('title', input.dataset.isMasked === '1' ? 'Show' : 'Hide');
            }
        }
        window.togglePayoutFieldMask = togglePayoutFieldMask;
        window.maskAccountNumber = maskAccountNumber;
        window.maskAccountName = maskAccountName;
