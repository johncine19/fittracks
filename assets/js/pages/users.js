/**
 * Users Management Controller
 * Extracted from admin/users.php
 */

const FT_USERS_CONFIG = window.USERS_CONFIG || {};
const CURRENT_TAB = FT_USERS_CONFIG.currentTab || 'all';
const GYM_FILTER = FT_USERS_CONFIG.gymFilter || '';
const IS_ADMIN = Boolean(FT_USERS_CONFIG.isAdmin);
let CURRENT_CSRF_TOKEN = FT_USERS_CONFIG.csrfToken || (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
const initialRole = FT_USERS_CONFIG.initialRole || (IS_ADMIN ? 'gym_owner' : 'trainer');

function toggleTrainerFields(role) {
    const tf = document.getElementById('trainer_fields');
    const spec = document.getElementById('new_user_spec');
    if (!tf) return;
    if (role === 'trainer') {
        tf.style.display = 'grid';
        tf.style.gridTemplateColumns = 'repeat(2, minmax(0, 1fr))';
        if (spec) spec.required = true;
    } else {
        tf.style.display = 'none';
        if (spec) spec.required = false;
    }
}

if (FT_USERS_CONFIG.hasOldUser) {
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('createUserModal')?.showModal();
    });
}

    function toggleEditTrainerFields(role) {
        const tf = document.getElementById('eu_trainer_fields');
        const spec = document.getElementById('eu_spec');
        if (!tf || !spec) return;
        if (role === 'trainer') {
            tf.style.display = 'flex';
            spec.required = true;
        } else {
            tf.style.display = 'none';
            spec.required = false;
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
            html: `
                <form id="editUserForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <input type="hidden" name="csrf_token" value="${CURRENT_CSRF_TOKEN}">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" id="eu_id">
                    <input type="hidden" name="admin_password" id="eu_admin_pass">
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">First name * <input name="first_name" id="eu_fn" class="form-control" required autocapitalize="words" style="width: 100%; box-sizing: border-box; text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())"></label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Last name * <input name="last_name" id="eu_ln" class="form-control" required autocapitalize="words" style="width: 100%; box-sizing: border-box; text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())"></label>
                    </div>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Email * <input type="email" name="email" id="eu_email" class="form-control" required style="width: 100%; box-sizing: border-box; text-transform: lowercase;" oninput="this.value = this.value.toLowerCase()" onblur="this.value = this.value.trim().toLowerCase()"></label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Mobile Number * <input type="tel" name="phone" id="eu_phone" class="form-control" pattern="[0-9]{11}" maxlength="11" title="Please enter exactly 11 digits" placeholder="09123456789" required style="width: 100%; box-sizing: border-box;"></label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Role *
                        <select name="role" id="eu_role" class="form-control" style="width: 100%; box-sizing: border-box;" onchange="toggleEditTrainerFields(this.value)">
                            ${IS_ADMIN ? `
                                <option value="gym_owner">Gym Owner</option>
                            ` : ''}
                            <option value="trainer">Trainer</option>
                            <option value="member">Member</option>
                        </select>
                    </label>
                    <div id="eu_trainer_fields" style="display: none; flex-direction: column; gap: 12px;">
                        <label style="display:block; color: var(--muted); font-size: 14px;">Specialization <small style="font-weight:400">(trainer only)</small>
                            <input name="specialization" id="eu_spec" class="form-control" placeholder="e.g. Strength & Conditioning" style="width: 100%; box-sizing: border-box;">
                        </label>
                        <label style="display:block; color: var(--muted); font-size: 14px;">Staff Permission Level
                            <select name="staff_role" id="eu_staff_role" class="form-control" style="width: 100%; box-sizing: border-box;">
                                <option value="trainer">Fitness Trainer (Floor Staff)</option>
                                <option value="front_desk">Front Desk / Cashier (Financials Masked)</option>
                                <option value="manager">Operations Manager (Full Staff Access)</option>
                            </select>
                        </label>
                        <label style="display:block; color: var(--muted); font-size: 14px;">Bio <small style="font-weight:400">(trainer only)</small>
                            <input name="bio" id="eu_bio" class="form-control" placeholder="Short bio" style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                    <label style="display:block; color: var(--muted); font-size: 14px;">New Password <small>(leave blank to keep current)</small> <input type="password" name="new_password" id="eu_pass" class="form-control" style="width: 100%; box-sizing: border-box;"></label>
                </form>
            `,
            didOpen: () => {
                document.getElementById('eu_id').value = u.user_id;
                document.getElementById('eu_fn').value = u.first_name;
                document.getElementById('eu_ln').value = u.last_name;
                document.getElementById('eu_email').value = u.email;
                document.getElementById('eu_phone').value = u.phone || '';
                document.getElementById('eu_role').value = u.role;
                document.getElementById('eu_spec').value = u.specialization || '';
                if (document.getElementById('eu_staff_role')) {
                    document.getElementById('eu_staff_role').value = u.staff_role || 'trainer';
                }
                document.getElementById('eu_bio').value = u.bio || '';
                toggleEditTrainerFields(u.role);
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

    // Cache initial server-rendered content so clearing search is instantaneous
    const initialTableHtml = userTableBody ? userTableBody.innerHTML : '';
    const initialCardsHtml = userMobileCards ? userMobileCards.innerHTML : '';
    const initialCountText = userCountLabel ? userCountLabel.textContent : '';
    const initialEmptyStateDisplay = userEmptyState ? userEmptyState.style.display : 'none';

    let userSearchDebounceTimer = null;

    function escapeUserHtml(str) {
        if (!str && str !== 0) return '';
        return String(str).replace(/[&<>"']/g, function(m) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[m];
        });
    }

    function clearUserSearch(focusInput = true) {
        if (userSearchInput) userSearchInput.value = '';
        if (userSearchClear) userSearchClear.style.display = 'none';
        if (userSearchStatus) userSearchStatus.style.display = 'none';

        // Restore initial server rendered HTML
        if (userTableBody) userTableBody.innerHTML = initialTableHtml;
        if (userMobileCards) userMobileCards.innerHTML = initialCardsHtml;
        if (userCountLabel) userCountLabel.textContent = initialCountText;
        if (userEmptyState) userEmptyState.style.display = initialEmptyStateDisplay;
        if (userDesktopTableWrap) userDesktopTableWrap.style.display = initialEmptyStateDisplay === 'block' ? 'none' : '';
        if (userMobileCards) userMobileCards.style.display = initialEmptyStateDisplay === 'block' ? 'none' : '';
        if (userPagination) userPagination.style.display = initialEmptyStateDisplay === 'block' ? 'none' : '';

        if (focusInput && userSearchInput) {
            userSearchInput.focus();
        }
    }

    function renderDesktopRow(u, csrfToken) {
        const roleClass = 'badge badge-' + u.role;
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
        const scoreHtml = u.is_member
            ? `<strong style="color:var(--lime);">${u.engagement_score}</strong>`
            : `<span class="muted">—</span>`;
        const rawUserJson = escapeUserHtml(JSON.stringify(u.raw_user));
        const staffBadge = (u.role === 'trainer' && u.staff_role && u.staff_role !== 'trainer')
            ? `<span class="badge" style="font-size:10px; margin-left:4px; background:rgba(59,130,246,0.15); color:#93c5fd; border:1px solid rgba(59,130,246,0.3);">${u.staff_role === 'front_desk' ? 'Front Desk' : 'Manager'}</span>`
            : '';

        return `
        <tr class="user-table-row" data-name="${escapeUserHtml(u.full_name.toLowerCase())}" data-email="${escapeUserHtml(u.email.toLowerCase())}" data-phone="${escapeUserHtml(u.phone)}" data-role="${escapeUserHtml(u.role)}" data-id="${u.user_id}">
            <td>
                <div class="user-cell">
                    ${u.avatar_html}
                    <div class="user-cell-info">
                        ${escapeUserHtml(u.full_name)}
                        <small>#${u.user_id}</small>
                    </div>
                </div>
            </td>
            <td style="color:var(--muted)">${escapeUserHtml(u.email)}</td>
            <td><span class="${roleClass}">${roleDisplayName}</span>${staffBadge}</td>
            ${IS_ADMIN ? `<td class="user-gym-td">${associatedGymHtml}</td>` : ''}
            ${CURRENT_TAB === 'trainer' ? `<td>${specHtml}</td>` : ''}
            ${CURRENT_TAB === 'member' ? `<td>${scoreHtml}</td>` : ''}
            <td><span class="${statusClass}">${escapeUserHtml(u.status)}</span></td>
            <td style="color:var(--muted);font-size:12px">${escapeUserHtml(u.joined_formatted)}</td>
            <td>
                <div style="display:flex;gap:4px;align-items:center;">
                    <form method="post" class="row-actions" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="${csrfToken}">
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="user_id" value="${u.user_id}">
                        <select name="status" style="width:auto;padding:6px 10px;font-size:12px;margin:0">
                            <option value="active" ${u.status === 'active' ? 'selected' : ''}>active</option>
                            <option value="suspended" ${u.status === 'suspended' ? 'selected' : ''}>suspended</option>
                        </select>
                        <button type="submit" class="btn-sm btn-ghost">Update</button>
                    </form>
                    <button type="button" class="btn btn-secondary btn-edit-user" data-user="${rawUserJson}" style="padding:4px 8px;font-size:12px;margin-left:8px;">Edit</button>
                    ${u.is_member ? `
                        <a href="index.php?page=diet_builder&member_user_id=${u.user_id}&ref=users" class="btn btn-secondary" style="padding:4px 8px;font-size:12px;text-decoration:none;display:inline-flex;align-items:center;gap:4px;" title="Manage Diet Plan">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                            Diet Plan
                        </a>
                    ` : ''}
                    ${u.can_delete ? `
                        <button type="button" class="btn btn-danger btn-delete-user" data-user-id="${u.user_id}" data-user-name="${escapeUserHtml(u.full_name)}" data-user-role="${escapeUserHtml(u.role)}" style="padding:4px 8px;font-size:12px;">Delete</button>
                    ` : ''}
                </div>
            </td>
        </tr>
        `;
    }

    function renderMobileCard(u, csrfToken) {
        const roleClass = 'badge badge-' + u.role;
        const statusClass = 'badge badge-' + u.status;
        const roleDisplayName = escapeUserHtml(u.role_display);
        const rawUserJson = escapeUserHtml(JSON.stringify(u.raw_user));
        const mobileStaffBadge = (u.role === 'trainer' && u.staff_role && u.staff_role !== 'trainer')
            ? `<span class="badge" style="font-size:10px; background:rgba(59,130,246,0.15); color:#93c5fd; border:1px solid rgba(59,130,246,0.3);">${u.staff_role === 'front_desk' ? 'Front Desk' : 'Manager'}</span>`
            : '';

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
                    ${u.avatar_html}
                    <div class="user-card-names">
                        <div class="user-card-fullname">${escapeUserHtml(u.full_name)}</div>
                        <div class="user-card-id">#${u.user_id}</div>
                    </div>
                </div>
                <div class="user-card-badges">
                    <span class="${roleClass}">${roleDisplayName}</span>
                    ${mobileStaffBadge}
                    <span class="${statusClass}">${escapeUserHtml(u.status)}</span>
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
                        Score
                    </span>
                    <span class="user-card-detail-value"><strong style="color:var(--lime);">${u.engagement_score}</strong> / 100</span>
                </div>
                ` : ''}

                <div class="user-card-detail-item">
                    <span class="user-card-detail-label">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Joined
                    </span>
                    <span class="user-card-detail-value">${escapeUserHtml(u.joined_formatted)}</span>
                </div>
            </div>

            <div class="user-card-actions">
                <form method="post" class="user-card-status-form">
                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="user_id" value="${u.user_id}">
                    <span class="user-card-status-label">Status:</span>
                    <select name="status">
                        <option value="active" ${u.status === 'active' ? 'selected' : ''}>Active</option>
                        <option value="suspended" ${u.status === 'suspended' ? 'selected' : ''}>Suspended</option>
                    </select>
                    <button type="submit" class="btn-sm btn-ghost">Update</button>
                </form>
                
                <div class="user-card-btn-group">
                    <button type="button" class="btn btn-secondary btn-sm btn-edit-user" data-user="${rawUserJson}">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                        Edit
                    </button>
                    ${u.is_member ? `
                        <a href="index.php?page=diet_builder&member_user_id=${u.user_id}&ref=users" class="btn btn-secondary btn-sm" title="Manage Diet Plan">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                            Diet
                        </a>
                    ` : ''}
                    ${u.can_delete ? `
                    <button type="button" class="btn btn-danger btn-sm btn-delete-user" data-user-id="${u.user_id}" data-user-name="${escapeUserHtml(u.full_name)}" data-user-role="${escapeUserHtml(u.role)}">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        Delete
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
    }

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

    if (createUserForm) {
        createUserForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const pass = createUserPass ? createUserPass.value : '';
            if (pass.length < 8 || !/[a-zA-Z]/.test(pass) || !/[0-9]/.test(pass)) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Password Requirement',
                    text: 'Password must be at least 8 characters, with a letter and a number, and not be a common password.',
                    confirmButtonColor: 'var(--lime-dark)'
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
                    // KEEP MODAL OPEN, ALL CREDENTIALS INTACT!
                    Swal.fire({
                        icon: 'error',
                        title: 'Unable to Create User',
                        text: data.message || 'An error occurred.',
                        confirmButtonColor: 'var(--lime-dark)'
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
                createUserForm.submit();
            }
        });
    }

    // ── Global Custom Role Dropdown Initialization ─────────────────────────
    const roleItems = [];
    if (IS_ADMIN) {
        roleItems.push({
            id: 'gym_owner',
            label: 'Gym Owner',
            icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#c084fc" stroke-width="2"><path d="M3 21h18M3 7v14M21 7v14M6 11h3M6 15h3M15 11h3M15 15h3M9 3l3 3 3-3"/></svg>'
        });
    }
    roleItems.push(
        {
            id: 'trainer',
            label: 'Trainer',
            icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2"><path d="M6 4v16M18 4v16M2 8h4M18 8h4M2 16h4M18 16h4M6 12h12"/></svg>'
        },
        {
            id: 'member',
            label: 'Member',
            icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2dd4bf" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>'
        }
    );

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
        toggleTrainerFields(initialRole);
    }
