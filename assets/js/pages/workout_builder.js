/**
 * Trainer Workout Builder Controller
 * Extracted from workout_builder.php
 */

const FT_WB_CONFIG = window.WORKOUT_BUILDER_CONFIG || {};
window.gymExercisesData = FT_WB_CONFIG.gymExercisesData || [];
const csrfToken = FT_WB_CONFIG.csrfToken || (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
const memberId = FT_WB_CONFIG.memberId || 0;
const userGoal = FT_WB_CONFIG.primaryGoal || '';
const prefDays = FT_WB_CONFIG.prefDays || 3;
const memberName = FT_WB_CONFIG.memberName || 'Member';
const hasMembership = Boolean(FT_WB_CONFIG.hasMembership);
const defaultStart = FT_WB_CONFIG.defaultStart || '';
const defaultEnd = FT_WB_CONFIG.defaultEnd || '';

    // 1. Templates Modal
    function openTemplatesModal() {
        const templatesHtml = document.getElementById('templateOptionsPayload').innerHTML;
        Swal.fire({
            title: 'Select Workout Split Preset',
            html: templatesHtml,
            showCancelButton: true,
            showConfirmButton: false,
            cancelButtonText: 'Cancel',
            cancelButtonColor: '#6c757d',
            background: 'var(--bg)',
            color: 'var(--ink)',
            width: 'min(94vw, 650px)'
        });
    }

    function selectTemplate(templateKey, templateTitle) {
        Swal.fire({
            title: 'Apply ' + templateTitle + '?',
            text: "This will populate your draft with this pre-built routine. Any existing exercises will be replaced. Continue?",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: 'var(--lime-dark, #84cc16)',
            cancelButtonColor: '#6c757d',
            background: 'var(--bg)',
            color: 'var(--ink)',
            confirmButtonText: 'Yes, Apply Template'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.showLoading();
                document.getElementById('templateKeyInput').value = templateKey;
                document.getElementById('applyTemplateForm').submit();
            }
        });
    }

    // 2. Advanced Auto-Generate Modal
    function openAdvancedAutoGenerateModal() {
        Swal.fire({
            title: 'Auto-Generate Training Routine',
            width: 'min(94vw, 560px)',
            html: `
                <div style="text-align: left; display: flex; flex-direction: column; gap: 14px; font-size: 14px;">
                    <p style="margin: 0; color: var(--muted); font-size: 13px;">
                        Antigravity Engine will configure a periodized split tailored to this member's physiological goals.
                    </p>
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13px; margin-bottom: 4px;">Primary Goal</label>
                        <select id="swal-gen-goal" class="form-control" style="width: 100%;">
                            <option value="weight_loss" ${userGoal === 'weight_loss' ? 'selected' : ''}>Fat Loss & Conditioning</option>
                            <option value="muscle_gain" ${userGoal === 'muscle_gain' ? 'selected' : ''}>Muscle Hypertrophy (Mass)</option>
                            <option value="strength" ${userGoal === 'strength' ? 'selected' : ''}>Max Strength & Power</option>
                            <option value="endurance" ${userGoal === 'endurance' ? 'selected' : ''}>Stamina & Endurance</option>
                            <option value="general_health" ${userGoal === 'general_health' ? 'selected' : ''}>General Health & Mobility</option>
                        </select>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div>
                            <label style="display:block; color: var(--muted); font-size: 13px; margin-bottom: 4px;">Frequency (Days/Wk)</label>
                            <select id="swal-gen-days" class="form-control" style="width: 100%;">
                                <option value="2" ${prefDays === 2 ? 'selected' : ''}>2 Days (Express)</option>
                                <option value="3" ${prefDays === 3 ? 'selected' : ''}>3 Days (Standard)</option>
                                <option value="4" ${prefDays === 4 ? 'selected' : ''}>4 Days (Upper/Lower)</option>
                                <option value="5" ${prefDays === 5 ? 'selected' : ''}>5 Days (Bro Split / PPL)</option>
                                <option value="6" ${prefDays === 6 ? 'selected' : ''}>6 Days (High Frequency)</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; color: var(--muted); font-size: 13px; margin-bottom: 4px;">Split Architecture</label>
                            <select id="swal-gen-split" class="form-control" style="width: 100%;">
                                <option value="auto" selected>Auto-Select Best Split</option>
                                <option value="full_body">Full Body Compound</option>
                                <option value="upper_lower">Upper / Lower Body</option>
                                <option value="ppl">Push / Pull / Legs</option>
                                <option value="body_part">Muscle Isolated Split</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13px; margin-bottom: 4px;">Athlete Experience Level</label>
                        <select id="swal-gen-exp" class="form-control" style="width: 100%;">
                            <option value="beginner">Beginner (Form & Foundation)</option>
                            <option value="intermediate" selected>Intermediate (Hypertrophy & Progressive)</option>
                            <option value="advanced">Advanced (Heavy Loading & RPE 8+)</option>
                        </select>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Generate Routine',
            confirmButtonColor: 'var(--lime-dark, #84cc16)',
            cancelButtonColor: '#6c757d',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                return {
                    goal: document.getElementById('swal-gen-goal').value,
                    days: document.getElementById('swal-gen-days').value,
                    split: document.getElementById('swal-gen-split').value,
                    exp: document.getElementById('swal-gen-exp').value
                };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.showLoading();
                document.getElementById('autoGenGoal').value = result.value.goal;
                document.getElementById('autoGenDays').value = result.value.days;
                document.getElementById('autoGenSplit').value = result.value.split;
                document.getElementById('autoGenExp').value = result.value.exp;
                document.getElementById('advancedAutoGenForm').submit();
            }
        });
    }

    // 3. Day Specific Auto-Gen Modal
    function openDayAutoGenModal(dayNum, dayName) {
        Swal.fire({
            title: 'Auto-Generate ' + dayName,
            width: 'min(94vw, 500px)',
            html: `
                <div style="text-align: left; display: flex; flex-direction: column; gap: 12px; font-size: 14px;">
                    <p style="margin: 0; color: var(--muted); font-size: 13px;">
                        Select muscle target for <strong>${dayName}</strong>:
                    </p>
                    <select id="swal-day-focus" class="form-control" style="width: 100%;">
                        <option value="auto" selected>Auto-Select (Balanced Routine)</option>
                        <option value="push">Push (Chest, Shoulders, Triceps)</option>
                        <option value="pull">Pull (Back, Biceps, Rear Delts)</option>
                        <option value="legs">Legs (Quads, Hamstrings, Calves)</option>
                        <option value="upper">Upper Body (Compound Mix)</option>
                        <option value="lower">Lower Body & Core</option>
                        <option value="full_body">Full Body Workout</option>
                        <option value="core_cardio">Core, Abs & HIIT</option>
                    </select>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Generate Day',
            confirmButtonColor: 'var(--lime-dark, #84cc16)',
            cancelButtonColor: '#6c757d',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                return document.getElementById('swal-day-focus').value;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.showLoading();
                document.getElementById('autoGenDayInput').value = dayNum;
                document.getElementById('autoGenDayFocus').value = result.value;
                document.getElementById('autoGenDayForm').submit();
            }
        });
    }

    // 4. Confirm Clear Day
    function confirmClearDay(dayNum, dayName) {
        Swal.fire({
            title: 'Clear ' + dayName + '?',
            text: 'This will remove all scheduled exercises for ' + dayName + '.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6c757d',
            background: 'var(--bg)',
            color: 'var(--ink)',
            confirmButtonText: 'Yes, clear it'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.showLoading();
                document.getElementById('clearDayInput').value = dayNum;
                document.getElementById('clearDayForm').submit();
            }
        });
    }

    // 5. Publish Prompt
    function promptPublish() {
        Swal.fire({
            title: 'Publish Workout Plan',
            width: 'min(94vw, 520px)',
            html: `
                <div style="text-align: left; display: flex; flex-direction: column; gap: 14px;">
                    <p style="margin:0; color:var(--ink); font-size: 14px;">
                        This will activate the training plan and notify <strong>${memberName}</strong>.
                    </p>
                    ${!hasMembership ? `
                    <div style="background-color: rgba(255, 193, 7, 0.1); border-left: 4px solid var(--orange); padding: 10px; border-radius: 6px;">
                        <p style="margin: 0; font-size: 13px; color: var(--orange);">
                            <strong>Notice:</strong> This member does not currently hold an active membership. This plan will serve as a 7-day trial.
                        </p>
                    </div>
                    ` : ''}
                    <div style="display:flex; gap:10px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Start Date
                            <input type="date" id="swal-start" class="form-control" style="width:100%; margin-top:5px;" value="${defaultStart}" ${!hasMembership ? 'readonly' : ''}>
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">End Date
                            <input type="date" id="swal-end" class="form-control" style="width:100%; margin-top:5px;" value="${defaultEnd}" ${!hasMembership ? 'readonly' : ''}>
                        </label>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonColor: 'var(--lime-dark, #84cc16)',
            cancelButtonColor: '#6c757d',
            background: 'var(--bg)',
            color: 'var(--ink)',
            confirmButtonText: 'Yes, Publish Routine',
            preConfirm: () => {
                const start = document.getElementById('swal-start').value;
                const end = document.getElementById('swal-end').value;
                if (!start || !end) {
                    Swal.showValidationMessage('Please select both start and end dates');
                    return false;
                }
                if (end < start) {
                    Swal.showValidationMessage('End date cannot precede start date');
                    return false;
                }
                return { start, end };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.showLoading();
                const form = document.getElementById('publishForm');
                
                let startInput = document.createElement('input');
                startInput.type = 'hidden';
                startInput.name = 'start_date';
                startInput.value = result.value.start;
                form.appendChild(startInput);

                let endInput = document.createElement('input');
                endInput.type = 'hidden';
                endInput.name = 'end_date';
                endInput.value = result.value.end;
                form.appendChild(endInput);
                
                form.submit();
            }
        });
    }

    // 6. Add Exercise Modal with Custom Dark Combobox & Hybrid Live Search
    function openAddExerciseModal(dayNum, dayName) {
        const exercisesList = window.gymExercisesData || [];
        const hasExercises = exercisesList.length > 0;

        if (!hasExercises) {
            Swal.fire({
                title: 'No Exercises in Library',
                icon: 'warning',
                html: `
                    <div style="text-align: left; font-size: 14px; line-height: 1.5; color: var(--ink);">
                        <p>There are currently no exercises in your gym's exercise library.</p>
                        <p style="color: var(--muted); font-size: 13px; margin-top: 8px;">Please add exercises to your gym's library on the <strong>Exercises</strong> page before adding them to a workout routine.</p>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-dumbbell" style="margin-right: 6px;"></i>Go to Exercises Page',
                confirmButtonColor: 'var(--lime-dark, #84cc16)',
                cancelButtonText: 'Cancel',
                cancelButtonColor: '#6c757d',
                background: 'var(--bg)',
                color: 'var(--ink)'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'index.php?page=exercises';
                }
            });
            return;
        }

        Swal.fire({
            title: 'Add Exercise • ' + dayName,
            width: 'min(94vw, 560px)',
            html: `
                <form id="addExForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 14px; font-size: 13px;">
                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                    <input type="hidden" name="action" value="add_exercise">
                    <input type="hidden" name="day_of_week" value="${dayNum}">
                    <input type="hidden" name="exercise_id" id="wbExerciseId" value="" required>
                    
                    <!-- Custom Themed Combobox with Hybrid Live Search -->
                    <div class="wb-combobox-wrap">
                        <label class="wb-modal-label">Exercise *</label>
                        
                        <div id="wbExTrigger" class="wb-combobox-trigger" tabindex="0">
                            <div id="wbExTriggerContent" style="display: flex; align-items: center; gap: 8px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis;">
                                <i class="fas fa-dumbbell" style="color: #64748b; font-size: 13px;"></i>
                                <span id="wbExTriggerPlaceholder" style="font-size: 13.5px;">Select an exercise...</span>
                            </div>
                            <i id="wbExChevron" class="fas fa-chevron-down" style="font-size: 12px; transition: transform 0.2s ease; flex-shrink: 0; margin-left: 8px;"></i>
                        </div>

                        <div id="wbExMenu" class="wb-combobox-menu">
                            <div class="wb-combobox-search-wrap">
                                <div class="wb-combobox-search-box">
                                    <i class="fas fa-search" style="position: absolute; left: 11px; color: #64748b; font-size: 12px;"></i>
                                    <input type="text" id="wbExSearchInput" class="wb-combobox-search-input" placeholder="Search exercises by name, muscle, category..." autocomplete="off">
                                    <span id="wbExSearchClear" style="position: absolute; right: 8px; color: #64748b; font-size: 12px; cursor: pointer; display: none; padding: 3px 6px; border-radius: 4px;" title="Clear">✕</span>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11px; color: #64748b; margin-top: 5px; padding: 0 2px;">
                                    <span id="wbExCount">All exercises</span>
                                    <span id="wbExLiveSpinner" style="display: none; color: var(--lime);"><i class="fas fa-circle-notch fa-spin"></i> Searching...</span>
                                </div>
                            </div>

                            <div id="wbExList" class="wb-combobox-list">
                                <!-- Rendered dynamically -->
                            </div>
                        </div>
                    </div>
                    
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px;">
                        <div>
                            <label class="wb-modal-label">Sets *</label>
                            <input type="number" name="sets" value="3" min="1" max="20" class="form-control wb-modal-input" required>
                        </div>
                        <div>
                            <label class="wb-modal-label">Reps *</label>
                            <input type="text" name="reps" value="10-12" class="form-control wb-modal-input" placeholder="e.g. 8-10, 12, Failure" required>
                        </div>
                        <div>
                            <label class="wb-modal-label">Target Wt (kg)</label>
                            <input type="number" step="0.5" name="target_weight_kg" placeholder="e.g. 60" class="form-control wb-modal-input">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px;">
                        <div>
                            <label class="wb-modal-label">Rest (s)</label>
                            <select name="rest_seconds" class="form-control wb-modal-select">
                                <option value="0">0s</option>
                                <option value="30">30s</option>
                                <option value="45">45s</option>
                                <option value="60" selected>60s</option>
                                <option value="90">90s</option>
                                <option value="120">120s</option>
                                <option value="180">180s</option>
                            </select>
                        </div>
                        <div>
                            <label class="wb-modal-label">Tempo</label>
                            <input type="text" name="tempo" placeholder="e.g. 3-0-1-0" class="form-control wb-modal-input">
                        </div>
                        <div>
                            <label class="wb-modal-label">RPE</label>
                            <input type="text" name="rpe" placeholder="e.g. RPE 8" class="form-control wb-modal-input">
                        </div>
                    </div>
                    
                    <div>
                        <label class="wb-modal-label">Coaching Cues / Notes</label>
                        <input type="text" name="notes" placeholder="e.g. Focus on deep stretch at the bottom, pause 1s" class="form-control wb-modal-input">
                    </div>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Add Exercise',
            confirmButtonColor: 'var(--lime-dark, #84cc16)',
            cancelButtonColor: '#6c757d',
            background: 'var(--bg)',
            color: 'var(--ink)',
            didOpen: () => {
                const swalContainer = Swal.getHtmlContainer();
                if (swalContainer) {
                    swalContainer.style.overflow = 'visible';
                    swalContainer.style.zIndex = '30';
                    swalContainer.style.position = 'relative';
                }
                const swalActions = Swal.getActions();
                if (swalActions) {
                    swalActions.style.zIndex = '1';
                    swalActions.style.position = 'relative';
                }
                const swalPopup = Swal.getPopup();
                if (swalPopup) swalPopup.style.overflow = 'visible';

                const trigger = document.getElementById('wbExTrigger');
                const menu = document.getElementById('wbExMenu');
                const chevron = document.getElementById('wbExChevron');
                const searchInput = document.getElementById('wbExSearchInput');
                const searchClear = document.getElementById('wbExSearchClear');
                const listEl = document.getElementById('wbExList');
                const countEl = document.getElementById('wbExCount');
                const spinner = document.getElementById('wbExLiveSpinner');
                const hiddenInput = document.getElementById('wbExerciseId');
                const triggerContent = document.getElementById('wbExTriggerContent');

                let selectedId = null;
                let localExercises = [...(window.gymExercisesData || [])];
                let ajaxTimer = null;
                const esc = window.escapeHtml || function(s) {
                    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
                };

                function renderList(query = '') {
                    const q = query.trim().toLowerCase();
                    const filtered = localExercises.filter(e => {
                        if (!q) return true;
                        return (e.name && e.name.toLowerCase().includes(q)) ||
                               (e.muscle_group && e.muscle_group.toLowerCase().includes(q)) ||
                               (e.category && e.category.toLowerCase().includes(q));
                    });

                    if (countEl) {
                        countEl.textContent = q ? `${filtered.length} matching` : `${localExercises.length} exercises available`;
                    }

                    if (filtered.length === 0) {
                        listEl.innerHTML = `
                            <div style="padding: 22px 10px; text-align: center; color: #94a3b8; font-size: 13px;">
                                <i class="fas fa-search" style="font-size: 20px; margin-bottom: 8px; display: block; opacity: 0.35;"></i>
                                No exercises found matching "${esc(q)}"
                            </div>
                        `;
                        return;
                    }

                    // Group exercises by muscle group
                    const grouped = {};
                    filtered.forEach(item => {
                        const grp = item.muscle_group || 'General';
                        if (!grouped[grp]) grouped[grp] = [];
                        grouped[grp].push(item);
                    });

                    let html = '';
                    for (const [grpName, items] of Object.entries(grouped)) {
                        html += `
                            <div class="wb-combobox-group-header">
                                <span>${esc(grpName)}</span>
                                <span style="font-size: 10px; font-weight: normal; opacity: 0.7;">${items.length}</span>
                            </div>
                        `;
                        items.forEach(item => {
                            const isSelected = selectedId === item.id;
                            html += `
                                <div class="wb-combobox-item ${isSelected ? 'active' : ''}" data-id="${item.id}" data-name="${encodeURIComponent(item.name)}" data-group="${encodeURIComponent(item.muscle_group)}">
                                    <div style="display: flex; align-items: center; gap: 8px; min-width: 0;">
                                        <i class="fas fa-dumbbell" style="color: ${isSelected ? 'var(--lime)' : '#64748b'}; font-size: 12px; flex-shrink: 0;"></i>
                                        <span class="wb-combobox-item-name">
                                            ${esc(item.name)}
                                        </span>
                                        ${item.category ? `<span class="wb-combobox-item-category">${esc(item.category)}</span>` : ''}
                                    </div>
                                    ${isSelected ? '<i class="fas fa-check" style="color: var(--lime); font-size: 12px; margin-left: 8px;"></i>' : ''}
                                </div>
                            `;
                        });
                    }
                    listEl.innerHTML = html;

                    // Click listeners
                    listEl.querySelectorAll('.wb-combobox-item').forEach(el => {
                        el.addEventListener('click', (e) => {
                            e.stopPropagation();
                            const id = parseInt(el.getAttribute('data-id'), 10);
                            const name = decodeURIComponent(el.getAttribute('data-name'));
                            const grp = decodeURIComponent(el.getAttribute('data-group'));
                            selectExercise(id, name, grp);
                        });
                    });
                }

                function selectExercise(id, name, grp) {
                    selectedId = id;
                    hiddenInput.value = id;
                    trigger.classList.remove('error');
                    triggerContent.innerHTML = `
                        <span class="wb-selected-pill">
                            <i class="fas fa-dumbbell" style="color: var(--lime);"></i>
                            <span>${esc(name)}</span>
                        </span>
                        <span class="wb-group-badge">
                            ${esc(grp)}
                        </span>
                    `;
                    menu.style.display = 'none';
                    chevron.style.transform = 'rotate(0deg)';
                    renderList(searchInput.value);
                }

                // Toggle dropdown menu
                trigger.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isOpen = menu.style.display === 'block';
                    menu.style.display = isOpen ? 'none' : 'block';
                    chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
                    if (!isOpen) {
                        setTimeout(() => searchInput.focus(), 60);
                    }
                });

                // Close on click outside
                const outsideClickListener = (e) => {
                    if (!trigger.contains(e.target) && !menu.contains(e.target)) {
                        menu.style.display = 'none';
                        chevron.style.transform = 'rotate(0deg)';
                    }
                };
                document.addEventListener('click', outsideClickListener);

                // Hybrid Search: 0ms instant local filter + 250ms debounced AJAX
                searchInput.addEventListener('input', (e) => {
                    const val = e.target.value;
                    searchClear.style.display = val ? 'block' : 'none';
                    
                    // 1. 0ms instant local filter
                    renderList(val);

                    // 2. 250ms debounced AJAX server query
                    if (ajaxTimer) clearTimeout(ajaxTimer);
                    const qTrim = val.trim();
                    if (qTrim.length >= 1) {
                        if (spinner) spinner.style.display = 'inline-block';
                        ajaxTimer = setTimeout(() => {
                            fetch('index.php?page=workout_builder&member_user_id=' + encodeURIComponent(memberId) + '&action=search_exercises&q=' + encodeURIComponent(qTrim), {
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            })
                            .then(r => r.json())
                            .then(data => {
                                if (spinner) spinner.style.display = 'none';
                                if (searchInput.value.trim().toLowerCase() !== qTrim.toLowerCase()) return;
                                const results = data.results || [];
                                results.forEach(res => {
                                    if (!localExercises.some(item => item.id === res.id)) {
                                        localExercises.push(res);
                                        window.gymExercisesData.push(res);
                                    }
                                });
                                renderList(searchInput.value);
                            })
                            .catch(() => {
                                if (spinner) spinner.style.display = 'none';
                            });
                        }, 250);
                    } else {
                        if (spinner) spinner.style.display = 'none';
                    }
                });

                searchClear.addEventListener('click', (e) => {
                    e.stopPropagation();
                    searchInput.value = '';
                    searchClear.style.display = 'none';
                    if (spinner) spinner.style.display = 'none';
                    renderList('');
                    searchInput.focus();
                });

                // Initial render
                renderList('');
            },
            preConfirm: () => {
                const form = document.getElementById('addExForm');
                const trigger = document.getElementById('wbExTrigger');
                if (!form.exercise_id.value) {
                    if (trigger) trigger.classList.add('error');
                    Swal.showValidationMessage('Please select an exercise from the dropdown');
                    return false;
                }
                if (!form.sets.value || !form.reps.value) {
                    Swal.showValidationMessage('Please enter sets and reps');
                    return false;
                }
                form.submit();
            }
        });
    }

    // 7. Edit Exercise Modal
    function openEditExerciseModal(ex) {
        Swal.fire({
            title: 'Edit • ' + ex.exercise_name,
            width: 'min(94vw, 560px)',
            html: `
                <form id="editExForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; font-size: 13px;">
                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                    <input type="hidden" name="action" value="edit_exercise">
                    <input type="hidden" name="plan_exercise_id" value="${ex.plan_exercise_id}">
                    
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px;">
                        <div>
                            <label class="wb-modal-label">Sets *</label>
                            <input type="number" name="sets" value="${ex.sets}" min="1" max="20" class="form-control wb-modal-input" required>
                        </div>
                        <div>
                            <label class="wb-modal-label">Reps *</label>
                            <input type="text" name="reps" value="${escapeHtml(ex.reps || '10')}" class="form-control wb-modal-input" required>
                        </div>
                        <div>
                            <label class="wb-modal-label">Target Wt (kg)</label>
                            <input type="number" step="0.5" name="target_weight_kg" value="${ex.target_weight_kg || ''}" placeholder="e.g. 60" class="form-control wb-modal-input">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px;">
                        <div>
                            <label class="wb-modal-label">Rest (s)</label>
                            <select name="rest_seconds" class="form-control wb-modal-select">
                                <option value="0" ${ex.rest_seconds == 0 ? 'selected' : ''}>0s</option>
                                <option value="30" ${ex.rest_seconds == 30 ? 'selected' : ''}>30s</option>
                                <option value="45" ${ex.rest_seconds == 45 ? 'selected' : ''}>45s</option>
                                <option value="60" ${ex.rest_seconds == 60 ? 'selected' : ''}>60s</option>
                                <option value="90" ${ex.rest_seconds == 90 ? 'selected' : ''}>90s</option>
                                <option value="120" ${ex.rest_seconds == 120 ? 'selected' : ''}>120s</option>
                                <option value="180" ${ex.rest_seconds == 180 ? 'selected' : ''}>180s</option>
                            </select>
                        </div>
                        <div>
                            <label class="wb-modal-label">Tempo</label>
                            <input type="text" name="tempo" value="${escapeHtml(ex.tempo || '')}" placeholder="e.g. 3-0-1-0" class="form-control wb-modal-input">
                        </div>
                        <div>
                            <label class="wb-modal-label">RPE</label>
                            <input type="text" name="rpe" value="${escapeHtml(ex.rpe || '')}" placeholder="e.g. RPE 8" class="form-control wb-modal-input">
                        </div>
                    </div>
                    
                    <div>
                        <label class="wb-modal-label">Coaching Cues / Notes</label>
                        <input type="text" name="notes" value="${escapeHtml(ex.notes || '')}" placeholder="e.g. Keep chest tall" class="form-control wb-modal-input">
                    </div>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Save Changes',
            confirmButtonColor: 'var(--lime-dark, #84cc16)',
            cancelButtonColor: '#6c757d',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('editExForm');
                if (!form.sets.value || !form.reps.value) {
                    Swal.showValidationMessage('Sets and reps are required');
                    return false;
                }
                form.submit();
            }
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // 8. Density Mode (Compact vs Detailed)
    function applyDensity(mode) {
        const grid = document.getElementById('builderDaysGrid');
        const label = document.getElementById('densityLabel');
        const icon = document.getElementById('densityIcon');
        if (!grid) return;

        if (mode === 'compact') {
            grid.classList.add('builder-compact');
            grid.classList.remove('builder-detailed');
            if (label) label.textContent = 'Detailed View';
            if (icon) {
                icon.innerHTML = '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>';
            }
        } else {
            grid.classList.remove('builder-compact');
            grid.classList.add('builder-detailed');
            if (label) label.textContent = 'Compact View';
            if (icon) {
                icon.innerHTML = '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>';
            }
        }
    }

    function toggleDensity() {
        const grid = document.getElementById('builderDaysGrid');
        if (!grid) return;
        const isCompact = grid.classList.contains('builder-compact');
        const nextMode = isCompact ? 'detailed' : 'compact';
        applyDensity(nextMode);
        try {
            localStorage.setItem('workout_builder_density', nextMode);
        } catch (e) {}
    }

    function toggleNoteOpen(btn) {
        const item = btn.closest('.builder-ex-item');
        if (!item) return;
        const notes = item.querySelector('.builder-ex-notes');
        if (notes) {
            notes.classList.toggle('is-open');
            btn.classList.toggle('is-active');
        }
    }

    // 9. Day Tab Switcher (Focused Day vs All Days)
    function selectDayTab(dayKey) {
        const grid = document.getElementById('builderDaysGrid');
        if (!grid) return;

        document.querySelectorAll('.builder-tab-btn').forEach(btn => {
            btn.classList.toggle('is-active', btn.getAttribute('data-day') === String(dayKey));
        });

        const cards = document.querySelectorAll('.builder-day-card');
        if (dayKey === 'all') {
            grid.classList.remove('is-single-day');
            cards.forEach(card => card.style.display = '');
        } else {
            grid.classList.add('is-single-day');
            cards.forEach(card => {
                if (card.getAttribute('data-day') === String(dayKey)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        try {
            localStorage.setItem('workout_builder_active_day', String(dayKey));
        } catch (e) {}
    }

    // 10. Initialization and SortableJS Drag & Drop
    document.addEventListener('DOMContentLoaded', function () {
        // Restore Density
        const savedDensity = localStorage.getItem('workout_builder_density') || 'detailed';
        applyDensity(savedDensity);

        // Restore Day Tab
        const urlParams = new URLSearchParams(window.location.search);
        const dayParam = urlParams.get('day');
        const savedDay = dayParam || localStorage.getItem('workout_builder_active_day') || 'all';
        selectDayTab(savedDay);

        const lists = document.querySelectorAll('.sortable-list');
        lists.forEach(list => {
            new Sortable(list, {
                group: 'shared', // Drag & drop seamlessly across days
                animation: 150,
                ghostClass: 'sortable-ghost',
                handle: '.builder-ex-item',
                onEnd: function (evt) {
                    const currentList = evt.to;
                    const items = Array.from(currentList.children)
                                       .filter(el => el.hasAttribute('data-id'))
                                       .map(el => el.getAttribute('data-id'));
                    
                    const targetDay = currentList.getAttribute('data-day');
                    
                    if (items.length > 0) {
                        fetch('index.php?page=workout_builder&member_user_id=' + encodeURIComponent(memberId), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': csrfToken
                            },
                            body: JSON.stringify({
                                action: 'reorder',
                                day_of_week: targetDay,
                                items: items
                            })
                        }).then(() => {
                            if (evt.from !== evt.to) {
                                // Reload to update empty states and metrics accurately
                                window.location.reload();
                            }
                        });
                    }
                }
            });
        });
    });
