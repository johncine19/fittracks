/**
 * Admin Equipment Management Controller
 * Extracted from admin/equipment.php
 */

(function() {
    const FT_CFG = window.ADMIN_EQUIPMENT_CONFIG || {};
    const GYM_ID = FT_CFG.gymId || 0;
    const CSRF_TOKEN = FT_CFG.csrfToken || (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
        let equipmentData = [];
        let searchQuery = '';
        let categoryFilter = 'all';
        let statusFilter = 'all';
        let currentEquipPage = 1;
        let equipPageSize = 8;

        async function adminEquipPost(action, formData) {
            formData.append('csrf_token', CSRF_TOKEN);
            if (!formData.has('gym_id')) {
                formData.append('gym_id', GYM_ID);
            }
            const res = await fetch(`index.php?page=equipment_api&action=${action}&gym_id=${GYM_ID}`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: formData
            });
            return await res.json();
        }

        const icons = {
            available: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
            in_use: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
            maintenance: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
            out_of_service: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>'
        };

        async function fetchAdminEquipment() {
            try {
                const res = await fetch(`index.php?page=equipment_api&action=poll&gym_id=${GYM_ID}`);
                const data = await res.json();
                if (data.success) {
                    equipmentData = data.equipment || [];
                    renderAdminTable();
                }
            } catch (err) {
                console.error(err);
            }
        }

        function renderAdminTable() {
            const tbody = document.getElementById('admin-tbody');
            const mobileCards = document.getElementById('admin-mobile-cards');
            const countPill = document.getElementById('equip-count-pill');
            const unitsBadge = document.querySelector('.units-badge');
            const paginationBar = document.getElementById('admin-equip-pagination');

            const filtered = equipmentData.filter(item => {
                if (categoryFilter !== 'all' && item.category !== categoryFilter) return false;
                if (statusFilter !== 'all') {
                    if (statusFilter === 'available' && item.status !== 'available') return false;
                    if (statusFilter === 'in_use' && item.status !== 'in_use') return false;
                    if (statusFilter === 'maintenance' && item.status !== 'maintenance') return false;
                    if (statusFilter === 'out_of_service' && item.status !== 'out_of_service') return false;
                }
                if (searchQuery) {
                    const haystack = (item.name + ' ' + item.unit_number + ' ' + item.category + ' ' + (item.location_area || '')).toLowerCase();
                    if (!haystack.includes(searchQuery)) return false;
                }
                return true;
            });

            if (countPill) {
                countPill.textContent = `${filtered.length} unit${filtered.length === 1 ? '' : 's'}`;
            }
            if (unitsBadge) {
                unitsBadge.textContent = `${equipmentData.length} Units`;
            }

            if (filtered.length === 0) {
                if (paginationBar) paginationBar.style.display = 'none';
                tbody.innerHTML = `<tr><td colspan="8" style="padding: 40px; text-align: center; color: var(--muted);">No matching equipment units found.</td></tr>`;
                if (mobileCards) {
                    mobileCards.innerHTML = `<div style="padding: 36px 20px; text-align: center; color: var(--muted); background: var(--panel-soft); border-radius: 10px; border: 1px dashed var(--line); font-size: 13px;">No matching equipment units found.</div>`;
                }
                return;
            }

            // Pagination calculation
            const totalFiltered = filtered.length;
            const totalPages = Math.max(1, Math.ceil(totalFiltered / equipPageSize));
            if (currentEquipPage > totalPages) {
                currentEquipPage = totalPages;
            }
            if (currentEquipPage < 1) {
                currentEquipPage = 1;
            }

            const startIndex = (currentEquipPage - 1) * equipPageSize;
            const endIndex = Math.min(startIndex + equipPageSize, totalFiltered);
            const pagedItems = filtered.slice(startIndex, endIndex);

            if (paginationBar) {
                paginationBar.style.display = 'flex';
                renderAdminPagination(totalFiltered, totalPages, startIndex, endIndex);
            }

            let tableHtml = '';
            let cardsHtml = '';

            pagedItems.forEach(eq => {
                const status = eq.status || 'available';
                const statusLabel = {
                    'available': 'Available',
                    'in_use': 'In Use',
                    'maintenance': 'Maintenance',
                    'out_of_service': 'Out of Service'
                }[status] || 'Available';

                const statusClass = 'status-' + status;
                const iconSvg = icons[status] || icons.available;

                const occupant = eq.current_user_display ? escapeHtml(eq.current_user_display) : '—';
                const queueCount = parseInt(eq.waiting_queue_count || 0);
                const nextMaint = eq.next_maintenance_date ? escapeHtml(eq.next_maintenance_date) : '—';

                // Status action button: either Set Maintenance or Restore Available
                let maintActionBtn = '';
                if (status === 'maintenance' || status === 'out_of_service') {
                    maintActionBtn = `<button type="button" onclick="restoreAvailable(${eq.equipment_id})" class="btn-act btn-act-restore" title="Restore unit to available">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Restore
                    </button>`;
                } else {
                    maintActionBtn = `<button type="button" onclick="openMaintModal(${eq.equipment_id})" class="btn-act btn-act-service" title="Schedule maintenance">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                        Service
                    </button>`;
                }

                const imageThumb = eq.image_url ? 
                    `<img src="${escapeHtml(eq.image_url)}" alt="${escapeHtml(eq.name)}" style="width: 36px; height: 36px; object-fit: cover; border-radius: 6px; border: 1px solid var(--line); flex-shrink: 0;" loading="lazy">` : 
                    `<div style="width: 36px; height: 36px; border-radius: 6px; background: var(--panel-soft); border: 1px solid var(--line); display: flex; align-items: center; justify-content: center; color: var(--muted); flex-shrink: 0;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="4" height="12" rx="1"/><rect x="18" y="6" width="4" height="12" rx="1"/><path d="M6 12h12"/></svg></div>`;

                // Desktop Table Row
                tableHtml += `
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                ${imageThumb}
                                <div>
                                    <div style="display: flex; align-items: center; gap: 5px;">
                                        <strong style="color: var(--ink); font-size: 14px;">${escapeHtml(eq.name)}</strong>
                                        <span class="unit-pill">${escapeHtml(eq.unit_number)}</span>
                                    </div>
                                    <span style="font-size: 11px; color: var(--muted);">${escapeHtml(eq.equipment_condition || 'Good')} condition</span>
                                </div>
                            </div>
                        </td>
                        <td style="color: var(--muted);">${escapeHtml(eq.category)}</td>
                        <td style="color: var(--muted);">${escapeHtml(eq.location_area || 'Main Floor')}</td>
                        <td>
                            <span class="status-pill ${statusClass}">
                                ${iconSvg} ${statusLabel}
                            </span>
                        </td>
                        <td style="color: var(--ink); font-weight: ${occupant !== '—' ? '600' : 'normal'};">
                            ${occupant}
                        </td>
                        <td>
                            <span style="color: ${queueCount > 0 ? '#d97706' : 'var(--muted)'}; font-weight: ${queueCount > 0 ? '700' : 'normal'};">
                                ${queueCount > 0 ? `${queueCount} waiting` : '0 waiting'}
                            </span>
                        </td>
                        <td style="color: var(--muted); font-size: 12px;">
                            ${nextMaint}
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 6px; align-items: center; justify-content: flex-end;">
                                <button type="button" onclick="openQueueModal(${eq.equipment_id})" class="btn-act btn-act-default" title="View session & queue">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                                    Queue
                                </button>
                                <button type="button" onclick="openEditModal(${eq.equipment_id})" class="btn-act btn-act-default" title="Edit details">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    Edit
                                </button>
                                ${maintActionBtn}
                                <button type="button" onclick="confirmDeleteEquipment(${eq.equipment_id})" class="btn-act btn-act-delete" title="Delete unit">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;

                // Responsive Mobile Card (No horizontal scrolling!)
                cardsHtml += `
                    <div class="equip-mobile-card">
                        <div class="equip-mcard-header">
                            <div class="equip-mcard-title-group" style="display: flex; align-items: center; gap: 10px;">
                                ${imageThumb}
                                <div>
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <h4 class="equip-mcard-title">${escapeHtml(eq.name)}</h4>
                                        <span class="unit-pill">${escapeHtml(eq.unit_number)}</span>
                                    </div>
                                    <div class="equip-mcard-sub">
                                        <span>${escapeHtml(eq.category)}</span>
                                        <span>•</span>
                                        <span>${escapeHtml(eq.location_area || 'Main Floor')}</span>
                                    </div>
                                </div>
                            </div>
                            <span class="status-pill ${statusClass}">
                                ${iconSvg} ${statusLabel}
                            </span>
                        </div>

                        <div class="equip-mcard-details-grid">
                            <div class="equip-mcard-detail-item">
                                <span class="equip-mcard-detail-label">Current User</span>
                                <span class="equip-mcard-detail-val" style="color: ${occupant !== '—' ? 'var(--ink)' : 'var(--muted)'}; font-weight: ${occupant !== '—' ? '600' : 'normal'};">
                                    ${occupant}
                                </span>
                            </div>
                            <div class="equip-mcard-detail-item">
                                <span class="equip-mcard-detail-label">Queue</span>
                                <span class="equip-mcard-detail-val" style="color: ${queueCount > 0 ? '#d97706' : 'var(--muted)'}; font-weight: ${queueCount > 0 ? '700' : 'normal'};">
                                    ${queueCount > 0 ? `${queueCount} in line` : '0 waiting'}
                                </span>
                            </div>
                            <div class="equip-mcard-detail-item">
                                <span class="equip-mcard-detail-label">Next Service</span>
                                <span class="equip-mcard-detail-val" style="color: var(--muted);">
                                    ${nextMaint}
                                </span>
                            </div>
                        </div>

                        <div class="equip-mcard-actions">
                            <button type="button" onclick="openQueueModal(${eq.equipment_id})" class="btn-act btn-act-default">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                                Queue
                            </button>
                            <button type="button" onclick="openEditModal(${eq.equipment_id})" class="btn-act btn-act-default">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Edit
                            </button>
                            ${maintActionBtn}
                            <button type="button" onclick="confirmDeleteEquipment(${eq.equipment_id})" class="btn-act btn-act-delete" title="Delete unit">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            </button>
                        </div>
                    </div>
                `;
            });

            tbody.innerHTML = tableHtml;
            if (mobileCards) {
                mobileCards.innerHTML = cardsHtml;
            }
        }

        function renderAdminPagination(totalItems, totalPages, startIndex, endIndex) {
            const infoEl = document.getElementById('admin-equip-page-info');
            const buttonsEl = document.getElementById('admin-equip-page-buttons');
            if (!infoEl || !buttonsEl) return;

            infoEl.innerHTML = `Showing <strong>${startIndex + 1}–${endIndex}</strong> of <strong>${totalItems}</strong> units`;

            if (totalPages <= 1) {
                buttonsEl.innerHTML = `
                    <button type="button" class="btn-equip-page disabled" aria-label="Previous page">&lt;</button>
                    <button type="button" class="btn-equip-page active">1</button>
                    <button type="button" class="btn-equip-page disabled" aria-label="Next page">&gt;</button>
                `;
                return;
            }

            let pages = [];
            if (totalPages <= 5) {
                for (let i = 1; i <= totalPages; i++) pages.push(i);
            } else {
                if (currentEquipPage <= 3) {
                    pages = [1, 2, 3, '...', totalPages];
                } else if (currentEquipPage >= totalPages - 2) {
                    pages = [1, '...', totalPages - 2, totalPages - 1, totalPages];
                } else {
                    pages = [1, '...', currentEquipPage - 1, currentEquipPage, currentEquipPage + 1, '...', totalPages];
                }
            }

            let btnsHtml = '';
            // Previous button (<)
            const prevDisabled = currentEquipPage <= 1 ? 'disabled' : '';
            btnsHtml += `<button type="button" class="btn-equip-page ${prevDisabled}" onclick="goToEquipPage(${currentEquipPage - 1})" aria-label="Previous page">&lt;</button>`;

            // Page numbers
            pages.forEach(p => {
                if (p === '...') {
                    btnsHtml += `<span class="equip-page-ellipsis">...</span>`;
                } else {
                    const isActive = p === currentEquipPage ? 'active' : '';
                    btnsHtml += `<button type="button" class="btn-equip-page ${isActive}" onclick="goToEquipPage(${p})">${p}</button>`;
                }
            });

            // Next button (>)
            const nextDisabled = currentEquipPage >= totalPages ? 'disabled' : '';
            btnsHtml += `<button type="button" class="btn-equip-page ${nextDisabled}" onclick="goToEquipPage(${currentEquipPage + 1})" aria-label="Next page">&gt;</button>`;

            buttonsEl.innerHTML = btnsHtml;
        }

        window.goToEquipPage = function(page) {
            currentEquipPage = page;
            renderAdminTable();
            const tableWrap = document.querySelector('.equip-table-wrap');
            if (tableWrap) {
                const rect = tableWrap.getBoundingClientRect();
                if (rect.top < 0) {
                    tableWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        };

        window.changeEquipPageSize = function(size) {
            equipPageSize = parseInt(size, 10) || 8;
            currentEquipPage = 1;
            renderAdminTable();
        };

        // ADD MODAL
        window.openAddModal = function() {
            document.getElementById('modal-title').textContent = 'Add Equipment';
            document.getElementById('form-equip-id').value = '0';
            document.getElementById('form-name').value = '';
            document.getElementById('form-category').value = 'Machines';
            document.getElementById('form-condition').value = 'Good';
            document.getElementById('form-unit-number').value = '#1';
            document.getElementById('form-quantity').value = '1';
            document.getElementById('batch-qty-container').style.display = 'block';
            document.getElementById('form-location').value = 'Main Gym Floor';
            document.getElementById('form-image-file').value = '';
            document.getElementById('form-image-url').value = '';
            document.getElementById('image-preview-container').style.display = 'none';
            document.getElementById('form-next-maint').value = '';
            document.getElementById('form-description').value = '';
            document.getElementById('equipment-modal').style.display = 'flex';
        };

        // EDIT MODAL
        window.openEditModal = function(eq) {
            if (typeof eq === 'number' || (typeof eq === 'string' && /^\d+$/.test(eq))) {
                const id = parseInt(eq, 10);
                eq = (equipmentData || []).find(e => parseInt(e.equipment_id, 10) === id) || null;
            }
            if (!eq) return;

            document.getElementById('modal-title').textContent = 'Edit Equipment Details';
            document.getElementById('form-equip-id').value = eq.equipment_id;
            document.getElementById('form-name').value = eq.name;
            document.getElementById('form-category').value = eq.category;
            document.getElementById('form-condition').value = eq.equipment_condition || 'Good';
            document.getElementById('form-unit-number').value = eq.unit_number;
            document.getElementById('batch-qty-container').style.display = 'none';
            document.getElementById('form-location').value = eq.location_area || 'Main Gym Floor';
            document.getElementById('form-image-file').value = '';
            document.getElementById('form-image-url').value = eq.image_url || '';
            
            if (eq.image_url) {
                document.getElementById('form-image-preview').src = eq.image_url;
                document.getElementById('image-preview-name').textContent = eq.name;
                document.getElementById('image-preview-meta').textContent = 'Current Equipment Image';
                document.getElementById('image-preview-container').style.display = 'flex';
            } else {
                document.getElementById('image-preview-container').style.display = 'none';
            }

            document.getElementById('form-next-maint').value = eq.next_maintenance_date || '';
            document.getElementById('form-description').value = eq.description || '';
            document.getElementById('equipment-modal').style.display = 'flex';
        };

        window.closeModal = function() {
            document.getElementById('equipment-modal').style.display = 'none';
        };

        window.clearImageSelection = function() {
            document.getElementById('form-image-file').value = '';
            document.getElementById('form-image-url').value = '';
            document.getElementById('image-preview-container').style.display = 'none';
        };

        // Image file selection & 5MB validation
        document.getElementById('form-image-file').addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                if (file.size > 5 * 1024 * 1024) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'File Too Large',
                        text: `The selected image is ${(file.size / (1024 * 1024)).toFixed(2)}MB. Maximum allowed size is 5MB.`,
                        confirmButtonColor: '#ff4d5d'
                    });
                    this.value = '';
                    document.getElementById('image-preview-container').style.display = 'none';
                    return;
                }
                const reader = new FileReader();
                reader.onload = function(evt) {
                    document.getElementById('form-image-preview').src = evt.target.result;
                    document.getElementById('image-preview-name').textContent = file.name;
                    document.getElementById('image-preview-meta').textContent = `${(file.size / 1024).toFixed(0)} KB • Will upload to ImageKit`;
                    document.getElementById('image-preview-container').style.display = 'flex';
                };
                reader.readAsDataURL(file);
            }
        });

        document.getElementById('form-image-url').addEventListener('input', function() {
            const val = this.value.trim();
            if (val && !document.getElementById('form-image-file').files.length) {
                document.getElementById('form-image-preview').src = val;
                document.getElementById('image-preview-name').textContent = 'Image URL';
                document.getElementById('image-preview-meta').textContent = 'Direct link';
                document.getElementById('image-preview-container').style.display = 'flex';
            } else if (!val && !document.getElementById('form-image-file').files.length) {
                document.getElementById('image-preview-container').style.display = 'none';
            }
        });

        // SUBMIT ADD/EDIT FORM
        document.getElementById('equipment-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);

            try {
                const data = await adminEquipPost('admin_save', formData);
                if (data.success) {
                    if (window.playNotifSound) window.playNotifSound('success');
                    Swal.fire({
                        icon: 'success',
                        title: 'Saved',
                        text: data.message,
                        timer: 1500,
                        showConfirmButton: false
                    });
                    closeModal();
                    fetchAdminEquipment();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message || 'Failed to save equipment.'
                    });
                }
            } catch (err) {
                console.error(err);
                Swal.fire({
                    icon: 'error',
                    title: 'Network Error',
                    text: 'Could not communicate with the server.'
                });
            }
        });

        // MAINTENANCE MODAL
        window.openMaintModal = function(equipmentId, fullName) {
            if (!fullName && Array.isArray(equipmentData)) {
                const found = equipmentData.find(e => parseInt(e.equipment_id, 10) === parseInt(equipmentId, 10));
                if (found) fullName = `${found.name} ${found.unit_number}`;
            }
            fullName = fullName || 'Equipment';
            document.getElementById('maint-modal-title').textContent = `Maintenance: ${fullName}`;
            document.getElementById('maint-equip-id').value = equipmentId;
            document.getElementById('maint-status').value = 'maintenance';
            document.getElementById('maint-reason').value = '';
            document.getElementById('maint-return-date').value = '';
            document.getElementById('maint-modal').style.display = 'flex';
        };

        window.closeMaintModal = function() {
            document.getElementById('maint-modal').style.display = 'none';
        };

        document.getElementById('maint-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);

            try {
                const data = await adminEquipPost('admin_set_maintenance', formData);
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Updated',
                        text: data.message,
                        timer: 1500,
                        showConfirmButton: false
                    });
                    closeMaintModal();
                    fetchAdminEquipment();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message || 'Failed to update maintenance.'
                    });
                }
            } catch (err) {
                console.error(err);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while saving maintenance.'
                });
            }
        });

        // RESTORE TO AVAILABLE
        window.restoreAvailable = async function(equipmentId) {
            try {
                const formData = new FormData();
                formData.append('equipment_id', equipmentId);

                const data = await adminEquipPost('admin_restore_available', formData);
                if (data.success) {
                    if (window.playNotifSound) window.playNotifSound('success');
                    Swal.fire({
                        icon: 'success',
                        title: 'Restored',
                        text: data.message,
                        timer: 1500,
                        showConfirmButton: false
                    });
                    fetchAdminEquipment();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Could Not Restore',
                        text: data.message || 'Failed to restore equipment.'
                    });
                }
            } catch (err) {
                console.error(err);
                Swal.fire({
                    icon: 'error',
                    title: 'Network Error',
                    text: 'Could not communicate with the server.'
                });
            }
        };

        // DELETE EQUIPMENT
        window.confirmDeleteEquipment = function(equipmentId, fullName) {
            if (!fullName && Array.isArray(equipmentData)) {
                const found = equipmentData.find(e => parseInt(e.equipment_id, 10) === parseInt(equipmentId, 10));
                if (found) fullName = `${found.name} ${found.unit_number}`;
            }
            fullName = fullName || 'this equipment';
            Swal.fire({
                title: 'Delete Equipment?',
                text: `Are you sure you want to remove ${fullName} from your gym's inventory?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Delete',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b'
            }).then(async (result) => {
                if (result.isConfirmed) {
                    try {
                        const formData = new FormData();
                        formData.append('equipment_id', equipmentId);

                        const data = await adminEquipPost('admin_delete', formData);
                        if (data.success) {
                            if (window.playNotifSound) window.playNotifSound('warning');
                            Swal.fire({
                                icon: 'success',
                                title: 'Removed',
                                text: data.message,
                                timer: 1500,
                                showConfirmButton: false
                            });
                            fetchAdminEquipment();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message || 'Failed to remove equipment.'
                            });
                        }
                    } catch (err) {
                        console.error(err);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An error occurred while removing equipment.'
                        });
                    }
                }
            });
        };

        // QUEUE DETAILS MODAL
        window.openQueueModal = async function(equipmentId, fullName) {
            if (!fullName && Array.isArray(equipmentData)) {
                const found = equipmentData.find(e => parseInt(e.equipment_id, 10) === parseInt(equipmentId, 10));
                if (found) fullName = `${found.name} ${found.unit_number}`;
            }
            fullName = fullName || 'Equipment';
            document.getElementById('queue-modal-title').textContent = fullName;
            document.getElementById('queue-modal').style.display = 'flex';
            document.getElementById('qmodal-session-content').innerHTML = 'Loading session info...';
            document.getElementById('qmodal-queue-list').innerHTML = 'Loading queue list...';

            try {
                const res = await fetch(`index.php?page=equipment_api&action=admin_queue_details&equipment_id=${equipmentId}&gym_id=${GYM_ID}`);
                const data = await res.json();

                if (!data.success) {
                    document.getElementById('qmodal-session-content').innerHTML = 'Could not load details.';
                    return;
                }

                // Render active session
                const sess = data.active_session;
                const eq = data.equipment || {};
                const queue = data.queue || [];
                const notifiedMember = queue.find(q => q.queue_status === 'notified');
                const badge = document.getElementById('qmodal-session-badge');

                if (sess) {
                    badge.textContent = 'In Use';
                    badge.className = 'status-pill status-in_use';

                    const elapsedMins = Math.floor((sess.elapsed_seconds || 0) / 60);
                    document.getElementById('qmodal-session-content').innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <strong style="color: var(--ink);">${escapeHtml(sess.first_name)} ${escapeHtml(sess.last_name)}</strong>
                                <span style="color: var(--muted); font-size: 12px; margin-left: 6px;">(${escapeHtml(sess.email)})</span>
                                <div style="color: var(--muted); font-size: 12px; margin-top: 2px;">
                                    Started: ${new Date(sess.start_time).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})} • Elapsed: ${elapsedMins} mins
                                </div>
                            </div>
                            <button type="button" onclick="adminForceFinishSession(${sess.session_id})" class="btn" style="background: #ef4444; color: #fff; font-size: 12px; padding: 6px 14px; border-radius: 6px; border: none; cursor: pointer;">
                                Force Finish
                            </button>
                        </div>
                    `;
                } else if (eq.status === 'maintenance') {
                    badge.textContent = 'Maintenance';
                    badge.className = 'status-pill status-maintenance';
                    document.getElementById('qmodal-session-content').innerHTML = `<span style="color: #d97706; font-size: 13px;">${escapeHtml(eq.maintenance_reason || 'Scheduled maintenance.')}</span>`;
                } else if (eq.status === 'out_of_service') {
                    badge.textContent = 'Out of Service';
                    badge.className = 'status-pill status-out_of_service';
                    document.getElementById('qmodal-session-content').innerHTML = '<span style="color: #ef4444; font-size: 13px;">Unit is out of service.</span>';
                } else if (notifiedMember) {
                    badge.textContent = 'Reserved (Claiming)';
                    badge.className = 'status-pill status-in_use';
                    const remainingSecs = Math.max(0, parseInt(notifiedMember.claim_seconds_left || 120));
                    document.getElementById('qmodal-session-content').innerHTML = `
                        <div style="color: #059669; font-size: 13px; font-weight: 600;">
                            Reserved for <strong>${escapeHtml(notifiedMember.first_name)} ${escapeHtml(notifiedMember.last_name)}</strong>
                            <span style="color: var(--muted); font-weight: 400; margin-left: 6px;">(~${remainingSecs}s claim window active)</span>
                        </div>
                    `;
                } else {
                    badge.textContent = 'Available';
                    badge.className = 'status-pill status-available';
                    document.getElementById('qmodal-session-content').innerHTML = '<span style="color: var(--muted);">No active member session currently. Ready for use.</span>';
                }

                // Render queue list
                if (queue.length === 0) {
                    document.getElementById('qmodal-queue-list').innerHTML = '<p style="color: var(--muted); font-size: 13px; margin: 8px 0;">No members currently waiting in line.</p>';
                } else {
                    let qHtml = '<ul style="list-style: none; padding: 0; margin: 0;">';
                    queue.forEach(q => {
                        const isNotified = q.queue_status === 'notified';
                        const statusNote = isNotified ? '<span style="color: #059669; font-weight: 700; margin-left: 6px;">[Claiming window active]</span>' : '';
                        qHtml += `
                            <li style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--line); font-size: 13px;">
                                <div>
                                    <strong style="color: var(--lime); margin-right: 6px;">#${q.queue_position}</strong>
                                    <span style="color: var(--ink); font-weight: 600;">${escapeHtml(q.first_name)} ${escapeHtml(q.last_name)}</span>
                                    ${statusNote}
                                    <div style="color: var(--muted); font-size: 11px;">Waiting ${q.minutes_waiting} mins</div>
                                </div>
                                <button type="button" onclick="adminRemoveFromQueue(${q.queue_id})" class="btn-act btn-act-delete" style="font-size: 11px; padding: 4px 8px;" title="Remove from queue">
                                    Remove
                                </button>
                            </li>
                        `;
                    });
                    qHtml += '</ul>';
                    document.getElementById('qmodal-queue-list').innerHTML = qHtml;
                }

            } catch (err) {
                console.error(err);
            }
        };

        window.closeQueueModal = function() {
            document.getElementById('queue-modal').style.display = 'none';
            fetchAdminEquipment();
        };

        window.adminForceFinishSession = function(sessionId) {
            Swal.fire({
                title: 'End Session?',
                text: 'Are you sure you want to end this active session? The machine will immediately be offered to the next waiting member.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'End Session',
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b'
            }).then(async (res) => {
                if (res.isConfirmed) {
                    try {
                        const formData = new FormData();
                        formData.append('session_id', sessionId);
                        const d = await adminEquipPost('admin_force_finish', formData);
                        if (d.success) {
                            closeQueueModal();
                            fetchAdminEquipment();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: d.message || 'Could not finish session.'
                            });
                        }
                    } catch (err) {
                        console.error(err);
                    }
                }
            });
        };

        window.adminRemoveFromQueue = async function(queueId) {
            try {
                const formData = new FormData();
                formData.append('queue_id', queueId);
                const d = await adminEquipPost('admin_remove_queue', formData);
                if (d.success) {
                    closeQueueModal();
                    fetchAdminEquipment();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: d.message || 'Could not remove member from queue.'
                    });
                }
            } catch (err) {
                console.error(err);
            }
        };

        // Filtering events
        document.getElementById('admin-search').addEventListener('input', function() {
            searchQuery = this.value.trim().toLowerCase();
            currentEquipPage = 1;
            renderAdminTable();
        });

        document.getElementById('admin-category-filter').addEventListener('change', function() {
            categoryFilter = this.value;
            currentEquipPage = 1;
            renderAdminTable();
        });

        document.getElementById('admin-status-filter').addEventListener('change', function() {
            statusFilter = this.value;
            currentEquipPage = 1;
            renderAdminTable();
        });

        const pageSizeSelect = document.getElementById('admin-equip-pagesize');
        if (pageSizeSelect) {
            pageSizeSelect.addEventListener('change', function() {
                changeEquipPageSize(this.value);
            });
        }

        function escapeHtml(text) {
            if (!text) return '';
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return String(text).replace(/[&<>"']/g, m => map[m]);
        }

        // Tab Switching Controller (Units vs Overview & Analytics)
        window.switchEquipView = function(view) {
            const btnUnits = document.getElementById('btn-equip-units');
            const btnAnalytics = document.getElementById('btn-equip-analytics');
            const viewUnits = document.getElementById('view-equip-units');
            const viewAnalytics = document.getElementById('view-equip-analytics');

            if (view === 'analytics') {
                btnAnalytics?.classList.add('active');
                btnUnits?.classList.remove('active');
                if (viewAnalytics) viewAnalytics.style.display = 'block';
                if (viewUnits) viewUnits.style.display = 'none';
                try { localStorage.setItem('fittracks_equip_active_tab', 'analytics'); } catch (e) {}
                try {
                    const url = new URL(window.location);
                    url.searchParams.set('tab', 'analytics');
                    history.replaceState(null, '', url.toString());
                } catch (e) {}
            } else {
                btnUnits?.classList.add('active');
                btnAnalytics?.classList.remove('active');
                if (viewUnits) viewUnits.style.display = 'block';
                if (viewAnalytics) viewAnalytics.style.display = 'none';
                try { localStorage.setItem('fittracks_equip_active_tab', 'units'); } catch (e) {}
                try {
                    const url = new URL(window.location);
                    url.searchParams.set('tab', 'units');
                    history.replaceState(null, '', url.toString());
                } catch (e) {}
            }
        };

        // Auto-restore tab preference
        const urlParams = new URLSearchParams(window.location.search);
        const forcedTab = urlParams.get('tab');
        if (forcedTab === 'analytics') {
            window.switchEquipView('analytics');
        } else if (forcedTab === 'units') {
            window.switchEquipView('units');
        } else {
            const savedTab = localStorage.getItem('fittracks_equip_active_tab');
            if (savedTab === 'analytics') {
                window.switchEquipView('analytics');
            }
        }

        fetchAdminEquipment();
    })();
