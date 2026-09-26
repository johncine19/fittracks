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

    // Auto-migration & seed check for ingredients
    try {
        $hasCol = (bool) $pdo->query("SHOW COLUMNS FROM food_items LIKE 'ingredients'")->fetch();
        if (!$hasCol) {
            $pdo->exec("ALTER TABLE food_items ADD COLUMN ingredients TEXT NULL AFTER recipe_desc");
        }
        $unseeded = $pdo->query("SELECT food_id, name FROM food_items WHERE (ingredients IS NULL OR ingredients = '') AND source = 'system'")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($unseeded)) {
            $staples = get_staple_recipes_ingredients();
            $upd = $pdo->prepare("UPDATE food_items SET ingredients = ? WHERE food_id = ?");
            foreach ($unseeded as $uItem) {
                $uName = trim((string)$uItem['name']);
                foreach ($staples as $sName => $sList) {
                    if (strcasecmp($uName, $sName) === 0 || stripos($uName, $sName) !== false || stripos($sName, $uName) !== false) {
                        $upd->execute([json_encode($sList), (int)$uItem['food_id']]);
                        break;
                    }
                }
            }
        }
    } catch (Throwable $e) {}

    // Handle AJAX request to view / fetch ingredients
    if ((isset($_GET['action']) && $_GET['action'] === 'get_ingredients') || (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && post('action') === 'get_ingredients')) {
        $foodId = (int) ($_GET['food_id'] ?? post('food_id'));
        $stmt = $pdo->prepare("SELECT * FROM food_items WHERE food_id = ? AND is_active = 1");
        $stmt->execute([$foodId]);
        $food = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$food) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Food item not found']);
            exit;
        }

        $ingredientsStruct = get_food_ingredients($food);
        $photoUrl = get_meal_photo_url($food['image_url'], $food['name'], $food['meal_type']);
        $food['photo_url'] = $photoUrl;
        $food['ingredients_data'] = $ingredientsStruct;
        $food['ingredients_text'] = format_ingredients_for_textarea($food['ingredients'], $food['name']);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'food' => $food]);
        exit;
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

            $ingredientsRaw = trim((string) post('ingredients')) ?: null;
            $ingredientsToSave = null;
            if (!empty($ingredientsRaw)) {
                $parsed = parse_ingredients_data($ingredientsRaw);
                $ingredientsToSave = !empty($parsed['all']) ? json_encode($parsed['all']) : $ingredientsRaw;
            }

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
                    (gym_id, name, meal_type, dietary_restriction, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc, ingredients, source)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'custom')
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
                    $desc,
                    $ingredientsToSave
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

            $ingredientsRaw = trim((string) post('ingredients')) ?: null;
            $ingredientsToSave = null;
            if (!empty($ingredientsRaw)) {
                $parsed = parse_ingredients_data($ingredientsRaw);
                $ingredientsToSave = !empty($parsed['all']) ? json_encode($parsed['all']) : $ingredientsRaw;
            }

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
                SET name = ?, meal_type = ?, dietary_restriction = ?, serving_size = ?, calories = ?, protein_g = ?, carbs_g = ?, fat_g = ?, recipe_desc = ?, ingredients = ?, image_url = COALESCE(?, image_url)
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
                $ingredientsToSave,
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
        $where[] = '(name LIKE ? OR recipe_desc LIKE ? OR ingredients LIKE ?)';
        $params[] = '%' . $searchQuery . '%';
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
    $foods = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($foods as $idx => $f) {
        $foods[$idx]['ingredients_data'] = get_food_ingredients($f);
        $foods[$idx]['ingredients_text'] = format_ingredients_for_textarea($f['ingredients'] ?? null, $f['name']);
    }

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

/* ========================================================
   Fresh & Clean Organic Design System (Food & Nutrition)
   ======================================================== */
.food-card-organic {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
}
.food-card-organic:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 22px rgba(0, 0, 0, 0.07);
    border-color: #cbd5e1;
}
[data-theme="dark"] .food-card-organic,
.dark .food-card-organic {
    background: var(--surface);
    border-color: var(--line);
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25);
}

.food-card-title {
    margin: 0 0 6px;
    font-size: 14.5px;
    font-weight: 700;
    color: #111827;
    line-height: 1.35;
    cursor: pointer;
    transition: color 0.15s ease;
}
.food-card-title:hover {
    color: #15803d !important;
}
[data-theme="dark"] .food-card-title {
    color: #f9fafb;
}
[data-theme="dark"] .food-card-title:hover {
    color: #4ade80 !important;
}

.food-card-desc {
    margin: 0 0 12px;
    font-size: 12px;
    color: #6b7280;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
[data-theme="dark"] .food-card-desc {
    color: #94a3b8;
}

.food-card-serving-row {
    font-size: 11.5px;
    color: #6b7280;
}
.food-card-serving-row strong {
    color: #111827;
}
[data-theme="dark"] .food-card-serving-row {
    color: #94a3b8;
}
[data-theme="dark"] .food-card-serving-row strong {
    color: #f1f5f9;
}

.food-macro-cell {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    padding: 5px 4px;
    border-radius: 6px;
    text-align: center;
    font-size: 11.5px;
    font-weight: 600;
    color: #111827;
}
.food-macro-cell .macro-lbl {
    display: block;
    font-size: 9.5px;
    color: #6b7280;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.2px;
}
[data-theme="dark"] .food-macro-cell {
    background: rgba(255, 255, 255, 0.04);
    border-color: var(--line);
    color: #f1f5f9;
}
[data-theme="dark"] .food-macro-cell .macro-lbl {
    color: #94a3b8;
}

/* Semi-translucent frosted glass badge for dish photography */
.food-tag-frosted {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    background: rgba(17, 24, 39, 0.65);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #f9fafb;
    padding: 2.5px 7.5px;
    border-radius: 5px;
    letter-spacing: 0.3px;
    display: inline-flex;
    align-items: center;
}

.food-calories-pill {
    position: absolute;
    bottom: 8px;
    right: 10px;
    background: rgba(17, 24, 39, 0.68);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    color: #fef08a;
    font-size: 11.5px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, 0.18);
    display: inline-flex;
    align-items: center;
    gap: 4.5px;
    z-index: 2;
}

.btn-view-ingredients {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #15803d;
    font-weight: 600;
    font-size: 12px;
    padding: 4.5px 11px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
}
.btn-view-ingredients:hover {
    background: #16a34a;
    border-color: #16a34a;
    color: #ffffff;
    box-shadow: 0 2px 8px rgba(22, 163, 74, 0.25);
}
.btn-view-ingredients:active {
    transform: scale(0.97);
}
[data-theme="dark"] .btn-view-ingredients {
    background: rgba(22, 163, 74, 0.14);
    border-color: rgba(34, 197, 94, 0.32);
    color: #4ade80;
}
[data-theme="dark"] .btn-view-ingredients:hover {
    background: #16a34a;
    border-color: #16a34a;
    color: #ffffff;
}

.food-card-footer {
    padding: 10px 14px;
    background: #f9fafb;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
}
[data-theme="dark"] .food-card-footer {
    background: rgba(0, 0, 0, 0.18);
    border-color: var(--line);
}

/* Ingredients Modal System */
.ing-modal-box {
    background: #ffffff !important;
    border: 1px solid #e5e7eb !important;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.16) !important;
}
[data-theme="dark"] .ing-modal-box {
    background: var(--panel-bg, #121721) !important;
    border-color: var(--line) !important;
}
.ing-modal-hero {
    position: relative;
    height: 125px;
    background: #000;
    overflow: hidden;
    flex-shrink: 0;
}
.ing-modal-close-btn {
    position: absolute;
    top: 10px;
    right: 12px;
    z-index: 10;
    background: rgba(0, 0, 0, 0.65);
    color: #fff;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    font-size: 20px;
    line-height: 1;
    border: 1px solid rgba(255, 255, 255, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.15s;
}
.ing-modal-close-btn:hover {
    background: rgba(0, 0, 0, 0.85);
}
.ing-modal-hero-badge {
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    padding: 2.5px 8px;
    border-radius: 5px;
    background: rgba(17, 24, 39, 0.65) !important;
    backdrop-filter: blur(8px) !important;
    -webkit-backdrop-filter: blur(8px) !important;
    border: 1px solid rgba(255, 255, 255, 0.2) !important;
    color: #f9fafb !important;
    letter-spacing: 0.3px;
}
.ing-modal-hero-subtitle {
    font-size: 10.5px;
    color: #4ade80;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin-bottom: 2px;
}
.ing-modal-hero-title {
    margin: 0;
    font-size: 18.5px;
    font-weight: 800;
    color: #ffffff;
    line-height: 1.25;
    text-shadow: 0 2px 6px rgba(0, 0, 0, 0.8);
}
.ing-modal-nutrition {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 12px 14px;
    margin-bottom: 14px;
}
[data-theme="dark"] .ing-modal-nutrition {
    background: var(--bg);
    border-color: var(--line);
}
.ing-modal-portion-text {
    font-size: 12px;
    color: #6b7280;
    font-weight: 600;
}
.ing-modal-portion-text strong {
    color: #111827;
}
[data-theme="dark"] .ing-modal-portion-text {
    color: var(--muted);
}
[data-theme="dark"] .ing-modal-portion-text strong {
    color: var(--ink);
}
.ing-modal-verified-badge {
    font-size: 10.5px;
    color: #15803d;
    font-weight: 700;
    background: #f0fdf4;
    padding: 2px 7px;
    border-radius: 4px;
    border: 1px solid #bbf7d0;
}
[data-theme="dark"] .ing-modal-verified-badge {
    background: rgba(22, 163, 74, 0.14);
    border-color: rgba(34, 197, 94, 0.3);
    color: #4ade80;
}
.ing-modal-macros-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    text-align: center;
}
.ing-modal-macro-cell {
    background: #ffffff;
    padding: 7px 4px;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}
[data-theme="dark"] .ing-modal-macro-cell {
    background: color-mix(in srgb, var(--surface) 90%, transparent);
    border-color: var(--line);
}
.ing-modal-macro-lbl {
    display: block;
    font-size: 9.5px;
    color: #6b7280;
    font-weight: 600;
    text-transform: uppercase;
}
[data-theme="dark"] .ing-modal-macro-lbl {
    color: var(--muted);
}
.ing-modal-macro-val {
    font-size: 14px;
    font-weight: 800;
}
.ing-modal-nutrition-note {
    margin-top: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    color: #6b7280;
    line-height: 1.35;
}
[data-theme="dark"] .ing-modal-nutrition-note {
    color: var(--muted);
}
.ing-modal-section-title {
    font-size: 12.5px;
    font-weight: 800;
    color: #111827;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
[data-theme="dark"] .ing-modal-section-title {
    color: var(--ink);
}
.ing-modal-items-count-badge {
    font-size: 11px;
    font-weight: 700;
    color: #6b7280;
    background: #f3f4f6;
    padding: 2px 7px;
    border-radius: 4px;
}
[data-theme="dark"] .ing-modal-items-count-badge {
    color: var(--muted);
    background: color-mix(in srgb, var(--ink) 8%, transparent);
}
.ing-modal-list-container {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
    padding-bottom: 4px;
}
.ing-modal-list-container > .ing-modal-item:only-child {
    grid-column: 1 / -1;
}
.ing-modal-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 7px 10px;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    transition: border-color 0.15s ease, background 0.15s ease;
    min-width: 0;
    gap: 6px;
}
[data-theme="dark"] .ing-modal-item {
    background: color-mix(in srgb, var(--surface) 80%, transparent);
    border-color: var(--line);
}
.ing-modal-item-name {
    font-size: 12px;
    font-weight: 600;
    color: #111827;
    line-height: 1.25;
    word-break: break-word;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
[data-theme="dark"] .ing-modal-item-name {
    color: var(--ink);
}
.ing-modal-item-measure {
    font-size: 11px;
    font-weight: 700;
    color: #111827;
    background: #f3f4f6;
    padding: 2.5px 7px;
    border-radius: 5px;
    border: 1px solid #e5e7eb;
    white-space: nowrap;
    flex-shrink: 0;
}
[data-theme="dark"] .ing-modal-item-measure {
    color: var(--ink);
    background: color-mix(in srgb, var(--ink) 9%, transparent);
    border-color: var(--line);
}
.ing-modal-item-opt-badge {
    font-size: 8.5px;
    font-weight: 700;
    color: #6b7280;
    background: #f3f4f6;
    padding: 1.5px 4px;
    border-radius: 4px;
    text-transform: uppercase;
    border: 1px dashed #d1d5db;
    letter-spacing: 0.2px;
}
[data-theme="dark"] .ing-modal-item-opt-badge {
    color: var(--muted);
    background: color-mix(in srgb, var(--ink) 6%, transparent);
    border-color: var(--line);
}
.ing-modal-toggle-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #15803d;
    font-size: 10.5px;
    font-weight: 700;
    padding: 3.5px 11px;
    border-radius: 16px;
    cursor: pointer;
    transition: all 0.15s ease;
    letter-spacing: 0.2px;
}
.ing-modal-toggle-btn:hover {
    background: #16a34a;
    border-color: #16a34a;
    color: #ffffff;
}
[data-theme="dark"] .ing-modal-toggle-btn {
    background: rgba(22, 163, 74, 0.12);
    border-color: rgba(34, 197, 94, 0.3);
    color: #4ade80;
}
[data-theme="dark"] .ing-modal-toggle-btn:hover {
    background: #16a34a;
    border-color: #16a34a;
    color: #ffffff;
}
.ing-modal-desc-box {
    background: #f9fafb;
    border-left: 3px solid #16a34a;
    padding: 8px 12px;
}
.ing-modal-desc-lbl {
    font-size: 10px;
    font-weight: 800;
    color: #15803d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 2px;
}
[data-theme="dark"] .ing-modal-desc-box {
    background: color-mix(in srgb, var(--surface) 60%, transparent);
    border-left-color: #4ade80;
}
[data-theme="dark"] .ing-modal-desc-lbl {
    color: #4ade80;
}
.ing-modal-body {
    padding: 14px 18px 24px;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    flex: 1 1 auto;
    min-height: 0;
}
.ing-modal-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 18px;
    flex-shrink: 0;
    border-top: 1px solid #e5e7eb;
    background: #f9fafb;
}
[data-theme="dark"] .ing-modal-footer {
    border-top-color: var(--line);
    background: color-mix(in srgb, var(--bg) 40%, var(--panel-bg, #121721));
}

/* SweetAlert and Modal Mobile Responsiveness & Centering */
@media (max-width: 640px) {
    .ft-modal-overlay {
        padding: 8px !important;
        align-items: center !important;
        justify-content: center !important;
    }
    .ft-modal-box {
        max-width: calc(100vw - 16px) !important;
        max-height: 94vh !important;
        height: auto !important;
        margin: auto !important;
        border-radius: 14px !important;
    }
    .ft-modal-header {
        padding: 12px 14px !important;
    }
    .ft-modal-body {
        padding: 12px 14px !important;
        -webkit-overflow-scrolling: touch !important;
    }
    .ft-modal-footer {
        padding: 10px 14px !important;
    }

    /* Ingredients Modal Mobile Optimizations */
    #modal-food-ingredients.ft-modal-overlay {
        padding: 6px !important;
        align-items: center !important;
        justify-content: center !important;
    }
    #modal-food-ingredients .ft-modal-box {
        width: 100% !important;
        max-width: calc(100vw - 12px) !important;
        max-height: 92vh !important;
        height: auto !important;
        display: flex !important;
        flex-direction: column !important;
        margin: auto !important;
        border-radius: 12px !important;
    }
    #modal-food-ingredients .ing-modal-hero {
        height: 82px !important;
        flex-shrink: 0 !important;
    }
    #modal-food-ingredients .ing-modal-close-btn {
        top: 6px !important;
        right: 6px !important;
        width: 26px !important;
        height: 26px !important;
        font-size: 16px !important;
    }
    #modal-food-ingredients .ing-modal-hero-badge {
        font-size: 9px !important;
        padding: 1.5px 5px !important;
    }
    #modal-food-ingredients .ing-modal-hero-subtitle {
        font-size: 8.5px !important;
        margin-bottom: 1px !important;
    }
    #modal-food-ingredients .ing-modal-hero-title {
        font-size: 14px !important;
        line-height: 1.2 !important;
    }
    #modal-food-ingredients .ing-modal-body {
        padding: 8px 10px 24px 10px !important;
        overflow-y: auto !important;
        -webkit-overflow-scrolling: touch !important;
        overscroll-behavior: contain !important;
        flex: 1 1 auto !important;
        min-height: 0 !important;
    }
    #modal-food-ingredients .ing-modal-nutrition {
        padding: 7px 9px !important;
        margin-bottom: 8px !important;
        border-radius: 7px !important;
    }
    #modal-food-ingredients .ing-modal-portion-text {
        font-size: 10.5px !important;
    }
    #modal-food-ingredients .ing-modal-verified-badge {
        font-size: 8px !important;
        padding: 1px 4px !important;
    }
    #modal-food-ingredients .ing-modal-macros-grid {
        gap: 4px !important;
    }
    #modal-food-ingredients .ing-modal-macro-cell {
        padding: 4px 2px !important;
        border-radius: 5px !important;
    }
    #modal-food-ingredients .ing-modal-macro-lbl {
        font-size: 8px !important;
    }
    #modal-food-ingredients .ing-modal-macro-val {
        font-size: 12px !important;
    }
    #modal-food-ingredients .ing-modal-nutrition-note {
        font-size: 9px !important;
        margin-top: 4px !important;
        line-height: 1.25 !important;
    }
    #modal-food-ingredients .ing-modal-section-title {
        font-size: 10.5px !important;
    }
    #modal-food-ingredients .ing-modal-items-count-badge {
        font-size: 9px !important;
        padding: 1px 4px !important;
    }
    #modal-food-ingredients .ing-modal-list-container {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 5px !important;
        padding-bottom: 4px !important;
    }
    #modal-food-ingredients .ing-modal-item {
        padding: 5px 6.5px !important;
        gap: 4px !important;
        border-radius: 6px !important;
    }
    #modal-food-ingredients .ing-modal-item-name {
        font-size: 11px !important;
        line-height: 1.2 !important;
    }
    #modal-food-ingredients .ing-modal-item-measure {
        font-size: 10px !important;
        padding: 2px 5px !important;
        border-radius: 4px !important;
    }
    #modal-food-ingredients .ing-modal-item-opt-badge {
        font-size: 8px !important;
        padding: 1px 3.5px !important;
    }
    #modal-food-ingredients .ing-modal-toggle-btn {
        font-size: 10px !important;
        padding: 3px 9px !important;
        gap: 3.5px !important;
    }
    #modal-food-ingredients .ing-modal-footer {
        padding: 6px 10px !important;
        flex-shrink: 0 !important;
    }
    #modal-food-ingredients .btn-modal-cancel {
        padding: 5px 12px !important;
        font-size: 11.5px !important;
        border-radius: 6px !important;
    }
    #modal-food-ingredients #ing-modal-footer-edit-container button {
        padding: 5px 8px !important;
        font-size: 11px !important;
        border-radius: 6px !important;
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
    background: var(--panel-soft, rgba(15, 23, 42, 0.6));
    padding: 4px;
    border-radius: 10px;
    border: 1px solid var(--line, rgba(255, 255, 255, 0.08));
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
    background: color-mix(in srgb, var(--ink) 6%, transparent);
}
.swal-tab-btn.active {
    color: var(--lime);
    background: #1a2230;
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

/* Modal Form Controls & Macro Cards */
.swal-field-label {
    display: block;
    font-size: 13px;
    color: var(--muted);
    font-weight: 600;
}
.swal-hint-code,
.swal-field-label code,
.swal2-popup code {
    background: #1e293b !important;
    color: #f1f5f9 !important;
    border: 1px solid #334155 !important;
    padding: 2px 7px !important;
    border-radius: 5px !important;
    font-family: inherit !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    display: inline-block !important;
    line-height: 1.3 !important;
}
.swal-form-control {
    width: 100% !important;
    box-sizing: border-box !important;
    padding: 9px 12px !important;
    border-radius: 6px !important;
    border: 1px solid #334155 !important;
    background: #1a2230 !important;
    color: #ffffff !important;
    font-size: 13px !important;
    font-family: inherit !important;
    transition: border-color 0.2s, box-shadow 0.2s, background-color 0.2s;
}
.swal-form-control:focus {
    border-color: var(--lime) !important;
    outline: none !important;
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--lime) 25%, transparent) !important;
}
.swal-card-box {
    background: #141b28;
    border: 1px solid #283548;
    border-radius: 8px;
    padding: 12px;
    transition: background 0.2s, border-color 0.2s;
}
.swal-macro-input {
    width: 100% !important;
    box-sizing: border-box !important;
    padding: 7px 8px !important;
    border-radius: 6px !important;
    border: 1px solid #334155 !important;
    background: #1a2230 !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    font-size: 13.5px !important;
    text-align: center;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.swal-macro-input:focus {
    border-color: var(--lime) !important;
    outline: none !important;
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--lime) 25%, transparent) !important;
}
.swal-tab-next-btn {
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: var(--ink);
    border-radius: 7px;
    padding: 8px 14px;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 4px;
    transition: all 0.2s;
}
.swal-tab-next-btn:hover {
    border-color: var(--lime);
    background: color-mix(in srgb, var(--lime) 12%, rgba(255, 255, 255, 0.06));
}

/* Light Mode Overrides for Food Modal */
[data-theme="light"] .swal-tabs-bar {
    background: #f1f5f9 !important;
    border: 1px solid #e2e8f0 !important;
}
[data-theme="light"] .swal-tab-btn {
    color: #64748b !important;
}
[data-theme="light"] .swal-tab-btn:hover {
    color: #0f172a !important;
    background: rgba(0, 0, 0, 0.04) !important;
}
[data-theme="light"] .swal-tab-btn.active {
    background: #ffffff !important;
    color: var(--lime-dark, #4d7c0f) !important;
    border: 1px solid #cbd5e1 !important;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.07) !important;
}
[data-theme="light"] .swal-tab-btn.active svg {
    stroke: var(--lime-dark, #4d7c0f) !important;
}
[data-theme="light"] .swal-field-label {
    color: #475569 !important;
}
[data-theme="light"] .swal-hint-code,
[data-theme="light"] .swal-field-label code,
[data-theme="light"] .swal2-popup code {
    background: #e2e8f0 !important;
    color: #0f172a !important;
    border: 1px solid #cbd5e1 !important;
}
[data-theme="light"] .swal-form-control {
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    color: #0f172a !important;
}
[data-theme="light"] .swal-form-control::placeholder {
    color: #94a3b8 !important;
}
[data-theme="light"] .swal-card-box {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
}
[data-theme="light"] .swal-macro-input {
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    color: #0f172a !important;
}
[data-theme="light"] .swal-tab-next-btn {
    background: #f8fafc !important;
    border: 1px solid #cbd5e1 !important;
    color: #0f172a !important;
}
[data-theme="light"] .swal-tab-next-btn:hover {
    background: color-mix(in srgb, var(--lime) 10%, #ffffff) !important;
    border-color: var(--lime) !important;
}
[data-theme="light"] .ft-modal-box {
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15) !important;
}
[data-theme="light"] .ft-modal-footer {
    background: #f8fafc !important;
    border-top: 1px solid #e2e8f0 !important;
}
[data-theme="light"] .photo-upload-dropzone {
    border: 2px dashed #cbd5e1 !important;
    background: #f8fafc !important;
}
[data-theme="light"] .photo-upload-dropzone:hover {
    border-color: var(--lime) !important;
    background: color-mix(in srgb, var(--lime) 8%, #ffffff) !important;
}
[data-theme="light"] .photo-modal-tab-btn {
    border-color: #cbd5e1 !important;
    color: #64748b !important;
}
[data-theme="light"] .photo-modal-tab-btn.active {
    background: color-mix(in srgb, var(--lime) 12%, #ffffff) !important;
    color: var(--lime-dark, #4d7c0f) !important;
    border-color: var(--lime) !important;
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
                    <div class="food-card-organic">
                        <!-- Food Card Header / Image -->
                        <div style="position: relative; height: 130px; background: #000; overflow: hidden;">
                            <img id="food-card-img-<?= $food['food_id'] ?>" src="<?= h($photoUrl) ?>" alt="<?= h($food['name']) ?>" style="width: 100%; height: 100%; object-fit: cover; opacity: 0.88;" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80';">
                            <div style="position: absolute; top: 10px; left: 10px; display: flex; gap: 6px; flex-wrap: wrap; z-index: 2;">
                                <span class="food-tag-frosted">
                                    <?= h($food['meal_type']) ?>
                                </span>
                                <?php if ($food['dietary_restriction'] !== 'none'): ?>
                                    <span class="food-tag-frosted" style="color: #6ee7b7;">
                                        <?= h($food['dietary_restriction']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div style="position: absolute; top: 10px; right: 10px; display: flex; align-items: center; gap: 6px; z-index: 2;">
                                <?php if ($isCustom): ?>
                                    <span class="food-tag-frosted" style="color: #c084fc; border-color: rgba(192, 132, 252, 0.4);">
                                        Gym Custom
                                    </span>
                                <?php else: ?>
                                    <span class="food-tag-frosted" style="color: #94a3b8;">
                                        Staple
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="food-calories-pill">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="#f59e0b" stroke="#f59e0b" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 17c1.38 0 2.5-1.12 2.5-2.5 0-1.63-1.04-2.88-1.78-3.77a6.8 6.8 0 0 1-1.42-2.43c-.04-.05-.1-.08-.16-.08s-.12.03-.16.08a8.87 8.87 0 0 0-1.48 2.43C8.16 11.68 8.5 13.3 8.5 14.5z"/><path d="M12 2c-.15 0-.3.06-.41.17-.11.11-.17.26-.17.41 0 1.25-.43 2.45-1.22 3.39A9.9 9.9 0 0 1 8 8.13C6.73 9.77 6 11.8 6 14c0 3.31 2.69 6 6 6s6-2.69 6-6c0-2.8-1.22-5.4-3.32-7.14C13.56 5.92 12.8 4.2 12.8 2.58c0-.15-.06-.3-.17-.41A.58.58 0 0 0 12 2z"/></svg>
                                <span><?= $food['calories'] ?> kcal</span>
                            </div>
                        </div>

                        <!-- Card Body -->
                        <div style="padding: 14px 16px; flex: 1; display: flex; flex-direction: column;">
                            <h4 class="food-card-title" onclick="openViewIngredientsModal(<?= (int)$food['food_id'] ?>)" title="Click to view ingredients & recipe breakdown"><?= h($food['name']) ?></h4>
                            <?php if ($food['recipe_desc']): ?>
                                <p class="food-card-desc">
                                    <?= h($food['recipe_desc']) ?>
                                </p>
                            <?php else: ?>
                                <div style="margin-bottom: 12px;"></div>
                            <?php endif; ?>

                            <!-- Serving Size & Macros Pill -->
                            <div style="margin-top: auto; padding-top: 10px; border-top: 1px solid #f1f5f9;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                    <span class="food-card-serving-row">Serving: <strong><?= h($food['serving_size']) ?></strong></span>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px; text-align: center;">
                                    <div class="food-macro-cell">
                                        <span class="macro-lbl">Protein</span>
                                        <span><?= $food['protein_g'] ?>g</span>
                                    </div>
                                    <div class="food-macro-cell">
                                        <span class="macro-lbl">Carbs</span>
                                        <span><?= $food['carbs_g'] ?>g</span>
                                    </div>
                                    <div class="food-macro-cell">
                                        <span class="macro-lbl">Fat</span>
                                        <span><?= $food['fat_g'] ?>g</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Card Actions Footer -->
                        <div class="food-card-footer">
                            <button type="button" 
                                    onclick="openViewIngredientsModal(<?= (int)$food['food_id'] ?>)" 
                                    class="btn-view-ingredients" 
                                    title="View ingredients and measurements">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 10 10H12V2z"></path><path d="M12 12 2.1 10.5"></path><path d="M12 12V2"></path></svg>
                                <span>View Ingredients</span>
                            </button>
                            <div style="display: flex; align-items: center; gap: 6px;">
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

<!-- View Food Ingredients & Recipe Breakdown Modal -->
<div class="ft-modal-overlay" id="modal-food-ingredients" style="display: none;" onclick="if(event.target===this) closeViewIngredientsModal()">
    <div class="ft-modal-box ing-modal-box" style="max-width: 540px;">
        <!-- Hero Header with Image -->
        <div class="ing-modal-hero">
            <img id="ing-modal-hero-img" src="" alt="Food Preview" style="width: 100%; height: 100%; object-fit: cover; opacity: 0.75;" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80';">
            <div style="position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,0.2) 0%, rgba(18,23,33,0.92) 100%);"></div>
            
            <button type="button" class="ft-modal-close ing-modal-close-btn" onclick="closeViewIngredientsModal()" title="Close">&times;</button>

            <div style="position: absolute; top: 10px; left: 14px; display: flex; gap: 5px; z-index: 5;">
                <span id="ing-modal-meal-type" class="ing-modal-hero-badge"></span>
                <span id="ing-modal-diet-type" class="ing-modal-hero-badge" style="color: #6ee7b7;"></span>
            </div>

            <div style="position: absolute; bottom: 10px; left: 14px; right: 14px; z-index: 5;">
                <div class="ing-modal-hero-subtitle">Recipe & Ingredients Breakdown</div>
                <h3 id="ing-modal-title" class="ing-modal-hero-title"></h3>
            </div>
        </div>

        <div class="ft-modal-body ing-modal-body">
            <!-- Serving & Nutrition Derivation Grid -->
            <div class="ing-modal-nutrition">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 6px;">
                    <span class="ing-modal-portion-text">
                        Portion / Serving Size: <strong style="color: var(--ink);" id="ing-modal-serving"></strong>
                    </span>
                    <span class="ing-modal-verified-badge">
                        Verified Nutrition
                    </span>
                </div>

                <div class="ing-modal-macros-grid">
                    <div class="ing-modal-macro-cell">
                        <span class="ing-modal-macro-lbl">Calories</span>
                        <span class="ing-modal-macro-val" style="color: #15803d;" id="ing-modal-cals"></span>
                    </div>
                    <div class="ing-modal-macro-cell">
                        <span class="ing-modal-macro-lbl">Protein</span>
                        <span class="ing-modal-macro-val" style="color: #2563eb;" id="ing-modal-protein"></span>
                    </div>
                    <div class="ing-modal-macro-cell">
                        <span class="ing-modal-macro-lbl">Carbs</span>
                        <span class="ing-modal-macro-val" style="color: #d97706;" id="ing-modal-carbs"></span>
                    </div>
                    <div class="ing-modal-macro-cell">
                        <span class="ing-modal-macro-lbl">Fat</span>
                        <span class="ing-modal-macro-val" style="color: #dc2626;" id="ing-modal-fat"></span>
                    </div>
                </div>

                <div class="ing-modal-nutrition-note">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.2" style="flex-shrink: 0;"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    <span>Nutritional values are calculated directly from measured portion weights of ingredients below.</span>
                </div>
            </div>

            <!-- Unified Ingredients List -->
            <div style="margin-bottom: 12px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 7px;">
                    <div style="display: flex; align-items: center; gap: 7px;">
                        <span style="display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: #16a34a;"></span>
                        <span class="ing-modal-section-title">Ingredients</span>
                    </div>
                    <span id="ing-modal-items-count" class="ing-modal-items-count-badge"></span>
                </div>
                <div id="ing-modal-items-list" class="ing-modal-list-container"></div>
                <div id="ing-modal-show-all-container" style="display: none; margin-top: 6px; text-align: center;">
                    <button type="button" id="ing-modal-show-all-btn" class="ing-modal-toggle-btn" onclick="toggleIngredientsShowAll()">
                        <span id="ing-modal-show-all-text">Show all</span>
                        <svg id="ing-modal-show-all-icon" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="transition: transform 0.2s;"><path d="M6 9l6 6 6-9"/></svg>
                    </button>
                </div>
            </div>

            <!-- Recipe Notes & Preparation (if any) -->
            <div id="ing-modal-desc-box" class="ing-modal-desc-box" style="display: none; border-radius: 0 8px 8px 0; margin-top: 10px;">
                <div class="ing-modal-desc-lbl">
                    Preparation & Notes
                </div>
                <div id="ing-modal-desc" style="font-size: 11.5px; color: var(--ink); line-height: 1.4;"></div>
            </div>
        </div>

        <div class="ft-modal-footer ing-modal-footer">
            <div id="ing-modal-footer-edit-container">
                <!-- Injected dynamically if user has edit permission -->
            </div>
            <button type="button" class="btn-modal-cancel" onclick="closeViewIngredientsModal()" style="padding: 7px 18px;">Close</button>
        </div>
    </div>
</div>

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
                <div style="background: var(--panel-soft); border: 1px dashed var(--line); border-radius: 10px; padding: 18px; text-align: center;">
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
const FT_CURRENT_USER_ROLE = <?= json_encode($user['role']) ?>;
const FT_CURRENT_GYM_ID = <?= json_encode($gymId) ?>;
const FT_FOODS_MAP = <?= json_encode(array_combine(
    array_column($foods, 'food_id'),
    array_map(function($f) {
        $f['photo_url'] = get_meal_photo_url($f['image_url'], $f['name'], $f['meal_type']);
        return $f;
    }, $foods)
), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?: '{}' ?>;

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
                <button type="button" onclick="editFromIngredientsModal()" class="btn-sm btn-ghost" style="padding: 6px 12px; font-size: 12.5px; border-radius: 7px; color: var(--lime); border-color: rgba(132,204,22,0.35); display: inline-flex; align-items: center; gap: 5px; font-weight: 600;">
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
                <?= csrf_field() ?>
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

                    <?php if ($user['role'] === 'platform_admin'): ?>
                        <label style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--ink); cursor: pointer;">
                            <input type="checkbox" name="is_global" value="1">
                            <span>Save as platform global staple (available to all gyms)</span>
                        </label>
                    <?php endif; ?>

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
                <?= csrf_field() ?>
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
</script>
<?php
    render_footer();
}
