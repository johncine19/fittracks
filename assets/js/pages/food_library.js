/**
 * Food Library Controller
 * Extracted from food_library.php
 */

const FT_CSRF_TOKEN = (window.FOOD_LIBRARY_CONFIG && window.FOOD_LIBRARY_CONFIG.csrfToken)
    ? window.FOOD_LIBRARY_CONFIG.csrfToken
    : (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
const FT_CURRENT_USER_ROLE = (window.FOOD_LIBRARY_CONFIG && window.FOOD_LIBRARY_CONFIG.currentUserRole)
    ? window.FOOD_LIBRARY_CONFIG.currentUserRole
    : '';
const FT_CURRENT_GYM_ID = (window.FOOD_LIBRARY_CONFIG && window.FOOD_LIBRARY_CONFIG.currentGymId)
    ? window.FOOD_LIBRARY_CONFIG.currentGymId
    : 0;
const FT_FOODS_MAP = (window.FOOD_LIBRARY_CONFIG && window.FOOD_LIBRARY_CONFIG.foodsMap)
    ? window.FOOD_LIBRARY_CONFIG.foodsMap
    : {};

let currentViewIngredientsFood = null;

async function openViewIngredientsModal(foodId) {
    let food = FT_FOODS_MAP[foodId];
    if (!food || !food.ingredients_data) {
        try {
            const res = await fetch('index.php?page=food_library&tab=library&action=get_ingredients&food_id=' + encodeURIComponent(foodId));
            const data = await res.json();
            if (data.success && data.food) {
                food = data.food;
                FT_FOODS_MAP[foodId] = food;
            }
        } catch (e) {
            console.error('Failed to fetch ingredients', e);
        }
    }

    if (!food) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Could not load ingredients for this dish.',
            background: 'var(--bg)',
            color: 'var(--ink)'
        });
        return;
    }

    currentViewIngredientsFood = food;

    // Populate Hero Header
    const heroImg = document.getElementById('ing-modal-hero-img');
    const mealBadge = document.getElementById('ing-modal-meal-type');
    const dietBadge = document.getElementById('ing-modal-diet-type');
    const titleEl = document.getElementById('ing-modal-title');
    const servingEl = document.getElementById('ing-modal-serving');
    const calEl = document.getElementById('ing-modal-cals');
    const proteinEl = document.getElementById('ing-modal-protein');
    const carbsEl = document.getElementById('ing-modal-carbs');
    const fatEl = document.getElementById('ing-modal-fat');

    if (heroImg) heroImg.src = food.photo_url || 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80';
    if (mealBadge) mealBadge.textContent = food.meal_type || 'Meal';
    if (dietBadge) {
        if (food.dietary_restriction && food.dietary_restriction !== 'none') {
            dietBadge.textContent = food.dietary_restriction;
            dietBadge.style.display = 'inline-block';
        } else {
            dietBadge.style.display = 'none';
        }
    }
    if (titleEl) titleEl.textContent = food.name;
    if (servingEl) servingEl.textContent = food.serving_size || '1 serving';
    if (calEl) calEl.textContent = (food.calories || 0) + ' kcal';
    if (proteinEl) proteinEl.textContent = (food.protein_g || 0) + 'g';
    if (carbsEl) carbsEl.textContent = (food.carbs_g || 0) + 'g';
    if (fatEl) fatEl.textContent = (food.fat_g || 0) + 'g';

    // Populate Single Unified Ingredients List (2x2 Grid with Show All)
    const listEl = document.getElementById('ing-modal-items-list');
    const countEl = document.getElementById('ing-modal-items-count');
    const items = food.ingredients_data?.all || [];
    const INITIAL_LIMIT = 4;
    const hasMore = items.length > INITIAL_LIMIT;
    isIngredientsExpanded = false;

    if (countEl) countEl.textContent = items.length + (items.length === 1 ? ' item' : ' items');
    if (listEl) {
        if (items.length === 0) {
            listEl.innerHTML = '<div style="grid-column: 1 / -1; font-size: 12.5px; color: var(--muted); font-style: italic; padding: 6px 0;">No ingredients listed for this food item.</div>';
        } else {
            listEl.innerHTML = items.map((item, idx) => {
                const isExtra = idx >= INITIAL_LIMIT;
                const hideStyle = isExtra ? 'display: none;' : '';
                return `
                    <div class="ing-modal-item ${isExtra ? 'ing-modal-item-extra' : ''}" style="${hideStyle}">
                        <div style="display: flex; align-items: center; gap: 6px; min-width: 0; flex: 1;">
                            <span style="width: 5px; height: 5px; border-radius: 50%; background: #16a34a; flex-shrink: 0;"></span>
                            <span class="ing-modal-item-name" title="${escapeHtml(item.name)}">${escapeHtml(item.name)}</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 4px; flex-shrink: 0;">
                            <span class="ing-modal-item-measure">
                                ${escapeHtml(item.measure || (item.amount ? item.amount + ' ' + (item.unit || '') : '1 portion'))}
                            </span>
                            ${item.is_optional ? `<span class="ing-modal-item-opt-badge">Opt</span>` : ''}
                        </div>
                    </div>
                `;
            }).join('');
        }
    }

    const toggleContainer = document.getElementById('ing-modal-show-all-container');
    const toggleText = document.getElementById('ing-modal-show-all-text');
    const toggleIcon = document.getElementById('ing-modal-show-all-icon');
    if (toggleContainer) {
        if (hasMore) {
            toggleContainer.style.display = 'block';
            if (toggleText) toggleText.textContent = `Show all (${items.length})`;
            if (toggleIcon) toggleIcon.style.transform = 'rotate(0deg)';
        } else {
            toggleContainer.style.display = 'none';
        }
    }

    // Populate Preparation Notes
    const descBox = document.getElementById('ing-modal-desc-box');
    const descEl = document.getElementById('ing-modal-desc');
    if (food.recipe_desc && food.recipe_desc.trim()) {
        if (descBox) descBox.style.display = 'block';
        if (descEl) descEl.textContent = food.recipe_desc.trim();
    } else {
        if (descBox) descBox.style.display = 'none';
    }

    // Setup Edit Button in Footer if permitted
    const editContainer = document.getElementById('ing-modal-footer-edit-container');
    const isCustom = !food.is_global && (food.gym_id !== null && food.gym_id !== undefined && food.gym_id !== '');
    const canEdit = (FT_CURRENT_USER_ROLE === 'platform_admin' || (FT_CURRENT_USER_ROLE === 'gym_owner' && isCustom));
    if (editContainer) {
        if (canEdit) {
            editContainer.innerHTML = `
                <button type="button" onclick="editFromIngredientsModal()" class="btn-sm btn-ghost btn-ing-modal-edit" style="color: var(--lime); border-color: rgba(132,204,22,0.35);">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                    <span>Edit Food & Ingredients</span>
                </button>
            `;
        } else {
            editContainer.innerHTML = '';
        }
    }

    // Show modal
    const modal = document.getElementById('modal-food-ingredients');
    if (modal) {
        if (modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
    document.body.style.overflow = 'hidden';
}

let isIngredientsExpanded = false;

function toggleIngredientsShowAll() {
    isIngredientsExpanded = !isIngredientsExpanded;
    const extraItems = document.querySelectorAll('.ing-modal-item-extra');
    const textEl = document.getElementById('ing-modal-show-all-text');
    const iconEl = document.getElementById('ing-modal-show-all-icon');
    const totalCount = currentViewIngredientsFood?.ingredients_data?.all?.length || 0;

    extraItems.forEach(el => {
        el.style.display = isIngredientsExpanded ? 'flex' : 'none';
    });

    if (textEl) {
        textEl.textContent = isIngredientsExpanded ? 'Show less' : `Show all (${totalCount})`;
    }
    if (iconEl) {
        iconEl.style.transform = isIngredientsExpanded ? 'rotate(180deg)' : 'rotate(0deg)';
    }
}

function closeViewIngredientsModal() {
    isIngredientsExpanded = false;
    const modal = document.getElementById('modal-food-ingredients');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('active');
    }
    document.body.style.overflow = '';
}

function editFromIngredientsModal() {
    if (currentViewIngredientsFood) {
        const foodToEdit = currentViewIngredientsFood;
        closeViewIngredientsModal();
        openEditFoodModal(foodToEdit);
    }
}

let currentFoodPhotoData = {
    foodId: null,
    foodName: '',
    mealType: '',
    customUrl: '',
    autoUrl: '',
    activeTab: 'upload',
    selectedFile: null
};

function openFoodPhotoModal(foodId, foodName, mealType, customUrl, currentUrl) {
    currentFoodPhotoData = {
        foodId: foodId,
        foodName: foodName,
        mealType: mealType,
        customUrl: customUrl || '',
        autoUrl: currentUrl || '',
        activeTab: (customUrl ? 'url' : 'upload'),
        selectedFile: null
    };

    const modal = document.getElementById('modal-food-photo');
    const nameEl = document.getElementById('modal-photo-preview-name');
    const previewImg = document.getElementById('modal-photo-preview-img');
    const badgeEl = document.getElementById('modal-photo-preview-badge');
    const urlInput = document.getElementById('modal-food-photo-url-input');
    const fileLabel = document.getElementById('food-photo-file-label');
    const fileInput = document.getElementById('modal-food-photo-file');
    const errEl = document.getElementById('modal-photo-error');

    if (nameEl) nameEl.textContent = foodName;
    if (previewImg) previewImg.src = currentUrl || 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80';
    if (urlInput) urlInput.value = customUrl || '';
    if (fileInput) fileInput.value = '';
    if (fileLabel) fileLabel.textContent = 'Click to select photo';
    if (errEl) {
        errEl.textContent = '';
        errEl.style.display = 'none';
    }

    if (customUrl) {
        if (badgeEl) badgeEl.textContent = customUrl.includes('imagekit') ? 'IMAGEKIT PHOTO' : 'CUSTOM WEB PHOTO';
        switchFoodPhotoTab('url');
    } else {
        if (badgeEl) badgeEl.textContent = 'AUTO-MATCHED PHOTO';
        switchFoodPhotoTab('upload');
    }

    if (modal) {
        if (modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
    document.body.style.overflow = 'hidden';
}

function closeFoodPhotoModal() {
    const modal = document.getElementById('modal-food-photo');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('active');
    }
    document.body.style.overflow = '';
    currentFoodPhotoData.selectedFile = null;
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeFoodPhotoModal();
        closeViewIngredientsModal();
    }
});

function switchFoodPhotoTab(tab) {
    currentFoodPhotoData.activeTab = tab;

    ['upload', 'url', 'reset'].forEach(t => {
        const btn = document.getElementById('tab-btn-' + t);
        const panel = document.getElementById('panel-tab-' + t);
        if (btn) btn.classList.toggle('active', t === tab);
        if (panel) panel.style.display = (t === tab) ? 'block' : 'none';
    });

    const badgeEl = document.getElementById('modal-photo-preview-badge');
    const previewImg = document.getElementById('modal-photo-preview-img');

    if (tab === 'reset') {
        if (previewImg) previewImg.src = currentFoodPhotoData.autoUrl;
        if (badgeEl) badgeEl.textContent = 'AUTO-MATCHED PHOTO (PREVIEW)';
    } else if (tab === 'url') {
        const val = document.getElementById('modal-food-photo-url-input')?.value?.trim();
        if (val) {
            if (previewImg) previewImg.src = val;
            if (badgeEl) badgeEl.textContent = 'CUSTOM URL PREVIEW';
        } else {
            if (previewImg) previewImg.src = currentFoodPhotoData.autoUrl;
            if (badgeEl) badgeEl.textContent = currentFoodPhotoData.customUrl ? 'CUSTOM PHOTO' : 'AUTO-MATCHED PHOTO';
        }
    } else if (tab === 'upload') {
        if (currentFoodPhotoData.selectedFile) {
            if (badgeEl) badgeEl.textContent = 'IMAGEKIT UPLOAD PREVIEW';
        } else {
            if (previewImg) previewImg.src = currentFoodPhotoData.autoUrl;
            if (badgeEl) badgeEl.textContent = currentFoodPhotoData.customUrl ? 'CUSTOM PHOTO' : 'AUTO-MATCHED PHOTO';
        }
    }
}

function handleFoodPhotoSelected(input) {
    const file = input.files?.[0];
    if (!file) return;

    if (!file.type.startsWith('image/')) {
        Swal.fire({
            icon: 'error',
            title: 'Invalid File',
            text: 'Please select an image file (PNG, JPG, WebP).',
            background: 'var(--bg)',
            color: 'var(--ink)'
        });
        input.value = '';
        return;
    }

    if (file.size > 5 * 1024 * 1024) {
        Swal.fire({
            icon: 'error',
            title: 'File Too Large',
            text: 'Image size must be under 5MB.',
            background: 'var(--bg)',
            color: 'var(--ink)'
        });
        input.value = '';
        return;
    }

    currentFoodPhotoData.selectedFile = file;
    const label = document.getElementById('food-photo-file-label');
    if (label) label.textContent = 'Selected: ' + file.name;

    const reader = new FileReader();
    reader.onload = function(e) {
        const previewImg = document.getElementById('modal-photo-preview-img');
        const badgeEl = document.getElementById('modal-photo-preview-badge');
        if (previewImg) previewImg.src = e.target.result;
        if (badgeEl) badgeEl.textContent = 'IMAGEKIT UPLOAD PREVIEW';
    };
    reader.readAsDataURL(file);
}

function handleFoodPhotoUrlInput(val) {
    const trimmed = val.trim();
    const previewImg = document.getElementById('modal-photo-preview-img');
    const badgeEl = document.getElementById('modal-photo-preview-badge');

    if (trimmed) {
        if (previewImg) previewImg.src = trimmed;
        if (badgeEl) badgeEl.textContent = 'CUSTOM URL PREVIEW';
    } else {
        if (previewImg) previewImg.src = currentFoodPhotoData.autoUrl;
        if (badgeEl) badgeEl.textContent = 'AUTO-MATCHED PHOTO';
    }
}

async function saveFoodPhoto() {
    const saveBtn = document.getElementById('btn-modal-save-photo');
    const errEl = document.getElementById('modal-photo-error');
    if (errEl) {
        errEl.textContent = '';
        errEl.style.display = 'none';
    }

    const mode = currentFoodPhotoData.activeTab;
    const foodId = currentFoodPhotoData.foodId;

    if (!foodId) {
        alert('Invalid food item selected.');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'update_food_photo');
    formData.append('food_id', foodId);
    formData.append('photo_type', mode);
    formData.append('csrf_token', FT_CSRF_TOKEN);

    if (mode === 'upload') {
        if (!currentFoodPhotoData.selectedFile) {
            if (errEl) {
                errEl.textContent = 'Please choose an image file to upload, or switch to Image URL / Reset.';
                errEl.style.display = 'block';
            }
            return;
        }
        formData.append('photo_file', currentFoodPhotoData.selectedFile);
    } else if (mode === 'url') {
        const urlVal = document.getElementById('modal-food-photo-url-input')?.value?.trim();
        if (!urlVal) {
            if (errEl) {
                errEl.textContent = 'Please enter a valid image URL, or switch to Reset to Auto.';
                errEl.style.display = 'block';
            }
            return;
        }
        formData.append('photo_url', urlVal);
    }

    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';
    }

    try {
        const res = await fetch('index.php?page=food_library&tab=library', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const data = await res.json();
        if (!data.success) {
            throw new Error(data.error || 'Failed to update photo');
        }

        // Success! Update card image dynamically
        const cardImg = document.getElementById('food-card-img-' + foodId);
        if (cardImg && data.resolved_url) {
            cardImg.src = data.resolved_url;
        }

        closeFoodPhotoModal();

        Swal.fire({
            icon: 'success',
            title: 'Photo Updated!',
            text: 'Meal photo has been customized successfully.',
            background: 'var(--bg)',
            color: 'var(--ink)',
            confirmButtonColor: 'var(--lime-dark)',
            timer: 2000,
            timerProgressBar: true
        });

    } catch (err) {
        if (errEl) {
            errEl.textContent = err.message;
            errEl.style.display = 'block';
        } else {
            alert(err.message);
        }
    } finally {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Photo';
        }
    }
}
function switchSwalFoodTab(tabNum) {
    const tab1Btn = document.getElementById('swal-tab-btn-1');
    const tab2Btn = document.getElementById('swal-tab-btn-2');
    const panel1 = document.getElementById('swal-tab-panel-1');
    const panel2 = document.getElementById('swal-tab-panel-2');

    if (tabNum === 1) {
        if (tab1Btn) tab1Btn.classList.add('active');
        if (tab2Btn) tab2Btn.classList.remove('active');
        if (panel1) panel1.style.display = 'flex';
        if (panel2) panel2.style.display = 'none';
    } else {
        if (tab2Btn) tab2Btn.classList.add('active');
        if (tab1Btn) tab1Btn.classList.remove('active');
        if (panel2) panel2.style.display = 'flex';
        if (panel1) panel1.style.display = 'none';
    }
}

function openAddFoodModal() {
    Swal.fire({
        title: 'Add Custom Food Item',
        width: 'min(540px, calc(100vw - 24px))',
        background: 'var(--bg)',
        color: 'var(--ink)',
        html: `
            <div class="swal-tabs-bar">
                <button type="button" class="swal-tab-btn active" id="swal-tab-btn-1" onclick="switchSwalFoodTab(1)">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M9 12h6"/><path d="M9 16h6"/></svg>
                    <span>1. General & Macros</span>
                </button>
                <button type="button" class="swal-tab-btn" id="swal-tab-btn-2" onclick="switchSwalFoodTab(2)">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    <span>2. Photo & Details</span>
                </button>
            </div>

            <form id="foodItemForm" method="post" enctype="multipart/form-data" style="text-align: left;">
                <input type="hidden" name="csrf_token" value="${FT_CSRF_TOKEN}">
                <input type="hidden" name="action" value="create">
                
                <!-- TAB 1: General Info & Macros -->
                <div id="swal-tab-panel-1" class="swal-tab-panel" style="display: flex;">
                    <label class="swal-field-label">Dish / Product Name *
                        <input type="text" name="name" class="swal-form-control" placeholder="e.g. High Protein Chicken Teriyaki Bowl" required style="margin-top: 4px;">
                    </label>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <label class="swal-field-label">Meal Type *
                            <select name="meal_type" class="swal-form-control" required style="margin-top: 4px;">
                                <option value="Breakfast">Breakfast</option>
                                <option value="Lunch" selected>Lunch</option>
                                <option value="Dinner">Dinner</option>
                                <option value="Snack">Snack</option>
                            </select>
                        </label>
                        <label class="swal-field-label">Dietary Type
                            <select name="dietary_restriction" class="swal-form-control" style="margin-top: 4px;">
                                <option value="none">None</option>
                                <option value="vegetarian">Vegetarian</option>
                                <option value="vegan">Vegan</option>
                                <option value="pescatarian">Pescatarian</option>
                                <option value="halal">Halal</option>
                                <option value="gluten-free">Gluten Free</option>
                                <option value="keto">Keto</option>
                                <option value="paleo">Paleo</option>
                                <option value="nut-allergy">Nut Allergy</option>
                                <option value="dairy-free">Dairy Free</option>
                            </select>
                        </label>
                    </div>

                    <label class="swal-field-label">Serving Portion
                        <input type="text" name="serving_size" class="swal-form-control" value="1 serving" placeholder="e.g. 1 plate (200g chicken)" style="margin-top: 4px;">
                    </label>

                    <div class="swal-card-box">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="font-size: 12px; font-weight: 700; color: var(--lime);">Nutrition per Serving</span>
                            <span style="font-size: 11px; color: var(--muted);">Auto-calculates kcal</span>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 8px;">
                            <div>
                                <span style="font-size: 10px; color: var(--muted); display: block; margin-bottom: 3px; font-weight: 600;">Calories</span>
                                <input type="number" name="calories" id="swal_cals" value="450" required class="swal-macro-input">
                            </div>
                            <div>
                                <span style="font-size: 10px; color: var(--muted); display: block; margin-bottom: 3px; font-weight: 600;">Protein (g)</span>
                                <input type="number" step="0.1" name="protein_g" id="swal_p" value="35" oninput="calculateSwalCals()" required class="swal-macro-input">
                            </div>
                            <div>
                                <span style="font-size: 10px; color: var(--muted); display: block; margin-bottom: 3px; font-weight: 600;">Carbs (g)</span>
                                <input type="number" step="0.1" name="carbs_g" id="swal_c" value="45" oninput="calculateSwalCals()" required class="swal-macro-input">
                            </div>
                            <div>
                                <span style="font-size: 10px; color: var(--muted); display: block; margin-bottom: 3px; font-weight: 600;">Fat (g)</span>
                                <input type="number" step="0.1" name="fat_g" id="swal_f" value="12" oninput="calculateSwalCals()" required class="swal-macro-input">
                            </div>
                        </div>
                    </div>

                    ${FT_CURRENT_USER_ROLE === 'platform_admin' ? `
                        <label style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--ink); cursor: pointer;">
                            <input type="checkbox" name="is_global" value="1">
                            <span>Save as platform global staple (available to all gyms)</span>
                        </label>
                    ` : ''}

                    <button type="button" onclick="switchSwalFoodTab(2)" class="swal-tab-next-btn">
                        <span>Continue to Photo & Description</span>
                        <span style="color: var(--lime); display: inline-flex; align-items: center; gap: 4px;">Next <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                    </button>
                </div>

                <!-- TAB 2: Photo & Details -->
                <div id="swal-tab-panel-2" class="swal-tab-panel" style="display: none;">
                    <div class="swal-card-box">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="font-size: 12px; font-weight: 700; color: var(--lime);">Meal Image (Optional)</span>
                            <span style="font-size: 10.5px; color: var(--muted); background: color-mix(in srgb, var(--ink) 8%, transparent); padding: 2px 6px; border-radius: 4px;">ImageKit CDN</span>
                        </div>
                        <label style="display: block; font-size: 11.5px; color: var(--muted); margin-bottom: 4px; font-weight: 600;">Upload Photo File (PNG, JPG, WebP):</label>
                        <input type="file" name="food_image" accept="image/png,image/jpeg,image/webp,image/jpg" class="swal-form-control" style="padding: 7px 8px !important; font-size: 12px !important;">
                        
                        <div style="text-align: center; margin: 8px 0; font-size: 11px; color: var(--muted);">— OR paste web image URL —</div>
                        <input type="url" name="image_url" placeholder="https://images.unsplash.com/..." class="swal-form-control" style="font-size: 12px !important;">
                    </div>

                    <label class="swal-field-label">Ingredients & Portions (One per line)
                        <span style="display:block; font-size: 11px; color: var(--muted); font-weight: normal; margin-top: 2px;">
                            e.g. <span class="swal-hint-code">Sitaw — 150 g</span> or <span class="swal-hint-code">Garlic — 2 cloves (optional)</span>
                        </span>
                        <textarea name="ingredients" rows="4" placeholder="Sitaw / Yard-long beans — 150 g&#10;Firm tofu — 100 g&#10;Quinoa — 1/2 cup&#10;Garlic — 2 cloves&#10;Soy sauce — 1 tbsp&#10;Vinegar — 1 tbsp&#10;Cooking oil — 1 tsp" class="swal-form-control" style="margin-top: 4px; min-height: 90px; font-family: monospace, sans-serif; font-size: 12px; resize: vertical;"></textarea>
                    </label>

                    <label class="swal-field-label" style="margin-top: 4px;">Description & Preparation Notes
                        <textarea name="recipe_desc" rows="2" placeholder="Brief notes on preparation or cooking instructions..." class="swal-form-control" style="margin-top: 4px; min-height: 60px; resize: vertical;"></textarea>
                    </label>

                    <button type="button" onclick="switchSwalFoodTab(1)" style="background: transparent; border: none; color: var(--muted); font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; align-self: flex-start; padding: 4px 0;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                        <span style="text-decoration: underline;">Back to General Info & Macros</span>
                    </button>
                </div>
            </form>
        `,
        showCancelButton: true,
        confirmButtonText: 'Save Food Item',
        confirmButtonColor: 'var(--lime-dark)',
        cancelButtonColor: 'var(--line)',
        preConfirm: () => {
            const form = document.getElementById('foodItemForm');
            if (!form.name.value.trim()) {
                switchSwalFoodTab(1);
                Swal.showValidationMessage('Dish / Product name is required');
                return false;
            }
            form.submit();
        }
    });
}

function openEditFoodModal(food) {
    Swal.fire({
        title: 'Edit Food Item',
        width: 'min(540px, calc(100vw - 24px))',
        background: 'var(--bg)',
        color: 'var(--ink)',
        html: `
            <div class="swal-tabs-bar">
                <button type="button" class="swal-tab-btn active" id="swal-tab-btn-1" onclick="switchSwalFoodTab(1)">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M9 12h6"/><path d="M9 16h6"/></svg>
                    <span>1. General & Macros</span>
                </button>
                <button type="button" class="swal-tab-btn" id="swal-tab-btn-2" onclick="switchSwalFoodTab(2)">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    <span>2. Photo & Details</span>
                </button>
            </div>

            <form id="editFoodForm" method="post" enctype="multipart/form-data" style="text-align: left;">
                <input type="hidden" name="csrf_token" value="${FT_CSRF_TOKEN}">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="food_id" value="${food.food_id}">
                
                <!-- TAB 1: General Info & Macros -->
                <div id="swal-tab-panel-1" class="swal-tab-panel" style="display: flex;">
                    <label class="swal-field-label">Dish / Product Name *
                        <input type="text" name="name" class="swal-form-control" value="${escapeHtml(food.name)}" required style="margin-top: 4px;">
                    </label>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <label class="swal-field-label">Meal Type *
                            <select name="meal_type" class="swal-form-control" required style="margin-top: 4px;">
                                <option value="Breakfast" ${food.meal_type === 'Breakfast' ? 'selected' : ''}>Breakfast</option>
                                <option value="Lunch" ${food.meal_type === 'Lunch' ? 'selected' : ''}>Lunch</option>
                                <option value="Dinner" ${food.meal_type === 'Dinner' ? 'selected' : ''}>Dinner</option>
                                <option value="Snack" ${food.meal_type === 'Snack' ? 'selected' : ''}>Snack</option>
                            </select>
                        </label>
                        <label class="swal-field-label">Dietary Type
                            <select name="dietary_restriction" class="swal-form-control" style="margin-top: 4px;">
                                <option value="none" ${(!food.dietary_restriction || food.dietary_restriction === 'none') ? 'selected' : ''}>None</option>
                                <option value="vegetarian" ${food.dietary_restriction === 'vegetarian' ? 'selected' : ''}>Vegetarian</option>
                                <option value="vegan" ${food.dietary_restriction === 'vegan' ? 'selected' : ''}>Vegan</option>
                                <option value="pescatarian" ${food.dietary_restriction === 'pescatarian' ? 'selected' : ''}>Pescatarian</option>
                                <option value="halal" ${food.dietary_restriction === 'halal' ? 'selected' : ''}>Halal</option>
                                <option value="gluten-free" ${(food.dietary_restriction === 'gluten-free' || food.dietary_restriction === 'gluten free') ? 'selected' : ''}>Gluten Free</option>
                                <option value="keto" ${food.dietary_restriction === 'keto' ? 'selected' : ''}>Keto</option>
                                <option value="paleo" ${food.dietary_restriction === 'paleo' ? 'selected' : ''}>Paleo</option>
                                <option value="nut-allergy" ${(food.dietary_restriction === 'nut-allergy' || food.dietary_restriction === 'nut allergy') ? 'selected' : ''}>Nut Allergy</option>
                                <option value="dairy-free" ${(food.dietary_restriction === 'dairy-free' || food.dietary_restriction === 'dairy free') ? 'selected' : ''}>Dairy Free</option>
                            </select>
                        </label>
                    </div>

                    <label class="swal-field-label">Serving Portion
                        <input type="text" name="serving_size" class="swal-form-control" value="${escapeHtml(food.serving_size || '')}" style="margin-top: 4px;">
                    </label>

                    <div class="swal-card-box">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="font-size: 12px; font-weight: 700; color: var(--lime);">Nutrition per Serving</span>
                            <span style="font-size: 11px; color: var(--muted);">Auto-calculates kcal</span>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 8px;">
                            <div>
                                <span style="font-size: 10px; color: var(--muted); display: block; margin-bottom: 3px; font-weight: 600;">Calories</span>
                                <input type="number" name="calories" id="swal_cals" value="${food.calories}" required class="swal-macro-input">
                            </div>
                            <div>
                                <span style="font-size: 10px; color: var(--muted); display: block; margin-bottom: 3px; font-weight: 600;">Protein (g)</span>
                                <input type="number" step="0.1" name="protein_g" id="swal_p" value="${food.protein_g}" oninput="calculateSwalCals()" required class="swal-macro-input">
                            </div>
                            <div>
                                <span style="font-size: 10px; color: var(--muted); display: block; margin-bottom: 3px; font-weight: 600;">Carbs (g)</span>
                                <input type="number" step="0.1" name="carbs_g" id="swal_c" value="${food.carbs_g}" oninput="calculateSwalCals()" required class="swal-macro-input">
                            </div>
                            <div>
                                <span style="font-size: 10px; color: var(--muted); display: block; margin-bottom: 3px; font-weight: 600;">Fat (g)</span>
                                <input type="number" step="0.1" name="fat_g" id="swal_f" value="${food.fat_g}" oninput="calculateSwalCals()" required class="swal-macro-input">
                            </div>
                        </div>
                    </div>

                    <button type="button" onclick="switchSwalFoodTab(2)" class="swal-tab-next-btn">
                        <span>Continue to Photo & Description</span>
                        <span style="color: var(--lime); display: inline-flex; align-items: center; gap: 4px;">Next <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                    </button>
                </div>

                <!-- TAB 2: Photo & Details -->
                <div id="swal-tab-panel-2" class="swal-tab-panel" style="display: none;">
                    <div class="swal-card-box">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="font-size: 12px; font-weight: 700; color: var(--lime);">Meal Image</span>
                            <button type="button" onclick="Swal.close(); openFoodPhotoModal(${food.food_id}, ${JSON.stringify(food.name)}, ${JSON.stringify(food.meal_type)}, ${JSON.stringify(food.image_url || '')}, ${JSON.stringify(food.image_url || '')});" style="background: rgba(132, 204, 22, 0.15); border: 1px solid rgba(132, 204, 22, 0.3); color: var(--lime); font-size: 11px; font-weight: 700; border-radius: 4px; padding: 4px 9px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                <span>Open Photo Studio</span>
                            </button>
                        </div>
                        <label style="display: block; font-size: 11.5px; color: var(--muted); margin-bottom: 4px; font-weight: 600;">Upload New Photo (ImageKit):</label>
                        <input type="file" name="food_image" accept="image/png,image/jpeg,image/webp,image/jpg" class="swal-form-control" style="padding: 7px 8px !important; font-size: 12px !important;">
                        
                        <div style="text-align: center; margin: 8px 0; font-size: 11px; color: var(--muted);">— OR web image link —</div>
                        <input type="url" name="image_url" value="${escapeHtml(food.image_url || '')}" placeholder="https://..." class="swal-form-control" style="font-size: 12px !important;">
                    </div>

                    <label class="swal-field-label">Ingredients & Portions (One per line)
                        <span style="display:block; font-size: 11px; color: var(--muted); font-weight: normal; margin-top: 2px;">
                            e.g. <span class="swal-hint-code">Sitaw — 150 g</span> or <span class="swal-hint-code">Garlic — 2 cloves (optional)</span>
                        </span>
                        <textarea name="ingredients" rows="4" placeholder="Sitaw / Yard-long beans — 150 g&#10;Firm tofu — 100 g&#10;Quinoa — 1/2 cup&#10;Garlic — 2 cloves" class="swal-form-control" style="margin-top: 4px; min-height: 90px; font-family: monospace, sans-serif; font-size: 12px; resize: vertical;">${escapeHtml(food.ingredients_text || '')}</textarea>
                    </label>

                    <label class="swal-field-label" style="margin-top: 4px;">Description & Preparation Notes
                        <textarea name="recipe_desc" rows="2" placeholder="Brief notes on preparation or cooking instructions..." class="swal-form-control" style="margin-top: 4px; min-height: 60px; resize: vertical;">${escapeHtml(food.recipe_desc || '')}</textarea>
                    </label>

                    <button type="button" onclick="switchSwalFoodTab(1)" style="background: transparent; border: none; color: var(--muted); font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; align-self: flex-start; padding: 4px 0;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                        <span style="text-decoration: underline;">Back to General Info & Macros</span>
                    </button>
                </div>
            </form>
        `,
        showCancelButton: true,
        confirmButtonText: 'Update Food Item',
        confirmButtonColor: 'var(--lime-dark)',
        cancelButtonColor: 'var(--line)',
        preConfirm: () => {
            const form = document.getElementById('editFoodForm');
            if (!form.name.value.trim()) {
                switchSwalFoodTab(1);
                Swal.showValidationMessage('Dish / Product name is required');
                return false;
            }
            form.submit();
        }
    });
}

function calculateSwalCals() {
    const p = parseFloat(document.getElementById('swal_p')?.value) || 0;
    const c = parseFloat(document.getElementById('swal_c')?.value) || 0;
    const f = parseFloat(document.getElementById('swal_f')?.value) || 0;
    const cals = document.getElementById('swal_cals');
    if (cals) {
        cals.value = Math.round((p * 4) + (c * 4) + (f * 9));
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Open Food Facts Live Search
function executeOffSearch() {
    const query = document.getElementById('off_search_query')?.value.trim();
    const status = document.getElementById('off_search_status');
    const grid = document.getElementById('off_results_grid');

    if (!query) {
        if (status) status.innerText = 'Please enter a search keyword.';
        return;
    }

    if (status) status.innerHTML = 'Searching Open Food Facts database... ⏳';
    if (grid) grid.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 40px;"><div class="spinner"></div> Searching global databases...</div>';

    fetch('index.php?page=food_lookup&action=openfoodfacts&query=' + encodeURIComponent(query))
        .then(res => res.json())
        .then(data => {
            if (!data.success || !data.products || data.products.length === 0) {
                if (status) status.innerText = '';
                grid.innerHTML = `<div style="grid-column: 1/-1; text-align: center; padding: 40px; color: var(--muted);">No matching grocery products found for "${escapeHtml(query)}". Try another brand or product name.</div>`;
                return;
            }

            if (status) status.innerText = `Found ${data.products.length} products on Open Food Facts.`;

            grid.innerHTML = data.products.map((p, idx) => {
                const img = p.image || 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80';
                return `
                    <div style="background: var(--surface); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; display: flex; flex-direction: column;">
                        <div style="height: 140px; background: #000; position: relative;">
                            <img src="${img}" alt="" style="width: 100%; height: 100%; object-fit: cover; opacity: 0.9;" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80';">
                            ${p.brand ? `<span style="position: absolute; top: 10px; left: 10px; background: rgba(0,0,0,0.8); color: #38bdf8; font-size: 10.5px; font-weight: 700; padding: 2px 8px; border-radius: 4px; border: 1px solid rgba(56, 189, 248, 0.3);">${escapeHtml(p.brand)}</span>` : ''}
                            <span style="position: absolute; bottom: 8px; right: 10px; background: rgba(0,0,0,0.8); color: var(--lime); font-size: 12px; font-weight: 800; padding: 2px 7px; border-radius: 4px;">
                                ${p.calories_100g} kcal / 100g
                            </span>
                        </div>
                        <div style="padding: 14px; flex: 1; display: flex; flex-direction: column;">
                            <h4 style="margin: 0 0 6px; font-size: 14px; font-weight: 700; color: var(--ink); line-height: 1.35;">${escapeHtml(p.name)}</h4>
                            <span style="font-size: 11.5px; color: var(--muted); margin-bottom: 12px;">Portion: ${escapeHtml(p.serving_size || '100g')}</span>

                            <div style="margin-top: auto; padding-top: 10px; border-top: 1px solid rgba(255,255,255,0.06); display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px; text-align: center; font-size: 11px; margin-bottom: 14px;">
                                <div style="background: var(--bg); padding: 5px; border-radius: 6px; border: 1px solid var(--line);">
                                    <span style="display:block; font-size: 9px; color: var(--muted);">Protein</span>
                                    <span style="color: var(--ink); font-weight: 700;">${p.protein_100g}g</span>
                                </div>
                                <div style="background: var(--bg); padding: 5px; border-radius: 6px; border: 1px solid var(--line);">
                                    <span style="display:block; font-size: 9px; color: var(--muted);">Carbs</span>
                                    <span style="color: var(--ink); font-weight: 700;">${p.carbs_100g}g</span>
                                </div>
                                <div style="background: var(--bg); padding: 5px; border-radius: 6px; border: 1px solid var(--line);">
                                    <span style="display:block; font-size: 9px; color: var(--muted);">Fat</span>
                                    <span style="color: var(--ink); font-weight: 700;">${p.fat_100g}g</span>
                                </div>
                            </div>

                            <button onclick='importProductToGym(${JSON.stringify(p).replace(/'/g, "&#39;")})' class="btn" style="width: 100%; background: rgba(132, 204, 22, 0.15); color: var(--lime); border: 1px solid rgba(132, 204, 22, 0.4); font-size: 12.5px; font-weight: 700; padding: 8px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                <span>Import to Gym Menu</span>
                            </button>
                        </div>
                    </div>
                `;
            }).join('');
        })
        .catch(err => {
            if (status) status.innerText = '';
            grid.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 40px; color: var(--danger);">Network error querying Open Food Facts. Please try again.</div>';
        });
}

function importProductToGym(product) {
    Swal.fire({
        title: 'Import to Gym Library',
        width: 'min(460px, calc(100vw - 20px))',
        background: 'var(--bg)',
        color: 'var(--ink)',
        html: `
            <div style="text-align: left; margin-top: 10px;">
                <p style="margin: 0 0 12px; font-size: 13.5px; color: var(--ink);">
                    Importing <strong>${escapeHtml(product.name)}</strong> into your gym food library.
                </p>
                <label style="display:block; font-size: 13px; color: var(--muted); font-weight: 600; margin-bottom: 6px;">
                    Select Default Meal Type:
                </label>
                <select id="importMealType" class="swal-form-control" style="padding: 10px 12px !important; font-size: 13.5px !important;">
                    <option value="Snack">Snack</option>
                    <option value="Breakfast">Breakfast</option>
                    <option value="Lunch">Lunch</option>
                    <option value="Dinner">Dinner</option>
                </select>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Confirm Import',
        confirmButtonColor: 'var(--lime-dark)',
        cancelButtonColor: 'var(--line)',
        showLoaderOnConfirm: true,
        preConfirm: () => {
            const mealType = document.getElementById('importMealType').value;
            const formData = new FormData();
            formData.append('action', 'import_external_food');
            formData.append('csrf_token', FT_CSRF_TOKEN);
            formData.append('name', product.name || '');
            formData.append('meal_type', mealType);
            formData.append('serving_size', product.serving_size || '100g');
            formData.append('calories', product.calories_100g ?? 0);
            formData.append('protein_g', product.protein_100g ?? 0);
            formData.append('carbs_g', product.carbs_100g ?? 0);
            formData.append('fat_g', product.fat_100g ?? 0);
            formData.append('image_url', product.image || '');
            formData.append('source', 'openfoodfacts');
            formData.append('recipe_desc', product.brand ? `Brand: ${product.brand}` : '');

            return fetch('index.php?page=food_lookup', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: formData
            })
            .then(async res => {
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    throw new Error('Server returned an invalid response. Please try again.');
                }
                if (!data.success) {
                    throw new Error(data.error || data.message || 'Could not import food');
                }
                return data;
            })
            .catch(error => {
                Swal.showValidationMessage(`Import failed: ${error.message}`);
            });
        }
    }).then(result => {
        if (result.isConfirmed && result.value?.success) {
            Swal.fire({
                icon: 'success',
                title: 'Imported!',
                text: result.value.message,
                background: 'var(--bg)',
                color: 'var(--ink)',
                confirmButtonColor: 'var(--lime-dark)',
                confirmButtonText: 'Go to Catalog'
            }).then(() => {
                window.location.href = 'index.php?page=food_library&tab=library';
            });
        }
    });
}
