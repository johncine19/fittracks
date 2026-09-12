<?php
declare(strict_types=1);

function food_library_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner']);
    $pdo = db();
    verify_csrf();

    $gymId = null;
    if ($user['role'] === 'gym_owner') {
        $gymId = get_user_gym_id($user);
        if (!$gymId) {
            flash('No gym found for this gym owner.', 'danger');
            redirect('dashboard');
        }
    }

    $activeTab = (string) ($_GET['tab'] ?? 'library');
    $mealTypeFilter = trim((string) ($_GET['meal_type'] ?? ''));
    $restrictionFilter = trim((string) ($_GET['restriction'] ?? ''));
    $searchQuery = trim((string) ($_GET['q'] ?? ''));
    $scopeFilter = trim((string) ($_GET['scope'] ?? 'all')); // 'all', 'gym', 'global'

    // Handle Form Submissions (Create, Edit, Delete)
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $postAction = post('action');

        if ($postAction === 'create') {
            $name = trim((string) post('name'));
            $mealType = (string) post('meal_type');
            $restriction = trim((string) post('dietary_restriction')) ?: 'none';
            $servingSize = trim((string) post('serving_size')) ?: '1 serving';
            $calories = max(0, (int) post('calories'));
            $protein = max(0.0, (float) post('protein_g'));
            $carbs = max(0.0, (float) post('carbs_g'));
            $fat = max(0.0, (float) post('fat_g'));
            $desc = trim((string) post('recipe_desc')) ?: null;
            $imageUrl = trim((string) post('image_url')) ?: null;

            // Optional image file upload via ImageKit CDN
            if (isset($_FILES['food_image']) && !empty($_FILES['food_image']['tmp_name'])) {
                try {
                    require_once __DIR__ . '/../../core/file_handler.php';
                    $imageUrl = FileUpload::storeMealImage($_FILES['food_image']);
                } catch (Throwable $e) {
                    flash('Image upload warning: ' . $e->getMessage(), 'warning');
                }
            }

            if (!$name) {
                flash('Food item name is required.', 'danger');
            } else {
                $targetGymId = ($user['role'] === 'platform_admin' && post('is_global') === '1') ? null : $gymId;
                $stmt = $pdo->prepare("
                    INSERT INTO food_items 
                    (gym_id, name, meal_type, dietary_restriction, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc, source)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'custom')
                ");
                $stmt->execute([
                    $targetGymId,
                    $name,
                    $mealType,
                    $restriction,
                    $servingSize,
                    $calories,
                    $protein,
                    $carbs,
                    $fat,
                    $imageUrl,
                    $desc
                ]);
                flash('New food item added to library!', 'success');
                redirect('food_library');
            }
        }

        if ($postAction === 'edit') {
            $foodId = (int) post('food_id');
            $name = trim((string) post('name'));
            $mealType = (string) post('meal_type');
            $restriction = trim((string) post('dietary_restriction')) ?: 'none';
            $servingSize = trim((string) post('serving_size')) ?: '1 serving';
            $calories = max(0, (int) post('calories'));
            $protein = max(0.0, (float) post('protein_g'));
            $carbs = max(0.0, (float) post('carbs_g'));
            $fat = max(0.0, (float) post('fat_g'));
            $desc = trim((string) post('recipe_desc')) ?: null;
            $imageUrl = trim((string) post('image_url')) ?: null;

            // Check permissions
            $existing = $pdo->query("SELECT * FROM food_items WHERE food_id = {$foodId}")->fetch();
            if (!$existing) {
                flash('Food item not found.', 'danger');
                redirect('food_library');
            }

            if ($user['role'] === 'gym_owner' && (string)$existing['gym_id'] !== (string)$gymId) {
                flash('Permission denied. You can only edit your own gym items.', 'danger');
                redirect('food_library');
            }

            if (isset($_FILES['food_image']) && !empty($_FILES['food_image']['tmp_name'])) {
                try {
                    require_once __DIR__ . '/../../core/file_handler.php';
                    $imageUrl = FileUpload::storeMealImage($_FILES['food_image']);
                } catch (Throwable $e) {
                    flash('Image upload warning: ' . $e->getMessage(), 'warning');
                }
            }

            $stmt = $pdo->prepare("
                UPDATE food_items 
                SET name = ?, meal_type = ?, dietary_restriction = ?, serving_size = ?, calories = ?, protein_g = ?, carbs_g = ?, fat_g = ?, recipe_desc = ?, image_url = COALESCE(?, image_url)
                WHERE food_id = ?
            ");
            $stmt->execute([
                $name,
                $mealType,
                $restriction,
                $servingSize,
                $calories,
                $protein,
                $carbs,
                $fat,
                $desc,
                $imageUrl,
                $foodId
            ]);
            flash('Food item updated successfully!', 'success');
            redirect('food_library');
        }

        if ($postAction === 'update_food_photo') {
            $foodId = (int) post('food_id');
            $photoType = (string) post('photo_type'); // 'upload', 'url', 'reset'
            $customUrl = trim((string) post('photo_url'));

            $stmtCheck = $pdo->prepare('SELECT food_id, gym_id, name, meal_type, image_url FROM food_items WHERE food_id = ? AND is_active = 1');
            $stmtCheck->execute([$foodId]);
            $foodRow = $stmtCheck->fetch();

            if (!$foodRow) {
                if (ob_get_level()) ob_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Food item not found.']);
                exit;
            }

            if (!in_array($user['role'], ['platform_admin', 'gym_owner'], true)) {
                if (ob_get_level()) ob_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Permission denied.']);
                exit;
            }

            $newImageUrl = null;
            if ($photoType === 'reset') {
                $newImageUrl = null;
            } elseif ($photoType === 'upload' && !empty($_FILES['photo_file']['tmp_name'])) {
                try {
                    require_once __DIR__ . '/../../core/file_handler.php';
                    $newImageUrl = FileUpload::storeMealImage($_FILES['photo_file']);
                } catch (Throwable $e) {
                    if (ob_get_level()) ob_clean();
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                    exit;
                }
            } elseif ($photoType === 'url') {
                if (empty($customUrl) || !filter_var($customUrl, FILTER_VALIDATE_URL)) {
                    if (ob_get_level()) ob_clean();
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => 'Please provide a valid image web address.']);
                    exit;
                }
                $newImageUrl = $customUrl;
            } else {
                if (ob_get_level()) ob_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Invalid photo submission.']);
                exit;
            }

            $upStmt = $pdo->prepare('UPDATE food_items SET image_url = ? WHERE food_id = ?');
            $upStmt->execute([$newImageUrl, $foodId]);

            $resolvedUrl = get_meal_photo_url($newImageUrl, $foodRow['name'], $foodRow['meal_type']);

            if (ob_get_level()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'food_id' => $foodId,
                'image_url' => $newImageUrl,
                'resolved_url' => $resolvedUrl,
                'is_custom' => !empty($newImageUrl),
                'storage_provider' => (!empty($newImageUrl) && str_contains($newImageUrl, 'imagekit.io')) ? 'ImageKit CDN' : 'Custom/Local'
            ]);
            exit;
        }

        if ($postAction === 'delete') {
            $foodId = (int) post('food_id');
            $existing = $pdo->query("SELECT * FROM food_items WHERE food_id = {$foodId}")->fetch();
            if ($existing) {
                if ($user['role'] === 'gym_owner' && (string)$existing['gym_id'] !== (string)$gymId) {
                    flash('Permission denied.', 'danger');
                } else {
                    $pdo->prepare("DELETE FROM food_items WHERE food_id = ?")->execute([$foodId]);
                    flash('Food item removed from library.', 'info');
                }
            }
            redirect('food_library');
        }
    }

    // Build Query for List Tab
    $where = ['is_active = 1'];
    $params = [];

    if ($user['role'] === 'gym_owner') {
        if ($scopeFilter === 'gym') {
            $where[] = 'gym_id = ?';
            $params[] = $gymId;
        } elseif ($scopeFilter === 'global') {
            $where[] = 'gym_id IS NULL';
        } else {
            $where[] = '(gym_id = ? OR gym_id IS NULL)';
            $params[] = $gymId;
        }
    } else {
        // Platform admin
        if ($scopeFilter === 'global') {
            $where[] = 'gym_id IS NULL';
        } elseif ($scopeFilter === 'gym') {
            $where[] = 'gym_id IS NOT NULL';
        }
    }

    if ($mealTypeFilter) {
        $where[] = 'meal_type = ?';
        $params[] = $mealTypeFilter;
    }

    if ($restrictionFilter) {
        if ($restrictionFilter === 'none') {
            $where[] = "(dietary_restriction = 'none' OR dietary_restriction = '' OR dietary_restriction IS NULL)";
        } elseif ($restrictionFilter === 'gluten-free') {
            $where[] = "(dietary_restriction = 'gluten-free' OR dietary_restriction = 'gluten free')";
        } elseif ($restrictionFilter === 'nut-allergy') {
            $where[] = "(dietary_restriction = 'nut-allergy' OR dietary_restriction = 'nut allergy')";
        } elseif ($restrictionFilter === 'dairy-free') {
            $where[] = "(dietary_restriction = 'dairy-free' OR dietary_restriction = 'dairy free')";
        } else {
            $where[] = 'dietary_restriction = ?';
            $params[] = $restrictionFilter;
        }
    }

    if ($searchQuery) {
        $where[] = '(name LIKE ? OR recipe_desc LIKE ?)';
        $params[] = '%' . $searchQuery . '%';
        $params[] = '%' . $searchQuery . '%';
    }

    $whereSql = implode(' AND ', $where);

    // Count total foods for pagination
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM food_items WHERE {$whereSql}");
    $countStmt->execute($params);
    $totalFoods = (int) $countStmt->fetchColumn();

    // Pagination configuration
    $perPage = 12;
    $totalPages = max(1, (int) ceil($totalFoods / $perPage));
    $page = max(1, min($totalPages, (int) ($_GET['p'] ?? 1)));
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("SELECT * FROM food_items WHERE {$whereSql} ORDER BY (gym_id IS NOT NULL) DESC, name ASC LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute($params);
    $foods = $stmt->fetchAll();

    $fromCount = $totalFoods > 0 ? $offset + 1 : 0;
    $toCount = min($offset + $perPage, $totalFoods);

    // Helper URL for pagination and filters
    $buildFilterUrl = function(array $overrides = []) use ($searchQuery, $mealTypeFilter, $restrictionFilter, $scopeFilter, $page) {
        $q = [
            'page' => 'food_library',
            'tab' => 'library',
            'q' => $searchQuery,
            'meal_type' => $mealTypeFilter,
            'restriction' => $restrictionFilter,
            'scope' => $scopeFilter,
            'p' => $page
        ];
        foreach ($overrides as $k => $v) {
            if ($v === null || $v === '') {
                unset($q[$k]);
            } else {
                $q[$k] = $v;
            }
        }
        if (isset($q['scope']) && $q['scope'] === 'all') {
            unset($q['scope']);
        }
        return 'index.php?' . http_build_query($q);
    };

    $paginationBaseUrl = 'index.php?page=food_library&tab=library';
    if ($searchQuery) $paginationBaseUrl .= '&q=' . urlencode($searchQuery);
    if ($mealTypeFilter) $paginationBaseUrl .= '&meal_type=' . urlencode($mealTypeFilter);
    if ($restrictionFilter) $paginationBaseUrl .= '&restriction=' . urlencode($restrictionFilter);
    if ($scopeFilter && $scopeFilter !== 'all') $paginationBaseUrl .= '&scope=' . urlencode($scopeFilter);

    render_header('Food Library', $user);
?>
<style>
/* ==================================================== */
/* FOOD LIBRARY TOOLBAR & FILTER UX                    */
/* ==================================================== */
.food-filter-toolbar {
    display: grid;
    grid-template-columns: minmax(220px, 2fr) minmax(130px, 1fr) minmax(140px, 1fr) minmax(130px, 1fr) auto auto;
    gap: 10px;
    align-items: center;
    background: color-mix(in srgb, var(--surface) 92%, var(--ink));
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 10px 14px;
    margin-bottom: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}
@media (max-width: 1024px) {
    .food-filter-toolbar {
        grid-template-columns: 1fr 1fr;
    }
    .filter-search-box {
        grid-column: 1 / -1;
    }
    .filter-btn-col {
        grid-column: 1 / -1;
        display: flex;
        gap: 8px;
    }
}
@media (max-width: 580px) {
    .food-filter-toolbar {
        grid-template-columns: 1fr;
    }
    .filter-btn-col {
        grid-column: auto;
    }
}

.filter-search-box {
    position: relative;
    width: 100%;
}
.filter-search-box input {
    width: 100% !important;
    box-sizing: border-box !important;
    padding: 9px 32px 9px 36px !important;
    border-radius: 8px !important;
    border: 1px solid var(--line) !important;
    background: var(--bg) !important;
    color: var(--ink) !important;
    font-size: 13.5px !important;
    transition: all 0.2s ease;
}
.filter-search-box input:focus {
    border-color: var(--lime) !important;
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--lime) 15%, transparent);
    outline: none;
}
.filter-search-icon {
    position: absolute;
    left: 11px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--muted);
    pointer-events: none;
}
.filter-search-clear {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--muted);
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
    padding: 2px;
}
.filter-search-clear:hover {
    color: var(--ink);
}

select {
    color-scheme: dark !important;
}
.filter-select-wrapper {
    position: relative;
    width: 100%;
}
.filter-select-wrapper select {
    width: 100% !important;
    box-sizing: border-box !important;
    padding: 10px 36px 10px 14px !important;
    border-radius: 8px !important;
    border: 1px solid var(--line) !important;
    background-color: var(--bg) !important;
    color: var(--ink) !important;
    font-size: 13.5px !important;
    font-weight: 500 !important;
    cursor: pointer;
    color-scheme: dark !important;
    appearance: none !important;
    -webkit-appearance: none !important;
    -moz-appearance: none !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%238792ad' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: right 12px center !important;
    background-size: 15px !important;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.filter-select-wrapper select:focus {
    border-color: var(--lime) !important;
    outline: none;
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--lime) 15%, transparent);
}
.filter-select-wrapper select option {
    background-color: #121824 !important;
    color: #f1f5f9 !important;
    padding: 10px 14px !important;
    font-size: 13.5px !important;
}
.filter-select-wrapper select option:checked {
    background-color: #1e293b !important;
    color: var(--lime, #ccff00) !important;
    font-weight: 700 !important;
}
.filter-select-wrapper select option:hover {
    background-color: #273549 !important;
    color: #ffffff !important;
}

.btn-filter-submit {
    background: var(--lime);
    color: var(--bg);
    font-weight: 700;
    padding: 9px 16px;
    border-radius: 8px;
    border: none;
    font-size: 13px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: opacity 0.15s;
    white-space: nowrap;
    height: 37px;
}
.btn-filter-submit:hover {
    opacity: 0.9;
}
.btn-filter-reset {
    background: var(--panel-soft);
    color: var(--muted);
    border: 1px solid var(--line);
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    transition: all 0.2s;
    white-space: nowrap;
    height: 37px;
    box-sizing: border-box;
}
.btn-filter-reset:hover {
    color: var(--ink);
    border-color: var(--muted);
}

/* Active Filter Chips */
.food-active-filters {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 18px;
    padding: 4px 2px;
}
.active-filters-label {
    font-size: 12px;
    color: var(--muted);
    font-weight: 600;
}
.filter-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: color-mix(in srgb, var(--lime) 10%, var(--surface));
    border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
    color: var(--ink);
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    text-decoration: none;
    transition: all 0.15s ease;
}
.filter-chip:hover {
    background: color-mix(in srgb, var(--lime) 20%, var(--surface));
    border-color: var(--lime);
}
.chip-remove {
    font-size: 14px;
    font-weight: 700;
    line-height: 1;
    color: var(--muted);
}
.filter-chip:hover .chip-remove {
    color: var(--ink);
}
.filter-clear-all {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: var(--muted);
    font-size: 11.5px;
    font-weight: 600;
    padding: 4px 11px;
    border-radius: 20px;
    text-decoration: none;
    margin-left: 2px;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    white-space: nowrap;
}
.filter-clear-all svg {
    flex-shrink: 0;
    transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
.filter-clear-all:hover {
    background: color-mix(in srgb, #ef4444 14%, var(--surface));
    border-color: color-mix(in srgb, #ef4444 38%, transparent);
    color: #fca5a5;
    box-shadow: 0 2px 10px rgba(239, 68, 68, 0.18);
}
.filter-clear-all:hover svg {
    transform: rotate(-60deg);
}
.filter-clear-all:active {
    transform: scale(0.96);
}

/* Pagination Footer Bar */
.food-pagination-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 24px;
    padding-top: 18px;
    border-top: 1px solid var(--line);
    flex-wrap: wrap;
    gap: 14px;
}
.food-pagination-info {
    color: var(--muted);
    font-size: 13.5px;
}
.food-pagination-container .pagination {
    margin-top: 0 !important;
}

/* Food Banner Photo Button */
.food-banner-photo-btn {
    background: rgba(15, 23, 42, 0.82);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 6px;
    padding: 2px 7px;
    font-size: 10.5px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.15s ease;
}
.food-banner-photo-btn:hover {
    background: var(--lime);
    color: var(--bg);
    border-color: var(--lime);
}

/* Modal System for Customize Meal Photo */
.ft-modal-overlay {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    max-width: 100vw !important;
    max-height: 100vh !important;
    box-sizing: border-box !important;
    z-index: 999999 !important;
    background: rgba(0, 0, 0, 0.82) !important;
    backdrop-filter: blur(8px) !important;
    -webkit-backdrop-filter: blur(8px) !important;
    display: none;
    align-items: center !important;
    justify-content: center !important;
    padding: 16px !important;
    margin: 0 !important;
}
.ft-modal-overlay.active {
    display: flex !important;
    animation: ftModalFadeIn 0.2s ease;
}
@keyframes ftModalFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
.ft-modal-box {
    background: var(--panel-bg, #121721);
    color: var(--ink);
    border: 1px solid var(--line);
    border-radius: 14px;
    width: 100% !important;
    max-width: 520px !important;
    max-height: 88vh !important;
    margin: auto !important; /* Forces perfect centering on mobile and desktop */
    display: flex !important;
    flex-direction: column !important;
    box-shadow: 0 24px 60px rgba(0,0,0,0.6);
    overflow: hidden;
    animation: ftModalSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes ftModalSlideUp {
    from { opacity: 0; transform: translateY(14px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

/* SweetAlert and Modal Mobile Responsiveness & Centering */
@media (max-width: 640px) {
    .ft-modal-overlay {
        padding: 10px !important;
        align-items: center !important;
        justify-content: center !important;
    }
    .ft-modal-box {
        max-width: calc(100vw - 20px) !important;
        max-height: 92vh !important;
        margin: auto !important;
    }
    .ft-modal-header {
        padding: 14px 16px !important;
    }
    .ft-modal-body {
        padding: 14px 16px !important;
    }
    .ft-modal-footer {
        padding: 12px 16px !important;
    }
    .swal2-container {
        padding: 10px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
    .swal2-container.swal2-center {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
    .swal2-popup {
        width: calc(100vw - 20px) !important;
        max-width: calc(100vw - 20px) !important;
        margin: auto !important;
        padding: 16px 14px !important;
        box-sizing: border-box !important;
    }
    .swal2-html-container {
        margin: 10px 0 0 !important;
        padding: 0 !important;
        overflow-x: hidden !important;
    }
}

/* SweetAlert Tab Switcher System */
.swal-tabs-bar {
    display: flex;
    gap: 8px;
    margin: 8px 0 16px;
    background: rgba(15, 23, 42, 0.6);
    padding: 4px;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.08);
}
.swal-tab-btn {
    flex: 1;
    padding: 9px 12px;
    font-size: 13px;
    font-weight: 700;
    color: var(--muted);
    background: transparent;
    border: 1px solid transparent;
    border-radius: 7px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    transition: all 0.2s ease;
}
.swal-tab-btn svg {
    flex-shrink: 0;
    transition: stroke 0.2s ease;
}
.swal-tab-btn:hover {
    color: var(--ink);
    background: rgba(255, 255, 255, 0.04);
}
.swal-tab-btn.active {
    color: var(--lime);
    background: color-mix(in srgb, var(--lime) 14%, #121721);
    border-color: color-mix(in srgb, var(--lime) 40%, transparent);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}
.swal-tab-btn.active svg {
    stroke: var(--lime);
}
.swal-tab-panel {
    display: flex;
    flex-direction: column;
    gap: 12px;
    text-align: left;
    animation: swalTabFade 0.2s ease;
}
@keyframes swalTabFade {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
}
.ft-modal-header {
    padding: 18px 22px;
    border-bottom: 1px solid var(--line);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}
.ft-modal-title {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--ink);
    display: flex;
    align-items: center;
    gap: 8px;
}
.ft-modal-subtitle {
    margin: 3px 0 0 0;
    font-size: 12px;
    color: var(--muted);
}
.ft-modal-close {
    background: transparent;
    border: none;
    color: var(--muted);
    cursor: pointer;
    padding: 6px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}
.ft-modal-close:hover {
    background: rgba(255,255,255,0.08);
    color: var(--ink);
}
.ft-modal-body {
    padding: 20px 22px;
    overflow-y: auto;
    flex: 1 1 auto;
}
.ft-modal-footer {
    padding: 14px 22px;
    border-top: 1px solid var(--line);
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
    background: color-mix(in srgb, var(--bg) 40%, var(--panel-bg, #121721));
}
.btn-modal-cancel {
    background: transparent;
    color: var(--muted);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 8px 16px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s ease;
}
.btn-modal-cancel:hover {
    color: var(--ink);
    border-color: var(--muted);
}
.btn-modal-save {
    background: var(--lime);
    color: var(--bg);
    border: none;
    border-radius: 8px;
    padding: 9px 20px;
    font-weight: 800;
    font-size: 13.5px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}
.btn-modal-save:hover {
    opacity: 0.92;
    box-shadow: 0 4px 14px color-mix(in srgb, var(--lime) 35%, transparent);
}
.btn-modal-save:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* Modal Tabs */
.photo-modal-tabs {
    display: flex;
    gap: 6px;
    margin-bottom: 16px;
    border-bottom: 1px solid var(--line);
    padding-bottom: 10px;
}
.photo-modal-tab-btn {
    background: transparent;
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--muted);
    padding: 7px 14px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}
.photo-modal-tab-btn:hover {
    color: var(--ink);
    border-color: var(--muted);
}
.photo-modal-tab-btn.active {
    background: color-mix(in srgb, var(--lime) 12%, transparent);
    color: var(--lime);
    border-color: color-mix(in srgb, var(--lime) 50%, transparent);
}

/* Modal Preview Banner */
.photo-modal-preview-banner {
    position: relative;
    width: 100%;
    height: 155px;
    border-radius: 12px;
    overflow: hidden;
    background: #000;
    margin-bottom: 16px;
    border: 1px solid var(--line);
}
.photo-modal-preview-banner img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 0.88;
    display: block;
}
.photo-modal-preview-banner::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, rgba(0,0,0,0.15) 0%, rgba(0,0,0,0.85) 100%);
    pointer-events: none;
}
.photo-modal-preview-badge {
    position: absolute;
    top: 10px;
    left: 12px;
    background: rgba(0,0,0,0.85);
    color: var(--lime);
    font-size: 10.5px;
    font-weight: 800;
    padding: 3px 8px;
    border-radius: 5px;
    letter-spacing: 0.5px;
    border: 1px solid rgba(132, 204, 22, 0.4);
    text-transform: uppercase;
    z-index: 2;
}
.photo-modal-preview-title {
    position: absolute;
    bottom: 12px;
    left: 14px;
    right: 14px;
    font-size: 15px;
    font-weight: 800;
    color: #fff;
    text-shadow: 0 2px 6px rgba(0,0,0,0.8);
    z-index: 2;
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Upload Dropzone */
.photo-upload-dropzone {
    border: 2px dashed rgba(255,255,255,0.18);
    border-radius: 12px;
    padding: 26px 18px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
    background: rgba(255,255,255,0.02);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.photo-upload-dropzone:hover {
    border-color: var(--lime);
    background: rgba(132, 204, 22, 0.05);
}
.photo-upload-dropzone svg {
    color: var(--lime);
}

/* Photo URL Input */
.photo-url-input {
    width: 100%;
    box-sizing: border-box;
    padding: 10px 14px;
    border-radius: 8px;
    border: 1px solid var(--line);
    background: var(--bg);
    color: var(--ink);
    font-size: 13.5px;
    font-family: inherit;
    transition: border-color 0.2s;
}
.photo-url-input:focus {
    outline: none;
    border-color: var(--lime);
}
</style>

<section class="panel" style="margin-bottom: 24px;">
    <!-- Page Header & Action Toolbar -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 20px;">
        <div style="flex: 1; min-width: 240px;">
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <h1 style="margin: 0; font-size: 24px; font-weight: 800; color: var(--ink);">Food & Recipe Library</h1>
                <span style="font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; background: rgba(132, 204, 22, 0.15); color: var(--lime); border: 1px solid rgba(132, 204, 22, 0.3);">
                    <?= $totalFoods ?> <?= $totalFoods === 1 ? 'Food' : 'Foods' ?>
                </span>
            </div>
            <p style="margin: 4px 0 0; color: var(--muted); font-size: 13.5px;">
                Manage meal templates, customize gym food options, or import branded groceries from online nutrition databases.
            </p>
        </div>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: nowrap; flex-shrink: 0;">
            <button onclick="openAddFoodModal()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: 700; height: 38px; padding: 0 16px; border-radius: 8px; border: none; display: inline-flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <span>Add Custom Food</span>
            </button>
        </div>
    </div>

    <!-- Navigation Tabs: Library vs Online Search -->
    <div style="display: flex; gap: 8px; border-bottom: 1px solid var(--line); margin-bottom: 20px;">
        <a href="index.php?page=food_library&tab=library" style="padding: 10px 16px; font-size: 13.5px; font-weight: 600; text-decoration: none; border-bottom: 2px solid <?= $activeTab === 'library' ? 'var(--lime)' : 'transparent' ?>; color: <?= $activeTab === 'library' ? 'var(--lime)' : 'var(--muted)' ?>; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"></path><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path><line x1="6" y1="1" x2="6" y2="4"></line><line x1="10" y1="1" x2="10" y2="4"></line><line x1="14" y1="1" x2="14" y2="4"></line></svg>
            <span>Food Catalog</span>
        </a>
        <a href="index.php?page=food_library&tab=online_import" style="padding: 10px 16px; font-size: 13.5px; font-weight: 600; text-decoration: none; border-bottom: 2px solid <?= $activeTab === 'online_import' ? 'var(--lime)' : 'transparent' ?>; color: <?= $activeTab === 'online_import' ? 'var(--lime)' : 'var(--muted)' ?>; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
            <span>Online Nutrition Search (Open Food Facts)</span>
        </a>
    </div>

    <?php if ($activeTab === 'library'): ?>
        <!-- Modernized Filter Toolbar -->
        <form method="get" class="food-filter-toolbar">
            <input type="hidden" name="page" value="food_library">
            <input type="hidden" name="tab" value="library">

            <div class="filter-search-box">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="filter-search-icon"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" name="q" id="foodSearchInput" value="<?= h($searchQuery) ?>" placeholder="Search dishes by name or ingredients...">
                <?php if ($searchQuery): ?>
                    <button type="button" onclick="document.getElementById('foodSearchInput').value=''; this.form.submit();" class="filter-search-clear" title="Clear search">&times;</button>
                <?php endif; ?>
            </div>

            <div class="filter-select-wrapper">
                <select name="meal_type" onchange="this.form.submit()">
                    <option value="">All Meal Types</option>
                    <option value="Breakfast" <?= $mealTypeFilter === 'Breakfast' ? 'selected' : '' ?>>Breakfast</option>
                    <option value="Lunch" <?= $mealTypeFilter === 'Lunch' ? 'selected' : '' ?>>Lunch</option>
                    <option value="Dinner" <?= $mealTypeFilter === 'Dinner' ? 'selected' : '' ?>>Dinner</option>
                    <option value="Snack" <?= $mealTypeFilter === 'Snack' ? 'selected' : '' ?>>Snack</option>
                </select>
            </div>

            <div class="filter-select-wrapper">
                <select name="restriction" onchange="this.form.submit()">
                    <option value="">All Dietary Types</option>
                    <option value="none" <?= in_array($restrictionFilter, ['none', 'None']) ? 'selected' : '' ?>>None</option>
                    <option value="vegetarian" <?= strcasecmp((string)$restrictionFilter, 'vegetarian') === 0 ? 'selected' : '' ?>>Vegetarian</option>
                    <option value="vegan" <?= strcasecmp((string)$restrictionFilter, 'vegan') === 0 ? 'selected' : '' ?>>Vegan</option>
                    <option value="pescatarian" <?= strcasecmp((string)$restrictionFilter, 'pescatarian') === 0 ? 'selected' : '' ?>>Pescatarian</option>
                    <option value="halal" <?= strcasecmp((string)$restrictionFilter, 'halal') === 0 ? 'selected' : '' ?>>Halal</option>
                    <option value="gluten-free" <?= in_array($restrictionFilter, ['gluten-free', 'gluten free']) ? 'selected' : '' ?>>Gluten Free</option>
                    <option value="keto" <?= strcasecmp((string)$restrictionFilter, 'keto') === 0 ? 'selected' : '' ?>>Keto</option>
                    <option value="paleo" <?= strcasecmp((string)$restrictionFilter, 'paleo') === 0 ? 'selected' : '' ?>>Paleo</option>
                    <option value="nut-allergy" <?= in_array($restrictionFilter, ['nut-allergy', 'nut allergy']) ? 'selected' : '' ?>>Nut Allergy</option>
                    <option value="dairy-free" <?= in_array($restrictionFilter, ['dairy-free', 'dairy free']) ? 'selected' : '' ?>>Dairy Free</option>
                </select>
            </div>

            <div class="filter-select-wrapper">
                <select name="scope" onchange="this.form.submit()">
                    <option value="all" <?= $scopeFilter === 'all' ? 'selected' : '' ?>>All Items</option>
                    <option value="gym" <?= $scopeFilter === 'gym' ? 'selected' : '' ?>>Gym Custom Foods</option>
                    <option value="global" <?= $scopeFilter === 'global' ? 'selected' : '' ?>>Platform Staples</option>
                </select>
            </div>

            <div class="filter-btn-col">
                <button type="submit" class="btn btn-filter-submit">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                    <span>Filter</span>
                </button>
            </div>

            <?php if ($searchQuery || $mealTypeFilter || $restrictionFilter || $scopeFilter !== 'all'): ?>
                <div class="filter-btn-col">
                    <a href="index.php?page=food_library&tab=library" class="btn btn-filter-reset" title="Reset all filters">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        <span>Reset</span>
                    </a>
                </div>
            <?php endif; ?>
        </form>

        <?php 
        $hasActiveFilters = !empty($searchQuery) || !empty($mealTypeFilter) || !empty($restrictionFilter) || ($scopeFilter && $scopeFilter !== 'all');
        ?>
        <?php if ($hasActiveFilters): ?>
            <div class="food-active-filters">
                <span class="active-filters-label">Active Filters:</span>

                <?php if (!empty($searchQuery)): ?>
                    <a href="<?= $buildFilterUrl(['q' => null, 'p' => 1]) ?>" class="filter-chip" title="Remove keyword filter">
                        <span>Keyword: "<strong><?= h($searchQuery) ?></strong>"</span>
                        <span class="chip-remove">&times;</span>
                    </a>
                <?php endif; ?>

                <?php if (!empty($mealTypeFilter)): ?>
                    <a href="<?= $buildFilterUrl(['meal_type' => null, 'p' => 1]) ?>" class="filter-chip" title="Remove meal type filter">
                        <span>Meal: <strong><?= h($mealTypeFilter) ?></strong></span>
                        <span class="chip-remove">&times;</span>
                    </a>
                <?php endif; ?>

                <?php if (!empty($restrictionFilter)): ?>
                    <a href="<?= $buildFilterUrl(['restriction' => null, 'p' => 1]) ?>" class="filter-chip" title="Remove dietary filter">
                        <span>Diet: <strong><?= h(ucwords(str_replace('-', ' ', $restrictionFilter))) ?></strong></span>
                        <span class="chip-remove">&times;</span>
                    </a>
                <?php endif; ?>

                <?php if (!empty($scopeFilter) && $scopeFilter !== 'all'): ?>
                    <a href="<?= $buildFilterUrl(['scope' => 'all', 'p' => 1]) ?>" class="filter-chip" title="Remove scope filter">
                        <span>Scope: <strong><?= $scopeFilter === 'gym' ? 'Gym Custom' : 'Platform Staples' ?></strong></span>
                        <span class="chip-remove">&times;</span>
                    </a>
                <?php endif; ?>

                <a href="index.php?page=food_library&tab=library" class="filter-clear-all" title="Clear all active filters">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                        <path d="M3 3v5h5"/>
                    </svg>
                    <span>Clear all</span>
                </a>
            </div>
        <?php endif; ?>

        <!-- Foods Grid -->
        <?php if (empty($foods)): ?>
            <div style="text-align: center; padding: 60px 20px; background: var(--bg); border: 1px dashed var(--line); border-radius: 12px;">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.5" style="margin-bottom: 12px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <h3 style="margin: 0; font-size: 16px; color: var(--ink);">No food items found</h3>
                <p style="margin: 6px 0 16px; font-size: 13px; color: var(--muted);">Try adjusting your filter criteria or click below to add a new food.</p>
                <button onclick="openAddFoodModal()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: 700; padding: 8px 16px; border-radius: 8px;">+ Add Custom Food</button>
            </div>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(310px, 1fr)); gap: 16px;">
                <?php foreach ($foods as $food): 
                    $photoUrl = get_meal_photo_url($food['image_url'], $food['name'], $food['meal_type']);
                    $isCustom = !empty($food['gym_id']);
                    $canEditPhoto = in_array($user['role'], ['platform_admin', 'gym_owner'], true);
                ?>
                    <div style="background: var(--surface); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; transition: transform 0.15s, border-color 0.15s;">
                        <!-- Food Card Header / Image -->
                        <div style="position: relative; height: 130px; background: #000; overflow: hidden;">
                            <img id="food-card-img-<?= $food['food_id'] ?>" src="<?= h($photoUrl) ?>" alt="<?= h($food['name']) ?>" style="width: 100%; height: 100%; object-fit: cover; opacity: 0.85;" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80';">
                            <div style="position: absolute; top: 10px; left: 10px; display: flex; gap: 6px; flex-wrap: wrap;">
                                <span style="font-size: 10.5px; font-weight: 800; text-transform: uppercase; background: rgba(0,0,0,0.75); color: #60a5fa; border: 1px solid rgba(96, 165, 250, 0.4); padding: 2px 7px; border-radius: 4px; backdrop-filter: blur(4px);">
                                    <?= h($food['meal_type']) ?>
                                </span>
                                <?php if ($food['dietary_restriction'] !== 'none'): ?>
                                    <span style="font-size: 10.5px; font-weight: 700; text-transform: capitalize; background: rgba(0,0,0,0.75); color: #34d399; border: 1px solid rgba(52, 211, 153, 0.4); padding: 2px 7px; border-radius: 4px; backdrop-filter: blur(4px);">
                                        <?= h($food['dietary_restriction']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div style="position: absolute; top: 10px; right: 10px; display: flex; align-items: center; gap: 6px;">
                                <?php if ($isCustom): ?>
                                    <span style="font-size: 10px; font-weight: 800; background: rgba(168, 85, 247, 0.9); color: #fff; padding: 2px 7px; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.5px;">
                                        Gym Custom
                                    </span>
                                <?php else: ?>
                                    <span style="font-size: 10px; font-weight: 700; background: rgba(0,0,0,0.75); color: #94a3b8; padding: 2px 6px; border-radius: 4px;">
                                        Staple
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div style="position: absolute; bottom: 8px; right: 10px; background: rgba(0,0,0,0.8); color: var(--lime); font-size: 12px; font-weight: 800; padding: 3px 8px; border-radius: 6px; border: 1px solid rgba(132, 204, 22, 0.3);">
                                🔥 <?= $food['calories'] ?> kcal
                            </div>
                        </div>

                        <!-- Card Body -->
                        <div style="padding: 14px 16px; flex: 1; display: flex; flex-direction: column;">
                            <h4 style="margin: 0 0 6px; font-size: 14.5px; font-weight: 700; color: var(--ink); line-height: 1.35;"><?= h($food['name']) ?></h4>
                            <?php if ($food['recipe_desc']): ?>
                                <p style="margin: 0 0 12px; font-size: 12px; color: var(--muted); line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                    <?= h($food['recipe_desc']) ?>
                                </p>
                            <?php else: ?>
                                <div style="margin-bottom: 12px;"></div>
                            <?php endif; ?>

                            <!-- Serving Size & Macros Pill -->
                            <div style="margin-top: auto; padding-top: 10px; border-top: 1px solid rgba(255,255,255,0.06);">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                    <span style="font-size: 11.5px; color: var(--muted);">Serving: <strong style="color: var(--ink);"><?= h($food['serving_size']) ?></strong></span>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px; text-align: center; font-size: 11.5px; font-weight: 600;">
                                    <div style="background: var(--bg); padding: 5px 4px; border-radius: 6px; border: 1px solid var(--line);">
                                        <span style="display: block; font-size: 9.5px; color: var(--muted); font-weight: 500;">Protein</span>
                                        <span style="color: var(--ink);"><?= $food['protein_g'] ?>g</span>
                                    </div>
                                    <div style="background: var(--bg); padding: 5px 4px; border-radius: 6px; border: 1px solid var(--line);">
                                        <span style="display: block; font-size: 9.5px; color: var(--muted); font-weight: 500;">Carbs</span>
                                        <span style="color: var(--ink);"><?= $food['carbs_g'] ?>g</span>
                                    </div>
                                    <div style="background: var(--bg); padding: 5px 4px; border-radius: 6px; border: 1px solid var(--line);">
                                        <span style="display: block; font-size: 9.5px; color: var(--muted); font-weight: 500;">Fat</span>
                                        <span style="color: var(--ink);"><?= $food['fat_g'] ?>g</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Card Actions Footer -->
                        <div style="padding: 10px 14px; background: rgba(0,0,0,0.15); border-top: 1px solid var(--line); display: flex; justify-content: flex-end; align-items: center; gap: 8px;">
                            <button type="button" 
                                    onclick='openFoodPhotoModal(<?= (int)$food['food_id'] ?>, <?= json_encode($food['name'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, <?= json_encode($food['meal_type'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, <?= json_encode($food['image_url'] ?? '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, <?= json_encode($photoUrl, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)' 
                                    class="btn-sm btn-ghost" 
                                    style="padding: 4px 10px; font-size: 12px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;"
                                    title="Customize Meal Photo">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                                Photo
                            </button>
                            <?php if ($user['role'] === 'platform_admin' || $isCustom): ?>
                                <button type="button" onclick="openEditFoodModal(<?= htmlspecialchars(json_encode($food), ENT_QUOTES, 'UTF-8') ?>)" class="btn-sm btn-ghost" style="padding: 4px 10px; font-size: 12px; border-radius: 6px;">Edit</button>
                                <form method="post" style="margin:0;" onsubmit="return confirm('Delete this food item?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="food_id" value="<?= (int)$food['food_id'] ?>">
                                    <button class="btn-sm btn-danger" style="padding: 4px 10px; font-size: 12px; border-radius: 6px;">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination Controls & Info -->
            <div class="food-pagination-footer">
                <div class="food-pagination-info">
                    Showing <strong style="color:var(--ink);"><?= $fromCount ?></strong>–<strong style="color:var(--ink);"><?= $toCount ?></strong> of <strong style="color:var(--lime);"><?= $totalFoods ?></strong> <?= $totalFoods === 1 ? 'food item' : 'food items' ?>
                </div>
                <?php if ($totalPages > 1): ?>
                    <div class="food-pagination-container">
                        <?php render_pagination($page, $totalPages, $paginationBaseUrl); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($activeTab === 'online_import'): ?>
        <!-- Online Nutrition Search Tab (Open Food Facts) -->
        <div style="background: var(--surface); padding: 20px; border-radius: 12px; border: 1px solid var(--line); margin-bottom: 20px;">
            <h3 style="margin: 0 0 6px; font-size: 17px; font-weight: 700; color: var(--ink);">Instant Open Food Facts Search</h3>
            <p style="margin: 0 0 16px; font-size: 13px; color: var(--muted);">
                Search millions of global and regional grocery items, protein powders, snacks, and ready-to-eat meals. Click "Import to Gym" to add them to your food catalog.
            </p>

            <div style="display: flex; gap: 10px; max-width: 600px;">
                <input type="text" id="off_search_query" placeholder="e.g. Greek Yogurt, Kirkland Peanut Butter, Whey Protein..." onkeydown="if(event.key==='Enter') executeOffSearch();" style="flex: 1; padding: 10px 14px; border-radius: 8px; border: 1px solid var(--line); background: var(--bg); color: var(--ink); font-size: 13.5px;">
                <button onclick="executeOffSearch()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: 700; padding: 0 18px; border-radius: 8px; border: none; cursor: pointer;">
                    Search
                </button>
            </div>
            <div id="off_search_status" style="margin-top: 10px; font-size: 12.5px; color: var(--muted);"></div>
        </div>

        <!-- Open Food Facts Results Container -->
        <div id="off_results_grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(310px, 1fr)); gap: 16px;">
            <div style="grid-column: 1 / -1; text-align: center; padding: 40px 20px; color: var(--muted); font-size: 13.5px;">
                Type a brand, grocery item, or food name above to search online.
            </div>
        </div>
    <?php endif; ?>
</section>

<!-- Customize Meal Photo Modal (ImageKit / URL / Auto) -->
<div class="ft-modal-overlay" id="modal-food-photo" style="display: none;" onclick="if(event.target===this) closeFoodPhotoModal()">
    <div class="ft-modal-box">
        <div class="ft-modal-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 20px; line-height: 1;">📷</span>
                <div>
                    <h3 class="ft-modal-title">Customize Meal Photo</h3>
                    <p class="ft-modal-subtitle">Upload to ImageKit or enter a custom link</p>
                </div>
            </div>
            <button type="button" class="ft-modal-close" onclick="closeFoodPhotoModal()" title="Close">&times;</button>
        </div>

        <div class="ft-modal-body">
            <!-- Dynamic Preview Banner -->
            <div class="photo-modal-preview-banner">
                <img id="modal-photo-preview-img" src="" alt="Meal Preview" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80';">
                <div class="photo-modal-preview-badge" id="modal-photo-preview-badge">AUTO-MATCHED PHOTO</div>
                <div class="photo-modal-preview-title" id="modal-photo-preview-name">Meal Title</div>
            </div>

            <!-- Tab Switcher -->
            <div class="photo-modal-tabs">
                <button type="button" class="photo-modal-tab-btn active" id="tab-btn-upload" onclick="switchFoodPhotoTab('upload')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                    <span>Upload (ImageKit)</span>
                </button>
                <button type="button" class="photo-modal-tab-btn" id="tab-btn-url" onclick="switchFoodPhotoTab('url')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                    <span>Image URL</span>
                </button>
                <button type="button" class="photo-modal-tab-btn" id="tab-btn-reset" onclick="switchFoodPhotoTab('reset')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="1 4 1 10 7 10"></polyline><polyline points="23 20 23 14 17 14"></polyline><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"></path></svg>
                    <span>Reset to Auto</span>
                </button>
            </div>

            <!-- Tab 1: Upload (ImageKit) -->
            <div id="panel-tab-upload">
                <input type="file" id="modal-food-photo-file" accept="image/png,image/jpeg,image/webp,image/jpg" style="display: none;" onchange="handleFoodPhotoSelected(this)">
                <div class="photo-upload-dropzone" id="food-photo-dropzone" onclick="document.getElementById('modal-food-photo-file').click()">
                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                    <div style="font-size: 14.5px; font-weight: 700; color: var(--ink);" id="food-photo-file-label">Click to select photo</div>
                    <div style="font-size: 12px; color: var(--muted);">PNG, JPG, WebP up to 5MB • Saves directly to ImageKit CDN</div>
                </div>
            </div>

            <!-- Tab 2: Custom URL -->
            <div id="panel-tab-url" style="display: none;">
                <label style="display:block; font-size: 12.5px; font-weight: 600; color: var(--muted); margin-bottom: 6px;">Direct Image Web Link</label>
                <input type="url" id="modal-food-photo-url-input" class="photo-url-input" placeholder="https://images.unsplash.com/photo-..." oninput="handleFoodPhotoUrlInput(this.value)">
                <p style="margin: 8px 0 0; font-size: 12px; color: var(--muted);">Paste any direct image URL (Unsplash, Pexels, public CDN). It will be loaded securely via HTTPS.</p>
            </div>

            <!-- Tab 3: Reset to Auto -->
            <div id="panel-tab-reset" style="display: none;">
                <div style="background: rgba(0,0,0,0.25); border: 1px dashed var(--line); border-radius: 10px; padding: 18px; text-align: center;">
                    <div style="font-size: 14px; font-weight: 700; color: var(--ink); margin-bottom: 6px;">Restore Default Auto-Matching Photo</div>
                    <p style="margin: 0; font-size: 12.5px; color: var(--muted); line-height: 1.5;">
                        Clears your custom photo override. FitTracks will automatically match high-resolution photos based on the dish name and meal category.
                    </p>
                </div>
            </div>

            <div id="modal-photo-error" style="display: none; margin-top: 12px; font-size: 12.5px; color: var(--danger); font-weight: 600;"></div>
        </div>

        <div class="ft-modal-footer">
            <button type="button" class="btn-modal-cancel" id="btn-modal-cancel-photo" onclick="closeFoodPhotoModal()">Cancel</button>
            <button type="button" class="btn-modal-save" id="btn-modal-save-photo" onclick="saveFoodPhoto()">Save Photo</button>
        </div>
    </div>
</div>

<!-- Add / Edit Food Modal Script -->
<script>
const FT_CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;

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
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                
                <!-- TAB 1: General Info & Macros -->
                <div id="swal-tab-panel-1" class="swal-tab-panel" style="display: flex;">
                    <label style="display:block; font-size: 13px; color: var(--muted); font-weight: 600;">Dish / Product Name *
                        <input type="text" name="name" class="form-control" placeholder="e.g. High Protein Chicken Teriyaki Bowl" required style="width: 100%; box-sizing: border-box; margin-top: 4px; padding: 9px 12px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff;">
                    </label>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <label style="display:block; font-size: 13px; color: var(--muted); font-weight: 600;">Meal Type *
                            <select name="meal_type" required style="width: 100%; box-sizing: border-box; margin-top: 4px; padding: 9px 12px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff;">
                                <option value="Breakfast">Breakfast</option>
                                <option value="Lunch" selected>Lunch</option>
                                <option value="Dinner">Dinner</option>
                                <option value="Snack">Snack</option>
                            </select>
                        </label>
                        <label style="display:block; font-size: 13px; color: var(--muted); font-weight: 600;">Dietary Type
                            <select name="dietary_restriction" style="width: 100%; box-sizing: border-box; margin-top: 4px; padding: 9px 12px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff;">
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

                    <label style="display:block; font-size: 13px; color: var(--muted); font-weight: 600;">Serving Portion
                        <input type="text" name="serving_size" value="1 serving" placeholder="e.g. 1 plate (200g chicken)" style="width: 100%; box-sizing: border-box; margin-top: 4px; padding: 9px 12px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff;">
                    </label>

                    <div style="background: #141b28; border: 1px solid #283548; border-radius: 8px; padding: 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="font-size: 12px; font-weight: 700; color: var(--lime);">Nutrition per Serving</span>
                            <span style="font-size: 11px; color: var(--muted);">Auto-calculates kcal</span>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 8px;">
                            <div>
                                <span style="font-size: 10px; color: var(--muted); display: block; margin-bottom: 2px;">Calories</span>
                                <input type="number" name="calories" id="swal_cals" value="450" required style="width: 100%; box-sizing: border-box; padding: 7px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff; font-weight: 700;">
                            </div>
                            <div>
                                <span style="font-size: 10px; color: var(--muted); display: block; margin-bottom: 2px;">Protein (g)</span>
                                <input type="number" step="0.1" name="protein_g" id="swal_p" value="35" oninput="calculateSwalCals()" required style="width: 100%; box-sizing: border-box; padding: 7px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff;">
                            </div>
                            <div>
                                <span style="font-size: 10px; color: var(--muted); display: block; margin-bottom: 2px;">Carbs (g)</span>
                                <input type="number" step="0.1" name="carbs_g" id="swal_c" value="45" oninput="calculateSwalCals()" required style="width: 100%; box-sizing: border-box; padding: 7px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff;">
                            </div>
                            <div>
                                <span style="font-size: 10px; color: var(--muted); display: block; margin-bottom: 2px;">Fat (g)</span>
                                <input type="number" step="0.1" name="fat_g" id="swal_f" value="12" oninput="calculateSwalCals()" required style="width: 100%; box-sizing: border-box; padding: 7px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff;">
                            </div>
                        </div>
                    </div>

                    <?php if ($user['role'] === 'platform_admin'): ?>
                        <label style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--ink); cursor: pointer;">
                            <input type="checkbox" name="is_global" value="1">
                            <span>Save as platform global staple (available to all gyms)</span>
                        </label>
                    <?php endif; ?>

                    <button type="button" onclick="switchSwalFoodTab(2)" style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.15); color: var(--ink); border-radius: 7px; padding: 8px 14px; font-size: 12.5px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: space-between; margin-top: 4px; transition: all 0.2s;">
                        <span>Continue to Photo & Description</span>
                        <span style="color: var(--lime); display: inline-flex; align-items: center; gap: 4px;">Next <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                    </button>
                </div>

                <!-- TAB 2: Photo & Details -->
                <div id="swal-tab-panel-2" class="swal-tab-panel" style="display: none;">
                    <div style="background: #141b28; border: 1px solid #283548; border-radius: 8px; padding: 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="font-size: 12px; font-weight: 700; color: var(--lime);">Meal Image (Optional)</span>
                            <span style="font-size: 10.5px; color: var(--muted); background: rgba(255,255,255,0.06); padding: 2px 6px; border-radius: 4px;">ImageKit CDN</span>
                        </div>
                        <label style="display: block; font-size: 11.5px; color: var(--muted); margin-bottom: 4px; font-weight: 600;">Upload Photo File (PNG, JPG, WebP):</label>
                        <input type="file" name="food_image" accept="image/png,image/jpeg,image/webp,image/jpg" style="width: 100%; box-sizing: border-box; padding: 7px 8px; border-radius: 6px; border: 1px solid #334155; background: #0f172a; color: #fff; font-size: 12px;">
                        
                        <div style="text-align: center; margin: 8px 0; font-size: 11px; color: var(--muted);">— OR paste web image URL —</div>
                        <input type="url" name="image_url" placeholder="https://images.unsplash.com/..." style="width: 100%; box-sizing: border-box; padding: 8px 12px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff; font-size: 12px;">
                    </div>

                    <label style="display:block; font-size: 13px; color: var(--muted); font-weight: 600;">Description & Ingredients
                        <textarea name="recipe_desc" rows="3" placeholder="Brief notes on ingredients or preparation..." style="width: 100%; box-sizing: border-box; margin-top: 4px; padding: 9px 12px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff; min-height: 80px; resize: vertical;"></textarea>
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
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="food_id" value="${food.food_id}">
                
                <!-- TAB 1: General Info & Macros -->
                <div id="swal-tab-panel-1" class="swal-tab-panel" style="display: flex;">
                    <label style="display:block; font-size: 13px; color: var(--muted); font-weight: 600;">Dish / Product Name *
                        <input type="text" name="name" value="${escapeHtml(food.name)}" required style="width: 100%; box-sizing: border-box; margin-top: 4px; padding: 9px 12px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff;">
                    </label>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <label style="display:block; font-size: 13px; color: var(--muted); font-weight: 600;">Meal Type *
                            <select name="meal_type" required style="width: 100%; box-sizing: border-box; margin-top: 4px; padding: 9px 12px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff;">
                                <option value="Breakfast" ${food.meal_type === 'Breakfast' ? 'selected' : ''}>Breakfast</option>
                                <option value="Lunch" ${food.meal_type === 'Lunch' ? 'selected' : ''}>Lunch</option>
                                <option value="Dinner" ${food.meal_type === 'Dinner' ? 'selected' : ''}>Dinner</option>
                                <option value="Snack" ${food.meal_type === 'Snack' ? 'selected' : ''}>Snack</option>
                            </select>
                        </label>
                        <label style="display:block; font-size: 13px; color: var(--muted); font-weight: 600;">Dietary Type
                            <select name="dietary_restriction" style="width: 100%; box-sizing: border-box; margin-top: 4px; padding: 9px 12px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff;">
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

                    <label style="display:block; font-size: 13px; color: var(--muted); font-weight: 600;">Serving Portion
                        <input type="text" name="serving_size" value="${escapeHtml(food.serving_size || '')}" style="width: 100%; box-sizing: border-box; margin-top: 4px; padding: 9px 12px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff;">
                    </label>

                    <div style="background: #141b28; border: 1px solid #283548; border-radius: 8px; padding: 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="font-size: 12px; font-weight: 700; color: var(--lime);">Nutrition per Serving</span>
                            <span style="font-size: 11px; color: var(--muted);">Auto-calculates kcal</span>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 8px;">
                            <div>
                                <span style="font-size: 10px; color: var(--muted); display: block; margin-bottom: 2px;">Calories</span>
                                <input type="number" name="calories" id="swal_cals" value="${food.calories}" required style="width: 100%; box-sizing: border-box; padding: 7px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff; font-weight: 700;">
                            </div>
                            <div>
                                <span style="font-size: 10px; color: var(--muted); display: block; margin-bottom: 2px;">Protein (g)</span>
                                <input type="number" step="0.1" name="protein_g" id="swal_p" value="${food.protein_g}" oninput="calculateSwalCals()" required style="width: 100%; box-sizing: border-box; padding: 7px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff;">
                            </div>
                            <div>
                                <span style="font-size: 10px; color: var(--muted); display: block; margin-bottom: 2px;">Carbs (g)</span>
                                <input type="number" step="0.1" name="carbs_g" id="swal_c" value="${food.carbs_g}" oninput="calculateSwalCals()" required style="width: 100%; box-sizing: border-box; padding: 7px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff;">
                            </div>
                            <div>
                                <span style="font-size: 10px; color: var(--muted); display: block; margin-bottom: 2px;">Fat (g)</span>
                                <input type="number" step="0.1" name="fat_g" id="swal_f" value="${food.fat_g}" oninput="calculateSwalCals()" required style="width: 100%; box-sizing: border-box; padding: 7px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff;">
                            </div>
                        </div>
                    </div>

                    <button type="button" onclick="switchSwalFoodTab(2)" style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.15); color: var(--ink); border-radius: 7px; padding: 8px 14px; font-size: 12.5px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: space-between; margin-top: 4px; transition: all 0.2s;">
                        <span>Continue to Photo & Description</span>
                        <span style="color: var(--lime); display: inline-flex; align-items: center; gap: 4px;">Next <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                    </button>
                </div>

                <!-- TAB 2: Photo & Details -->
                <div id="swal-tab-panel-2" class="swal-tab-panel" style="display: none;">
                    <div style="background: #141b28; border: 1px solid #283548; border-radius: 8px; padding: 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="font-size: 12px; font-weight: 700; color: var(--lime);">Meal Image</span>
                            <button type="button" onclick="Swal.close(); openFoodPhotoModal(${food.food_id}, ${JSON.stringify(food.name)}, ${JSON.stringify(food.meal_type)}, ${JSON.stringify(food.image_url || '')}, ${JSON.stringify(food.image_url || '')});" style="background: rgba(132, 204, 22, 0.15); border: 1px solid rgba(132, 204, 22, 0.3); color: var(--lime); font-size: 11px; font-weight: 700; border-radius: 4px; padding: 4px 9px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                <span>Open Photo Studio</span>
                            </button>
                        </div>
                        <label style="display: block; font-size: 11.5px; color: var(--muted); margin-bottom: 4px; font-weight: 600;">Upload New Photo (ImageKit):</label>
                        <input type="file" name="food_image" accept="image/png,image/jpeg,image/webp,image/jpg" style="width: 100%; box-sizing: border-box; padding: 7px 8px; border-radius: 6px; border: 1px solid #334155; background: #0f172a; color: #fff; font-size: 12px;">
                        
                        <div style="text-align: center; margin: 8px 0; font-size: 11px; color: var(--muted);">— OR web image link —</div>
                        <input type="url" name="image_url" value="${escapeHtml(food.image_url || '')}" placeholder="https://..." style="width: 100%; box-sizing: border-box; padding: 8px 12px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff; font-size: 12px;">
                    </div>

                    <label style="display:block; font-size: 13px; color: var(--muted); font-weight: 600;">Description & Ingredients
                        <textarea name="recipe_desc" rows="3" placeholder="Brief notes on ingredients or preparation..." style="width: 100%; box-sizing: border-box; margin-top: 4px; padding: 9px 12px; border-radius: 6px; border: 1px solid #334155; background: #1a2230; color: #fff; min-height: 80px; resize: vertical;">${escapeHtml(food.recipe_desc || '')}</textarea>
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
                <select id="importMealType" style="width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: 8px; border: 1px solid #334155; background: #1a2230; color: #fff; font-size: 13.5px;">
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
</script>
<?php
    render_footer();
}
