/**
 * Diet & Nutrition Controller
 * Extracted from diet.php
 */

const FT_CSRF_TOKEN = (window.DIET_CONFIG && window.DIET_CONFIG.csrfToken)
    ? window.DIET_CONFIG.csrfToken
    : (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
window.FT_CSRF_TOKEN = FT_CSRF_TOKEN;

function getLiveCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || (window.DIET_CONFIG && window.DIET_CONFIG.csrfToken)
        || window.FT_CSRF_TOKEN
        || (document.querySelector('input[name="csrf_token"]')?.value)
        || '';
}
window.getLiveCsrfToken = getLiveCsrfToken;

function confirmRegeneratePlan() {
    Swal.fire({
        title: 'Regenerate Diet Plan?',
        text: 'This will archive your current plan and generate a new customized plan based on your current weight and goals.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: 'var(--lime, #c7ff22)',
        cancelButtonColor: 'transparent',
        confirmButtonText: '<span style="color:var(--lime-btn-text, #090b10);font-weight:800;">Yes, Regenerate Plan</span>',
        cancelButtonText: 'Cancel',
        background: getComputedStyle(document.documentElement).getPropertyValue('--panel-bg').trim() || '#121721',
        color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('regenerate-plan-form').submit();
        }
    });
}

window.confirmRegeneratePlan = confirmRegeneratePlan;

// Popover Controller for Multi-Gym Plan Switcher
window.toggleDietPlanPopover = function(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    const wrap = document.getElementById('diet-plan-popover-wrap');
    const btn = document.getElementById('diet-plan-popover-trigger');
    if (!wrap) return;

    const isOpen = wrap.classList.toggle('open');
    if (btn) {
        btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
};

document.addEventListener('click', function(e) {
    const wrap = document.getElementById('diet-plan-popover-wrap');
    if (wrap && wrap.classList.contains('open')) {
        if (!wrap.contains(e.target)) {
            wrap.classList.remove('open');
            const btn = document.getElementById('diet-plan-popover-trigger');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        }
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const wrap = document.getElementById('diet-plan-popover-wrap');
        if (wrap && wrap.classList.contains('open')) {
            wrap.classList.remove('open');
            const btn = document.getElementById('diet-plan-popover-trigger');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        }
        if (typeof closeDietPlanInfoModal === 'function') {
            closeDietPlanInfoModal();
        }
    }
});

/* ====================================================
   NUTRITION PORTION & UNIT CONVERSION UTILITIES
   ==================================================== */
const PORTION_UNIT_FACTORS = {
    g: 1,
    ml: 1,
    l: 1000,
    oz: 28.3495,
    cup: 240,
    tbsp: 15,
    tsp: 5,
    pcs: null, // dynamically uses item.baseServingG (1 piece)
    serving: null // dynamically uses item.baseServingG
};

function getPortionUnitFactor(unit, baseServingG) {
    if (unit === 'serving' || unit === 'pcs' || unit === 'pc') return baseServingG || 100;
    return PORTION_UNIT_FACTORS[unit] || 1;
}

function detectSmartFoodUnit(name, portion, baseServingG) {
    const nameLower = (name || '').toLowerCase();
    const portionLower = (portion || '').toLowerCase();
    if (portionLower.includes('ml')) return 'ml';
    if (portionLower.includes('liter') || portionLower.includes('litre') || portionLower.match(/\b\d+(\.\d+)?\s*l\b/i)) return 'l';
    if (portionLower.includes('oz') || portionLower.includes('ounce')) return 'oz';
    if (portionLower.includes('cup')) return 'cup';
    if (portionLower.includes('tbsp') || portionLower.includes('tablespoon')) return 'tbsp';
    if (portionLower.includes('tsp') || portionLower.includes('teaspoon')) return 'tsp';
    if (nameLower.match(/\b(milk|juice|water|tea|coffee|soda|shake|oil|soup|broth|beverage|drink|sauce)\b/)) {
        return baseServingG >= 1000 ? 'l' : 'ml';
    }
    return 'g';
}

function formatPortionQty(qty) {
    if (qty >= 10) return Math.round(qty * 10) / 10;
    return Math.round(qty * 100) / 100;
}

/* ====================================================
   SECTION 2: Macro Tracker Controller (IIFE)
   ==================================================== */
(function() {
    const form       = document.getElementById('macro-log-form');
    const saveBtn    = document.getElementById('macro-save-btn');
    const saveBtnTxt = document.getElementById('btn-save-text');
    const btnAutoCal = document.getElementById('btn-auto-calc-cals');
    const btnReset   = document.getElementById('btn-reset-macros');
    const modeInput  = document.getElementById('macro-input-mode');

    const tabAdd = document.getElementById('tab-mode-add');
    const tabSet = document.getElementById('tab-mode-set');

    const lblCals  = document.getElementById('lbl-text-cals');
    const lblPro   = document.getElementById('lbl-text-pro');
    const lblCarbs = document.getElementById('lbl-text-carbs');
    const lblFat   = document.getElementById('lbl-text-fat');
    
    // Inputs
    const inCals  = document.getElementById('macro-input-cals');
    const inPro   = document.getElementById('macro-input-pro');
    const inCarbs = document.getElementById('macro-input-carbs');
    const inFat   = document.getElementById('macro-input-fat');

    // Displays
    const calsDisp  = document.getElementById('macro-logged-cals');
    const tarDisp   = document.getElementById('macro-target-cals');
    const pctCals   = document.getElementById('macro-pct-cals');
    const barCals   = document.getElementById('macro-progress-bar-cals');

    const proDisp   = document.getElementById('macro-logged-pro');
    const tarPro    = document.getElementById('macro-target-pro');
    const pctPro    = document.getElementById('macro-pct-pro');
    const barPro    = document.getElementById('macro-progress-bar-pro');

    const carbsDisp = document.getElementById('macro-logged-carbs');
    const tarCarbs  = document.getElementById('macro-target-carbs');
    const pctCarbs  = document.getElementById('macro-pct-carbs');
    const barCarbs  = document.getElementById('macro-progress-bar-carbs');

    const fatDisp   = document.getElementById('macro-logged-fat');
    const tarFat    = document.getElementById('macro-target-fat');
    const pctFat    = document.getElementById('macro-pct-fat');
    const barFat    = document.getElementById('macro-progress-bar-fat');

    if (!form) return;
    const csrf = form.querySelector('[name="csrf_token"]').value;

    window.setMacroLogMode = function(mode) {
        if (!modeInput) return;
        modeInput.value = mode;

        if (mode === 'add') {
            tabAdd?.classList.add('active');
            tabSet?.classList.remove('active');

            if (lblCals)  lblCals.textContent  = 'Cal';
            if (lblPro)   lblPro.textContent   = 'Pro';
            if (lblCarbs) lblCarbs.textContent = 'Carb';
            if (lblFat)   lblFat.textContent   = 'Fat';

            if (inCals)  inCals.placeholder  = '+0';
            if (inPro)   inPro.placeholder   = '+0';
            if (inCarbs) inCarbs.placeholder = '+0';
            if (inFat)   inFat.placeholder   = '+0';

            if (inCals)  inCals.value  = '';
            if (inPro)   inPro.value   = '';
            if (inCarbs) inCarbs.value = '';
            if (inFat)   inFat.value   = '';

            if (saveBtnTxt) saveBtnTxt.textContent = '+ Add to Log';
        } else {
            tabSet?.classList.add('active');
            tabAdd?.classList.remove('active');

            if (lblCals)  lblCals.textContent  = 'Cal';
            if (lblPro)   lblPro.textContent   = 'Pro';
            if (lblCarbs) lblCarbs.textContent = 'Carb';
            if (lblFat)   lblFat.textContent   = 'Fat';

            if (inCals)  inCals.placeholder  = '0';
            if (inPro)   inPro.placeholder   = '0';
            if (inCarbs) inCarbs.placeholder = '0';
            if (inFat)   inFat.placeholder   = '0';

            // Populate with current daily totals
            if (inCals)  inCals.value  = (calsDisp?.textContent?.trim()  || '0');
            if (inPro)   inPro.value   = (proDisp?.textContent?.trim()   || '0');
            if (inCarbs) inCarbs.value = (carbsDisp?.textContent?.trim() || '0');
            if (inFat)   inFat.value   = (fatDisp?.textContent?.trim()   || '0');

            if (saveBtnTxt) saveBtnTxt.textContent = 'Update Total';
        }
    };

    // -------------------------------------------------------------
    // Smart Meal Assistant Handlers (CalorieNinjas Natural Language)
    // -------------------------------------------------------------
    const ninjaInput      = document.getElementById('smart-ninja-input');
    const ninjaBtn        = document.getElementById('btn-analyze-ninja');
    const ninjaBtnTxt     = document.getElementById('btn-analyze-ninja-txt');
    const ninjaBreakdown  = document.getElementById('ninja-breakdown-wrap');
    const ninjaItemsEl    = document.getElementById('ninja-breakdown-items');
    const ninjaTotalsEl   = document.getElementById('ninja-breakdown-totals');

    // In-memory memoization cache for smart meal queries (seeded with the quick meal presets)
    const clientMealCache = new Map([
        ['2 boiled eggs and 1 slice whole wheat bread', {
            success: true,
            total_calories: 217,
            total_protein: 16.2,
            total_carbs: 14.5,
            total_fat: 10.6,
            items: [
                { name: 'Boiled Eggs', serving_size_g: 100, calories: 143, protein_g: 12.6, carbs_g: 0.7, fat_g: 9.5 },
                { name: 'Whole Wheat Bread', serving_size_g: 30, calories: 74, protein_g: 3.6, carbs_g: 13.8, fat_g: 1.1 }
            ]
        }],
        ['150g grilled chicken breast and 1 cup white rice', {
            success: true,
            total_calories: 489,
            total_protein: 50.9,
            total_carbs: 53.2,
            total_fat: 5.8,
            items: [
                { name: 'Grilled Chicken Breast', serving_size_g: 150, calories: 247, protein_g: 46.5, carbs_g: 0.0, fat_g: 5.4 },
                { name: 'White Rice', serving_size_g: 186, calories: 242, protein_g: 4.4, carbs_g: 53.2, fat_g: 0.4 }
            ]
        }],
        ['1 scoop whey protein and 1 cup milk', {
            success: true,
            total_calories: 269,
            total_protein: 31.7,
            total_carbs: 13.7,
            total_fat: 9.4,
            items: [
                { name: 'Whey Protein', serving_size_g: 30, calories: 120, protein_g: 24.0, carbs_g: 2.0, fat_g: 1.5 },
                { name: 'Milk', serving_size_g: 244, calories: 149, protein_g: 7.7, carbs_g: 11.7, fat_g: 7.9 }
            ]
        }],
        ['1 can tuna and 1 medium banana', {
            success: true,
            total_calories: 219,
            total_protein: 26.3,
            total_carbs: 27.4,
            total_fat: 0.9,
            items: [
                { name: 'Tuna', serving_size_g: 85, calories: 113, protein_g: 25.0, carbs_g: 0.0, fat_g: 0.5 },
                { name: 'Banana', serving_size_g: 118, calories: 106, protein_g: 1.3, carbs_g: 27.4, fat_g: 0.4 }
            ]
        }]
    ]);

    let currentSmartMealData = null;

    function renderMealBreakdownAndApply(data, mealLabel = 'Meal') {
        if (!data || !data.success || !data.items || !data.items.length) return;

        currentSmartMealData = {
            label: mealLabel,
            items: data.items.map((item, idx) => {
                const baseServingG = parseFloat(item.serving_size_g) > 0 ? parseFloat(item.serving_size_g) : 100;
                const baseCals = parseFloat(item.calories) || 0;
                const basePro = parseFloat(item.protein_g) || 0;
                const baseCarbs = parseFloat(item.carbs_g) || 0;
                const baseFat = parseFloat(item.fat_g) || 0;

                const defaultUnit = detectSmartFoodUnit(item.name, item.portion, baseServingG);
                let initialQty = baseServingG;
                if (defaultUnit === 'l') initialQty = baseServingG / 1000;
                else if (defaultUnit === 'oz') initialQty = baseServingG / 28.3495;
                else if (defaultUnit === 'cup') initialQty = baseServingG / 240;
                else if (defaultUnit === 'tbsp') initialQty = baseServingG / 15;
                else if (defaultUnit === 'tsp') initialQty = baseServingG / 5;

                return {
                    id: idx,
                    name: item.name,
                    baseServingG: baseServingG,
                    calsPerG: baseCals / baseServingG,
                    proPerG: basePro / baseServingG,
                    carbsPerG: baseCarbs / baseServingG,
                    fatPerG: baseFat / baseServingG,
                    currentUnit: defaultUnit,
                    currentQty: formatPortionQty(initialQty),
                    currentCals: baseCals,
                    currentPro: basePro,
                    currentCarbs: baseCarbs,
                    currentFat: baseFat,
                    isExcluded: false
                };
            })
        };

        renderBreakdownItemsHTML();
        recalculateBreakdownTotalsAndSync(true);
        if (ninjaBreakdown) ninjaBreakdown.style.display = 'block';
    }

    function renderBreakdownItemsHTML() {
        if (!ninjaItemsEl || !currentSmartMealData) return;

        ninjaItemsEl.innerHTML = currentSmartMealData.items.map(item => {
            if (item.isExcluded) return '';
            return `
                <div class="smart-breakdown-item" data-idx="${item.id}">
                    <div class="breakdown-item-main">
                        <span class="breakdown-item-name">${escapeHtml(item.name)}</span>
                        
                        <div class="breakdown-portion-ctrl" title="Click to adjust amount or measurement unit">
                            <input type="number" 
                                   class="breakdown-qty-input" 
                                   value="${item.currentQty}" 
                                   min="0" 
                                   step="any" 
                                   data-idx="${item.id}"
                                   aria-label="Quantity for ${escapeHtml(item.name)}">
                            <select class="breakdown-unit-select" data-idx="${item.id}" aria-label="Unit for ${escapeHtml(item.name)}">
                                <option value="g" ${item.currentUnit === 'g' ? 'selected' : ''}>g</option>
                                <option value="ml" ${item.currentUnit === 'ml' ? 'selected' : ''}>ml</option>
                                <option value="l" ${item.currentUnit === 'l' ? 'selected' : ''}>L</option>
                                <option value="oz" ${item.currentUnit === 'oz' ? 'selected' : ''}>oz</option>
                                <option value="cup" ${item.currentUnit === 'cup' ? 'selected' : ''}>cup</option>
                                <option value="tbsp" ${item.currentUnit === 'tbsp' ? 'selected' : ''}>tbsp</option>
                                <option value="tsp" ${item.currentUnit === 'tsp' ? 'selected' : ''}>tsp</option>
                                <option value="pcs" ${item.currentUnit === 'pcs' ? 'selected' : ''}>pcs</option>
                                <option value="serving" ${item.currentUnit === 'serving' ? 'selected' : ''}>serving</option>
                            </select>
                        </div>

                        <button type="button" class="breakdown-item-del-btn" data-idx="${item.id}" title="Remove this ingredient" onclick="removeBreakdownItem(${item.id})">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        </button>
                    </div>

                    <div class="breakdown-item-macros" id="breakdown-item-macros-${item.id}">
                        <span><strong class="breakdown-macro-val">${Math.round(item.currentCals)}</strong> <span class="breakdown-macro-unit">kcal</span></span>
                        <span class="breakdown-macro-sep">&bull;</span>
                        <span><strong class="breakdown-macro-val">${Math.round(item.currentPro * 10) / 10}</strong><span class="breakdown-macro-unit">g P</span></span>
                        <span class="breakdown-macro-sep">&bull;</span>
                        <span><strong class="breakdown-macro-val">${Math.round(item.currentCarbs * 10) / 10}</strong><span class="breakdown-macro-unit">g C</span></span>
                        <span class="breakdown-macro-sep">&bull;</span>
                        <span><strong class="breakdown-macro-val">${Math.round(item.currentFat * 10) / 10}</strong><span class="breakdown-macro-unit">g F</span></span>
                    </div>
                </div>
            `;
        }).join('');
    }

    function updateBreakdownRowMacros(idx) {
        if (!currentSmartMealData || !currentSmartMealData.items[idx]) return;
        const item = currentSmartMealData.items[idx];
        const macrosEl = document.getElementById('breakdown-item-macros-' + idx);
        if (!macrosEl) return;

        macrosEl.innerHTML = `
            <span><strong class="breakdown-macro-val">${Math.round(item.currentCals)}</strong> <span class="breakdown-macro-unit">kcal</span></span>
            <span class="breakdown-macro-sep">&bull;</span>
            <span><strong class="breakdown-macro-val">${Math.round(item.currentPro * 10) / 10}</strong><span class="breakdown-macro-unit">g P</span></span>
            <span class="breakdown-macro-sep">&bull;</span>
            <span><strong class="breakdown-macro-val">${Math.round(item.currentCarbs * 10) / 10}</strong><span class="breakdown-macro-unit">g C</span></span>
            <span class="breakdown-macro-sep">&bull;</span>
            <span><strong class="breakdown-macro-val">${Math.round(item.currentFat * 10) / 10}</strong><span class="breakdown-macro-unit">g F</span></span>
        `;
    }

    function onBreakdownQtyInput(idx, rawVal) {
        if (!currentSmartMealData || !currentSmartMealData.items[idx]) return;
        const item = currentSmartMealData.items[idx];
        if (item.isExcluded) return;

        let qty = parseFloat(rawVal);
        if (isNaN(qty) || qty < 0) qty = 0;
        item.currentQty = qty;

        const factor = getPortionUnitFactor(item.currentUnit, item.baseServingG);
        const effectiveGrams = Math.max(0, qty * factor);

        item.currentCals = item.calsPerG * effectiveGrams;
        item.currentPro = item.proPerG * effectiveGrams;
        item.currentCarbs = item.carbsPerG * effectiveGrams;
        item.currentFat = item.fatPerG * effectiveGrams;

        updateBreakdownRowMacros(idx);
        recalculateBreakdownTotalsAndSync(false);
    }

    function onBreakdownUnitChange(idx, newUnit) {
        if (!currentSmartMealData || !currentSmartMealData.items[idx]) return;
        const item = currentSmartMealData.items[idx];
        if (item.isExcluded) return;

        const oldUnit = item.currentUnit;
        const oldFactor = getPortionUnitFactor(oldUnit, item.baseServingG);
        const newFactor = getPortionUnitFactor(newUnit, item.baseServingG);

        const currentGrams = item.currentQty * oldFactor;
        let newQty = currentGrams / newFactor;
        newQty = formatPortionQty(newQty);

        item.currentUnit = newUnit;
        item.currentQty = newQty;

        const inputEl = ninjaItemsEl?.querySelector(`.breakdown-qty-input[data-idx="${idx}"]`);
        if (inputEl) inputEl.value = newQty;

        onBreakdownQtyInput(idx, newQty);
    }

    window.removeBreakdownItem = function(idx) {
        if (!currentSmartMealData || !currentSmartMealData.items[idx]) return;
        currentSmartMealData.items[idx].isExcluded = true;

        const rowEl = ninjaItemsEl?.querySelector(`.smart-breakdown-item[data-idx="${idx}"]`);
        if (rowEl) {
            if (typeof rowEl.remove === 'function') {
                rowEl.remove();
            } else if (rowEl.parentNode) {
                rowEl.parentNode.removeChild(rowEl);
            }
        }

        const remaining = currentSmartMealData.items.filter(it => !it.isExcluded);
        if (remaining.length === 0) {
            if (ninjaBreakdown) ninjaBreakdown.style.display = 'none';
            applyMacrosToForm(0, 0, 0, 0, '');
        } else {
            recalculateBreakdownTotalsAndSync(false);
        }
    };

    function recalculateBreakdownTotalsAndSync(isInitial = false) {
        if (!currentSmartMealData) return;
        let totCals = 0, totPro = 0, totCarbs = 0, totFat = 0;

        currentSmartMealData.items.forEach(it => {
            if (!it.isExcluded) {
                totCals += (parseFloat(it.currentCals) || 0);
                totPro += (parseFloat(it.currentPro) || 0);
                totCarbs += (parseFloat(it.currentCarbs) || 0);
                totFat += (parseFloat(it.currentFat) || 0);
            }
        });

        totCals = Math.round(totCals);
        totPro = Math.round(totPro * 10) / 10;
        totCarbs = Math.round(totCarbs * 10) / 10;
        totFat = Math.round(totFat * 10) / 10;

        if (ninjaTotalsEl) {
            ninjaTotalsEl.innerHTML = `
                <div class="smart-total-chip">
                    <span class="chip-label">Calories</span>
                    <span class="chip-value">${totCals} kcal</span>
                </div>
                <div class="smart-total-chip">
                    <span class="chip-label">Protein</span>
                    <span class="chip-value">${totPro}g</span>
                </div>
                <div class="smart-total-chip">
                    <span class="chip-label">Carbs</span>
                    <span class="chip-value">${totCarbs}g</span>
                </div>
                <div class="smart-total-chip">
                    <span class="chip-label">Fat</span>
                    <span class="chip-value">${totFat}g</span>
                </div>
            `;
        }

        if (isInitial) {
            applyMacrosToForm(totCals, totPro, totCarbs, totFat, currentSmartMealData.label);
        } else {
            if (inCals)  inCals.value  = totCals;
            if (inPro)   inPro.value   = totPro;
            if (inCarbs) inCarbs.value = totCarbs;
            if (inFat)   inFat.value   = totFat;
        }
    }

    window.setQuickMealText = function(text) {
        if (window.innerWidth <= 768 && typeof window.toggleMobileMealLogger === 'function') {
            window.toggleMobileMealLogger(true);
        }
        if (!ninjaInput) return;
        ninjaInput.value = text;
        const norm = text.trim().toLowerCase().replace(/\s+/g, ' ');
        if (clientMealCache.has(norm)) {
            renderMealBreakdownAndApply(clientMealCache.get(norm), text);
            return;
        }
        analyzeMealText();
    };

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function applyMacrosToForm(cals, pro, carbs, fat, foodName = '') {
        if (window.innerWidth <= 768 && typeof window.toggleMobileMealLogger === 'function') {
            window.toggleMobileMealLogger(true);
        }
        if (inCals)  inCals.value  = Math.round(parseFloat(cals) || 0);
        if (inPro)   inPro.value   = Math.round((parseFloat(pro) || 0) * 10) / 10;
        if (inCarbs) inCarbs.value = Math.round((parseFloat(carbs) || 0) * 10) / 10;
        if (inFat)   inFat.value   = Math.round((parseFloat(fat) || 0) * 10) / 10;

        // Visual flash pulse on inputs
        [inCals, inPro, inCarbs, inFat].forEach(inp => {
            if (!inp) return;
            inp.classList.remove('field-highlight-flash');
            void inp.offsetWidth; // trigger reflow
            inp.classList.add('field-highlight-flash');
        });

        // Toast notification (sleek corner toast, compact font, zero modal blocking)
        const label = foodName ? escapeHtml(foodName) : 'Meal';
        if (window.Swal) {
            Swal.fire({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 2200,
                timerProgressBar: false,
                icon: 'success',
                title: `Applied ${label} (${Math.round(cals)} kcal)`
            });
        }

        // Scroll to form fields
        if (form && typeof form.scrollIntoView === 'function') {
            form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    window.clearSmartMealAssistant = function() {
        currentSmartMealData = null;
        // 1. Clear search input
        if (ninjaInput) ninjaInput.value = '';

        // 2. Hide and clear breakdown containers
        if (ninjaBreakdown) ninjaBreakdown.style.display = 'none';
        if (ninjaItemsEl) ninjaItemsEl.innerHTML = '';
        if (ninjaTotalsEl) ninjaTotalsEl.innerHTML = '';

        // 3. Clear bottom macro inputs
        if (inCals)  inCals.value  = '';
        if (inPro)   inPro.value   = '';
        if (inCarbs) inCarbs.value = '';
        if (inFat)   inFat.value   = '';

        // 4. Remove flash highlights
        [inCals, inPro, inCarbs, inFat].forEach(inp => {
            if (inp) {
                inp.classList.remove('field-highlight-flash');
                inp.style.background = '';
            }
        });

        // 5. Refocus input
        ninjaInput?.focus();

        // 6. Toast notification
        if (window.Swal) {
            Swal.fire({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 1600,
                timerProgressBar: false,
                icon: 'info',
                title: 'Cleared search & inputs'
            });
        }
    };

    async function analyzeMealText() {
        const text = ninjaInput?.value?.trim();
        if (!text) {
            ninjaInput?.focus();
            return;
        }

        const norm = text.toLowerCase().replace(/\s+/g, ' ');

        // Check instant client-side cache first (Zero API call)
        if (clientMealCache.has(norm)) {
            renderMealBreakdownAndApply(clientMealCache.get(norm), text);
            return;
        }

        // Prevent duplicate concurrent requests
        if (ninjaBtn && ninjaBtn.disabled) return;

        if (ninjaBtn) ninjaBtn.disabled = true;
        if (ninjaBtnTxt) ninjaBtnTxt.textContent = 'Analyzing...';

        try {
            const res = await fetch('index.php?page=food_lookup&action=calorieninjas&query=' + encodeURIComponent(text));
            const data = await res.json();

            if (!data || !data.success) {
                Swal.fire({
                    icon: res.status === 429 ? 'warning' : 'info',
                    title: res.status === 429 ? 'Rate Limit Reached' : 'Meal Not Found',
                    text: data?.error || 'Could not parse nutritional data for this query.',
                    background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                    color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff'
                });
                return;
            }

            // Save to client-side cache for instant re-use in this session
            clientMealCache.set(norm, data);

            renderMealBreakdownAndApply(data, text);
        } catch (err) {
            Swal.fire({
                icon: 'error',
                title: 'Lookup Error',
                text: 'Could not connect to nutrition service. Please try again.',
                background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff'
            });
        } finally {
            if (ninjaBtn) ninjaBtn.disabled = false;
            if (ninjaBtnTxt) ninjaBtnTxt.textContent = 'Analyze & Fill';
        }
    }

    if (ninjaBtn) {
        ninjaBtn.addEventListener('click', analyzeMealText);
    }
    if (ninjaInput) {
        ninjaInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                analyzeMealText();
            }
        });
    }

    // -------------------------------------------------------------
    // Image-to-Text Nutrition Scanner (Labels, Menus, Recipes)
    // -------------------------------------------------------------
    const scanBtn = document.getElementById('btn-scan-meal-photo');
    const scanBtnTxt = document.getElementById('btn-scan-meal-photo-txt');
    const photoFileInput = document.getElementById('meal-photo-file-input');

    window.triggerMealPhotoScan = function() {
        if (!photoFileInput) return;
        photoFileInput.value = '';
        photoFileInput.click();
    };

    window.handleMealPhotoSelected = async function(event) {
        const file = event?.target?.files?.[0];
        if (!file) return;

        // Size limit: 8MB
        if (file.size > 8 * 1024 * 1024) {
            Swal.fire({
                icon: 'warning',
                title: 'File Too Large',
                text: 'Please choose an image under 8MB.',
                confirmButtonColor: '#10b981',
                background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff'
            });
            return;
        }

        if (scanBtn) scanBtn.disabled = true;
        if (scanBtnTxt) scanBtnTxt.textContent = 'Scanning...';

        if (window.Swal) {
            Swal.fire({
                title: 'Reading Meal Text...',
                html: '<div style="font-size:13.5px; color:var(--muted); margin-top:4px;">Scanning for nutrition facts, menus, or recipe text...</div>',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                },
                background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff'
            });
        }

        try {
            const formData = new FormData();
            formData.append('image', file);

            const res = await fetch('index.php?page=food_lookup&action=scan_image_nutrition', {
                method: 'POST',
                body: formData
            });

            const data = await res.json();
            if (window.Swal) Swal.close();

            if (!data || !data.success) {
                if (data && data.empty_text) {
                    Swal.fire({
                        icon: 'info',
                        title: 'No Readable Meal Text Found',
                        html: `
                            <div style="text-align: left; font-size: 13.5px; line-height: 1.55;">
                                <p style="margin-bottom: 10px;">We could not detect any readable food names or nutrition facts in this photo.</p>
                                <div style="background: rgba(255,255,255,0.06); padding: 12px 14px; border-radius: 9px; border-left: 3px solid #10b981;">
                                    <strong style="color: #10b981;">What this scanner works on:</strong>
                                    <ul style="margin: 6px 0 0 18px; padding: 0;">
                                        <li>Nutrition facts labels on food packaging</li>
                                        <li>Printed restaurant menus or receipts</li>
                                        <li>Written meal logs or recipe cards</li>
                                    </ul>
                                </div>
                                <p style="margin-top: 10px; color: var(--muted, #8792ad); font-size: 12px;">
                                    <em>Note: It does not recognize or estimate food directly from a plate without text.</em>
                                </p>
                            </div>
                        `,
                        confirmButtonText: 'Got It',
                        confirmButtonColor: '#10b981',
                        background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                        color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff'
                    });
                } else {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Scan Error',
                        text: data?.error || 'Could not analyze this image. Please try again.',
                        confirmButtonColor: '#10b981',
                        background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                        color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff'
                    });
                }
                return;
            }

            // Extract success: populate search input & smart breakdown
            const scannedSummary = data.query || 'Scanned Nutrition Label';
            if (ninjaInput) ninjaInput.value = scannedSummary;

            renderMealBreakdownAndApply(data, scannedSummary);

            if (window.Swal) {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 2800,
                    icon: 'success',
                    title: `Extracted ${data.items.length} items (${data.total_calories} kcal)`
                });
            }
        } catch (err) {
            if (window.Swal) Swal.close();
            Swal.fire({
                icon: 'error',
                title: 'Scan Failed',
                text: 'Network error analyzing image. Please try again.',
                confirmButtonColor: '#10b981',
                background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff'
            });
        } finally {
            if (scanBtn) scanBtn.disabled = false;
            if (scanBtnTxt) scanBtnTxt.textContent = 'Scan Label / Menu';
            if (photoFileInput) photoFileInput.value = '';
        }
    };

    // Delegated event listeners for portion & unit controls inside Smart Breakdown items
    if (ninjaItemsEl) {
        ninjaItemsEl.addEventListener('input', function(e) {
            if (!e.target.classList.contains('breakdown-qty-input')) return;
            const idx = parseInt(e.target.dataset.idx, 10);
            onBreakdownQtyInput(idx, e.target.value);
        });

        ninjaItemsEl.addEventListener('change', function(e) {
            if (!e.target.classList.contains('breakdown-unit-select')) return;
            const idx = parseInt(e.target.dataset.idx, 10);
            onBreakdownUnitChange(idx, e.target.value);
        });
    }



    // Helper: auto calculate calories from macros: (P * 4) + (C * 4) + (F * 9)
    function calcCaloriesFromMacros() {
        const p = parseFloat(inPro?.value || 0) || 0;
        const c = parseFloat(inCarbs?.value || 0) || 0;
        const f = parseFloat(inFat?.value || 0) || 0;
        return Math.round((p * 4) + (c * 4) + (f * 9));
    }

    if (btnAutoCal) {
        btnAutoCal.addEventListener('click', function() {
            const calculated = calcCaloriesFromMacros();
            if (calculated > 0 && inCals) {
                inCals.value = calculated;
                inCals.style.transition = 'background 0.3s ease';
                inCals.style.background = 'color-mix(in srgb, var(--lime, #84cc16) 25%, transparent)';
                setTimeout(() => { inCals.style.background = ''; }, 400);
            }
        });
    }

    function shootConfetti(particleCount = 70) {
        if (typeof confetti === 'function') {
            try {
                confetti({
                    particleCount: particleCount,
                    spread: 70,
                    origin: { y: 0.6 }
                });
            } catch (e) {}
        }
    }

    function fireCelebrationModal({
        themeColor = 'var(--lime, #84cc16)',
        themeGlow,
        badgeText,
        iconSvg,
        title,
        metricLabel,
        loggedVal,
        targetVal,
        description,
        btnText = 'Got it, continue!',
        btnBg = 'var(--lime, #84cc16)',
        isAll = false,
        allStats = null
    }) {
        const effectiveGlow = themeGlow || `color-mix(in srgb, ${themeColor} 45%, transparent)`;

        let statsCardHtml = '';
        if (isAll && allStats) {
            statsCardHtml = `
                <div class="celebration-card-wrap" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 14px 10px; margin: 16px 0; display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;">
                    <div class="celebration-mini-stat" style="background: rgba(0,0,0,0.3); border: 1px solid color-mix(in srgb, var(--macro-cals) 25%, transparent); border-radius: 8px; padding: 8px 4px; text-align:center;">
                        <div style="font-size: 10px; font-weight: 700; color: var(--macro-cals); text-transform: uppercase;">Calories</div>
                        <div class="mini-val" style="font-size: 14px; font-weight: 800; color: #fff; margin: 3px 0;">${allStats.cals}</div>
                        <div style="font-size: 10px; color: #22c55e; font-weight: 700;">Target Met</div>
                    </div>
                    <div class="celebration-mini-stat" style="background: rgba(0,0,0,0.3); border: 1px solid color-mix(in srgb, var(--macro-pro) 25%, transparent); border-radius: 8px; padding: 8px 4px; text-align:center;">
                        <div style="font-size: 10px; font-weight: 700; color: var(--macro-pro); text-transform: uppercase;">Protein</div>
                        <div class="mini-val" style="font-size: 14px; font-weight: 800; color: #fff; margin: 3px 0;">${allStats.pro}g</div>
                        <div style="font-size: 10px; color: #22c55e; font-weight: 700;">Target Met</div>
                    </div>
                    <div class="celebration-mini-stat" style="background: rgba(0,0,0,0.3); border: 1px solid color-mix(in srgb, var(--macro-carbs) 25%, transparent); border-radius: 8px; padding: 8px 4px; text-align:center;">
                        <div style="font-size: 10px; font-weight: 700; color: var(--macro-carbs); text-transform: uppercase;">Carbs</div>
                        <div class="mini-val" style="font-size: 14px; font-weight: 800; color: #fff; margin: 3px 0;">${allStats.carbs}g</div>
                        <div style="font-size: 10px; color: #22c55e; font-weight: 700;">Target Met</div>
                    </div>
                    <div class="celebration-mini-stat" style="background: rgba(0,0,0,0.3); border: 1px solid color-mix(in srgb, var(--macro-fat) 25%, transparent); border-radius: 8px; padding: 8px 4px; text-align:center;">
                        <div style="font-size: 10px; font-weight: 700; color: var(--macro-fat); text-transform: uppercase;">Fat</div>
                        <div class="mini-val" style="font-size: 14px; font-weight: 800; color: #fff; margin: 3px 0;">${allStats.fat}g</div>
                        <div style="font-size: 10px; color: #22c55e; font-weight: 700;">Target Met</div>
                    </div>
                </div>`;
        } else {
            statsCardHtml = `
                <div class="celebration-card-wrap" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 14px 18px; margin: 16px 0; text-align: left;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <span style="font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px;">${metricLabel} Goal</span>
                        <span style="font-size: 11px; font-weight: 800; color: #22c55e; background: rgba(34,197,94,0.12); padding: 2px 8px; border-radius: 12px; border: 1px solid rgba(34,197,94,0.3); display: inline-flex; align-items: center; gap: 4px;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            Target Met
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px;">
                        <div>
                            <span style="font-size: 26px; font-weight: 900; color: ${themeColor};">${loggedVal}</span>
                            <span style="font-size: 12px; color: var(--muted); margin-left: 4px;">logged</span>
                        </div>
                        <span style="font-size: 13px; color: var(--muted); font-weight: 500;">Goal: <strong style="color:var(--ink);">${targetVal}</strong></span>
                    </div>
                    <div class="celebration-track" style="height: 6px; background: rgba(255,255,255,0.08); border-radius: 3px; overflow: hidden;">
                        <div style="height: 100%; width: 100%; background: #22c55e; border-radius: 3px;"></div>
                    </div>
                </div>`;
        }

        const modalHtml = `
            <div style="text-align: center; padding: 6px 4px; --celeb-glow: ${effectiveGlow};">
                <!-- Glowing Hero Icon -->
                <div class="celebration-hero-pulse" style="--celeb-glow: ${effectiveGlow}; width: 76px; height: 76px; margin: 0 auto 16px; border-radius: 50%; background: radial-gradient(circle, ${effectiveGlow} 0%, rgba(12,16,22,0.92) 100%); border: 2px solid ${themeColor}; display: flex; align-items: center; justify-content: center; box-shadow: 0 0 38px ${effectiveGlow};">
                    ${iconSvg}
                </div>

                <!-- Milestone Tag -->
                <div style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; background: color-mix(in srgb, ${themeColor} 12%, transparent); border: 1px solid color-mix(in srgb, ${themeColor} 38%, transparent); border-radius: 20px; font-size: 11px; font-weight: 800; color: ${themeColor}; letter-spacing: 0.8px; text-transform: uppercase; margin-bottom: 10px;">
                    <span style="width: 6px; height: 6px; border-radius: 50%; background: ${themeColor};"></span>
                    ${badgeText}
                </div>

                <!-- Title -->
                <h2 class="celebration-title">
                    ${title}
                </h2>

                <!-- Metric Breakdown -->
                ${statsCardHtml}

                <!-- Tip / Description -->
                <p class="celebration-desc">
                    ${description}
                </p>

                <!-- Action Buttons: Clear Affirmative + Explicit Close -->
                <div style="display: flex; justify-content: center; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 4px;">
                    <button type="button" class="celebration-action-btn celebration-close-trigger" style="--celeb-glow: ${effectiveGlow}; background: ${btnBg}; color: var(--lime-contrast, #090b10); font-weight: 800; font-size: 13.5px; border: none; padding: 11px 24px; border-radius: 9px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 8px 24px ${effectiveGlow};">
                        <span>${btnText || 'Got it, continue!'}</span>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    </button>
                    <button type="button" class="celebration-secondary-btn celebration-close-trigger" style="background: transparent; color: var(--muted); font-weight: 600; font-size: 13px; border: 1px solid var(--line); padding: 10px 18px; border-radius: 9px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                        Close
                    </button>
                </div>
                <div style="font-size: 11.5px; color: var(--muted); margin-top: 13px;">
                    This milestone has been recorded in today's daily log.
                </div>
            </div>
        `;

        Swal.fire({
            html: modalHtml,
            showConfirmButton: false,
            showCloseButton: true,
            allowOutsideClick: true,
            allowEscapeKey: true,
            background: 'transparent',
            color: '#ffffff',
            width: 460,
            padding: '24px 20px 20px',
            customClass: {
                popup: 'diet-celebration-popup'
            },
            didOpen: (popup) => {
                const dismissModal = (e) => {
                    if (e) {
                        e.preventDefault();
                    }
                    Swal.close();
                    // Fallback to guarantee backdrop and popup cleanup in case of animation delay
                    setTimeout(() => {
                        const activeContainer = document.querySelector('.swal2-container');
                        if (activeContainer && activeContainer.querySelector('.diet-celebration-popup')) {
                            activeContainer.remove();
                            document.body.classList.remove('swal2-shown', 'swal2-height-auto');
                        }
                    }, 240);
                };

                // Ensure top-right 'X' button always dismisses reliably
                const closeBtn = popup.querySelector('.swal2-close');
                if (closeBtn) {
                    closeBtn.setAttribute('title', 'Close dialog');
                    closeBtn.onclick = dismissModal;
                }
                // Ensure all close buttons dismiss reliably
                popup.querySelectorAll('.celebration-close-trigger').forEach(function(btn) {
                    btn.onclick = dismissModal;
                });
            }
        });
    }

    // Reset button handler
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            Swal.fire({
                title: 'Reset Today\'s Log?',
                text: 'This will reset your logged calories, protein, carbs, and fat for today back to 0.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: 'transparent',
                confirmButtonText: 'Yes, Reset Today',
                cancelButtonText: 'Cancel',
                background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
            }).then(async (result) => {
                if (result.isConfirmed) {
                    await submitMacroLog('reset');
                }
            });
        });
    }

    window.applyMacroLogResultToUI = function(data, isManual = false, activeMode = 'add') {
        if (!data || !data.success) return;

        // If user reset today's macros, reset meal slot cards too
        if (activeMode === 'reset') {
            if (typeof resetTodayMealCardsUI === 'function') {
                resetTodayMealCardsUI();
            }
        }

        const prevPro   = parseFloat(proDisp?.textContent   || 0) || 0;
        const prevCals  = parseFloat(calsDisp?.textContent  || 0) || 0;
        const prevCarbs = parseFloat(carbsDisp?.textContent || 0) || 0;
        const prevFat   = parseFloat(fatDisp?.textContent   || 0) || 0;

        const newCals  = data.logged_cals;
        const newPro   = data.logged_pro;
        const newCarbs = data.logged_carbs;
        const newFat   = data.logged_fat;

        const targetCals  = data.target_cals;
        const targetPro   = data.target_pro;
        const targetCarbs = data.target_carbs;
        const targetFat   = data.target_fat;

        function formatStatus(logged, target, type) {
            if (target <= 0) {
                return { text: '0%', color: 'var(--muted)', width: '0%', barBg: `var(--macro-${type})` };
            }
            const diff = logged - target;
            const pct = Math.round((logged / target) * 100);

            if (diff > 0) {
                if (type === 'pro') {
                    return { text: `+${diff}g Over`, color: '#22c55e', width: '100%', barBg: '#22c55e' };
                } else if (type === 'cals') {
                    return { text: `+${diff} kcal Over`, color: '#f59e0b', width: '100%', barBg: '#f59e0b' };
                } else if (type === 'carbs') {
                    return { text: `+${diff}g Over`, color: '#f59e0b', width: '100%', barBg: '#f59e0b' };
                } else { // fat
                    return { text: `+${diff}g Over`, color: '#f43f5e', width: '100%', barBg: '#f43f5e' };
                }
            } else if (diff === 0) {
                return { text: '100% ✓ Met', color: '#22c55e', width: '100%', barBg: '#22c55e' };
            } else {
                return { text: `${pct}%`, color: 'var(--muted)', width: `${Math.min(100, pct)}%`, barBg: `var(--macro-${type})` };
            }
        }

        const calsProg  = formatStatus(newCals, targetCals, 'cals');
        const proProg   = formatStatus(newPro, targetPro, 'pro');
        const carbsProg = formatStatus(newCarbs, targetCarbs, 'carbs');
        const fatProg   = formatStatus(newFat, targetFat, 'fat');

        // Calories update
        if (calsDisp) calsDisp.textContent = newCals;
        if (tarDisp && targetCals) tarDisp.textContent = targetCals;
        if (pctCals) {
            pctCals.textContent = calsProg.text;
            pctCals.style.color = calsProg.color;
        }
        if (barCals) {
            barCals.style.width = calsProg.width;
            barCals.style.background = calsProg.barBg;
        }

        // Hero Remaining Number & Radial Ring update
        const heroRemainingVal = document.getElementById('hero-cals-remaining-val');
        const heroRemainingLbl = document.getElementById('hero-cals-remaining-lbl');
        if (heroRemainingVal && targetCals) {
            const rem = targetCals - newCals;
            heroRemainingVal.textContent = Math.abs(rem);
            if (heroRemainingLbl) {
                heroRemainingLbl.textContent = rem >= 0 ? 'kcal remaining' : 'kcal over target';
            }
        }
        const ringBar = document.getElementById('hero-radial-ring-bar');
        if (ringBar && targetCals > 0) {
            const rawPct = Math.min(100, Math.max(0, (newCals / targetCals) * 100));
            const ringOffset = 175.93 * (1 - (rawPct / 100));
            ringBar.style.strokeDashoffset = ringOffset;
            ringBar.style.stroke = calsProg.color;
        }

        // Protein update
        if (proDisp) proDisp.textContent = newPro;
        if (tarPro && targetPro) tarPro.textContent = targetPro;
        if (pctPro) {
            pctPro.textContent = proProg.text;
            pctPro.style.color = proProg.color;
        }
        if (barPro) {
            barPro.style.width = proProg.width;
            barPro.style.background = proProg.barBg;
        }

        // Carbs update
        if (carbsDisp) carbsDisp.textContent = newCarbs;
        if (tarCarbs && targetCarbs) tarCarbs.textContent = targetCarbs;
        if (pctCarbs) {
            pctCarbs.textContent = carbsProg.text;
            pctCarbs.style.color = carbsProg.color;
        }
        if (barCarbs) {
            barCarbs.style.width = carbsProg.width;
            barCarbs.style.background = carbsProg.barBg;
        }

        // Fat update
        if (fatDisp) fatDisp.textContent = newFat;
        if (tarFat && targetFat) tarFat.textContent = targetFat;
        if (pctFat) {
            pctFat.textContent = fatProg.text;
            pctFat.style.color = fatProg.color;
        }
        if (barFat) {
            barFat.style.width = fatProg.width;
            barFat.style.background = fatProg.barBg;
        }

        // Update top tab navigation badge
        const trackerBadge = document.querySelector('.diet-nav-badge.tracker-badge');
        if (trackerBadge && targetCals) {
            trackerBadge.textContent = `${newCals} / ${targetCals} kcal`;
        }

        // Detect newly reached goals
        const reachedPro   = (prevPro < targetPro) && (newPro >= targetPro) && (targetPro > 0);
        const reachedCals  = (prevCals < targetCals) && (newCals >= targetCals) && (targetCals > 0);
        const reachedCarbs = (prevCarbs < targetCarbs) && (newCarbs >= targetCarbs) && (targetCarbs > 0);
        const reachedFat   = (prevFat < targetFat) && (newFat >= targetFat) && (targetFat > 0);

        const allNowMet  = (newCals >= targetCals && newPro >= targetPro && newCarbs >= targetCarbs && newFat >= targetFat) && (targetCals > 0 && targetPro > 0);
        const prevAllMet = (prevCals >= targetCals && prevPro >= targetPro && prevCarbs >= targetCarbs && prevFat >= targetFat);
        const reachedAll = allNowMet && !prevAllMet;

        const bgVal  = getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721';
        const inkVal = getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff';

        if (reachedAll) {
            shootConfetti(120);
            setTimeout(() => {
                fireCelebrationModal({
                    themeColor: 'var(--lime, #84cc16)',
                    themeGlow: 'color-mix(in srgb, var(--lime, #84cc16) 45%, transparent)',
                    badgeText: 'DAILY TARGETS COMPLETED',
                    iconSvg: '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="var(--lime, #84cc16)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"></path><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"></path><path d="M4 22h16"></path><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"></path><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"></path><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"></path></svg>',
                    title: 'Perfect Macro Day!',
                    description: 'Incredible dedication! You have successfully reached every single nutritional target scheduled for today.',
                    btnText: 'Got it, continue!',
                    btnBg: 'var(--lime, #84cc16)',
                    isAll: true,
                    allStats: { cals: newCals, pro: newPro, carbs: newCarbs, fat: newFat }
                });
            }, 250);
        } else if (reachedPro) {
            shootConfetti(80);
            setTimeout(() => {
                fireCelebrationModal({
                    themeColor: 'var(--macro-pro, #38bdf8)',
                    themeGlow: 'color-mix(in srgb, var(--macro-pro, #38bdf8) 45%, transparent)',
                    badgeText: 'PROTEIN GOAL ACHIEVED',
                    iconSvg: '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="var(--macro-pro, #38bdf8)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6.5 6.5 11 11"/><path d="m21 21-1-1"/><path d="m3 3 1 1"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/><path d="m3 10 7-7"/><path d="m14 21 7-7"/></svg>',
                    title: 'Protein Target Crushed!',
                    metricLabel: 'Protein',
                    loggedVal: newPro + 'g',
                    targetVal: targetPro + 'g',
                    description: 'Hitting your daily protein goal accelerates muscle recovery, preserves lean tissue, and sustains your strength.',
                    btnText: 'Got it, continue!',
                    btnBg: 'var(--macro-pro, #38bdf8)'
                });
            }, 250);
        } else if (reachedCals) {
            shootConfetti(70);
            setTimeout(() => {
                fireCelebrationModal({
                    themeColor: 'var(--lime, #84cc16)',
                    themeGlow: 'color-mix(in srgb, var(--lime, #84cc16) 45%, transparent)',
                    badgeText: 'CALORIE GOAL ACHIEVED',
                    iconSvg: '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="var(--lime, #84cc16)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
                    title: 'Calorie Target Reached!',
                    metricLabel: 'Calories',
                    loggedVal: newCals + ' kcal',
                    targetVal: targetCals + ' kcal',
                    description: 'You reached your planned daily energy intake, keeping your nutrition perfectly aligned with your fitness goal.',
                    btnText: 'Got it, continue!',
                    btnBg: 'var(--lime, #84cc16)'
                });
            }, 250);
        } else if (reachedCarbs) {
            shootConfetti(60);
            setTimeout(() => {
                fireCelebrationModal({
                    themeColor: 'var(--macro-carbs, #fbbf24)',
                    themeGlow: 'color-mix(in srgb, var(--macro-carbs, #fbbf24) 45%, transparent)',
                    badgeText: 'CARBOHYDRATES TARGET MET',
                    iconSvg: '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="var(--macro-carbs, #fbbf24)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>',
                    title: 'Carbs Target Met!',
                    metricLabel: 'Carbs',
                    loggedVal: newCarbs + 'g',
                    targetVal: targetCarbs + 'g',
                    description: 'Your glycogen stores are refueled and ready to power your next training session.',
                    btnText: 'Got it, continue!',
                    btnBg: 'var(--macro-carbs, #fbbf24)'
                });
            }, 250);
        } else if (reachedFat) {
            shootConfetti(60);
            setTimeout(() => {
                fireCelebrationModal({
                    themeColor: 'var(--macro-fat, #f43f5e)',
                    themeGlow: 'color-mix(in srgb, var(--macro-fat, #f43f5e) 45%, transparent)',
                    badgeText: 'HEALTHY FATS TARGET MET',
                    iconSvg: '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="var(--macro-fat, #f43f5e)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><path d="m9 12 2 2 4-4"></path></svg>',
                    title: 'Healthy Fat Target Met!',
                    metricLabel: 'Fat',
                    loggedVal: newFat + 'g',
                    targetVal: targetFat + 'g',
                    description: 'Healthy fats optimize hormone balance, joint health, and steady long-term energy.',
                    btnText: 'Got it, continue!',
                    btnBg: 'var(--macro-fat, #f43f5e)'
                });
            }, 250);
        } else if (isManual) {
            // Standard Toast feedback with clean text, no emojis
            const Toast = Swal.mixin({
                toast: true, position: 'top-end', showConfirmButton: false,
                timer: 3000, timerProgressBar: true,
                background: bgVal,
                color: inkVal,
            });

            let toastMsg = 'Macros saved for today.';
            if (activeMode === 'add') {
                toastMsg = `Added to today's log. Total: <strong>${data.logged_cals} kcal</strong>`;
            } else if (activeMode === 'reset') {
                toastMsg = 'Today\'s log reset to 0.';
            } else {
                toastMsg = `Today's totals updated. Total: <strong>${data.logged_cals} kcal</strong>`;
            }

            Toast.fire({ icon: 'success', title: toastMsg });
        }
    };

    async function submitMacroLog(forcedMode = null) {
        saveBtn.disabled = true;
        saveBtn.style.opacity = '0.7';

        const activeMode = forcedMode || modeInput?.value || 'add';

        // Validate that user is not submitting negative numbers
        const valCals  = parseFloat(inCals?.value  || 0);
        const valPro   = parseFloat(inPro?.value   || 0);
        const valCarbs = parseFloat(inCarbs?.value || 0);
        const valFat   = parseFloat(inFat?.value   || 0);

        if (activeMode !== 'reset' && (valCals < 0 || valPro < 0 || valCarbs < 0 || valFat < 0)) {
            const bgVal  = getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721';
            const inkVal = getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff';
            Swal.fire({
                icon: 'warning',
                title: 'Positive Numbers Only',
                text: 'Calories and macronutrients cannot be negative. Please enter 0 or a positive value.',
                background: bgVal,
                color: inkVal,
                confirmButtonColor: 'var(--lime, #c7ff22)',
                confirmButtonText: 'Understood'
            });
            saveBtn.disabled = false;
            saveBtn.style.opacity = '1';
            return;
        }

        // In 'add' mode, auto-fill calories if left empty but macros were entered
        if (activeMode === 'add' && (!inCals.value || inCals.value === '0') && (inPro.value || inCarbs.value || inFat.value)) {
            inCals.value = calcCaloriesFromMacros();
        }

        const body = new URLSearchParams({
            mode:       activeMode,
            calories:   activeMode === 'reset' ? '0' : (inCals?.value  || '0'),
            protein_g:  activeMode === 'reset' ? '0' : (inPro?.value   || '0'),
            carbs_g:    activeMode === 'reset' ? '0' : (inCarbs?.value || '0'),
            fat_g:      activeMode === 'reset' ? '0' : (inFat?.value   || '0'),
            csrf_token: csrf
        });

        let data = null;
        try {
            const res = await fetch('index.php?page=log_macros', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: body.toString()
            });
            const text = await res.text();
            try {
                data = JSON.parse(text);
            } catch (jsonErr) {
                console.error("Non-JSON response from log_macros:", text);
                data = { success: false, error: 'Server response error. Please try again.' };
            }
        } catch (err) {
            const bgVal  = getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721';
            const inkVal = getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff';
            Swal.fire({
                icon: 'error',
                title: 'Connection Error',
                text: 'Could not reach the server. Please check your network connection.',
                background: bgVal,
                color: inkVal,
                confirmButtonColor: 'var(--lime, #c7ff22)',
                confirmButtonText: 'OK'
            });
            saveBtn.disabled = false;
            saveBtn.style.opacity = '1';
            return;
        }

        if (data && data.success) {
            window.applyMacroLogResultToUI(data, true, activeMode);

            // In 'add' or 'reset' mode, clear inputs ready for the next food entry
            if (activeMode === 'add' || activeMode === 'reset') {
                inCals.value = '';
                inPro.value = '';
                inCarbs.value = '';
                inFat.value = '';
            }
        } else {
            const bgVal  = getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721';
            const inkVal = getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff';
            const msg = (data && data.error) ? data.error : 'Could not save macros. Please try again.';
            Swal.fire({
                icon: 'warning',
                title: 'Unable to Save',
                text: msg,
                background: bgVal,
                color: inkVal,
                confirmButtonColor: 'var(--lime, #c7ff22)',
                confirmButtonText: 'OK'
            });
        }

        saveBtn.disabled = false;
        saveBtn.style.opacity = '1';
    }

    // Input sanitization: block minus / negative typing & pasting on macro fields
    [inCals, inPro, inCarbs, inFat].forEach(inp => {
        if (!inp) return;
        inp.addEventListener('keydown', function(e) {
            if (e.key === '-' || e.key === 'Subtract') {
                e.preventDefault();
            }
        });
        inp.addEventListener('input', function() {
            if (this.value !== '' && parseFloat(this.value) < 0) {
                this.value = '0';
            }
        });
        inp.addEventListener('paste', function(e) {
            const text = (e.clipboardData || window.clipboardData)?.getData('text');
            if (text && text.includes('-')) {
                e.preventDefault();
                const sanitized = text.replace(/[^0-9.]/g, '');
                this.value = sanitized;
            }
        });
    });


    form.addEventListener('submit', function(e) {
        e.preventDefault();
        submitMacroLog();
    });
})();


/* ====================================================
   SECTION 3: Modals, Plan Inspector & View Switcher
   ==================================================== */

// State management for modals
let currentInspectingMeal = null;
let currentSwappingMeal = null;
let swapOptionsData = [];
let activeSwapFilter = 'all';
let calculatedCustomMealData = null;



// Switch weekly meal plan day tab
function switchDietTab(dayNum) {
    document.querySelectorAll('.diet-tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.diet-day-tab').forEach(btn => btn.classList.remove('active'));
    
    const targetContent = document.getElementById('diet-tab-' + dayNum);
    if (targetContent) targetContent.style.display = 'block';
    
    const activeBtn = document.getElementById('diet-day-btn-' + dayNum);
    if (activeBtn) {
        activeBtn.classList.add('active');
    }
}

// Top View Switcher (Today's Macro Tracker vs Weekly Meal Plan)
function switchDietView(view) {
    const btnTracker  = document.getElementById('btn-view-tracker');
    const btnPlan     = document.getElementById('btn-view-plan');
    const viewTracker = document.getElementById('view-macro-tracker');
    const viewPlan    = document.getElementById('view-meal-plan');

    if (view === 'plan') {
        btnPlan?.classList.add('active');
        btnTracker?.classList.remove('active');
        if (viewPlan) viewPlan.style.display = 'block';
        if (viewTracker) viewTracker.style.display = 'none';
        try { localStorage.setItem('fittracks_diet_active_tab', 'plan'); } catch (e) {}
    } else {
        btnTracker?.classList.add('active');
        btnPlan?.classList.remove('active');
        if (viewTracker) viewTracker.style.display = 'block';
        if (viewPlan) viewPlan.style.display = 'none';
        try { localStorage.setItem('fittracks_diet_active_tab', 'tracker'); } catch (e) {}
    }
}

// ----------------------------------------------------
// FEATURE 1: ONE-CLICK QUICK LOG PLANNED MEAL (WITH GUARD RAILS)
// ----------------------------------------------------
function normalizeMealType(rawType) {
    let t = (rawType || 'Meal').trim();
    if (t.toLowerCase().includes('snack')) return 'Snack';
    return t.charAt(0).toUpperCase() + t.slice(1).toLowerCase();
}

function handleAlreadyLoggedClick(mealType, timeStr) {
    const timeTxt = timeStr ? ` at <strong>${timeStr}</strong>` : ' earlier today';
    Swal.fire({
        icon: 'info',
        title: `${mealType} already logged today`,
        html: `<p style="margin:0 0 10px 0; font-size:14px; color:var(--ink);">${mealType} was recorded${timeTxt}.</p><p style="margin:0; font-size:13px; color:var(--muted);">You can log ${mealType.toLowerCase()} again tomorrow.</p>`,
        background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
        color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
        confirmButtonColor: 'var(--lime, #c7ff22)',
        confirmButtonText: 'Understood'
    });
}

function handleViewOnlyClick(dayName) {
    const dName = dayName || 'another day';
    Swal.fire({
        icon: 'info',
        title: `Scheduled for ${dName}`,
        html: `<p style="margin:0 0 10px 0; font-size:14px; color:var(--ink);">This meal is part of your meal plan for <strong>${dName}</strong>.</p><p style="margin:0; font-size:13px; color:var(--muted);">You can log it when ${dName} arrives, or inspect its ingredients and swap recipes anytime!</p>`,
        background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
        color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
        confirmButtonColor: 'var(--lime, #c7ff22)',
        confirmButtonText: 'Got It'
    });
}

function markMealAsLoggedUI(mealId, normType, timeStr) {
    const timeDisplay = timeStr || 'Today';
    
    // Update all cards matching this meal type for today
    const cards = document.querySelectorAll(`.meal-card[data-day="${window.FT_CURRENT_DAY_NUM}"]`);
    cards.forEach(c => {
        const cType = normalizeMealType(c.dataset.type);
        if (cType === normType) {
            const mId = c.dataset.mealId;
            const cBtn = c.querySelector('.btn-log-meal');
            if (cBtn) {
                cBtn.disabled = false; // keep accessible so clicking displays the gentle "already logged" message
                cBtn.className = 'meal-action-btn btn-log-meal logged-completed';
                cBtn.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg> <span>Logged ✓</span>`;
                cBtn.title = `${normType} already logged today at ${timeDisplay}. Click for details.`;
                cBtn.onclick = function() { handleAlreadyLoggedClick(normType, timeDisplay); };
            }
            const checkEl = document.getElementById('meal-check-' + mId);
            if (checkEl) checkEl.style.display = 'inline-flex';

            const statusEl = document.getElementById('meal-status-pill-' + mId);
            if (statusEl) {
                statusEl.className = 'meal-status-pill pill-logged';
                statusEl.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg> <span class="status-txt">Logged today at ${timeDisplay}</span>`;
            }
        }
    });

    // Also update inspection modal button if open
    const inspectBtn = document.getElementById('btn-inspect-quick-log');
    if (inspectBtn && currentInspectingMeal && normalizeMealType(currentInspectingMeal.type) === normType) {
        inspectBtn.disabled = false;
        inspectBtn.style.opacity = '0.9';
        inspectBtn.style.cursor = 'pointer';
        inspectBtn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg> <span>${normType} Already Logged Today</span>`;
        inspectBtn.onclick = function() {
            closeInspectModal();
            handleAlreadyLoggedClick(normType, timeDisplay);
        };
    }
}

function resetTodayMealCardsUI() {
    window.FT_LOGGED_MEALS_TODAY = {};
    const cards = document.querySelectorAll(`.meal-card[data-day="${window.FT_CURRENT_DAY_NUM}"]`);
    cards.forEach(c => {
        const mId = c.dataset.mealId;
        const normType = normalizeMealType(c.dataset.type);
        const cBtn = c.querySelector('.btn-log-meal');
        if (cBtn) {
            cBtn.disabled = false;
            cBtn.className = 'meal-action-btn btn-log-meal';
            cBtn.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg> <span>+ Log Meal</span>`;
            cBtn.title = `Log your ${normType.toLowerCase()} to track your daily nutrition`;
            cBtn.onclick = function() { quickLogPlannedMeal(parseInt(mId, 10)); };
        }
        const checkEl = document.getElementById('meal-check-' + mId);
        if (checkEl) checkEl.style.display = 'none';

        const statusEl = document.getElementById('meal-status-pill-' + mId);
        if (statusEl) {
            statusEl.className = 'meal-status-pill pill-unlogged';
            statusEl.innerHTML = `<span class="meal-status-dot"></span> <span class="status-txt">No meal logged yet</span>`;
        }
    });
}

async function quickLogPlannedMeal(mealId, customTotals = null) {
    const card = document.getElementById('meal-card-' + mealId);
    if (!card) return;

    if (card._isLogging) return; // Prevent double clicks

    const rawType = card.dataset.type || 'Meal';
    const normType = normalizeMealType(rawType);
    const dayNum = parseInt(card.dataset.day || 0, 10);
    
    // If customTotals is provided (from selected ingredients), use those!
    const cals = customTotals && customTotals.cals !== undefined ? customTotals.cals : parseFloat(card.dataset.cals || 0);
    const pro = customTotals && customTotals.pro !== undefined ? customTotals.pro : parseFloat(card.dataset.pro || 0);
    const carbs = customTotals && customTotals.carbs !== undefined ? customTotals.carbs : parseFloat(card.dataset.carbs || 0);
    const fat = customTotals && customTotals.fat !== undefined ? customTotals.fat : parseFloat(card.dataset.fat || 0);
    const foodItems = customTotals && customTotals.names && customTotals.names.length > 0 
        ? customTotals.names.join(', ') 
        : (card.dataset.food || '');

    // Guard 1: Only today can be logged
    if (dayNum !== window.FT_CURRENT_DAY_NUM) {
        Swal.fire({
            icon: 'info',
            title: 'Only Today\'s Meals Can Be Logged',
            text: 'This meal is scheduled for another day. Daily meal logging is restricted to today only.',
            background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
            color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
            confirmButtonColor: 'var(--lime, #c7ff22)',
            confirmButtonText: 'Understood'
        });
        return;
    }

    // Guard 2: Already logged today
    if (window.FT_LOGGED_MEALS_TODAY && window.FT_LOGGED_MEALS_TODAY[normType]) {
        handleAlreadyLoggedClick(normType, window.FT_LOGGED_MEALS_TODAY[normType].time);
        return;
    }

    // Confirmation Alert before logging meal
    const mealNameDisplay = card.dataset.food || normType;
    const isCustomized = customTotals && customTotals.count !== undefined;
    const confirmResult = await Swal.fire({
        title: `Log ${normType}?`,
        html: `
            <div style="font-size: 13.5px; color: var(--muted); margin-bottom: 12px; line-height: 1.45;">
                Do you want to log <strong>${escapeHtml(mealNameDisplay)}</strong> to your nutrition tracker today?
                ${isCustomized ? `<div style="font-size: 12px; color: var(--lime); margin-top: 6px; font-weight: 600;">(${customTotals.count} ingredients selected)</div>` : ''}
            </div>
            <div style="display: flex; align-items: center; justify-content: center; gap: 8px; flex-wrap: wrap; padding: 10px 14px; background: rgba(255,255,255,0.04); border: 1px solid var(--line); border-radius: 8px;">
                <span style="font-size: 14.5px; font-weight: 800; color: var(--lime);">+${cals} kcal</span>
                <span style="font-size: 12px; color: var(--muted); font-weight: 600;">(${pro}g P • ${carbs}g C • ${fat}g F)</span>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align: -2px; margin-right: 4px;"><polyline points="20 6 9 17 4 12"/></svg> Yes, Log Meal',
        cancelButtonText: 'Cancel',
        confirmButtonColor: 'var(--lime, #84cc16)',
        cancelButtonColor: 'rgba(255, 255, 255, 0.12)',
        background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
        color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
        customClass: {
            confirmButton: 'btn-swal-confirm-lime',
            cancelButton: 'btn-swal-cancel-dark'
        }
    });

    if (!confirmResult.isConfirmed) {
        return;
    }

    const btn = card.querySelector('.btn-log-meal');
    const originalHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<span class="ft-spinner" style="width:12px; height:12px; border-width:2px; display:inline-block;"></span> <span>Saving...</span>`;
    }
    card._isLogging = true;

    try {
        const body = new URLSearchParams({
            mode: 'add',
            meal_id: mealId.toString(),
            day_of_week: dayNum.toString(),
            meal_type: normType,
            calories: cals.toString(),
            protein_g: pro.toString(),
            carbs_g: carbs.toString(),
            fat_g: fat.toString(),
            food_items: foodItems,
            csrf_token: FT_CSRF_TOKEN
        });

        const res = await fetch('index.php?page=log_macros', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: body.toString()
        });

        const data = await res.json();
        card._isLogging = false;

        if (data && data.already_logged) {
            // Already logged (duplicate prevented by backend/DB)
            const logTime = data.logged_time || 'earlier today';
            if (!window.FT_LOGGED_MEALS_TODAY) window.FT_LOGGED_MEALS_TODAY = {};
            window.FT_LOGGED_MEALS_TODAY[normType] = { time: logTime, meal_id: mealId };
            markMealAsLoggedUI(mealId, normType, logTime);
            handleAlreadyLoggedClick(normType, logTime);
            return;
        }

        if (data && data.can_log_today_only) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
            Swal.fire({
                icon: 'info',
                title: 'Today Only',
                text: data.error || 'Only meals for today can be logged.',
                background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
                confirmButtonColor: 'var(--lime, #c7ff22)',
            });
            return;
        }

        if (data && data.success) {
            const loggedTime = data.logged_time || new Date().toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'});
            if (!window.FT_LOGGED_MEALS_TODAY) window.FT_LOGGED_MEALS_TODAY = {};
            window.FT_LOGGED_MEALS_TODAY[normType] = { time: loggedTime, meal_id: mealId };

            // Apply live UI updates to the meal cards immediately
            markMealAsLoggedUI(mealId, normType, loggedTime);

            // Apply live update to Today's Macro Tracker DOM
            if (typeof window.applyMacroLogResultToUI === 'function') {
                window.applyMacroLogResultToUI(data, false);
            }

            // Toast feedback with clean, unified, unstacked layout
            const isMobile = window.innerWidth <= 768;
            Swal.fire({
                toast: true,
                position: isMobile ? 'top' : 'top-end',
                icon: 'success',
                width: isMobile ? 'calc(100vw - 28px)' : '420px',
                html: `<div class="diet-toast-body">
                    <div class="diet-toast-header">
                        <div class="diet-toast-headline">
                            <span class="diet-toast-title">${normType} Log</span>
                            <strong class="diet-toast-cals">+${cals} kcal</strong>
                        </div>
                        <span class="diet-toast-macro-breakdown">${pro}g P • ${carbs}g C • ${fat}g F</span>
                    </div>
                    <div class="diet-toast-footer">
                        <span>Logged at ${loggedTime}</span>
                        <span class="diet-toast-link">View Tracker <i class="fa-solid fa-arrow-right"></i></span>
                    </div>
                </div>`,
                showConfirmButton: false,
                timer: 4500,
                timerProgressBar: true,
                background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
                customClass: {
                    container: 'diet-log-toast-container',
                    popup: 'diet-log-toast'
                },
                didOpen: (toast) => {
                    toast.style.cursor = 'pointer';
                    const container = toast.closest('.swal2-container');
                    if (container && window.innerWidth <= 768) {
                        container.style.transform = 'none';
                        container.style.left = '0';
                        container.style.right = '0';
                        container.style.width = '100%';
                    }
                    toast.onmouseenter = Swal.stopTimer;
                    toast.onmouseleave = Swal.resumeTimer;
                    toast.addEventListener('click', () => {
                        switchDietView('tracker');
                        Swal.close();
                    });
                }
            });
        } else {
            throw new Error(data.error || 'Failed to log meal');
        }
    } catch (err) {
        console.error(err);
        card._isLogging = false;
        if (btn) {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        }
        Swal.fire({
            icon: 'error',
            title: 'Unable to Log Meal',
            text: err.message || 'Could not record meal to daily macros.',
            background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
            color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
            confirmButtonColor: 'var(--lime, #c7ff22)',
        });
    }
}

// ----------------------------------------------------
// FEATURE 1: INGREDIENT & MICRONUTRIENT INSPECTION
// ----------------------------------------------------
async function inspectPlannedMeal(mealId) {
    const card = document.getElementById('meal-card-' + mealId);
    if (!card) return;

    currentInspectingMeal = {
        mealId: mealId,
        type: card.dataset.type || 'Meal',
        food: card.dataset.food || '',
        cals: parseFloat(card.dataset.cals || 0),
        pro: parseFloat(card.dataset.pro || 0),
        carbs: parseFloat(card.dataset.carbs || 0),
        fat: parseFloat(card.dataset.fat || 0)
    };

    const modal = document.getElementById('modal-inspect-meal');
    const title = document.getElementById('inspect-modal-title');
    const subtitle = document.getElementById('inspect-modal-subtitle');
    const nameEl = document.getElementById('inspect-meal-name');
    const chipCals = document.getElementById('inspect-chip-cals');
    const chipPro = document.getElementById('inspect-chip-pro');
    const chipCarbs = document.getElementById('inspect-chip-carbs');
    const chipFat = document.getElementById('inspect-chip-fat');
    const loadingEl = document.getElementById('inspect-loading');
    const contentEl = document.getElementById('inspect-content');
    const fallbackEl = document.getElementById('inspect-fallback');
    const itemsCont = document.getElementById('inspect-items-container');
    const microBanner = document.getElementById('inspect-micro-banner');

    title.textContent = `${currentInspectingMeal.type} Breakdown`;
    subtitle.textContent = `Ingredient inspection & micronutrient profile`;
    nameEl.textContent = currentInspectingMeal.food;
    chipCals.textContent = `${currentInspectingMeal.cals} kcal`;
    chipPro.textContent = `${currentInspectingMeal.pro}g P`;
    chipCarbs.textContent = `${currentInspectingMeal.carbs}g C`;
    chipFat.textContent = `${currentInspectingMeal.fat}g F`;

    // Configure inspect quick log button state
    const inspectLogBtn = document.getElementById('btn-inspect-quick-log');
    const dayNum = parseInt(card.dataset.day || 0, 10);
    const normType = normalizeMealType(currentInspectingMeal.type);

    if (inspectLogBtn) {
        if (dayNum !== window.FT_CURRENT_DAY_NUM) {
            inspectLogBtn.disabled = true;
            inspectLogBtn.style.opacity = '0.6';
            inspectLogBtn.style.cursor = 'not-allowed';
            inspectLogBtn.onclick = null;
            inspectLogBtn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg> <span>View Only (Scheduled for Another Day)</span>`;
        } else if (window.FT_LOGGED_MEALS_TODAY && window.FT_LOGGED_MEALS_TODAY[normType]) {
            const logTime = window.FT_LOGGED_MEALS_TODAY[normType].time;
            inspectLogBtn.disabled = false;
            inspectLogBtn.style.opacity = '0.9';
            inspectLogBtn.style.cursor = 'pointer';
            inspectLogBtn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg> <span>${normType} Already Logged Today</span>`;
            inspectLogBtn.onclick = function() {
                closeInspectModal();
                handleAlreadyLoggedClick(normType, logTime);
            };
        } else {
            inspectLogBtn.disabled = false;
            inspectLogBtn.style.opacity = '1';
            inspectLogBtn.style.cursor = 'pointer';
            inspectLogBtn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg> <span>+ Log This Meal to Today</span>`;
            inspectLogBtn.onclick = function() { quickLogFromInspection(); };
        }
    }

    itemsCont.innerHTML = '';
    const seeMoreWrapReset = document.getElementById('inspect-see-more-wrap');
    if (seeMoreWrapReset) seeMoreWrapReset.style.display = 'none';
    isInspectIngredientsExpanded = false;
    microBanner.style.display = 'none';
    fallbackEl.style.display = 'none';
    contentEl.style.display = 'none';
    loadingEl.style.display = 'block';
    modal.style.display = 'flex';

    // Prepare clean search query (e.g., "350g of Chicken Adobo" -> "350g Chicken Adobo")
    const cleanQuery = currentInspectingMeal.food.replace(/\b(\d+g)\s+of\s+/i, '$1 ');

    try {
        let items = [];
        let totFiber = 0, totSugar = 0, totSodium = 0, totPotassium = 0;

        // 1. Prioritize Gym Owner's Food Library & Verified Recipe Database FIRST
        let decData = null;
        try {
            const decUrl = 'index.php?page=food_lookup&action=decompose_meal&query=' + encodeURIComponent(currentInspectingMeal.food) +
                '&food=' + encodeURIComponent(currentInspectingMeal.food) +
                `&calories=${currentInspectingMeal.cals}&protein_g=${currentInspectingMeal.pro}&carbs_g=${currentInspectingMeal.carbs}&fat_g=${currentInspectingMeal.fat}`;
            const decRes = await fetch(decUrl);
            decData = await decRes.json();
            if (decData && decData.success && Array.isArray(decData.items) && decData.items.length > 0) {
                items = decData.items;
                if (decData.verified_food_name) {
                    nameEl.textContent = decData.verified_food_name;
                }
            }
        } catch (_) {}

        // 2. Fallback: If no match in gym's food library, query external nutrition API (CalorieNinjas)
        if (!items || items.length === 0) {
            try {
                const res = await fetch('index.php?page=food_lookup&action=calorieninjas&query=' + encodeURIComponent(cleanQuery));
                const data = await res.json();
                if (data && data.success && Array.isArray(data.items) && data.items.length > 0) {
                    items = data.items;
                }
            } catch (_) {}
        }

        loadingEl.style.display = 'none';

        if (items && items.length > 0) {
            contentEl.style.display = 'block';
            currentInspectingMeal.items = items.map((item, idx) => {
                const baseServingG = parseFloat(item.serving_size_g) > 0 ? parseFloat(item.serving_size_g) : 100;
                const baseCals = parseFloat(item.calories) || 0;
                const basePro = parseFloat(item.protein_g) || 0;
                const baseCarbs = parseFloat(item.carbs_g) || 0;
                const baseFat = parseFloat(item.fat_g) || 0;

                const defaultUnit = detectSmartFoodUnit(item.name, item.portion, baseServingG);
                let initialQty = baseServingG;
                if (defaultUnit === 'l') initialQty = baseServingG / 1000;
                else if (defaultUnit === 'oz') initialQty = baseServingG / 28.3495;
                else if (defaultUnit === 'cup') initialQty = baseServingG / 240;
                else if (defaultUnit === 'tbsp') initialQty = baseServingG / 15;
                else if (defaultUnit === 'tsp') initialQty = baseServingG / 5;

                return {
                    ...item,
                    baseServingG: baseServingG,
                    calsPerG: baseCals / baseServingG,
                    proPerG: basePro / baseServingG,
                    carbsPerG: baseCarbs / baseServingG,
                    fatPerG: baseFat / baseServingG,
                    currentUnit: defaultUnit,
                    currentQty: formatPortionQty(initialQty),
                    currentCals: baseCals,
                    currentPro: basePro,
                    currentCarbs: baseCarbs,
                    currentFat: baseFat
                };
            });

            const INSPECT_INITIAL_LIMIT = 4;
            const hasMore = items.length > INSPECT_INITIAL_LIMIT;
            isInspectIngredientsExpanded = false;

            itemsCont.innerHTML = currentInspectingMeal.items.map((item, idx) => {
                totFiber += (item.fiber_g || 0);
                totSugar += (item.sugar_g || 0);
                totSodium += (item.sodium_mg || 0);
                totPotassium += (item.potassium_mg || 0);

                const isExtra = idx >= INSPECT_INITIAL_LIMIT;
                const extraClass = isExtra ? ' inspect-item-extra' : '';
                const extraStyle = isExtra ? ' style="display: none;"' : '';

                return `
                    <label class="inspect-item-row${extraClass}" data-idx="${idx}"${extraStyle}>
                        <div class="inspect-item-main">
                            <input type="checkbox" class="inspect-ingredient-check" data-idx="${idx}" checked onchange="recalculateInspectedIngredients()">
                            <div class="inspect-item-left">
                                <span class="inspect-item-title">${escapeHtml(item.name)}</span>
                                <div class="inspect-portion-ctrl" onclick="event.stopPropagation();" title="Adjust portion weight or unit">
                                    <input type="number" 
                                           class="inspect-qty-input" 
                                           value="${item.currentQty}" 
                                           min="0" 
                                           step="any" 
                                           data-idx="${idx}"
                                           onclick="event.stopPropagation();"
                                           oninput="onInspectQtyInput(${idx}, this.value)"
                                           aria-label="Quantity for ${escapeHtml(item.name)}">
                                    <select class="inspect-unit-select" 
                                            data-idx="${idx}" 
                                            onclick="event.stopPropagation();"
                                            onchange="onInspectUnitChange(${idx}, this.value)"
                                            aria-label="Unit for ${escapeHtml(item.name)}">
                                        <option value="g" ${item.currentUnit === 'g' ? 'selected' : ''}>g</option>
                                        <option value="ml" ${item.currentUnit === 'ml' ? 'selected' : ''}>ml</option>
                                        <option value="l" ${item.currentUnit === 'l' ? 'selected' : ''}>L</option>
                                        <option value="oz" ${item.currentUnit === 'oz' ? 'selected' : ''}>oz</option>
                                        <option value="cup" ${item.currentUnit === 'cup' ? 'selected' : ''}>cup</option>
                                        <option value="tbsp" ${item.currentUnit === 'tbsp' ? 'selected' : ''}>tbsp</option>
                                        <option value="tsp" ${item.currentUnit === 'tsp' ? 'selected' : ''}>tsp</option>
                                        <option value="pcs" ${item.currentUnit === 'pcs' ? 'selected' : ''}>pcs</option>
                                        <option value="serving" ${item.currentUnit === 'serving' ? 'selected' : ''}>serving</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="inspect-item-macros" id="inspect-item-macros-${idx}">
                            <span class="inspect-chip chip-cals" title="Calories: ${Math.round(item.currentCals)} kcal">${Math.round(item.currentCals)} kcal</span>
                            <span class="inspect-chip chip-pro" title="Protein: ${Math.round(item.currentPro * 10) / 10}g">${Math.round(item.currentPro * 10) / 10}g P</span>
                            <span class="inspect-chip chip-carbs" title="Carbohydrates: ${Math.round(item.currentCarbs * 10) / 10}g">${Math.round(item.currentCarbs * 10) / 10}g C</span>
                            <span class="inspect-chip chip-fat" title="Fat: ${Math.round(item.currentFat * 10) / 10}g">${Math.round(item.currentFat * 10) / 10}g F</span>
                        </div>
                    </label>
                `;
            }).join('');

            // Configure See More Ingredients Button
            const seeMoreWrap = document.getElementById('inspect-see-more-wrap');
            const seeMoreBtn = document.getElementById('btn-inspect-toggle-more');
            const seeMoreText = document.getElementById('inspect-toggle-more-text');
            if (hasMore) {
                const extraCount = items.length - INSPECT_INITIAL_LIMIT;
                if (seeMoreWrap) seeMoreWrap.style.display = 'block';
                if (seeMoreText) seeMoreText.textContent = `See More Ingredients (+${extraCount})`;
                if (seeMoreBtn) {
                    seeMoreBtn.setAttribute('aria-expanded', 'false');
                    seeMoreBtn.classList.remove('expanded');
                }
            } else {
                if (seeMoreWrap) seeMoreWrap.style.display = 'none';
            }

            // Display Preparation & Notes from Gym's Food Library if available
            const notesEl = document.getElementById('inspect-recipe-notes');
            const notesText = document.getElementById('inspect-recipe-notes-text');
            if (notesEl && notesText) {
                if (decData && decData.recipe_notes) {
                    notesText.textContent = decData.recipe_notes;
                    notesEl.style.display = 'block';
                } else {
                    notesEl.style.display = 'none';
                }
            }

            // Recalculate based on default checked status
            recalculateInspectedIngredients();

            // Micronutrient banner if available
            if (totFiber > 0 || totSodium > 0 || totSugar > 0 || totPotassium > 0) {
                microBanner.style.display = 'flex';
                microBanner.innerHTML = `
                    <div class="inspect-micro-stat">
                        <span class="inspect-micro-stat-label">Dietary Fiber</span>
                        <span class="inspect-micro-stat-val">${totFiber.toFixed(1)}g</span>
                    </div>
                    <div class="inspect-micro-stat">
                        <span class="inspect-micro-stat-label">Sugars</span>
                        <span class="inspect-micro-stat-val">${totSugar.toFixed(1)}g</span>
                    </div>
                    <div class="inspect-micro-stat">
                        <span class="inspect-micro-stat-label">Sodium</span>
                        <span class="inspect-micro-stat-val">${Math.round(totSodium)}mg</span>
                    </div>
                    <div class="inspect-micro-stat">
                        <span class="inspect-micro-stat-label">Potassium</span>
                        <span class="inspect-micro-stat-val">${Math.round(totPotassium)}mg</span>
                    </div>
                `;
            }
        } else {
            // Graceful fallback to planned targets
            fallbackEl.style.display = 'block';
            document.getElementById('inspect-fallback-title').textContent = 'Scheduled Meal Nutrition';
            document.getElementById('inspect-fallback-msg').textContent = `Target: ${currentInspectingMeal.cals} kcal | ${currentInspectingMeal.pro}g Protein | ${currentInspectingMeal.carbs}g Carbs | ${currentInspectingMeal.fat}g Fat`;
        }
    } catch (err) {
        console.error("Inspection error:", err);
        loadingEl.style.display = 'none';
        fallbackEl.style.display = 'block';
        document.getElementById('inspect-fallback-title').textContent = 'Scheduled Meal Nutrition';
        document.getElementById('inspect-fallback-msg').textContent = `Target: ${currentInspectingMeal.cals} kcal | ${currentInspectingMeal.pro}g Protein | ${currentInspectingMeal.carbs}g Carbs | ${currentInspectingMeal.fat}g Fat`;
    }
}

function onInspectQtyInput(idx, rawVal) {
    if (!currentInspectingMeal || !currentInspectingMeal.items || !currentInspectingMeal.items[idx]) return;
    const it = currentInspectingMeal.items[idx];
    let qty = parseFloat(rawVal);
    if (isNaN(qty) || qty < 0) qty = 0;
    it.currentQty = qty;

    const factor = getPortionUnitFactor(it.currentUnit, it.baseServingG);
    const effectiveGrams = Math.max(0, qty * factor);

    it.currentCals = it.calsPerG * effectiveGrams;
    it.currentPro = it.proPerG * effectiveGrams;
    it.currentCarbs = it.carbsPerG * effectiveGrams;
    it.currentFat = it.fatPerG * effectiveGrams;

    const macrosEl = document.getElementById('inspect-item-macros-' + idx);
    if (macrosEl) {
        macrosEl.innerHTML = `
            <span class="inspect-chip chip-cals" title="Calories: ${Math.round(it.currentCals)} kcal">${Math.round(it.currentCals)} kcal</span>
            <span class="inspect-chip chip-pro" title="Protein: ${Math.round(it.currentPro * 10) / 10}g">${Math.round(it.currentPro * 10) / 10}g P</span>
            <span class="inspect-chip chip-carbs" title="Carbohydrates: ${Math.round(it.currentCarbs * 10) / 10}g">${Math.round(it.currentCarbs * 10) / 10}g C</span>
            <span class="inspect-chip chip-fat" title="Fat: ${Math.round(it.currentFat * 10) / 10}g">${Math.round(it.currentFat * 10) / 10}g F</span>
        `;
    }

    recalculateInspectedIngredients();
}

function onInspectUnitChange(idx, newUnit) {
    if (!currentInspectingMeal || !currentInspectingMeal.items || !currentInspectingMeal.items[idx]) return;
    const it = currentInspectingMeal.items[idx];
    const oldUnit = it.currentUnit;
    const oldFactor = getPortionUnitFactor(oldUnit, it.baseServingG);
    const newFactor = getPortionUnitFactor(newUnit, it.baseServingG);

    const currentGrams = it.currentQty * oldFactor;
    let newQty = currentGrams / newFactor;
    newQty = formatPortionQty(newQty);

    it.currentUnit = newUnit;
    it.currentQty = newQty;

    const inputEl = document.querySelector(`.inspect-qty-input[data-idx="${idx}"]`);
    if (inputEl) inputEl.value = newQty;

    onInspectQtyInput(idx, newQty);
}

function recalculateInspectedIngredients() {
    if (!currentInspectingMeal || !currentInspectingMeal.items) return;
    const checks = document.querySelectorAll('.inspect-ingredient-check');
    let totCals = 0, totPro = 0, totCarbs = 0, totFat = 0;
    let selectedCount = 0;
    let selectedNames = [];

    checks.forEach(chk => {
        const row = chk.closest('.inspect-item-row');
        if (chk.checked) {
            const idx = parseInt(chk.dataset.idx, 10);
            const it = currentInspectingMeal.items[idx];
            if (it) {
                totCals += (parseFloat(it.currentCals ?? it.calories) || 0);
                totPro += (parseFloat(it.currentPro ?? it.protein_g) || 0);
                totCarbs += (parseFloat(it.currentCarbs ?? it.carbs_g) || 0);
                totFat += (parseFloat(it.currentFat ?? it.fat_g) || 0);
                selectedNames.push(it.name);
                selectedCount++;
            }
            if (row) {
                row.style.opacity = '1';
                row.style.borderColor = 'var(--line)';
            }
        } else {
            if (row) {
                row.style.opacity = '0.45';
                row.style.borderColor = 'transparent';
            }
        }
    });

    currentInspectingMeal.selectedTotals = {
        cals: Math.round(totCals),
        pro: Math.round(totPro * 10) / 10,
        carbs: Math.round(totCarbs * 10) / 10,
        fat: Math.round(totFat * 10) / 10,
        count: selectedCount,
        names: selectedNames
    };

    // Update chips live
    document.getElementById('inspect-chip-cals').textContent = `${currentInspectingMeal.selectedTotals.cals} kcal`;
    document.getElementById('inspect-chip-pro').textContent = `${currentInspectingMeal.selectedTotals.pro}g P`;
    document.getElementById('inspect-chip-carbs').textContent = `${currentInspectingMeal.selectedTotals.carbs}g C`;
    document.getElementById('inspect-chip-fat').textContent = `${currentInspectingMeal.selectedTotals.fat}g F`;

    const hintEl = document.getElementById('inspect-meal-status-hint');
    if (hintEl) {
        hintEl.textContent = `Estimated based on ${selectedCount} of ${checks.length} selected ingredients`;
    }

    const logBtn = document.getElementById('btn-inspect-quick-log');
    if (logBtn && !logBtn.disabled) {
        if (selectedCount === 0) {
            logBtn.innerHTML = `<span>Select at least 1 ingredient</span>`;
            logBtn.style.opacity = '0.5';
            logBtn.style.pointerEvents = 'none';
        } else {
            logBtn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg> <span>+ Log Selected Ingredients (${currentInspectingMeal.selectedTotals.cals} kcal)</span>`;
            logBtn.style.opacity = '1';
            logBtn.style.pointerEvents = 'auto';
        }
    }
}

function toggleAllInspectedIngredients(status) {
    const checks = document.querySelectorAll('.inspect-ingredient-check');
    checks.forEach(chk => { chk.checked = status; });
    recalculateInspectedIngredients();
}

let isInspectIngredientsExpanded = false;

function toggleInspectSeeMore() {
    isInspectIngredientsExpanded = !isInspectIngredientsExpanded;
    const extras = document.querySelectorAll('.inspect-item-extra');
    const seeMoreBtn = document.getElementById('btn-inspect-toggle-more');
    const seeMoreText = document.getElementById('inspect-toggle-more-text');
    const extraCount = currentInspectingMeal && currentInspectingMeal.items ? Math.max(0, currentInspectingMeal.items.length - 4) : 0;

    extras.forEach(el => {
        el.style.display = isInspectIngredientsExpanded ? '' : 'none';
    });

    if (seeMoreBtn) {
        seeMoreBtn.setAttribute('aria-expanded', isInspectIngredientsExpanded ? 'true' : 'false');
        if (isInspectIngredientsExpanded) {
            seeMoreBtn.classList.add('expanded');
            if (seeMoreText) seeMoreText.textContent = 'See Less Ingredients';
        } else {
            seeMoreBtn.classList.remove('expanded');
            if (seeMoreText) seeMoreText.textContent = `See More Ingredients (+${extraCount})`;
        }
    }
}

function closeInspectModal() {
    const modal = document.getElementById('modal-inspect-meal');
    if (modal) modal.style.display = 'none';
    const seeMoreWrap = document.getElementById('inspect-see-more-wrap');
    if (seeMoreWrap) seeMoreWrap.style.display = 'none';
    isInspectIngredientsExpanded = false;
    currentInspectingMeal = null;
}

function quickLogFromInspection() {
    if (!currentInspectingMeal) return;
    const mealId = currentInspectingMeal.mealId;
    const custom = currentInspectingMeal.selectedTotals;
    closeInspectModal();

    if (custom && custom.count > 0) {
        quickLogPlannedMeal(mealId, custom);
    } else {
        quickLogPlannedMeal(mealId);
    }
}

// ----------------------------------------------------
// FEATURE 2: SWAP MEAL WITH ALTERNATIVE HEALTHY RECIPES
// ----------------------------------------------------
async function openSwapMealModal(mealId) {
    const card = document.getElementById('meal-card-' + mealId);
    if (!card) return;

    currentSwappingMeal = {
        mealId: mealId,
        dayNum: card.dataset.day || '1',
        type: card.dataset.type || 'Meal',
        food: card.dataset.food || '',
        cals: parseFloat(card.dataset.cals || 0),
        pro: parseFloat(card.dataset.pro || 0),
        carbs: parseFloat(card.dataset.carbs || 0),
        fat: parseFloat(card.dataset.fat || 0)
    };

    const modal = document.getElementById('modal-swap-meal');
    const title = document.getElementById('swap-modal-title');
    const subtitle = document.getElementById('swap-modal-subtitle');
    const currentFood = document.getElementById('swap-current-food');
    const currentMacros = document.getElementById('swap-current-macros');
    const loadingEl = document.getElementById('swap-loading');
    const gridEl = document.getElementById('swap-recipes-grid');

    title.textContent = `Swap ${currentSwappingMeal.type}`;
    subtitle.textContent = `Choose an alternative recipe balanced around ${currentSwappingMeal.cals} kcal`;
    currentFood.textContent = currentSwappingMeal.food;
    currentMacros.textContent = `${currentSwappingMeal.cals} kcal • ${currentSwappingMeal.pro}g Protein • ${currentSwappingMeal.carbs}g Carbs • ${currentSwappingMeal.fat}g Fat`;

    gridEl.innerHTML = '';
    loadingEl.style.display = 'block';
    modal.style.display = 'flex';

    try {
        const url = `index.php?page=diet&action=get_swap_options&meal_type=${encodeURIComponent(currentSwappingMeal.type)}&calories=${currentSwappingMeal.cals}&meal_id=${mealId}`;
        const res = await fetch(url);
        const data = await res.json();
        loadingEl.style.display = 'none';

        if (data && data.success && Array.isArray(data.options)) {
            swapOptionsData = data.options;
            
            // Set user diet restriction on filter pill
            const dietPill = document.getElementById('pill-filter-diet');
            if (dietPill) {
                const restName = data.user_restriction && data.user_restriction !== 'none'
                    ? data.user_restriction.replace('-', ' ')
                    : 'Balanced';
                dietPill.textContent = `Matched: ${capitalize(restName)}`;
            }

            renderSwapRecipes();
        } else {
            gridEl.innerHTML = `<div style="padding:24px; text-align:center; color:var(--muted);">No alternative recipes found for this meal type.</div>`;
        }
    } catch (err) {
        console.error("Swap options fetch error:", err);
        loadingEl.style.display = 'none';
        gridEl.innerHTML = `<div style="padding:24px; text-align:center; color:var(--muted);">Could not load alternatives. Please check your connection.</div>`;
    }
}

function renderSwapRecipes() {
    const gridEl = document.getElementById('swap-recipes-grid');
    if (!gridEl) return;

    let filtered = swapOptionsData;
    if (activeSwapFilter === 'diet') {
        filtered = swapOptionsData.filter(o => o.is_diet_match);
    } else if (activeSwapFilter === 'high-protein') {
        filtered = swapOptionsData.filter(o => o.protein_g >= 30 || (o.tags && o.tags.some(t => t.toLowerCase().includes('protein'))));
    }
    currentFilteredSwapRecipes = filtered;

    if (filtered.length === 0) {
        gridEl.innerHTML = `<div style="padding:30px 10px; text-align:center; color:var(--muted); font-size:13px;">No recipes match the "${activeSwapFilter}" filter. Showing all options.</div>`;
        setTimeout(() => {
            const firstPill = document.querySelector('.swap-filter-pill');
            filterSwapRecipes('all', firstPill);
        }, 1200);
        return;
    }

    gridEl.innerHTML = filtered.map((opt, idx) => {
        const tagBadges = (opt.tags || []).map(t => {
            const isDietTag = opt.is_diet_match && t.toLowerCase().includes('diet');
            return `<span class="swap-tag ${isDietTag ? 'tag-diet' : ''}">${escapeHtml(t)}</span>`;
        }).join('');

        const dietMatchBadge = opt.is_diet_match ? `<span class="swap-tag tag-diet">✓ Diet Match</span>` : '';

        return `
            <div class="swap-recipe-card">
                <div class="swap-recipe-info">
                    <div class="swap-recipe-title">${escapeHtml(opt.title)}</div>
                    <div class="swap-recipe-tags">${dietMatchBadge}${tagBadges}</div>
                    <div class="swap-recipe-macros">
                        <span><strong>${opt.calories}</strong> kcal</span>
                        <span>•</span>
                        <span><strong>${opt.protein_g}g</strong> Protein</span>
                        <span>•</span>
                        <span><strong>${opt.carbs_g}g</strong> Carbs</span>
                        <span>•</span>
                        <span><strong>${opt.fat_g}g</strong> Fat</span>
                        <span style="color:var(--muted); font-size:10.5px;">(${opt.grams}g portion)</span>
                    </div>
                </div>
                <button type="button" class="btn-select-swap" onclick="selectSwapOption(${idx}, this)">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 3h5v5"/><path d="M4 20L21 3"/><path d="M21 16v5h-5"/><path d="M15 15l6 6"/><path d="M4 4l5 5"/></svg>
                    <span>Swap to This</span>
                </button>
            </div>
        `;
    }).join('');
}

let currentFilteredSwapRecipes = [];

function selectSwapOption(idx, btnEl = null) {
    const opt = (currentFilteredSwapRecipes && currentFilteredSwapRecipes[idx]) || swapOptionsData[idx];
    if (!opt) return;
    confirmSwapRecipe(opt.food_items, opt.calories, opt.protein_g, opt.carbs_g, opt.fat_g, btnEl, opt.food_id || opt.id);
}

function filterSwapRecipes(filter, btnEl) {
    activeSwapFilter = filter;
    document.querySelectorAll('.swap-filter-pill').forEach(b => {
        if (b && b.classList) b.classList.remove('active');
    });
    if (btnEl && btnEl.classList) btnEl.classList.add('active');
    renderSwapRecipes();
}

// Send swap action to backend
async function confirmSwapRecipe(foodItems, cals, pro, carbs, fat, triggerBtn = null, foodId = null) {
    if (!currentSwappingMeal) return;

    const mealId = currentSwappingMeal.mealId;
    const dayNum = currentSwappingMeal.dayNum;
    const mealType = currentSwappingMeal.type;

    // Loading feedback on trigger button
    const allSwapBtns = document.querySelectorAll('.btn-select-swap');
    allSwapBtns.forEach(b => { if (b) b.disabled = true; });

    let origBtnHtml = '';
    if (triggerBtn) {
        origBtnHtml = triggerBtn.innerHTML;
        triggerBtn.innerHTML = `<span style="display:inline-block;animation:spin 0.8s linear infinite;margin-right:6px;">⟳</span><span>Swapping...</span>`;
    }

    try {
        const token = getLiveCsrfToken();
        const body = new URLSearchParams({
            action: 'swap_meal',
            meal_id: mealId.toString(),
            food_id: (foodId || '').toString(),
            food_items: foodItems,
            calories: cals.toString(),
            protein_g: pro.toString(),
            carbs_g: carbs.toString(),
            fat_g: fat.toString(),
            csrf_token: token
        });

        const res = await fetch('index.php?page=diet&action=swap_meal', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': token,
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: body.toString()
        });

        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (parseErr) {
            console.error("Non-JSON response received from server:", text);
            throw new Error(`Server returned an invalid response (${res.status || 'Error'}). Please refresh the page.`);
        }

        if (data && data.success) {
            closeSwapModal();

            // 1. Update meal card DOM in place
            const card = document.getElementById('meal-card-' + mealId);
            if (card) {
                card.dataset.food = foodItems;
                card.dataset.cals = cals;
                card.dataset.pro = pro;
                card.dataset.carbs = carbs;
                card.dataset.fat = fat;

                const calsBadge = document.getElementById('meal-cals-badge-' + mealId);
                const foodText  = document.getElementById('meal-food-' + mealId);
                const proEl     = document.getElementById('meal-pro-' + mealId);
                const carbsEl   = document.getElementById('meal-carbs-' + mealId);
                const fatEl     = document.getElementById('meal-fat-' + mealId);

                if (calsBadge) calsBadge.textContent = `${cals} kcal`;
                if (foodText)  foodText.textContent = foodItems;
                if (proEl)     proEl.textContent = pro;
                if (carbsEl)   carbsEl.textContent = carbs;
                if (fatEl)     fatEl.textContent = fat;

                // Update photo if auto-resolved for new food item
                if (data.resolved_url) {
                    const cardImg = document.getElementById('meal-img-' + mealId);
                    if (cardImg) cardImg.src = data.resolved_url;
                    const editPhotoBtn = document.getElementById('btn-edit-photo-' + mealId);
                    if (editPhotoBtn) {
                        editPhotoBtn.setAttribute('data-food', foodItems);
                        editPhotoBtn.setAttribute('data-resolved-url', data.resolved_url);
                    }
                }

                // Subtle flash animation on updated card
                card.style.transition = 'box-shadow 0.3s ease, border-color 0.3s ease';
                card.style.borderColor = 'var(--lime)';
                card.style.boxShadow = '0 0 20px color-mix(in srgb, var(--lime) 30%, transparent)';
                setTimeout(() => {
                    card.style.borderColor = 'var(--line)';
                    card.style.boxShadow = 'none';
                }, 1600);
            }

            // 2. Update day totals in DOM
            if (data.day_totals) {
                const dayCalsEl  = document.getElementById('day-total-cals-' + dayNum);
                const dayProEl   = document.getElementById('day-total-pro-' + dayNum);
                const dayCarbsEl = document.getElementById('day-total-carbs-' + dayNum);
                const dayFatEl   = document.getElementById('day-total-fat-' + dayNum);

                if (dayCalsEl)  dayCalsEl.textContent = data.day_totals.calories;
                if (dayProEl)   dayProEl.textContent = data.day_totals.protein_g;
                if (dayCarbsEl) dayCarbsEl.textContent = data.day_totals.carbs_g;
                if (dayFatEl)   dayFatEl.textContent = data.day_totals.fat_g;
            }

            // 3. If swapped meal is today's schedule, update today's targets in Macro Tracker
            if (data.is_today && data.today_targets) {
                const tarDisp  = document.getElementById('macro-target-cals');
                const tarPro   = document.getElementById('macro-target-pro');
                const tarCarbs = document.getElementById('macro-target-carbs');
                const tarFat   = document.getElementById('macro-target-fat');

                if (tarDisp)  tarDisp.textContent = data.today_targets.target_cals;
                if (tarPro)   tarPro.textContent = data.today_targets.target_pro;
                if (tarCarbs) tarCarbs.textContent = data.today_targets.target_carbs;
                if (tarFat)   tarFat.textContent = data.today_targets.target_fat;

                // Re-evaluate Macro Tracker progress percentages
                const loggedCals = parseFloat(document.getElementById('macro-logged-cals')?.textContent || 0);
                const loggedPro  = parseFloat(document.getElementById('macro-logged-pro')?.textContent || 0);
                const loggedCarbs = parseFloat(document.getElementById('macro-logged-carbs')?.textContent || 0);
                const loggedFat  = parseFloat(document.getElementById('macro-logged-fat')?.textContent || 0);

                if (typeof window.applyMacroLogResultToUI === 'function') {
                    window.applyMacroLogResultToUI({
                        success: true,
                        logged_cals: loggedCals,
                        logged_pro: loggedPro,
                        logged_carbs: loggedCarbs,
                        logged_fat: loggedFat,
                        target_cals: data.today_targets.target_cals,
                        target_pro: data.today_targets.target_pro,
                        target_carbs: data.today_targets.target_carbs,
                        target_fat: data.today_targets.target_fat
                    }, false);
                }
            }

            // Toast feedback
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: `${mealType} Swapped!`,
                html: `New meal: <strong>${escapeHtml(foodItems)}</strong> (${cals} kcal)`,
                showConfirmButton: false,
                timer: 3500,
                timerProgressBar: true,
                background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
            });
        } else {
            throw new Error(data.error || data.message || 'Could not swap meal.');
        }
    } catch (err) {
        console.error("Swap meal error:", err);
        Swal.fire({
            icon: 'error',
            title: 'Swap Failed',
            text: err.message || 'Could not update meal plan. Please try again.',
            background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
            color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
            confirmButtonColor: 'var(--lime, #c7ff22)',
        });
    } finally {
        allSwapBtns.forEach(b => { if (b) b.disabled = false; });
        if (triggerBtn && origBtnHtml) {
            triggerBtn.innerHTML = origBtnHtml;
        }
    }
}

function closeSwapModal() {
    const modal = document.getElementById('modal-swap-meal');
    if (modal) modal.style.display = 'none';
    currentSwappingMeal = null;
    swapOptionsData = [];
}

// Utility: escape HTML
function escapeHtml(str) {
    if (!str) return '';
    return str.toString()
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Utility: capitalize
function capitalize(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1);
}

document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam  = urlParams.get('tab');
    let savedTab = null;
    try { savedTab = localStorage.getItem('fittracks_diet_active_tab'); } catch (e) {}

    if (tabParam === 'plan' || (!tabParam && savedTab === 'plan')) {
        switchDietView('plan');
    } else {
        switchDietView('tracker');
    }
});

// Explicit global window bindings for inline onclick event handlers
window.switchDietTab = switchDietTab;
window.switchDietView = switchDietView;
window.normalizeMealType = normalizeMealType;
window.handleAlreadyLoggedClick = handleAlreadyLoggedClick;
window.handleViewOnlyClick = handleViewOnlyClick;
window.markMealAsLoggedUI = markMealAsLoggedUI;
window.resetTodayMealCardsUI = resetTodayMealCardsUI;
window.quickLogPlannedMeal = quickLogPlannedMeal;
window.inspectPlannedMeal = inspectPlannedMeal;
window.recalculateInspectedIngredients = recalculateInspectedIngredients;
window.toggleAllInspectedIngredients = toggleAllInspectedIngredients;
window.toggleInspectSeeMore = toggleInspectSeeMore;
window.closeInspectModal = closeInspectModal;
window.quickLogFromInspection = quickLogFromInspection;
window.openSwapMealModal = openSwapMealModal;
window.renderSwapRecipes = renderSwapRecipes;
window.selectSwapOption = selectSwapOption;
window.filterSwapRecipes = filterSwapRecipes;
window.switchSwapTab = switchSwapTab;
window.calculateCustomSwap = calculateCustomSwap;
window.applyCustomSwap = applyCustomSwap;
window.confirmSwapRecipe = confirmSwapRecipe;
window.closeSwapModal = closeSwapModal;
window.removeBreakdownItem = removeBreakdownItem;
window.onInspectQtyInput = onInspectQtyInput;
window.onInspectUnitChange = onInspectUnitChange;

function openDietPlanInfoModal() {
    const modal = document.getElementById('modal-diet-plan-info');
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function closeDietPlanInfoModal() {
    const modal = document.getElementById('modal-diet-plan-info');
    if (modal && modal.style.display !== 'none') {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

window.openDietPlanInfoModal = openDietPlanInfoModal;
window.closeDietPlanInfoModal = closeDietPlanInfoModal;

function toggleMobileMealLogger(forceOpen = null) {
    const tile = document.getElementById('bento-tile-logger');
    const btn = document.getElementById('btn-mobile-toggle-logger');
    const label = document.getElementById('mobile-logger-btn-label');
    if (!tile || !btn) return;

    const isOpen = tile.classList.contains('is-mobile-open');
    const shouldOpen = (forceOpen !== null) ? forceOpen : !isOpen;

    if (shouldOpen) {
        tile.classList.add('is-mobile-open');
        btn.classList.add('is-active');
        btn.setAttribute('aria-expanded', 'true');
        if (label) label.textContent = '✕ Close';
        setTimeout(() => {
            const input = document.getElementById('smart-ninja-input');
            if (input && typeof input.focus === 'function' && window.innerWidth <= 768 && forceOpen === null) {
                input.focus();
            }
            if (tile && typeof tile.scrollIntoView === 'function') {
                tile.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }, 50);
    } else {
        tile.classList.remove('is-mobile-open');
        btn.classList.remove('is-active');
        btn.setAttribute('aria-expanded', 'false');
        if (label) label.textContent = '+ Log Food';
    }
}

window.toggleMobileMealLogger = toggleMobileMealLogger;


