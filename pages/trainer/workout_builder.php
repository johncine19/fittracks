<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/workouts.php';

function workout_builder_page(): void
{
    $user = require_roles(['trainer', 'gym_owner']);
    $memberId = (int) ($_GET['member_user_id'] ?? 0);
    
    if (!$memberId) {
        redirect('dashboard');
    }
    
    $pdo = db();
    
    // Fetch member
    $memberStmt = $pdo->prepare('SELECT first_name, last_name, profile_picture FROM users WHERE user_id = ?');
    $memberStmt->execute([$memberId]);
    $member = $memberStmt->fetch();
    
    if (!$member) {
        flash('Member not found.', 'error');
        redirect('training');
    }
    
    $profile = member_profile((int)$memberId) ?: [];

    // Specific training days chosen by the member take precedence and cannot be overridden by staff
    $prefWorkoutDays = [];
    if (!empty($profile['preferred_workout_days'])) {
        $prefWorkoutDays = array_values(array_filter(array_map('intval', explode(',', (string)$profile['preferred_workout_days'])), fn($d) => $d >= 1 && $d <= 7));
        sort($prefWorkoutDays);
    }
    // This target is authoritative for plans created in this builder.
    $prefDays = !empty($prefWorkoutDays) ? count($prefWorkoutDays) : max(1, min(7, (int)($profile['weekly_workout_target'] ?: 3)));

    // Authoritative athlete experience level derived from fitness tier and activity
    $rawActivity = strtolower((string)($profile['activity_level'] ?? ''));
    $activityLabel = !empty($rawActivity) ? ucwords(str_replace('_', ' ', $rawActivity)) : 'Moderate';
    $rawTier = (int)($profile['fitness_tier'] ?? 0);
    if ($rawTier >= 5) {
        $expLevel = 3;
    } elseif ($rawTier >= 3) {
        $expLevel = 2;
    } elseif ($rawTier >= 1) {
        $expLevel = 1;
    } else {
        if (in_array($rawActivity, ['sedentary', 'light', 'lightly_active'], true)) {
            $expLevel = 1;
        } elseif (in_array($rawActivity, ['very_active', 'extra_active', 'active'], true)) {
            $expLevel = 3;
        } else {
            $expLevel = 2;
        }
    }
    $tierNames = [1 => 'Beginner', 2 => 'Intermediate', 3 => 'Advanced'];
    $tierKeys = [1 => 'beginner', 2 => 'intermediate', 3 => 'advanced'];
    $memberTierLabel = $tierNames[$expLevel] ?? 'Intermediate';
    $memberExpKey = $tierKeys[$expLevel] ?? 'intermediate';
    $fitnessTier = $expLevel;
    $getScheduledDaysCount = static function (PDO $pdo, int $planId): int {
        $stmt = $pdo->prepare('SELECT COUNT(DISTINCT day_of_week) FROM training_plan_exercises WHERE plan_id = ?');
        $stmt->execute([$planId]);
        return (int) $stmt->fetchColumn();
    };
    
    // Fetch active membership to sync dates
    $membershipStmt = $pdo->prepare('SELECT m.start_date, m.end_date, mp.plan_name, mp.duration_days FROM memberships m LEFT JOIN membership_plans mp ON m.plan_id = mp.plan_id WHERE m.user_id = ? AND m.status = "active" AND m.end_date >= CURDATE() ORDER BY m.end_date DESC LIMIT 1');
    $membershipStmt->execute([$memberId]);
    $membership = $membershipStmt->fetch();
    $hasMembership = (bool) $membership;
    $today = date('Y-m-d');
    $defaultStart = $today;
    $defaultEnd = $membership ? $membership['end_date'] : date('Y-m-d', strtotime('+7 days'));
    $membershipEndDate = $membership ? $membership['end_date'] : null;
    $membershipPlanName = $membership['plan_name'] ?? null;
    
    // Fetch trainer ID & gym_id
    $trainerId = ensure_coach_profile((int)$user['user_id']);
    $trainerProfile = $pdo->query('SELECT gym_id FROM trainer_profiles WHERE trainer_id = ' . $trainerId)->fetch();
    
    if ($user['role'] === 'trainer') {
        $isAssigned = (bool) scalar('SELECT 1 FROM trainer_assignments WHERE trainer_id = ? AND member_user_id = ? AND status = "active"', [$trainerId, $memberId]);
        if (!$isAssigned) {
            flash('You can only design workout plans for clients actively assigned to you.', 'danger');
            redirect('trainer_members');
        }
    }

    if ($user['role'] === 'gym_owner') {
        $trainerGymId = (int) (scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]) ?? 0);
    } else {
        $trainerGymId = (int) ($trainerProfile['gym_id'] ?? 0);
        if (!$trainerGymId) {
            $trainerGymId = (int) (scalar('SELECT gym_id FROM gym_members WHERE user_id = ? LIMIT 1', [$memberId]) ?? 0);
        }
        if (!$trainerGymId) {
            $trainerGymId = (int) (scalar('SELECT mp.gym_id FROM memberships m JOIN membership_plans mp ON m.plan_id = mp.plan_id WHERE m.user_id = ? AND m.status = "active" LIMIT 1', [$memberId]) ?? 0);
        }
    }
    // Scope every builder action to the gym operated by the current trainer or
    // owner. A member can be linked to multiple gyms, so their gym_members row
    // is not a safe source for this gym's exercise library.
    $exerciseLibraryGymId = $trainerGymId;
    
    // AJAX Live Search for Exercises (Hybrid Search server-side query)
    if (isset($_GET['action']) && $_GET['action'] === 'search_exercises') {
        header('Content-Type: application/json; charset=utf-8');
        $q = trim((string)($_GET['q'] ?? ''));
        if ($exerciseLibraryGymId <= 0 || $q === '') {
            echo json_encode(['results' => []]);
            exit;
        }
        $searchStmt = $pdo->prepare('
            SELECT exercise_id, name, muscle_group, category, difficulty_level, description 
            FROM exercises 
            WHERE gym_id = ? AND (name LIKE ? OR muscle_group LIKE ? OR category LIKE ?)
            ORDER BY muscle_group, name
            LIMIT 50
        ');
        $like = '%' . $q . '%';
        $searchStmt->execute([$exerciseLibraryGymId, $like, $like, $like]);
        $results = [];
        foreach ($searchStmt->fetchAll() as $row) {
            $results[] = [
                'id' => (int)$row['exercise_id'],
                'name' => $row['name'],
                'muscle_group' => !empty($row['muscle_group']) ? ucwords((string)$row['muscle_group']) : 'General',
                'category' => !empty($row['category']) ? ucwords((string)$row['category']) : '',
                'difficulty' => (int)($row['difficulty_level'] ?? 1),
                'description' => (string)($row['description'] ?? '')
            ];
        }
        echo json_encode(['results' => $results]);
        exit;
    }
    
    // Resolve target plan (Approach 1: Direct Editing + Approach 2: Lazy Draft on Mutation)
    $requestedPlanId = (int) ($_GET['plan_id'] ?? 0);
    $plan = null;

    // 1. If explicit plan_id is provided, bind directly to that specific plan (active or draft)
    if ($requestedPlanId > 0) {
        $reqStmt = $pdo->prepare('SELECT * FROM training_plans WHERE plan_id = ? AND member_user_id = ?');
        $reqStmt->execute([$requestedPlanId, $memberId]);
        $plan = $reqStmt->fetch() ?: null;
    }

    // 2. If no plan_id was specified, check for an existing draft for this member in this facility
    if (!$plan) {
        if ($trainerGymId > 0) {
            $stmt = $pdo->prepare('
                SELECT * FROM training_plans 
                WHERE member_user_id = ? 
                  AND (gym_id = ? OR gym_id IS NULL) 
                  AND status = "draft" 
                ORDER BY plan_id DESC LIMIT 1
            ');
            $stmt->execute([$memberId, $trainerGymId]);
        } else {
            $stmt = $pdo->prepare('
                SELECT * FROM training_plans 
                WHERE member_user_id = ? 
                  AND status = "draft" 
                ORDER BY plan_id DESC LIMIT 1
            ');
            $stmt->execute([$memberId]);
        }
        $plan = $stmt->fetch() ?: null;
    }

    // 3. If no draft exists, check for an active plan for this member in this facility (Direct edit)
    if (!$plan) {
        if ($trainerGymId > 0) {
            $activePlanStmt = $pdo->prepare('
                SELECT * FROM training_plans 
                WHERE member_user_id = ? 
                  AND (gym_id = ? OR gym_id IS NULL) 
                  AND status = "active" 
                ORDER BY plan_id DESC LIMIT 1
            ');
            $activePlanStmt->execute([$memberId, $trainerGymId]);
        } else {
            $activePlanStmt = $pdo->prepare('
                SELECT * FROM training_plans 
                WHERE member_user_id = ? 
                  AND status = "active" 
                ORDER BY plan_id DESC LIMIT 1
            ');
            $activePlanStmt->execute([$memberId]);
        }
        $plan = $activePlanStmt->fetch() ?: null;
    }

    $planId = $plan ? (int)$plan['plan_id'] : 0;
    if ($plan) {
        if (!empty($plan['start_date']) && $plan['start_date'] >= $today) { $defaultStart = $plan['start_date']; } else { $defaultStart = $today; }
        if ($hasMembership && !empty($membership['end_date'])) { $defaultEnd = $membership['end_date']; } elseif (!empty($plan['end_date']) && $plan['end_date'] >= $defaultStart) { $defaultEnd = $plan['end_date']; }
    }

    // Lazy initialization helper: on HTTP GET, do NOT create database rows!
    // Only create a draft row when a mutation (POST action) actually occurs.
    $ensurePlanId = function () use (&$planId, &$plan, $pdo, $memberId, $trainerId, $trainerGymId, $profile, $member, $defaultStart, $defaultEnd, $user): int {
        if ($planId > 0) {
            return $planId;
        }
        $goal = $profile['primary_goal'] ?? 'general_health';
        $title = 'Workout Plan for ' . ($member['first_name'] ?? 'Member');
        $stmt = $pdo->prepare('INSERT INTO training_plans (member_user_id, trainer_id, gym_id, title, goal, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, "draft")');
        $stmt->execute([$memberId, $trainerId, $trainerGymId > 0 ? $trainerGymId : null, $title, $goal, $defaultStart, $defaultEnd]);
        $planId = (int) $pdo->lastInsertId();

        $fetchStmt = $pdo->prepare('SELECT * FROM training_plans WHERE plan_id = ?');
        $fetchStmt->execute([$planId]);
        $plan = $fetchStmt->fetch() ?: null;

        if (function_exists('log_plan_change')) {
            log_plan_change('workout', $planId, $trainerGymId > 0 ? $trainerGymId : null, (int)$user['user_id'], 'created', 'Draft workout plan initialized');
        }
        return $planId;
    };

    // Clean up any exercises not part of the current gym or global library
    if ($planId > 0 && $exerciseLibraryGymId > 0) {
        $pdo->prepare(
            'DELETE FROM training_plan_exercises
             WHERE plan_id = ?
               AND exercise_id NOT IN (SELECT exercise_id FROM exercises WHERE gym_id = ? OR gym_id = 0 OR gym_id IS NULL)'
        )->execute([$planId, $exerciseLibraryGymId]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
        
        // 0. Discard Draft
        if ($action === 'discard_draft') {
            if ($plan && ($plan['status'] ?? '') === 'draft') {
                $pdo->prepare('DELETE FROM training_plan_exercises WHERE plan_id = ?')->execute([$planId]);
                $pdo->prepare('DELETE FROM training_plans WHERE plan_id = ?')->execute([$planId]);
                if (function_exists('log_plan_change')) {
                    log_plan_change('workout', $planId, $trainerGymId > 0 ? $trainerGymId : null, (int)$user['user_id'], 'deleted', 'Discarded draft workout plan');
                }
                flash('Draft workout plan discarded.', 'info');
            }
            redirect('training');
        }

        // 1. Auto Generate Entire Plan
        if ($action === 'auto_generate') {
            try {
                $planId = $ensurePlanId();
            $options = [
                'goal' => post('goal') ?: null,
                // Do not accept a staff-supplied override: use the member's
                // recorded workout frequency for every generated plan.
                'days_count' => $prefDays,
                'split_type' => post('split_type') ?: 'auto',
                'experience_level' => post('experience_level') ?: $memberExpKey,
            ];
            
            $options['library_gym_id'] = $exerciseLibraryGymId;
            if (auto_populate_plan_advanced($planId, $memberId, $options)) {
                flash('Plan auto-generated with custom parameters. Review before publishing.', 'success');
            } else {
                flash("This member's gym exercise library is empty. Add exercises before generating a plan.", 'error');
            }
            } catch (Throwable $e) {
                error_log('Auto generate workout error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                flash('Unable to auto-generate plan: ' . $e->getMessage(), 'error');
            }
            header('Location: index.php?page=workout_builder&member_user_id=' . $memberId . ($planId > 0 ? '&plan_id=' . $planId : ''));
            exit;
        }
        
        // 2. Auto Generate Specific Day
        if ($action === 'auto_generate_day') {
            try {
                $planId = $ensurePlanId();
            $dayOfWeek = (int) post('day_of_week');
            if ($dayOfWeek < 1 || $dayOfWeek > 7) {
                flash('Please select a valid day of the week.', 'error');
                redirect('workout_builder&member_user_id=' . $memberId);
            }
            if (!empty($prefWorkoutDays) && !in_array($dayOfWeek, $prefWorkoutDays, true)) {
                $allowedNames = implode(', ', array_map('workout_day_name', $prefWorkoutDays));
                flash("Member's committed training schedule is strictly limited to {$allowedNames}. You cannot schedule workouts on their rest days.", 'error');
                redirect('workout_builder&member_user_id=' . $memberId);
            }
            $dayAlreadyScheduled = (bool) scalar(
                'SELECT 1 FROM training_plan_exercises WHERE plan_id = ? AND day_of_week = ? LIMIT 1',
                [$planId, $dayOfWeek]
            );
            if (!$dayAlreadyScheduled && $getScheduledDaysCount($pdo, $planId) >= $prefDays) {
                flash("This member's plan is limited to {$prefDays} workout day(s) per week.", 'error');
                redirect('workout_builder&member_user_id=' . $memberId);
            }
            $focus = post('focus') ?: 'auto';
            if (auto_populate_day_focused($planId, $memberId, $dayOfWeek, $focus, $exerciseLibraryGymId)) {
                flash(workout_day_name($dayOfWeek) . ' routine generated.', 'success');
            } else {
                flash("This member's gym exercise library has no suitable exercises for that routine.", 'error');
            }
            } catch (Throwable $e) {
                error_log('Auto generate workout error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                flash('Unable to auto-generate plan: ' . $e->getMessage(), 'error');
            }
            header('Location: index.php?page=workout_builder&member_user_id=' . $memberId . ($planId > 0 ? '&plan_id=' . $planId : ''));
            exit;
        }

        // 3. Apply Preset Template
        if ($action === 'apply_template') {
            try {
                $planId = $ensurePlanId();
            $templateKey = post('template_key') ?: '';
            $template = get_workout_templates()[$templateKey] ?? null;
            $templateDays = is_array($template['days'] ?? null) ? count($template['days']) : 0;
            $applied = $templateDays === $prefDays
                ? apply_workout_template($planId, $templateKey, $exerciseLibraryGymId)
                : false;
            if ($applied) {
                flash('Preset template applied successfully!', 'success');
            } else {
                flash("Choose a {$prefDays}-day preset to match this member's workout frequency.", 'error');
            }
            } catch (Throwable $e) {
                error_log('Auto generate workout error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                flash('Unable to auto-generate plan: ' . $e->getMessage(), 'error');
            }
            header('Location: index.php?page=workout_builder&member_user_id=' . $memberId . ($planId > 0 ? '&plan_id=' . $planId : ''));
            exit;
        }

        // 4. Clear Specific Day
        if ($action === 'clear_day') {
            try {
            $dayOfWeek = (int) post('day_of_week');
            $pdo->prepare('DELETE FROM training_plan_exercises WHERE plan_id = ? AND day_of_week = ?')
                ->execute([$planId, $dayOfWeek]);
            flash(workout_day_name($dayOfWeek) . ' exercises cleared.', 'success');
            } catch (Throwable $e) {
                error_log('Auto generate workout error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                flash('Unable to auto-generate plan: ' . $e->getMessage(), 'error');
            }
            header('Location: index.php?page=workout_builder&member_user_id=' . $memberId . ($planId > 0 ? '&plan_id=' . $planId : ''));
            exit;
        }

        // 5. Publish Plan
        if ($action === 'publish') {
            $startDate = post('start_date') ?: date('Y-m-d');
            $endDate = post('end_date') ?: ($defaultEnd ?? date('Y-m-d', strtotime('+4 weeks')));
            $curToday = date('Y-m-d');

            if ($startDate < $curToday) {
                flash('Start date cannot be in the past. Please select today or a future date.', 'error');
                redirect('workout_builder&member_user_id=' . $memberId . ($planId > 0 ? '&plan_id=' . $planId : ''));
            }
            if ($endDate < $startDate) {
                flash('End date cannot be earlier than start date.', 'error');
                redirect('workout_builder&member_user_id=' . $memberId . ($planId > 0 ? '&plan_id=' . $planId : ''));
            }
            if ($hasMembership && !empty($membership['end_date']) && $endDate > $membership['end_date']) {
                flash("End date cannot exceed member's active membership plan expiration date (" . date('M j, Y', strtotime($membership['end_date'])) . ").", 'error');
                redirect('workout_builder&member_user_id=' . $memberId . ($planId > 0 ? '&plan_id=' . $planId : ''));
            }

            
            $scheduledDays = $getScheduledDaysCount($pdo, $planId);
            if ($scheduledDays !== $prefDays) {
                flash("This plan has {$scheduledDays} workout day(s), but the member's target is {$prefDays} per week. Adjust the schedule before publishing.", 'error');
                redirect('workout_builder&member_user_id=' . $memberId);
            }

            // Archive old active plans strictly for this trainer or this trainer's gym facility
            if ($trainerGymId > 0) {
                $pdo->prepare('
                    UPDATE training_plans tp
                    LEFT JOIN trainer_profiles tp_prof ON tp.trainer_id = tp_prof.trainer_id
                    SET tp.status = "archived"
                    WHERE tp.member_user_id = ? 
                      AND tp.status = "active" 
                      AND (tp.trainer_id = ? OR tp.gym_id = ? OR tp_prof.gym_id = ?)
                ')->execute([$memberId, $trainerId, $trainerGymId, $trainerGymId]);
            } else {
                $pdo->prepare('UPDATE training_plans SET status = "archived" WHERE member_user_id = ? AND trainer_id = ? AND status = "active"')
                    ->execute([$memberId, $trainerId]);
            }
            // Set draft to active with the selected dates and gym_id
            $assignedTrainer = (int) (scalar('SELECT trainer_id FROM trainer_assignments WHERE member_user_id = ? AND status = "active" LIMIT 1', [$memberId]) ?? 0);
            $planTrainerId = ($user['role'] === 'trainer') ? $trainerId : ($assignedTrainer ?: $trainerId);
            $pdo->prepare('UPDATE training_plans SET status = "active", trainer_id = COALESCE(trainer_id, ?), gym_id = COALESCE(gym_id, ?), start_date = ?, end_date = ? WHERE plan_id = ?')
                ->execute([$planTrainerId, $trainerGymId > 0 ? $trainerGymId : null, $startDate, $endDate, $planId]);
            
            if (function_exists('log_plan_change')) {
                log_plan_change('workout', $planId, $trainerGymId > 0 ? $trainerGymId : null, (int)$user['user_id'], 'published', "Published workout plan active from {$startDate} to {$endDate}");
            }

            notify_user($memberId, 'system', 'New Workout Plan Published!', 'Your coach has published an updated training routine for you.');
            flash('Workout plan ' . (($plan['status'] ?? '') === 'draft' ? 'published' : 'updated') . ' successfully!', 'success');
            redirect('training');
        }

        // 6. Add Exercise
        if ($action === 'add_exercise') {
            try {
                $planId = $ensurePlanId();
            $dayOfWeek = (int) post('day_of_week');
            if ($dayOfWeek < 1 || $dayOfWeek > 7) {
                flash('Please select a valid day of the week.', 'error');
                redirect('workout_builder&member_user_id=' . $memberId);
            }
            if (!empty($prefWorkoutDays) && !in_array($dayOfWeek, $prefWorkoutDays, true)) {
                $allowedNames = implode(', ', array_map('workout_day_name', $prefWorkoutDays));
                flash("Member's committed training schedule is strictly limited to {$allowedNames}. You cannot schedule workouts on their rest days.", 'error');
                redirect('workout_builder&member_user_id=' . $memberId);
            }
            $dayAlreadyScheduled = (bool) scalar(
                'SELECT 1 FROM training_plan_exercises WHERE plan_id = ? AND day_of_week = ? LIMIT 1',
                [$planId, $dayOfWeek]
            );
            if (!$dayAlreadyScheduled && $getScheduledDaysCount($pdo, $planId) >= $prefDays) {
                flash("This member's plan is limited to {$prefDays} workout day(s) per week.", 'error');
                redirect('workout_builder&member_user_id=' . $memberId);
            }
            $exerciseId = (int) post('exercise_id');
            $sets = max(1, (int) post('sets'));
            $reps = trim((string) post('reps')) ?: '10-12';
            $weightRaw = trim((string) post('target_weight_kg'));
            $targetWeight = ($weightRaw !== '' && is_numeric($weightRaw)) ? (float)$weightRaw : null;
            $rest = max(0, (int) post('rest_seconds'));
            $tempo = trim((string) post('tempo')) ?: null;
            $rpe = trim((string) post('rpe')) ?: null;
            $notes = trim((string) post('notes')) ?: null;
            if ($notes === null && $exerciseId > 0) {
                $defaultDesc = scalar('SELECT description FROM exercises WHERE exercise_id = ?', [$exerciseId]);
                if (!empty($defaultDesc)) {
                    $notes = trim((string)$defaultDesc);
                }
            }

            $maxOrder = $pdo->query("SELECT MAX(sequence_order) FROM training_plan_exercises WHERE plan_id = $planId AND day_of_week = $dayOfWeek")->fetchColumn();
            $order = $maxOrder ? ((int)$maxOrder + 1) : 1;

            $stmt = $pdo->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, target_weight_kg, rest_seconds, notes, tempo, rpe) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$planId, $exerciseId, $dayOfWeek, $order, $sets, $reps, $targetWeight, $rest, $notes, $tempo, $rpe]);
            flash('Exercise added to ' . workout_day_name($dayOfWeek) . '.', 'success');
            } catch (Throwable $e) {
                error_log('Auto generate workout error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                flash('Unable to auto-generate plan: ' . $e->getMessage(), 'error');
            }
            header('Location: index.php?page=workout_builder&member_user_id=' . $memberId . ($planId > 0 ? '&plan_id=' . $planId : ''));
            exit;
        }

        // 7. Edit Exercise
        if ($action === 'edit_exercise') {
            try {
            $planExId = (int) post('plan_exercise_id');
            $sets = max(1, (int) post('sets'));
            $reps = trim((string) post('reps')) ?: '10';
            $weightRaw = trim((string) post('target_weight_kg'));
            $targetWeight = ($weightRaw !== '' && is_numeric($weightRaw)) ? (float)$weightRaw : null;
            $rest = max(0, (int) post('rest_seconds'));
            $tempo = trim((string) post('tempo')) ?: null;
            $rpe = trim((string) post('rpe')) ?: null;
            $notes = trim((string) post('notes')) ?: null;

            $stmt = $pdo->prepare('
                UPDATE training_plan_exercises 
                SET sets = ?, reps = ?, target_weight_kg = ?, rest_seconds = ?, tempo = ?, rpe = ?, notes = ?
                WHERE plan_exercise_id = ? AND plan_id = ?
            ');
            $stmt->execute([$sets, $reps, $targetWeight, $rest, $tempo, $rpe, $notes, $planExId, $planId]);
            flash('Exercise prescription updated.', 'success');
            } catch (Throwable $e) {
                error_log('Auto generate workout error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                flash('Unable to auto-generate plan: ' . $e->getMessage(), 'error');
            }
            header('Location: index.php?page=workout_builder&member_user_id=' . $memberId . ($planId > 0 ? '&plan_id=' . $planId : ''));
            exit;
        }

        // 8. Remove Exercise
        if ($action === 'remove_exercise') {
            try {
            $planExId = (int) post('plan_exercise_id');
            $pdo->prepare('DELETE FROM training_plan_exercises WHERE plan_exercise_id = ? AND plan_id = ?')->execute([$planExId, $planId]);
            flash('Exercise removed.', 'success');
            } catch (Throwable $e) {
                error_log('Auto generate workout error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                flash('Unable to auto-generate plan: ' . $e->getMessage(), 'error');
            }
            header('Location: index.php?page=workout_builder&member_user_id=' . $memberId . ($planId > 0 ? '&plan_id=' . $planId : ''));
            exit;
        }
        
        // 9. API endpoint for drag & drop
        if ($action === 'reorder') {
            header('Content-Type: application/json');
            $orderData = json_decode(file_get_contents('php://input'), true);
            if (isset($orderData['items']) && is_array($orderData['items'])) {
                $targetDay = (int) ($orderData['day_of_week'] ?? 0);
                if ($targetDay < 1 || $targetDay > 7) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Invalid workout day.']);
                    exit;
                }
                if (!empty($prefWorkoutDays) && !in_array($targetDay, $prefWorkoutDays, true)) {
                    $allowedNames = implode(', ', array_map('workout_day_name', $prefWorkoutDays));
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => "Member's committed training schedule is strictly limited to {$allowedNames}. You cannot move exercises to their rest days."]);
                    exit;
                }
                $pdo->beginTransaction();
                try {
                    foreach ($orderData['items'] as $index => $planExId) {
                        $pdo->prepare('UPDATE training_plan_exercises SET sequence_order = ?, day_of_week = ? WHERE plan_exercise_id = ? AND plan_id = ?')
                            ->execute([$index + 1, $targetDay, (int) $planExId, $planId]);
                    }
                    if ($getScheduledDaysCount($pdo, $planId) > $prefDays) {
                        $pdo->rollBack();
                        http_response_code(422);
                        echo json_encode(['success' => false, 'message' => "This member's plan is limited to {$prefDays} workout day(s) per week."]);
                        exit;
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }
            echo json_encode(['success' => true]);
            exit;
        }
    }

    // Fetch exercises for the dropdown - prioritize gym-specific exercises over global library duplicates
    if ($exerciseLibraryGymId > 0) {
        $stmt = $pdo->prepare('
            SELECT exercise_id, name, muscle_group, category, difficulty_level, gym_id, description 
            FROM exercises 
            WHERE (gym_id = ? OR gym_id = 0 OR gym_id IS NULL) 
            ORDER BY (gym_id = ?) DESC, muscle_group, name
        ');
        $stmt->execute([$exerciseLibraryGymId, $exerciseLibraryGymId]);
        $rawExercises = $stmt->fetchAll();
    } else {
        $stmt = $pdo->query('
            SELECT exercise_id, name, muscle_group, category, difficulty_level, gym_id, description 
            FROM exercises 
            WHERE (gym_id = 0 OR gym_id IS NULL) 
            ORDER BY muscle_group, name
        ');
        $rawExercises = $stmt->fetchAll();
    }

    // Deduplicate by normalized exercise name so gym-specific overrides win and duplicates are eliminated
    $seenExerciseNames = [];
    $allExercises = [];
    foreach ($rawExercises as $ex) {
        $normName = strtolower(trim((string)$ex['name']));
        if (isset($seenExerciseNames[$normName])) {
            continue;
        }
        $seenExerciseNames[$normName] = true;
        $allExercises[] = $ex;
    }

    usort($allExercises, function ($a, $b) {
        $grpCmp = strcasecmp((string)$a['muscle_group'], (string)$b['muscle_group']);
        if ($grpCmp !== 0) return $grpCmp;
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });

    $diffMap = [1 => 'Beginner', 2 => 'Intermediate', 3 => 'Expert'];
    $exercisesData = [];
    foreach ($allExercises as $ex) {
        $dLevel = (int)($ex['difficulty_level'] ?? 1);
        $exercisesData[] = [
            'id' => (int)$ex['exercise_id'],
            'name' => $ex['name'],
            'muscle_group' => !empty($ex['muscle_group']) ? ucwords((string)$ex['muscle_group']) : 'General',
            'category' => !empty($ex['category']) ? ucwords((string)$ex['category']) : '',
            'difficulty' => $dLevel,
            'difficulty_label' => $diffMap[$dLevel] ?? 'Beginner',
            'description' => (string)($ex['description'] ?? '')
        ];
    }
    $exercisesJson = json_encode($exercisesData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

    $rawActivity = strtolower((string)($profile['activity_level'] ?? ''));
    $activityLabel = !empty($rawActivity) ? ucwords(str_replace('_', ' ', $rawActivity)) : 'Moderate';
    $fitnessTier = (int)($profile['fitness_tier'] ?? 0);
    if (false) {
        if (in_array($rawActivity, ['sedentary', 'light', 'lightly_active'], true)) {
            $fitnessTier = 1;
        } elseif (in_array($rawActivity, ['very_active', 'extra_active', 'active'], true)) {
            $fitnessTier = 3;
        } else {
            $fitnessTier = 2;
        }
    }
    $tierNames = [1 => 'Beginner', 2 => 'Intermediate', 3 => 'Expert'];
    $memberTierLabel = $tierNames[$expLevel] ?? 'Intermediate';

    // Fetch assigned exercises for this draft
    $stmt = $pdo->prepare('
        SELECT tpe.*, e.name as exercise_name, e.muscle_group, e.category, e.difficulty_level, e.description as default_instructions 
        FROM training_plan_exercises tpe
        JOIN exercises e ON tpe.exercise_id = e.exercise_id AND (e.gym_id = ? OR e.gym_id = 0 OR e.gym_id IS NULL)
        WHERE tpe.plan_id = ?
        ORDER BY tpe.day_of_week, tpe.sequence_order
    ');
    $stmt->execute([$exerciseLibraryGymId, $planId]);
    $planExercises = $stmt->fetchAll();

    // Fetch available templates
    $templates = function_exists('get_available_workout_templates')
        ? get_available_workout_templates()
        : (function_exists('get_workout_templates') ? get_workout_templates() : []);

    $days = [
        1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
        4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'
    ];

    // Compute plan totals
    $totalExercisesCount = count($planExercises);
    $totalSetsCount = array_sum(array_column($planExercises, 'sets'));
    $trainingDaysCount = count(array_unique(array_column($planExercises, 'day_of_week')));

    render_header('Workout Builder', $user);
    ?>
    
    <!-- Load SortableJS for drag and drop -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>

    <link rel="stylesheet" href="<?= h(asset_url('css/pages/workout_builder.css')) ?>">

    <div class="builder-header-wrap">
        <div class="builder-member-meta">
            <div onclick="openMemberInfoModal()" style="cursor: pointer;" title="Click to view member info">
            <?= render_avatar($member, 'large') ?>
        </div>
        <div class="builder-member-title-wrap" style="min-width: 0; flex: 1;">
            <div style="display: flex; align-items: center; gap: 0.45rem; flex-wrap: wrap;">
                <h1 style="margin: 0; font-size: 1.3rem; font-weight: 800; line-height: 1.2;"><?= h($member['first_name'] . ' ' . $member['last_name']) ?></h1>
                <?php if (($plan['status'] ?? '') === 'active'): ?>
                    <span class="builder-badge" style="background: rgba(132, 204, 22, 0.15); color: var(--lime, #84cc16); border: 1px solid var(--lime, #84cc16); font-weight: 700;">Live Active Plan</span>
                <?php else: ?>
                    <span class="builder-badge builder-badge-draft">Draft</span>
                <?php endif; ?>
                <span class="builder-badge builder-badge-stat"><?= $trainingDaysCount ?> Days/Wk</span>
                <span class="builder-badge builder-badge-stat"><?= $totalExercisesCount ?> ex &middot; <?= $totalSetsCount ?> sets</span>
                <button type="button" onclick="openMemberInfoModal()" class="builder-member-info-pill" title="View Member Profile & Fitness Info">
                    <i class="fas fa-info-circle"></i>
                    <span>Member Info</span>
                </button>
            </div>
        </div>
        </div>
        <div class="builder-actions-group">
            <button type="button" onclick="openTemplatesModal()" class="builder-btn builder-btn-secondary" title="Choose from pre-built training splits">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                Presets
            </button>
            <button type="button" onclick="openAdvancedAutoGenerateModal()" class="builder-btn builder-btn-outline-lime" title="Smart AI Auto-Generate with custom parameters">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/></svg>
                Auto-Gen
            </button>
            <form id="publishForm" method="post" style="margin:0;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="publish">
                <button type="button" onclick="promptPublish()" class="builder-btn builder-btn-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Publish
                </button>
            </form>
        </div>
    </div>

    <!-- Hidden form for applying a template -->
    <form id="applyTemplateForm" method="post" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="apply_template">
        <input type="hidden" name="template_key" id="templateKeyInput" value="">
    </form>

    <!-- Hidden form for auto-generating with advanced options -->
    <form id="advancedAutoGenForm" method="post" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="auto_generate">
        <input type="hidden" name="goal" id="autoGenGoal" value="">
        <input type="hidden" name="days_count" id="autoGenDays" value="3">
        <input type="hidden" name="split_type" id="autoGenSplit" value="auto">
        <input type="hidden" name="experience_level" id="autoGenExp" value="intermediate">
    </form>

    <!-- Hidden form for auto-generating a specific day -->
    <form id="autoGenDayForm" method="post" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="auto_generate_day">
        <input type="hidden" name="day_of_week" id="autoGenDayInput" value="">
        <input type="hidden" name="focus" id="autoGenDayFocus" value="auto">
    </form>

    <!-- Hidden form for clearing a specific day -->
    <form id="clearDayForm" method="post" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="clear_day">
        <input type="hidden" name="day_of_week" id="clearDayInput" value="">
    </form>

    <!-- View Toolbar: Day Filter Tabs & Density Switcher -->
    <div class="builder-view-toolbar">
        <div class="builder-day-tabs" id="builderDayTabs">
            <button type="button" class="builder-tab-btn is-active" data-day="all" onclick="selectDayTab('all')">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span>All Days</span>
                <span class="builder-tab-count"><?= $totalExercisesCount ?></span>
            </button>
            <?php foreach ($days as $dNum => $dName): 
                $dayList = array_filter($planExercises, fn($x) => (int)$x['day_of_week'] === $dNum);
                $dCnt = count($dayList);
                $isCommittedDay = empty($prefWorkoutDays) || in_array($dNum, $prefWorkoutDays, true);
            ?>
                <button type="button" class="builder-tab-btn" data-day="<?= $dNum ?>" onclick="selectDayTab('<?= $dNum ?>')" style="<?= !$isCommittedDay ? 'opacity: 0.6;' : '' ?>" title="<?= !$isCommittedDay ? 'Rest Day (Member Unavailable)' : 'Training Day' ?>">
                    <span><?= substr($dName, 0, 3) ?><?= !$isCommittedDay ? ' <small style="font-size:9px; opacity:0.7;">(Rest)</small>' : '' ?></span>
                    <span class="builder-tab-count" style="<?= $dCnt === 0 ? 'opacity: 0.55;' : '' ?>"><?= $dCnt ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <button type="button" id="densityToggleBtn" class="builder-density-btn" onclick="toggleDensity()" title="Toggle card density (Compact vs Detailed)">
                <svg id="densityIcon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="3" y1="6" x2="21" y2="6"/>
                    <line x1="3" y1="12" x2="21" y2="12"/>
                    <line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
                <span id="densityLabel">Compact View</span>
            </button>
        </div>
    </div>

    <!-- 7-Day Workout Grid -->
    <div class="builder-days-grid" id="builderDaysGrid">
        <?php foreach ($days as $dayNum => $dayName): 
            $dayExs = array_values(array_filter($planExercises, fn($ex) => (int)$ex['day_of_week'] === $dayNum));
            $exCount = count($dayExs);
            $setCount = array_sum(array_column($dayExs, 'sets'));
            $isCommittedDay = empty($prefWorkoutDays) || in_array($dayNum, $prefWorkoutDays, true);
            
            // Calculate total day duration
            $totalDurationSecs = 0;
            $muscles = [];
            foreach ($dayExs as $de) {
                $totalDurationSecs += ((int)$de['sets']) * (45 + (int)$de['rest_seconds']);
                if (!empty($de['muscle_group'])) {
                    $muscles[] = ucwords((string)$de['muscle_group']);
                }
            }
            $muscles = array_unique($muscles);
            $estMinutes = $exCount > 0 ? max(15, (int)round($totalDurationSecs / 60)) : 0;
            $focusBadge = $isCommittedDay ? 'Rest Day' : 'Rest Day (Member Unavailable)';
            if ($exCount > 0) {
                $focusBadge = !empty($muscles) ? implode(' • ', array_slice($muscles, 0, 2)) : 'Training Day';
            }
        ?>
            <div class="builder-day-card <?= !$isCommittedDay ? 'builder-day-card-rest' : '' ?>" data-day="<?= $dayNum ?>" style="<?= !$isCommittedDay ? 'border-style: dashed; opacity: 0.85;' : '' ?>">
                <div class="builder-day-header">
                    <div class="builder-day-title-row">
                        <h3 class="builder-day-name">
                            <span><?= $dayName ?></span>
                            <?php if (!$isCommittedDay): ?>
                                <span class="builder-badge" style="background: rgba(148, 163, 184, 0.15); color: var(--muted); font-size: 10px; font-weight: 700; text-transform: uppercase;">Member Rest</span>
                            <?php endif; ?>
                        </h3>
                        <div class="builder-day-actions">
                            <?php if ($isCommittedDay): ?>
                                <button type="button" onclick="openAddExerciseModal(<?= $dayNum ?>, '<?= $dayName ?>')" class="builder-micro-btn" title="Add exercise to <?= $dayName ?>">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                    Add
                                </button>
                                <button type="button" onclick="openDayAutoGenModal(<?= $dayNum ?>, '<?= $dayName ?>')" class="builder-micro-btn" title="Auto-generate <?= $dayName ?> with target focus">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/></svg>
                                    Auto
                                </button>
                                <?php if ($exCount > 0): ?>
                                    <button type="button" onclick="confirmClearDay(<?= $dayNum ?>, '<?= $dayName ?>')" class="builder-micro-btn builder-micro-btn-clear" title="Clear all exercises on <?= $dayName ?>">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    </button>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="font-size: 11px; color: var(--muted); font-weight: 500; font-style: italic;">Committed Rest Day</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="builder-day-meta-row">
                        <span style="color: <?= $exCount > 0 ? 'var(--lime)' : 'var(--muted)' ?>; font-weight: 600;"><?= h($focusBadge) ?></span>
                        <?php if ($exCount > 0): ?>
                            <span><?= $exCount ?> ex • <?= $setCount ?> sets • ~<?= $estMinutes ?>m</span>
                        <?php else: ?>
                            <span><?= $isCommittedDay ? 'No workouts scheduled' : 'Rest day (Adhered)' ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="builder-sortable-list <?= $isCommittedDay ? 'sortable-list' : 'locked-rest-list' ?>" data-day="<?= $dayNum ?>">
                    <?php if ($exCount === 0): ?>
                        <div class="builder-empty-day">
                            <?php if ($isCommittedDay): ?>
                                Rest day. Click <strong>+ Add</strong> or <strong>Auto</strong> to schedule exercises.
                            <?php else: ?>
                                Scheduled rest day according to member preference. Exercises cannot be scheduled on this day.
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($dayExs as $ex): 
                            $jsonEx = htmlspecialchars(json_encode($ex), ENT_QUOTES, 'UTF-8');
                        ?>
                            <div class="builder-ex-item" data-id="<?= $ex['plan_exercise_id'] ?>">
                                <div class="builder-ex-main-row">
                                    <div style="flex: 1; min-width: 0;">
                                        <div class="builder-ex-name"><?= h($ex['exercise_name']) ?></div>
                                        <div class="builder-ex-pills">
                                            <span class="builder-pill builder-pill-muscle"><?= h($ex['muscle_group']) ?></span>
                                            <?php 
                                            $cardDiff = (int)($ex['difficulty_level'] ?? 1);
                                            $cardDiffLabel = $diffMap[$cardDiff] ?? 'Beginner';
                                            $cardDiffColor = $cardDiff === 3 ? 'var(--danger, #ef4444)' : ($cardDiff === 2 ? 'var(--orange, #eab308)' : 'var(--success, #22c55e)');
                                            ?>
                                            <span class="builder-pill" style="color:<?= $cardDiffColor ?>; border-color:color-mix(in srgb, <?= $cardDiffColor ?> 30%, transparent);" title="Difficulty Level"><?= h($cardDiffLabel) ?></span>
                                            <span class="builder-pill builder-pill-lime"><strong><?= (int)$ex['sets'] ?></strong> sets &times; <strong><?= h($ex['reps']) ?></strong></span>
                                            <?php if ($ex['target_weight_kg'] !== null && $ex['target_weight_kg'] > 0): ?>
                                                <span class="builder-pill builder-pill-orange" title="Target Weight">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                                                    <?= (float)$ex['target_weight_kg'] ?> kg
                                                </span>
                                            <?php endif; ?>
                                            <span class="builder-pill" title="Rest Period">
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                                <?= (int)$ex['rest_seconds'] ?>s
                                            </span>
                                            <?php if (!empty($ex['tempo'])): ?>
                                                <span class="builder-pill" style="color:var(--lime); border-color: color-mix(in srgb, var(--lime) 30%, transparent);" title="Tempo">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                                    <?= h($ex['tempo']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($ex['rpe'])): ?>
                                                <span class="builder-pill" style="color:var(--orange, #f59e0b); border-color: color-mix(in srgb, var(--orange, #f59e0b) 30%, transparent);" title="RPE Intensity">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
                                                    <?= h($ex['rpe']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($ex['notes'])): ?>
                                                <button type="button" class="builder-note-pill" onclick="toggleNoteOpen(this)" title="Toggle Coach Notes">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                                    <span>Note</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($ex['notes'])): ?>
                                            <div class="builder-ex-notes">
                                                "<?= h($ex['notes']) ?>"
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="builder-ex-actions">
                                        <button type="button" class="builder-icon-btn edit-btn" onclick='openEditExerciseModal(<?= $jsonEx ?>)' title="Edit Prescription">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                        </button>
                                        <button type="button" class="builder-icon-btn del-btn" onclick="confirmRemoveExercise(<?= (int)$ex['plan_exercise_id'] ?>, <?= htmlspecialchars(json_encode($ex['exercise_name'])) ?>)" title="Remove Exercise">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Hidden exercise options for select dropdowns -->
    <div id="exerciseOptions" style="display:none;">
        <?php
        $currentGroup = '';
        foreach ($allExercises as $ex) {
            $group = !empty($ex['muscle_group']) ? ucwords((string)$ex['muscle_group']) : 'General';
            if ($currentGroup !== $group) {
                if ($currentGroup !== '') echo '</optgroup>';
                $currentGroup = $group;
                echo '<optgroup label="' . h($currentGroup) . '">';
            }
            echo '<option value="' . (int)$ex['exercise_id'] . '">' . h($ex['name']) . '</option>';
        }
        if ($currentGroup !== '') echo '</optgroup>';
        ?>
    </div>
    

    <!-- Hidden template choices for presets modal -->
    <!-- Hidden payload for Member Profile Info Modal -->
    <div id="memberInfoPayload" style="display:none;">
        <div class="wb-plain-info-wrap">
            <div class="wb-plain-member-header">
                <div style="flex-shrink:0;">
                    <?= render_avatar($member, 'medium') ?>
                </div>
                <div style="min-width:0; flex:1;">
                    <div class="wb-plain-member-name"><?= h($member['first_name'] . ' ' . $member['last_name']) ?></div>
                    <?php if (!empty($member['email'])): ?>
                        <div class="wb-plain-member-sub"><?= h($member['email']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="wb-plain-info-list">
                <div class="wb-plain-info-row">
                    <span class="wb-plain-info-label">Goal:</span>
                    <span class="wb-plain-info-val"><?= h(ucwords(str_replace('_', ' ', (string)($profile['primary_goal'] ?? 'General Health')))) ?></span>
                </div>

                <div class="wb-plain-info-row">
                    <span class="wb-plain-info-label">Weight:</span>
                    <span class="wb-plain-info-val">
                        <?= h((string)($profile['weight_kg'] ?? 'N/A')) ?> kg
                        <?php if (!empty($profile['target_weight_kg'])): ?>
                            <span class="wb-plain-info-sub">&rarr; Target: <?= h((string)$profile['target_weight_kg']) ?> kg</span>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="wb-plain-info-row">
                    <span class="wb-plain-info-label">Activity:</span>
                    <span class="wb-plain-info-val"><?= h($activityLabel) ?> (<?= h($memberTierLabel) ?>)</span>
                </div>

                <div class="wb-plain-info-row">
                    <span class="wb-plain-info-label">Target:</span>
                    <span class="wb-plain-info-val"><?= (int)($profile['weekly_workout_target'] ?? 3) ?>d/wk &bull; ~<?= (int)($profile['preferred_duration_mins'] ?? 45) ?>m</span>
                </div>

                <div class="wb-plain-info-row">
                    <span class="wb-plain-info-label">Committed Days:</span>
                    <span class="wb-plain-info-val">
                        <?php if (!empty($prefWorkoutDays)): ?>
                            <?= h(implode(', ', array_map(fn($d) => substr(workout_day_name($d), 0, 3), $prefWorkoutDays))) ?> (Enforced)
                        <?php else: ?>
                            Flexible
                        <?php endif; ?>
                    </span>
                </div>

                <div class="wb-plain-info-row">
                    <span class="wb-plain-info-label">Membership:</span>
                    <span class="wb-plain-info-val"><?= $hasMembership ? 'Active' : 'Trial' ?></span>
                </div>

                <?php if (!empty($profile['height_cm'])): ?>
                    <div class="wb-plain-info-row">
                        <span class="wb-plain-info-label">Height:</span>
                        <span class="wb-plain-info-val"><?= (float)$profile['height_cm'] ?> cm</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="templateOptionsPayload" style="display:none;">
        <div style="max-height: 480px; overflow-y: auto; text-align: left; padding-right: 5px;">
            <?php foreach ($templates as $key => $tmpl): 
                $tmplTitle = $tmpl['title'] ?? $tmpl['name'] ?? ucfirst(str_replace('_', ' ', (string)$key));
                $tmplDays = (int)($tmpl['days_per_week'] ?? (isset($tmpl['days']) && is_array($tmpl['days']) ? count($tmpl['days']) : 3));
                if ($tmplDays !== $prefDays) continue;
                $tmplDesc = $tmpl['description'] ?? '';
                $tmplSplit = $tmpl['split_name'] ?? $tmpl['name'] ?? $tmplTitle;
                $tmplGoal = $tmpl['primary_goal'] ?? $tmpl['goal'] ?? 'general_health';
            ?>
                <div class="template-card-choice" onclick="selectTemplate('<?= $key ?>', '<?= addslashes(h($tmplTitle)) ?>')">
                    <div class="template-card-heading">
                        <strong class="template-card-title"><?= h($tmplTitle) ?></strong>
                        <span class="builder-badge builder-badge-stat template-card-days"><?= $tmplDays ?> Days/Wk</span>
                    </div>
                    <div style="font-size: 12px; color: var(--muted); margin-bottom: 6px;">
                        <?= h($tmplDesc) ?>
                    </div>
                    <div style="font-size: 11px; color: var(--ink); opacity: 0.8; font-weight: 500;">
                        Split: <?= h($tmplSplit) ?> &bull; Goal: <?= h(ucwords(str_replace('_', ' ', $tmplGoal))) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Workout Builder Configuration & Script -->
    <script>
    window.WORKOUT_BUILDER_CONFIG = {
        gymExercisesData: <?= $exercisesJson ?>,
        csrfToken: <?= json_encode(csrf_token()) ?>,
        memberId: <?= (int)$memberId ?>,
        primaryGoal: <?= json_encode($profile['primary_goal'] ?? '') ?>,
        prefDays: <?= (int)$prefDays ?>,
        memberName: <?= json_encode($member['first_name'] ?? 'Member') ?>,
        hasMembership: <?= json_encode((bool)$hasMembership) ?>,
        today: <?= json_encode($today) ?>,
        membershipEndDate: <?= json_encode($membershipEndDate) ?>,
        membershipPlanName: <?= json_encode($membershipPlanName) ?>,
        defaultStart: <?= json_encode($defaultStart) ?>,
        defaultEnd: <?= json_encode($defaultEnd) ?>,
        memberActivityLevel: <?= json_encode($activityLabel) ?>,
        memberFitnessTier: <?= (int)$expLevel ?>,
        memberExpLevel: <?= json_encode($memberExpKey) ?>,
        memberExpLabel: <?= json_encode($memberTierLabel) ?>,
        memberTierLabel: <?= json_encode($memberTierLabel) ?>
    };
    </script>
    <script>
    function confirmRemoveExercise(planExId, exerciseName) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Remove Exercise?',
                html: `<p style="margin:0 0 6px 0; color:var(--ink);">Are you sure you want to remove <strong>${exerciseName}</strong> from this workout day?</p>`,
                icon: 'warning',
                iconColor: '#ef4444',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6c757d',
                background: 'var(--panel, var(--bg))',
                color: 'var(--ink)',
                confirmButtonText: 'Yes, Remove',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    if (typeof Swal.showLoading === 'function') Swal.showLoading();
                    const form = document.createElement('form');
                    form.method = 'POST';
                    const csrf = window.WORKOUT_BUILDER_CONFIG ? window.WORKOUT_BUILDER_CONFIG.csrfToken : '';
                    form.innerHTML = `
                        <input type="hidden" name="csrf_token" value="${csrf}">
                        <input type="hidden" name="action" value="remove_exercise">
                        <input type="hidden" name="plan_exercise_id" value="${planExId}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        } else if (confirm('Remove ' + exerciseName + '?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            const csrf = window.WORKOUT_BUILDER_CONFIG ? window.WORKOUT_BUILDER_CONFIG.csrfToken : '';
            form.innerHTML = `
                <input type="hidden" name="csrf_token" value="${csrf}">
                <input type="hidden" name="action" value="remove_exercise">
                <input type="hidden" name="plan_exercise_id" value="${planExId}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    window.confirmRemoveExercise = confirmRemoveExercise;
    function openMemberInfoModal() {
        const payloadEl = document.getElementById('memberInfoPayload');
        if (!payloadEl) return;
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: '<span style="font-size:1.15rem; font-weight:700;">Member Info</span>',
                html: payloadEl.innerHTML,
                showCloseButton: true,
                showConfirmButton: true,
                confirmButtonText: 'Close',
                confirmButtonColor: 'var(--lime)',
                background: 'var(--panel, var(--bg))',
                color: 'var(--ink)',
                width: 480,
                padding: '1.25rem'
            });
        }
    }
    window.openMemberInfoModal = openMemberInfoModal;

    function confirmDiscardDraft() {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Discard Draft Workout?',
                text: 'Are you sure you want to discard this draft? Any exercises added to this draft will be removed.',
                icon: 'warning',
                iconColor: '#ef4444',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6c757d',
                background: 'var(--panel, var(--bg))',
                color: 'var(--ink)',
                confirmButtonText: 'Yes, Discard Draft',
                cancelButtonText: 'Keep Editing'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.showLoading();
                    document.getElementById('discardDraftForm').submit();
                }
            });
        } else if (confirm('Are you sure you want to discard this draft?')) {
            document.getElementById('discardDraftForm').submit();
        }
    }
    window.confirmDiscardDraft = confirmDiscardDraft;

    </script>
    <script src="<?= h(asset_url('js/pages/workout_builder.js')) ?>"></script>
    <?php
    render_footer();
}
