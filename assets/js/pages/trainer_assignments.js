/**
 * Trainer Assignments Controller
 * Extracted from trainer_assignments.php
 */

const FT_CFG = window.TRAINER_ASSIGNMENTS_CONFIG || {};
const ftCoachesData = FT_CFG.coaches || [];
const ftMembersData = FT_CFG.members || [];
let ftDietMembers = FT_CFG.dietMembers || [];
const ftCsrfToken = FT_CFG.csrfToken || (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
const ftCurrentDate = FT_CFG.currentDate || new Date().toISOString().split('T')[0];

    function addTrainer() {
        Swal.fire({
            title: 'Add Trainer',
            html: `
                <form id="addTrainerForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <input type="hidden" name="csrf_token" value="${ftCsrfToken}">
                    <input type="hidden" name="action" value="create_trainer">
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">First Name *
                        <input name="first_name" class="form-control" style="width: 100%; box-sizing: border-box; text-transform: capitalize;" autocapitalize="words" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())" required>
                    </label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Last Name *
                        <input name="last_name" class="form-control" style="width: 100%; box-sizing: border-box; text-transform: capitalize;" autocapitalize="words" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())" required>
                    </label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Email *
                        <input type="email" name="email" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                    </label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Password *
                        <input type="password" name="password" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                    </label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Specialization *
                        <input name="specialization" class="form-control" placeholder="e.g. Strength & Conditioning" style="width: 100%; box-sizing: border-box;" required>
                    </label>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Create Trainer',
            confirmButtonColor: 'var(--lime-dark)',
            preConfirm: () => {
                const form = document.getElementById('addTrainerForm');
                if (!form.first_name.value || !form.last_name.value || !form.email.value || !form.password.value || !form.specialization.value) {
                    Swal.showValidationMessage('Please fill all required fields');
                    return false;
                }
                form.submit();
            }
        });
    }

// Data loaded from FT_CFG at top of controller

    function addAssignment() {
        let selectedTrainerId = null;
        let selectedMemberIds = [];
        let memberDebounceTimer = null;

        Swal.fire({
            title: 'New Assignment',
            width: '520px',
            html: `
                <form id="addAssignmentForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 14px; margin-top: 15px;">
                    <input type="hidden" name="csrf_token" value="${ftCsrfToken}">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="trainer_id" id="na_trainer_id" value="">
                    <input type="hidden" name="member_user_ids" id="na_member_user_ids" value="">
                    
                    <!-- Activity / Task Title (Optional) -->
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">
                            Activity / Task Title <span style="font-size: 12px; color: var(--muted);">(Optional)</span>
                        </label>
                        <input type="text" name="activity_title" id="na_activity_title" placeholder="e.g. Group Conditioning, Boot Camp, Squad Strength..." autocomplete="off" style="width: 100%; box-sizing: border-box; background-color: var(--panel); color: var(--ink); border: 1px solid var(--line); padding: 10px 12px; border-radius: 8px; font-size: 13.5px; outline: none;">
                        <span style="font-size: 11px; color: var(--muted); margin-top: 4px; display: block;">Leave blank for default assignment title.</span>
                    </div>

                    <!-- Trainer Searchable Combobox -->
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Trainer *</label>
                        <div style="position: relative; width: 100%;">
                            <div id="naTrainerTrigger" style="width: 100%; box-sizing: border-box; padding: 10px 14px; border-radius: 8px; font-size: 14px; background: var(--panel); color: var(--ink); border: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none;">
                                <span id="naTrainerText" style="color: var(--muted); display: flex; align-items: center; gap: 8px;">Select Trainer...</span>
                                <svg id="naTrainerChevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="transition: transform 0.2s; color: var(--muted);"><polyline points="6 9 12 15 18 9"></polyline></svg>
                            </div>
                            <div id="naTrainerMenu" style="display: none; position: absolute; left: 0; right: 0; margin-top: 6px; background: var(--surface); border: 1px solid var(--line); border-radius: 8px; max-height: 220px; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.25); z-index: 1050;">
                                <div style="padding: 8px; border-bottom: 1px solid var(--line); background: var(--panel); position: sticky; top: 0; z-index: 2;">
                                    <input type="text" id="naTrainerSearch" placeholder="Search trainer..." autocomplete="off" style="width: 100%; box-sizing: border-box; padding: 7px 10px; background: var(--bg); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); font-size: 13px; outline: none;">
                                </div>
                                <div id="naTrainerList" style="padding: 4px;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Member Multi-Select Combobox -->
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <label style="color: var(--muted); font-size: 13.5px; font-weight: 500;">Assign Member(s) *</label>
                            <span id="naMemberCountBadge" style="font-size: 11px; padding: 2px 8px; border-radius: 10px; background: rgba(132, 204, 22, 0.15); color: var(--lime); font-weight: 700; border: 1px solid rgba(132, 204, 22, 0.3);">
                                0 selected
                            </span>
                        </div>

                        <!-- Selected Chips Box -->
                        <div id="naSelectedChips" style="min-height: 42px; max-height: 90px; overflow-y: auto; padding: 6px 8px; background: var(--panel); border: 1px solid var(--line); border-radius: 8px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 8px;">
                            <span id="naSelectedChipsEmpty" style="color: var(--muted); font-size: 12px; padding: 2px 4px;">No members selected yet. Search and check members below.</span>
                        </div>

                        <!-- Search and Quick Actions -->
                        <div style="display: flex; gap: 6px; margin-bottom: 6px; align-items: center;">
                            <div style="position: relative; flex: 1;">
                                <input type="text" id="naMemberSearch" placeholder="Search members to select..." autocomplete="off" style="width: 100%; box-sizing: border-box; padding: 7px 10px; background: var(--bg); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); font-size: 13px; outline: none;">
                            </div>
                            <button type="button" id="naSelectAllBtn" class="btn-sm btn-ghost" style="padding: 6px 9px; font-size: 11.5px; border: 1px solid var(--line); font-weight: 600; white-space: nowrap; cursor: pointer;">
                                Select All
                            </button>
                            <button type="button" id="naClearAllBtn" class="btn-sm btn-ghost" style="padding: 6px 9px; font-size: 11.5px; border: 1px solid var(--line); color: var(--muted); font-weight: 600; white-space: nowrap; cursor: pointer;">
                                Clear
                            </button>
                        </div>

                        <!-- Member Checklist -->
                        <div id="naMemberList" style="max-height: 175px; overflow-y: auto; background: var(--surface); border: 1px solid var(--line); border-radius: 8px; padding: 4px;"></div>
                    </div>
                    
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Assigned date *</label>
                        <input type="date" name="assigned_date" class="form-control" value="${ftCurrentDate}" style="width: 100%; box-sizing: border-box; background-color: var(--panel); color: var(--ink); border: 1px solid var(--line); padding: 10px 12px; border-radius: 8px; font-size: 13.5px; color-scheme: dark light;" required>
                    </div>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Assign Members',
            confirmButtonColor: 'var(--lime-dark)',
            didOpen: () => {
                // Trainer Combobox Logic
                const tTrigger = document.getElementById('naTrainerTrigger');
                const tMenu = document.getElementById('naTrainerMenu');
                const tChevron = document.getElementById('naTrainerChevron');
                const tSearch = document.getElementById('naTrainerSearch');
                const tList = document.getElementById('naTrainerList');
                const tHidden = document.getElementById('na_trainer_id');
                const tText = document.getElementById('naTrainerText');

                function renderTrainers(query = '') {
                    const q = query.trim().toLowerCase();
                    const filtered = ftCoachesData.filter(c => c.name.toLowerCase().includes(q));
                    if (filtered.length === 0) {
                        tList.innerHTML = '<div style="padding: 12px; text-align: center; color: var(--muted); font-size: 13px;">No trainers found</div>';
                        return;
                    }
                    tList.innerHTML = filtered.map(c => `
                        <div class="na-trainer-item" data-id="${c.id}" data-name="${encodeURIComponent(c.name)}" data-ini="${c.initials}"
                             style="padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; border-radius: 6px; cursor: pointer; margin-bottom: 2px; transition: background 0.15s; background: ${selectedTrainerId === c.id ? 'rgba(132, 204, 22, 0.15)' : 'transparent'};">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="width: 28px; height: 28px; border-radius: 50%; background: var(--panel-soft); color: var(--lime); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; border: 1px solid var(--line);">
                                    ${c.initials}
                                </div>
                                <span style="color: var(--ink); font-size: 13px; font-weight: 500;">${c.name}</span>
                            </div>
                            ${selectedTrainerId === c.id ? '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}
                        </div>
                    `).join('');

                    tList.querySelectorAll('.na-trainer-item').forEach(el => {
                        el.addEventListener('mouseenter', () => { if (parseInt(el.getAttribute('data-id'), 10) !== selectedTrainerId) el.style.background = 'var(--panel-soft)'; });
                        el.addEventListener('mouseleave', () => { if (parseInt(el.getAttribute('data-id'), 10) !== selectedTrainerId) el.style.background = 'transparent'; });
                        el.addEventListener('click', () => {
                            selectedTrainerId = parseInt(el.getAttribute('data-id'), 10);
                            tHidden.value = selectedTrainerId;
                            const name = decodeURIComponent(el.getAttribute('data-name'));
                            const ini = el.getAttribute('data-ini');
                            tText.innerHTML = `
                                <span style="width: 22px; height: 22px; border-radius: 50%; background: var(--panel-soft); color: var(--lime); display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; border: 1px solid var(--line);">${ini}</span>
                                <span style="color: var(--ink); font-weight: 600;">${name}</span>
                            `;
                            tMenu.style.display = 'none';
                            tChevron.style.transform = 'rotate(0deg)';
                            tTrigger.style.borderColor = 'var(--lime)';
                        });
                    });
                }

                renderTrainers();

                tTrigger.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isOpen = tMenu.style.display === 'block';
                    tMenu.style.display = isOpen ? 'none' : 'block';
                    tChevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
                    if (!isOpen && tSearch) setTimeout(() => tSearch.focus(), 50);
                });

                if (tSearch) {
                    tSearch.addEventListener('input', (e) => renderTrainers(e.target.value));
                    tSearch.addEventListener('click', (e) => e.stopPropagation());
                }

                // Member Multi-Select Logic
                const mSearch = document.getElementById('naMemberSearch');
                const mList = document.getElementById('naMemberList');
                const mChipsBox = document.getElementById('naSelectedChips');
                const mChipsEmpty = document.getElementById('naSelectedChipsEmpty');
                const mCountBadge = document.getElementById('naMemberCountBadge');
                const mHidden = document.getElementById('na_member_user_ids');
                const btnSelectAll = document.getElementById('naSelectAllBtn');
                const btnClearAll = document.getElementById('naClearAllBtn');
                const confirmBtn = Swal.getConfirmButton();

                function updateSelectedChips() {
                    mHidden.value = selectedMemberIds.join(',');
                    mCountBadge.textContent = selectedMemberIds.length + ' selected';
                    
                    if (confirmBtn) {
                        confirmBtn.textContent = selectedMemberIds.length > 0 
                            ? `Assign (${selectedMemberIds.length}) Member${selectedMemberIds.length > 1 ? 's' : ''}` 
                            : 'Assign Members';
                    }

                    if (selectedMemberIds.length === 0) {
                        mChipsBox.innerHTML = '<span id="naSelectedChipsEmpty" style="color: var(--muted); font-size: 12px; padding: 2px 4px;">No members selected yet. Search and check members below.</span>';
                        const existingWarnBox = document.getElementById('naDoubleAssignWarning');
                        if (existingWarnBox) existingWarnBox.remove();
                        return;
                    }

                    mChipsBox.innerHTML = selectedMemberIds.map(id => {
                        const m = ftMembersData.find(item => item.id === id) || { name: 'Member #' + id, initials: 'M' };
                        const displayName = m.full_name || m.name.split(' (')[0];
                        return `
                            <span class="na-chip" style="display: inline-flex; align-items: center; gap: 5px; background: rgba(132, 204, 22, 0.15); border: 1px solid rgba(132, 204, 22, 0.3); color: var(--ink); font-size: 12px; font-weight: 500; padding: 2px 8px; border-radius: 14px;">
                                <span style="width: 18px; height: 18px; border-radius: 50%; background: var(--panel-soft); color: var(--lime); display: inline-flex; align-items: center; justify-content: center; font-size: 9px; font-weight: 700;">${m.initials}</span>
                                <span>${displayName}</span>
                                <button type="button" class="na-chip-remove" data-id="${id}" style="background: none; border: none; color: var(--muted); cursor: pointer; padding: 0 2px; font-size: 13px; line-height: 1; display: inline-flex; align-items: center;" title="Remove">✕</button>
                            </span>
                        `;
                    }).join('');

                    // Display gentle warning callout if any selected members are already active with another coach
                    const warningMembers = selectedMemberIds
                        .map(id => ftMembersData.find(item => item.id === id))
                        .filter(m => m && m.active_trainer);

                    const existingWarnBox = document.getElementById('naDoubleAssignWarning');
                    if (warningMembers.length > 0) {
                        const warnNames = warningMembers.map(m => m.full_name || m.name.split(' (')[0]).join(', ');
                        const warnHtml = `
                            <div id="naDoubleAssignWarning" style="margin-bottom: 8px; padding: 7px 11px; background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 8px; font-size: 11.5px; color: #d97706; display: flex; align-items: flex-start; gap: 7px; line-height: 1.4;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink: 0; margin-top: 2px;"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                <div><strong>Multiple Trainers Note:</strong> ${warnNames} already active with another coach. Proceeding will assign them to both trainers.</div>
                            </div>
                        `;
                        if (existingWarnBox) {
                            existingWarnBox.outerHTML = warnHtml;
                        } else {
                            mChipsBox.insertAdjacentHTML('afterend', warnHtml);
                        }
                    } else if (existingWarnBox) {
                        existingWarnBox.remove();
                    }

                    mChipsBox.querySelectorAll('.na-chip-remove').forEach(btn => {
                        btn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            const rid = parseInt(btn.getAttribute('data-id'), 10);
                            selectedMemberIds = selectedMemberIds.filter(id => id !== rid);
                            updateSelectedChips();
                            renderMemberList(mSearch.value);
                        });
                    });
                }

                function renderMemberList(query = '') {
                    const q = query.trim().toLowerCase();
                    const filtered = ftMembersData.filter(m => m.name.toLowerCase().includes(q));

                    if (filtered.length === 0) {
                        mList.innerHTML = `<div style="padding: 12px; text-align: center; color: var(--muted); font-size: 13px;">No members found matching "${q}"</div>`;
                    } else {
                        mList.innerHTML = filtered.map(m => {
                            const isChecked = selectedMemberIds.includes(m.id);
                            return `
                                <div class="na-member-row" data-id="${m.id}"
                                     style="padding: 7px 10px; display: flex; align-items: center; justify-content: space-between; border-radius: 6px; cursor: pointer; margin-bottom: 2px; transition: background 0.15s; background: ${isChecked ? 'rgba(132, 204, 22, 0.12)' : 'transparent'};">
                                    <div style="display: flex; align-items: center; gap: 9px; min-width: 0; flex: 1;">
                                        <input type="checkbox" class="na-member-checkbox" data-id="${m.id}" ${isChecked ? 'checked' : ''} style="cursor: pointer; width: 15px; height: 15px; accent-color: var(--lime); flex-shrink: 0;">
                                        <div style="width: 26px; height: 26px; border-radius: 50%; background: var(--panel-soft); color: var(--lime); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; border: 1px solid var(--line); flex-shrink: 0;">
                                            ${m.initials}
                                        </div>
                                        <div style="min-width: 0; flex: 1;">
                                            <div style="color: var(--ink); font-size: 13px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                ${m.name}
                                            </div>
                                            ${m.active_trainer ? `
                                                <div style="font-size: 10.5px; color: #d97706; display: flex; align-items: center; gap: 4px; margin-top: 1px;">
                                                    <span style="display: inline-block; width: 5px; height: 5px; border-radius: 50%; background: #d97706; flex-shrink: 0;"></span>
                                                    <span>Already with ${m.active_trainer}</span>
                                                </div>
                                            ` : ''}
                                        </div>
                                    </div>
                                    <span style="font-size: 11px; color: ${m.has_plan ? '#22c55e' : 'var(--muted)'}; font-weight: 600; flex-shrink: 0; margin-left: 8px;">
                                        ${m.has_plan ? 'Active Plan' : '1 Day'}
                                    </span>
                                </div>
                            `;
                        }).join('');

                        mList.querySelectorAll('.na-member-row').forEach(row => {
                            const id = parseInt(row.getAttribute('data-id'), 10);
                            row.addEventListener('mouseenter', () => { if (!selectedMemberIds.includes(id)) row.style.background = 'var(--panel-soft)'; });
                            row.addEventListener('mouseleave', () => { if (!selectedMemberIds.includes(id)) row.style.background = 'transparent'; });
                            row.addEventListener('click', (e) => {
                                if (e.target.tagName !== 'INPUT') {
                                    const cb = row.querySelector('.na-member-checkbox');
                                    cb.checked = !cb.checked;
                                }
                                const isChecked = row.querySelector('.na-member-checkbox').checked;
                                if (isChecked && !selectedMemberIds.includes(id)) {
                                    selectedMemberIds.push(id);
                                } else if (!isChecked) {
                                    selectedMemberIds = selectedMemberIds.filter(item => item !== id);
                                }
                                updateSelectedChips();
                                row.style.background = isChecked ? 'rgba(132, 204, 22, 0.12)' : 'transparent';
                            });
                        });
                    }

                    // Debounced server search fallback
                    if (q) {
                        if (memberDebounceTimer) clearTimeout(memberDebounceTimer);
                        memberDebounceTimer = setTimeout(() => {
                            fetch('index.php?page=trainer_assignments&action=search_assign_data&type=member&q=' + encodeURIComponent(q), {
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (mSearch.value.trim().toLowerCase() !== q) return;
                                const results = data.results || [];
                                results.forEach(rm => {
                                    if (!ftMembersData.some(m => m.id === rm.id)) {
                                        ftMembersData.push(rm);
                                    }
                                });
                                renderMemberList(q);
                            })
                            .catch(err => console.error('Member search error', err));
                        }, 250);
                    }
                }

                renderMemberList();
                updateSelectedChips();

                mSearch.addEventListener('input', (e) => renderMemberList(e.target.value));

                btnSelectAll.addEventListener('click', () => {
                    const q = mSearch.value.trim().toLowerCase();
                    const filtered = ftMembersData.filter(m => m.name.toLowerCase().includes(q));
                    filtered.forEach(m => {
                        if (!selectedMemberIds.includes(m.id)) selectedMemberIds.push(m.id);
                    });
                    updateSelectedChips();
                    renderMemberList(mSearch.value);
                });

                btnClearAll.addEventListener('click', () => {
                    selectedMemberIds = [];
                    updateSelectedChips();
                    renderMemberList(mSearch.value);
                });

                document.addEventListener('click', function closeMenus(e) {
                    if (tTrigger && tMenu && !tTrigger.contains(e.target) && !tMenu.contains(e.target)) {
                        tMenu.style.display = 'none';
                        tChevron.style.transform = 'rotate(0deg)';
                    }
                });
            },
            preConfirm: () => {
                const form = document.getElementById('addAssignmentForm');
                if (!form.trainer_id.value) {
                    Swal.showValidationMessage('Please select a trainer');
                    return false;
                }
                if (selectedMemberIds.length === 0) {
                    Swal.showValidationMessage('Please select at least one member');
                    return false;
                }
                if (!form.assigned_date.value) {
                    Swal.showValidationMessage('Please select an assigned date');
                    return false;
                }
                form.submit();
            }
        });
    }

    // ── Manage Members in an Existing Assignment ──────────────────────────
    function manageGroupMembers(groupId) {
        Swal.fire({
            title: 'Loading Assignment Details...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
                fetch('index.php?page=trainer_assignments&action=get_group_details&group_id=' + encodeURIComponent(groupId), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        Swal.fire('Error', data.error || 'Failed to load assignment details', 'error');
                        return;
                    }
                    showGroupMembersModal(data);
                })
                .catch(err => {
                    console.error('Group details error', err);
                    Swal.fire('Error', 'Unable to retrieve assignment information.', 'error');
                });
            }
        });
    }

    function showGroupMembersModal(data) {
        let currentMembers = data.members || [];
        let availableMembers = data.available_members || [];
        let selectedAddIds = [];
        let hasModifiedMembers = false;

        Swal.fire({
            title: 'Manage Assignment Members',
            width: 'min(94vw, 580px)',
            html: `
<!-- Group Member Styles loaded in trainer_assignments.css -->
                <div style="text-align: left; margin-top: 10px;">
                    <!-- Header info card -->
                    <div style="padding: 10px 14px; background: var(--panel); border: 1px solid var(--line); border-radius: 8px; margin-bottom: 14px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: var(--ink);">${data.activity_title}</h4>
                            <span style="font-size: 11.5px; color: var(--muted);">${data.assigned_date}</span>
                        </div>
                        <div style="font-size: 13px; color: var(--muted);">
                            Trainer: <strong style="color: var(--ink);">${data.trainer_name}</strong> • ${data.specialization}
                        </div>
                    </div>

                    <!-- Inline Notification Alert Banner -->
                    <div id="gmmAlertBox" style="display: none; padding: 9px 12px; border-radius: 8px; font-size: 12.5px; font-weight: 600; margin-bottom: 14px; align-items: center; gap: 8px; transition: all 0.3s ease;"></div>

                    <!-- Assigned Members List -->
                    <div style="margin-bottom: 18px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; gap: 8px;">
                            <div style="font-size: 13.5px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 7px;">
                                <span>Assigned Members</span>
                                <span id="gmmCountBadge" style="font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px; background: rgba(132, 204, 22, 0.15); color: var(--lime); border: 1px solid rgba(132, 204, 22, 0.3); display: inline-flex; align-items: center; justify-content: center; line-height: 1;">${currentMembers.length}</span>
                            </div>
                            <span class="gmm-sub-text" style="font-size: 11.5px; color: var(--muted);">Individual status and actions</span>
                        </div>
                        
                        <div id="gmmCurrentList" style="max-height: 220px; overflow-y: auto; background: var(--surface); border: 1px solid var(--line); border-radius: 8px; padding: 4px;">
                        </div>
                    </div>

                    <!-- Add More Members Section -->
                    <div style="padding-top: 12px; border-top: 1px solid var(--line);">
                        <label style="font-size: 13px; font-weight: 700; color: var(--ink); margin-bottom: 8px; display: block;">
                            Add Members to Assignment
                        </label>

                        <div style="display: flex; gap: 8px; margin-bottom: 8px; flex-wrap: wrap;">
                            <input type="text" id="gmmAvailSearch" placeholder="Search gym members to add..." autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault();event.stopPropagation();}" style="flex: 1; min-width: 160px; box-sizing: border-box; padding: 7px 10px; background: var(--bg); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); font-size: 13px; outline: none;">
                            <button type="button" id="gmmAddSelectedBtn" onclick="event.preventDefault(); event.stopPropagation();" class="btn-sm" style="background: var(--lime); color: var(--bg); font-weight: 700; padding: 0 14px; white-space: nowrap; opacity: 0.5; cursor: not-allowed; border: none; transition: all 0.2s;" disabled>
                                Add Member(s)
                            </button>
                        </div>

                        <div id="gmmAvailList" style="max-height: 140px; overflow-y: auto; background: var(--surface); border: 1px solid var(--line); border-radius: 8px; padding: 4px;">
                        </div>
                    </div>

                    <!-- Bottom Action Controls -->
                    <div style="margin-top: 20px; display: flex; justify-content: center; align-items: center; border-top: 1px solid var(--line); padding-top: 16px;">
                        <button type="button" id="gmmDoneBtn" onclick="event.preventDefault(); event.stopPropagation();" style="background: var(--lime); color: var(--bg); font-weight: 700; padding: 10px 42px; font-size: 14px; border-radius: 8px; border: none; cursor: pointer; transition: opacity 0.2s; min-width: 140px;">
                            Done
                        </button>
                    </div>
                </div>
            `,
            showConfirmButton: false,
            showCancelButton: false,
            showCloseButton: true,
            allowOutsideClick: false,
            allowEscapeKey: false,
            willClose: () => {
                if (hasModifiedMembers) {
                    window.location.reload();
                }
            },
            didOpen: () => {
                const alertBox = document.getElementById('gmmAlertBox');
                const currentList = document.getElementById('gmmCurrentList');
                const countBadge = document.getElementById('gmmCountBadge');
                const availSearch = document.getElementById('gmmAvailSearch');
                const availList = document.getElementById('gmmAvailList');
                const addBtn = document.getElementById('gmmAddSelectedBtn');
                const doneBtn = document.getElementById('gmmDoneBtn');

                if (doneBtn) {
                    doneBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        Swal.close();
                    });
                }

                let alertTimer = null;
                function showAlert(msg, isSuccess = true) {
                    if (!alertBox) return;
                    if (alertTimer) clearTimeout(alertTimer);
                    alertBox.style.display = 'flex';
                    alertBox.style.background = isSuccess ? 'rgba(34, 197, 94, 0.12)' : 'rgba(239, 68, 68, 0.12)';
                    alertBox.style.color = isSuccess ? '#22c55e' : '#ef4444';
                    alertBox.style.border = isSuccess ? '1px solid rgba(34, 197, 94, 0.3)' : '1px solid rgba(239, 68, 68, 0.3)';
                    alertBox.innerHTML = `
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <span>${msg}</span>
                    `;
                    alertTimer = setTimeout(() => {
                        if (alertBox) alertBox.style.display = 'none';
                    }, 4000);
                }

                function renderCurrentList() {
                    if (countBadge) countBadge.textContent = currentMembers.length;
                    if (!currentList) return;

                    if (currentMembers.length === 0) {
                        currentList.innerHTML = '<div style="padding: 16px; text-align: center; color: var(--muted); font-size: 13px;">No members currently in this assignment.</div>';
                        return;
                    }

                    currentList.innerHTML = currentMembers.map(m => {
                        const statusBadge = m.status === 'active' 
                            ? '<span class="badge badge-active" style="font-size: 10px; padding: 2px 7px;">Active</span>' 
                            : `<span class="badge" style="background: var(--panel-soft); color: var(--muted); font-size: 10px; padding: 2px 7px;">${m.status}</span>`;
                        return `
                            <div class="gmm-member-item">
                                <div class="gmm-member-info">
                                    <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--panel-soft); color: var(--lime); display: flex; align-items: center; justify-content: center; font-size: 10.5px; font-weight: 700; border: 1px solid var(--line); flex-shrink: 0;">
                                        ${m.initials}
                                    </div>
                                    <div style="min-width: 0; flex: 1;">
                                        <div style="font-size: 13.5px; font-weight: 600; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                            ${m.full_name}
                                        </div>
                                        <div style="font-size: 11.5px; color: var(--muted); display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                            ${m.has_plan ? '<span style="color:#22c55e; font-weight: 600;">Has Plan</span>' : '<span>1 Day</span>'}
                                            <span style="opacity: 0.4;">•</span>
                                            <span>Assigned: ${m.assigned_date}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="gmm-member-controls">
                                    <div style="display: flex; align-items: center;">
                                        ${statusBadge}
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <a href="index.php?page=diet_builder&member_user_id=${m.user_id}&ref=trainer_assignments" class="btn-sm btn-ghost" style="padding: 4px 10px; font-size: 11px; text-decoration: none; border: 1px solid var(--line); color: var(--ink); font-weight: 600; border-radius: 6px;" title="Diet Plan">
                                            Diet
                                        </a>
                                        <button type="button" class="btn-sm btn-danger gmm-remove-btn" onclick="event.preventDefault(); event.stopPropagation();" data-assign-id="${m.assignment_id}" data-name="${encodeURIComponent(m.full_name)}" style="padding: 4px 10px; font-size: 11px; font-weight: 600; border-radius: 6px;" title="Remove from assignment">
                                            Remove
                                        </button>
                                    </div>
                                </div>
                            </div>
                        `;
                    }).join('');

                    // Bind remove buttons
                    currentList.querySelectorAll('.gmm-remove-btn').forEach(btn => {
                        btn.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            const assignId = parseInt(btn.getAttribute('data-assign-id'), 10);
                            const name = decodeURIComponent(btn.getAttribute('data-name'));

                            if (!confirm(`Are you sure you want to remove ${name} from this assignment?`)) {
                                return;
                            }

                            btn.disabled = true;
                            btn.textContent = 'Removing...';

                            const formData = new FormData();
                            formData.append('action', 'remove_group_member');
                            formData.append('assignment_id', assignId);
                            formData.append('csrf_token', ftCsrfToken);
                            formData.append('is_ajax', '1');

                            fetch('index.php?page=trainer_assignments', {
                                method: 'POST',
                                headers: { 
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': ftCsrfToken
                                },
                                body: formData
                            })
                            .then(r => r.json())
                            .then(resp => {
                                if (resp.success) {
                                    hasModifiedMembers = true;
                                    showAlert(`Removed ${name} from assignment.`);
                                    // Re-fetch and update lists in place
                                    fetch('index.php?page=trainer_assignments&action=get_group_details&group_id=' + encodeURIComponent(data.group_id), {
                                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                                    })
                                    .then(r => r.json())
                                    .then(updatedData => {
                                        data = updatedData;
                                        currentMembers = updatedData.members || [];
                                        availableMembers = updatedData.available_members || [];
                                        selectedAddIds = [];
                                        renderCurrentList();
                                        renderAvailList(availSearch ? availSearch.value : '');
                                    });
                                } else {
                                    btn.disabled = false;
                                    btn.textContent = 'Remove';
                                    showAlert(resp.message || resp.error || 'Failed to remove member', false);
                                }
                            })
                            .catch(err => {
                                btn.disabled = false;
                                btn.textContent = 'Remove';
                                console.error('Remove member error', err);
                                showAlert('Failed to remove member', false);
                            });
                        });
                    });
                }

                function renderAvailList(q = '') {
                    const term = q.trim().toLowerCase();
                    const filtered = availableMembers.filter(m => m.name.toLowerCase().includes(term));

                    if (filtered.length === 0) {
                        availList.innerHTML = `<div style="padding: 12px; text-align: center; color: var(--muted); font-size: 12.5px;">${availableMembers.length === 0 ? 'All eligible gym members are already in this assignment.' : 'No matching members found'}</div>`;
                        return;
                    }

                    availList.innerHTML = filtered.map(m => {
                        const isChecked = selectedAddIds.includes(m.id);
                        return `
                            <div class="gmm-avail-row" data-id="${m.id}" style="padding: 6px 10px; display: flex; align-items: center; justify-content: space-between; border-radius: 6px; cursor: pointer; margin-bottom: 2px; background: ${isChecked ? 'rgba(132, 204, 22, 0.12)' : 'transparent'};">
                                <div style="display: flex; align-items: center; gap: 8px; min-width: 0; flex: 1;">
                                    <input type="checkbox" class="gmm-avail-cb" data-id="${m.id}" ${isChecked ? 'checked' : ''} style="cursor: pointer; width: 14px; height: 14px; accent-color: var(--lime); flex-shrink: 0;">
                                    <div style="min-width: 0; flex: 1;">
                                        <div style="font-size: 12.5px; font-weight: 500; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${m.name}</div>
                                        ${m.active_trainer ? `
                                            <div style="font-size: 10.5px; color: #d97706; display: flex; align-items: center; gap: 3px; margin-top: 1px;">
                                                <span style="display: inline-block; width: 5px; height: 5px; border-radius: 50%; background: #d97706; flex-shrink: 0;"></span>
                                                <span>Active with ${m.active_trainer}</span>
                                            </div>
                                        ` : ''}
                                    </div>
                                </div>
                                <span style="font-size: 11px; color: ${m.has_plan ? '#22c55e' : 'var(--muted)'}; flex-shrink: 0; margin-left: 8px;">
                                    ${m.has_plan ? 'Has Plan' : '1 Day'}
                                </span>
                            </div>
                        `;
                    }).join('');

                    availList.querySelectorAll('.gmm-avail-row').forEach(row => {
                        const id = parseInt(row.getAttribute('data-id'), 10);
                        row.addEventListener('click', (e) => {
                            e.stopPropagation();
                            if (e.target.tagName !== 'INPUT') {
                                const cb = row.querySelector('.gmm-avail-cb');
                                cb.checked = !cb.checked;
                            }
                            const isChecked = row.querySelector('.gmm-avail-cb').checked;
                            if (isChecked && !selectedAddIds.includes(id)) {
                                selectedAddIds.push(id);
                            } else if (!isChecked) {
                                selectedAddIds = selectedAddIds.filter(x => x !== id);
                            }
                            addBtn.disabled = selectedAddIds.length === 0;
                            addBtn.style.opacity = selectedAddIds.length > 0 ? '1' : '0.5';
                            addBtn.style.cursor = selectedAddIds.length > 0 ? 'pointer' : 'not-allowed';
                            addBtn.textContent = selectedAddIds.length > 0 ? `Add (${selectedAddIds.length})` : 'Add Member(s)';
                            row.style.background = isChecked ? 'rgba(132, 204, 22, 0.12)' : 'transparent';
                        });
                    });
                }

                renderCurrentList();
                renderAvailList();

                availSearch.addEventListener('input', (e) => renderAvailList(e.target.value));

                addBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (selectedAddIds.length === 0) return;
                    addBtn.disabled = true;
                    addBtn.textContent = 'Adding...';

                    const formData = new FormData();
                    formData.append('action', 'add_members_to_group');
                    formData.append('group_id', data.group_id);
                    formData.append('member_user_ids', selectedAddIds.join(','));
                    formData.append('csrf_token', ftCsrfToken);
                    formData.append('is_ajax', '1');

                    fetch('index.php?page=trainer_assignments', {
                        method: 'POST',
                        headers: { 
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': ftCsrfToken
                        },
                        body: formData
                    })
                    .then(r => r.json())
                    .then(resp => {
                        if (resp.success) {
                            hasModifiedMembers = true;
                            if (resp.group_id) {
                                data.group_id = resp.group_id;
                            }
                            showAlert(resp.message || 'Member(s) added successfully.');
                            addBtn.textContent = 'Added ✓';
                            addBtn.style.background = '#22c55e';
                            addBtn.style.color = '#ffffff';

                            fetch('index.php?page=trainer_assignments&action=get_group_details&group_id=' + encodeURIComponent(data.group_id), {
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            })
                            .then(r => r.json())
                            .then(updatedData => {
                                data = updatedData;
                                currentMembers = updatedData.members || [];
                                availableMembers = updatedData.available_members || [];
                                selectedAddIds = [];
                                setTimeout(() => {
                                    if (addBtn) {
                                        addBtn.disabled = true;
                                        addBtn.style.opacity = '0.5';
                                        addBtn.style.cursor = 'not-allowed';
                                        addBtn.style.background = 'var(--lime)';
                                        addBtn.style.color = 'var(--bg)';
                                        addBtn.textContent = 'Add Member(s)';
                                    }
                                }, 1200);
                                renderCurrentList();
                                renderAvailList(availSearch ? availSearch.value : '');
                            });
                        } else {
                            addBtn.disabled = false;
                            addBtn.textContent = 'Add Member(s)';
                            showAlert(resp.message || resp.error || 'Failed to add members', false);
                        }
                    })
                    .catch(err => {
                        addBtn.disabled = false;
                        addBtn.textContent = 'Add Member(s)';
                        console.error('Add member error', err);
                        showAlert('Failed to add members', false);
                    });
                });
            }
        });
    }

    function openDietPlanSelector() {
        let selectedId = null;
        let selectedName = '';
        let dietDebounceTimer = null;

        Swal.fire({
            title: 'Member Diet Plan',
            width: '460px',
            html: `
                <div style="text-align: left; margin-top: 15px;">
                    <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 8px; font-weight: 500;">
                        Select a member to view or edit their meal plan:
                    </label>
                    <div style="position: relative; width: 100%;">
                        <div id="ftCustomSelectTrigger" style="width: 100%; box-sizing: border-box; padding: 11px 14px; border-radius: 8px; font-size: 14px; background: var(--panel); color: var(--ink); border: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none;">
                            <span id="ftSelectedMemberText" style="color: var(--muted); display: flex; align-items: center; gap: 8px;">Select Member...</span>
                            <svg id="ftSelectChevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="transition: transform 0.2s; color: var(--muted);"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </div>
                        <div id="ftCustomDropdownMenu" style="display: none; width: 100%; box-sizing: border-box; margin-top: 6px; background: var(--surface); border: 1px solid var(--line); border-radius: 8px; max-height: 230px; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.25); z-index: 1050;">
                            <div style="padding: 8px; border-bottom: 1px solid var(--line); background: var(--panel); position: sticky; top: 0; z-index: 2;">
                                <input type="text" id="ftMemberSearch" placeholder="Search member..." autocomplete="off" style="width: 100%; box-sizing: border-box; padding: 8px 12px; background: var(--bg); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); font-size: 13px; outline: none;">
                            </div>
                            <div id="ftMemberListContainer" style="padding: 4px;"></div>
                        </div>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Open Diet Plan',
            confirmButtonColor: 'var(--lime-dark)',
            didOpen: () => {
                const trigger = document.getElementById('ftCustomSelectTrigger');
                const menu = document.getElementById('ftCustomDropdownMenu');
                const chevron = document.getElementById('ftSelectChevron');
                const listContainer = document.getElementById('ftMemberListContainer');
                const searchInput = document.getElementById('ftMemberSearch');

                function bindDietClicks() {
                    listContainer.querySelectorAll('.ft-member-item').forEach(el => {
                        el.addEventListener('mouseenter', () => {
                            if (parseInt(el.getAttribute('data-id'), 10) !== selectedId) el.style.background = 'var(--panel-soft)';
                        });
                        el.addEventListener('mouseleave', () => {
                            if (parseInt(el.getAttribute('data-id'), 10) !== selectedId) el.style.background = 'transparent';
                        });
                        el.addEventListener('click', () => {
                            selectedId = parseInt(el.getAttribute('data-id'), 10);
                            selectedName = decodeURIComponent(el.getAttribute('data-name'));
                            const ini = el.getAttribute('data-initials');

                            const label = document.getElementById('ftSelectedMemberText');
                            if (label) {
                                label.innerHTML = `
                                    <span style="width: 22px; height: 22px; border-radius: 50%; background: var(--panel-soft); color: var(--lime); display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; border: 1px solid var(--line);">${ini}</span>
                                    <span style="color: var(--ink); font-weight: 600;">${selectedName}</span>
                                `;
                            }
                            menu.style.display = 'none';
                            chevron.style.transform = 'rotate(0deg)';
                            trigger.style.borderColor = 'var(--lime)';
                        });
                    });
                }

                function renderMembers(query = '') {
                    const q = query.trim().toLowerCase();
                    const filtered = ftDietMembers.filter(m => m.name.toLowerCase().includes(q));
                    
                    if (filtered.length === 0) {
                        listContainer.innerHTML = '<div style="padding: 14px; text-align: center; color: var(--muted); font-size: 13px;">Searching members...</div>';
                    } else {
                        listContainer.innerHTML = filtered.map(m => {
                            const isSelected = selectedId === m.id;
                            return `
                                <div class="ft-member-item" data-id="${m.id}" data-name="${encodeURIComponent(m.name)}" data-initials="${m.initials}"
                                     style="padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; border-radius: 6px; cursor: pointer; margin-bottom: 2px; transition: background 0.15s; background: ${isSelected ? 'rgba(132, 204, 22, 0.15)' : 'transparent'};">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="width: 30px; height: 30px; border-radius: 50%; background: var(--panel-soft); color: var(--lime); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; border: 1px solid var(--line);">
                                            ${m.initials}
                                        </div>
                                        <span style="color: var(--ink); font-size: 13.5px; font-weight: 600;">${m.name}</span>
                                    </div>
                                    ${isSelected ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}
                                </div>
                            `;
                        }).join('');

                        bindDietClicks();
                    }

                    // Hybrid debounced server search
                    if (q) {
                        if (dietDebounceTimer) clearTimeout(dietDebounceTimer);
                        dietDebounceTimer = setTimeout(() => {
                            fetch('index.php?page=trainer_assignments&action=search_assign_data&type=member&q=' + encodeURIComponent(q), {
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (searchInput.value.trim().toLowerCase() !== q) return;
                                const results = data.results || [];
                                results.forEach(rm => {
                                    if (!ftDietMembers.some(m => m.id === rm.id)) {
                                        ftDietMembers.push({ id: rm.id, name: rm.full_name, initials: rm.initials });
                                    }
                                });
                                const updatedFiltered = ftDietMembers.filter(m => m.name.toLowerCase().includes(q));
                                if (updatedFiltered.length === 0) {
                                    listContainer.innerHTML = `<div style="padding: 14px; text-align: center; color: var(--muted); font-size: 13px;">No members found matching "${q}"</div>`;
                                } else {
                                    listContainer.innerHTML = updatedFiltered.map(m => {
                                        const isSelected = selectedId === m.id;
                                        return `
                                            <div class="ft-member-item" data-id="${m.id}" data-name="${encodeURIComponent(m.name)}" data-initials="${m.initials}"
                                                 style="padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; border-radius: 6px; cursor: pointer; margin-bottom: 2px; transition: background 0.15s; background: ${isSelected ? 'rgba(132, 204, 22, 0.15)' : 'transparent'};">
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <div style="width: 30px; height: 30px; border-radius: 50%; background: var(--panel-soft); color: var(--lime); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; border: 1px solid var(--line);">
                                                        ${m.initials}
                                                    </div>
                                                    <span style="color: var(--ink); font-size: 13.5px; font-weight: 600;">${m.name}</span>
                                                </div>
                                                ${isSelected ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}
                                            </div>
                                        `;
                                    }).join('');
                                    bindDietClicks();
                                }
                            })
                            .catch(err => console.error('Diet member search error', err));
                        }, 250);
                    }
                }

                renderMembers();

                trigger.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isOpen = menu.style.display === 'block';
                    menu.style.display = isOpen ? 'none' : 'block';
                    chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
                    if (!isOpen && searchInput) {
                        setTimeout(() => searchInput.focus(), 50);
                    }
                });

                if (searchInput) {
                    searchInput.addEventListener('input', (e) => {
                        renderMembers(e.target.value);
                    });
                    searchInput.addEventListener('click', (e) => e.stopPropagation());
                }

                document.addEventListener('click', function closeMenu(e) {
                    if (trigger && menu && !trigger.contains(e.target) && !menu.contains(e.target)) {
                        menu.style.display = 'none';
                        chevron.style.transform = 'rotate(0deg)';
                    }
                });
            },
            preConfirm: () => {
                if (!selectedId) {
                    Swal.showValidationMessage('Please select a member');
                    return false;
                }
                window.location.href = 'index.php?page=diet_builder&member_user_id=' + selectedId + '&ref=trainer_assignments';
            }
        });
    }

    // ── Pagination & Live Search for Assignments Table & Mobile Cards ────
    let assignCurrentPage = 1;
    let assignPageSize = 10;
    let assignAllRows = [];
    let assignAllCards = [];
    let assignFilteredIndices = [];

    const assignSearchInput = document.getElementById('assignmentSearchInput');
    const assignSearchClear = document.getElementById('assignmentSearchClear');
    const assignPerPageSelect = document.getElementById('assignPerPageSelect');
    const assignCountLabel = document.getElementById('assignmentCountLabel');
    const assignEmptyState = document.getElementById('assignmentEmptyState');
    const assignEmptyStateText = document.getElementById('assignmentEmptyStateText');
    const assignTableWrap = document.querySelector('.assignments-desktop-table');
    const assignCardsWrap = document.getElementById('assignmentMobileCards');
    const assignPaginationBar = document.getElementById('assignmentPaginationBar');
    const assignPaginationInfo = document.getElementById('assignmentPaginationInfo');
    const assignPaginationControls = document.getElementById('assignmentPaginationControls');

    function initAssignPagination() {
        assignAllRows = Array.from(document.querySelectorAll('.assignment-row'));
        assignAllCards = Array.from(document.querySelectorAll('.assignment-card-item'));
        const totalItems = Math.max(assignAllRows.length, assignAllCards.length);
        if (totalItems === 0) return;

        assignFilteredIndices = Array.from({ length: totalItems }, (_, i) => i);

        if (assignSearchInput) {
            assignSearchInput.addEventListener('input', function() {
                const query = this.value.trim().toLowerCase();
                if (assignSearchClear) {
                    assignSearchClear.style.display = query !== '' ? 'block' : 'none';
                }

                assignFilteredIndices = [];
                for (let i = 0; i < totalItems; i++) {
                    const targetEl = assignAllRows[i] || assignAllCards[i];
                    if (!query) {
                        assignFilteredIndices.push(i);
                    } else if (targetEl) {
                        const coach = (targetEl.getAttribute('data-coach') || '');
                        const title = (targetEl.getAttribute('data-title') || '');
                        const type = (targetEl.getAttribute('data-type') || '');
                        const member = (targetEl.getAttribute('data-member') || '');
                        const status = (targetEl.getAttribute('data-status') || '');
                        if (coach.includes(query) || title.includes(query) || type.includes(query) || member.includes(query) || status.includes(query)) {
                            assignFilteredIndices.push(i);
                        }
                    }
                }

                assignCurrentPage = 1;
                renderAssignPage();
            });

            assignSearchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    clearAssignmentSearch();
                }
            });
        }

        if (assignPerPageSelect) {
            assignPerPageSelect.addEventListener('change', function() {
                assignPageSize = parseInt(this.value, 10) || 10;
                assignCurrentPage = 1;
                renderAssignPage();
            });
        }

        renderAssignPage();
    }

    function renderAssignPage() {
        const totalItems = assignFilteredIndices.length;
        const totalPages = Math.max(1, Math.ceil(totalItems / assignPageSize));

        if (assignCurrentPage > totalPages) assignCurrentPage = totalPages;
        if (assignCurrentPage < 1) assignCurrentPage = 1;

        const startIndex = (assignCurrentPage - 1) * assignPageSize;
        const endIndex = Math.min(startIndex + assignPageSize, totalItems);
        const visibleSet = new Set(assignFilteredIndices.slice(startIndex, endIndex));

        // Toggle table rows
        assignAllRows.forEach((r, idx) => {
            r.style.display = visibleSet.has(idx) ? '' : 'none';
        });

        // Toggle mobile cards
        assignAllCards.forEach((c, idx) => {
            c.style.display = visibleSet.has(idx) ? '' : 'none';
        });

        // Count label & search query state
        const query = assignSearchInput ? assignSearchInput.value.trim() : '';
        if (assignCountLabel) {
            assignCountLabel.textContent = (query ? totalItems : assignAllRows.length) + ' assignments' + (query ? ' found' : '');
        }

        if (query && totalItems === 0) {
            if (assignEmptyState) {
                assignEmptyState.style.display = 'block';
                if (assignEmptyStateText) {
                    assignEmptyStateText.textContent = `No assignments found matching "${query}".`;
                }
            }
            if (assignTableWrap) assignTableWrap.style.display = 'none';
            if (assignCardsWrap) assignCardsWrap.style.display = 'none';
        } else {
            if (assignEmptyState) assignEmptyState.style.display = 'none';
            if (assignTableWrap) assignTableWrap.style.display = '';
            if (assignCardsWrap) assignCardsWrap.style.display = '';
        }

        updateAssignPaginationUI(totalItems, totalPages, startIndex, endIndex);
    }

    function updateAssignPaginationUI(totalItems, totalPages, startIndex, endIndex) {
        if (!assignPaginationBar || !assignPaginationInfo || !assignPaginationControls) return;

        const maxTotal = Math.max(assignAllRows.length, assignAllCards.length);
        if (maxTotal === 0 || totalItems === 0) {
            assignPaginationBar.style.display = 'none';
            return;
        }

        assignPaginationBar.style.display = 'flex';

        const startDisplay = startIndex + 1;
        assignPaginationInfo.innerHTML = `Showing <strong>${startDisplay}</strong> to <strong>${endIndex}</strong> of <strong>${totalItems}</strong> assignment${totalItems === 1 ? '' : 's'}`;

        if (totalPages <= 1) {
            assignPaginationControls.innerHTML = '';
            return;
        }

        let html = '';

        // Prev Button
        const prevDisabled = assignCurrentPage === 1 ? ' disabled' : '';
        html += `<button type="button" class="assign-page-btn${prevDisabled}" onclick="goToAssignPage(${assignCurrentPage - 1})" aria-label="Previous page">← Prev</button>`;

        // Numbered buttons
        const startPage = Math.max(1, assignCurrentPage - 2);
        const endPage = Math.min(totalPages, assignCurrentPage + 2);

        if (startPage > 1) {
            html += `<button type="button" class="assign-page-btn" onclick="goToAssignPage(1)">1</button>`;
            if (startPage > 2) {
                html += `<span class="assign-page-ellipsis">…</span>`;
            }
        }

        for (let p = startPage; p <= endPage; p++) {
            if (p === assignCurrentPage) {
                html += `<button type="button" class="assign-page-btn active">${p}</button>`;
            } else {
                html += `<button type="button" class="assign-page-btn" onclick="goToAssignPage(${p})">${p}</button>`;
            }
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<span class="assign-page-ellipsis">…</span>`;
            }
            html += `<button type="button" class="assign-page-btn" onclick="goToAssignPage(${totalPages})">${totalPages}</button>`;
        }

        // Next Button
        const nextDisabled = assignCurrentPage === totalPages ? ' disabled' : '';
        html += `<button type="button" class="assign-page-btn${nextDisabled}" onclick="goToAssignPage(${assignCurrentPage + 1})" aria-label="Next page">Next →</button>`;

        assignPaginationControls.innerHTML = html;
    }

    function goToAssignPage(page) {
        assignCurrentPage = page;
        renderAssignPage();
        const targetView = window.innerWidth <= 768 ? assignCardsWrap : assignTableWrap;
        if (targetView) {
            const rect = targetView.getBoundingClientRect();
            if (rect.top < 0) {
                targetView.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }

    function clearAssignmentSearch() {
        if (assignSearchInput) {
            assignSearchInput.value = '';
            assignSearchInput.focus();
        }
        if (assignSearchClear) assignSearchClear.style.display = 'none';

        const totalItems = Math.max(assignAllRows.length, assignAllCards.length);
        assignFilteredIndices = Array.from({ length: totalItems }, (_, i) => i);
        assignCurrentPage = 1;
        renderAssignPage();
    }

    document.addEventListener('DOMContentLoaded', initAssignPagination);
    if (document.readyState === 'interactive' || document.readyState === 'complete') {
        initAssignPagination();
    }
