/**
 * Onboarding Goal Setup Controller
 * Extracted from auth/setup_goal.php
 */

        (function() {
            function escapeHtml(str) {
                return String(str).replace(/[&<>"']/g, function(m) {
                    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[m];
                });
            }

            const stage1 = document.getElementById('goal-stage-1');
            const stage2 = document.getElementById('goal-stage-2');
            const stage3 = document.getElementById('goal-stage-3');
            const stepperText = document.getElementById('stepper-step-text');
            const stepperBar1 = document.getElementById('stepper-bar-1');
            const stepperBar2 = document.getElementById('stepper-bar-2');
            const stepperBar3 = document.getElementById('stepper-bar-3');
            const stage2Pill = document.getElementById('stage2-category-pill');
            const searchInput = document.getElementById('goal-search');
            const goalCards = document.querySelectorAll('.goal-card');
            const toTargetsBtn = document.getElementById('to-targets-btn');
            const skipTargetsBtn = document.getElementById('skip-targets-btn');
            const submitBtn = document.getElementById('submit-goal-btn');
            const selectedDisplay = document.getElementById('selected-goal-display');
            const noResults = document.getElementById('no-results-box');

            let currentCategory = 'all';
            let searchQuery = '';

            window.goToStage = function(stageNum, categoryKey) {
                if (stageNum === 1) {
                    if (stage1) stage1.classList.add('active');
                    if (stage2) stage2.classList.remove('active');
                    if (stage3) stage3.classList.remove('active');
                    if (stepperText) stepperText.textContent = 'Step 2: Focus Track (1 of 3)';
                    if (stepperBar2) {
                        stepperBar2.style.background = '#e2e8f0';
                        stepperBar2.style.boxShadow = 'none';
                    }
                    if (stepperBar3) {
                        stepperBar3.style.background = '#e2e8f0';
                        stepperBar3.style.boxShadow = 'none';
                    }
                    if (searchInput && searchQuery) {
                        searchInput.value = '';
                        searchQuery = '';
                    }
                } else if (stageNum === 2) {
                    if (stage1) stage1.classList.remove('active');
                    if (stage2) stage2.classList.add('active');
                    if (stage3) stage3.classList.remove('active');
                    if (stepperText) stepperText.textContent = 'Step 2: Target Goal (2 of 3)';
                    if (stepperBar2) {
                        stepperBar2.style.background = '#84cc16';
                        stepperBar2.style.boxShadow = '0 0 8px rgba(132, 204, 22, 0.4)';
                    }
                    if (stepperBar3) {
                        stepperBar3.style.background = '#e2e8f0';
                        stepperBar3.style.boxShadow = 'none';
                    }
                    if (categoryKey) {
                        currentCategory = categoryKey;
                        const card = document.querySelector(`.focus-category-card[data-category="${categoryKey}"]`);
                        const catTitle = card ? card.querySelector('.focus-category-title')?.textContent : categoryKey;
                        if (stage2Pill) {
                            stage2Pill.innerHTML = `<span>${escapeHtml(catTitle)}</span>`;
                        }
                    }
                    filterGoals();
                } else if (stageNum === 3) {
                    if (stage1) stage1.classList.remove('active');
                    if (stage2) stage2.classList.remove('active');
                    if (stage3) stage3.classList.add('active');
                    if (stepperText) stepperText.textContent = 'Step 2: Supporting Targets (3 of 3)';
                    if (stepperBar2) {
                        stepperBar2.style.background = '#84cc16';
                        stepperBar2.style.boxShadow = '0 0 8px rgba(132, 204, 22, 0.4)';
                    }
                    if (stepperBar3) {
                        stepperBar3.style.background = '#84cc16';
                        stepperBar3.style.boxShadow = '0 0 8px rgba(132, 204, 22, 0.4)';
                    }
                }
            };

            window.selectCategory = function(catKey) {
                goToStage(2, catKey);
            };

            /* Target Modals Management */
            window.openTargetModal = function(type) {
                const modal = document.getElementById('modal-target-' + type);
                if (modal) {
                    modal.showModal();
                }
            };

            window.closeTargetModal = function(type) {
                const modal = document.getElementById('modal-target-' + type);
                if (modal) {
                    modal.close();
                }
            };

            window.saveTargetModal = function(type) {
                if (type === 'weight') {
                    const input = document.getElementById('modal-field-weight');
                    const val = input ? input.value.trim() : '';
                    document.getElementById('form-target-weight').value = val;
                    const preview = document.getElementById('preview-target-weight');
                    const card = document.getElementById('card-target-weight');
                    if (val && !isNaN(val)) {
                        preview.innerHTML = `<span>Target: <strong>${parseFloat(val).toFixed(1)} kg</strong></span>`;
                        card.classList.add('has-value');
                    } else {
                        preview.innerHTML = `<span class="tc-empty">Not set &bull; Tap to configure</span>`;
                        card.classList.remove('has-value');
                    }
                    closeTargetModal('weight');
                } else if (type === 'bodyfat') {
                    const input = document.getElementById('modal-field-bodyfat');
                    const val = input ? input.value.trim() : '';
                    document.getElementById('form-target-bodyfat').value = val;
                    const preview = document.getElementById('preview-target-bodyfat');
                    const card = document.getElementById('card-target-bodyfat');
                    if (val && !isNaN(val)) {
                        preview.innerHTML = `<span>Target: <strong>${parseFloat(val).toFixed(1)} %</strong></span>`;
                        card.classList.add('has-value');
                    } else {
                        preview.innerHTML = `<span class="tc-empty">Not set &bull; Tap to configure</span>`;
                        card.classList.remove('has-value');
                    }
                    closeTargetModal('bodyfat');
                } else if (type === 'waist') {
                    const input = document.getElementById('modal-field-waist');
                    const val = input ? input.value.trim() : '';
                    document.getElementById('form-target-waist').value = val;
                    const preview = document.getElementById('preview-target-waist');
                    const card = document.getElementById('card-target-waist');
                    if (val && !isNaN(val)) {
                        preview.innerHTML = `<span>Target: <strong>${parseFloat(val).toFixed(1)} cm</strong></span>`;
                        card.classList.add('has-value');
                    } else {
                        preview.innerHTML = `<span class="tc-empty">Not set &bull; Tap to configure</span>`;
                        card.classList.remove('has-value');
                    }
                    closeTargetModal('waist');
                } else if (type === 'pr') {
                    const ex = document.getElementById('modal-field-pr-exercise').value;
                    const input = document.getElementById('modal-field-pr-weight');
                    const val = input ? input.value.trim() : '';
                    document.getElementById('form-target-exercise').value = ex;
                    document.getElementById('form-target-pr-weight').value = val;
                    const preview = document.getElementById('preview-target-pr');
                    const card = document.getElementById('card-target-pr');
                    if (val && !isNaN(val)) {
                        preview.innerHTML = `<span>${escapeHtml(ex)}: <strong>${parseFloat(val).toFixed(1)} kg</strong></span>`;
                        card.classList.add('has-value');
                    } else {
                        preview.innerHTML = `<span class="tc-empty">Not set &bull; Tap to configure</span>`;
                        card.classList.remove('has-value');
                    }
                    closeTargetModal('pr');
                } else if (type === 'endurance') {
                    const act = document.getElementById('modal-field-endurance-act').value;
                    const distInput = document.getElementById('modal-field-endurance-dist');
                    const timeInput = document.getElementById('modal-field-endurance-time');
                    const dist = distInput ? distInput.value.trim() : '';
                    const time = timeInput ? timeInput.value.trim() : '';
                    document.getElementById('form-endurance-activity').value = act;
                    document.getElementById('form-endurance-distance').value = dist;
                    document.getElementById('form-endurance-time').value = time;
                    const btnText = document.getElementById('preview-endurance-btn-text');
                    const btn = document.getElementById('btn-open-endurance');
                    if (dist || time) {
                        let label = escapeHtml(act) + ': ';
                        if (dist) label += parseFloat(dist).toFixed(1) + 'km';
                        if (dist && time) label += ' in ';
                        if (time) label += parseInt(time) + ' mins';
                        label += ' (Edit)';
                        btnText.textContent = label;
                        btn.classList.add('active');
                    } else {
                        btnText.textContent = '+ Add Endurance Target';
                        btn.classList.remove('active');
                    }
                    closeTargetModal('endurance');
                }
            };

            window.clearTargetModal = function(type) {
                if (type === 'weight') {
                    const input = document.getElementById('modal-field-weight');
                    if (input) input.value = '';
                    saveTargetModal('weight');
                } else if (type === 'bodyfat') {
                    const input = document.getElementById('modal-field-bodyfat');
                    if (input) input.value = '';
                    saveTargetModal('bodyfat');
                } else if (type === 'waist') {
                    const input = document.getElementById('modal-field-waist');
                    if (input) input.value = '';
                    saveTargetModal('waist');
                } else if (type === 'pr') {
                    const input = document.getElementById('modal-field-pr-weight');
                    if (input) input.value = '';
                    saveTargetModal('pr');
                } else if (type === 'endurance') {
                    const distInput = document.getElementById('modal-field-endurance-dist');
                    const timeInput = document.getElementById('modal-field-endurance-time');
                    if (distInput) distInput.value = '';
                    if (timeInput) timeInput.value = '';
                    saveTargetModal('endurance');
                }
            };

            // Goal Card Click Handler
            goalCards.forEach(card => {
                card.addEventListener('click', function(e) {
                    const radio = this.querySelector('input[type="radio"]');
                    if (radio && e.target !== radio) {
                        radio.checked = true;
                    }
                    goalCards.forEach(c => c.classList.remove('selected'));
                    this.classList.add('selected');

                    const goalTitle = this.querySelector('.goal-title')?.textContent || 'Selected';
                    if (selectedDisplay) selectedDisplay.textContent = goalTitle;

                    if (toTargetsBtn) {
                        toTargetsBtn.disabled = false;
                        toTargetsBtn.style.opacity = '1';
                        toTargetsBtn.style.cursor = 'pointer';
                    }
                    if (skipTargetsBtn) {
                        skipTargetsBtn.disabled = false;
                        skipTargetsBtn.style.cursor = 'pointer';
                    }
                });
            });

            // Initialize selection if a goal is pre-checked (e.g. edit mode)
            const prechecked = document.querySelector('input[name="primary_goal"]:checked');
            if (prechecked) {
                const card = prechecked.closest('.goal-card');
                if (card) {
                    card.classList.add('selected');
                    const goalTitle = card.querySelector('.goal-title')?.textContent || prechecked.value;
                    if (selectedDisplay) selectedDisplay.textContent = goalTitle;
                    if (toTargetsBtn) {
                        toTargetsBtn.disabled = false;
                        toTargetsBtn.style.opacity = '1';
                        toTargetsBtn.style.cursor = 'pointer';
                    }
                    if (skipTargetsBtn) {
                        skipTargetsBtn.disabled = false;
                        skipTargetsBtn.style.cursor = 'pointer';
                    }
                }
            }

            // Quick Search Listener
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    searchQuery = this.value.trim().toLowerCase();
                    if (searchQuery.length > 0) {
                        currentCategory = 'all';
                        if (stage2Pill) {
                            stage2Pill.innerHTML = `<span>Search: "${escapeHtml(searchQuery)}"</span>`;
                        }
                        goToStage(2, null);
                    } else {
                        goToStage(1);
                    }
                });
            }

            function filterGoals() {
                let visibleCount = 0;
                goalCards.forEach(card => {
                    const cardCat = card.getAttribute('data-category');
                    const title = card.getAttribute('data-title');
                    const desc = card.getAttribute('data-desc');

                    const matchesCat = (currentCategory === 'all' || cardCat === currentCategory);
                    const matchesSearch = (!searchQuery || title.includes(searchQuery) || desc.includes(searchQuery));

                    if (matchesCat && matchesSearch) {
                        card.style.display = 'flex';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });

                if (noResults) {
                    if (visibleCount === 0) {
                        noResults.classList.add('show');
                    } else {
                        noResults.classList.remove('show');
                    }
                }
            }

            // Sanitize modal numeric inputs so negative numbers cannot be typed or pasted
            const targetNumericIds = ['modal-field-weight', 'modal-field-bodyfat', 'modal-field-waist', 'modal-field-pr-weight', 'modal-field-endurance-dist', 'modal-field-endurance-time'];
            targetNumericIds.forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;

                el.addEventListener('keydown', function(e) {
                    if (e.key === '-' || e.key === '+' || e.key === 'e' || e.key === 'E' || e.code === 'NumpadSubtract' || e.code === 'Minus') {
                        e.preventDefault();
                    }
                });

                el.addEventListener('input', function() {
                    if (this.value.includes('-')) {
                        this.value = this.value.replace(/-/g, '');
                    }
                    if (this.value.includes('+')) {
                        this.value = this.value.replace(/\+/g, '');
                    }
                    const num = parseFloat(this.value);
                    if (!isNaN(num) && num < 0) {
                        this.value = Math.abs(num);
                    }
                });

                el.addEventListener('paste', function(e) {
                    const pasteData = (e.clipboardData || window.clipboardData)?.getData('text');
                    if (pasteData && (pasteData.includes('-') || pasteData.includes('+') || /[eE]/.test(pasteData))) {
                        e.preventDefault();
                        const sanitized = pasteData.replace(/[-+eE]/g, '');
                        const start = this.selectionStart ?? this.value.length;
                        const end = this.selectionEnd ?? this.value.length;
                        const val = this.value;
                        this.value = val.slice(0, start) + sanitized + val.slice(end);
                        this.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                });
            });
        })();
