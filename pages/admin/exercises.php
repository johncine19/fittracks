<?php
declare(strict_types=1);

function exercises_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner', 'trainer']);
    $pdo = db();
    verify_csrf();

    $gymId = null;
    $gyms = [];
    if (in_array($user['role'], ['gym_owner', 'trainer'], true)) {
        $gymId = get_user_gym_id($user);
        if (!$gymId) {
            flash('No gym assigned to your account.', 'danger');
            redirect('dashboard');
        }
    } else {
        // Platform admin can select a gym when creating an exercise
        $gyms = $pdo->query('SELECT gym_id, name FROM gyms ORDER BY name ASC')->fetchAll();
    }

    $action = $_GET['action'] ?? 'list';

    // ── Check Exercise Duplicate (AJAX) ─────────────────────────
    if ($action === 'check_duplicate') {
        header('Content-Type: application/json; charset=UTF-8');
        $targetGymId = $gymId;
        if (!$targetGymId && $user['role'] === 'platform_admin') {
            $targetGymId = (int) ($_GET['gym_id'] ?? 0);
        }
        $checkName = trim((string) ($_GET['name'] ?? ''));
        $excludeId = (int) ($_GET['exclude_id'] ?? 0);

        if ($checkName === '') {
            echo json_encode(['exists' => false]);
            exit;
        }

        $chkSql = 'SELECT exercise_id, name, gym_id FROM exercises WHERE (gym_id = ? OR gym_id = 0 OR gym_id IS NULL) AND LOWER(TRIM(name)) = LOWER(TRIM(?))';
        $chkParams = [$targetGymId, $checkName];
        if ($excludeId > 0) {
            $chkSql .= ' AND exercise_id != ?';
            $chkParams[] = $excludeId;
        }
        $chkSql .= ' LIMIT 1';
        $match = query_one($chkSql, $chkParams);

        if ($match) {
            $isGlobal = ((int)$match['gym_id'] === 0);
            echo json_encode([
                'exists' => true,
                'exercise_id' => (int)$match['exercise_id'],
                'is_global' => $isGlobal,
                'message' => 'An exercise named "' . htmlspecialchars($match['name']) . '" already exists ' . ($isGlobal ? 'in the global library.' : 'in your gym library.')
            ]);
        } else {
            echo json_encode(['exists' => false]);
        }
        exit;
    }

    // ── API Ninjas Exercise Search (AJAX) ────────────────────────
    if ($action === 'search_ninja') {
        header('Content-Type: application/json; charset=UTF-8');

        // Rate Limit: 20 searches per minute per gym/user to conserve API quota and prevent abuse
        $searchRateLimitKey = 'ninja_search:' . $user['user_id'];
        if (RateLimiter::tooManyAttempts($searchRateLimitKey, 20, 60)) {
            $retryAfter = max(1, RateLimiter::secondsRemaining($searchRateLimitKey, 60));
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'rate_limited' => true,
                'retry_after' => $retryAfter,
                'error' => "Rate limit reached: Up to 20 searches per minute allowed. Please wait {$retryAfter} second(s) before searching again."
            ]);
            exit;
        }
        RateLimiter::hit($searchRateLimitKey, 60);

        $apiKey = (string) (app_env('API_NINJAS_KEY') ?: (app_env('CALORIENINJAS_API_KEY') ?: (getenv('API_NINJAS_KEY') ?: '')));
        if ($apiKey === '') {
            echo json_encode([
                'success' => false,
                'error' => 'API Ninjas key is not configured in .env. Please set API_NINJAS_KEY in your .env file.'
            ]);
            exit;
        }

        $targetGymId = $gymId;
        if (!$targetGymId && $user['role'] === 'platform_admin') {
            $targetGymId = (int) ($_GET['gym_id'] ?? 0);
        }

        $qName = trim((string) ($_GET['q'] ?? ''));
        $qMuscle = trim((string) ($_GET['muscle'] ?? ''));
        $qType = trim((string) ($_GET['type'] ?? ''));
        $qDifficulty = trim((string) ($_GET['difficulty'] ?? ''));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));

        $apiParams = [];
        if ($qName !== '') $apiParams['name'] = $qName;
        if ($qMuscle !== '') $apiParams['muscle'] = strtolower($qMuscle);
        if ($qType !== '') $apiParams['type'] = strtolower($qType);
        if ($qDifficulty !== '') $apiParams['difficulty'] = strtolower($qDifficulty);
        if ($offset > 0) $apiParams['offset'] = $offset;

        if (empty($apiParams)) {
            $apiParams['type'] = 'strength';
        }

        $url = 'https://api.api-ninjas.com/v1/exercises?' . http_build_query($apiParams);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Api-Key: ' . $apiKey
        ]);

        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            echo json_encode(['success' => false, 'error' => 'Network error connecting to API Ninjas: ' . $curlErr]);
            exit;
        }

        if ($httpCode !== 200) {
            $errMsg = 'API Ninjas request failed (HTTP ' . $httpCode . ')';
            if ($httpCode === 401 || $httpCode === 403) {
                $errMsg = 'Invalid API Ninjas Key. Please check API_NINJAS_KEY in .env.';
            } elseif ($httpCode === 400) {
                $errMsg = 'Invalid search parameters sent to API Ninjas.';
            }
            echo json_encode(['success' => false, 'error' => $errMsg]);
            exit;
        }

        $rawList = json_decode((string)$resp, true);
        if (!is_array($rawList)) {
            echo json_encode(['success' => false, 'error' => 'Failed to parse exercise data from API Ninjas.']);
            exit;
        }

        $existingNames = [];
        if (!empty($rawList)) {
            $names = array_filter(array_map(fn($item) => trim((string)($item['name'] ?? '')), $rawList));
            if (!empty($names)) {
                $inClause = implode(',', array_fill(0, count($names), '?'));
                $chkParams = array_merge([$targetGymId], array_values($names));
                $chkStmt = $pdo->prepare("SELECT LOWER(name) FROM exercises WHERE (gym_id = ? OR gym_id = 0 OR gym_id IS NULL) AND LOWER(name) IN ($inClause)");
                $chkStmt->execute(array_map('strtolower', $chkParams));
                $existingNames = $chkStmt->fetchAll(PDO::FETCH_COLUMN);
            }
        }

        $formatted = [];
        foreach ($rawList as $item) {
            $name = trim((string)($item['name'] ?? ''));
            if ($name === '') continue;

            $type = trim((string)($item['type'] ?? ''));
            $muscle = trim((string)($item['muscle'] ?? ''));
            $diffStr = strtolower(trim((string)($item['difficulty'] ?? 'beginner')));
            $diffLevel = match($diffStr) {
                'expert' => 3,
                'intermediate' => 2,
                default => 1,
            };

            $equip = '';
            if (!empty($item['equipments']) && is_array($item['equipments'])) {
                $equip = implode(', ', array_map('ucwords', $item['equipments']));
            } elseif (!empty($item['equipment'])) {
                $equip = ucwords(trim((string)$item['equipment']));
            }
            if ($equip === '') {
                $equip = 'Bodyweight';
            }

            $instructions = trim((string)($item['instructions'] ?? ''));
            $safety = trim((string)($item['safety_info'] ?? ''));
            $desc = $instructions;
            if ($safety !== '') {
                $desc .= ($desc !== '' ? "

Safety: " : "") . $safety;
            }

            $alreadyIn = in_array(strtolower($name), $existingNames, true);

            $formatted[] = [
                'name' => $name,
                'category' => ucwords(str_replace('_', ' ', $type ?: 'Strength')),
                'muscle_group' => ucwords(str_replace('_', ' ', $muscle ?: 'General')),
                'equipment' => $equip,
                'difficulty' => ucfirst($diffStr),
                'difficulty_level' => $diffLevel,
                'description' => $desc,
                'already_imported' => $alreadyIn,
            ];
        }

        echo json_encode([
            'success' => true,
            'exercises' => $formatted,
            'count' => count($formatted),
            'offset' => $offset,
            'target_gym_id' => $targetGymId
        ]);
        exit;
    }

    
    // Self-heal orphaned global exercises (e.g. from legacy fix_db.php migration that assigned initial global exercises to deleted gym #1)
    if (empty($_SESSION['exercises_global_scope_healed'])) {
        try {
            $orphanedCount = (int) scalar('SELECT COUNT(*) FROM exercises e LEFT JOIN gyms g ON e.gym_id = g.gym_id WHERE e.gym_id IS NULL OR (e.gym_id > 0 AND g.gym_id IS NULL)');
            if ($orphanedCount > 0) {
                try {
                    $pdo->exec('ALTER TABLE exercises DROP FOREIGN KEY fk_exercises_gym');
                } catch (Throwable) {}
                try {
                    $pdo->exec('ALTER TABLE exercises MODIFY COLUMN gym_id INT UNSIGNED NOT NULL DEFAULT 0');
                } catch (Throwable) {}
                $pdo->exec('UPDATE exercises e LEFT JOIN gyms g ON e.gym_id = g.gym_id SET e.gym_id = 0 WHERE e.gym_id IS NULL OR (e.gym_id > 0 AND g.gym_id IS NULL)');
            }
            $_SESSION['exercises_global_scope_healed'] = 1;
        } catch (Throwable) {}
    }

    // Build filter queries based on role
    $catQuery = 'SELECT DISTINCT category FROM exercises WHERE category IS NOT NULL AND category != ""';
    $mgQuery = 'SELECT DISTINCT muscle_group FROM exercises WHERE muscle_group IS NOT NULL AND muscle_group != ""';
    $filterParams = [];
    if ($gymId) {
        $catQuery .= ' AND gym_id = ?';
        $mgQuery .= ' AND gym_id = ?';
        $filterParams[] = $gymId;
    } elseif ($user['role'] === 'platform_admin') {
        $catQuery .= ' AND (gym_id = 0 OR gym_id IS NULL)';
        $mgQuery .= ' AND (gym_id = 0 OR gym_id IS NULL)';
    }
    $catQuery .= ' ORDER BY category';
    $mgQuery .= ' ORDER BY muscle_group';
    
    $catStmt = $pdo->prepare($catQuery);
    $catStmt->execute($filterParams);
    $allCategories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $mgStmt = $pdo->prepare($mgQuery);
    $mgStmt->execute($filterParams);
    $allMuscleGroups = $mgStmt->fetchAll(PDO::FETCH_COLUMN);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postAction = post('action');

        // ── Single Exercise Import from API Ninjas (AJAX) ────────
        if ($postAction === 'import_ninja') {
            header('Content-Type: application/json; charset=UTF-8');

            // Rate Limit: 30 imports per minute per gym/user
            $importRateLimitKey = 'ninja_import:' . $user['user_id'];
            if (RateLimiter::tooManyAttempts($importRateLimitKey, 30, 60)) {
                $retryAfter = max(1, RateLimiter::secondsRemaining($importRateLimitKey, 60));
                http_response_code(429);
                echo json_encode([
                    'success' => false,
                    'rate_limited' => true,
                    'retry_after' => $retryAfter,
                    'error' => "Import rate limit reached. Please wait {$retryAfter} second(s) before importing more exercises."
                ]);
                exit;
            }
            RateLimiter::hit($importRateLimitKey, 60);

            $targetGymId = $gymId;
            if ($targetGymId === null && $user['role'] === 'platform_admin') {
                $targetGymId = (post('gym_id') !== '' && post('gym_id') !== null) ? (int) post('gym_id') : 0;
            }

            $name = trim((string) post('name'));
            $category = trim((string) post('category'));
            $muscle_group = trim((string) post('muscle_group'));
            $equipment = trim((string) post('equipment'));
            $difficulty_level = max(1, min(3, (int) post('difficulty_level')));
            $description = trim((string) post('description'));

            if ($name === '') {
                echo json_encode(['success' => false, 'error' => 'Exercise name is required.']);
                exit;
            }

            $exists = scalar('SELECT exercise_id FROM exercises WHERE (gym_id = ? OR gym_id = 0 OR gym_id IS NULL) AND LOWER(TRIM(name)) = LOWER(TRIM(?))', [$targetGymId, $name]);
            if ($exists) {
                echo json_encode(['success' => true, 'already_existed' => true, 'exercise_id' => (int)$exists, 'message' => 'Exercise is already in your library.']);
                exit;
            }

            $stmt = $pdo->prepare('INSERT INTO exercises (name, category, muscle_group, equipment, difficulty_level, description, gym_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $name,
                $category ?: null,
                $muscle_group ?: null,
                $equipment ?: null,
                $difficulty_level,
                $description ?: null,
                $targetGymId
            ]);
            $newId = (int) $pdo->lastInsertId();

            audit_log($user['user_id'], 'import_ninja', 'exercise', (string) $newId, json_encode(['name' => $name, 'gym_id' => $targetGymId]));
            echo json_encode(['success' => true, 'exercise_id' => $newId, 'name' => $name, 'message' => 'Exercise imported successfully!']);
            exit;
        }

        // ── Batch Exercises Import from API Ninjas (AJAX) ─────────
        if ($postAction === 'batch_import_ninja') {
            header('Content-Type: application/json; charset=UTF-8');

            // Rate Limit: 30 imports per minute per gym/user
            $importRateLimitKey = 'ninja_import:' . $user['user_id'];
            if (RateLimiter::tooManyAttempts($importRateLimitKey, 30, 60)) {
                $retryAfter = max(1, RateLimiter::secondsRemaining($importRateLimitKey, 60));
                http_response_code(429);
                echo json_encode([
                    'success' => false,
                    'rate_limited' => true,
                    'retry_after' => $retryAfter,
                    'error' => "Import rate limit reached. Please wait {$retryAfter} second(s) before importing more exercises."
                ]);
                exit;
            }
            RateLimiter::hit($importRateLimitKey, 60);

            $targetGymId = $gymId;
            if ($targetGymId === null && $user['role'] === 'platform_admin') {
                $targetGymId = (post('gym_id') !== '' && post('gym_id') !== null) ? (int) post('gym_id') : 0;
            }

            $rawPayload = post('exercises_json') ?? '';
            $items = json_decode((string)$rawPayload, true);
            if (!is_array($items) || empty($items)) {
                echo json_encode(['success' => false, 'error' => 'No exercises selected for import.']);
                exit;
            }
            if (count($items) > 50) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'You can import a maximum of 50 exercises per batch.']);
                exit;
            }

            $importedCount = 0;
            $skippedCount = 0;
            $stmt = $pdo->prepare('INSERT INTO exercises (name, category, muscle_group, equipment, difficulty_level, description, gym_id) VALUES (?, ?, ?, ?, ?, ?, ?)');

            foreach ($items as $item) {
                $name = trim((string)($item['name'] ?? ''));
                if ($name === '') continue;

                $exists = scalar('SELECT exercise_id FROM exercises WHERE (gym_id = ? OR gym_id = 0 OR gym_id IS NULL) AND LOWER(TRIM(name)) = LOWER(TRIM(?))', [$targetGymId, $name]);
                if ($exists) {
                    $skippedCount++;
                    continue;
                }

                $category = trim((string)($item['category'] ?? ''));
                $muscle_group = trim((string)($item['muscle_group'] ?? ''));
                $equipment = trim((string)($item['equipment'] ?? ''));
                $difficulty_level = max(1, min(3, (int)($item['difficulty_level'] ?? 1)));
                $description = trim((string)($item['description'] ?? ''));

                $stmt->execute([
                    $name,
                    $category ?: null,
                    $muscle_group ?: null,
                    $equipment ?: null,
                    $difficulty_level,
                    $description ?: null,
                    $targetGymId
                ]);
                $newId = (int) $pdo->lastInsertId();
                audit_log($user['user_id'], 'import_ninja', 'exercise', (string) $newId, json_encode(['name' => $name, 'gym_id' => $targetGymId]));
                $importedCount++;
            }

            echo json_encode([
                'success' => true,
                'imported_count' => $importedCount,
                'skipped_count' => $skippedCount,
                'message' => "Successfully imported {$importedCount} exercise(s)." . ($skippedCount > 0 ? " ({$skippedCount} already in library skipped)" : "")
            ]);
            exit;
        }
        
        if ($postAction === 'create' || $postAction === 'edit') {
            $name = trim((string) post('name'));
            $category = trim((string) post('category'));
            $muscle_group = trim((string) post('muscle_group'));
            $equipment = trim((string) post('equipment'));
            $difficulty_level = max(1, min(3, (int) post('difficulty_level', 1)));
            $description = trim((string) post('description'));
            
            // For platform admin creating an exercise, they can pick a gym or Global (0)
            $targetGymId = $gymId;
            if ($targetGymId === null && $user['role'] === 'platform_admin') {
                $targetGymId = (post('gym_id') !== '' && post('gym_id') !== null) ? (int) post('gym_id') : 0;
            }
            
            if (!$name) {
                flash('Exercise name is required.', 'danger');
            } elseif ($targetGymId === null) {
                flash('You must select a gym or Global for this exercise.', 'danger');
            } else {
                $urlInput = trim((string) post('animation_url_input'));
                if ($postAction === 'create') {
                    $dupCheck = query_one('SELECT exercise_id, gym_id FROM exercises WHERE (gym_id = ? OR gym_id = 0 OR gym_id IS NULL) AND LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1', [$targetGymId, $name]);
                    if ($dupCheck) {
                        $isGlobal = ((int)$dupCheck['gym_id'] === 0);
                        flash('An exercise named "' . htmlspecialchars($name) . '" already exists ' . ($isGlobal ? 'in the global library.' : 'in your gym library.') . ' Duplicates are not allowed.', 'danger');
                        redirect('exercises');
                    }
                    $stmt = $pdo->prepare('INSERT INTO exercises (name, category, muscle_group, equipment, difficulty_level, description, gym_id, animation_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$name, $category, $muscle_group, $equipment ?: null, $difficulty_level, $description, $targetGymId, $urlInput ?: null]);
                    $newId = (int) $pdo->lastInsertId();
                    
                    if (isset($_FILES['animation']) && $_FILES['animation']['error'] !== UPLOAD_ERR_NO_FILE) {
                        try {
                            require_once __DIR__ . '/../../core/file_handler.php';
                            $animFile = FileUpload::storeExerciseAnimation($_FILES['animation'], $newId);
                            $pdo->prepare('UPDATE exercises SET animation_url = ? WHERE exercise_id = ?')->execute([$animFile, $newId]);
                        } catch (Throwable $e) {
                            flash($e->getMessage(), 'warning');
                        }
                    }

                    audit_log($user['user_id'], 'create', 'exercise', (string) $newId, json_encode(['name' => $name, 'category' => $category, 'gym_id' => $targetGymId]));
                    flash('Exercise created successfully.');
                } else {
                    $editId = (int) post('exercise_id');
                    // Ensure permission
                    $exGymId = scalar('SELECT gym_id FROM exercises WHERE exercise_id = ?', [$editId]);
                    if (in_array($user['role'], ['gym_owner', 'trainer'], true) && (string)$exGymId !== (string)$gymId) {
                        flash('Permission denied.', 'danger');
                    } else {
                        $dupCheck = query_one('SELECT exercise_id, gym_id FROM exercises WHERE (gym_id = ? OR gym_id = 0 OR gym_id IS NULL) AND LOWER(TRIM(name)) = LOWER(TRIM(?)) AND exercise_id != ? LIMIT 1', [$targetGymId, $name, $editId]);
                        if ($dupCheck) {
                            $isGlobal = ((int)$dupCheck['gym_id'] === 0);
                            flash('Cannot save: an exercise named "' . htmlspecialchars($name) . '" already exists ' . ($isGlobal ? 'in the global library.' : 'in your gym library.') . '.', 'danger');
                            redirect('exercises');
                        }
                        if ($user['role'] === 'platform_admin') {
                            $stmt = $pdo->prepare('UPDATE exercises SET name = ?, category = ?, muscle_group = ?, equipment = ?, difficulty_level = ?, description = ?, gym_id = ? WHERE exercise_id = ?');
                            $stmt->execute([$name, $category, $muscle_group, $equipment ?: null, $difficulty_level, $description, (int)$targetGymId, $editId]);
                        } else {
                            $stmt = $pdo->prepare('UPDATE exercises SET name = ?, category = ?, muscle_group = ?, equipment = ?, difficulty_level = ?, description = ? WHERE exercise_id = ?');
                            $stmt->execute([$name, $category, $muscle_group, $equipment ?: null, $difficulty_level, $description, $editId]);
                        }
                        
                        if ($urlInput) {
                            $pdo->prepare('UPDATE exercises SET animation_url = ? WHERE exercise_id = ?')->execute([$urlInput, $editId]);
                        }
                        
                        if (isset($_FILES['animation']) && $_FILES['animation']['error'] !== UPLOAD_ERR_NO_FILE) {
                            try {
                                require_once __DIR__ . '/../../core/file_handler.php';
                                $animFile = FileUpload::storeExerciseAnimation($_FILES['animation'], $editId);
                                $pdo->prepare('UPDATE exercises SET animation_url = ? WHERE exercise_id = ?')->execute([$animFile, $editId]);
                            } catch (Throwable $e) {
                                flash($e->getMessage(), 'warning');
                            }
                        }

                        audit_log($user['user_id'], 'edit', 'exercise', (string) $editId, json_encode(['name' => $name]));
                        flash('Exercise updated successfully.');
                    }
                }
                redirect('exercises');
            }
        } elseif ($postAction === 'delete') {
            $delId = (int) post('exercise_id');
            $exRow = query_one('SELECT exercise_id, gym_id, name FROM exercises WHERE exercise_id = ?', [$delId]);
            if (!$exRow) {
                flash('Exercise not found.', 'danger');
                redirect('exercises');
            }

            if (in_array($user['role'], ['gym_owner', 'trainer'], true) && (string)$exRow['gym_id'] !== (string)$gymId) {
                flash('Permission denied.', 'danger');
                redirect('exercises');
            }

            $exName = $exRow['name'];
            $targetGym = $exRow['gym_id'] ?: $gymId;

            // 1. If this exercise is a duplicate of another exercise in the same gym (or global catalog),
            // safely remap foreign references to the primary exercise so active workouts are preserved.
            $altExerciseId = (int) scalar(
                'SELECT exercise_id FROM exercises WHERE gym_id = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) AND exercise_id != ? ORDER BY exercise_id ASC LIMIT 1',
                [$targetGym, $exName, $delId]
            );
            if (!$altExerciseId) {
                $altExerciseId = (int) scalar(
                    'SELECT exercise_id FROM exercises WHERE (gym_id = 0 OR gym_id IS NULL) AND LOWER(TRIM(name)) = LOWER(TRIM(?)) AND exercise_id != ? ORDER BY exercise_id ASC LIMIT 1',
                    [$exName, $delId]
                );
            }

            if ($altExerciseId > 0) {
                try {
                    $pdo->prepare('UPDATE training_plan_exercises SET exercise_id = ? WHERE exercise_id = ?')->execute([$altExerciseId, $delId]);
                    $pdo->prepare('UPDATE exercise_completions SET exercise_id = ? WHERE exercise_id = ?')->execute([$altExerciseId, $delId]);
                } catch (Throwable $e) {
                    error_log('Failed remapping duplicate exercise: ' . $e->getMessage());
                }
            }

            // 2. Check if any blocking foreign key references remain
            $tpeCount = (int) scalar('SELECT COUNT(*) FROM training_plan_exercises WHERE exercise_id = ?', [$delId]);
            $compCount = (int) scalar('SELECT COUNT(*) FROM exercise_completions WHERE exercise_id = ?', [$delId]);

            if ($tpeCount > 0 || $compCount > 0) {
                flash('Cannot delete "' . htmlspecialchars((string)$exName) . '" because it is currently assigned to ' . $tpeCount . ' active workout plan(s) or member log(s). Please remove or replace it in those plans first.', 'warning');
                redirect('exercises');
            }

            // 3. Delete exercise safely with try/catch
            try {
                $stmt = $pdo->prepare('DELETE FROM exercises WHERE exercise_id = ?');
                $stmt->execute([$delId]);
                audit_log($user['user_id'], 'delete', 'exercise', (string) $delId);
                flash('Exercise "' . htmlspecialchars((string)$exName) . '" deleted successfully.');
            } catch (Throwable $e) {
                error_log('Failed to delete exercise ' . $delId . ': ' . $e->getMessage());
                flash('Unable to delete exercise: related records depend on it.', 'danger');
            }
            redirect('exercises');
        }
    }

    render_header('Exercises', $user);
    
    // Combined standard & existing taxonomy lists
    $standardMuscles = ['Chest', 'Biceps', 'Triceps', 'Forearms', 'Quadriceps', 'Hamstrings', 'Calves', 'Glutes', 'Lats', 'Middle Back', 'Lower Back', 'Traps', 'Neck', 'Abdominals', 'Abductors', 'Adductors'];
    $combinedMuscles = array_unique(array_merge($standardMuscles, array_filter($allMuscleGroups)));
    sort($combinedMuscles);

    $standardTypes = ['Strength', 'Cardio', 'Powerlifting', 'Olympic Lifting', 'Plyometrics', 'Stretching', 'Strongman'];
    $combinedTypes = array_unique(array_merge($standardTypes, array_filter($allCategories)));
    sort($combinedTypes);

    $q = trim((string) ($_GET['q'] ?? ''));
    $cat = trim((string) ($_GET['cat'] ?? ''));
    $muscle = trim((string) ($_GET['muscle'] ?? ''));
    $diff = trim((string) ($_GET['diff'] ?? ''));
    $scope = ($user['role'] === 'platform_admin') ? 'global' : '';

    $csrfStr = csrf_field();

    $sql = 'SELECT e.*, g.name AS gym_name FROM exercises e LEFT JOIN gyms g ON e.gym_id = g.gym_id WHERE 1=1';
    $countSql = 'SELECT COUNT(*) FROM exercises e LEFT JOIN gyms g ON e.gym_id = g.gym_id WHERE 1=1';
    $params = [];
    
    if ($gymId) {
        $sql .= ' AND e.gym_id = ?';
        $countSql .= ' AND e.gym_id = ?';
        $params[] = $gymId;
    } elseif ($user['role'] === 'platform_admin') {
        $sql .= ' AND (e.gym_id = 0 OR e.gym_id IS NULL)';
        $countSql .= ' AND (e.gym_id = 0 OR e.gym_id IS NULL)';
    }
    
    if ($q !== '') {
        $sql .= ' AND e.name LIKE ?';
        $countSql .= ' AND e.name LIKE ?';
        $params[] = '%' . $q . '%';
    }
    if ($cat !== '') {
        $sql .= ' AND LOWER(e.category) = LOWER(?)';
        $countSql .= ' AND LOWER(e.category) = LOWER(?)';
        $params[] = $cat;
    }
    if ($muscle !== '') {
        $sql .= ' AND LOWER(e.muscle_group) = LOWER(?)';
        $countSql .= ' AND LOWER(e.muscle_group) = LOWER(?)';
        $params[] = $muscle;
    }
    if ($diff !== '' && is_numeric($diff)) {
        $sql .= ' AND e.difficulty_level = ?';
        $countSql .= ' AND e.difficulty_level = ?';
        $params[] = (int) $diff;
    }
    
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $total = (int) $stmtCount->fetchColumn();
    
    $pageNum = max(1, (int)($_GET['p'] ?? 1));
    $limit = 12;
    $offset = ($pageNum - 1) * $limit;
    $totalPages = (int) ceil($total / $limit);

    $sql .= ' ORDER BY COALESCE(e.difficulty_level, 1) ASC, e.name ASC LIMIT ' . $limit . ' OFFSET ' . $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $exercises = $stmt->fetchAll();
    ?>

    <style>
        .ex-card { 
            background: var(--panel-soft); 
            border: 1px solid var(--line); 
            border-radius: 16px; 
            padding: 0; 
            transition: all 0.2s ease; 
            display: flex; 
            flex-direction: column; 
            position: relative; 
            overflow: hidden; 
            height: 100%; 
            box-sizing: border-box; 
        }
        .ex-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--ex-accent, var(--lime));
            opacity: 0.85;
            z-index: 3;
            transition: opacity 0.2s ease;
        }
        .ex-card:hover { 
            border-color: color-mix(in srgb, var(--ex-accent, var(--lime)) 45%, var(--line)); 
            transform: translateY(-3px); 
            box-shadow: 0 12px 28px rgba(0,0,0,0.22); 
        }
        .ex-card:hover::before {
            opacity: 1;
        }
        .ex-card-thumb-wrap {
            position: relative;
            width: 100%;
            height: 152px;
            background: radial-gradient(circle at 50% 35%, color-mix(in srgb, var(--ex-accent, #38bdf8) 14%, #0b1320) 0%, #050a10 80%);
            overflow: hidden;
            flex-shrink: 0;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            border-bottom: 1px solid var(--line);
        }
        .ex-card-thumb {
            width: 100%;
            height: 100%;
            object-fit: contain;
            object-position: center;
            display: block;
            transition: transform 0.35s ease, opacity 0.2s ease;
            opacity: 0.94;
        }
        .ex-card:hover .ex-card-thumb {
            transform: scale(1.04);
            opacity: 1;
        }
        .ex-thumb-badge-group {
            position: absolute;
            top: 12px;
            left: 10px;
            right: 10px;
            display: flex;
            justify-content: space-between;
            gap: 6px;
            align-items: center;
            z-index: 2;
            pointer-events: none;
        }
        .ex-thumb-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            padding: 4px 9px;
            border-radius: 999px;
            background: rgba(9, 13, 22, 0.78);
            backdrop-filter: blur(6px);
            color: #f8fafc;
            border: 1px solid color-mix(in srgb, var(--ex-accent, #94a3b8) 45%, rgba(255, 255, 255, 0.14));
            box-shadow: 0 2px 6px rgba(0,0,0,0.3);
        }
        .ex-thumb-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--ex-accent, var(--lime));
            flex-shrink: 0;
        }
        .ex-diff-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            padding: 4px 9px;
            border-radius: 999px;
            backdrop-filter: blur(6px);
            box-shadow: 0 2px 6px rgba(0,0,0,0.3);
        }
        .ex-diff-pill.is-beginner {
            background: rgba(16, 185, 129, 0.22);
            color: #6ee7b7;
            border: 1px solid rgba(16, 185, 129, 0.5);
        }
        .ex-diff-pill.is-intermediate {
            background: rgba(245, 158, 11, 0.22);
            color: #fcd34d;
            border: 1px solid rgba(245, 158, 11, 0.5);
        }
        .ex-diff-pill.is-expert {
            background: rgba(239, 68, 68, 0.24);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.5);
        }
        .ex-thumb-pill-anim {
            position: absolute;
            bottom: 10px;
            right: 10px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 9px;
            border-radius: 999px;
            background: rgba(163, 230, 53, 0.94);
            color: #0d1302;
            box-shadow: 0 2px 8px rgba(0,0,0,0.4);
            z-index: 2;
            text-decoration: none;
            transition: transform 0.15s ease, background 0.15s ease;
        }
        .ex-thumb-pill-anim:hover {
            background: #bef264;
            transform: scale(1.05);
            color: #000;
        }
        .ex-card-body {
            padding: 16px 18px 14px;
            display: flex;
            flex-direction: column;
            flex-grow: 1;
            gap: 10px;
        }
        .ex-card-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }
        .ex-card-title {
            margin: 0;
            font-size: 16.5px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1.3;
            cursor: pointer;
        }
        .ex-card-title:hover {
            color: var(--ex-accent, var(--lime));
        }
        .ex-card-actions {
            display: flex;
            gap: 6px;
            flex-shrink: 0;
        }
        .ex-card-action-btn {
            width: 30px;
            height: 30px;
            padding: 0 !important;
            border-radius: 8px;
            background: var(--surface);
            border: 1px solid var(--line);
            color: var(--muted);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease;
        }
        .ex-card-action-btn:hover {
            color: var(--ink);
            border-color: var(--lime);
        }
        .ex-card-action-btn.is-del:hover {
            color: #ef4444;
            border-color: rgba(239, 68, 68, 0.45);
            background: rgba(239, 68, 68, 0.1);
        }
        .ex-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr)); gap: 16px; align-items: stretch; }
        .ex-meta-group {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }
        .ex-meta-tag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 9px;
            border-radius: 7px;
            background: var(--surface);
            border: 1px solid var(--line);
            font-size: 11.5px;
            line-height: 1.2;
            color: var(--ink);
            font-weight: 600;
        }
        .ex-meta-tag svg {
            color: var(--muted);
            flex-shrink: 0;
        }
        .ex-desc-clamp {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.5;
            font-size: 13px;
            color: var(--muted);
            margin: 0;
            flex-grow: 1;
        }
        .ex-card-footer {
            padding: 11px 18px;
            border-top: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            background: color-mix(in srgb, var(--surface) 45%, transparent);
        }
        .ex-see-more-btn {
            background: transparent !important;
            border: none !important;
            color: var(--ink) !important;
            font-size: 12px !important;
            font-weight: 700 !important;
            cursor: pointer !important;
            padding: 0 !important;
            margin: 0 !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 5px !important;
            box-shadow: none !important;
            outline: none !important;
            min-height: auto !important;
            height: auto !important;
            border-radius: 0 !important;
            transition: color 0.15s ease, transform 0.15s ease !important;
        }
        .ex-see-more-btn:hover {
            color: var(--ex-accent, var(--lime)) !important;
        }
        .ex-see-more-btn svg {
            transition: transform 0.15s ease;
        }
        .ex-see-more-btn:hover svg {
            transform: translateX(3px);
        }
        .exercises-banner {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .exercises-banner-copy {
            min-width: 0;
        }
        .exercises-banner-action {
            flex: 0 0 auto;
        }
        .exercises-title {
            display: flex;
            align-items: center;
            gap: 11px;
            margin: 0;
            color: var(--ink);
            font-size: 22px;
            font-weight: 750;
            letter-spacing: -0.025em;
        }
        .exercises-banner-description {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 14px;
        }
        .exercises-new-button {
            min-height: 42px;
            padding: 9px 14px;
            border: 1px solid var(--lime-dark, #9fd21c);
            border-radius: 8px;
            background: var(--lime, #c7ff22);
            color: #080b0d;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.18s ease, border-color 0.18s ease;
        }
                .exercises-ninja-button {
            min-height: 42px;
            padding: 9px 15px;
            border: 1px solid rgba(56, 189, 248, 0.4);
            border-radius: 8px;
            background: rgba(56, 189, 248, 0.12);
            color: #38bdf8;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.18s ease;
        }
        .exercises-ninja-button:hover {
            background: rgba(56, 189, 248, 0.22);
            border-color: #38bdf8;
            color: #7dd3fc;
            box-shadow: 0 0 15px rgba(56, 189, 248, 0.2);
        }
        .ninja-modal-dialog {
            max-width: 900px;
            width: 95vw;
            max-height: 90vh;
            border-radius: 16px;
            background: var(--panel);
            border: 1px solid var(--line);
            color: var(--ink);
            padding: 0;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
        }
        .ninja-modal-dialog[open] {
            display: flex;
            flex-direction: column;
        }
        .ninja-modal-dialog::backdrop {
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
        }
        .ninja-modal-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            background: var(--panel);
            flex-shrink: 0;
        }
        .ninja-header-icon {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.16), rgba(99, 102, 241, 0.16));
            border: 1px solid rgba(56, 189, 248, 0.28);
            color: #0ea5e9;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .ninja-header-badge {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            padding: 2px 7px;
            border-radius: 999px;
            background: rgba(56, 189, 248, 0.12);
            color: #0284c7;
            border: 1px solid rgba(56, 189, 248, 0.25);
        }
        .ninja-close-btn {
            background: var(--surface);
            border: 1px solid var(--line);
            color: var(--ink);
            cursor: pointer;
            width: 36px;
            height: 36px;
            padding: 0 !important;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease;
            flex-shrink: 0;
        }
        .ninja-close-btn svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
            display: block;
        }
        .ninja-close-btn:hover {
            background: var(--bg);
            border-color: var(--lime);
            color: var(--ink);
        }
        .ninja-done-btn {
            height: 36px;
            padding: 0 20px !important;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--surface);
            color: var(--ink);
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .ninja-done-btn:hover {
            background: var(--bg);
            border-color: var(--lime);
        }
        .ninja-search-panel {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .ninja-scope-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--line);
        }
        .ninja-scope-label {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--muted);
            white-space: nowrap;
        }
        .ninja-scope-select {
            flex: 1;
            min-width: 240px;
            max-width: 420px;
            height: 36px;
            padding: 0 12px;
            font-size: 13px;
            line-height: normal;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--bg);
            color: var(--ink);
        }
        .ninja-search-grid {
            display: grid;
            grid-template-columns: 2fr 1.05fr 1.05fr 0.95fr auto;
            gap: 10px;
            align-items: end;
        }
        .ninja-field-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
            margin-bottom: 5px;
            display: block;
        }
        .ninja-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .ninja-input-icon {
            position: absolute;
            left: 11px;
            color: var(--muted);
            pointer-events: none;
        }
        .ninja-field-control {
            width: 100%;
            height: 38px;
            padding: 0 12px;
            font-size: 13px;
            line-height: normal;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--bg);
            color: var(--ink);
            box-sizing: border-box;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .ninja-field-control.has-icon {
            padding-left: 34px;
        }
        .ninja-field-control:focus,
        .ninja-scope-select:focus {
            outline: none;
            border-color: var(--lime);
            box-shadow: 0 0 0 2px color-mix(in srgb, var(--lime) 20%, transparent);
        }
        .ninja-search-submit {
            height: 38px;
            padding: 0 18px;
            border-radius: 8px;
            border: none;
            background: var(--lime);
            color: var(--bg);
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            cursor: pointer;
            white-space: nowrap;
            transition: filter 0.15s ease, transform 0.1s ease;
        }
        .ninja-search-submit:hover {
            filter: brightness(1.06);
        }
        .ninja-search-submit:active {
            transform: scale(0.98);
        }
        .ninja-popular-row {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
            padding-top: 2px;
        }
        .ninja-chip {
            background: var(--bg);
            border: 1px solid var(--line);
            color: var(--muted);
            border-radius: 999px;
            padding: 4px 11px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .ninja-chip:hover {
            border-color: var(--lime);
            color: var(--ink);
            background: color-mix(in srgb, var(--lime) 10%, var(--bg));
        }
        .ninja-empty-card {
            text-align: center;
            padding: 42px 24px;
            border-radius: 12px;
            background: var(--surface);
            border: 1px dashed var(--line);
            color: var(--muted);
        }
        .ninja-empty-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: rgba(56, 189, 248, 0.1);
            border: 1px solid rgba(56, 189, 248, 0.2);
            color: #0ea5e9;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
        }
        .ninja-empty-meta {
            margin-top: 14px;
            display: inline-flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 8px;
            font-size: 11.5px;
            color: var(--muted);
        }
        .ninja-empty-tag {
            padding: 3px 9px;
            border-radius: 999px;
            background: var(--bg);
            border: 1px solid var(--line);
            font-weight: 500;
        }
        @media (max-width: 760px) {
            .ninja-search-grid {
                grid-template-columns: 1fr 1fr;
            }
            .ninja-search-grid > div:first-child,
            .ninja-search-grid > div:last-child {
                grid-column: span 2;
            }
            .ninja-search-submit {
                width: 100%;
            }
        }
        .ninja-card {
            background: var(--panel-soft);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 16px;
            transition: all 0.18s ease;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 10px;
            height: 100%;
            box-sizing: border-box;
        }
        .ninja-card:hover {
            border-color: rgba(255, 255, 255, 0.18);
            background: color-mix(in srgb, var(--panel-soft) 85%, var(--surface));
        }
        .ninja-card.already-imported {
            opacity: 0.75;
            border-style: dashed;
        }

        .exercises-new-button:hover {
            background: var(--lime-dark, #9fd21c);
            border-color: var(--lime-dark, #9fd21c);
        }
        .exercises-filter-form {
            display: grid;
            grid-template-columns: 2fr 1.05fr 1.05fr 0.95fr auto;
            gap: 12px;
            align-items: end;
            margin-bottom: 22px;
            padding: 14px 16px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 12px;
            position: relative;
            z-index: 50;
        }
        .exercises-filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
            min-width: 0;
        }
        .exercises-filter-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
        }
        .exercises-search-field {
            position: relative;
            display: flex;
            align-items: center;
        }
        .exercises-search-icon {
            position: absolute;
            left: 11px;
            color: var(--muted);
            pointer-events: none;
        }
        .exercises-filter-control {
            width: 100%;
            height: 38px;
            padding: 0 12px;
            font-size: 13px;
            line-height: normal;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--bg);
            color: var(--ink);
            box-sizing: border-box;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .exercises-filter-control.has-icon {
            padding-left: 34px;
        }
        .exercises-filter-control:focus {
            outline: none;
            border-color: var(--lime);
            box-shadow: 0 0 0 2px color-mix(in srgb, var(--lime) 20%, transparent);
        }
        .exercises-filter-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .exercises-filter-button {
            height: 38px;
            padding: 0 16px;
            border-radius: 8px;
            background: var(--lime);
            color: var(--bg);
            border: none;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            cursor: pointer;
            white-space: nowrap;
            transition: filter 0.15s ease, transform 0.1s ease;
        }
        .exercises-filter-button:hover {
            filter: brightness(1.06);
        }
        .exercises-filter-button:active {
            transform: scale(0.98);
        }
        .exercises-clear-button {
            height: 38px;
            padding: 0 14px;
            border-radius: 8px;
            background: var(--bg);
            border: 1px solid var(--line);
            color: var(--muted);
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            box-sizing: border-box;
            transition: all 0.15s ease;
        }
        .exercises-clear-button:hover {
            color: var(--ink);
            border-color: var(--lime);
        }
        .exercises-category-dropdown {
            position: relative;
            z-index: 55;
        }
        @media (max-width: 900px) {
            .exercises-filter-form {
                grid-template-columns: 1fr 1fr;
            }
            .exercises-filter-group.is-search,
            .exercises-filter-actions {
                grid-column: span 2;
            }
            .exercises-filter-actions .exercises-filter-button,
            .exercises-filter-actions .exercises-clear-button {
                flex: 1;
            }
        }
        @media (max-width: 768px) {
            .ex-modal-row-3 { grid-template-columns: 1fr !important; }
            .exercises-banner {
                align-items: stretch;
                padding: 16px 14px;
                gap: 14px;
            }
            .exercises-title {
                gap: 9px;
                font-size: 20px;
            }
            .exercises-banner-description {
                margin-top: 7px;
                font-size: 13px;
            }
            .exercises-banner-action {
                width: 100%;
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            .exercises-ninja-button,
            .exercises-new-button {
                width: 100% !important;
                justify-content: center !important;
            }
            .exercises-filter-form {
                grid-template-columns: 1fr 1fr !important;
                padding: 12px !important;
                gap: 10px !important;
            }
            .exercises-filter-group.is-search,
            .exercises-filter-group.is-diff,
            .exercises-filter-actions {
                grid-column: span 2 !important;
            }
            .ninja-search-grid {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
            }
            .ninja-search-grid button {
                width: 100% !important;
                justify-content: center !important;
            }
        }
    </style>

    <div>
        <div class="exercises-banner animate-fade-in">
            <div class="exercises-banner-copy">
                <h1 class="exercises-title">
                    Exercises Database
                </h1>
                <p class="exercises-banner-description">
                    <?= $gymId ? 'Manage your gym\'s custom and API-imported exercises used in workout plans.' : 'Manage the global library of exercises used for workout templates.' ?>
                </p>
            </div>
            <div class="exercises-banner-action" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                <button type="button" class="exercises-ninja-button" onclick="openNinjaModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                    Import from API Ninjas
                </button>
                <button type="button" class="exercises-new-button" onclick="openNewExerciseModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    New Exercise
                </button>
            </div>
        </div>

        <form method="get" class="animate-fade-in exercises-filter-form">
            <input type="hidden" name="page" value="exercises">
            
            <!-- Search input -->
            <div class="exercises-filter-group is-search">
                <label for="exFilterSearch" class="exercises-filter-label">Search</label>
                <div class="exercises-search-field">
                    <svg class="exercises-search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input id="exFilterSearch" type="text" name="q" class="exercises-filter-control has-icon" placeholder="Search exercises..." value="<?= h($q) ?>">
                </div>
            </div>
            
            <!-- Target Muscle Filter -->
            <div class="exercises-filter-group">
                <label for="exFilterMuscle" class="exercises-filter-label">Muscle</label>
                <select id="exFilterMuscle" name="muscle" class="exercises-filter-control" onchange="this.form.submit()">
                    <option value="">All Muscles</option>
                    <?php foreach ($combinedMuscles as $m): ?>
                        <option value="<?= h($m) ?>" <?= (strcasecmp($muscle, $m) === 0 ? 'selected' : '') ?>><?= h(ucwords($m)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Exercise Type Filter -->
            <div class="exercises-filter-group">
                <label for="exFilterType" class="exercises-filter-label">Type</label>
                <select id="exFilterType" name="cat" class="exercises-filter-control" onchange="this.form.submit()">
                    <option value="">All Types</option>
                    <?php foreach ($combinedTypes as $c): ?>
                        <option value="<?= h($c) ?>" <?= (strcasecmp($cat, $c) === 0 ? 'selected' : '') ?>><?= h(ucwords($c)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Difficulty Filter -->
            <div class="exercises-filter-group is-diff">
                <label for="exFilterDiff" class="exercises-filter-label">Difficulty</label>
                <select id="exFilterDiff" name="diff" class="exercises-filter-control" onchange="this.form.submit()">
                    <option value="">All Levels</option>
                    <option value="1" <?= selected($diff, '1') ?>>Beginner</option>
                    <option value="2" <?= selected($diff, '2') ?>>Intermediate</option>
                    <option value="3" <?= selected($diff, '3') ?>>Expert</option>
                </select>
            </div>
            
            <div class="exercises-filter-actions">
                <button type="submit" class="exercises-filter-button">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                    <span>Filter</span>
                </button>
                <?php if ($q !== '' || $cat !== '' || $muscle !== '' || $diff !== ''): ?>
                    <a href="index.php?page=exercises" class="exercises-clear-button">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="animate-fade-in">
            <?php if (!$exercises): ?>
                <div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <p>No exercises found matching your criteria.</p>
                </div>
            <?php else: ?>
                <div class="ex-grid">
                    <?php foreach ($exercises as $ex): 
                        $safeJson = htmlspecialchars(json_encode($ex)); 
                        $photoUrl = get_exercise_image_url($ex['animation_url'] ?? null, $ex['name'], $ex['muscle_group'] ?? '', $ex['category'] ?? '');
                        $hasAnimation = !empty($ex['animation_url']);
                        $diffLevel = (int)($ex['difficulty_level'] ?? 1);
                        $diffMap = [1 => 'Beginner', 2 => 'Intermediate', 3 => 'Expert'];
                        $diffLabel = $diffMap[$diffLevel] ?? 'Beginner';
                        $diffCls = match ($diffLevel) {
                            2 => 'is-intermediate',
                            3 => 'is-expert',
                            default => 'is-beginner',
                        };
                        $exAccent = match ($diffLevel) {
                            2 => '#f59e0b',
                            3 => '#ef4444',
                            default => '#10b981',
                        };
                    ?>
                        <div class="ex-card" style="--ex-accent: <?= $exAccent ?>;">
                            <!-- Exercise Visual Thumbnail Banner -->
                            <div class="ex-card-thumb-wrap" onclick="openExerciseDetailsModal(<?= $safeJson ?>)" title="Click to view details">
                                <img class="ex-card-thumb" 
                                     src="<?= h($photoUrl) ?>" 
                                     alt="<?= h($ex['name']) ?>" 
                                     loading="lazy"
                                     onerror="this.onerror=null; this.src='assets/images/default exercise.png';">
                                
                                <div class="ex-thumb-badge-group">
                                    <?php if (!empty($ex['category'])): ?>
                                        <span class="ex-thumb-pill">
                                            <span class="ex-thumb-dot"></span>
                                            <?= h(ucwords((string)$ex['category'])) ?>
                                        </span>
                                    <?php else: ?>
                                        <span></span>
                                    <?php endif; ?>
                                    <span class="ex-diff-pill <?= $diffCls ?>"><?= h($diffLabel) ?></span>
                                </div>

                                <?php if ($hasAnimation): ?>
                                    <?php 
                                        $animUrl = (str_starts_with($ex['animation_url'], 'http://') || str_starts_with($ex['animation_url'], 'https://'))
                                            ? $ex['animation_url']
                                            : 'assets/exercise_animations/' . $ex['animation_url'];
                                    ?>
                                    <a href="<?= h($animUrl) ?>" target="_blank" onclick="event.stopPropagation();" class="ex-thumb-pill-anim" title="View exercise animation">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                        <span>Animation</span>
                                    </a>
                                <?php endif; ?>
                            </div>

                            <div class="ex-card-body">
                                <div class="ex-card-head">
                                    <div style="min-width: 0;">
                                        <h3 class="ex-card-title" onclick="openExerciseDetailsModal(<?= $safeJson ?>)"><?= h($ex['name']) ?></h3>
                                        <?php if ($user['role'] === 'platform_admin'): ?>
                                            <div style="font-size: 11px; color: var(--muted); margin-top: 3px; font-weight: 600;">
                                                Global Catalog
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php 
                                    $isGlobalEx = empty($ex['gym_id']) || (int)$ex['gym_id'] === 0 || empty($ex['gym_name']);
                                    $canManageEx = ($user['role'] === 'platform_admin' || (!$isGlobalEx && (int)$ex['gym_id'] === (int)$gymId));
                                    ?>
                                    <?php if ($canManageEx): ?>
                                        <div class="ex-card-actions">
                                            <button type="button" class="ex-card-action-btn" onclick="editExercise(<?= $safeJson ?>)" title="Edit Exercise">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            </button>
                                            <button type="button" class="ex-card-action-btn is-del" onclick="confirmDeleteExercise(<?= (int)$ex['exercise_id'] ?>, <?= htmlspecialchars(json_encode($ex['name']), ENT_QUOTES) ?>)" title="Delete Exercise">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="ex-meta-group">
                                    <?php if (!empty($ex['muscle_group'])): ?>
                                        <span class="ex-meta-tag" title="Target Muscle">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                                            <span><?= h(ucwords((string)$ex['muscle_group'])) ?></span>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($ex['equipment'])): ?>
                                        <span class="ex-meta-tag" title="Equipment">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 5v14M18 5v14M2 9v6M22 9v6M6 12h12"/></svg>
                                            <span><?= h(ucwords((string)$ex['equipment'])) ?></span>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <?php $descText = trim((string)($ex['description'] ?? '')); ?>
                                <p class="ex-desc-clamp">
                                    <?= nl2br(h($descText ?: 'No description provided.')) ?>
                                </p>
                            </div>

                            <div class="ex-card-footer">
                                <button type="button" class="ex-see-more-btn" onclick="openExerciseDetailsModal(<?= $safeJson ?>)">
                                    <span>View instructions</span>
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div style="margin-top: 32px;">
                        <?php 
                        $queryString = '&q=' . urlencode($q) . '&cat=' . urlencode($cat) . '&muscle=' . urlencode($muscle) . '&diff=' . urlencode($diff) . ($scope !== '' ? '&scope=' . urlencode($scope) : '');
                        render_pagination($pageNum, $totalPages, '?page=exercises' . $queryString); 
                        ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Hidden Delete Form for SweetAlert -->
    <form id="deleteExerciseForm" method="post" action="index.php?page=exercises" style="display:none;">
        <?= $csrfStr ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="exercise_id" id="deleteExerciseId" value="">
    </form>

    <!-- Exercise Details Modal -->
    <dialog id="exerciseDetailsModal" class="ninja-modal-dialog" style="max-width: 640px;">
        <div class="modal-header" style="padding: 18px 22px; border-bottom: 1px solid var(--line); display:flex; justify-content:space-between; align-items:flex-start; background: var(--panel);">
            <div>
                <h3 id="exDetailName" style="margin:0; font-size:20px; font-weight:750; color:var(--ink);">Exercise Details</h3>
                <div id="exDetailMeta" class="ex-meta-group" style="margin-top:8px;"></div>
            </div>
            <button type="button" class="modal-close" onclick="document.getElementById('exerciseDetailsModal').close()" style="background:transparent; border:none; color:var(--muted); cursor:pointer; padding:6px; border-radius:6px; display:flex; align-items:center;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="modal-body" style="padding: 22px; max-height: calc(85vh - 120px); overflow-y: auto; display:flex; flex-direction:column; gap:16px;">
            <div id="exDetailAnimBox" style="display:none; text-align:center; padding:12px; background:rgba(0,0,0,0.2); border-radius:8px; border:1px solid var(--line);">
                <video id="exDetailVideo" style="max-height:220px; max-width:100%; border-radius:6px; display:none;" autoplay loop muted playsinline></video>
                <img id="exDetailImg" style="max-height:220px; max-width:100%; object-fit:contain; border-radius:6px; display:none;" alt="Exercise Animation">
            </div>

            <div>
                <h4 style="margin:0 0 8px; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--muted);">Instructions & Safety Notes</h4>
                <div id="exDetailDesc" style="font-size:14px; line-height:1.65; color:var(--ink); background:color-mix(in srgb, var(--surface) 60%, transparent); padding:16px; border-radius:10px; border:1px solid var(--line); white-space:pre-wrap;"></div>
            </div>
        </div>
        <div class="modal-footer" style="padding: 14px 22px; border-top: 1px solid var(--line); display:flex; justify-content:flex-end; background: var(--panel);">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('exerciseDetailsModal').close()" style="padding:8px 20px; border-radius:8px; font-weight:600; cursor:pointer;">
                Close
            </button>
        </div>
    </dialog>

    <!-- API Ninjas Exercise Import Modal -->
    <dialog id="ninjaModal" class="ninja-modal-dialog">
        <div class="ninja-modal-header">
            <div style="display:flex; align-items:center; gap:12px; min-width:0;">
                <div class="ninja-header-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                </div>
                <div style="min-width:0;">
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <h3 style="margin:0; font-size:17px; font-weight:700; color:var(--ink);">Import from API Ninjas</h3>
                        <span class="ninja-header-badge">Verified Library</span>
                    </div>
                    <p style="margin:3px 0 0; font-size:12.5px; color:var(--muted);">Search and import verified exercises with instructions directly into your gym library.</p>
                </div>
            </div>
            <button type="button" class="ninja-close-btn" onclick="closeNinjaModal()" aria-label="Close modal">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>

        <div class="modal-body" style="padding: 18px 22px; flex: 1; min-height: 0; overflow-y: auto; display:flex; flex-direction:column; gap:16px;">
            <!-- Search Toolbar -->
            <form id="ninjaSearchForm" class="ninja-search-panel" onsubmit="event.preventDefault(); triggerNinjaSearch();">
                <input type="hidden" id="ninjaGymSelect" value="<?= $user['role'] === 'platform_admin' ? 0 : (int)$gymId ?>">

                <div class="ninja-search-grid">
                    <div>
                        <label for="ninjaSearchName" class="ninja-field-label">Exercise Name / Keyword</label>
                        <div class="ninja-input-wrap">
                            <svg class="ninja-input-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text" id="ninjaSearchName" class="ninja-field-control has-icon" placeholder="e.g. Bench press, Squat, Curl, Row...">
                        </div>
                    </div>
                    <div>
                        <label for="ninjaSearchMuscle" class="ninja-field-label">Target Muscle</label>
                        <select id="ninjaSearchMuscle" class="ninja-field-control">
                            <option value="">All Muscles</option>
                            <option value="chest">Chest</option>
                            <option value="biceps">Biceps</option>
                            <option value="triceps">Triceps</option>
                            <option value="forearms">Forearms</option>
                            <option value="quadriceps">Quadriceps (Quads)</option>
                            <option value="hamstrings">Hamstrings</option>
                            <option value="calves">Calves</option>
                            <option value="glutes">Glutes</option>
                            <option value="lats">Lats</option>
                            <option value="middle_back">Middle Back</option>
                            <option value="lower_back">Lower Back</option>
                            <option value="traps">Traps</option>
                            <option value="neck">Neck</option>
                            <option value="abdominals">Abdominals (Abs)</option>
                            <option value="abductors">Abductors</option>
                            <option value="adductors">Adductors</option>
                        </select>
                    </div>
                    <div>
                        <label for="ninjaSearchType" class="ninja-field-label">Exercise Type</label>
                        <select id="ninjaSearchType" class="ninja-field-control">
                            <option value="">All Types</option>
                            <option value="strength">Strength</option>
                            <option value="cardio">Cardio</option>
                            <option value="powerlifting">Powerlifting</option>
                            <option value="olympic_weightlifting">Olympic Lifting</option>
                            <option value="plyometrics">Plyometrics</option>
                            <option value="stretching">Stretching</option>
                            <option value="strongman">Strongman</option>
                        </select>
                    </div>
                    <div>
                        <label for="ninjaSearchDifficulty" class="ninja-field-label">Difficulty</label>
                        <select id="ninjaSearchDifficulty" class="ninja-field-control">
                            <option value="">All Levels</option>
                            <option value="beginner">Beginner</option>
                            <option value="intermediate">Intermediate</option>
                            <option value="expert">Expert</option>
                        </select>
                    </div>
                    <div>
                        <button type="submit" id="ninjaSearchBtn" class="ninja-search-submit">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <span>Search</span>
                        </button>
                    </div>
                </div>

                <!-- Quick suggestion chips -->
                <div class="ninja-popular-row">
                    <span style="font-size:10.5px; color:var(--muted); text-transform:uppercase; letter-spacing:0.05em; font-weight:700; margin-right:2px;">Popular:</span>
                    <button type="button" class="ninja-chip" onclick="quickNinjaFilter('chest')">Chest</button>
                    <button type="button" class="ninja-chip" onclick="quickNinjaFilter('biceps')">Biceps</button>
                    <button type="button" class="ninja-chip" onclick="quickNinjaFilter('triceps')">Triceps</button>
                    <button type="button" class="ninja-chip" onclick="quickNinjaFilter('quadriceps')">Quads & Legs</button>
                    <button type="button" class="ninja-chip" onclick="quickNinjaFilter('lats')">Back & Lats</button>
                    <button type="button" class="ninja-chip" onclick="quickNinjaFilter('abdominals')">Abs & Core</button>
                    <button type="button" class="ninja-chip" onclick="quickNinjaFilter('', 'cardio')">Cardio</button>
                </div>
            </form>

            <!-- Batch actions bar -->
            <div id="ninjaBatchBar" style="display:none; justify-content:space-between; align-items:center; padding:10px 14px; background:var(--surface); border:1px solid var(--line); border-radius:10px;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px; font-weight:600; margin:0; user-select:none;">
                        <input type="checkbox" id="ninjaSelectAll" onchange="toggleSelectAllNinja(this)" style="width:16px; height:16px; accent-color: var(--lime); cursor:pointer;">
                        Select All Available
                    </label>
                    <span id="ninjaResultsCount" style="font-size:12px; color:var(--muted);"></span>
                </div>
                <button type="button" id="ninjaBatchBtn" onclick="importBatchNinjaExercises()" disabled class="btn btn-sm" style="background:var(--lime); color:var(--bg); font-weight:700; border-radius:7px; padding:6px 14px; display:inline-flex; align-items:center; gap:6px; cursor:pointer; opacity:0.5; transition:all 0.15s ease;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <span>Import Selected (<span id="ninjaSelectedCount">0</span>)</span>
                </button>
            </div>

            <!-- Results List Container -->
            <div id="ninjaResultsList" style="min-height:180px; display:flex; flex-direction:column; gap:12px;">
                <!-- Initial state -->
                <div id="ninjaInitialState" class="ninja-empty-card">
                    <div class="ninja-empty-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    </div>
                    <h4 style="margin:0 0 6px; color:var(--ink); font-size:15px; font-weight:700;">Search verified exercises</h4>
                    <p style="margin:0; font-size:13px; max-width:440px; margin-inline:auto; line-height:1.5;">Enter a keyword above or select a popular muscle group to browse and import exercises with step-by-step instructions.</p>
                    <div class="ninja-empty-meta">
                        <span class="ninja-empty-tag">Step-by-step instructions</span>
                        <span class="ninja-empty-tag">Target muscle</span>
                        <span class="ninja-empty-tag">Difficulty &amp; equipment</span>
                    </div>
                </div>

                <!-- Loading state -->
                <div id="ninjaLoadingState" class="ninja-empty-card" style="display:none;">
                    <div style="width:28px; height:28px; margin:0 auto 12px; border: 3px solid rgba(56,189,248,0.2); border-top-color: #0ea5e9; border-radius: 50%; animation: ninjaSpin 0.75s linear infinite;"></div>
                    <p style="margin:0; font-size:13px; font-weight:600; color:var(--ink);">Fetching exercises from API Ninjas...</p>
                </div>

                <!-- Error state -->
                <div id="ninjaErrorState" style="display:none; text-align:center; padding:24px 20px; background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.25); border-radius:10px; color:#f87171;">
                    <p id="ninjaErrorMessage" style="margin:0; font-size:13px; font-weight:600;"></p>
                </div>

                <!-- Empty state -->
                <div id="ninjaEmptyState" class="ninja-empty-card" style="display:none;">
                    <p style="margin:0; font-size:13.5px; font-weight:600; color:var(--ink);">No matching exercises found</p>
                    <p style="margin:4px 0 0; font-size:12.5px;">Try different keywords, muscle groups, or clear a filter.</p>
                </div>

                <!-- Dynamic Cards rendered here -->
                <div id="ninjaCardsContainer" style="display:flex; flex-direction:column; gap:10px;"></div>
            </div>
        </div>

        <div class="modal-footer" style="padding: 14px 22px; border-top: 1px solid var(--line); display:flex; justify-content:space-between; align-items:center; background: var(--panel); flex-shrink: 0;">
            <div id="ninjaImportStatus" style="font-size:13px; font-weight:600; color:var(--lime);"></div>
            <button type="button" class="ninja-done-btn" onclick="closeNinjaModal()">
                Done
            </button>
        </div>
    </dialog>

    <!-- Modal -->
    <dialog id="exModal" class="modal">
        <div class="modal-header">
            <h3 id="exModalTitle" style="margin:0; font-size:20px;">Exercise</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('exModal').close()">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="modal-body">
            <form id="exForm" method="post" action="index.php?page=exercises" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:16px;">
                <?= $csrfStr ?>
                <input type="hidden" name="action" id="exFormAction" value="create">
                <input type="hidden" name="exercise_id" id="exFormId" value="">
                
                <label><span class="label-title">Name <span class="req-star">*</span></span> <input type="text" name="name" id="exName" class="form-control" placeholder="e.g. Barbell Squat" required autocomplete="off"></label>
                <div id="exNameDuplicateWarning" style="display:none; color:#ef4444; font-size:12px; margin-top:-8px; margin-bottom:4px; font-weight:500; align-items:center; gap:6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span id="exNameDuplicateText">An exercise with this name already exists.</span>
                </div>
                
                <?php if ($user['role'] === 'platform_admin'): ?>
                <input type="hidden" name="gym_id" id="exGymId" value="0">
                <?php endif; ?>
                
                <div class="ex-modal-row-3" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px;">
                    <label><span class="label-title">Target Muscle <span class="req-star">*</span></span>
                        <select name="muscle_group" id="exMuscle" class="form-control" required style="height:42px;">
                            <option value="">-- Select Muscle --</option>
                            <option value="Chest">Chest</option>
                            <option value="Biceps">Biceps</option>
                            <option value="Triceps">Triceps</option>
                            <option value="Forearms">Forearms</option>
                            <option value="Quadriceps">Quadriceps (Quads)</option>
                            <option value="Hamstrings">Hamstrings</option>
                            <option value="Calves">Calves</option>
                            <option value="Glutes">Glutes</option>
                            <option value="Lats">Lats</option>
                            <option value="Middle Back">Middle Back</option>
                            <option value="Lower Back">Lower Back</option>
                            <option value="Traps">Traps</option>
                            <option value="Neck">Neck</option>
                            <option value="Abdominals">Abdominals (Abs)</option>
                            <option value="Abductors">Abductors</option>
                            <option value="Adductors">Adductors</option>
                        </select>
                    </label>
                    <label><span class="label-title">Exercise Type <span class="req-star">*</span></span>
                        <select name="category" id="exCat" class="form-control" required style="height:42px;">
                            <option value="">-- Select Type --</option>
                            <option value="Strength">Strength</option>
                            <option value="Cardio">Cardio</option>
                            <option value="Powerlifting">Powerlifting</option>
                            <option value="Olympic Lifting">Olympic Lifting</option>
                            <option value="Plyometrics">Plyometrics</option>
                            <option value="Stretching">Stretching</option>
                            <option value="Strongman">Strongman</option>
                        </select>
                    </label>
                    <label><span class="label-title">Difficulty <span class="req-star">*</span></span>
                        <select name="difficulty_level" id="exDifficulty" class="form-control" required style="height:42px;">
                            <option value="1">Beginner</option>
                            <option value="2">Intermediate</option>
                            <option value="3">Expert</option>
                        </select>
                    </label>
                </div>
                
                <label><span class="label-title">Equipment</span>
                    <input type="text" name="equipment" id="exEquipment" class="form-control" placeholder="e.g. Barbell, Dumbbell, Cable Machine, Bodyweight">
                </label>
                
                <label>Description <textarea name="description" id="exDesc" class="form-control" placeholder="Form cues or notes..." rows="3"></textarea></label>
                
                <label>Upload Animation File <span style="color:var(--muted); font-size:12px;">(MP4, WebM, GIF, WebP - Max 15MB)</span>
                    <input type="file" name="animation" id="exAnimation" class="form-control" accept="video/mp4,video/webm,image/gif,image/webp">
                </label>
                
                <label style="margin-top:-6px;">
                    <span style="color:var(--muted); font-size:12px; font-weight:600;">Or Animation URL <span style="font-weight:normal;">(ImageKit, Cloudinary, or direct video/GIF link)</span></span>
                    <input type="url" name="animation_url_input" id="exAnimationUrlInput" class="form-control" placeholder="https://ik.imagekit.io/... or https://.../anim.gif">
                </label>

                <div id="exCurrentAnimContainer" style="display:none; font-size: 13px; padding: 12px; background: color-mix(in srgb, var(--surface) 60%, transparent); border-radius: 8px; border: 1px solid var(--line);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <span style="color:var(--muted); font-weight:600;">Current Animation Preview:</span> 
                        <a id="exCurrentAnimLink" href="#" target="_blank" style="color:var(--lime); font-weight: 600; text-decoration: underline;">Open Link ↗</a>
                    </div>
                    <div id="exPreviewBox" style="display:flex; justify-content:center; align-items:center; min-height:140px; max-height:220px; background:rgba(0,0,0,0.25); border-radius:6px; overflow:hidden; border:1px solid var(--line);">
                        <video id="exPreviewVideo" style="max-height:200px; max-width:100%; border-radius:6px; display:none;" autoplay loop muted playsinline></video>
                        <img id="exPreviewImg" style="max-height:200px; max-width:100%; object-fit:contain; border-radius:6px; display:none;" alt="Preview">
                        <span id="exPreviewError" style="display:none; color:var(--muted); font-size:12px;">Preview unavailable</span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="margin-top:8px;">Save Exercise</button>
            </form>
        </div>
    </dialog>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const exNameInput = document.getElementById('exName');
        if (exNameInput) {
            exNameInput.addEventListener('input', checkExerciseDuplicate);
            exNameInput.addEventListener('blur', checkExerciseDuplicate);
        }

        const exForm = document.getElementById('exForm');
        if (exForm) {
            exForm.addEventListener('submit', function(e) {
                if (isDuplicateExName) {
                    e.preventDefault();
                    const warnText = document.getElementById('exNameDuplicateText');
                    const msg = (warnText && warnText.textContent) ? warnText.textContent : 'An exercise with this name already exists.';
                    if (window.Swal) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Duplicate Exercise',
                            text: msg,
                            confirmButtonColor: '#f59e0b'
                        });
                    } else {
                        alert(msg);
                    }
                    if (exNameInput) exNameInput.focus();
                }
            });
        }
    });

    function confirmDeleteExercise(id, name) {
        if (window.Swal) {
            Swal.fire({
                title: 'Delete Exercise?',
                text: 'Are you sure you want to delete "' + name + '"? This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#374151',
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel',
                reverseButtons: true,
                focusCancel: true
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('deleteExerciseId').value = id;
                    document.getElementById('deleteExerciseForm').submit();
                }
            });
        } else {
            if (confirm('Are you sure you want to delete "' + name + '"?')) {
                document.getElementById('deleteExerciseId').value = id;
                document.getElementById('deleteExerciseForm').submit();
            }
        }
    }

    function openExerciseDetailsModal(ex) {
        if (!ex) return;
        document.getElementById('exDetailName').innerText = ex.name || 'Exercise Details';
        
        const metaWrap = document.getElementById('exDetailMeta');
        let metaHtml = '';
        if (ex.muscle_group) {
            metaHtml += `<span class="ex-meta-tag"><span class="ex-meta-label">Target:</span><span class="ex-meta-value">${escapeHtml(ex.muscle_group)}</span></span>`;
        }
        if (ex.category) {
            metaHtml += `<span class="ex-meta-tag"><span class="ex-meta-label">Type:</span><span class="ex-meta-value">${escapeHtml(ex.category)}</span></span>`;
        }
        if (ex.equipment) {
            metaHtml += `<span class="ex-meta-tag"><span class="ex-meta-label">Equip:</span><span class="ex-meta-value">${escapeHtml(ex.equipment)}</span></span>`;
        }
        if (ex.difficulty_level) {
            const diffs = {1: 'Beginner', 2: 'Intermediate', 3: 'Expert'};
            metaHtml += `<span class="ex-meta-tag"><span class="ex-meta-label">Level:</span><span class="ex-meta-value">${diffs[ex.difficulty_level] || 'All Levels'}</span></span>`;
        }
        metaWrap.innerHTML = metaHtml;

        const descBox = document.getElementById('exDetailDesc');
        descBox.innerText = ex.description ? ex.description : 'No description or instructions provided.';

        const animBox = document.getElementById('exDetailAnimBox');
        const video = document.getElementById('exDetailVideo');
        const img = document.getElementById('exDetailImg');
        animBox.style.display = 'none';
        video.style.display = 'none';
        img.style.display = 'none';

        if (ex.animation_url && ex.animation_url.trim() !== '') {
            let raw = ex.animation_url.trim();
            const src = (raw.startsWith('http://') || raw.startsWith('https://'))
                ? raw
                : (raw.startsWith('assets/') ? raw : 'assets/exercise_animations/' + raw);
            
            const cleanUrl = raw.split('?')[0].toLowerCase();
            const isImg = cleanUrl.endsWith('.gif') || cleanUrl.endsWith('.webp') || cleanUrl.endsWith('.png') || cleanUrl.endsWith('.jpg') || cleanUrl.endsWith('.jpeg');
            animBox.style.display = 'block';
            if (isImg) {
                img.src = src;
                img.style.display = 'block';
            } else {
                video.src = src;
                video.style.display = 'block';
                video.load();
                video.play().catch(()=>{});
            }
        }

        const modal = document.getElementById('exerciseDetailsModal');
        if (modal) {
            modal.showModal();
        }
    }

    function resolveExerciseImageJs(name, muscle, category) {
        return 'assets/images/default exercise.png';
    }

    function setSelectValueCaseInsensitive(selectEl, value) {
        if (!selectEl) return;
        if (!value) { selectEl.value = ''; return; }
        const lower = String(value).toLowerCase().trim();
        for (let i = 0; i < selectEl.options.length; i++) {
            if (selectEl.options[i].value.toLowerCase().trim() === lower) {
                selectEl.selectedIndex = i;
                return;
            }
        }
        const newOpt = document.createElement('option');
        newOpt.value = value;
        newOpt.textContent = value;
        selectEl.appendChild(newOpt);
        selectEl.value = value;
    }

    let exNameCheckTimer = null;
    let isDuplicateExName = false;

    function resetExerciseDuplicateWarning() {
        isDuplicateExName = false;
        const warning = document.getElementById('exNameDuplicateWarning');
        const input = document.getElementById('exName');
        if (warning) warning.style.display = 'none';
        if (input) input.style.borderColor = '';
    }

    function checkExerciseDuplicate() {
        clearTimeout(exNameCheckTimer);
        const input = document.getElementById('exName');
        const warning = document.getElementById('exNameDuplicateWarning');
        const warnText = document.getElementById('exNameDuplicateText');
        if (!input || !warning || !warnText) return;

        const val = (input.value || '').trim();
        const excludeId = document.getElementById('exFormId').value || 0;
        const gymIdSelect = document.getElementById('exGymId');
        const gymId = gymIdSelect ? gymIdSelect.value : '';

        if (!val || val.length < 2) {
            warning.style.display = 'none';
            input.style.borderColor = '';
            isDuplicateExName = false;
            return;
        }

        exNameCheckTimer = setTimeout(() => {
            let url = 'index.php?page=exercises&action=check_duplicate&name=' + encodeURIComponent(val) + '&exclude_id=' + encodeURIComponent(excludeId);
            if (gymId) url += '&gym_id=' + encodeURIComponent(gymId);

            fetch(url)
                .then(res => res.json())
                .then(data => {
                    if (data.exists) {
                        isDuplicateExName = true;
                        warnText.textContent = data.message || 'An exercise with this name already exists.';
                        warning.style.display = 'flex';
                        input.style.borderColor = '#ef4444';
                    } else {
                        isDuplicateExName = false;
                        warning.style.display = 'none';
                        input.style.borderColor = '';
                    }
                })
                .catch(() => {
                    isDuplicateExName = false;
                });
        }, 250);
    }

    function openNewExerciseModal() {
        resetExerciseDuplicateWarning();
        document.getElementById('exForm').reset();
        document.getElementById('exFormAction').value = 'create';
        document.getElementById('exFormId').value = '';
        document.getElementById('exEquipment').value = '';
        if (document.getElementById('exCat')) document.getElementById('exCat').value = 'Strength';
        if (document.getElementById('exMuscle')) document.getElementById('exMuscle').value = '';
        if (document.getElementById('exDifficulty')) document.getElementById('exDifficulty').value = '1';
        if (document.getElementById('exGymId')) document.getElementById('exGymId').value = '0';
        document.getElementById('exModalTitle').innerText = 'Add New Exercise';
        document.getElementById('exCurrentAnimContainer').style.display = 'none';
        document.getElementById('exPreviewVideo').style.display = 'none';
        document.getElementById('exPreviewImg').style.display = 'none';
        document.getElementById('exModal').showModal();
    }

    function editExercise(ex) {
        resetExerciseDuplicateWarning();
        document.getElementById('exForm').reset();
        document.getElementById('exFormAction').value = 'edit';
        document.getElementById('exModalTitle').innerText = 'Edit Exercise';
        
        document.getElementById('exFormId').value = ex.exercise_id;
        document.getElementById('exName').value = ex.name;
        setSelectValueCaseInsensitive(document.getElementById('exCat'), ex.category || 'Strength');
        setSelectValueCaseInsensitive(document.getElementById('exMuscle'), ex.muscle_group || '');
        if (document.getElementById('exDifficulty')) {
            document.getElementById('exDifficulty').value = ex.difficulty_level || 1;
        }
        document.getElementById('exEquipment').value = ex.equipment || '';
        document.getElementById('exDesc').value = ex.description || '';
        
        var gymIdSelect = document.getElementById('exGymId');
        if (gymIdSelect) {
            gymIdSelect.value = (ex.gym_id && Number(ex.gym_id) > 0 && ex.gym_name) ? String(ex.gym_id) : '0';
        }

        const animContainer = document.getElementById('exCurrentAnimContainer');
        const animLink = document.getElementById('exCurrentAnimLink');
        const previewVideo = document.getElementById('exPreviewVideo');
        const previewImg = document.getElementById('exPreviewImg');
        const previewError = document.getElementById('exPreviewError');
        const urlInput = document.getElementById('exAnimationUrlInput');

        previewVideo.style.display = 'none';
        previewImg.style.display = 'none';
        previewError.style.display = 'none';

        if (ex.animation_url && ex.animation_url.trim() !== '') {
            let raw = ex.animation_url.trim();
            const src = (raw.startsWith('http://') || raw.startsWith('https://'))
                ? raw
                : (raw.startsWith('assets/') ? raw : 'assets/exercise_animations/' + raw);
            
            animLink.href = src;
            animContainer.style.display = 'block';

            if (raw.startsWith('http://') || raw.startsWith('https://')) {
                urlInput.value = raw;
            } else {
                urlInput.value = '';
            }

            const cleanUrl = raw.split('?')[0].toLowerCase();
            const isImg = cleanUrl.endsWith('.gif') || cleanUrl.endsWith('.webp') || cleanUrl.endsWith('.png') || cleanUrl.endsWith('.jpg') || cleanUrl.endsWith('.jpeg');
            if (isImg) {
                previewImg.onload = () => { previewImg.style.display = 'block'; };
                previewImg.onerror = () => { previewError.style.display = 'block'; };
                previewImg.src = src;
            } else {
                previewVideo.muted = true;
                previewVideo.setAttribute('playsinline', '');
                previewVideo.onloadeddata = () => { previewVideo.style.display = 'block'; previewVideo.play().catch(()=>{}); };
                previewVideo.onerror = () => { previewError.style.display = 'block'; };
                previewVideo.src = src;
                try { previewVideo.load(); } catch(e) {}
            }
        } else {
            animContainer.style.display = 'none';
            urlInput.value = '';
        }
        
        document.getElementById('exModal').showModal();
    }

    // ── API Ninjas Modal & Import Client Logic ───────────────────
    let currentNinjaResults = [];
    let hasImportedAny = false;
    const csrfToken = <?= json_encode(csrf_token()) ?>;

    function openNinjaModal() {
        const statusEl = document.getElementById('ninjaImportStatus');
        if (statusEl) statusEl.innerText = '';
        hasImportedAny = false;
        const detailsModal = document.getElementById('exerciseDetailsModal');
        if (detailsModal) {
            detailsModal.addEventListener('click', function(e) {
                if (e.target === this) this.close();
            });
        }
        const modal = document.getElementById('ninjaModal');
        if (modal) {
            modal.showModal();
        } else {
            console.error('Modal #ninjaModal not found');
        }
    }

    function closeNinjaModal() {
        const modal = document.getElementById('ninjaModal');
        if (modal) {
            modal.close();
        }
        if (hasImportedAny) {
            window.location.reload();
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('ninjaModal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeNinjaModal();
                }
            });
        }
    });

    function quickNinjaFilter(muscle, type = '') {
        const muscleSelect = document.getElementById('ninjaSearchMuscle');
        const typeSelect = document.getElementById('ninjaSearchType');
        const nameInput = document.getElementById('ninjaSearchName');
        
        if (muscleSelect) muscleSelect.value = muscle;
        if (typeSelect) typeSelect.value = type;
        if (nameInput) nameInput.value = '';
        triggerNinjaSearch();
    }

    async function triggerNinjaSearch() {
        const name = (document.getElementById('ninjaSearchName').value || '').trim();
        const muscle = document.getElementById('ninjaSearchMuscle').value;
        const type = document.getElementById('ninjaSearchType').value;
        const difficulty = document.getElementById('ninjaSearchDifficulty').value;
        const gymEl = document.getElementById('ninjaGymSelect');
        const targetGymId = gymEl ? gymEl.value : '';

        const initialEl = document.getElementById('ninjaInitialState');
        const loadingEl = document.getElementById('ninjaLoadingState');
        const errorEl = document.getElementById('ninjaErrorState');
        const emptyEl = document.getElementById('ninjaEmptyState');
        const container = document.getElementById('ninjaCardsContainer');
        const batchBar = document.getElementById('ninjaBatchBar');

        initialEl.style.display = 'none';
        errorEl.style.display = 'none';
        emptyEl.style.display = 'none';
        container.innerHTML = '';
        batchBar.style.display = 'none';
        loadingEl.style.display = 'block';

        const params = new URLSearchParams({
            page: 'exercises',
            action: 'search_ninja',
            q: name,
            muscle: muscle,
            type: type,
            difficulty: difficulty,
            gym_id: targetGymId
        });

        try {
            const resp = await fetch('index.php?' + params.toString(), {
                headers: { 'Accept': 'application/json' }
            });
            const data = await resp.json();
            loadingEl.style.display = 'none';

            if (!data.success) {
                errorEl.style.display = 'block';
                document.getElementById('ninjaErrorMessage').innerText = data.error || 'Failed to search exercises.';
                if (data.rate_limited && data.retry_after) {
                    const btn = document.getElementById('ninjaSearchBtn');
                    if (btn) {
                        btn.disabled = true;
                        let left = parseInt(data.retry_after, 10);
                        const origHtml = btn.innerHTML;
                        const timer = setInterval(() => {
                            left--;
                            if (left <= 0) {
                                clearInterval(timer);
                                btn.disabled = false;
                                btn.innerHTML = origHtml;
                                errorEl.style.display = 'none';
                            } else {
                                btn.innerText = `Wait ${left}s`;
                            }
                        }, 1000);
                    }
                }
                return;
            }

            currentNinjaResults = data.exercises || [];
            if (currentNinjaResults.length === 0) {
                emptyEl.style.display = 'block';
                return;
            }

            renderNinjaResults(currentNinjaResults);
        } catch (err) {
            loadingEl.style.display = 'none';
            errorEl.style.display = 'block';
            document.getElementById('ninjaErrorMessage').innerText = 'Request error: ' + err.message;
        }
    }

    function renderNinjaResults(exercises) {
        const container = document.getElementById('ninjaCardsContainer');
        const batchBar = document.getElementById('ninjaBatchBar');
        const countSpan = document.getElementById('ninjaResultsCount');
        const selectAllCb = document.getElementById('ninjaSelectAll');

        container.innerHTML = '';
        batchBar.style.display = 'flex';
        countSpan.innerText = exercises.length + ' exercises found';
        if (selectAllCb) selectAllCb.checked = false;
        updateBatchImportButton();

        exercises.forEach((ex, idx) => {
            const card = document.createElement('div');
            card.className = 'ninja-card' + (ex.already_imported ? ' already-imported' : '');
            card.id = 'ninja-card-' + idx;

            let diffBadge = '';
            if (ex.difficulty_level === 1) {
                diffBadge = '<span class="ex-pill" style="background:rgba(34,197,94,0.15); color:#4ade80;">Beginner</span>';
            } else if (ex.difficulty_level === 2) {
                diffBadge = '<span class="ex-pill" style="background:rgba(234,179,8,0.15); color:#facc15;">Intermediate</span>';
            } else {
                diffBadge = '<span class="ex-pill" style="background:rgba(239,68,68,0.15); color:#f87171;">Expert</span>';
            }

            let equipBadge = '';
            if (ex.equipment) {
                equipBadge = `<span class="ex-pill" style="background:rgba(168,85,247,0.15); color:#c084fc;">${escapeHtml(ex.equipment)}</span>`;
            }

            const actionHtml = ex.already_imported 
                ? `<span class="badge" style="background:rgba(255,255,255,0.06); color:var(--muted); border:1px solid var(--line); font-size:12px; font-weight:600; padding:5px 10px; border-radius:6px; display:inline-flex; align-items:center; gap:4px;">✓ In Library</span>`
                : `<button type="button" class="btn btn-sm btn-primary" onclick="importSingleNinjaExercise(this, ${idx})" style="font-weight:700; border-radius:6px; padding:6px 14px; font-size:12px;">+ Import</button>`;

            const cbHtml = ex.already_imported 
                ? `<input type="checkbox" disabled style="width:16px; height:16px; opacity:0.3; cursor:not-allowed;">`
                : `<input type="checkbox" class="ninja-item-cb" data-idx="${idx}" onchange="updateBatchImportButton()" style="width:16px; height:16px; accent-color: var(--lime); cursor:pointer;">`;

            card.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        ${cbHtml}
                        <h4 style="margin:0; font-size:15px; font-weight:700; color:var(--ink);">${escapeHtml(ex.name)}</h4>
                    </div>
                    <div id="ninja-action-${idx}">
                        ${actionHtml}
                    </div>
                </div>

                <div class="ex-meta-group" style="margin-top:2px;">
                    <span class="ex-meta-tag">
                        <span class="ex-meta-label">Target:</span>
                        <span class="ex-meta-value">${escapeHtml(ex.muscle_group)}</span>
                    </span>
                    <span class="ex-meta-tag">
                        <span class="ex-meta-label">Type:</span>
                        <span class="ex-meta-value">${escapeHtml(ex.category)}</span>
                    </span>
                    ${ex.equipment ? `
                        <span class="ex-meta-tag">
                            <span class="ex-meta-label">Equip:</span>
                            <span class="ex-meta-value">${escapeHtml(ex.equipment)}</span>
                        </span>
                    ` : ''}
                    <span class="ex-meta-tag">
                        <span class="ex-meta-label">Level:</span>
                        <span class="ex-meta-value">${escapeHtml(ex.difficulty)}</span>
                    </span>
                </div>

                ${ex.description ? `
                    <details style="font-size:13px; color:var(--muted); line-height:1.5; margin-top:2px; background:rgba(0,0,0,0.15); border-radius:6px; padding:6px 10px;">
                        <summary style="cursor:pointer; font-weight:600; color:var(--ink); font-size:12px; outline:none;">Show Form Instructions</summary>
                        <p style="margin:8px 0 0; white-space:pre-wrap;">${escapeHtml(ex.description)}</p>
                    </details>
                ` : ''}
            `;

            container.appendChild(card);
        });
    }

    function toggleSelectAllNinja(cb) {
        const checkboxes = document.querySelectorAll('.ninja-item-cb');
        checkboxes.forEach(c => {
            if (!c.disabled) c.checked = cb.checked;
        });
        updateBatchImportButton();
    }

    function updateBatchImportButton() {
        const checked = document.querySelectorAll('.ninja-item-cb:checked');
        const countSpan = document.getElementById('ninjaSelectedCount');
        const btn = document.getElementById('ninjaBatchBtn');
        if (countSpan) countSpan.innerText = checked.length;
        if (btn) {
            btn.disabled = checked.length === 0;
            btn.style.opacity = checked.length === 0 ? '0.5' : '1';
        }
    }

    async function importSingleNinjaExercise(btn, idx) {
        const ex = currentNinjaResults[idx];
        if (!ex) return;

        const origText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = 'Importing...';

        const gymEl = document.getElementById('ninjaGymSelect');
        const targetGymId = gymEl ? gymEl.value : '';

        const formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('action', 'import_ninja');
        formData.append('gym_id', targetGymId);
        formData.append('name', ex.name);
        formData.append('category', ex.category);
        formData.append('muscle_group', ex.muscle_group);
        formData.append('equipment', ex.equipment || '');
        formData.append('difficulty_level', ex.difficulty_level || 1);
        formData.append('description', ex.description || '');

        try {
            const resp = await fetch('index.php?page=exercises', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData
            });
            const data = await resp.json();

            if (data.success) {
                hasImportedAny = true;
                ex.already_imported = true;
                const actionWrap = document.getElementById('ninja-action-' + idx);
                if (actionWrap) {
                    actionWrap.innerHTML = `<span class="badge" style="background:rgba(132,204,22,0.15); color:#84cc16; border:1px solid rgba(132,204,22,0.3); font-size:12px; font-weight:600; padding:5px 10px; border-radius:6px; display:inline-flex; align-items:center; gap:4px;">✓ Imported</span>`;
                }
                const card = document.getElementById('ninja-card-' + idx);
                if (card) {
                    card.classList.add('already-imported');
                    const cb = card.querySelector('.ninja-item-cb');
                    if (cb) {
                        cb.checked = false;
                        cb.disabled = true;
                    }
                }
                updateBatchImportButton();
                document.getElementById('ninjaImportStatus').innerText = `✓ "${ex.name}" added to gym library!`;
            } else {
                btn.disabled = false;
                btn.innerHTML = origText;
                alert(data.error || 'Failed to import exercise.');
            }
        } catch (err) {
            btn.disabled = false;
            btn.innerHTML = origText;
            alert('Import error: ' + err.message);
        }
    }

    async function importBatchNinjaExercises() {
        const checked = Array.from(document.querySelectorAll('.ninja-item-cb:checked'));
        if (checked.length === 0) return;

        const btn = document.getElementById('ninjaBatchBtn');
        const origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = 'Importing...';

        const gymEl = document.getElementById('ninjaGymSelect');
        const targetGymId = gymEl ? gymEl.value : '';

        const selectedItems = checked.map(c => {
            const idx = parseInt(c.dataset.idx, 10);
            return currentNinjaResults[idx];
        }).filter(Boolean);

        const formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('action', 'batch_import_ninja');
        formData.append('gym_id', targetGymId);
        formData.append('exercises_json', JSON.stringify(selectedItems));

        try {
            const resp = await fetch('index.php?page=exercises', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData
            });
            const data = await resp.json();

            if (data.success) {
                hasImportedAny = true;
                checked.forEach(c => {
                    const idx = parseInt(c.dataset.idx, 10);
                    if (currentNinjaResults[idx]) {
                        currentNinjaResults[idx].already_imported = true;
                    }
                    const actionWrap = document.getElementById('ninja-action-' + idx);
                    if (actionWrap) {
                        actionWrap.innerHTML = `<span class="badge" style="background:rgba(132,204,22,0.15); color:#84cc16; border:1px solid rgba(132,204,22,0.3); font-size:12px; font-weight:600; padding:5px 10px; border-radius:6px;">✓ Imported</span>`;
                    }
                    const card = document.getElementById('ninja-card-' + idx);
                    if (card) {
                        card.classList.add('already-imported');
                        c.checked = false;
                        c.disabled = true;
                    }
                });
                updateBatchImportButton();
                document.getElementById('ninjaImportStatus').innerText = data.message || `Imported ${data.imported_count} exercises!`;
            } else {
                alert(data.error || 'Failed to batch import.');
            }
        } catch (err) {
            alert('Batch import error: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    </script>
    <?php
    render_footer();
}
