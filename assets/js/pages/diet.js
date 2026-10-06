/**
 * Diet & Nutrition Controller
 * Extracted from diet.php
 */

const FT_CSRF_TOKEN = (window.DIET_CONFIG && window.DIET_CONFIG.csrfToken)
    ? window.DIET_CONFIG.csrfToken
    : (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
window.FT_CSRF_TOKEN = FT_CSRF_TOKEN;

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

            if (lblCals)  lblCals.textContent  = '+ Add Calories';
            if (lblPro)   lblPro.textContent   = '+ Add Protein (g)';
            if (lblCarbs) lblCarbs.textContent = '+ Add Carbs (g)';
            if (lblFat)   lblFat.textContent   = '+ Add Fat (g)';

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

            if (lblCals)  lblCals.textContent  = 'Total Calories';
            if (lblPro)   lblPro.textContent   = 'Total Protein (g)';
            if (lblCarbs) lblCarbs.textContent = 'Total Carbs (g)';
            if (lblFat)   lblFat.textContent   = 'Total Fat (g)';

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
    // Smart Meal Assistant Handlers (CalorieNinjas & Open Food Facts)
    // -------------------------------------------------------------
    const tabSmartNinja   = document.getElementById('tab-smart-ninja');
    const tabSmartOff     = document.getElementById('tab-smart-off');
    const panelSmartNinja = document.getElementById('panel-smart-ninja');
    const panelSmartOff   = document.getElementById('panel-smart-off');

    const ninjaInput      = document.getElementById('smart-ninja-input');
    const ninjaBtn        = document.getElementById('btn-analyze-ninja');
    const ninjaBtnTxt     = document.getElementById('btn-analyze-ninja-txt');
    const ninjaBreakdown  = document.getElementById('ninja-breakdown-wrap');
    const ninjaItemsEl    = document.getElementById('ninja-breakdown-items');
    const ninjaTotalsEl   = document.getElementById('ninja-breakdown-totals');

    const offInput        = document.getElementById('smart-off-input');
    const offBtn          = document.getElementById('btn-search-off');
    const offBtnTxt       = document.getElementById('btn-search-off-txt');
    const offServingInput = document.getElementById('off-serving-input');
    const offContainer    = document.getElementById('off-results-container');
    const offGrid         = document.getElementById('off-results-grid');

    window.switchSmartTab = function(tab) {
        if (tab === 'ninja') {
            tabSmartNinja?.classList.add('active');
            tabSmartOff?.classList.remove('active');
            panelSmartNinja?.classList.add('active');
            panelSmartOff?.classList.remove('active');
            ninjaInput?.focus();
        } else {
            tabSmartOff?.classList.add('active');
            tabSmartNinja?.classList.remove('active');
            panelSmartOff?.classList.add('active');
            panelSmartNinja?.classList.remove('active');
            offInput?.focus();
        }
    };

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

    function renderMealBreakdownAndApply(data, mealLabel = 'Meal') {
        if (!data || !data.success) return;

        // Render clean, structured breakdown items
        if (ninjaItemsEl && data.items && data.items.length) {
            ninjaItemsEl.innerHTML = data.items.map(item => `
                <div class="smart-breakdown-item">
                    <div class="breakdown-item-main">
                        <span class="breakdown-item-name">${escapeHtml(item.name)}</span>
                        <span class="breakdown-item-portion">${item.serving_size_g}g</span>
                    </div>
                    <div class="breakdown-item-macros">
                        <span><strong class="breakdown-macro-val">${item.calories}</strong> <span class="breakdown-macro-unit">kcal</span></span>
                        <span class="breakdown-macro-sep">&bull;</span>
                        <span><strong class="breakdown-macro-val">${item.protein_g}</strong><span class="breakdown-macro-unit">g P</span></span>
                        <span class="breakdown-macro-sep">&bull;</span>
                        <span><strong class="breakdown-macro-val">${item.carbs_g}</strong><span class="breakdown-macro-unit">g C</span></span>
                        <span class="breakdown-macro-sep">&bull;</span>
                        <span><strong class="breakdown-macro-val">${item.fat_g}</strong><span class="breakdown-macro-unit">g F</span></span>
                    </div>
                </div>
            `).join('');
        }

        // Render balanced, cohesive total chips
        if (ninjaTotalsEl) {
            ninjaTotalsEl.innerHTML = `
                <div class="smart-total-chip">
                    <span class="chip-label">Calories</span>
                    <span class="chip-value">${data.total_calories} kcal</span>
                </div>
                <div class="smart-total-chip">
                    <span class="chip-label">Protein</span>
                    <span class="chip-value">${data.total_protein}g</span>
                </div>
                <div class="smart-total-chip">
                    <span class="chip-label">Carbs</span>
                    <span class="chip-value">${data.total_carbs}g</span>
                </div>
                <div class="smart-total-chip">
                    <span class="chip-label">Fat</span>
                    <span class="chip-value">${data.total_fat}g</span>
                </div>
            `;
        }

        if (ninjaBreakdown) ninjaBreakdown.style.display = 'block';

        applyMacrosToForm(data.total_calories, data.total_protein, data.total_carbs, data.total_fat, mealLabel);
    }

    window.setQuickMealText = function(text) {
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
        form?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    window.clearSmartMealAssistant = function() {
        // 1. Clear search inputs
        if (ninjaInput) ninjaInput.value = '';
        if (offInput) offInput.value = '';

        // 2. Hide and clear breakdown containers
        if (ninjaBreakdown) ninjaBreakdown.style.display = 'none';
        if (ninjaItemsEl) ninjaItemsEl.innerHTML = '';
        if (ninjaTotalsEl) ninjaTotalsEl.innerHTML = '';
        if (offContainer) offContainer.style.display = 'none';

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

        // 5. Refocus current search input
        if (panelSmartOff?.classList.contains('active')) {
            offInput?.focus();
        } else {
            ninjaInput?.focus();
        }

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

    let cachedOffProducts = [];

    function renderOffProducts() {
        if (!offGrid || !cachedOffProducts.length) return;
        const portion = Math.max(1, parseFloat(offServingInput?.value || 100) || 100);
        const ratio = portion / 100;

        offGrid.innerHTML = cachedOffProducts.map((p, idx) => {
            const pCals = Math.round(p.calories_100g * ratio);
            const pPro = Math.round((p.protein_100g * ratio) * 10) / 10;
            const pCarbs = Math.round((p.carbs_100g * ratio) * 10) / 10;
            const pFat = Math.round((p.fat_100g * ratio) * 10) / 10;

            return `
                <div class="off-food-card" onclick="selectOffFood(${idx})">
                    <div>
                        <div style="font-size: 11px; color: var(--muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.3px;">
                            ${escapeHtml(p.brand || 'Food Product')}
                        </div>
                        <div style="font-size: 12.5px; font-weight: 700; color: var(--ink); margin: 2px 0 6px; line-height: 1.3;">
                            ${escapeHtml(p.name)}
                        </div>
                    </div>
                    <div>
                        <div style="display: flex; gap: 5px; flex-wrap: wrap; margin-bottom: 6px; font-size: 11px; font-weight: 700;">
                            <span style="color: var(--macro-cals); background: color-mix(in srgb, var(--macro-cals) 14%, transparent); padding: 2px 6px; border-radius: 6px;">${pCals} kcal</span>
                            <span style="color: var(--macro-pro); background: color-mix(in srgb, var(--macro-pro) 14%, transparent); padding: 2px 6px; border-radius: 6px;">${pPro}g P</span>
                            <span style="color: var(--macro-carbs); background: color-mix(in srgb, var(--macro-carbs) 14%, transparent); padding: 2px 6px; border-radius: 6px;">${pCarbs}g C</span>
                            <span style="color: var(--macro-fat); background: color-mix(in srgb, var(--macro-fat) 14%, transparent); padding: 2px 6px; border-radius: 6px;">${pFat}g F</span>
                        </div>
                        <div style="font-size: 10.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;">
                            <span>For ${portion}g</span>
                            <span style="color: var(--macro-pro); font-weight: 700;">Apply ➔</span>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    window.selectOffFood = function(idx) {
        const p = cachedOffProducts[idx];
        if (!p) return;
        const portion = Math.max(1, parseFloat(offServingInput?.value || 100) || 100);
        const ratio = portion / 100;

        const pCals = Math.round(p.calories_100g * ratio);
        const pPro = Math.round((p.protein_100g * ratio) * 10) / 10;
        const pCarbs = Math.round((p.carbs_100g * ratio) * 10) / 10;
        const pFat = Math.round((p.fat_100g * ratio) * 10) / 10;

        applyMacrosToForm(pCals, pPro, pCarbs, pFat, p.name);
    };

    window.adjustOffServing = function(delta) {
        if (!offServingInput) return;
        let val = parseFloat(offServingInput.value || 100) || 100;
        val = Math.max(5, Math.min(2000, val + delta));
        offServingInput.value = val;
        renderOffProducts();
    };

    if (offServingInput) {
        offServingInput.addEventListener('input', renderOffProducts);
    }

    async function searchOpenFoodFacts() {
        const query = offInput?.value?.trim();
        if (!query) {
            offInput?.focus();
            return;
        }

        if (offBtn) offBtn.disabled = true;
        if (offBtnTxt) offBtnTxt.textContent = 'Searching...';

        try {
            const res = await fetch('index.php?page=food_lookup&action=openfoodfacts&query=' + encodeURIComponent(query));
            const data = await res.json();

            if (!data || !data.success || !data.products || !data.products.length) {
                Swal.fire({
                    icon: 'info',
                    title: 'No Results',
                    text: data?.error || 'No branded foods found for this query.',
                    background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                    color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff'
                });
                return;
            }

            cachedOffProducts = data.products;
            if (offContainer) offContainer.style.display = 'block';
            renderOffProducts();
        } catch (err) {
            Swal.fire({
                icon: 'error',
                title: 'Search Error',
                text: 'Could not connect to Open Food Facts. Please try again.',
                background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff'
            });
        } finally {
            if (offBtn) offBtn.disabled = false;
            if (offBtnTxt) offBtnTxt.textContent = 'Search';
        }
    }

    if (offBtn) {
        offBtn.addEventListener('click', searchOpenFoodFacts);
    }
    if (offInput) {
        offInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchOpenFoodFacts();
            }
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
                inCals.style.background = 'rgba(199,255,34,0.25)';
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
        themeColor,
        themeGlow,
        badgeText,
        iconSvg,
        title,
        metricLabel,
        loggedVal,
        targetVal,
        description,
        btnText,
        btnBg,
        isAll = false,
        allStats = null
    }) {
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
            <div style="text-align: center; padding: 6px 4px;">
                <!-- Glowing Hero Icon -->
                <div class="celebration-hero-pulse" style="width: 76px; height: 76px; margin: 0 auto 16px; border-radius: 50%; background: radial-gradient(circle, ${themeGlow} 0%, rgba(15,20,17,0.92) 100%); border: 2px solid ${themeColor}; display: flex; align-items: center; justify-content: center; box-shadow: 0 0 40px ${themeGlow};">
                    ${iconSvg}
                </div>

                <!-- Milestone Tag -->
                <div style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; background: rgba(255,255,255,0.05); border: 1px solid ${themeColor}50; border-radius: 20px; font-size: 11px; font-weight: 800; color: ${themeColor}; letter-spacing: 0.8px; text-transform: uppercase; margin-bottom: 10px;">
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
                    <button type="button" class="celebration-action-btn celebration-close-trigger" style="background: ${btnBg}; color: var(--macro-btn-ink); font-weight: 800; font-size: 13.5px; border: none; padding: 11px 24px; border-radius: 9px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 8px 24px ${themeGlow};">
                        <span>Got it, continue!</span>
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
                    themeColor: 'var(--lime, #c7ff22)',
                    themeGlow: 'rgba(199, 255, 34, 0.45)',
                    badgeText: 'DAILY TARGETS COMPLETED',
                    iconSvg: '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"></path><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"></path><path d="M4 22h16"></path><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"></path><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"></path><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"></path></svg>',
                    title: 'Perfect Macro Day!',
                    description: 'Incredible dedication! You have successfully reached every single nutritional target scheduled for today.',
                    btnText: 'Keep the Streak',
                    btnBg: 'var(--lime, #c7ff22)',
                    isAll: true,
                    allStats: { cals: newCals, pro: newPro, carbs: newCarbs, fat: newFat }
                });
            }, 250);
        } else if (reachedPro) {
            shootConfetti(80);
            setTimeout(() => {
                fireCelebrationModal({
                    themeColor: '#38bdf8',
                    themeGlow: 'rgba(56, 189, 248, 0.45)',
                    badgeText: 'PROTEIN GOAL ACHIEVED',
                    iconSvg: '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#38bdf8" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6.5 6.5 11 11"/><path d="m21 21-1-1"/><path d="m3 3 1 1"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/><path d="m3 10 7-7"/><path d="m14 21 7-7"/></svg>',
                    title: 'Protein Target Crushed!',
                    metricLabel: 'Protein',
                    loggedVal: newPro + 'g',
                    targetVal: targetPro + 'g',
                    description: 'Hitting your daily protein goal accelerates muscle recovery, preserves lean tissue, and sustains your strength.',
                    btnText: 'Keep Building',
                    btnBg: '#38bdf8'
                });
            }, 250);
        } else if (reachedCals) {
            shootConfetti(70);
            setTimeout(() => {
                fireCelebrationModal({
                    themeColor: 'var(--lime, #c7ff22)',
                    themeGlow: 'rgba(199, 255, 34, 0.45)',
                    badgeText: 'CALORIE GOAL ACHIEVED',
                    iconSvg: '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
                    title: 'Calorie Target Reached!',
                    metricLabel: 'Calories',
                    loggedVal: newCals + ' kcal',
                    targetVal: targetCals + ' kcal',
                    description: 'You reached your planned daily energy intake, keeping your nutrition perfectly aligned with your fitness goal.',
                    btnText: 'Continue',
                    btnBg: 'var(--lime, #c7ff22)'
                });
            }, 250);
        } else if (reachedCarbs) {
            shootConfetti(60);
            setTimeout(() => {
                fireCelebrationModal({
                    themeColor: '#fbbf24',
                    themeGlow: 'rgba(251, 191, 36, 0.45)',
                    badgeText: 'CARBOHYDRATES TARGET MET',
                    iconSvg: '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#fbbf24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>',
                    title: 'Carbs Target Met!',
                    metricLabel: 'Carbs',
                    loggedVal: newCarbs + 'g',
                    targetVal: targetCarbs + 'g',
                    description: 'Your glycogen stores are refueled and ready to power your next training session.',
                    btnText: 'Awesome',
                    btnBg: '#fbbf24'
                });
            }, 250);
        } else if (reachedFat) {
            shootConfetti(60);
            setTimeout(() => {
                fireCelebrationModal({
                    themeColor: '#f43f5e',
                    themeGlow: 'rgba(244, 63, 94, 0.45)',
                    badgeText: 'HEALTHY FATS TARGET MET',
                    iconSvg: '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#f43f5e" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><path d="m9 12 2 2 4-4"></path></svg>',
                    title: 'Healthy Fat Target Met!',
                    metricLabel: 'Fat',
                    loggedVal: newFat + 'g',
                    targetVal: targetFat + 'g',
                    description: 'Healthy fats optimize hormone balance, joint health, and steady long-term energy.',
                    btnText: 'Awesome',
                    btnBg: '#f43f5e'
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

    if (offServingInput) {
        offServingInput.addEventListener('keydown', function(e) {
            if (e.key === '-' || e.key === 'Subtract') {
                e.preventDefault();
            }
        });
        offServingInput.addEventListener('input', function() {
            if (this.value !== '' && parseFloat(this.value) < 1) {
                this.value = '1';
            }
        });
    }

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

            // Toast feedback with subtle, modern notification
            const isMobile = window.innerWidth <= 768;
            Swal.fire({
                toast: true,
                position: isMobile ? 'bottom' : 'top-end',
                icon: 'success',
                title: `${normType} Logged!`,
                html: `<div style="display:flex; flex-direction:column; gap:3px; margin-top:2px;">
                    <span style="font-size:12px; opacity:0.8;">${normType} logged today at ${loggedTime}.</span>
                    <span style="font-size:12.5px;">
                        <strong style="color:var(--lime, #bef264); font-weight:700;">+${cals} kcal</strong>
                        <span style="opacity:0.8; font-size:11.5px; margin-left:2px;">(${pro}g P • ${carbs}g C • ${fat}g F)</span>
                    </span>
                    <div style="display:inline-flex; align-items:center; gap:5px; margin-top:4px; font-size:11.5px; font-weight:700; color:var(--lime, #bef264);">
                        <span>View Tracker</span>
                        <i class="fa-solid fa-arrow-right" style="font-size:10px;"></i>
                    </div>
                </div>`,
                showConfirmButton: false,
                timer: 4000,
                timerProgressBar: true,
                background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
                didOpen: (toast) => {
                    toast.style.cursor = 'pointer';
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
            currentInspectingMeal.items = items;

            const INSPECT_INITIAL_LIMIT = 4;
            const hasMore = items.length > INSPECT_INITIAL_LIMIT;
            isInspectIngredientsExpanded = false;

            itemsCont.innerHTML = items.map((item, idx) => {
                totFiber += (item.fiber_g || 0);
                totSugar += (item.sugar_g || 0);
                totSodium += (item.sodium_mg || 0);
                totPotassium += (item.potassium_mg || 0);

                const portionDisplay = item.portion || (item.serving_size_g ? Math.round(item.serving_size_g) + 'g' : '1 portion');
                const isExtra = idx >= INSPECT_INITIAL_LIMIT;
                const extraClass = isExtra ? ' inspect-item-extra' : '';
                const extraStyle = isExtra ? ' style="display: none;"' : '';

                return `
                    <label class="inspect-item-row${extraClass}" data-idx="${idx}"${extraStyle}>
                        <div class="inspect-item-main">
                            <input type="checkbox" class="inspect-ingredient-check" data-idx="${idx}" checked onchange="recalculateInspectedIngredients()">
                            <div class="inspect-item-left">
                                <span class="inspect-item-title">${escapeHtml(item.name)}</span>
                                <span class="inspect-item-portion">${escapeHtml(portionDisplay)}</span>
                            </div>
                        </div>
                        <div class="inspect-item-macros">
                            <span class="inspect-chip chip-cals">${item.calories} kcal</span>
                            <span class="inspect-chip chip-pro">${item.protein_g}g P</span>
                            <span class="inspect-chip chip-carbs">${item.carbs_g}g C</span>
                            <span class="inspect-chip chip-fat">${item.fat_g}g F</span>
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
                totCals += (parseFloat(it.calories) || 0);
                totPro += (parseFloat(it.protein_g) || 0);
                totCarbs += (parseFloat(it.carbs_g) || 0);
                totFat += (parseFloat(it.fat_g) || 0);
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

    // Reset tabs
    switchSwapTab('curated');
    document.getElementById('swap-custom-input').value = '';
    document.getElementById('swap-custom-result').style.display = 'none';
    calculatedCustomMealData = null;

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
            gridEl.innerHTML = `<div style="padding:24px; text-align:center; color:var(--muted);">No alternative recipes found. You can enter a custom meal below.</div>`;
        }
    } catch (err) {
        console.error("Swap options fetch error:", err);
        loadingEl.style.display = 'none';
        gridEl.innerHTML = `<div style="padding:24px; text-align:center; color:var(--muted);">Could not load alternatives. Please check your connection or use Custom Meal Search.</div>`;
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

    if (filtered.length === 0) {
        gridEl.innerHTML = `<div style="padding:30px 10px; text-align:center; color:var(--muted); font-size:13px;">No recipes match the "${activeSwapFilter}" filter. Showing all options.</div>`;
        setTimeout(() => { filterSwapRecipes('all', document.querySelector('.swap-filter-pill')); }, 1200);
        return;
    }

    gridEl.innerHTML = filtered.map(opt => {
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
                <button type="button" class="btn-select-swap" onclick="confirmSwapRecipe('${escapeHtml(opt.food_items)}', ${opt.calories}, ${opt.protein_g}, ${opt.carbs_g}, ${opt.fat_g})">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 3h5v5"/><path d="M4 20L21 3"/><path d="M21 16v5h-5"/><path d="M15 15l6 6"/><path d="M4 4l5 5"/></svg>
                    <span>Swap to This</span>
                </button>
            </div>
        `;
    }).join('');
}

function filterSwapRecipes(filter, btnEl) {
    activeSwapFilter = filter;
    document.querySelectorAll('.swap-filter-pill').forEach(b => b.classList.remove('active'));
    if (btnEl) btnEl.classList.add('active');
    renderSwapRecipes();
}

function switchSwapTab(tab) {
    const tabCurated = document.getElementById('tab-swap-curated');
    const tabCustom  = document.getElementById('tab-swap-custom');
    const panelCurated = document.getElementById('swap-panel-curated');
    const panelCustom  = document.getElementById('swap-panel-custom');

    if (tab === 'custom') {
        tabCustom.classList.add('active');
        tabCurated.classList.remove('active');
        panelCustom.style.display = 'block';
        panelCurated.style.display = 'none';
    } else {
        tabCurated.classList.add('active');
        tabCustom.classList.remove('active');
        panelCurated.style.display = 'block';
        panelCustom.style.display = 'none';
    }
}

// Custom Search calculation via CalorieNinjas
async function calculateCustomSwap() {
    const input = document.getElementById('swap-custom-input');
    const query = input ? input.value.trim() : '';
    if (!query) {
        Swal.fire({
            icon: 'warning',
            title: 'Enter Meal Description',
            text: 'Please enter a food item or ingredients (e.g., 200g chicken breast, 1 cup brown rice).',
            background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
            color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
            confirmButtonColor: 'var(--lime, #c7ff22)',
        });
        return;
    }

    const btn = document.getElementById('btn-calc-custom-swap');
    const btnText = document.getElementById('btn-calc-custom-text');
    const loadingEl = document.getElementById('swap-custom-loading');
    const resultCard = document.getElementById('swap-custom-result');

    btn.disabled = true;
    btnText.textContent = 'Calculating...';
    loadingEl.style.display = 'block';
    resultCard.style.display = 'none';

    try {
        const res = await fetch('index.php?page=food_lookup&action=calorieninjas&query=' + encodeURIComponent(query));
        const data = await res.json();
        loadingEl.style.display = 'none';
        btn.disabled = false;
        btnText.textContent = 'Calculate';

        if (data && data.success) {
            calculatedCustomMealData = {
                food_items: query,
                calories: Math.round(data.total_calories || 0),
                protein_g: parseFloat(data.total_protein || 0),
                carbs_g: parseFloat(data.total_carbs || 0),
                fat_g: parseFloat(data.total_fat || 0)
            };

            document.getElementById('swap-custom-name').textContent = query;
            document.getElementById('swap-custom-cals').textContent = `${calculatedCustomMealData.calories} kcal`;
            document.getElementById('swap-custom-macros-row').innerHTML = `
                <span><strong>${calculatedCustomMealData.protein_g}g</strong> Protein</span>
                <span>•</span>
                <span><strong>${calculatedCustomMealData.carbs_g}g</strong> Carbs</span>
                <span>•</span>
                <span><strong>${calculatedCustomMealData.fat_g}g</strong> Fat</span>
            `;
            resultCard.style.display = 'block';
        } else {
            Swal.fire({
                icon: 'warning',
                title: 'No Nutrition Data',
                text: data.error || 'Could not find nutrition values for this query. Try adding portions like "150g" or "1 cup".',
                background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
                color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
                confirmButtonColor: 'var(--lime, #c7ff22)',
            });
        }
    } catch (err) {
        console.error("Custom swap calc error:", err);
        loadingEl.style.display = 'none';
        btn.disabled = false;
        btnText.textContent = 'Calculate';
        Swal.fire({
            icon: 'error',
            title: 'Connection Error',
            text: 'Could not connect to nutrition engine. Please check your network.',
            background: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#121721',
            color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
            confirmButtonColor: 'var(--lime, #c7ff22)',
        });
    }
}

function applyCustomSwap() {
    if (!calculatedCustomMealData) return;
    confirmSwapRecipe(
        calculatedCustomMealData.food_items,
        calculatedCustomMealData.calories,
        calculatedCustomMealData.protein_g,
        calculatedCustomMealData.carbs_g,
        calculatedCustomMealData.fat_g
    );
}

// Send swap action to backend
async function confirmSwapRecipe(foodItems, cals, pro, carbs, fat) {
    if (!currentSwappingMeal) return;

    const mealId = currentSwappingMeal.mealId;
    const dayNum = currentSwappingMeal.dayNum;
    const mealType = currentSwappingMeal.type;

    try {
        const body = new URLSearchParams({
            action: 'swap_meal',
            meal_id: mealId.toString(),
            food_items: foodItems,
            calories: cals.toString(),
            protein_g: pro.toString(),
            carbs_g: carbs.toString(),
            fat_g: fat.toString(),
            csrf_token: FT_CSRF_TOKEN
        });

        const res = await fetch('index.php?page=diet', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: body.toString()
        });

        const data = await res.json();
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
            throw new Error(data.error || 'Could not swap meal.');
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

