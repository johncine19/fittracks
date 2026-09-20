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
    
    $profileStmt = $pdo->prepare('SELECT primary_goal, weight_kg, activity_level, weekly_workout_target, preferred_duration_mins FROM member_profiles WHERE user_id = ?');
    $profileStmt->execute([$memberId]);
    $profile = $profileStmt->fetch() ?: [];
    $prefDays = max(2, min(6, (int)($profile['weekly_workout_target'] ?: 3)));
    
    // Fetch active membership to sync dates
    $membershipStmt = $pdo->prepare('SELECT start_date, end_date FROM memberships WHERE user_id = ? AND status = "active" ORDER BY end_date DESC LIMIT 1');
    $membershipStmt->execute([$memberId]);
    $membership = $membershipStmt->fetch();
    $hasMembership = (bool) $membership;
    $defaultStart = $membership ? $membership['start_date'] : date('Y-m-d');
    $defaultEnd = $membership ? $membership['end_date'] : date('Y-m-d', strtotime('+28 days'));
    
    // Fetch trainer ID & gym_id
    $trainerId = ensure_coach_profile((int)$user['user_id']);
    $trainerProfile = $pdo->query('SELECT gym_id FROM trainer_profiles WHERE trainer_id = ' . $trainerId)->fetch();
    
    if ($user['role'] === 'gym_owner') {
        $trainerGymId = $user['gym_id'] ?? scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]) ?? scalar('SELECT gym_id FROM gyms ORDER BY gym_id ASC LIMIT 1');
    } else {
        $trainerGymId = (int) ($trainerProfile['gym_id'] ?? 0);
    }
    
    // Check if there is an active draft plan for this member by this trainer
    $stmt = $pdo->prepare('SELECT * FROM training_plans WHERE member_user_id = ? AND trainer_id = ? AND status = "draft" LIMIT 1');
    $stmt->execute([$memberId, $trainerId]);
    $draft = $stmt->fetch();
    
    if (!$draft) {
        // Create an empty draft
        $goal = $profile['primary_goal'] ?? 'general_health';
        $title = 'Workout Plan for ' . $member['first_name'];
        $stmt = $pdo->prepare('INSERT INTO training_plans (member_user_id, trainer_id, title, goal, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, "draft")');
        $stmt->execute([$memberId, $trainerId, $title, $goal, $defaultStart, $defaultEnd]);
        $planId = (int) $pdo->lastInsertId();
        
        // Copy exercises from the most recent active plan (if one exists)
        $activePlan = $pdo->prepare('SELECT plan_id FROM training_plans WHERE member_user_id = ? AND trainer_id = ? AND status = "active" ORDER BY plan_id DESC LIMIT 1');
        $activePlan->execute([$memberId, $trainerId]);
        $lastActivePlanId = $activePlan->fetchColumn();
        
        if ($lastActivePlanId) {
            $pdo->prepare('
                INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, target_weight_kg, rest_seconds, notes, tempo, rpe)
                SELECT ?, exercise_id, day_of_week, sequence_order, sets, reps, target_weight_kg, rest_seconds, notes, tempo, rpe
                FROM training_plan_exercises
                WHERE plan_id = ?
            ')->execute([$planId, $lastActivePlanId]);
        }
    } else {
        $planId = (int) $draft['plan_id'];
        if (!empty($draft['start_date'])) $defaultStart = $draft['start_date'];
        if (!empty($draft['end_date'])) $defaultEnd = $draft['end_date'];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
        
        // 1. Auto Generate Entire Plan
        if ($action === 'auto_generate') {
            $options = [
                'goal' => post('goal') ?: null,
                'days_count' => (int) post('days_count'),
                'split_type' => post('split_type') ?: 'auto',
                'experience_level' => post('experience_level') ?: 'intermediate',
            ];
            
            auto_populate_plan_advanced($planId, $memberId, $options);
            flash('Plan auto-generated with custom parameters. Review before publishing.', 'success');
            header('Location: index.php?page=workout_builder&member_user_id=' . $memberId);
            exit;
        }
        
        // 2. Auto Generate Specific Day
        if ($action === 'auto_generate_day') {
            $dayOfWeek = (int) post('day_of_week');
            $focus = post('focus') ?: 'auto';
            auto_populate_day_focused($planId, $memberId, $dayOfWeek, $focus);
            flash(workout_day_name($dayOfWeek) . ' routine generated.', 'success');
            header('Location: index.php?page=workout_builder&member_user_id=' . $memberId);
            exit;
        }

        // 3. Apply Preset Template
        if ($action === 'apply_template') {
            $templateKey = post('template_key') ?: '';
            $applied = apply_workout_template($planId, $templateKey, (int)$trainerGymId);
            if ($applied) {
                flash('Preset template applied successfully!', 'success');
            } else {
                flash('Unable to apply template.', 'error');
            }
            header('Location: index.php?page=workout_builder&member_user_id=' . $memberId);
            exit;
        }

        // 4. Clear Specific Day
        if ($action === 'clear_day') {
            $dayOfWeek = (int) post('day_of_week');
            $pdo->prepare('DELETE FROM training_plan_exercises WHERE plan_id = ? AND day_of_week = ?')
                ->execute([$planId, $dayOfWeek]);
            flash(workout_day_name($dayOfWeek) . ' exercises cleared.', 'success');
            header('Location: index.php?page=workout_builder&member_user_id=' . $memberId);
            exit;
        }

        // 5. Publish Plan
        if ($action === 'publish') {
            $startDate = post('start_date') ?: date('Y-m-d');
            $endDate = post('end_date') ?: date('Y-m-d', strtotime('+4 weeks'));
            
            // Archive old active plans
            $pdo->prepare('UPDATE training_plans SET status = "archived" WHERE member_user_id = ? AND status = "active"')->execute([$memberId]);
            // Set draft to active with the selected dates
            $pdo->prepare('UPDATE training_plans SET status = "active", start_date = ?, end_date = ? WHERE plan_id = ?')->execute([$startDate, $endDate, $planId]);
            
            notify_user($memberId, 'system', 'New Workout Plan Published!', 'Your coach has published an updated training routine for you.');
            flash('Workout plan published successfully!', 'success');
            redirect('training');
        }

        // 6. Add Exercise
        if ($action === 'add_exercise') {
            $dayOfWeek = (int) post('day_of_week');
            $exerciseId = (int) post('exercise_id');
            $sets = max(1, (int) post('sets'));
            $reps = trim((string) post('reps')) ?: '10-12';
            $weightRaw = trim((string) post('target_weight_kg'));
            $targetWeight = ($weightRaw !== '' && is_numeric($weightRaw)) ? (float)$weightRaw : null;
            $rest = max(0, (int) post('rest_seconds'));
            $tempo = trim((string) post('tempo')) ?: null;
            $rpe = trim((string) post('rpe')) ?: null;
            $notes = trim((string) post('notes')) ?: null;

            $maxOrder = $pdo->query("SELECT MAX(sequence_order) FROM training_plan_exercises WHERE plan_id = $planId AND day_of_week = $dayOfWeek")->fetchColumn();
            $order = $maxOrder ? ((int)$maxOrder + 1) : 1;

            $stmt = $pdo->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, target_weight_kg, rest_seconds, notes, tempo, rpe) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$planId, $exerciseId, $dayOfWeek, $order, $sets, $reps, $targetWeight, $rest, $notes, $tempo, $rpe]);
            flash('Exercise added to ' . workout_day_name($dayOfWeek) . '.', 'success');
            header('Location: index.php?page=workout_builder&member_user_id=' . $memberId);
            exit;
        }

        // 7. Edit Exercise
        if ($action === 'edit_exercise') {
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
            header('Location: index.php?page=workout_builder&member_user_id=' . $memberId);
            exit;
        }

        // 8. Remove Exercise
        if ($action === 'remove_exercise') {
            $planExId = (int) post('plan_exercise_id');
            $pdo->prepare('DELETE FROM training_plan_exercises WHERE plan_exercise_id = ? AND plan_id = ?')->execute([$planExId, $planId]);
            flash('Exercise removed.', 'success');
            header('Location: index.php?page=workout_builder&member_user_id=' . $memberId);
            exit;
        }
        
        // 9. API endpoint for drag & drop
        if ($action === 'reorder') {
            header('Content-Type: application/json');
            $orderData = json_decode(file_get_contents('php://input'), true);
            if (isset($orderData['items']) && is_array($orderData['items'])) {
                $targetDay = (int) ($orderData['day_of_week'] ?? 0);
                foreach ($orderData['items'] as $index => $planExId) {
                    if ($targetDay > 0) {
                        $pdo->prepare('UPDATE training_plan_exercises SET sequence_order = ?, day_of_week = ? WHERE plan_exercise_id = ? AND plan_id = ?')
                            ->execute([$index + 1, $targetDay, (int) $planExId, $planId]);
                    } else {
                        $pdo->prepare('UPDATE training_plan_exercises SET sequence_order = ? WHERE plan_exercise_id = ? AND plan_id = ?')
                            ->execute([$index + 1, (int) $planExId, $planId]);
                    }
                }
            }
            echo json_encode(['success' => true]);
            exit;
        }
    }

    // Fetch exercises for the dropdown
    $stmt = $pdo->prepare('SELECT exercise_id, name, muscle_group, category, difficulty_level FROM exercises WHERE (gym_id = ? OR gym_id = 0) ORDER BY muscle_group, name');
    $stmt->execute([$trainerGymId]);
    $allExercises = $stmt->fetchAll();

    // Fetch assigned exercises for this draft
    $stmt = $pdo->prepare('
        SELECT tpe.*, e.name as exercise_name, e.muscle_group, e.category, e.difficulty_level 
        FROM training_plan_exercises tpe
        JOIN exercises e ON tpe.exercise_id = e.exercise_id
        WHERE tpe.plan_id = ?
        ORDER BY tpe.day_of_week, tpe.sequence_order
    ');
    $stmt->execute([$planId]);
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

    <style>
        .builder-header-wrap {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.25rem;
            margin-bottom: 1.5rem;
            padding: 1.25rem;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 14px;
        }
        .builder-member-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .builder-actions-group {
            display: flex;
            gap: 0.6rem;
            flex-wrap: wrap;
            align-items: center;
        }
        .builder-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.55rem 0.95rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            border: 1px solid transparent;
        }
        .builder-btn-primary {
            background: var(--lime);
            color: var(--lime-btn-text, #090b10);
            border-color: var(--lime);
        }
        .builder-btn-primary:hover {
            filter: brightness(1.1);
            box-shadow: 0 0 15px rgba(132, 204, 22, 0.35);
        }
        .builder-btn-secondary {
            background: var(--panel-soft);
            color: var(--ink);
            border-color: var(--line);
        }
        .builder-btn-secondary:hover {
            border-color: var(--lime);
            color: var(--lime);
        }
        .builder-btn-outline-lime {
            background: transparent;
            color: var(--lime);
            border-color: var(--lime);
        }
        .builder-btn-outline-lime:hover {
            background: color-mix(in srgb, var(--lime) 15%, transparent);
        }
        .builder-badge {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            padding: 0.2rem 0.6rem;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }
        .builder-badge-draft {
            background: color-mix(in srgb, var(--warning, #f59e0b) 18%, transparent);
            color: var(--warning, #f59e0b);
            border: 1px solid color-mix(in srgb, var(--warning, #f59e0b) 40%, transparent);
        }
        .builder-badge-stat {
            background: var(--panel-soft);
            color: var(--ink);
            border: 1px solid var(--line);
        }
        .builder-view-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.75rem;
            margin-top: 1.25rem;
            margin-bottom: 0.75rem;
            flex-wrap: wrap;
        }
        .builder-day-tabs {
            display: flex;
            gap: 0.35rem;
            overflow-x: auto;
            padding: 0.2rem 0.2rem 0.3rem 0.2rem;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
        }
        .builder-day-tabs::-webkit-scrollbar {
            display: none;
        }
        .builder-tab-btn {
            background: var(--panel);
            border: 1px solid var(--line);
            color: var(--muted);
            padding: 0.4rem 0.75rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            white-space: nowrap;
        }
        .builder-tab-btn:hover {
            color: var(--ink);
            border-color: color-mix(in srgb, var(--lime) 50%, var(--line));
        }
        .builder-tab-btn.is-active {
            background: color-mix(in srgb, var(--lime) 15%, var(--panel));
            color: var(--lime);
            border-color: var(--lime);
            box-shadow: 0 2px 8px rgba(132, 204, 22, 0.15);
        }
        .builder-tab-count {
            font-size: 0.7rem;
            padding: 0.1rem 0.4rem;
            border-radius: 10px;
            background: var(--panel-soft);
            color: var(--muted);
            font-weight: 700;
        }
        .builder-tab-btn.is-active .builder-tab-count {
            background: var(--lime);
            color: #0b141a;
        }
        .builder-density-btn {
            background: var(--panel);
            border: 1px solid var(--line);
            color: var(--ink);
            padding: 0.4rem 0.75rem;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
        }
        .builder-density-btn:hover {
            border-color: var(--lime);
            color: var(--lime);
        }
        .builder-days-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 310px), 1fr));
            gap: 1.15rem;
            margin-top: 0.5rem;
            align-items: start;
        }
        .builder-days-grid.is-single-day {
            grid-template-columns: minmax(320px, 780px);
            justify-content: center;
        }
        .builder-days-grid.is-single-day .builder-sortable-list {
            max-height: none;
            overflow-y: visible;
        }
        .builder-day-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .builder-day-card:hover {
            border-color: color-mix(in srgb, var(--lime) 40%, var(--line));
        }
        .builder-day-header {
            position: sticky;
            top: 0;
            z-index: 5;
            padding: 0.85rem 1rem;
            border-bottom: 1px solid var(--line);
            background: color-mix(in srgb, var(--panel) 92%, var(--bg));
            backdrop-filter: blur(8px);
        }
        .builder-day-title-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.3rem;
        }
        .builder-day-name {
            margin: 0;
            font-size: 1.02rem;
            font-weight: 700;
            color: var(--lime);
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .builder-day-meta-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.74rem;
            color: var(--muted);
        }
        .builder-day-actions {
            display: flex;
            gap: 0.35rem;
        }
        .builder-micro-btn {
            padding: 0.2rem 0.5rem;
            font-size: 0.72rem;
            font-weight: 600;
            border-radius: 5px;
            background: transparent;
            cursor: pointer;
            transition: all 0.15s ease;
            border: 1px solid var(--line);
            color: var(--ink);
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        .builder-micro-btn:hover {
            border-color: var(--lime);
            color: var(--lime);
        }
        .builder-micro-btn-clear {
            border-color: rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }
        .builder-micro-btn-clear:hover {
            background: rgba(239, 68, 68, 0.12);
            border-color: #ef4444;
        }
        .builder-sortable-list {
            padding: 0.75rem;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.55rem;
            min-height: 80px;
            max-height: calc(100vh - 280px);
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--line) transparent;
        }
        .builder-sortable-list::-webkit-scrollbar {
            width: 5px;
        }
        .builder-sortable-list::-webkit-scrollbar-thumb {
            background: var(--line);
            border-radius: 4px;
        }
        .builder-sortable-list::-webkit-scrollbar-track {
            background: transparent;
        }
        .builder-ex-item {
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 0.7rem 0.8rem;
            cursor: grab;
            transition: all 0.2s ease;
            position: relative;
        }
        .builder-ex-item:hover {
            border-color: color-mix(in srgb, var(--lime) 50%, var(--line));
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .builder-ex-item:active {
            cursor: grabbing;
        }
        .builder-ex-main-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.5rem;
        }
        .builder-ex-name {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--ink);
            line-height: 1.3;
        }
        .builder-ex-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            margin-top: 0.4rem;
        }
        .builder-pill {
            font-size: 0.7rem;
            font-weight: 600;
            padding: 0.15rem 0.45rem;
            border-radius: 4px;
            background: var(--panel-soft);
            color: var(--muted);
            border: 1px solid var(--line);
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        .builder-pill svg, .builder-note-pill svg {
            flex-shrink: 0;
        }
        .builder-pill-lime {
            background: color-mix(in srgb, var(--lime) 12%, transparent);
            color: var(--lime);
            border-color: color-mix(in srgb, var(--lime) 25%, transparent);
        }
        .builder-pill-orange {
            background: color-mix(in srgb, var(--orange, #f59e0b) 12%, transparent);
            color: var(--orange, #f59e0b);
            border-color: color-mix(in srgb, var(--orange, #f59e0b) 25%, transparent);
        }
        .builder-pill-muscle {
            text-transform: capitalize;
        }
        .builder-note-pill {
            font-size: 0.68rem;
            font-weight: 600;
            padding: 0.12rem 0.4rem;
            border-radius: 4px;
            background: transparent;
            color: var(--muted);
            border: 1px dashed var(--line);
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        .builder-note-pill:hover, .builder-note-pill.is-active {
            color: var(--ink);
            border-color: var(--muted);
            background: var(--panel-soft);
        }
        .builder-ex-notes {
            font-size: 0.72rem;
            color: var(--muted);
            margin-top: 0.35rem;
            font-style: italic;
            border-left: 2px solid var(--line);
            padding-left: 0.4rem;
        }
        .builder-ex-actions {
            display: flex;
            align-items: center;
            gap: 0.2rem;
            opacity: 0.8;
            transition: opacity 0.2s ease;
        }
        .builder-ex-item:hover .builder-ex-actions {
            opacity: 1;
        }
        .builder-icon-btn {
            background: none;
            border: none;
            padding: 0.3rem;
            cursor: pointer;
            border-radius: 4px;
            color: var(--muted);
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .builder-icon-btn:hover {
            color: var(--ink);
            background: var(--panel-soft);
        }
        .builder-icon-btn.edit-btn:hover {
            color: var(--lime);
        }
        .builder-icon-btn.del-btn:hover {
            color: #ef4444;
        }
        .builder-empty-day {
            text-align: center;
            color: var(--muted);
            padding: 1.5rem 0.5rem;
            font-size: 0.78rem;
            font-style: italic;
            border: 1px dashed var(--line);
            border-radius: 8px;
            margin: 0.5rem 0;
        }

        /* COMPACT MODE STYLES */
        .builder-compact .builder-sortable-list {
            gap: 0.35rem;
            padding: 0.55rem;
        }
        .builder-compact .builder-ex-item {
            padding: 0.42rem 0.65rem;
            border-radius: 7px;
        }
        .builder-compact .builder-ex-name {
            font-size: 0.84rem;
            line-height: 1.25;
        }
        .builder-compact .builder-ex-pills {
            gap: 0.25rem;
            margin-top: 0.2rem;
        }
        .builder-compact .builder-pill {
            font-size: 0.66rem;
            padding: 0.08rem 0.35rem;
        }
        .builder-detailed .builder-note-pill {
            display: none;
        }
        .builder-compact .builder-note-pill {
            display: inline-flex;
            font-size: 0.66rem;
            padding: 0.08rem 0.35rem;
        }
        .builder-compact .builder-ex-notes {
            display: none;
            font-size: 0.73rem;
            margin-top: 0.3rem;
            padding: 0.35rem 0.55rem;
            background: color-mix(in srgb, var(--panel-soft) 85%, transparent);
            border-left: 3px solid var(--lime);
            border-radius: 4px;
        }
        .builder-compact .builder-ex-notes.is-open {
            display: block !important;
            animation: fadeInNote 0.15s ease;
        }
        @keyframes fadeInNote {
            from { opacity: 0; transform: translateY(-3px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .builder-compact .builder-icon-btn {
            padding: 0.2rem;
        }
        .builder-compact .builder-icon-btn svg {
            width: 13px;
            height: 13px;
        }
        .sortable-ghost {
            opacity: 0.35;
            background-color: var(--line) !important;
            border: 1px dashed var(--lime) !important;
        }
        .builder-member-meta .avatar {
            flex-shrink: 0 !important;
            aspect-ratio: 1 / 1 !important;
            border-radius: 50% !important;
            width: 48px !important;
            height: 48px !important;
            min-width: 48px !important;
            min-height: 48px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
        }
        .builder-meta-chips {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.35rem 0.65rem;
            font-size: 0.8rem;
            color: var(--muted);
            margin-top: 0.35rem;
        }
        .builder-meta-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            white-space: nowrap;
        }
        /* Templates Modal Cards */
        .template-card-choice {
            text-align: left;
            padding: 1rem;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--bg);
            cursor: pointer;
            transition: all 0.2s ease;
            margin-bottom: 0.75rem;
        }
        .template-card-choice:hover {
            border-color: var(--lime);
            background: color-mix(in srgb, var(--lime) 5%, var(--bg));
            transform: translateX(4px);
        }

        /* MOBILE RESPONSIVE OPTIMIZATIONS */
        @media (max-width: 768px) {
            .builder-header-wrap {
                padding: 0.85rem;
                gap: 0.85rem;
                margin-bottom: 0.85rem;
                border-radius: 12px;
            }
            .builder-member-meta {
                gap: 0.75rem;
                width: 100%;
            }
            .builder-member-meta .avatar {
                width: 42px !important;
                height: 42px !important;
                min-width: 42px !important;
                min-height: 42px !important;
            }
            .builder-member-title-wrap h1 {
                font-size: 1.15rem !important;
            }
            .builder-actions-group {
                display: grid;
                grid-template-columns: 1fr 1fr 1.2fr;
                gap: 0.4rem;
                width: 100%;
            }
            .builder-actions-group form {
                margin: 0;
                width: 100%;
            }
            .builder-btn {
                width: 100%;
                justify-content: center;
                padding: 0.5rem 0.4rem;
                font-size: 0.78rem;
                gap: 0.3rem;
            }
            .builder-btn svg {
                width: 13px;
                height: 13px;
            }
            .builder-view-toolbar {
                display: flex;
                align-items: center;
                gap: 0.45rem;
                flex-wrap: nowrap;
                margin-top: 0.55rem;
                margin-bottom: 0.55rem;
                position: sticky;
                top: 0;
                z-index: 15;
                background: var(--bg);
                padding: 0.35rem 0;
            }
            .builder-day-tabs {
                flex: 1;
                min-width: 0;
                overflow-x: auto;
                scrollbar-width: none;
                -webkit-overflow-scrolling: touch;
                padding: 0.1rem 0;
            }
            .builder-tab-btn {
                flex-shrink: 0;
                padding: 0.35rem 0.6rem;
                font-size: 0.76rem;
                gap: 0.3rem;
            }
            .builder-tab-btn svg {
                display: none;
            }
            .builder-tab-count {
                font-size: 0.65rem;
                padding: 0.05rem 0.35rem;
            }
            .builder-density-btn {
                flex-shrink: 0;
                padding: 0.35rem 0.55rem;
                font-size: 0.74rem;
                white-space: nowrap;
            }
            .builder-days-grid {
                grid-template-columns: 1fr !important;
                gap: 0.85rem;
            }
            .builder-days-grid.is-single-day {
                grid-template-columns: 1fr !important;
            }
            .builder-sortable-list {
                max-height: none !important;
                overflow-y: visible !important;
                padding: 0.5rem;
            }
            .builder-day-header {
                padding: 0.7rem 0.85rem;
            }
            .builder-day-name {
                font-size: 0.95rem;
            }
            .builder-micro-btn {
                padding: 0.22rem 0.45rem;
                font-size: 0.7rem;
            }
            .builder-ex-item {
                padding: 0.55rem 0.7rem;
            }
            .builder-ex-name {
                font-size: 0.85rem;
            }
        }

        @media (max-width: 420px) {
            .builder-actions-group .builder-btn {
                font-size: 0.74rem;
                padding: 0.45rem 0.2rem;
            }
            .builder-density-btn span {
                display: none;
            }
            .builder-density-btn {
                padding: 0.4rem 0.55rem;
            }
        }
    </style>

    <div class="builder-header-wrap">
        <div class="builder-member-meta">
            <?= render_avatar($member, 'large') ?>
            <div class="builder-member-title-wrap" style="min-width: 0; flex: 1;">
                <div style="display: flex; align-items: center; gap: 0.45rem; flex-wrap: wrap;">
                    <h1 style="margin: 0; font-size: 1.3rem; font-weight: 800; line-height: 1.2;"><?= h($member['first_name'] . ' ' . $member['last_name']) ?></h1>
                    <span class="builder-badge builder-badge-draft">Draft</span>
                    <span class="builder-badge builder-badge-stat"><?= $trainingDaysCount ?> Days/Wk</span>
                    <span class="builder-badge builder-badge-stat"><?= $totalExercisesCount ?> ex &middot; <?= $totalSetsCount ?> sets</span>
                </div>
                <div class="builder-meta-chips">
                    <span class="builder-meta-chip">Goal: <strong style="color: var(--lime);"><?= h(ucwords(str_replace('_', ' ', (string)($profile['primary_goal'] ?? 'General Health')))) ?></strong></span>
                    <span class="builder-meta-chip">Weight: <strong><?= h((string)($profile['weight_kg'] ?? 'N/A')) ?> kg</strong></span>
                    <?php if (!empty($profile['weekly_workout_target'])): ?>
                        <span class="builder-meta-chip">Target: <strong style="color: var(--lime);"><?= (int)$profile['weekly_workout_target'] ?>d/wk</strong> &bull; <strong>~<?= (int)($profile['preferred_duration_mins'] ?? 45) ?>m</strong></span>
                    <?php endif; ?>
                    <span class="builder-meta-chip">Membership: <strong style="color: <?= $hasMembership ? '#2df0a5' : 'var(--orange)' ?>;"><?= $hasMembership ? 'Active' : 'Trial' ?></strong></span>
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
            ?>
                <button type="button" class="builder-tab-btn" data-day="<?= $dNum ?>" onclick="selectDayTab('<?= $dNum ?>')">
                    <span><?= substr($dName, 0, 3) ?></span>
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
            $focusBadge = 'Rest Day';
            if ($exCount > 0) {
                $focusBadge = !empty($muscles) ? implode(' • ', array_slice($muscles, 0, 2)) : 'Training Day';
            }
        ?>
            <div class="builder-day-card" data-day="<?= $dayNum ?>">
                <div class="builder-day-header">
                    <div class="builder-day-title-row">
                        <h3 class="builder-day-name">
                            <span><?= $dayName ?></span>
                        </h3>
                        <div class="builder-day-actions">
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
                        </div>
                    </div>
                    <div class="builder-day-meta-row">
                        <span style="color: <?= $exCount > 0 ? 'var(--lime)' : 'var(--muted)' ?>; font-weight: 600;"><?= h($focusBadge) ?></span>
                        <?php if ($exCount > 0): ?>
                            <span><?= $exCount ?> ex • <?= $setCount ?> sets • ~<?= $estMinutes ?>m</span>
                        <?php else: ?>
                            <span>No workouts</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="builder-sortable-list sortable-list" data-day="<?= $dayNum ?>">
                    <?php if ($exCount === 0): ?>
                        <div class="builder-empty-day">
                            Rest day. Click <strong>+ Add</strong> or <strong>Auto</strong> to schedule exercises.
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
                                        <form method="post" style="margin:0; display:inline;" onsubmit="return confirm('Remove <?= addslashes(h($ex['exercise_name'])) ?>?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="remove_exercise">
                                            <input type="hidden" name="plan_exercise_id" value="<?= $ex['plan_exercise_id'] ?>">
                                            <button type="submit" class="builder-icon-btn del-btn" title="Delete Exercise">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                            </button>
                                        </form>
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
            if ($currentGroup !== $ex['muscle_group']) {
                if ($currentGroup !== '') echo '</optgroup>';
                $currentGroup = $ex['muscle_group'];
                echo '<optgroup label="' . h(ucwords($currentGroup)) . '">';
            }
            echo '<option value="' . $ex['exercise_id'] . '">' . h($ex['name']) . '</option>';
        }
        if ($currentGroup !== '') echo '</optgroup>';
        ?>
    </div>

    <!-- Hidden template choices for presets modal -->
    <div id="templateOptionsPayload" style="display:none;">
        <div style="max-height: 480px; overflow-y: auto; text-align: left; padding-right: 5px;">
            <?php foreach ($templates as $key => $tmpl): 
                $tmplTitle = $tmpl['title'] ?? $tmpl['name'] ?? ucfirst(str_replace('_', ' ', (string)$key));
                $tmplDays = (int)($tmpl['days_per_week'] ?? (isset($tmpl['days']) && is_array($tmpl['days']) ? count($tmpl['days']) : 3));
                $tmplDesc = $tmpl['description'] ?? '';
                $tmplSplit = $tmpl['split_name'] ?? $tmpl['name'] ?? $tmplTitle;
                $tmplGoal = $tmpl['primary_goal'] ?? $tmpl['goal'] ?? 'general_health';
            ?>
                <div class="template-card-choice" onclick="selectTemplate('<?= $key ?>', '<?= addslashes(h($tmplTitle)) ?>')">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                        <strong style="color: var(--lime); font-size: 15px;"><?= h($tmplTitle) ?></strong>
                        <span class="builder-badge builder-badge-stat"><?= $tmplDays ?> Days/Wk</span>
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

    <script>
    // 1. Templates Modal
    function openTemplatesModal() {
        const templatesHtml = document.getElementById('templateOptionsPayload').innerHTML;
        Swal.fire({
            title: 'Select Workout Split Preset',
            html: templatesHtml,
            showCancelButton: true,
            showConfirmButton: false,
            cancelButtonText: 'Cancel',
            cancelButtonColor: '#6c757d',
            background: 'var(--bg)',
            color: 'var(--ink)',
            width: 'min(94vw, 650px)'
        });
    }

    function selectTemplate(templateKey, templateTitle) {
        Swal.fire({
            title: 'Apply ' + templateTitle + '?',
            text: "This will populate your draft with this pre-built routine. Any existing exercises will be replaced. Continue?",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: 'var(--lime-dark, #84cc16)',
            cancelButtonColor: '#6c757d',
            background: 'var(--bg)',
            color: 'var(--ink)',
            confirmButtonText: 'Yes, Apply Template'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.showLoading();
                document.getElementById('templateKeyInput').value = templateKey;
                document.getElementById('applyTemplateForm').submit();
            }
        });
    }

    // 2. Advanced Auto-Generate Modal
    function openAdvancedAutoGenerateModal() {
        Swal.fire({
            title: 'Auto-Generate Training Routine',
            width: 'min(94vw, 560px)',
            html: `
                <div style="text-align: left; display: flex; flex-direction: column; gap: 14px; font-size: 14px;">
                    <p style="margin: 0; color: var(--muted); font-size: 13px;">
                        Antigravity Engine will configure a periodized split tailored to this member's physiological goals.
                    </p>
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13px; margin-bottom: 4px;">Primary Goal</label>
                        <select id="swal-gen-goal" class="form-control" style="width: 100%;">
                            <option value="weight_loss" <?= ($profile['primary_goal'] ?? '') === 'weight_loss' ? 'selected' : '' ?>>Fat Loss & Conditioning</option>
                            <option value="muscle_gain" <?= ($profile['primary_goal'] ?? '') === 'muscle_gain' ? 'selected' : '' ?>>Muscle Hypertrophy (Mass)</option>
                            <option value="strength" <?= ($profile['primary_goal'] ?? '') === 'strength' ? 'selected' : '' ?>>Max Strength & Power</option>
                            <option value="endurance" <?= ($profile['primary_goal'] ?? '') === 'endurance' ? 'selected' : '' ?>>Stamina & Endurance</option>
                            <option value="general_health" <?= ($profile['primary_goal'] ?? '') === 'general_health' ? 'selected' : '' ?>>General Health & Mobility</option>
                        </select>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div>
                            <label style="display:block; color: var(--muted); font-size: 13px; margin-bottom: 4px;">Frequency (Days/Wk)</label>
                            <select id="swal-gen-days" class="form-control" style="width: 100%;">
                                <option value="2" <?= $prefDays === 2 ? 'selected' : '' ?>>2 Days (Express)</option>
                                <option value="3" <?= $prefDays === 3 ? 'selected' : '' ?>>3 Days (Standard)</option>
                                <option value="4" <?= $prefDays === 4 ? 'selected' : '' ?>>4 Days (Upper/Lower)</option>
                                <option value="5" <?= $prefDays === 5 ? 'selected' : '' ?>>5 Days (Bro Split / PPL)</option>
                                <option value="6" <?= $prefDays === 6 ? 'selected' : '' ?>>6 Days (High Frequency)</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; color: var(--muted); font-size: 13px; margin-bottom: 4px;">Split Architecture</label>
                            <select id="swal-gen-split" class="form-control" style="width: 100%;">
                                <option value="auto" selected>Auto-Select Best Split</option>
                                <option value="full_body">Full Body Compound</option>
                                <option value="upper_lower">Upper / Lower Body</option>
                                <option value="ppl">Push / Pull / Legs</option>
                                <option value="body_part">Muscle Isolated Split</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13px; margin-bottom: 4px;">Athlete Experience Level</label>
                        <select id="swal-gen-exp" class="form-control" style="width: 100%;">
                            <option value="beginner">Beginner (Form & Foundation)</option>
                            <option value="intermediate" selected>Intermediate (Hypertrophy & Progressive)</option>
                            <option value="advanced">Advanced (Heavy Loading & RPE 8+)</option>
                        </select>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Generate Routine',
            confirmButtonColor: 'var(--lime-dark, #84cc16)',
            cancelButtonColor: '#6c757d',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                return {
                    goal: document.getElementById('swal-gen-goal').value,
                    days: document.getElementById('swal-gen-days').value,
                    split: document.getElementById('swal-gen-split').value,
                    exp: document.getElementById('swal-gen-exp').value
                };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.showLoading();
                document.getElementById('autoGenGoal').value = result.value.goal;
                document.getElementById('autoGenDays').value = result.value.days;
                document.getElementById('autoGenSplit').value = result.value.split;
                document.getElementById('autoGenExp').value = result.value.exp;
                document.getElementById('advancedAutoGenForm').submit();
            }
        });
    }

    // 3. Day Specific Auto-Gen Modal
    function openDayAutoGenModal(dayNum, dayName) {
        Swal.fire({
            title: 'Auto-Generate ' + dayName,
            width: 'min(94vw, 500px)',
            html: `
                <div style="text-align: left; display: flex; flex-direction: column; gap: 12px; font-size: 14px;">
                    <p style="margin: 0; color: var(--muted); font-size: 13px;">
                        Select muscle target for <strong>${dayName}</strong>:
                    </p>
                    <select id="swal-day-focus" class="form-control" style="width: 100%;">
                        <option value="auto" selected>Auto-Select (Balanced Routine)</option>
                        <option value="push">Push (Chest, Shoulders, Triceps)</option>
                        <option value="pull">Pull (Back, Biceps, Rear Delts)</option>
                        <option value="legs">Legs (Quads, Hamstrings, Calves)</option>
                        <option value="upper">Upper Body (Compound Mix)</option>
                        <option value="lower">Lower Body & Core</option>
                        <option value="full_body">Full Body Workout</option>
                        <option value="core_cardio">Core, Abs & HIIT</option>
                    </select>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Generate Day',
            confirmButtonColor: 'var(--lime-dark, #84cc16)',
            cancelButtonColor: '#6c757d',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                return document.getElementById('swal-day-focus').value;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.showLoading();
                document.getElementById('autoGenDayInput').value = dayNum;
                document.getElementById('autoGenDayFocus').value = result.value;
                document.getElementById('autoGenDayForm').submit();
            }
        });
    }

    // 4. Confirm Clear Day
    function confirmClearDay(dayNum, dayName) {
        Swal.fire({
            title: 'Clear ' + dayName + '?',
            text: 'This will remove all scheduled exercises for ' + dayName + '.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6c757d',
            background: 'var(--bg)',
            color: 'var(--ink)',
            confirmButtonText: 'Yes, clear it'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.showLoading();
                document.getElementById('clearDayInput').value = dayNum;
                document.getElementById('clearDayForm').submit();
            }
        });
    }

    // 5. Publish Prompt
    function promptPublish() {
        Swal.fire({
            title: 'Publish Workout Plan',
            width: 'min(94vw, 520px)',
            html: `
                <div style="text-align: left; display: flex; flex-direction: column; gap: 14px;">
                    <p style="margin:0; color:var(--ink); font-size: 14px;">
                        This will activate the training plan and notify <strong><?= addslashes(h($member['first_name'])) ?></strong>.
                    </p>
                    <?php if (!$hasMembership): ?>
                    <div style="background-color: rgba(255, 193, 7, 0.1); border-left: 4px solid var(--orange); padding: 10px; border-radius: 6px;">
                        <p style="margin: 0; font-size: 13px; color: var(--orange);">
                            <strong>Notice:</strong> This member does not currently hold an active membership. This plan will serve as a 7-day trial.
                        </p>
                    </div>
                    <?php endif; ?>
                    <div style="display:flex; gap:10px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Start Date
                            <input type="date" id="swal-start" class="form-control" style="width:100%; margin-top:5px;" value="<?= $defaultStart ?>" <?= !$hasMembership ? 'readonly' : '' ?>>
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">End Date
                            <input type="date" id="swal-end" class="form-control" style="width:100%; margin-top:5px;" value="<?= $defaultEnd ?>" <?= !$hasMembership ? 'readonly' : '' ?>>
                        </label>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonColor: 'var(--lime-dark, #84cc16)',
            cancelButtonColor: '#6c757d',
            background: 'var(--bg)',
            color: 'var(--ink)',
            confirmButtonText: 'Yes, Publish Routine',
            preConfirm: () => {
                const start = document.getElementById('swal-start').value;
                const end = document.getElementById('swal-end').value;
                if (!start || !end) {
                    Swal.showValidationMessage('Please select both start and end dates');
                    return false;
                }
                if (end < start) {
                    Swal.showValidationMessage('End date cannot precede start date');
                    return false;
                }
                return { start, end };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.showLoading();
                const form = document.getElementById('publishForm');
                
                let startInput = document.createElement('input');
                startInput.type = 'hidden';
                startInput.name = 'start_date';
                startInput.value = result.value.start;
                form.appendChild(startInput);

                let endInput = document.createElement('input');
                endInput.type = 'hidden';
                endInput.name = 'end_date';
                endInput.value = result.value.end;
                form.appendChild(endInput);
                
                form.submit();
            }
        });
    }

    // 6. Add Exercise Modal
    function openAddExerciseModal(dayNum, dayName) {
        const optionsHtml = document.getElementById('exerciseOptions').innerHTML;
        
        Swal.fire({
            title: 'Add Exercise • ' + dayName,
            width: 'min(94vw, 560px)',
            html: `
                <form id="addExForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; font-size: 13px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_exercise">
                    <input type="hidden" name="day_of_week" value="${dayNum}">
                    
                    <div>
                        <label style="display:block; color: var(--muted); margin-bottom: 4px;">Exercise *</label>
                        <select name="exercise_id" class="form-control" required style="width:100%;">
                            <option value="">Select an exercise...</option>
                            ${optionsHtml}
                        </select>
                    </div>
                    
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px;">
                        <div>
                            <label style="display:block; color: var(--muted); margin-bottom: 4px;">Sets *</label>
                            <input type="number" name="sets" value="3" min="1" max="20" class="form-control" required style="width:100%;">
                        </div>
                        <div>
                            <label style="display:block; color: var(--muted); margin-bottom: 4px;">Reps *</label>
                            <input type="text" name="reps" value="10-12" class="form-control" placeholder="e.g. 8-10, 12, Failure" required style="width:100%;">
                        </div>
                        <div>
                            <label style="display:block; color: var(--muted); margin-bottom: 4px;">Target Wt (kg)</label>
                            <input type="number" step="0.5" name="target_weight_kg" placeholder="e.g. 60" class="form-control" style="width:100%;">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px;">
                        <div>
                            <label style="display:block; color: var(--muted); margin-bottom: 4px;">Rest (s)</label>
                            <select name="rest_seconds" class="form-control" style="width:100%;">
                                <option value="0">0s</option>
                                <option value="30">30s</option>
                                <option value="45">45s</option>
                                <option value="60" selected>60s</option>
                                <option value="90">90s</option>
                                <option value="120">120s</option>
                                <option value="180">180s</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; color: var(--muted); margin-bottom: 4px;">Tempo</label>
                            <input type="text" name="tempo" placeholder="e.g. 3-0-1-0" class="form-control" style="width:100%;">
                        </div>
                        <div>
                            <label style="display:block; color: var(--muted); margin-bottom: 4px;">RPE</label>
                            <input type="text" name="rpe" placeholder="e.g. RPE 8" class="form-control" style="width:100%;">
                        </div>
                    </div>
                    
                    <div>
                        <label style="display:block; color: var(--muted); margin-bottom: 4px;">Coaching Cues / Notes</label>
                        <input type="text" name="notes" placeholder="e.g. Focus on deep stretch at the bottom, pause 1s" class="form-control" style="width:100%;">
                    </div>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Add Exercise',
            confirmButtonColor: 'var(--lime-dark, #84cc16)',
            cancelButtonColor: '#6c757d',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('addExForm');
                if (!form.exercise_id.value || !form.sets.value || !form.reps.value) {
                    Swal.showValidationMessage('Please select exercise and enter sets and reps');
                    return false;
                }
                form.submit();
            }
        });
    }

    // 7. Edit Exercise Modal
    function openEditExerciseModal(ex) {
        Swal.fire({
            title: 'Edit • ' + ex.exercise_name,
            width: 'min(94vw, 560px)',
            html: `
                <form id="editExForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; font-size: 13px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="edit_exercise">
                    <input type="hidden" name="plan_exercise_id" value="${ex.plan_exercise_id}">
                    
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px;">
                        <div>
                            <label style="display:block; color: var(--muted); margin-bottom: 4px;">Sets *</label>
                            <input type="number" name="sets" value="${ex.sets}" min="1" max="20" class="form-control" required style="width:100%;">
                        </div>
                        <div>
                            <label style="display:block; color: var(--muted); margin-bottom: 4px;">Reps *</label>
                            <input type="text" name="reps" value="${escapeHtml(ex.reps || '10')}" class="form-control" required style="width:100%;">
                        </div>
                        <div>
                            <label style="display:block; color: var(--muted); margin-bottom: 4px;">Target Wt (kg)</label>
                            <input type="number" step="0.5" name="target_weight_kg" value="${ex.target_weight_kg || ''}" placeholder="e.g. 60" class="form-control" style="width:100%;">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px;">
                        <div>
                            <label style="display:block; color: var(--muted); margin-bottom: 4px;">Rest (s)</label>
                            <select name="rest_seconds" class="form-control" style="width:100%;">
                                <option value="0" ${ex.rest_seconds == 0 ? 'selected' : ''}>0s</option>
                                <option value="30" ${ex.rest_seconds == 30 ? 'selected' : ''}>30s</option>
                                <option value="45" ${ex.rest_seconds == 45 ? 'selected' : ''}>45s</option>
                                <option value="60" ${ex.rest_seconds == 60 ? 'selected' : ''}>60s</option>
                                <option value="90" ${ex.rest_seconds == 90 ? 'selected' : ''}>90s</option>
                                <option value="120" ${ex.rest_seconds == 120 ? 'selected' : ''}>120s</option>
                                <option value="180" ${ex.rest_seconds == 180 ? 'selected' : ''}>180s</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; color: var(--muted); margin-bottom: 4px;">Tempo</label>
                            <input type="text" name="tempo" value="${escapeHtml(ex.tempo || '')}" placeholder="e.g. 3-0-1-0" class="form-control" style="width:100%;">
                        </div>
                        <div>
                            <label style="display:block; color: var(--muted); margin-bottom: 4px;">RPE</label>
                            <input type="text" name="rpe" value="${escapeHtml(ex.rpe || '')}" placeholder="e.g. RPE 8" class="form-control" style="width:100%;">
                        </div>
                    </div>
                    
                    <div>
                        <label style="display:block; color: var(--muted); margin-bottom: 4px;">Coaching Cues / Notes</label>
                        <input type="text" name="notes" value="${escapeHtml(ex.notes || '')}" placeholder="e.g. Keep chest tall" class="form-control" style="width:100%;">
                    </div>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Save Changes',
            confirmButtonColor: 'var(--lime-dark, #84cc16)',
            cancelButtonColor: '#6c757d',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('editExForm');
                if (!form.sets.value || !form.reps.value) {
                    Swal.showValidationMessage('Sets and reps are required');
                    return false;
                }
                form.submit();
            }
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // 8. Density Mode (Compact vs Detailed)
    function applyDensity(mode) {
        const grid = document.getElementById('builderDaysGrid');
        const label = document.getElementById('densityLabel');
        const icon = document.getElementById('densityIcon');
        if (!grid) return;

        if (mode === 'compact') {
            grid.classList.add('builder-compact');
            grid.classList.remove('builder-detailed');
            if (label) label.textContent = 'Detailed View';
            if (icon) {
                icon.innerHTML = '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>';
            }
        } else {
            grid.classList.remove('builder-compact');
            grid.classList.add('builder-detailed');
            if (label) label.textContent = 'Compact View';
            if (icon) {
                icon.innerHTML = '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>';
            }
        }
    }

    function toggleDensity() {
        const grid = document.getElementById('builderDaysGrid');
        if (!grid) return;
        const isCompact = grid.classList.contains('builder-compact');
        const nextMode = isCompact ? 'detailed' : 'compact';
        applyDensity(nextMode);
        try {
            localStorage.setItem('workout_builder_density', nextMode);
        } catch (e) {}
    }

    function toggleNoteOpen(btn) {
        const item = btn.closest('.builder-ex-item');
        if (!item) return;
        const notes = item.querySelector('.builder-ex-notes');
        if (notes) {
            notes.classList.toggle('is-open');
            btn.classList.toggle('is-active');
        }
    }

    // 9. Day Tab Switcher (Focused Day vs All Days)
    function selectDayTab(dayKey) {
        const grid = document.getElementById('builderDaysGrid');
        if (!grid) return;

        document.querySelectorAll('.builder-tab-btn').forEach(btn => {
            btn.classList.toggle('is-active', btn.getAttribute('data-day') === String(dayKey));
        });

        const cards = document.querySelectorAll('.builder-day-card');
        if (dayKey === 'all') {
            grid.classList.remove('is-single-day');
            cards.forEach(card => card.style.display = '');
        } else {
            grid.classList.add('is-single-day');
            cards.forEach(card => {
                if (card.getAttribute('data-day') === String(dayKey)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        try {
            localStorage.setItem('workout_builder_active_day', String(dayKey));
        } catch (e) {}
    }

    // 10. Initialization and SortableJS Drag & Drop
    document.addEventListener('DOMContentLoaded', function () {
        // Restore Density
        const savedDensity = localStorage.getItem('workout_builder_density') || 'detailed';
        applyDensity(savedDensity);

        // Restore Day Tab
        const urlParams = new URLSearchParams(window.location.search);
        const dayParam = urlParams.get('day');
        const savedDay = dayParam || localStorage.getItem('workout_builder_active_day') || 'all';
        selectDayTab(savedDay);

        const lists = document.querySelectorAll('.sortable-list');
        lists.forEach(list => {
            new Sortable(list, {
                group: 'shared', // Drag & drop seamlessly across days
                animation: 150,
                ghostClass: 'sortable-ghost',
                handle: '.builder-ex-item',
                onEnd: function (evt) {
                    const currentList = evt.to;
                    const items = Array.from(currentList.children)
                                       .filter(el => el.hasAttribute('data-id'))
                                       .map(el => el.getAttribute('data-id'));
                    
                    const targetDay = currentList.getAttribute('data-day');
                    
                    if (items.length > 0) {
                        fetch('index.php?page=workout_builder&member_user_id=<?= $memberId ?>', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?>'
                            },
                            body: JSON.stringify({
                                action: 'reorder',
                                day_of_week: targetDay,
                                items: items
                            })
                        }).then(() => {
                            if (evt.from !== evt.to) {
                                // Reload to update empty states and metrics accurately
                                window.location.reload();
                            }
                        });
                    }
                }
            });
        });
    });
    </script>
    <?php
    render_footer();
}
