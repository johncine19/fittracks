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
        }

        function submitProfileForm() {
            const form = document.getElementById('gym-profile-form');
            if (form) form.requestSubmit();
        }

        function handleFormSubmit(form) {
            const saveBtn = document.getElementById('save-btn');
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.innerHTML = '<span style="display:inline-block;animation:spin 1s linear infinite;">⏳</span> Saving...';
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
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
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
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Restore active tab (default to 'general' so page is never too long)
            let activeTab = 'general';
            try {
                activeTab = localStorage.getItem('fittracks_gym_settings_tab') || 'general';
            } catch (e) {}
            switchSettingsTab(activeTab);

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
        });
