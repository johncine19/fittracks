/**
 * Trainer Training Dashboard Controller
 * Extracted from trainer/training.php
 */

const FT_TRAINING_CONFIG = window.TRAINING_CONFIG || {};
const csrfToken = FT_TRAINING_CONFIG.csrfToken || (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
window.planMembersData = FT_TRAINING_CONFIG.planMembersData || [];
const allGymMembers = FT_TRAINING_CONFIG.allGymMembers || [];

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[m];
    });
}

// Tab Switching Controller
function switchWorkoutView(view) {
    const btnCreate  = document.getElementById('btn-view-create');
    const btnAll     = document.getElementById('btn-view-all');
    const viewCreate = document.getElementById('view-create-workouts');
    const viewAll    = document.getElementById('view-all-workouts');

    if (view === 'all') {
        btnAll?.classList.add('active');
        btnCreate?.classList.remove('active');
        if (viewAll) viewAll.style.display = 'block';
        if (viewCreate) viewCreate.style.display = 'none';
        try { localStorage.setItem('fittracks_workouts_active_tab', 'all'); } catch (e) {}
        try { history.replaceState(null, '', 'index.php?page=training&tab=all'); } catch (e) {}
    } else {
        btnCreate?.classList.add('active');
        btnAll?.classList.remove('active');
        if (viewCreate) viewCreate.style.display = 'block';
        if (viewAll) viewAll.style.display = 'none';
        try { localStorage.setItem('fittracks_workouts_active_tab', 'create'); } catch (e) {}
        try { history.replaceState(null, '', 'index.php?page=training&tab=create'); } catch (e) {}
    }
}

// Client-Side Search & Filter for All Workouts Table
window.activeAllWorkoutsStatusFilter = 'all';

function filterAllWorkouts() {
    const searchInput = document.getElementById('all-workouts-search');
    const q = (searchInput?.value || '').toLowerCase().trim();
    const filter = window.activeAllWorkoutsStatusFilter || 'all';
    const rows = document.querySelectorAll('.all-workout-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const rowSearch = (row.dataset.search || '').toLowerCase();
        const rowStatus = (row.dataset.status || '').toLowerCase();

        const matchesSearch = !q || rowSearch.includes(q);
        const matchesStatus = (filter === 'all') || (rowStatus === filter);

        if (matchesSearch && matchesStatus) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const noResults = document.getElementById('all-workouts-no-results');
    if (noResults) {
        noResults.style.display = (visibleCount === 0) ? 'block' : 'none';
    }
}

function setAllWorkoutsStatusFilter(status, btn) {
    window.activeAllWorkoutsStatusFilter = status;
    document.querySelectorAll('.all-workout-filter-pill').forEach(el => el.classList.remove('active'));
    if (btn) btn.classList.add('active');
    filterAllWorkouts();
}

// window.planMembersData initialized from FT_TRAINING_CONFIG

let planMemberDrop = null;
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('planMemberWrap')) {
        planMemberDrop = new FitDropdown({
            container: '#planMemberWrap',
            name: 'member_user_id',
            placeholder: 'Search member by name or email...',
            searchable: true,
            searchPlaceholder: 'Search members...',
            allowClear: true,
            zIndex: 90,
            items: window.planMembersData,
            onChange: (selectedMember) => {
                const goalInput = document.getElementById('planGoalInput');
                if (goalInput && selectedMember && selectedMember.goal) {
                    goalInput.value = selectedMember.goal;
                }
            }
        });
    }

    const addPlanForm = document.getElementById('addPlanForm');
    if (addPlanForm) {
        addPlanForm.addEventListener('submit', function(e) {
            const memberInput = addPlanForm.querySelector('input[name="member_user_id"]');
            if (!memberInput || !memberInput.value) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Please Select a Member',
                    text: 'You must select a member before creating a workout plan.',
                    confirmButtonColor: 'var(--lime-dark)',
                    background: 'var(--bg)',
                    color: 'var(--ink)'
                });
                return false;
            }
        });
    }

    // Auto-restore saved tab unless specific URL query parameter overrides it
    const urlParams = new URLSearchParams(window.location.search);
    const forcedTab = urlParams.get('tab');
    const hasViewPlan = urlParams.get('view_plan_id');

    if (hasViewPlan || forcedTab === 'all') {
        switchWorkoutView('all');
    } else if (forcedTab === 'create') {
        switchWorkoutView('create');
    } else {
        const saved = localStorage.getItem('fittracks_workouts_active_tab');
        if (saved === 'all') {
            switchWorkoutView('all');
        }
    }
});

// Edit Training Plan Modal (SweetAlert2)
function editPlan(p) {
    let membersOptions = `<option value="${p.member_user_id}" selected>${p.member || 'Current Member'}</option>`;
    allGymMembers.forEach(m => {
        if (String(m.member_user_id) !== String(p.member_user_id)) {
            membersOptions += `<option value="${m.member_user_id}">${escapeHtml(m.name)}</option>`;
        }
    });

    Swal.fire({
        title: 'Edit Training Plan',
        html: `
            <form id="editPlanForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                <input type="hidden" name="csrf_token" value="${csrfToken}">
                <input type="hidden" name="action" value="edit_plan">
                <input type="hidden" name="plan_id" id="ep_id">
                <label style="display:block; color: var(--muted); font-size: 13px; font-weight:600;">Member
                    <select name="member_user_id" id="ep_member" class="form-control" style="width: 100%; box-sizing: border-box; margin-top:5px; background:var(--panel); color:var(--ink); border:1px solid var(--line); border-radius:8px; padding:8px 12px;" required>
                        ${membersOptions}
                    </select>
                </label>
                <label style="display:block; color: var(--muted); font-size: 13px; font-weight:600;">Plan Title * 
                    <input name="title" id="ep_title" class="form-control" required style="width: 100%; box-sizing: border-box; margin-top:5px; background:var(--panel); color:var(--ink); border:1px solid var(--line); border-radius:8px; padding:8px 12px;">
                </label>
                <label style="display:block; color: var(--muted); font-size: 13px; font-weight:600;">Goal 
                    <input name="goal" id="ep_goal" class="form-control" style="width: 100%; box-sizing: border-box; margin-top:5px; background:var(--panel); color:var(--ink); border:1px solid var(--line); border-radius:8px; padding:8px 12px;">
                </label>
                <label style="display:block; color: var(--muted); font-size: 13px; font-weight:600;">Start Date * 
                    <input name="start_date" id="ep_start" type="date" class="form-control" required style="width: 100%; box-sizing: border-box; margin-top:5px; background:var(--panel); color:var(--ink); border:1px solid var(--line); border-radius:8px; padding:8px 12px;">
                </label>
                <label style="display:block; color: var(--muted); font-size: 13px; font-weight:600;">End Date 
                    <input name="end_date" id="ep_end" type="date" class="form-control" style="width: 100%; box-sizing: border-box; margin-top:5px; background:var(--panel); color:var(--ink); border:1px solid var(--line); border-radius:8px; padding:8px 12px;">
                </label>
            </form>
        `,
        didOpen: () => {
            document.getElementById('ep_id').value = p.plan_id;
            document.getElementById('ep_member').value = p.member_user_id;
            document.getElementById('ep_title').value = p.title;
            document.getElementById('ep_goal').value = p.goal || '';
            document.getElementById('ep_start').value = p.start_date || '';
            document.getElementById('ep_end').value = p.end_date || '';
        },
        showCancelButton: true,
        confirmButtonText: 'Save Changes',
        cancelButtonText: 'Cancel',
        confirmButtonColor: 'var(--lime, #c7ff22)',
        cancelButtonColor: '#334155',
        background: getComputedStyle(document.documentElement).getPropertyValue('--panel-bg').trim() || '#121721',
        color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
        reverseButtons: true,
        preConfirm: () => {
            const form = document.getElementById('editPlanForm');
            if (!form.title.value || !form.start_date.value) {
                Swal.showValidationMessage('Title and start date are required');
                return false;
            }
            form.submit();
        }
    });
}

// Event delegation for plan action buttons
document.addEventListener('click', function(e) {
    const renewBtn = e.target.closest('.btn-renew-plan');
    if (renewBtn) {
        confirmRenewPlan(parseInt(renewBtn.getAttribute('data-plan-id'), 10), renewBtn.getAttribute('data-plan-title') || '');
        return;
    }
    const dupBtn = e.target.closest('.btn-duplicate-plan');
    if (dupBtn) {
        openDuplicateModal(parseInt(dupBtn.getAttribute('data-plan-id'), 10), dupBtn.getAttribute('data-plan-title') || '');
        return;
    }
    const delBtn = e.target.closest('.btn-delete-plan');
    if (delBtn) {
        confirmDeletePlan(parseInt(delBtn.getAttribute('data-plan-id'), 10), delBtn.getAttribute('data-plan-title') || '');
        return;
    }
});

// Duplicate Plan Modal (SweetAlert2)
function openDuplicateModal(planId, planTitle) {
    let membersOptions = '<option value="">-- Select Member --</option>';
    allGymMembers.forEach(m => {
        membersOptions += `<option value="${m.member_user_id}">${escapeHtml(m.name)}</option>`;
    });
    const safeTitle = planTitle ? `"${typeof escapeHtml === 'function' ? escapeHtml(planTitle) : planTitle}"` : 'this training plan';

    Swal.fire({
        title: 'Duplicate Plan',
        html: `
            <div style="text-align: left; margin-top: 8px;">
                <div style="display:flex; align-items:center; gap:10px; padding:10px 14px; background:color-mix(in srgb, var(--ink) 4%, transparent); border:1px solid var(--line); border-radius:10px; margin-bottom:14px;">
                    <div style="width:34px; height:34px; border-radius:8px; background:color-mix(in srgb, var(--lime) 15%, transparent); display:flex; align-items:center; justify-content:center; color:var(--lime); flex-shrink:0;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                    </div>
                    <div style="flex:1; min-width:0;">
                        <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--muted); font-weight:700;">Source Routine</div>
                        <div style="font-size:13.5px; font-weight:700; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${safeTitle}</div>
                    </div>
                </div>

                <form id="duplicatePlanForm" method="post" style="display: flex; flex-direction: column; gap: 12px;">
                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                    <input type="hidden" name="action" value="duplicate_plan">
                    <input type="hidden" name="plan_id" value="${planId}">
                    <p style="margin:0; color:var(--muted); font-size:13px; line-height:1.4;">Select the member who will receive a copy of this routine as a new draft:</p>
                    <label style="display:block; color: var(--ink); font-size: 13px; font-weight:600;">
                        Target Member <span style="color:var(--danger, #ef4444);">*</span>
                        <select name="target_member_id" class="form-control" style="width: 100%; box-sizing: border-box; margin-top:6px;" required>
                            <option value="">-- Select Target Member --</option>
                            ${membersOptions}
                        </select>
                    </label>
                </form>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Duplicate as Draft',
        cancelButtonText: 'Cancel',
        confirmButtonColor: 'var(--lime, #c7ff22)',
        cancelButtonColor: '#334155',
        background: getComputedStyle(document.documentElement).getPropertyValue('--panel-bg').trim() || '#121721',
        color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
        reverseButtons: true,
        focusCancel: true,
        preConfirm: () => {
            const form = document.getElementById('duplicatePlanForm');
            if (!form.target_member_id.value) {
                Swal.showValidationMessage('Please select a target member');
                return false;
            }
            form.submit();
        }
    });
}

// Delete Plan Confirmation Modal (SweetAlert2)
function confirmDeletePlan(planId, planTitle) {
    const safeTitle = planTitle ? `"${typeof escapeHtml === 'function' ? escapeHtml(planTitle) : planTitle}"` : 'this training plan';
    Swal.fire({
        title: 'Delete Training Plan?',
        html: `
            <div style="text-align:left; margin-top:8px;">
                <p style="margin:0; color:var(--ink); font-size:14px; line-height:1.4;">
                    Are you sure you want to permanently delete <strong>${safeTitle}</strong>?
                </p>
                <div style="display:flex; align-items:flex-start; gap:8px; margin-top:12px; padding:10px 12px; background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.25); border-radius:8px; color:#f87171; font-size:12px; line-height:1.4;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0; margin-top:1px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <span>This will remove all exercises, assigned schedule days, and member tracking data for this plan. This action cannot be undone.</span>
                </div>
            </div>
        `,
        icon: 'warning',
        iconColor: '#ef4444',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete Plan',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#334155',
        background: getComputedStyle(document.documentElement).getPropertyValue('--panel-bg').trim() || '#121721',
        color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
        reverseButtons: true,
        focusCancel: true
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'index.php?page=training';
            
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = csrfToken;
            form.appendChild(csrfInput);

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_plan';
            form.appendChild(actionInput);

            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'plan_id';
            idInput.value = planId;
            form.appendChild(idInput);

            const urlParams = new URLSearchParams(window.location.search);
            let currentTab = urlParams.get('tab') || '';
            if (!currentTab) {
                const viewAll = document.getElementById('view-all-workouts');
                if (viewAll && viewAll.style.display !== 'none') {
                    currentTab = 'all';
                } else {
                    currentTab = localStorage.getItem('fittracks_workouts_active_tab') || 'all';
                }
            }
            if (currentTab) {
                const tabInput = document.createElement('input');
                tabInput.type = 'hidden';
                tabInput.name = 'return_tab';
                tabInput.value = currentTab;
                form.appendChild(tabInput);
            }

            document.body.appendChild(form);
            form.submit();
        }
    });
}

// Renew Plan Confirmation Modal (SweetAlert2)
function confirmRenewPlan(planId, planTitle) {
    const safeTitle = planTitle ? `"${typeof escapeHtml === 'function' ? escapeHtml(planTitle) : planTitle}"` : 'this training plan';
    Swal.fire({
        title: 'Renew Training Plan?',
        html: `
            <div style="text-align:left; margin-top:8px;">
                <p style="margin:0; color:var(--ink); font-size:14px; line-height:1.4;">
                    Extend <strong>${safeTitle}</strong> for an additional <strong>4 weeks</strong>?
                </p>
                <p style="margin:8px 0 0 0; color:var(--muted); font-size:12.5px; line-height:1.4;">
                    The end date will be extended automatically and the routine will remain active for your client.
                </p>
            </div>
        `,
        icon: 'question',
        iconColor: 'var(--lime, #c7ff22)',
        showCancelButton: true,
        confirmButtonText: 'Yes, Renew Plan',
        cancelButtonText: 'Cancel',
        confirmButtonColor: 'var(--lime, #c7ff22)',
        cancelButtonColor: '#334155',
        background: getComputedStyle(document.documentElement).getPropertyValue('--panel-bg').trim() || '#121721',
        color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
        reverseButtons: true,
        focusCancel: false
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'index.php?page=training';
            
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = csrfToken;
            form.appendChild(csrfInput);

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'renew_plan';
            form.appendChild(actionInput);

            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'plan_id';
            idInput.value = planId;
            form.appendChild(idInput);

            document.body.appendChild(form);
            form.submit();
        }
    });
}
