<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/file_handler.php';

if (!function_exists('render_food_verification_badge')) {
    function render_food_verification_badge(string $status, bool $isCustom = true, ?string $rejectionReason = null): string {
        if (!$isCustom) {
            return '<span class="food-tag-frosted" style="color: #94a3b8;">Staple</span>';
        }
        return match ($status) {
            'verified' => '<span class="food-vbadge vbadge-verified" title="Reviewed and verified by Platform Administrator"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Verified</span>',
            'rejected' => '<span class="food-vbadge vbadge-rejected" title="' . (!empty($rejectionReason) ? 'Rejected: ' . htmlspecialchars($rejectionReason, ENT_QUOTES, 'UTF-8') : 'Rejected by Platform Administrator') . '"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Rejected</span>',
            default => '<span class="food-vbadge vbadge-unverified" title="Submitted and awaiting review by Platform Administrator"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Unverified</span>',
        };
    }
}

function food_library_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner', 'trainer']);
    $pdo = db();
    verify_csrf();

    $gymId = null;
    if ($user['role'] === 'gym_owner' || $user['role'] === 'trainer') {
        $gymId = get_user_gym_id($user);
        if (!$gymId) {
            flash('No gym assigned to your account.', 'danger');
            redirect('dashboard');
        }
        if ($user['role'] === 'gym_owner') {
            require_gym_feature('food_library');
        }
    }

    // Auto-migration & seed check for ingredients & verification columns
    try {
        $hasCol = (bool) $pdo->query("SHOW COLUMNS FROM food_items LIKE 'ingredients'")->fetch();
        if (!$hasCol) {
            $pdo->exec("ALTER TABLE food_items ADD COLUMN ingredients TEXT NULL AFTER recipe_desc");
        }
        $hasVerif = (bool) $pdo->query("SHOW COLUMNS FROM food_items LIKE 'verification_status'")->fetch();
        if (!$hasVerif) {
            $pdo->exec("ALTER TABLE food_items ADD COLUMN verification_status ENUM('unverified', 'verified', 'rejected') NOT NULL DEFAULT 'unverified' AFTER source");
            $pdo->exec("ALTER TABLE food_items ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER verification_status");
            $pdo->exec("ALTER TABLE food_items ADD COLUMN verified_at DATETIME DEFAULT NULL AFTER rejection_reason");
            $pdo->exec("ALTER TABLE food_items ADD COLUMN verified_by_user_id INT DEFAULT NULL AFTER verified_at");
            $pdo->exec("ALTER TABLE food_items ADD COLUMN created_by_user_id INT DEFAULT NULL AFTER verified_by_user_id");
            $pdo->exec("UPDATE food_items SET verification_status = 'verified' WHERE source = 'system' OR gym_id IS NULL");
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
                $initialStatus = ($user['role'] === 'platform_admin') ? 'verified' : 'unverified';
                $stmt = $pdo->prepare("
                    INSERT INTO food_items 
                    (gym_id, name, meal_type, dietary_restriction, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc, ingredients, source, verification_status, created_by_user_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'custom', ?, ?)
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
                    $ingredientsToSave,
                    $initialStatus,
                    (int)$user['user_id']
                ]);

                if ($initialStatus === 'unverified') {
                    $creatorName = $user['name'] ?? 'Gym Staff';
                    notify_admins('system', 'New Custom Food Submitted', "A custom food '{$name}' was added by {$creatorName} and is awaiting review in the Food Library.");
                    flash("Custom food item '{$name}' added to your library! (Status: Unverified — awaiting review by Platform Administrator)", 'success');
                } else {
                    flash('New food item added to library!', 'success');
                }
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
            $stmtEx = $pdo->prepare("SELECT * FROM food_items WHERE food_id = ?");
            $stmtEx->execute([$foodId]);
            $existing = $stmtEx->fetch(PDO::FETCH_ASSOC);
            if (!is_array($existing)) {
                flash('Food item not found.', 'danger');
                redirect('food_library');
            }

            if (in_array($user['role'], ['gym_owner', 'trainer'], true) && (string)$existing['gym_id'] !== (string)$gymId) {
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

            // Automatic Status Updates:
            // If a rejected food is edited and resubmitted, return it to the Unverified status until it is reviewed again.
            $newVerificationStatus = $existing['verification_status'] ?? 'verified';
            $resetRejectionReason = false;

            if ($user['role'] !== 'platform_admin') {
                if ($existing['verification_status'] === 'rejected' || $existing['source'] === 'custom') {
                    $newVerificationStatus = 'unverified';
                    $resetRejectionReason = true;
                }
            }

            $stmt = $pdo->prepare("
                UPDATE food_items 
                SET name = ?, meal_type = ?, dietary_restriction = ?, serving_size = ?, calories = ?, protein_g = ?, carbs_g = ?, fat_g = ?, recipe_desc = ?, ingredients = ?, image_url = COALESCE(?, image_url), verification_status = ?, rejection_reason = CASE WHEN ? = 1 THEN NULL ELSE rejection_reason END
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
                $newVerificationStatus,
                $resetRejectionReason ? 1 : 0,
                $foodId
            ]);

            if ($newVerificationStatus === 'unverified' && ($existing['verification_status'] ?? '') === 'rejected') {
                notify_admins('system', 'Rejected Food Resubmitted', "The custom food '{$name}' was edited and resubmitted for verification.");
                flash("Food item updated and resubmitted for Platform Admin review (Status: Unverified).", 'success');
            } else {
                flash('Food item updated successfully!', 'success');
            }
            redirect('food_library');
        }

        if ($postAction === 'approve_food') {
            if ($user['role'] !== 'platform_admin') {
                flash('Permission denied: Only platform administrators can approve custom foods.', 'danger');
                redirect('food_library');
            }
            $foodId = (int) post('food_id');
            $stmtF = $pdo->prepare("SELECT fi.*, g.name AS gym_name FROM food_items fi LEFT JOIN gyms g ON g.gym_id = fi.gym_id WHERE fi.food_id = ?");
            $stmtF->execute([$foodId]);
            $foodRow = $stmtF->fetch(PDO::FETCH_ASSOC);
            if (is_array($foodRow)) {
                $up = $pdo->prepare("UPDATE food_items SET verification_status = 'verified', verified_at = NOW(), verified_by_user_id = ?, rejection_reason = NULL WHERE food_id = ?");
                $up->execute([(int)$user['user_id'], $foodId]);

                // Notify creator or gym owner
                $notifyTarget = (int)($foodRow['created_by_user_id'] ?? 0);
                if (!$notifyTarget && !empty($foodRow['gym_id'])) {
                    $notifyTarget = (int) scalar('SELECT owner_user_id FROM gyms WHERE gym_id = ?', [(int)$foodRow['gym_id']]);
                }
                if ($notifyTarget > 0) {
                    notify_user($notifyTarget, 'system', 'Custom Food Approved', "Your custom food '{$foodRow['name']}' has been reviewed and approved by Platform Administrator.");
                }
                flash("Custom food '{$foodRow['name']}' has been approved and marked as Verified.", 'success');
            }
            $returnTab = post('return_tab') === 'verifications' ? 'verifications' : 'library';
            redirect("food_library&tab={$returnTab}");
        }

        if ($postAction === 'reject_food') {
            if ($user['role'] !== 'platform_admin') {
                flash('Permission denied: Only platform administrators can reject custom foods.', 'danger');
                redirect('food_library');
            }
            $foodId = (int) post('food_id');
            $reason = trim((string) post('rejection_reason'));
            $stmtF = $pdo->prepare("SELECT fi.*, g.name AS gym_name FROM food_items fi LEFT JOIN gyms g ON g.gym_id = fi.gym_id WHERE fi.food_id = ?");
            $stmtF->execute([$foodId]);
            $foodRow = $stmtF->fetch(PDO::FETCH_ASSOC);
            if (is_array($foodRow)) {
                $savedReason = $reason !== '' ? $reason : 'Did not meet nutritional validation criteria.';
                $up = $pdo->prepare("UPDATE food_items SET verification_status = 'rejected', verified_at = NOW(), verified_by_user_id = ?, rejection_reason = ? WHERE food_id = ?");
                $up->execute([(int)$user['user_id'], $savedReason, $foodId]);

                // Notify creator or gym owner
                $notifyTarget = (int)($foodRow['created_by_user_id'] ?? 0);
                if (!$notifyTarget && !empty($foodRow['gym_id'])) {
                    $notifyTarget = (int) scalar('SELECT owner_user_id FROM gyms WHERE gym_id = ?', [(int)$foodRow['gym_id']]);
                }
                if ($notifyTarget > 0) {
                    $reasonMsg = $reason !== '' ? " Reason: {$reason}" : '';
                    notify_user($notifyTarget, 'system', 'Custom Food Rejected', "Your custom food '{$foodRow['name']}' was not approved by Platform Administrator.{$reasonMsg}");
                }
                flash("Custom food '{$foodRow['name']}' has been rejected.", 'info');
            }
            $returnTab = post('return_tab') === 'verifications' ? 'verifications' : 'library';
            redirect("food_library&tab={$returnTab}");
        }

        if ($postAction === 'update_food_photo') {
            $foodId = (int) post('food_id');
            $photoType = (string) post('photo_type'); // 'upload', 'url', 'reset'
            $customUrl = trim((string) post('photo_url'));

            $stmtCheck = $pdo->prepare('SELECT food_id, gym_id, name, meal_type, image_url FROM food_items WHERE food_id = ? AND is_active = 1');
            $stmtCheck->execute([$foodId]);
            $foodRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$foodRow || !is_array($foodRow)) {
                if (ob_get_level()) ob_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Food item not found.']);
                exit;
            }

            if (!in_array($user['role'], ['platform_admin', 'gym_owner', 'trainer'], true)) {
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
            $stmtDel = $pdo->prepare("SELECT * FROM food_items WHERE food_id = ?");
            $stmtDel->execute([$foodId]);
            $existing = $stmtDel->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                if (in_array($user['role'], ['gym_owner', 'trainer'], true) && (string)$existing['gym_id'] !== (string)$gymId) {
                    flash('Permission denied.', 'danger');
                } else {
                    $pdo->prepare("DELETE FROM food_items WHERE food_id = ?")->execute([$foodId]);
                    flash('Food item removed from library.', 'info');
                }
            }
            redirect('food_library');
        }
    }

    // Verification Status Filter
    $statusFilter = trim((string) ($_GET['status'] ?? 'all'));

    // Build Query for List Tab
    $where = ['is_active = 1'];
    $params = [];

    if (in_array($user['role'], ['gym_owner', 'trainer'], true)) {
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

    if (in_array($statusFilter, ['unverified', 'verified', 'rejected'], true)) {
        $where[] = 'verification_status = ?';
        $params[] = $statusFilter;
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

    // Platform Admin Food Verification Approvals Tab Data
    $pendingVerificationsCount = 0;
    $verifiedVerificationsCount = 0;
    $rejectedVerificationsCount = 0;
    $vItems = [];
    $allGymsList = [];
    $vStatus = 'all';
    $vGymId = 0;
    $vSearch = '';

    if ($user['role'] === 'platform_admin') {
        $pendingVerificationsCount = (int)$pdo->query("SELECT COUNT(*) FROM food_items WHERE gym_id IS NOT NULL AND verification_status = 'unverified' AND is_active = 1")->fetchColumn();
        $verifiedVerificationsCount = (int)$pdo->query("SELECT COUNT(*) FROM food_items WHERE gym_id IS NOT NULL AND verification_status = 'verified' AND is_active = 1")->fetchColumn();
        $rejectedVerificationsCount = (int)$pdo->query("SELECT COUNT(*) FROM food_items WHERE gym_id IS NOT NULL AND verification_status = 'rejected' AND is_active = 1")->fetchColumn();

        if ($activeTab === 'verifications') {
            $vStatus = trim((string) ($_GET['v_status'] ?? 'all'));
            $vGymId = (int)($_GET['v_gym'] ?? 0);
            $vSearch = trim((string) ($_GET['v_q'] ?? ''));

            $vWhere = ['f.gym_id IS NOT NULL', 'f.is_active = 1'];
            $vParams = [];

            if (in_array($vStatus, ['unverified', 'verified', 'rejected'], true)) {
                $vWhere[] = 'f.verification_status = ?';
                $vParams[] = $vStatus;
            }
            if ($vGymId > 0) {
                $vWhere[] = 'f.gym_id = ?';
                $vParams[] = $vGymId;
            }
            if ($vSearch) {
                $vWhere[] = '(f.name LIKE ? OR f.ingredients LIKE ? OR g.name LIKE ?)';
                $vParams[] = "%{$vSearch}%";
                $vParams[] = "%{$vSearch}%";
                $vParams[] = "%{$vSearch}%";
            }

            $vWhereSql = implode(' AND ', $vWhere);
            $vStmt = $pdo->prepare("
                SELECT f.*, g.name as gym_name,
                       TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as submitter_name,
                       u.role as submitter_role,
                       TRIM(CONCAT(COALESCE(v.first_name, ''), ' ', COALESCE(v.last_name, ''))) as reviewer_name
                FROM food_items f
                LEFT JOIN gyms g ON f.gym_id = g.gym_id
                LEFT JOIN users u ON f.created_by_user_id = u.user_id
                LEFT JOIN users v ON f.verified_by_user_id = v.user_id
                WHERE {$vWhereSql}
                ORDER BY CASE WHEN f.verification_status = 'unverified' THEN 0 ELSE 1 END, f.food_id DESC
            ");
            $vStmt->execute($vParams);
            $vItems = $vStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($vItems as $idx => $item) {
                $vItems[$idx]['ingredients_data'] = get_food_ingredients($item);
                $vItems[$idx]['ingredients_text'] = format_ingredients_for_textarea($item['ingredients'] ?? null, $item['name']);
            }

            $allGymsList = $pdo->query("SELECT gym_id, name as gym_name FROM gyms ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // Helper URL for pagination and filters
    $buildFilterUrl = function(array $overrides = []) use ($searchQuery, $mealTypeFilter, $restrictionFilter, $scopeFilter, $statusFilter, $page) {
        $q = [
            'page' => 'food_library',
            'tab' => 'library',
            'q' => $searchQuery,
            'meal_type' => $mealTypeFilter,
            'restriction' => $restrictionFilter,
            'scope' => $scopeFilter,
            'status' => $statusFilter,
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
        if (isset($q['status']) && $q['status'] === 'all') {
            unset($q['status']);
        }
        return 'index.php?' . http_build_query($q);
    };

    $paginationBaseUrl = 'index.php?page=food_library&tab=library';
    if ($searchQuery) $paginationBaseUrl .= '&q=' . urlencode($searchQuery);
    if ($mealTypeFilter) $paginationBaseUrl .= '&meal_type=' . urlencode($mealTypeFilter);
    if ($restrictionFilter) $paginationBaseUrl .= '&restriction=' . urlencode($restrictionFilter);
    if ($scopeFilter && $scopeFilter !== 'all') $paginationBaseUrl .= '&scope=' . urlencode($scopeFilter);
    if ($statusFilter && $statusFilter !== 'all') $paginationBaseUrl .= '&status=' . urlencode($statusFilter);

    render_header('Food Library', $user);
?>
<link rel="stylesheet" href="<?= h(asset_url('css/pages/food_library.css')) ?>">

<section class="panel" style="margin-bottom: 24px;">
    <!-- Page Header & Action Toolbar -->
    <div class="food-page-header">
        <div class="food-page-header-info">
            <div class="food-page-title-row">
                <h1 class="food-page-title">Food & Recipe Library</h1>
                <span class="food-count-badge">
                    <?= $totalFoods ?> <?= $totalFoods === 1 ? 'Food' : 'Foods' ?>
                </span>
            </div>
            <p class="food-page-desc">
                Manage meal templates, customize gym food options, or import branded groceries from online nutrition databases.
            </p>
        </div>
        <div class="food-page-header-actions">
            <button onclick="openAddFoodModal()" class="food-add-btn">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <span>Add Custom Food</span>
            </button>
        </div>
    </div>

    <!-- Navigation Tabs: Library vs Online Search vs Verifications -->
    <div class="food-tabs-bar">
        <a href="index.php?page=food_library&tab=library" class="food-tab-link <?= $activeTab === 'library' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"></path><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path><line x1="6" y1="1" x2="6" y2="4"></line><line x1="10" y1="1" x2="10" y2="4"></line><line x1="14" y1="1" x2="14" y2="4"></line></svg>
            <span>Food Catalog</span>
        </a>
        <a href="index.php?page=food_library&tab=online_import" class="food-tab-link <?= $activeTab === 'online_import' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
            <span>Online Nutrition Search (Open Food Facts)</span>
        </a>
        <?php if ($user['role'] === 'platform_admin'): ?>
            <a href="index.php?page=food_library&tab=verifications" class="food-tab-link <?= $activeTab === 'verifications' ? 'active' : '' ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
                <span>Custom Food Approvals</span>
                <?php if ($pendingVerificationsCount > 0): ?>
                    <span class="tab-badge-pending"><?= $pendingVerificationsCount ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    </div>

    <!-- Navigation Tabs: Mobile FitDropdown -->
    <div class="food-tabs-mobile-wrap">
        <label class="food-tabs-mobile-label">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
            <span>Food Library View</span>
        </label>
        <div id="mobileFoodTabDropdown"></div>
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
                <select name="status" onchange="this.form.submit()">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Verification Statuses</option>
                    <option value="unverified" <?= $statusFilter === 'unverified' ? 'selected' : '' ?>>Unverified (Pending Review)</option>
                    <option value="verified" <?= $statusFilter === 'verified' ? 'selected' : '' ?>>Verified Only</option>
                    <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected Submissions</option>
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

            <?php if ($searchQuery || $mealTypeFilter || $restrictionFilter || $scopeFilter !== 'all' || $statusFilter !== 'all'): ?>
                <div class="filter-btn-col">
                    <a href="index.php?page=food_library&tab=library" class="btn btn-filter-reset" title="Reset all filters">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        <span>Reset</span>
                    </a>
                </div>
            <?php endif; ?>
        </form>

        <?php 
        $hasActiveFilters = !empty($searchQuery) || !empty($mealTypeFilter) || !empty($restrictionFilter) || ($scopeFilter && $scopeFilter !== 'all') || ($statusFilter && $statusFilter !== 'all');
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

                <?php if (!empty($statusFilter) && $statusFilter !== 'all'): ?>
                    <a href="<?= $buildFilterUrl(['status' => 'all', 'p' => 1]) ?>" class="filter-chip" title="Remove verification status filter">
                        <span>Status: <strong><?= ucfirst($statusFilter) ?></strong></span>
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
                <button onclick="openAddFoodModal()" class="food-add-btn">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    <span>Add Custom Food</span>
                </button>
            </div>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(310px, 1fr)); gap: 16px;">
                <?php foreach ($foods as $food): 
                    $photoUrl = get_meal_photo_url($food['image_url'], $food['name'], $food['meal_type']);
                    $isCustom = !empty($food['gym_id']);
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
                                <?= render_food_verification_badge($food['verification_status'] ?? 'unverified', $isCustom, $food['rejection_reason'] ?? null) ?>
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
                                <div style="margin-bottom: 8px;"></div>
                            <?php endif; ?>

                            <?php if (($food['verification_status'] ?? '') === 'rejected' && !empty($food['rejection_reason'])): ?>
                                <div class="food-rejected-alert">
                                    <strong style="color: #ef4444;">Admin Rejection Note:</strong> <?= h($food['rejection_reason']) ?>
                                    <div style="font-size: 10.5px; opacity: 0.85; margin-top: 2px;">Editing and saving this food will return it to Unverified status.</div>
                                </div>
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
                                <?php 
                                $canManageFood = ($user['role'] === 'platform_admin' || ($isCustom && in_array($user['role'], ['gym_owner', 'trainer'], true) && (string)$food['gym_id'] === (string)$gymId));
                                ?>
                                <?php if ($canManageFood): ?>
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

    <?php elseif ($activeTab === 'verifications' && $user['role'] === 'platform_admin'): ?>
        <!-- Dedicated Food Verification Review Interface -->
        <div class="verif-stats-grid">
            <div class="verif-stat-card">
                <div class="verif-stat-icon pending">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div>
                    <div class="verif-stat-val" style="color: #f59e0b;"><?= $pendingVerificationsCount ?></div>
                    <div class="verif-stat-lbl">Pending Review</div>
                </div>
            </div>
            <div class="verif-stat-card">
                <div class="verif-stat-icon verified">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
                <div>
                    <div class="verif-stat-val" style="color: #84cc16;"><?= $verifiedVerificationsCount ?></div>
                    <div class="verif-stat-lbl">Verified Custom Foods</div>
                </div>
            </div>
            <div class="verif-stat-card">
                <div class="verif-stat-icon rejected">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                </div>
                <div>
                    <div class="verif-stat-val" style="color: #ef4444;"><?= $rejectedVerificationsCount ?></div>
                    <div class="verif-stat-lbl">Rejected Submissions</div>
                </div>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <form method="get" class="verif-filter-bar">
            <input type="hidden" name="page" value="food_library">
            <input type="hidden" name="tab" value="verifications">

            <!-- Desktop Pill Filter Group -->
            <div class="verif-pill-group">
                <a href="index.php?page=food_library&tab=verifications&v_status=unverified<?= $vGymId ? '&v_gym='.$vGymId : '' ?><?= $vSearch ? '&v_q='.urlencode($vSearch) : '' ?>" class="verif-pill-btn <?= ($vStatus === 'unverified') ? 'active' : '' ?>">
                    <span>Pending Review</span>
                    <span style="font-size: 11px; opacity: 0.85;">(<?= $pendingVerificationsCount ?>)</span>
                </a>
                <a href="index.php?page=food_library&tab=verifications&v_status=all<?= $vGymId ? '&v_gym='.$vGymId : '' ?><?= $vSearch ? '&v_q='.urlencode($vSearch) : '' ?>" class="verif-pill-btn <?= ($vStatus === 'all') ? 'active' : '' ?>">
                    <span>All Submissions</span>
                </a>
                <a href="index.php?page=food_library&tab=verifications&v_status=verified<?= $vGymId ? '&v_gym='.$vGymId : '' ?><?= $vSearch ? '&v_q='.urlencode($vSearch) : '' ?>" class="verif-pill-btn <?= ($vStatus === 'verified') ? 'active' : '' ?>">
                    <span>Verified</span>
                    <span style="font-size: 11px; opacity: 0.85;">(<?= $verifiedVerificationsCount ?>)</span>
                </a>
                <a href="index.php?page=food_library&tab=verifications&v_status=rejected<?= $vGymId ? '&v_gym='.$vGymId : '' ?><?= $vSearch ? '&v_q='.urlencode($vSearch) : '' ?>" class="verif-pill-btn <?= ($vStatus === 'rejected') ? 'active' : '' ?>">
                    <span>Rejected</span>
                    <span style="font-size: 11px; opacity: 0.85;">(<?= $rejectedVerificationsCount ?>)</span>
                </a>
            </div>

            <!-- Mobile Verification Status Dropdown (FitDropdown) -->
            <div class="verif-dropdown-mobile-wrap">
                <label class="verif-mobile-dropdown-label">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
                    <span>Status Filter</span>
                </label>
                <div id="mobileVerifFilterDropdown"></div>
            </div>

            <div class="verif-filter-search-row">
                <div class="verif-search-input-wrap">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="verif-search-icon"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <input type="text" name="v_q" id="verifSearchInput" value="<?= h($vSearch) ?>" placeholder="Search custom food name or ingredients...">
                    <?php if ($vSearch): ?>
                        <button type="button" onclick="document.getElementById('verifSearchInput').value=''; this.form.submit();" class="filter-search-clear" title="Clear search">&times;</button>
                    <?php endif; ?>
                </div>
                
                <select name="v_gym" onchange="this.form.submit()">
                    <option value="0">All Gyms</option>
                    <?php foreach ($allGymsList as $g): ?>
                        <option value="<?= $g['gym_id'] ?>" <?= $vGymId === (int)$g['gym_id'] ? 'selected' : '' ?>><?= h($g['gym_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <!-- Submissions Table -->
        <?php if (empty($vItems)): ?>
            <div style="text-align: center; padding: 60px 20px; background: var(--surface); border: 1px dashed var(--line); border-radius: 12px;">
                <div style="font-size: 32px; margin-bottom: 8px;">🥗</div>
                <h3 style="margin: 0 0 6px; font-size: 16px; color: var(--ink);">No submissions found</h3>
                <p style="margin: 0; font-size: 13px; color: var(--muted);">There are no custom foods matching the selected filter criteria.</p>
            </div>
        <?php else: ?>
            <div class="verif-table-wrapper">
                <table class="verif-table">
                    <thead>
                        <tr>
                            <th class="verif-col-food">Custom Food Item</th>
                            <th class="verif-col-gym">Gym & Submitter</th>
                            <th class="verif-col-portion">Portion & Calories</th>
                            <th class="verif-col-macros">Macros (P / C / F)</th>
                            <th class="verif-col-status">Verification Status</th>
                            <th class="verif-col-actions" style="text-align: right;">Review Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vItems as $item): 
                            $photoUrl = get_meal_photo_url($item['image_url'], $item['name'], $item['meal_type']);
                            $itemVStatus = $item['verification_status'] ?? 'unverified';
                        ?>
                            <tr class="verif-row">
                                <td class="verif-cell verif-cell-food" data-label="Custom Food Item">
                                    <div class="verif-food-cell">
                                        <img src="<?= h($photoUrl) ?>" alt="<?= h($item['name']) ?>" class="verif-food-thumb" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80';">
                                        <div class="verif-food-info">
                                            <div class="verif-food-name" onclick="openViewIngredientsModal(<?= (int)$item['food_id'] ?>)" title="Click to inspect ingredients & nutrition breakdown">
                                                <?= h($item['name']) ?>
                                            </div>
                                            <div class="verif-food-sub">
                                                <span style="color: var(--lime); font-weight: 600;"><?= h($item['meal_type']) ?></span>
                                                <?php if ($item['dietary_restriction'] && $item['dietary_restriction'] !== 'none'): ?>
                                                    • <?= h($item['dietary_restriction']) ?>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($itemVStatus === 'rejected' && $item['rejection_reason']): ?>
                                                <div class="verif-rejection-box">
                                                    <strong>Rejection Note:</strong> <?= h($item['rejection_reason']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="verif-cell verif-cell-gym" data-label="Gym & Submitter">
                                    <div class="verif-cell-inner">
                                        <div class="verif-cell-label">Gym & Submitter</div>
                                        <div style="font-weight: 700; color: var(--ink); font-size: 13px;">
                                            <?= h($item['gym_name'] ?? 'Custom Gym') ?>
                                        </div>
                                        <div style="font-size: 11.5px; color: var(--muted); margin-top: 2px;">
                                            <?= h($item['submitter_name'] ?? 'Gym Staff') ?>
                                            <?php if ($item['submitter_role']): ?>
                                                <span style="opacity: 0.8;">(<?= ucfirst($item['submitter_role']) ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="verif-cell verif-cell-portion" data-label="Portion & Calories">
                                    <div class="verif-cell-inner">
                                        <div class="verif-cell-label">Portion & Calories</div>
                                        <div style="font-size: 12.5px; color: var(--ink);">
                                            <strong><?= h($item['serving_size'] ?: '1 serving') ?></strong>
                                        </div>
                                        <div style="font-size: 12px; color: #f59e0b; font-weight: 700; margin-top: 2px;">
                                            <?= $item['calories'] ?> kcal
                                        </div>
                                    </div>
                                </td>
                                <td class="verif-cell verif-cell-macros" data-label="Macros (P / C / F)">
                                    <div class="verif-cell-inner">
                                        <div class="verif-cell-label">Nutrition & Recipe</div>
                                        <div class="verif-macro-badges">
                                            <span class="vmacro-badge vmacro-p" title="Protein: <?= $item['protein_g'] ?>g"><strong><?= $item['protein_g'] ?>g</strong> P</span>
                                            <span class="vmacro-badge vmacro-c" title="Carbs: <?= $item['carbs_g'] ?>g"><strong><?= $item['carbs_g'] ?>g</strong> C</span>
                                            <span class="vmacro-badge vmacro-f" title="Fat: <?= $item['fat_g'] ?>g"><strong><?= $item['fat_g'] ?>g</strong> F</span>
                                        </div>
                                        <div style="margin-top: 5px;">
                                            <button type="button" onclick="openViewIngredientsModal(<?= (int)$item['food_id'] ?>)" class="btn-verif-inspect" title="Inspect full recipe breakdown">
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2a10 10 0 1 0 10 10H12V2z"></path><path d="M12 12 2.1 10.5"></path><path d="M12 12V2"></path></svg>
                                                <span>Inspect Recipe (<?= count($item['ingredients_data']['all'] ?? []) ?>)</span>
                                            </button>
                                        </div>
                                    </div>
                                </td>
                                <td class="verif-cell verif-cell-status" data-label="Verification Status">
                                    <div class="verif-cell-inner verif-cell-status-inner">
                                        <div class="verif-cell-label">Status</div>
                                        <div class="verif-status-display">
                                            <?= render_food_verification_badge($itemVStatus, true, $item['rejection_reason'] ?? null) ?>
                                            <?php if ($item['verified_at']): ?>
                                                <div style="font-size: 10.5px; color: var(--muted); margin-top: 4px;">
                                                    <?= date('M d, Y', strtotime($item['verified_at'])) ?>
                                                    <?php if ($item['reviewer_name']): ?>
                                                        <br class="desktop-only-br">by <?= h($item['reviewer_name']) ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="verif-cell verif-cell-actions" style="text-align: right;" data-label="Review Actions">
                                    <div class="verif-actions-col" style="justify-content: flex-end;">
                                        <?php if ($itemVStatus !== 'verified'): ?>
                                            <button type="button" onclick="confirmApproveFood(<?= (int)$item['food_id'] ?>, <?= htmlspecialchars(json_encode($item['name']), ENT_QUOTES, 'UTF-8') ?>, 'verifications')" class="btn-verif-approve" title="Approve custom food submission">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Approve</span>
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($itemVStatus !== 'rejected'): ?>
                                            <button type="button" onclick="promptRejectFood(<?= (int)$item['food_id'] ?>, <?= htmlspecialchars(json_encode($item['name']), ENT_QUOTES, 'UTF-8') ?>, 'verifications', <?= $itemVStatus === 'verified' ? 'true' : 'false' ?>)" class="btn-verif-reject" title="<?= $itemVStatus === 'verified' ? 'Revoke approval and reject submission' : 'Reject submission with feedback note' ?>">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span><?= $itemVStatus === 'verified' ? 'Revoke' : 'Reject' ?></span>
                                            </button>
                                        <?php endif; ?>

                                        <button type="button" onclick="openEditFoodModal(<?= htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8') ?>)" class="btn-verif-edit" title="Edit custom food details" aria-label="Edit custom food details">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                            <span>Edit</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
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
                    <span id="ing-modal-status-badge" class="food-vbadge vbadge-verified">
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

            <div id="ing-modal-rejection-note" class="food-rejected-alert" style="display: none; margin-bottom: 14px;"></div>

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
                <div class="ing-modal-desc-lbl" style="display: flex; align-items: center; justify-content: space-between; gap: 6px;">
                    <span style="display: inline-flex; align-items: center; gap: 5px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <span>Nutritional & Health Highlights</span>
                    </span>
                    <span style="font-size: 9.5px; font-weight: 700; opacity: 0.8; text-transform: uppercase; letter-spacing: 0.5px;">Member Guidance Note</span>
                </div>
                <div id="ing-modal-desc" style="font-size: 11.5px; color: var(--ink); line-height: 1.45;"></div>
            </div>
        </div>

        <div class="ft-modal-footer ing-modal-footer">
            <button type="button" class="btn-modal-cancel" onclick="closeViewIngredientsModal()">Close</button>
            <div id="ing-modal-footer-edit-container">
                <!-- Injected dynamically if user has edit permission -->
            </div>
        </div>
    </div>
</div>

<!-- Customize Meal Photo Modal (ImageKit / URL / Auto) -->
<div class="ft-modal-overlay" id="modal-food-photo" style="display: none;" onclick="if(event.target===this) closeFoodPhotoModal()">
    <div class="ft-modal-box">
        <div class="ft-modal-header">
            <div>
                <h3 class="ft-modal-title">Customize Meal Photo</h3>
                <p class="ft-modal-subtitle">Upload to ImageKit or enter a custom link</p>
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

<?php
// Safely prepare combined foods map for JS
$allFoodsCombined = [];
foreach (array_merge($foods ?? [], $vItems ?? []) as $f) {
    if (!empty($f['food_id'])) {
        $f['photo_url'] = get_meal_photo_url($f['image_url'] ?? null, $f['name'], $f['meal_type'] ?? 'Lunch');
        $allFoodsCombined[(int)$f['food_id']] = $f;
    }
}
?>
<!-- Food Library Configuration & Script -->
<script>
window.FOOD_LIBRARY_CONFIG = {
    csrfToken: <?= json_encode(csrf_token()) ?>,
    currentUserRole: <?= json_encode($user['role']) ?>,
    currentGymId: <?= json_encode($gymId) ?>,
    activeTab: <?= json_encode($activeTab) ?>,
    vStatus: <?= json_encode($vStatus ?? 'all') ?>,
    pendingCount: <?= (int)($pendingVerificationsCount ?? 0) ?>,
    verifiedCount: <?= (int)($verifiedVerificationsCount ?? 0) ?>,
    rejectedCount: <?= (int)($rejectedVerificationsCount ?? 0) ?>,
    foodsMap: <?= json_encode((object)$allFoodsCombined, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?: '{}' ?>
};
</script>
<script src="<?= h(asset_url('js/pages/food_library.js')) ?>"></script>
<?php
    render_footer();
}
