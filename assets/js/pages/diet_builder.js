/**
 * Trainer Diet Builder Controller
 * Extracted from diet_builder.php
 */

const FT_DIET_BUILDER_CONFIG = window.DIET_BUILDER_CONFIG || {};
const csrfToken = FT_DIET_BUILDER_CONFIG.csrfToken || (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
const memberRestriction = FT_DIET_BUILDER_CONFIG.memberRestriction || 'none';
const localFoods = FT_DIET_BUILDER_CONFIG.localFoods || [];

function confirmGeneratePlan(btn, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const form = btn.closest('form');
    if (!form) return false;

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Auto-Generate Diet Plan?',
            html: '<div style="font-size: 13.5px; color: var(--muted); line-height: 1.5; margin-top: 6px;">This will automatically generate a tailored 7-day meal plan based on the member\'s dietary restriction and fitness goal.<br><br><span style="color: #f59e0b; font-weight: 600;">⚠️ Any draft meals you have added manually will be cleared.</span></div>',
            icon: 'question',
            iconColor: 'var(--diet-accent, #84cc16)',
            showCancelButton: true,
            confirmButtonText: 'Yes, Generate Plan',
            cancelButtonText: 'Cancel',
            confirmButtonColor: 'var(--lime-dark, #65a30d)',
            cancelButtonColor: 'var(--line, #334155)',
            background: 'var(--panel, #121721)',
            color: 'var(--ink, #ffffff)',
            reverseButtons: true,
            focusCancel: true
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Generating Diet Plan...',
                    html: '<div style="font-size: 13.5px; color: var(--muted); margin-top: 6px;">Balancing nutrition and generating weekly schedule...</div>',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    background: 'var(--panel, #121721)',
                    color: 'var(--ink, #ffffff)',
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                form.submit();
            }
        });
    } else if (confirm('Auto-generate a dietary plan? This will clear any draft meals you have added manually.')) {
        form.submit();
    }
    return false;
}

function confirmPublishPlan(btn, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const form = btn.closest('form');
    if (!form) return false;

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Publish Diet Plan?',
            html: '<div style="font-size: 13.5px; color: var(--muted); line-height: 1.5; margin-top: 6px;">This will publish the diet plan and make it immediately active and visible to the member on their dashboard.</div>',
            icon: 'question',
            iconColor: 'var(--diet-accent, #84cc16)',
            showCancelButton: true,
            confirmButtonText: 'Yes, Publish Plan',
            cancelButtonText: 'Cancel',
            confirmButtonColor: 'var(--lime-dark, #65a30d)',
            cancelButtonColor: 'var(--line, #334155)',
            background: 'var(--panel, #121721)',
            color: 'var(--ink, #ffffff)',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    } else if (confirm('Publish this diet plan?')) {
        form.submit();
    }
    return false;
}

function confirmRemoveMeal(form, event, dayName) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    if (!form) return false;

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Remove Meal?',
            text: `Are you sure you want to remove this meal from ${dayName}?`,
            icon: 'warning',
            iconColor: '#ef4444',
            showCancelButton: true,
            confirmButtonText: 'Yes, Remove',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#ef4444',
            cancelButtonColor: 'var(--line, #334155)',
            background: 'var(--panel, #121721)',
            color: 'var(--ink, #ffffff)',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    } else if (confirm(`Remove this meal from ${dayName}?`)) {
        form.submit();
    }
    return false;
}

function updateMacros() {
    const p = parseFloat(document.getElementById('calc_p').value) || 0;
    const c = parseFloat(document.getElementById('calc_c').value) || 0;
    const f = parseFloat(document.getElementById('calc_f').value) || 0;
    const calsInput = document.getElementById('calc_cals');
    if (calsInput) {
        calsInput.value = Math.round((p * 4) + (c * 4) + (f * 9));
    }
}

let isOnlineSearch = false;
let searchDebounce = null;
// localFoods defined from FT_DIET_BUILDER_CONFIG
// memberRestriction defined from FT_DIET_BUILDER_CONFIG

function isDietaryCompatible(foodRestriction, targetRestriction) {
    if (!targetRestriction || targetRestriction === 'none' || targetRestriction === 'all') {
        return true;
    }
    const r = (foodRestriction || 'none').toLowerCase().trim();
    const target = targetRestriction.toLowerCase().trim();

    if (r === target) return true;

    // Vegan dishes are suitable for vegetarian, pescatarian, halal
    if (target === 'vegetarian') {
        return r === 'vegetarian' || r === 'vegan';
    }
    if (target === 'pescatarian') {
        return r === 'pescatarian' || r === 'vegetarian' || r === 'vegan';
    }
    if (target === 'halal') {
        return r === 'halal' || r === 'vegetarian' || r === 'vegan';
    }
    if (target === 'vegan') {
        return r === 'vegan';
    }
    if (target === 'keto') {
        return r === 'keto';
    }
    if (target === 'gluten-free') {
        return r === 'gluten-free';
    }
    return false;
}

let selectedLocalFoodId = null;

function closeAllDropdowns() {
    const menus = [
        { menu: document.getElementById('day_custom_menu'), trigger: document.getElementById('day_dropdown_trigger'), arrow: document.getElementById('day_trigger_arrow'), wrap: document.getElementById('wrap_day_of_week') },
        { menu: document.getElementById('meal_type_custom_menu'), trigger: document.getElementById('meal_type_dropdown_trigger'), arrow: document.getElementById('meal_type_trigger_arrow'), wrap: document.getElementById('wrap_meal_type') },
        { menu: document.getElementById('custom_food_menu'), trigger: document.getElementById('food_dropdown_trigger'), arrow: document.getElementById('food_trigger_arrow'), wrap: document.getElementById('wrap_local_food') }
    ];

    menus.forEach(item => {
        if (item.menu) item.menu.style.display = 'none';
        if (item.trigger) item.trigger.classList.remove('is-open');
        if (item.arrow) item.arrow.style.transform = 'rotate(0deg)';
        if (item.wrap) item.wrap.style.zIndex = '';
    });
}

function toggleCustomSelect(param1, param2) {
    if (param1 && typeof param1.stopPropagation === 'function') {
        param1.stopPropagation();
    }

    let type = null;
    let forceState = null;

    if (typeof param1 === 'string') {
        type = param1;
        if (typeof param2 === 'boolean') forceState = param2;
    } else if (typeof param2 === 'string') {
        type = param2;
    }

    if (!type) return;

    const map = {
        'day': { menu: document.getElementById('day_custom_menu'), trigger: document.getElementById('day_dropdown_trigger'), arrow: document.getElementById('day_trigger_arrow'), wrap: document.getElementById('wrap_day_of_week') },
        'meal': { menu: document.getElementById('meal_type_custom_menu'), trigger: document.getElementById('meal_type_dropdown_trigger'), arrow: document.getElementById('meal_type_trigger_arrow'), wrap: document.getElementById('wrap_meal_type') },
        'food': { menu: document.getElementById('custom_food_menu'), trigger: document.getElementById('food_dropdown_trigger'), arrow: document.getElementById('food_trigger_arrow'), wrap: document.getElementById('wrap_local_food') }
    };

    const target = map[type];
    if (!target || !target.menu || !target.trigger) return;

    const isCurrentlyOpen = (target.menu.style.display === 'block');
    const shouldOpen = (forceState !== null) ? !!forceState : !isCurrentlyOpen;

    // Close all open dropdowns first
    closeAllDropdowns();

    if (shouldOpen) {
        target.menu.style.display = 'block';
        target.trigger.classList.add('is-open');
        if (target.arrow) target.arrow.style.transform = 'rotate(180deg)';
        if (target.wrap) target.wrap.style.zIndex = '1050';
    }
}

function toggleCustomFoodDropdown(forceOrEvent) {
    if (forceOrEvent && typeof forceOrEvent.stopPropagation === 'function') {
        forceOrEvent.stopPropagation();
        toggleCustomSelect('food');
    } else if (typeof forceOrEvent === 'boolean') {
        toggleCustomSelect('food', forceOrEvent);
    } else {
        toggleCustomSelect('food');
    }
}

function selectDayOfWeek(num, name, event) {
    if (event && typeof event.stopPropagation === 'function') {
        event.stopPropagation();
    }
    closeAllDropdowns();
    switchDay(num);
}

function selectMealType(type, event) {
    if (event && typeof event.stopPropagation === 'function') {
        event.stopPropagation();
    }
    closeAllDropdowns();
    const input = document.getElementById('meal_type_select');
    if (input) input.value = type;

    const label = document.getElementById('meal_type_trigger_label');
    if (label) label.textContent = type;

    const icons = {
        'Breakfast': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/></svg>',
        'Lunch': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
        'Dinner': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
        'Snack': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>'
    };
    const iconContainer = document.getElementById('meal_type_trigger_icon');
    if (iconContainer && icons[type]) {
        iconContainer.innerHTML = icons[type];
    }

    document.querySelectorAll('#meal_type_custom_menu .custom-select-option').forEach(el => {
        el.classList.toggle('is-selected', el.getAttribute('data-meal-type') === type);
    });

    updateLocalFoodDropdown();
}

function clearLocalFoodSelection(event) {
    if (event && typeof event.stopPropagation === 'function') {
        event.stopPropagation();
    }
    selectedLocalFoodId = null;
    const triggerLabel = document.getElementById('food_trigger_label');
    const clearBtn = document.getElementById('food_trigger_clear');
    const mealTypeSelect = document.getElementById('meal_type_select');
    const foodItemsInput = document.getElementById('meal_food_items');
    const pInput = document.getElementById('calc_p');
    const cInput = document.getElementById('calc_c');
    const fInput = document.getElementById('calc_f');
    const calsInput = document.getElementById('calc_cals');
    const imgInput = document.getElementById('meal_image_url');
    const indicator = document.getElementById('autofill_indicator');
    const indicatorText = document.getElementById('autofill_indicator_text');

    const typeName = mealTypeSelect ? mealTypeSelect.value : 'dish';
    if (triggerLabel) triggerLabel.textContent = `Select a ${typeName} dish...`;
    if (clearBtn) clearBtn.style.display = 'none';
    document.querySelectorAll('.custom-food-item').forEach(el => el.classList.remove('is-selected'));

    if (foodItemsInput) foodItemsInput.value = '';
    if (pInput) pInput.value = 0;
    if (cInput) cInput.value = 0;
    if (fInput) fInput.value = 0;
    if (calsInput) calsInput.value = 0;
    if (imgInput) imgInput.value = '';

    if (indicator && indicatorText) {
        indicatorText.textContent = 'Local food selection cleared.';
        indicator.style.display = 'inline-flex';
        setTimeout(() => { indicator.style.display = 'none'; }, 2000);
    }
}

function updateLocalFoodDropdown() {
    const mealTypeSelect = document.getElementById('meal_type_select');
    const listContainer = document.getElementById('custom_food_list');
    const showAllCheck = document.getElementById('show_all_restrictions');
    const countBadge = document.getElementById('food_trigger_badge');
    const countSpan = document.getElementById('library_item_count');
    const triggerLabel = document.getElementById('food_trigger_label');
    const clearBtn = document.getElementById('food_trigger_clear');
    if (!listContainer || !mealTypeSelect) return;

    const currentMealType = mealTypeSelect.value;
    const filterByDiet = !showAllCheck || !showAllCheck.checked;
    const activeRestriction = filterByDiet ? memberRestriction : 'all';

    const matchingFoods = localFoods.filter(f => {
        const matchesType = !currentMealType || f.meal_type.toLowerCase() === currentMealType.toLowerCase();
        const matchesDiet = isDietaryCompatible(f.dietary_restriction, activeRestriction);
        return matchesType && matchesDiet;
    });

    if (countBadge) {
        countBadge.textContent = matchingFoods.length;
    }
    if (countSpan) {
        const dietNote = (filterByDiet && memberRestriction !== 'none') ? ` (${memberRestriction.replace('-', ' ')})` : '';
        countSpan.textContent = `${matchingFoods.length} dish${matchingFoods.length === 1 ? '' : 'es'} available${dietNote}`;
    }

    // Reset or update trigger label
    if (selectedLocalFoodId && !matchingFoods.some(f => parseInt(f.food_id, 10) === selectedLocalFoodId)) {
        selectedLocalFoodId = null;
        if (triggerLabel) triggerLabel.textContent = `Select a ${currentMealType} dish...`;
        if (clearBtn) clearBtn.style.display = 'none';
    } else if (!selectedLocalFoodId && triggerLabel) {
        triggerLabel.textContent = `Select a ${currentMealType} dish...`;
    }

    if (matchingFoods.length === 0) {
        listContainer.innerHTML = `
            <div style="padding: 20px 14px; text-align: center; color: var(--muted); font-size: 12.5px;">
                <p style="margin: 0 0 4px; font-weight: 700; color: var(--ink);">No ${currentMealType} dishes found</p>
                <p style="margin: 0; font-size: 11.5px; opacity: 0.8;">Try checking "Show all diets" or search below.</p>
            </div>
        `;
        return;
    }

    listContainer.innerHTML = matchingFoods.map(food => {
        const id = parseInt(food.food_id, 10);
        const isSelected = (selectedLocalFoodId === id);
        const img = food.image_url || '';
        const dietTag = (food.dietary_restriction && food.dietary_restriction !== 'none') 
            ? `<span class="custom-food-tag">${escapeHtml(food.dietary_restriction)}</span>` 
            : '';
        const serving = food.serving_size ? `<span>${escapeHtml(food.serving_size)}</span> &bull; ` : '';

        return `
            <div class="custom-food-item ${isSelected ? 'is-selected' : ''}" data-food-id="${id}">
                ${img ? `<img src="${escapeHtml(img)}" class="custom-food-thumb" alt="${escapeHtml(food.name)}" onerror="this.style.display='none'">` : `
                    <div class="custom-food-thumb" style="display: flex; align-items: center; justify-content: center; color: var(--muted);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/></svg>
                    </div>
                `}
                <div style="flex: 1; min-width: 0;">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px;">
                        <span class="custom-food-title">${escapeHtml(food.name)}</span>
                        ${dietTag}
                    </div>
                    <div class="custom-food-meta">
                        ${serving}
                        <span class="custom-food-cal">${escapeHtml(food.calories)} kcal</span>
                        <span style="opacity: 0.5;">&bull;</span>
                        <span>P: ${escapeHtml(food.protein_g)}g</span>
                        <span>C: ${escapeHtml(food.carbs_g)}g</span>
                        <span>F: ${escapeHtml(food.fat_g)}g</span>
                    </div>
                </div>
            </div>
        `;
    }).join('');

    // Attach click listeners to options
    listContainer.querySelectorAll('.custom-food-item').forEach(el => {
        el.addEventListener('click', function(e) {
            e.stopPropagation();
            const id = parseInt(this.getAttribute('data-food-id'), 10);
            const chosen = localFoods.find(f => parseInt(f.food_id, 10) === id);
            if (chosen) {
                selectedLocalFoodId = id;
                selectedOnlineFood = null;
                const clearOnlineBtn = document.getElementById('btn_clear_online_food');
                const onlineBtnText = document.getElementById('online_search_btn_text');
                if (clearOnlineBtn) clearOnlineBtn.style.display = 'none';
                if (onlineBtnText) onlineBtnText.textContent = 'Search Online (Open Food Facts)';

                if (triggerLabel) {
                    triggerLabel.textContent = `${chosen.name} (${chosen.calories} kcal)`;
                }
                if (clearBtn) {
                    clearBtn.style.display = 'inline-flex';
                }
                closeAllDropdowns();
                updateLocalFoodDropdown();
                selectFoodItem(chosen, false);
            }
        });
    });
}

let selectedOnlineFood = null;

function selectFoodItem(item, syncMealType = true) {
    const foodItemsInput = document.getElementById('meal_food_items');
    const pInput = document.getElementById('calc_p');
    const cInput = document.getElementById('calc_c');
    const fInput = document.getElementById('calc_f');
    const calsInput = document.getElementById('calc_cals');
    const imgInput = document.getElementById('meal_image_url');
    const typeSelect = document.getElementById('meal_type_select');
    const indicator = document.getElementById('autofill_indicator');
    const indicatorText = document.getElementById('autofill_indicator_text');
    const clearOnlineBtn = document.getElementById('btn_clear_online_food');
    const onlineBtnText = document.getElementById('online_search_btn_text');

    const name = item.name;
    const cals = (item.calories !== undefined && item.calories !== null) ? item.calories : (item.calories_100g || 0);
    const pro = (item.protein_g !== undefined && item.protein_g !== null) ? item.protein_g : (item.protein_100g || 0);
    const carbs = (item.carbs_g !== undefined && item.carbs_g !== null) ? item.carbs_g : (item.carbs_100g || 0);
    const fat = (item.fat_g !== undefined && item.fat_g !== null) ? item.fat_g : (item.fat_100g || 0);
    const img = item.image || item.image_url || '';

    if (foodItemsInput) {
        foodItemsInput.value = item.serving_size ? `${item.serving_size} of ${name}` : name;
    }
    if (pInput) pInput.value = pro;
    if (cInput) cInput.value = carbs;
    if (fInput) fInput.value = fat;
    if (calsInput) calsInput.value = cals;
    if (imgInput) imgInput.value = img;

    if (syncMealType && item.meal_type) {
        selectMealType(item.meal_type);
    }

    if (item.is_online) {
        selectedOnlineFood = item;
        // Reset local dropdown if any was selected
        selectedLocalFoodId = null;
        const triggerLabel = document.getElementById('food_trigger_label');
        const localClearBtn = document.getElementById('food_trigger_clear');
        const typeName = typeSelect ? typeSelect.value : 'dish';
        if (triggerLabel) triggerLabel.textContent = `Select a ${typeName} dish...`;
        if (localClearBtn) localClearBtn.style.display = 'none';
        document.querySelectorAll('.custom-food-item').forEach(el => el.classList.remove('is-selected'));

        // Show the online clear button ONLY for online food
        if (clearOnlineBtn) {
            clearOnlineBtn.style.display = 'inline-flex';
        }
        if (onlineBtnText) {
            onlineBtnText.textContent = `Online: ${name} (${cals} kcal)`;
        }
    } else {
        // Local food selected: ensure online clear button is hidden
        selectedOnlineFood = null;
        if (clearOnlineBtn) {
            clearOnlineBtn.style.display = 'none';
        }
        if (onlineBtnText) {
            onlineBtnText.textContent = 'Search Online (Open Food Facts)';
        }
    }

    if (indicator) {
        if (indicatorText) {
            indicatorText.textContent = `Selected "${name}" (${cals} kcal · ${pro}g P) auto-filled!`;
        }
        indicator.style.display = 'inline-flex';
        setTimeout(() => { indicator.style.display = 'none'; }, 4000);
    }
}

function clearOnlineFoodSelection() {
    selectedOnlineFood = null;
    const foodItemsInput = document.getElementById('meal_food_items');
    const pInput = document.getElementById('calc_p');
    const cInput = document.getElementById('calc_c');
    const fInput = document.getElementById('calc_f');
    const calsInput = document.getElementById('calc_cals');
    const imgInput = document.getElementById('meal_image_url');
    const clearBtn = document.getElementById('btn_clear_online_food');
    const btnText = document.getElementById('online_search_btn_text');
    const indicator = document.getElementById('autofill_indicator');
    const indicatorText = document.getElementById('autofill_indicator_text');

    if (foodItemsInput) foodItemsInput.value = '';
    if (pInput) pInput.value = 0;
    if (cInput) cInput.value = 0;
    if (fInput) fInput.value = 0;
    if (calsInput) calsInput.value = 0;
    if (imgInput) imgInput.value = '';

    if (clearBtn) clearBtn.style.display = 'none';
    if (btnText) btnText.textContent = 'Search Online (Open Food Facts)';

    if (indicator && indicatorText) {
        indicatorText.textContent = 'Online food selection cleared.';
        indicator.style.display = 'inline-flex';
        setTimeout(() => { indicator.style.display = 'none'; }, 2000);
    }
}

// csrfToken defined from FT_DIET_BUILDER_CONFIG
let currentActiveDay = 1;
let selectedTargetDays = [1];

function toggleTargetDay(dayNum) {
    dayNum = parseInt(dayNum, 10);
    const idx = selectedTargetDays.indexOf(dayNum);
    if (idx > -1) {
        if (selectedTargetDays.length > 1) {
            selectedTargetDays.splice(idx, 1);
        }
    } else {
        selectedTargetDays.push(dayNum);
        selectedTargetDays.sort((a, b) => a - b);
    }
    syncTargetDaysUI();
}

function setTargetDaysPreset(preset) {
    if (preset === 'active') {
        selectedTargetDays = [currentActiveDay];
    } else if (preset === 'weekdays') {
        selectedTargetDays = [1, 2, 3, 4, 5];
    } else if (preset === 'all') {
        selectedTargetDays = [1, 2, 3, 4, 5, 6, 7];
    } else if (preset === 'weekend') {
        selectedTargetDays = [6, 7];
    }
    syncTargetDaysUI();
}

function syncTargetDaysUI() {
    const daysMapJs = {1:'Mon', 2:'Tue', 3:'Wed', 4:'Thu', 5:'Fri', 6:'Sat', 7:'Sun'};
    const daysFullMapJs = {1:'Monday', 2:'Tuesday', 3:'Wednesday', 4:'Thursday', 5:'Friday', 6:'Saturday', 7:'Sunday'};
    
    // Update chip classes
    document.querySelectorAll('#target_days_pills .day-chip').forEach(btn => {
        const d = parseInt(btn.getAttribute('data-day'), 10);
        btn.classList.toggle('is-active', selectedTargetDays.includes(d));
    });

    // Update hidden inputs
    const inputsContainer = document.getElementById('target_days_inputs');
    if (inputsContainer) {
        inputsContainer.innerHTML = selectedTargetDays.map(d => `<input type="hidden" name="days_of_week[]" value="${d}">`).join('');
    }

    // Update count badge
    const badge = document.getElementById('target_days_count_badge');
    if (badge) {
        if (selectedTargetDays.length === 1) {
            badge.textContent = `1 day (${daysFullMapJs[selectedTargetDays[0]]})`;
        } else if (selectedTargetDays.length === 5 && selectedTargetDays.join(',') === '1,2,3,4,5') {
            badge.textContent = `5 days (Mon - Fri)`;
        } else if (selectedTargetDays.length === 7) {
            badge.textContent = `All 7 days`;
        } else {
            badge.textContent = `${selectedTargetDays.length} days (${selectedTargetDays.map(d => daysMapJs[d]).join(', ')})`;
        }
    }

    // Update Submit Button Text
    const submitBtnText = document.getElementById('btn_submit_add_meal_text');
    if (submitBtnText) {
        if (selectedTargetDays.length === 1) {
            submitBtnText.textContent = `Add Meal to Plan`;
        } else if (selectedTargetDays.length === 5 && selectedTargetDays.join(',') === '1,2,3,4,5') {
            submitBtnText.textContent = `Add Meal to 5 Days (M-F)`;
        } else if (selectedTargetDays.length === 7) {
            submitBtnText.textContent = `Add Meal to All 7 Days`;
        } else {
            submitBtnText.textContent = `Add Meal to ${selectedTargetDays.length} Days`;
        }
    }
}

function quickSelectMealSlot(dayNum, mealType) {
    switchDay(dayNum);
    setTargetDaysPreset('active');
    selectMealType(mealType);
    const wrap = document.getElementById('wrap_local_food');
    if (wrap) {
        wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => {
            toggleCustomSelect('food', true);
        }, 300);
    }
}

function openCopyDayModal(sourceDayNum, sourceDayName, mealCount) {
    const days = [
        { num: 1, name: 'Monday' },
        { num: 2, name: 'Tuesday' },
        { num: 3, name: 'Wednesday' },
        { num: 4, name: 'Thursday' },
        { num: 5, name: 'Friday' },
        { num: 6, name: 'Saturday' },
        { num: 7, name: 'Sunday' }
    ];
    
    const targets = days.filter(d => d.num !== sourceDayNum);
    let checkboxesHtml = targets.map(t => `
        <label style="display: flex; align-items: center; justify-content: space-between; padding: 9px 12px; background: rgba(255,255,255,0.03); border: 1px solid var(--diet-border); border-radius: 8px; cursor: pointer; margin-bottom: 6px; transition: border-color 0.15s;">
            <span style="font-weight: 600; font-size: 13px; color: var(--ink);">${t.name}</span>
            <input type="checkbox" value="${t.num}" class="swal-target-day-cb" ${t.num <= 5 ? 'checked' : ''} style="width: 17px; height: 17px; accent-color: var(--diet-accent); cursor: pointer;">
        </label>
    `).join('');

    Swal.fire({
        title: `Copy ${sourceDayName} Menu`,
        html: `
            <div style="text-align: left; font-size: 13px;">
                <p style="color: var(--diet-muted); margin: 0 0 12px; line-height: 1.4;">Copy all <strong>${mealCount} meals</strong> from <strong>${sourceDayName}</strong> to other days:</p>
                <div style="display: flex; gap: 6px; margin-bottom: 12px; flex-wrap: wrap;">
                    <button type="button" onclick="document.querySelectorAll('.swal-target-day-cb').forEach(c => c.checked = (parseInt(c.value) <= 5));" style="padding: 4px 9px; border-radius: 5px; font-size: 11px; font-weight: 600; background: var(--surface); color: var(--ink); border: 1px solid var(--diet-border); cursor: pointer;">Select Weekdays</button>
                    <button type="button" onclick="document.querySelectorAll('.swal-target-day-cb').forEach(c => c.checked = true);" style="padding: 4px 9px; border-radius: 5px; font-size: 11px; font-weight: 600; background: var(--surface); color: var(--ink); border: 1px solid var(--diet-border); cursor: pointer;">Select All</button>
                    <button type="button" onclick="document.querySelectorAll('.swal-target-day-cb').forEach(c => c.checked = false);" style="padding: 4px 9px; border-radius: 5px; font-size: 11px; font-weight: 600; background: var(--surface); color: var(--ink); border: 1px solid var(--diet-border); cursor: pointer;">Deselect All</button>
                </div>
                <div style="max-height: 200px; overflow-y: auto; margin-bottom: 12px; padding-right: 4px;">
                    ${checkboxesHtml}
                </div>
                <label style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--diet-muted); cursor: pointer; padding-top: 10px; border-top: 1px solid var(--diet-border);">
                    <input type="checkbox" id="swal_replace_existing" checked style="width: 15px; height: 15px; accent-color: var(--diet-accent); cursor: pointer;">
                    <span>Replace existing meals on target days</span>
                </label>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: '⚡ Copy Menu Now',
        cancelButtonText: 'Cancel',
        confirmButtonColor: 'var(--lime-dark, #65a30d)',
        cancelButtonColor: 'var(--line, #334155)',
        background: 'var(--panel, #121721)',
        color: 'var(--ink, #ffffff)',
        reverseButtons: true,
        preConfirm: () => {
            const selected = Array.from(document.querySelectorAll('.swal-target-day-cb:checked')).map(c => c.value);
            if (selected.length === 0) {
                Swal.showValidationMessage('Please select at least one day to copy to.');
                return false;
            }
            const replace = document.getElementById('swal_replace_existing').checked ? 1 : 0;
            return { targetDays: selected, replaceExisting: replace };
        }
    }).then(result => {
        if (result && result.isConfirmed) {
            submitCopyDayForm(sourceDayNum, result.value.targetDays, result.value.replaceExisting);
        }
    });
}

function submitCopyDayForm(fromDay, targetDays, replaceExisting) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';

    let html = `
        <input type="hidden" name="csrf_token" value="${escapeHtml(csrfToken)}">
        <input type="hidden" name="action" value="copy_day_menu">
        <input type="hidden" name="from_day" value="${fromDay}">
        <input type="hidden" name="replace_existing" value="${replaceExisting}">
        <input type="hidden" name="active_day" value="${fromDay}">
    `;
    targetDays.forEach(td => {
        html += `<input type="hidden" name="target_days[]" value="${td}">`;
    });
    form.innerHTML = html;
    document.body.appendChild(form);
    form.submit();
}

function confirmClearDay(dayNum, dayName) {
    Swal.fire({
        title: `Clear ${dayName}?`,
        text: `Are you sure you want to remove all meals from ${dayName}?`,
        icon: 'warning',
        iconColor: '#ef4444',
        showCancelButton: true,
        confirmButtonText: 'Yes, Clear Day',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#ef4444',
        cancelButtonColor: 'var(--line, #334155)',
        background: 'var(--panel, #121721)',
        color: 'var(--ink, #ffffff)',
        reverseButtons: true
    }).then(res => {
        if (res && res.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            form.innerHTML = `
                <input type="hidden" name="csrf_token" value="${escapeHtml(csrfToken)}">
                <input type="hidden" name="action" value="clear_day_menu">
                <input type="hidden" name="clear_day" value="${dayNum}">
                <input type="hidden" name="active_day" value="${dayNum}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

function switchDay(dayNum) {
    dayNum = parseInt(dayNum, 10);
    if (!dayNum || dayNum < 1 || dayNum > 7) dayNum = 1;
    currentActiveDay = dayNum;

    try {
        localStorage.setItem('fittracks_builder_day', dayNum);
    } catch(e) {}

    const builderActiveDayInput = document.getElementById('builder_active_day_input');
    if (builderActiveDayInput) {
        builderActiveDayInput.value = dayNum;
    }

    // If only 1 target day was selected, auto-update it to the new active day
    if (selectedTargetDays.length === 1) {
        selectedTargetDays = [dayNum];
        syncTargetDaysUI();
    }

    document.querySelectorAll('.day-panel').forEach(panel => {
        panel.style.display = 'none';
        panel.classList.remove('active');
    });
    const activePanel = document.getElementById('day-panel-' + dayNum);
    if (activePanel) {
        activePanel.style.display = 'block';
        activePanel.classList.add('active');
    }

    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    const activeBtn = document.querySelector('.tab-btn[data-day="' + dayNum + '"]');
    if (activeBtn) {
        activeBtn.classList.add('active');
    }

    const dayInput = document.getElementById('input_day_of_week');
    if (dayInput) {
        dayInput.value = dayNum;
    }
    const daysMapJs = {1:'Monday', 2:'Tuesday', 3:'Wednesday', 4:'Thursday', 5:'Friday', 6:'Saturday', 7:'Sunday'};
    const dayTriggerLabel = document.getElementById('day_trigger_label');
    const dayBadge = document.getElementById('day_trigger_badge');
    if (dayTriggerLabel && daysMapJs[dayNum]) {
        dayTriggerLabel.textContent = daysMapJs[dayNum];
    }
    if (dayBadge) {
        dayBadge.textContent = 'Day ' + dayNum;
    }
    document.querySelectorAll('#day_custom_menu .custom-select-option').forEach(el => {
        el.classList.toggle('is-selected', parseInt(el.getAttribute('data-day'), 10) === parseInt(dayNum, 10));
    });
}

// Online Food Search Modal Controller
let modalSearchDebounce = null;

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function openOnlineSearchModal() {
    const modal = document.getElementById('modal-online-food-search');
    const input = document.getElementById('modal_online_query');
    const clearBtn = document.getElementById('modal_online_clear_btn');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
        setTimeout(() => {
            if (input) {
                input.focus();
                if (clearBtn) {
                    clearBtn.style.display = input.value.trim().length > 0 ? 'inline-flex' : 'none';
                }
                if (input.value.trim().length >= 2) {
                    executeModalOnlineSearch(input.value.trim());
                }
            }
        }, 100);
    }
}

function closeOnlineSearchModal() {
    const modal = document.getElementById('modal-online-food-search');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('active');
    }
}

function executeModalOnlineSearch(query) {
    const resultsContainer = document.getElementById('modal_online_results');
    const loader = document.getElementById('modal_online_loader');
    if (!resultsContainer) return;

    if (!query || query.length < 2) {
        resultsContainer.innerHTML = `
            <div style="text-align: center; padding: 36px 14px; color: var(--muted); font-size: 13px;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="margin: 0 auto 10px; opacity: 0.5; display: block;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                Type a food or brand name above to search online.
            </div>
        `;
        return;
    }

    if (loader) loader.style.display = 'block';

    const url = 'index.php?page=food_lookup&action=openfoodfacts&query=' + encodeURIComponent(query);

    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (loader) loader.style.display = 'none';
            const items = data.products || [];

            if (items.length === 0) {
                resultsContainer.innerHTML = `
                    <div style="text-align: center; padding: 30px 14px; color: var(--muted); font-size: 13px;">
                        No online products found for "<strong>${escapeHtml(query)}</strong>".
                    </div>
                `;
                return;
            }

            resultsContainer.innerHTML = items.map((item, idx) => {
                const name = item.name || 'Unknown Product';
                const cals = item.calories_100g || 0;
                const pro = item.protein_100g || 0;
                const carbs = item.carbs_100g || 0;
                const fat = item.fat_100g || 0;
                const img = item.image || item.image_url || '';

                return `
                    <div class="modal-online-food-item" data-idx="${idx}" style="padding: 10px 12px; border-radius: 8px; border: 1px solid var(--line); background: var(--bg); cursor: pointer; display: flex; align-items: center; gap: 12px; transition: all 0.15s ease;">
                        ${img ? `<img src="${escapeHtml(img)}" style="width: 42px; height: 42px; border-radius: 6px; object-fit: cover; flex-shrink: 0; background: var(--panel-soft);" onerror="this.style.display='none'">` : `
                            <div style="width: 42px; height: 42px; border-radius: 6px; background: var(--panel-soft); display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--muted);">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/></svg>
                            </div>
                        `}
                        <div style="flex: 1; min-width: 0;">
                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 6px;">
                                <span style="color: var(--ink); font-size: 13.5px; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(name)}</span>
                                <span style="font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 4px; background: rgba(56, 189, 248, 0.15); color: #38bdf8; white-space: nowrap;">Open Food Facts</span>
                            </div>
                            <div style="display: flex; gap: 8px; font-size: 11.5px; color: var(--diet-muted); margin-top: 3px; align-items: center; flex-wrap: wrap;">
                                <span style="color: var(--diet-accent); font-weight: 700;">${cals} kcal / 100g</span>
                                <span style="opacity: 0.5;">&bull;</span>
                                <span>P: ${pro}g</span>
                                <span>C: ${carbs}g</span>
                                <span>F: ${fat}g</span>
                            </div>
                        </div>
                        <button type="button" style="font-size: 11px; font-weight: 700; padding: 4px 12px; height: auto; min-height: 28px; border-radius: 6px; border: 1px solid var(--diet-accent); background: var(--diet-accent); color: #080b0d; flex-shrink: 0; pointer-events: none;">
                            Select
                        </button>
                    </div>
                `;
            }).join('');

            resultsContainer.querySelectorAll('.modal-online-food-item').forEach((row, i) => {
                row.addEventListener('mouseenter', () => {
                    row.style.borderColor = 'var(--diet-accent)';
                    row.style.background = 'var(--panel-soft)';
                });
                row.addEventListener('mouseleave', () => {
                    row.style.borderColor = 'var(--diet-border)';
                    row.style.background = 'var(--bg)';
                });
                row.addEventListener('click', () => {
                    const chosen = items[i];
                    selectFoodItem({
                        name: chosen.name,
                        serving_size: '100g',
                        calories: chosen.calories_100g || 0,
                        protein_g: chosen.protein_100g || 0,
                        carbs_g: chosen.carbs_100g || 0,
                        fat_g: chosen.fat_100g || 0,
                        image: chosen.image || chosen.image_url || '',
                        is_online: true
                    }, false);
                    closeOnlineSearchModal();
                });
            });
        })
        .catch(() => {
            if (loader) loader.style.display = 'none';
            resultsContainer.innerHTML = '<div style="text-align: center; padding: 24px; color: var(--danger); font-size: 12.5px;">Network error connecting to Open Food Facts. Please try again.</div>';
        });
}

function clearModalOnlineQuery() {
    const input = document.getElementById('modal_online_query');
    const clearBtn = document.getElementById('modal_online_clear_btn');
    if (input) {
        input.value = '';
        input.focus();
    }
    if (clearBtn) {
        clearBtn.style.display = 'none';
    }
    executeModalOnlineSearch('');
}

document.addEventListener("DOMContentLoaded", function() {
    const urlParams = new URLSearchParams(window.location.search);
    let initialDay = parseInt(urlParams.get('active_day'), 10);
    if (!initialDay || isNaN(initialDay)) {
        try {
            initialDay = parseInt(localStorage.getItem('fittracks_builder_day'), 10);
        } catch(e) {}
    }
    if (!initialDay || initialDay < 1 || initialDay > 7) {
        initialDay = 1;
    }
    switchDay(initialDay);
    setTargetDaysPreset('active');
    updateMacros();
    updateLocalFoodDropdown();

    const onlineModalInput = document.getElementById('modal_online_query');
    const modalClearBtn = document.getElementById('modal_online_clear_btn');
    if (onlineModalInput) {
        onlineModalInput.addEventListener('input', function() {
            clearTimeout(modalSearchDebounce);
            const val = this.value.trim();
            if (modalClearBtn) {
                modalClearBtn.style.display = val.length > 0 ? 'inline-flex' : 'none';
            }
            modalSearchDebounce = setTimeout(() => {
                executeModalOnlineSearch(val);
            }, 300);
        });
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.custom-food-dropdown-wrap')) {
            closeAllDropdowns();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeOnlineSearchModal();
            closeAllDropdowns();
        }
    });
});
