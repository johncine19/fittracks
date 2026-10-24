/**
 * Users Management Controller
 * Extracted from admin/users.php
 */

const FT_USERS_CONFIG = window.USERS_CONFIG || {};
let CURRENT_TAB = FT_USERS_CONFIG.currentTab || 'all';
const GYM_FILTER = FT_USERS_CONFIG.gymFilter || '';
const IS_ADMIN = Boolean(FT_USERS_CONFIG.isAdmin);
let CURRENT_CSRF_TOKEN = FT_USERS_CONFIG.csrfToken || (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
const initialRole = FT_USERS_CONFIG.initialRole || (IS_ADMIN ? 'gym_owner' : 'trainer');

function toggleTrainerFields(role) {
    const tf = document.getElementById('trainer_fields');
    const sf = document.getElementById('staff_fields');
    const mf = document.getElementById('member_fields');
    const gf = document.getElementById('gym_owner_fields');
    const gn = document.getElementById('new_user_gym_name');
    const spec = document.getElementById('new_user_spec');
    const star = document.getElementById('specReqStar');
    if (tf) {
        if (!IS_ADMIN && role === 'trainer') {
            tf.style.display = 'grid';
            tf.style.gridTemplateColumns = 'repeat(2, minmax(0, 1fr))';
            if (spec) spec.required = true;
            if (star) star.style.display = 'inline';
        } else {
            tf.style.display = 'none';
            if (spec) spec.required = false;
            if (star) star.style.display = 'none';
        }
    }
    if (sf) {
        sf.style.display = (!IS_ADMIN && role === 'staff') ? 'block' : 'none';
    }
    if (mf) {
        mf.style.display = (!IS_ADMIN && role === 'member') ? 'block' : 'none';
    }
    if (gf) {
        gf.style.display = (IS_ADMIN && role === 'gym_owner') ? 'block' : 'none';
        if (gn) gn.required = (IS_ADMIN && role === 'gym_owner');
    }
}

window.togglePlatformPlanDetails = function(planKey) {
    const det = document.getElementById('platform_plan_details');
    const statusSelect = document.getElementById('new_user_platform_status');
    const cycleSelect = document.getElementById('new_user_platform_cycle');
    if (!det) return;

    if (planKey === 'none') {
        det.style.display = 'none';
        if (statusSelect) statusSelect.value = 'inactive';
    } else if (planKey === 'free_trial') {
        det.style.display = 'contents';
        if (statusSelect) statusSelect.value = 'trialing';
        if (cycleSelect) cycleSelect.value = 'monthly';
    } else {
        det.style.display = 'contents';
        if (statusSelect && statusSelect.value === 'trialing') {
            statusSelect.value = 'active_cash';
        }
    }
};

window.toggleMemberPaymentFields = function(planId) {
    const det = document.getElementById('member_plan_details');
    if (det) {
        det.style.display = (planId && String(planId).trim() !== '') ? 'contents' : 'none';
    }
};

if (FT_USERS_CONFIG.hasOldUser) {
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('createUserModal')?.showModal();
    });
}

    function toggleEditTrainerFields(role) {
        const tf = document.getElementById('eu_trainer_fields');
        const sf = document.getElementById('eu_staff_fields');
        const spec = document.getElementById('eu_spec');
        const star = document.getElementById('eu_spec_star');
        if (tf) {
            if (role === 'trainer') {
                tf.style.setProperty('display', 'grid', 'important');
                tf.style.gridTemplateColumns = 'repeat(2, minmax(0, 1fr))';
                if (spec) spec.required = true;
                if (star) star.style.display = 'inline';
            } else {
                tf.style.setProperty('display', 'none', 'important');
                if (spec) spec.required = false;
                if (star) star.style.display = 'none';
            }
        }
        if (sf) {
            sf.style.display = (role === 'staff') ? 'block' : 'none';
        }
    }

    document.addEventListener('click', function(e) {
        const editBtn = e.target.closest('.btn-edit-user');
        if (editBtn) {
            try {
                const userData = JSON.parse(editBtn.getAttribute('data-user') || '{}');
                editUser(userData);
            } catch (err) {
                console.error('Invalid user data', err);
            }
            return;
        }

        const delBtn = e.target.closest('.btn-delete-user');
        if (delBtn) {
            e.preventDefault();
            e.stopPropagation();
            const userId = parseInt(delBtn.getAttribute('data-user-id'), 10);
            const userName = delBtn.getAttribute('data-user-name') || '';
            const userRole = delBtn.getAttribute('data-user-role') || '';
            confirmDeleteUser(userId, userName, userRole);
            return;
        }

        const progBtn = e.target.closest('.btn-member-progress');
        if (progBtn) {
            e.preventDefault();
            e.stopPropagation();
            const memberId = parseInt(progBtn.getAttribute('data-member-id'), 10);
            const memberName = progBtn.getAttribute('data-member-name') || 'Member';
            openMemberProgressModal(memberId, memberName);
            return;
        }
    });

    window.confirmDeleteUser = function(target, eventOrName, maybeRole) {
        let userId = null;
        let userName = '';
        let userRole = '';

        if (typeof target === 'object' && target !== null && target.tagName === 'FORM') {
            const idInput = target.querySelector('input[name="user_id"]');
            userId = idInput ? parseInt(idInput.value, 10) : null;
            userName = typeof maybeRole === 'string' ? maybeRole : (typeof eventOrName === 'string' ? eventOrName : '');
            if (eventOrName && typeof eventOrName.preventDefault === 'function') {
                eventOrName.preventDefault();
                eventOrName.stopPropagation();
            }
        } else {
            userId = parseInt(target, 10);
            userName = typeof eventOrName === 'string' ? eventOrName : '';
            userRole = typeof maybeRole === 'string' ? maybeRole : '';
        }

        if (!userId) return false;

        const safeName = typeof escapeHtml === 'function' ? escapeHtml(userName) : (userName || 'this user');

        const doSubmit = () => {
            const tempForm = document.createElement('form');
            tempForm.method = 'POST';
            tempForm.action = 'index.php?page=users';
            tempForm.style.display = 'none';

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = CURRENT_CSRF_TOKEN;
            tempForm.appendChild(csrfInput);

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_user';
            tempForm.appendChild(actionInput);

            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'user_id';
            idInput.value = userId;
            tempForm.appendChild(idInput);

            document.body.appendChild(tempForm);
            tempForm.submit();
        };

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Delete User Account?',
                html: `
                    <div style="margin-top: 6px;">
                        <p style="margin: 0 0 14px 0; font-size: 14.5px; text-align: center; color: var(--ink, #ffffff); line-height: 1.5;">
                            Are you sure you want to permanently delete <strong>${safeName}</strong>?
                        </p>
                        <div style="display: flex; align-items: flex-start; text-align: left; gap: 8px; padding: 10px 12px; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.25); border-radius: 8px; color: #f87171; font-size: 12.5px; line-height: 1.4;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0; margin-top:1px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            <span>This will permanently delete this account and all associated access. <strong>This action cannot be undone.</strong></span>
                        </div>
                    </div>
                `,
                icon: 'warning',
                iconColor: '#ef4444',
                showCancelButton: true,
                confirmButtonText: 'Yes, Delete User',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#ef4444',
                cancelButtonColor: 'var(--line, #334155)',
                background: 'var(--panel, #121721)',
                color: 'var(--ink, #ffffff)',
                reverseButtons: true,
                focusCancel: true
            }).then((result) => {
                if (result.isConfirmed) {
                    doSubmit();
                }
            });
        } else if (confirm(`Are you sure you want to permanently delete ${userName || 'this user'}? This action cannot be undone.`)) {
            doSubmit();
        }

        return false;
    };

    function editUser(u) {
        Swal.fire({
            title: 'Edit User',
            customClass: {
                popup: 'edit-user-swal-modal'
            },
            width: '540px',
            showCloseButton: true,
            html: `
                <form id="editUserForm" method="post" class="edit-user-grid-form">
                    <input type="hidden" name="csrf_token" value="${CURRENT_CSRF_TOKEN}">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" id="eu_id">
                    <input type="hidden" name="admin_password" id="eu_admin_pass">

                    <label class="eu-field">
                        <span class="eu-label">First name <span class="req-star">*</span></span>
                        <input name="first_name" id="eu_fn" class="form-control" required autocapitalize="words" style="text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\\b\\w/g, l => l.toUpperCase())">
                    </label>

                    <label class="eu-field">
                        <span class="eu-label">Last name <span class="req-star">*</span></span>
                        <input name="last_name" id="eu_ln" class="form-control" required autocapitalize="words" style="text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\\b\\w/g, l => l.toUpperCase())">
                    </label>

                    <label class="eu-field eu-field-email">
                        <span class="eu-label">Email <span class="req-star">*</span></span>
                        <input type="email" name="email" id="eu_email" class="form-control" required style="text-transform: lowercase;" oninput="this.value = this.value.toLowerCase()" onblur="this.value = this.value.trim().toLowerCase()">
                    </label>

                    <label class="eu-field eu-field-phone">
                        <span class="eu-label">Mobile Number <span class="req-star">*</span></span>
                        <input type="tel" name="phone" id="eu_phone" class="form-control" pattern="[0-9]{11}" maxlength="11" title="Please enter exactly 11 digits" placeholder="09123456789" required oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)">
                    </label>

                    <div class="eu-field eu-field-role">
                        <span class="eu-label">Role <span class="req-star">*</span></span>
                        <div id="eu_role_wrap"></div>
                    </div>

                    <label class="eu-field eu-field-pass">
                        <span class="eu-label">New Password <small style="font-weight:400;color:var(--muted);">(blank = keep)</small></span>
                        <div style="position:relative; width:100%;">
                            <input type="password" name="new_password" id="eu_pass" class="form-control" style="padding-right:32px;" autocomplete="new-password" placeholder="Leave blank to keep">
                            <button type="button" onclick="const p=document.getElementById('eu_pass');const isP=p.type==='password';p.type=isP?'text':'password';this.querySelector('svg').style.opacity=isP?'1':'0.5';" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:2px;color:var(--ink);display:flex;align-items:center;" title="Toggle visibility" tabindex="-1">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity:0.5;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </label>

                    <div id="eu_trainer_fields" class="eu-trainer-fields" style="display: none;">
                        <label class="eu-field eu-field-full">
                            <span class="eu-label">Specialization <span class="req-star" id="eu_spec_star" style="display:none;">*</span></span>
                            <input name="specialization" id="eu_spec" class="form-control" placeholder="e.g. Strength & Conditioning">
                        </label>
                        <label class="eu-field eu-field-full">
                            <span class="eu-label">Bio</span>
                            <input name="bio" id="eu_bio" class="form-control" placeholder="Short bio">
                        </label>
                    </div>

                    <div id="eu_staff_fields" class="eu-staff-fields" style="display: none;">
                        <div class="eu-field eu-field-full">
                            <span class="eu-label">Staff Permission Level</span>
                            <div id="eu_staff_role_wrap"></div>
                        </div>
                    </div>
                </form>
            `,
            didOpen: () => {
                document.getElementById('eu_id').value = u.user_id;
                document.getElementById('eu_fn').value = u.first_name;
                document.getElementById('eu_ln').value = u.last_name;
                document.getElementById('eu_email').value = u.email;
                document.getElementById('eu_phone').value = u.phone || '';
                document.getElementById('eu_spec').value = u.specialization || '';
                document.getElementById('eu_bio').value = u.bio || '';

                const editRoleItems = [];
                if (IS_ADMIN) {
                    editRoleItems.push({ id: 'gym_owner', label: 'Gym Owner' });
                }
                if (!IS_ADMIN || u.role === 'staff') {
                    editRoleItems.push({ id: 'staff', label: 'Front Desk / Staff' });
                }
                if (!IS_ADMIN || u.role === 'trainer') {
                    editRoleItems.push({ id: 'trainer', label: 'Trainer' });
                }
                editRoleItems.push({ id: 'member', label: 'Member' });

                const editStaffRoleItems = [
                    { id: 'front_desk', label: 'Front Desk / Cashier (Financials Masked)' },
                    { id: 'manager', label: 'Operations Manager (Full Staff Access)' }
                ];

                if (typeof FitDropdown !== 'undefined') {
                    window.editUserRoleDropdown = new FitDropdown({
                        container: '#eu_role_wrap',
                        name: 'role',
                        id: 'eu_role',
                        value: u.role,
                        searchable: false,
                        zIndex: 60,
                        menuAlign: 'right',
                        items: editRoleItems,
                        onChange: function(item) {
                            const val = (item && typeof item === 'object') ? item.id : item;
                            toggleEditTrainerFields(val);
                        }
                    });

                    window.editUserStaffRoleDropdown = new FitDropdown({
                        container: '#eu_staff_role_wrap',
                        name: 'staff_role',
                        id: 'eu_staff_role',
                        value: u.staff_role || 'front_desk',
                        searchable: false,
                        zIndex: 50,
                        items: editStaffRoleItems
                    });
                }

                toggleEditTrainerFields(u.role);
            },
            willClose: () => {
                if (window.editUserRoleDropdown && typeof window.editUserRoleDropdown.destroy === 'function') {
                    window.editUserRoleDropdown.destroy();
                    window.editUserRoleDropdown = null;
                }
                if (window.editUserStaffRoleDropdown && typeof window.editUserStaffRoleDropdown.destroy === 'function') {
                    window.editUserStaffRoleDropdown.destroy();
                    window.editUserStaffRoleDropdown = null;
                }
            },
            showCancelButton: true,
            confirmButtonText: 'Save Changes',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('editUserForm');
                if (!form.first_name.value || !form.last_name.value || !form.email.value) {
                    Swal.showValidationMessage('Name and email are required');
                    return false;
                }
                const newPass = form.new_password ? form.new_password.value.trim() : '';
                if (newPass !== '') {
                    if (newPass.length < 8 || !/[a-zA-Z]/.test(newPass) || !/[0-9]/.test(newPass)) {
                        Swal.showValidationMessage('New password must be at least 8 characters, with a letter and a number.');
                        return false;
                    }
                }
                if (form.role.value === 'trainer' && !form.specialization.value) {
                    Swal.showValidationMessage('Specialization is required for trainers');
                    return false;
                }
                
                // Capture data before the first modal is destroyed
                const formData = new FormData(form);
                
                // Return a Promise that resolves when the nested Swal finishes
                return new Promise((resolve) => {
                    Swal.fire({
                        title: 'Confirm Admin Password',
                        text: 'Please enter your password to save these changes.',
                        input: 'password',
                        inputAttributes: {
                            autocapitalize: 'off',
                            autocorrect: 'off'
                        },
                        showCancelButton: true,
                        confirmButtonText: 'Confirm',
                        confirmButtonColor: 'var(--lime-dark)',
                        cancelButtonColor: 'var(--line)',
                        background: 'var(--bg)',
                        color: 'var(--ink)',
                        preConfirm: (password) => {
                            if (!password) {
                                Swal.showValidationMessage('Admin password is required');
                                return false;
                            }
                            
                            formData.set('admin_password', password);
                            
                            // Create a temporary form to submit the data
                            const tempForm = document.createElement('form');
                            tempForm.method = 'post';
                            tempForm.style.display = 'none';
                            for (let [key, value] of formData.entries()) {
                                const input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = key;
                                input.value = value;
                                tempForm.appendChild(input);
                            }
                            document.body.appendChild(tempForm);
                            tempForm.submit();
                        }
                    });
                });
            }
        });
    }

    // ── Live Search for Users Page ───────────────────────────────────────
    // CURRENT_TAB, GYM_FILTER, IS_ADMIN, CURRENT_CSRF_TOKEN initialized from FT_USERS_CONFIG at top

    const userSearchInput = document.getElementById('userSearchInput');
    const userSearchClear = document.getElementById('userSearchClear');
    const userSearchStatus = document.getElementById('userSearchStatus');
    const userCountLabel = document.getElementById('userCountLabel');
    const userTableBody = document.getElementById('userTableBody');
    const userMobileCards = document.getElementById('userMobileCards');
    const userEmptyState = document.getElementById('userEmptyState');
    const userEmptyStateText = document.getElementById('userEmptyStateText');
    const userDesktopTableWrap = document.querySelector('.users-desktop-table');
    const userPagination = document.getElementById('userPagination');

    // Cache tab content so clearing search is instantaneous
    let cachedTabTableHtml = userTableBody ? userTableBody.innerHTML : '';
    let cachedTabCardsHtml = userMobileCards ? userMobileCards.innerHTML : '';
    let cachedTabCountText = userCountLabel ? userCountLabel.textContent : '';
    let cachedTabEmptyDisplay = userEmptyState ? userEmptyState.style.display : 'none';
    let cachedTabPaginationHtml = userPagination ? userPagination.innerHTML : '';

    let userSearchDebounceTimer = null;

    function escapeUserHtml(str) {
        if (!str && str !== 0) return '';
        return String(str).replace(/[&<>"']/g, function(m) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[m];
        });
    }

    function updateColumnsVisibility() {
        const specCols = document.querySelectorAll('.col-spec');
        specCols.forEach(col => {
            col.style.display = (CURRENT_TAB === 'trainer') ? '' : 'none';
        });

        const scoreCols = document.querySelectorAll('.col-score');
        scoreCols.forEach(col => {
            col.style.display = (CURRENT_TAB === 'member') ? '' : 'none';
        });
    }

    function clearUserSearch(focusInput = true) {
        if (userSearchInput) userSearchInput.value = '';
        if (userSearchClear) userSearchClear.style.display = 'none';
        if (userSearchStatus) userSearchStatus.style.display = 'none';

        // Restore tab's server or AJAX rendered HTML
        if (userTableBody) userTableBody.innerHTML = cachedTabTableHtml;
        if (userMobileCards) userMobileCards.innerHTML = cachedTabCardsHtml;
        if (userCountLabel) userCountLabel.textContent = cachedTabCountText;
        if (userEmptyState) userEmptyState.style.display = cachedTabEmptyDisplay;
        if (userDesktopTableWrap) userDesktopTableWrap.style.display = cachedTabEmptyDisplay === 'block' ? 'none' : '';
        if (userMobileCards) userMobileCards.style.display = cachedTabEmptyDisplay === 'block' ? 'none' : '';
        if (userPagination) {
            userPagination.innerHTML = cachedTabPaginationHtml;
            userPagination.style.display = (cachedTabEmptyDisplay === 'block' || !cachedTabPaginationHtml) ? 'none' : '';
        }

        updateColumnsVisibility();

        if (focusInput && userSearchInput) {
            userSearchInput.focus();
        }
    }

    function renderDesktopRow(u, csrfToken) {
        const statusClass = 'badge badge-' + u.status;
        const roleDisplayName = escapeUserHtml(u.role_display);

        let gymMarkHtml = '';
        if (u.is_gym_owner) {
            if (u.owner_gym_status === 'approved') {
                gymMarkHtml = '<span class="gym-verify-mark is-verified" title="Verified Gym" aria-label="Verified"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>';
            } else {
                const hint = !u.associated_gym ? 'Not Verified (No Gym Submitted)' : (u.owner_gym_status === 'pending' ? 'Not Verified (Pending Approval)' : (u.owner_gym_status === 'rejected' ? 'Not Verified (Rejected)' : 'Not Verified'));
                gymMarkHtml = `<span class="gym-verify-mark is-unverified" title="${escapeUserHtml(hint)}" aria-label="${escapeUserHtml(hint)}">*</span>`;
            }
        }

        const associatedGymHtml = u.associated_gym 
            ? `<div class="user-gym-cell"><span class="user-gym-name" title="${escapeUserHtml(u.associated_gym)}">${escapeUserHtml(u.associated_gym)}</span>${gymMarkHtml}</div>`
            : (u.is_gym_owner ? `<div class="user-gym-cell"><span class="muted">—</span>${gymMarkHtml}</div>` : `<span class="muted">—</span>`);
        const specHtml = u.is_trainer
            ? `<span style="color:var(--ink);">${escapeUserHtml(u.specialization)}</span>`
            : `<span class="muted">—</span>`;
        const rawUserJson = escapeUserHtml(JSON.stringify(u.raw_user));
        const staffBadge = (u.role === 'staff')
            ? `<span class="user-staff-role-text">${u.staff_role === 'manager' ? 'Operations Manager' : 'Front Desk'}</span>`
            : '';

        let scoreHtml = '<span class="muted">—</span>';
        if (u.is_member) {
            const scoreVal = parseInt(u.engagement_score || 0, 10);
            const scoreTierClass = scoreVal >= 75 ? 'tier-high' : (scoreVal >= 45 ? 'tier-mid' : 'tier-low');
            scoreHtml = `
            <div class="user-score-badge ${scoreTierClass}">
                <span class="score-val">${scoreVal}</span>
                <span class="score-unit">/100</span>
            </div>`;
        }

        return `
        <tr class="user-table-row" data-name="${escapeUserHtml(u.full_name.toLowerCase())}" data-email="${escapeUserHtml(u.email.toLowerCase())}" data-phone="${escapeUserHtml(u.phone)}" data-role="${escapeUserHtml(u.role)}" data-id="${u.user_id}">
            <td>
                <div class="user-cell">
                    <div class="user-cell-avatar">
                        ${u.avatar_html}
                    </div>
                    <div class="user-cell-info">
                        <span class="user-full-name">${escapeUserHtml(u.full_name)}</span>
                        <span class="user-id-chip">#${u.user_id}</span>
                    </div>
                </div>
            </td>
            <td>
                <span class="user-email-text" title="${escapeUserHtml(u.email)}">${escapeUserHtml(u.email)}</span>
            </td>
            <td>
                <div class="user-role-wrap">
                    <span class="badge-role badge-role-${escapeUserHtml(u.role)}">${roleDisplayName}</span>
                    ${u.role === 'staff' ? `<span class="badge-staff-subrole">${u.staff_role === 'manager' ? 'Operations Manager' : 'Front Desk'}</span>` : ''}
                </div>
            </td>
            ${IS_ADMIN ? `<td class="user-gym-td">${associatedGymHtml}</td>` : ''}
            <td class="col-spec" style="${CURRENT_TAB === 'trainer' ? '' : 'display: none;'}">${specHtml}</td>
            <td class="col-score" style="${CURRENT_TAB === 'member' ? '' : 'display: none;'}">${scoreHtml}</td>
            <td>
                <span class="user-status-pill is-${escapeUserHtml(u.status)}">
                    <span class="status-dot"></span>
                    ${escapeUserHtml(u.status.charAt(0).toUpperCase() + u.status.slice(1))}
                </span>
            </td>
            <td>
                <span class="user-joined-date">${escapeUserHtml(u.joined_formatted)}</span>
            </td>
            <td style="text-align: right;">
                <div class="user-actions-cluster">
                    <form method="post" class="user-quick-status-form">
                        <input type="hidden" name="csrf_token" value="${csrfToken}">
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="user_id" value="${u.user_id}">
                        <select name="status" class="user-status-select-sm" onchange="this.form.submit()" title="Change Account Status">
                            <option value="active" ${u.status === 'active' ? 'selected' : ''}>Active</option>
                            <option value="suspended" ${u.status === 'suspended' ? 'selected' : ''}>Suspended</option>
                        </select>
                    </form>
                    <button type="button" class="btn-user-action btn-edit-user" data-user="${rawUserJson}" title="Edit User">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                        <span>Edit</span>
                    </button>
                    ${(!IS_ADMIN && u.is_member) || (u.can_view_progress) ? `
                        <button type="button" class="btn-user-action btn-user-progress btn-member-progress" data-member-id="${u.user_id}" data-member-name="${escapeUserHtml(u.full_name)}" title="View Member Progress">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                            <span>Progress</span>
                        </button>
                        <a href="index.php?page=diet_builder&member_user_id=${u.user_id}&ref=users" class="btn-user-action btn-user-diet" title="Manage Diet Plan">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                            <span>Diet</span>
                        </a>
                    ` : ''}
                    ${u.can_delete ? `
                        <button type="button" class="btn-user-action btn-action-delete btn-delete-user" data-user-id="${u.user_id}" data-user-name="${escapeUserHtml(u.full_name)}" data-user-role="${escapeUserHtml(u.role)}" title="Delete User">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    ` : ''}
                </div>
            </td>
        </tr>
        `;
    }

    function renderMobileCard(u, csrfToken) {
        const roleDisplayName = escapeUserHtml(u.role_display);
        const rawUserJson = escapeUserHtml(JSON.stringify(u.raw_user));

        let gymMarkHtml = '';
        if (u.is_gym_owner) {
            if (u.owner_gym_status === 'approved') {
                gymMarkHtml = '<span class="gym-verify-mark is-verified" title="Verified Gym" aria-label="Verified"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>';
            } else {
                const hint = !u.associated_gym ? 'Not Verified (No Gym Submitted)' : (u.owner_gym_status === 'pending' ? 'Not Verified (Pending Approval)' : (u.owner_gym_status === 'rejected' ? 'Not Verified (Rejected)' : 'Not Verified'));
                gymMarkHtml = `<span class="gym-verify-mark is-unverified" title="${escapeUserHtml(hint)}" aria-label="${escapeUserHtml(hint)}">*</span>`;
            }
        }

        return `
        <div class="user-card-item" data-name="${escapeUserHtml(u.full_name.toLowerCase())}" data-email="${escapeUserHtml(u.email.toLowerCase())}" data-phone="${escapeUserHtml(u.phone)}" data-role="${escapeUserHtml(u.role)}" data-id="${u.user_id}">
            <div class="user-card-header">
                <div class="user-card-identity">
                    <div class="user-card-avatar-wrap">
                        ${u.avatar_html}
                    </div>
                    <div class="user-card-names">
                        <div class="user-card-fullname">${escapeUserHtml(u.full_name)}</div>
                        <div class="user-card-id-sub">
                            <span class="user-id-chip">#${u.user_id}</span>
                            <span class="user-card-date">${escapeUserHtml(u.joined_formatted)}</span>
                        </div>
                    </div>
                </div>
                <div class="user-card-badges">
                    <span class="badge-role badge-role-${escapeUserHtml(u.role)}">${roleDisplayName}</span>
                    ${u.role === 'staff' ? `<span class="badge-staff-subrole">${u.staff_role === 'manager' ? 'Manager' : 'Front Desk'}</span>` : ''}
                    <span class="user-status-pill is-${escapeUserHtml(u.status)}">
                        <span class="status-dot"></span>
                        ${escapeUserHtml(u.status.charAt(0).toUpperCase() + u.status.slice(1))}
                    </span>
                </div>
            </div>

            <div class="user-card-details">
                <div class="user-card-detail-item">
                    <span class="user-card-detail-label">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        Email
                    </span>
                    <span class="user-card-detail-value email-value" title="${escapeUserHtml(u.email)}">${escapeUserHtml(u.email)}</span>
                </div>

                ${IS_ADMIN && (u.associated_gym || u.is_gym_owner) ? `
                <div class="user-card-detail-item">
                    <span class="user-card-detail-label">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M3 7v14M21 7v14M6 11h4M6 15h4M14 11h4M14 15h4M9 21v-4h6v4M3 7l9-4 9 4"/></svg>
                        Gym / Branch
                    </span>
                    <div class="user-card-detail-value" style="display:inline-flex; align-items:center; justify-content:flex-end; gap:2px; max-width:65%;">
                        <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escapeUserHtml(u.associated_gym || '—')}">${escapeUserHtml(u.associated_gym || '—')}</span>
                        ${gymMarkHtml}
                    </div>
                </div>
                ` : ''}

                ${u.role === 'trainer' && u.specialization ? `
                <div class="user-card-detail-item">
                    <span class="user-card-detail-label">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18M4 22h16M10 14.66V17c0 .55-.45 1-1 1H7c-.55 0-1-.45-1-1v-2.34M18 14.66V17c0 .55-.45 1-1 1h-2c-.55 0-1-.45-1-1v-2.34M8 2h8a2 2 0 0 1 2 2v7a6 6 0 0 1-12 0V4a2 2 0 0 1 2-2z"/></svg>
                        Specialization
                    </span>
                    <span class="user-card-detail-value">${escapeUserHtml(u.specialization)}</span>
                </div>
                ` : ''}

                ${u.role === 'member' ? `
                <div class="user-card-detail-item">
                    <span class="user-card-detail-label">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Engagement
                    </span>
                    <span class="user-card-detail-value"><strong style="color:var(--lime);">${u.engagement_score}</strong> / 100</span>
                </div>
                ` : ''}
            </div>

            <div class="user-card-actions">
                <form method="post" class="user-card-status-form">
                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="user_id" value="${u.user_id}">
                    <span class="user-card-status-label">Status:</span>
                    <select name="status" class="user-card-status-select" onchange="this.form.submit()">
                        <option value="active" ${u.status === 'active' ? 'selected' : ''}>Active</option>
                        <option value="suspended" ${u.status === 'suspended' ? 'selected' : ''}>Suspended</option>
                    </select>
                </form>
                
                <div class="user-card-btn-group">
                    <button type="button" class="btn-card-action btn-edit-user" data-user="${rawUserJson}">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                        <span>Edit</span>
                    </button>
                    ${(!IS_ADMIN && u.is_member) || (u.can_view_progress) ? `
                        <button type="button" class="btn-card-action btn-card-progress btn-member-progress" data-member-id="${u.user_id}" data-member-name="${escapeUserHtml(u.full_name)}" title="View Member Progress">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                            <span>Progress</span>
                        </button>
                        <a href="index.php?page=diet_builder&member_user_id=${u.user_id}&ref=users" class="btn-card-action btn-card-diet" title="Manage Diet Plan">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                            <span>Diet</span>
                        </a>
                    ` : ''}
                    ${u.can_delete ? `
                    <button type="button" class="btn-card-action btn-card-danger btn-delete-user" data-user-id="${u.user_id}" data-user-name="${escapeUserHtml(u.full_name)}" data-user-role="${escapeUserHtml(u.role)}" title="Delete User">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        <span>Delete</span>
                    </button>
                    ` : ''}
                </div>
            </div>
        </div>
        `;
    }

    if (userSearchInput) {
        userSearchInput.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();

            // Toggle clear button
            if (userSearchClear) {
                userSearchClear.style.display = query ? 'flex' : 'none';
            }

            // STEP 1: Instant Local Filter (0ms)
            const tableRows = userTableBody ? userTableBody.querySelectorAll('.user-table-row') : [];
            const cardItems = userMobileCards ? userMobileCards.querySelectorAll('.user-card-item') : [];
            let localMatches = 0;

            tableRows.forEach(row => {
                const name = row.getAttribute('data-name') || '';
                const email = row.getAttribute('data-email') || '';
                const phone = row.getAttribute('data-phone') || '';
                const role = row.getAttribute('data-role') || '';
                const id = row.getAttribute('data-id') || '';

                if (!query || name.includes(query) || email.includes(query) || phone.includes(query) || role.includes(query) || id.includes(query)) {
                    row.style.display = '';
                    localMatches++;
                } else {
                    row.style.display = 'none';
                }
            });

            cardItems.forEach(card => {
                const name = card.getAttribute('data-name') || '';
                const email = card.getAttribute('data-email') || '';
                const phone = card.getAttribute('data-phone') || '';
                const role = card.getAttribute('data-role') || '';
                const id = card.getAttribute('data-id') || '';

                if (!query || name.includes(query) || email.includes(query) || phone.includes(query) || role.includes(query) || id.includes(query)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });

            if (query) {
                if (userPagination) userPagination.style.display = 'none';
                if (localMatches > 0) {
                    if (userCountLabel) userCountLabel.textContent = localMatches + ' found';
                    if (userEmptyState) userEmptyState.style.display = 'none';
                    if (userDesktopTableWrap) userDesktopTableWrap.style.display = '';
                    if (userMobileCards) userMobileCards.style.display = '';
                } else {
                    if (userSearchStatus) {
                        userSearchStatus.style.display = 'block';
                        userSearchStatus.textContent = 'Searching database...';
                    }
                }
            } else {
                clearUserSearch(false);
                return;
            }

            // STEP 2: Debounced Server Search (250ms)
            if (userSearchDebounceTimer) clearTimeout(userSearchDebounceTimer);

            userSearchDebounceTimer = setTimeout(() => {
                if (userSearchStatus) {
                    userSearchStatus.style.display = 'block';
                    userSearchStatus.textContent = 'Searching database...';
                }

                const url = `index.php?page=users&action=search_users&q=${encodeURIComponent(query)}&tab=${encodeURIComponent(CURRENT_TAB)}&gym_id=${encodeURIComponent(GYM_FILTER)}`;

                fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(res => res.json())
                .then(data => {
                    // Ensure query hasn't changed in the meantime
                    if (userSearchInput.value.trim().toLowerCase() !== query) return;

                    if (userSearchStatus) userSearchStatus.style.display = 'none';
                    if (data.csrf_token) CURRENT_CSRF_TOKEN = data.csrf_token;

                    const users = data.users || [];
                    const total = data.total || 0;

                    if (userCountLabel) {
                        userCountLabel.textContent = total + ' found';
                    }

                    if (users.length === 0) {
                        if (userTableBody) userTableBody.innerHTML = '';
                        if (userMobileCards) userMobileCards.innerHTML = '';
                        if (userDesktopTableWrap) userDesktopTableWrap.style.display = 'none';
                        if (userMobileCards) userMobileCards.style.display = 'none';
                        if (userEmptyState) {
                            userEmptyState.style.display = 'block';
                            if (userEmptyStateText) {
                                userEmptyStateText.textContent = `No users found matching "${query}". Try a different name, email, phone number, or ID.`;
                            }
                        }
                    } else {
                        if (userEmptyState) userEmptyState.style.display = 'none';
                        if (userDesktopTableWrap) userDesktopTableWrap.style.display = '';
                        if (userMobileCards) userMobileCards.style.display = '';

                        // Render Desktop Rows
                        if (userTableBody) {
                            userTableBody.innerHTML = users.map(u => renderDesktopRow(u, CURRENT_CSRF_TOKEN)).join('');
                        }

                        // Render Mobile Cards
                        if (userMobileCards) {
                            userMobileCards.innerHTML = users.map(u => renderMobileCard(u, CURRENT_CSRF_TOKEN)).join('');
                        }

                        // Update pagination
                        if (userPagination) {
                            if (data.pagination_html && users.length > 0) {
                                userPagination.innerHTML = data.pagination_html;
                                userPagination.style.display = '';
                            } else {
                                userPagination.innerHTML = '';
                                userPagination.style.display = 'none';
                            }
                        }

                        updateColumnsVisibility();
                    }
                })
                .catch(err => {
                    console.error('User search error:', err);
                    if (userSearchStatus) {
                        userSearchStatus.style.display = 'block';
                        userSearchStatus.textContent = 'Search failed. Please try again.';
                    }
                });
            }, 250);
        });

        // Shortcut '/' to focus search, and 'Escape' to clear search
        document.addEventListener('keydown', function(e) {
            if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) {
                e.preventDefault();
                userSearchInput?.focus();
                userSearchInput?.select();
            } else if (e.key === 'Escape' && document.activeElement === userSearchInput) {
                clearUserSearch(true);
            }
        });
    }

    // ── Seamless Client-side Tab & Pagination Navigation ──────────────────
    let isTabLoading = false;

    function loadTabUsers(targetTab, pageNum = 1, pushHistory = true) {
        if (isTabLoading) return;
        isTabLoading = true;

        if (typeof window.hideTopProgress === 'function') {
            window.hideTopProgress();
        }

        CURRENT_TAB = targetTab;

        // Update active class on tab pills
        document.querySelectorAll('.users-nav-pill').forEach(pill => {
            if (pill.getAttribute('data-tab') === targetTab) {
                pill.classList.add('is-active');
            } else {
                pill.classList.remove('is-active');
            }
        });

        // Toggle table header & column visibility
        updateColumnsVisibility();

        // Clear search input on tab switch
        if (userSearchInput) userSearchInput.value = '';
        if (userSearchClear) userSearchClear.style.display = 'none';
        if (userSearchStatus) {
            userSearchStatus.style.display = 'block';
            userSearchStatus.textContent = 'Loading accounts...';
        }

        const gymParam = GYM_FILTER ? `&gym_id=${encodeURIComponent(GYM_FILTER)}` : '';
        const pageParam = pageNum > 1 ? `&p=${pageNum}` : '';
        const fetchUrl = `index.php?page=users&action=search_users&tab=${encodeURIComponent(targetTab)}${gymParam}${pageParam}`;
        const browserUrl = `?page=users&tab=${encodeURIComponent(targetTab)}${gymParam}${pageParam}`;

        if (pushHistory) {
            history.pushState({ tab: targetTab, page: pageNum }, '', browserUrl);
        }

        if (userTableBody) {
            userTableBody.style.transition = 'opacity 0.15s ease';
            userTableBody.style.opacity = '0.4';
        }
        if (userMobileCards) {
            userMobileCards.style.transition = 'opacity 0.15s ease';
            userMobileCards.style.opacity = '0.4';
        }

        fetch(fetchUrl, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            isTabLoading = false;
            if (typeof window.hideTopProgress === 'function') {
                window.hideTopProgress();
            }
            if (userTableBody) userTableBody.style.opacity = '1';
            if (userMobileCards) userMobileCards.style.opacity = '1';
            if (userSearchStatus) userSearchStatus.style.display = 'none';

            if (data.csrf_token) CURRENT_CSRF_TOKEN = data.csrf_token;

            const users = data.users || [];
            const total = data.total || 0;

            if (userCountLabel) {
                userCountLabel.textContent = total.toLocaleString() + ' found';
            }

            if (users.length === 0) {
                if (userTableBody) userTableBody.innerHTML = '';
                if (userMobileCards) userMobileCards.innerHTML = '';
                if (userDesktopTableWrap) userDesktopTableWrap.style.display = 'none';
                if (userMobileCards) userMobileCards.style.display = 'none';
                if (userEmptyState) {
                    userEmptyState.style.display = 'block';
                    if (userEmptyStateText) {
                        userEmptyStateText.textContent = 'No user accounts found in this category.';
                    }
                }
            } else {
                if (userEmptyState) userEmptyState.style.display = 'none';
                if (userDesktopTableWrap) userDesktopTableWrap.style.display = '';
                if (userMobileCards) userMobileCards.style.display = '';

                if (userTableBody) {
                    userTableBody.innerHTML = users.map(u => renderDesktopRow(u, CURRENT_CSRF_TOKEN)).join('');
                }
                if (userMobileCards) {
                    userMobileCards.innerHTML = users.map(u => renderMobileCard(u, CURRENT_CSRF_TOKEN)).join('');
                }
            }

            // Update pagination
            if (userPagination) {
                if (data.pagination_html && users.length > 0) {
                    userPagination.innerHTML = data.pagination_html;
                    userPagination.style.display = '';
                } else {
                    userPagination.innerHTML = '';
                    userPagination.style.display = 'none';
                }
            }

            // Cache current tab's state for instant search reset
            cachedTabTableHtml = userTableBody ? userTableBody.innerHTML : '';
            cachedTabCardsHtml = userMobileCards ? userMobileCards.innerHTML : '';
            cachedTabCountText = userCountLabel ? userCountLabel.textContent : '';
            cachedTabEmptyDisplay = userEmptyState ? userEmptyState.style.display : 'none';
            cachedTabPaginationHtml = userPagination ? userPagination.innerHTML : '';

            // Ensure column display is aligned with new rows
            updateColumnsVisibility();
        })
        .catch(err => {
            isTabLoading = false;
            if (typeof window.hideTopProgress === 'function') {
                window.hideTopProgress();
            }
            if (userTableBody) userTableBody.style.opacity = '1';
            if (userMobileCards) userMobileCards.style.opacity = '1';
            if (userSearchStatus) {
                userSearchStatus.style.display = 'block';
                userSearchStatus.textContent = 'Failed to load accounts. Please try again.';
            }
            console.error('Failed to load accounts for tab:', err);
        });
    }

    // Intercept clicks for tabs and pagination links
    document.addEventListener('click', function(e) {
        // Tab Pills
        const pill = e.target.closest('.users-nav-pill');
        if (pill) {
            if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            e.preventDefault();
            if (typeof window.hideTopProgress === 'function') {
                window.hideTopProgress();
            }
            const targetTab = pill.getAttribute('data-tab') || 'all';
            if (targetTab === CURRENT_TAB && !userSearchInput?.value.trim()) {
                return;
            }
            loadTabUsers(targetTab, 1, true);
            return;
        }

        // Pagination Links inside userPagination
        const pageLink = e.target.closest('#userPagination a');
        if (pageLink) {
            if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            e.preventDefault();
            if (typeof window.hideTopProgress === 'function') {
                window.hideTopProgress();
            }
            const href = pageLink.getAttribute('href') || '';
            const match = href.match(/[?&]p=(\d+)/);
            if (match) {
                const pageNum = parseInt(match[1], 10);
                loadTabUsers(CURRENT_TAB, pageNum, true);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
            return;
        }
    });

    // Browser back/forward navigation support
    window.addEventListener('popstate', function(e) {
        const urlParams = new URLSearchParams(window.location.search);
        const tab = urlParams.get('tab') || 'all';
        const p = parseInt(urlParams.get('p') || '1', 10);
        loadTabUsers(tab, p, false);
    });

    // ── Create User Form: Real-time Password Checking & AJAX Submission ───
    const createUserForm = document.getElementById('createUserForm');
    const createUserPass = document.getElementById('new_user_password');
    const createUserHint = document.getElementById('new_user_pass_hint');

    if (createUserPass && createUserHint) {
        createUserPass.addEventListener('input', function() {
            const val = this.value;
            if (!val) {
                createUserHint.style.color = 'var(--muted)';
                createUserHint.textContent = 'Must be at least 8 characters with a letter and a number.';
                return;
            }
            const hasLength = val.length >= 8;
            const hasLetter = /[a-zA-Z]/.test(val);
            const hasNumber = /[0-9]/.test(val);

            if (hasLength && hasLetter && hasNumber) {
                createUserHint.style.color = 'var(--lime, #84cc16)';
                createUserHint.textContent = '✓ Password meets requirements';
            } else {
                const missing = [];
                if (!hasLength) missing.push(`${val.length}/8 characters`);
                if (!hasLetter) missing.push('letter');
                if (!hasNumber) missing.push('number');
                createUserHint.style.color = 'var(--danger, #ef4444)';
                createUserHint.textContent = 'Requires: ' + missing.join(', ');
            }
        });
    }

    function showCreateUserError(msg) {
        if (!createUserForm) return;
        let errBanner = document.getElementById('createUserErrorBanner');
        if (!errBanner) {
            errBanner = document.createElement('div');
            errBanner.id = 'createUserErrorBanner';
            errBanner.style.cssText = 'grid-column: 1 / -1; display: flex; align-items: center; gap: 8px; background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.35); color: #ef4444; border-radius: 8px; padding: 10px 12px; font-size: 12.5px; font-weight: 600; margin-bottom: 2px;';
            const firstLabel = createUserForm.querySelector('label');
            if (firstLabel) {
                createUserForm.insertBefore(errBanner, firstLabel);
            } else {
                createUserForm.prepend(errBanner);
            }
        }
        errBanner.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg><span>' + (msg || 'Unable to create user.') + '</span>';
        errBanner.style.display = 'flex';

        if (msg && msg.toLowerCase().includes('email')) {
            const emailInput = createUserForm.querySelector('input[name="email"]');
            if (emailInput) {
                emailInput.style.borderColor = '#ef4444';
                emailInput.style.boxShadow = '0 0 0 2px rgba(239, 68, 68, 0.2)';
                emailInput.focus();
            }
        }
    }

    if (createUserForm) {
        createUserForm.addEventListener('input', function(e) {
            const errBanner = document.getElementById('createUserErrorBanner');
            if (errBanner) {
                errBanner.style.display = 'none';
            }
            if (e.target && e.target.style) {
                e.target.style.borderColor = '';
                e.target.style.boxShadow = '';
            }
        });

        createUserForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const pass = createUserPass ? createUserPass.value : '';
            if (pass.length < 8 || !/[a-zA-Z]/.test(pass) || !/[0-9]/.test(pass)) {
                showCreateUserError('Password must be at least 8 characters, with a letter and a number.');
                Swal.fire({
                    icon: 'warning',
                    title: 'Password Requirement',
                    text: 'Password must be at least 8 characters, with a letter and a number, and not be a common password.',
                    confirmButtonColor: 'var(--lime-dark)',
                    target: document.getElementById('createUserModal') || document.body
                });
                createUserPass?.focus();
                return;
            }

            const submitBtn = document.getElementById('createUserSubmitBtn') || createUserForm.querySelector('button[type="submit"]') || createUserForm.querySelector('button:not([type="button"])');
            const origBtnText = submitBtn ? submitBtn.innerHTML : 'Create user';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="loader" style="width:14px;height:14px;border:2px solid currentColor;border-bottom-color:transparent;border-radius:50%;display:inline-block;animation:rotation 1s linear infinite;margin-right:8px;vertical-align:-2px;"></span> Creating user...';
            }

            try {
                const formData = new FormData(createUserForm);
                formData.append('ajax', '1');

                const response = await fetch('index.php?page=users', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });

                const data = await response.json();

                if (!data.success) {
                    if (data.csrf_token) {
                        const csrfInput = createUserForm.querySelector('input[name="csrf_token"]');
                        if (csrfInput) csrfInput.value = data.csrf_token;
                        CURRENT_CSRF_TOKEN = data.csrf_token;
                    }
                    showCreateUserError(data.message || 'An error occurred.');
                    Swal.fire({
                        icon: 'error',
                        title: 'Unable to Create User',
                        text: data.message || 'An error occurred.',
                        confirmButtonColor: 'var(--lime-dark)',
                        target: document.getElementById('createUserModal') || document.body
                    });
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = origBtnText;
                    }
                    return;
                }

                // Success
                document.getElementById('createUserModal')?.close();
                Swal.fire({
                    icon: 'success',
                    title: 'User Created',
                    text: data.message,
                    confirmButtonColor: 'var(--lime-dark)'
                }).then(() => {
                    window.location.reload();
                });

            } catch (err) {
                console.error('Create user fetch error:', err);
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = origBtnText;
                }
                showCreateUserError('An unexpected network or server error occurred. Please try again.');
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An unexpected network error occurred. Please check your connection and try again.',
                    confirmButtonColor: 'var(--lime-dark)',
                    target: document.getElementById('createUserModal') || document.body
                });
            }
        });
    }

    // ── Global Custom Role Dropdown Initialization ─────────────────────────
    const roleItems = [];
    if (IS_ADMIN) {
        roleItems.push(
            { id: 'gym_owner', label: 'Gym Owner' },
            { id: 'member', label: 'Member' }
        );
    } else {
        roleItems.push(
            { id: 'staff', label: 'Front Desk / Staff' },
            { id: 'trainer', label: 'Trainer' },
            { id: 'member', label: 'Member' }
        );
    }

    if (typeof FitDropdown !== 'undefined') {
        window.createUserRoleDropdown = new FitDropdown({
            container: '#roleComboboxWrap',
            name: 'role',
            id: 'new_user_role',
            value: initialRole,
            searchable: false,
            zIndex: 100,
            items: roleItems,
            onChange: function(item) {
                // FitDropdown passes the selected item object ({id, label}), not the id string
                toggleTrainerFields((item && typeof item === 'object') ? item.id : item);
            }
        });
        if (!IS_ADMIN && document.getElementById('staffRoleComboboxWrap')) {
            window.createUserStaffRoleDropdown = new FitDropdown({
                container: '#staffRoleComboboxWrap',
                name: 'staff_role',
                id: 'new_user_staff_role',
                value: FT_USERS_CONFIG.oldUserStaffRole || 'front_desk',
                searchable: false,
                zIndex: 90,
                items: [
                    { id: 'front_desk', label: 'Front Desk / Cashier (Financials Masked)' },
                    { id: 'manager', label: 'Operations Manager (Full Staff Access)' }
                ]
            });
        }
        toggleTrainerFields(initialRole);
    }

    // ── Member Progress Quick Modal Engine ────────────────────────────────
    let currentProgressMemberId = null;

    window.openMemberProgressModal = function(memberId, memberName) {
        currentProgressMemberId = memberId;
        const modal = document.getElementById('memberProgressModal');
        if (!modal) return;

        const titleEl = document.getElementById('mpModalTitle');
        const subEl = document.getElementById('mpModalSubtitle');
        const skeletonEl = document.getElementById('mpLoadingSkeleton');
        const contentEl = document.getElementById('mpLoadedContent');
        const errorEl = document.getElementById('mpErrorState');

        if (titleEl) titleEl.textContent = `${memberName}'s Progress`;
        if (subEl) subEl.textContent = 'Live fitness tracking & metrics overview';

        if (skeletonEl) skeletonEl.style.display = 'flex';
        if (contentEl) contentEl.style.display = 'none';
        if (errorEl) errorEl.style.display = 'none';

        modal.showModal();

        fetchMemberProgress(memberId);
    };

    window.retryMemberProgress = function() {
        if (currentProgressMemberId) {
            const skeletonEl = document.getElementById('mpLoadingSkeleton');
            const contentEl = document.getElementById('mpLoadedContent');
            const errorEl = document.getElementById('mpErrorState');
            if (skeletonEl) skeletonEl.style.display = 'flex';
            if (contentEl) contentEl.style.display = 'none';
            if (errorEl) errorEl.style.display = 'none';
            fetchMemberProgress(currentProgressMemberId);
        }
    };

    const retryBtn = document.getElementById('mpRetryBtn');
    if (retryBtn) {
        retryBtn.addEventListener('click', window.retryMemberProgress);
    }

    function fetchMemberProgress(memberId) {
        fetch(`index.php?page=users&action=get_member_progress&member_user_id=${encodeURIComponent(memberId)}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            const skeletonEl = document.getElementById('mpLoadingSkeleton');
            const contentEl = document.getElementById('mpLoadedContent');
            const errorEl = document.getElementById('mpErrorState');

            if (!data || !data.success) {
                if (skeletonEl) skeletonEl.style.display = 'none';
                if (contentEl) contentEl.style.display = 'none';
                if (errorEl) errorEl.style.display = 'block';
                const msgEl = document.getElementById('mpErrorMessage');
                if (msgEl) msgEl.textContent = data.message || 'Unable to load member progress.';
                return;
            }

            const m = data.member;
            const s = data.stats;
            const c = data.measurements;
            const h = data.history || [];

            // KPIs
            const curWeightEl = document.getElementById('mpCurrentWeight');
            if (curWeightEl) curWeightEl.innerHTML = (s.current_weight !== null && s.current_weight !== undefined) ? `${s.current_weight} <small>kg</small>` : '—';

            const deltaPill = document.getElementById('mpWeightDeltaPill');
            const weightSub = document.getElementById('mpWeightSub');
            if (deltaPill) {
                deltaPill.className = `mp-delta-pill is-${s.delta_type || 'neutral'}`;
                deltaPill.textContent = s.weight_delta ? s.weight_delta : 'Baseline';
            }
            if (weightSub) {
                if (s.starting_weight && s.current_weight && Math.abs(s.current_weight - s.starting_weight) > 0.05) {
                    weightSub.textContent = `Start: ${s.starting_weight} kg (${s.weight_delta_pct > 0 ? '+' : ''}${s.weight_delta_pct}%)`;
                } else if (s.starting_weight) {
                    weightSub.textContent = `Start: ${s.starting_weight} kg`;
                } else {
                    weightSub.textContent = 'Baseline weigh-in';
                }
            }

            const bfEl = document.getElementById('mpBodyFat');
            if (bfEl) bfEl.innerHTML = (s.body_fat !== null && s.body_fat !== undefined) ? `${s.body_fat} <small>%</small>` : '—';
            const targetBfLabel = document.getElementById('mpTargetBfLabel');
            if (targetBfLabel) targetBfLabel.textContent = s.target_body_fat ? `Target: ${s.target_body_fat}%` : 'Target: —';

            const bmiEl = document.getElementById('mpBmi');
            if (bmiEl) bmiEl.textContent = s.bmi || '—';
            const bmiCatEl = document.getElementById('mpBmiCategory');
            if (bmiCatEl) bmiCatEl.textContent = s.bmi_category ? `${s.bmi_category}${m.height_cm ? ' • ' + m.height_cm + ' cm' : ''}` : '—';

            const scoreEl = document.getElementById('mpScore');
            if (scoreEl) scoreEl.innerHTML = `${s.engagement_score} <small>/100</small>`;
            const checkinsLabel = document.getElementById('mpCheckinsLabel');
            if (checkinsLabel) checkinsLabel.textContent = `${s.total_checkins} Check-ins • ${s.engagement_tier}`;

            // Fitness Goal & Milestone Track
            const goalNameEl = document.getElementById('mpGoalName');
            if (goalNameEl) goalNameEl.textContent = s.primary_goal;
            const weeklyTargetEl = document.getElementById('mpWeeklyTarget');
            if (weeklyTargetEl) weeklyTargetEl.textContent = `${s.weekly_target} workouts / week`;
            const targetWeightEl = document.getElementById('mpTargetWeight');
            if (targetWeightEl) targetWeightEl.textContent = s.target_weight ? `Target: ${s.target_weight} kg` : 'Target: Open';

            const progBar = document.getElementById('mpProgressBar');
            if (progBar) progBar.style.width = `${s.progress_pct || 50}%`;
            const startLegend = document.getElementById('mpStartWeightLegend');
            if (startLegend) startLegend.textContent = s.starting_weight ? `Start: ${s.starting_weight} kg` : 'Start: —';
            const pctLegend = document.getElementById('mpProgressPctLegend');
            if (pctLegend) pctLegend.textContent = `${s.progress_pct}% to milestone`;
            const targetLegend = document.getElementById('mpTargetWeightLegend');
            if (targetLegend) targetLegend.textContent = s.target_weight ? `Target: ${s.target_weight} kg` : 'Target: —';

            // Circumferences
            const setCirc = (id, val) => {
                const el = document.getElementById(id);
                const hasVal = (val !== null && val !== undefined && val > 0);
                if (el) el.textContent = hasVal ? `${val} cm` : '—';
                const box = el ? el.closest('.mp-circ-box') : null;
                if (box) {
                    box.classList.toggle('is-empty', !hasVal);
                    if (!hasVal) {
                        box.title = 'No measurement recorded yet';
                    } else {
                        box.removeAttribute('title');
                    }
                }
            };
            setCirc('mpCircWaist', c.waist_cm);
            setCirc('mpCircChest', c.chest_cm);
            setCirc('mpCircArms', c.arm_cm);
            setCirc('mpCircNeck', c.neck_cm);
            setCirc('mpCircHips', c.hips_cm);

            const lastDateEl = document.getElementById('mpCircLastDate');
            if (lastDateEl) lastDateEl.textContent = c.last_log_date ? `Recorded ${c.last_log_date}` : 'No date recorded';

            // Recent history
            const tbody = document.getElementById('mpHistoryTableBody');
            const emptyEl = document.getElementById('mpHistoryEmpty');
            const tableWrap = document.getElementById('mpHistoryTableWrap');
            const totalLogsEl = document.getElementById('mpTotalLogsCount');

            if (totalLogsEl) totalLogsEl.textContent = `${s.total_logs} log${s.total_logs === 1 ? '' : 's'} total`;

            if (tbody) {
                if (h.length === 0) {
                    if (emptyEl) emptyEl.style.display = 'block';
                    if (tableWrap) tableWrap.style.display = 'none';
                    tbody.innerHTML = '';
                } else {
                    if (emptyEl) emptyEl.style.display = 'none';
                    if (tableWrap) tableWrap.style.display = 'block';
                    tbody.innerHTML = h.map(item => `
                        <tr>
                            <td><strong>${escapeUserHtml(item.date)}</strong></td>
                            <td>${item.weight_kg ? `${item.weight_kg} kg` : '—'}</td>
                            <td>${item.body_fat ? `${item.body_fat}%` : '—'}</td>
                            <td>${item.waist_cm ? `W: ${item.waist_cm}cm` : ''} ${item.chest_cm ? `C: ${item.chest_cm}cm` : ''}</td>
                            <td style="color:var(--muted); font-size:11px;">${escapeUserHtml(item.notes || '—')}</td>
                        </tr>
                    `).join('');
                }
            }

            // Quick links
            const dietLink = document.getElementById('mpDietPlanLink');
            if (dietLink) dietLink.href = data.diet_url || '#';
            const fullHubLink = document.getElementById('mpFullHubLink');
            if (fullHubLink) fullHubLink.href = data.hub_url || '#';

            if (skeletonEl) skeletonEl.style.display = 'none';
            if (errorEl) errorEl.style.display = 'none';
            if (contentEl) contentEl.style.display = 'flex';
        })
        .catch(err => {
            console.error('Failed to fetch member progress:', err);
            const skeletonEl = document.getElementById('mpLoadingSkeleton');
            const contentEl = document.getElementById('mpLoadedContent');
            const errorEl = document.getElementById('mpErrorState');
            if (skeletonEl) skeletonEl.style.display = 'none';
            if (contentEl) contentEl.style.display = 'none';
            if (errorEl) errorEl.style.display = 'block';
            const msgEl = document.getElementById('mpErrorMessage');
            if (msgEl) msgEl.textContent = 'Network or server error while loading progress.';
        });
    }
