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
    
    // AJAX Live Search for Exercises (Hybrid Search server-side query)
    if (isset($_GET['action']) && $_GET['action'] === 'search_exercises') {
        header('Content-Type: application/json; charset=utf-8');
        $q = trim((string)($_GET['q'] ?? ''));
        if ($trainerGymId <= 0 || $q === '') {
            echo json_encode(['results' => []]);
            exit;
        }
        $searchStmt = $pdo->prepare('
            SELECT exercise_id, name, muscle_group, category, difficulty_level 
            FROM exercises 
            WHERE gym_id = ? AND (name LIKE ? OR muscle_group LIKE ? OR category LIKE ?)
            ORDER BY muscle_group, name
            LIMIT 50
        ');
        $like = '%' . $q . '%';
        $searchStmt->execute([$trainerGymId, $like, $like, $like]);
        $results = [];
        foreach ($searchStmt->fetchAll() as $row) {
            $results[] = [
                'id' => (int)$row['exercise_id'],
                'name' => $row['name'],
                'muscle_group' => !empty($row['muscle_group']) ? ucwords((string)$row['muscle_group']) : 'General',
                'category' => !empty($row['category']) ? ucwords((string)$row['category']) : '',
                'difficulty' => (int)($row['difficulty_level'] ?? 1)
            ];
        }
        echo json_encode(['results' => $results]);
        exit;
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

    // Fetch exercises for the dropdown - strictly scoped to the gym matching exercises page
    if ($trainerGymId > 0) {
        $stmt = $pdo->prepare('SELECT exercise_id, name, muscle_group, category, difficulty_level FROM exercises WHERE gym_id = ? ORDER BY muscle_group, name');
        $stmt->execute([$trainerGymId]);
        $allExercises = $stmt->fetchAll();
    } else {
        $allExercises = [];
    }

    $exercisesData = [];
    foreach ($allExercises as $ex) {
        $exercisesData[] = [
            'id' => (int)$ex['exercise_id'],
            'name' => $ex['name'],
            'muscle_group' => !empty($ex['muscle_group']) ? ucwords((string)$ex['muscle_group']) : 'General',
            'category' => !empty($ex['category']) ? ucwords((string)$ex['category']) : '',
            'difficulty' => (int)($ex['difficulty_level'] ?? 1)
        ];
    }
    $exercisesJson = json_encode($exercisesData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

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

    <link rel="stylesheet" href="<?= h(asset_url('css/pages/workout_builder.css')) ?>">

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
        defaultStart: <?= json_encode($defaultStart) ?>,
        defaultEnd: <?= json_encode($defaultEnd) ?>
    };
    </script>
    <script src="<?= h(asset_url('js/pages/workout_builder.js')) ?>"></script>
    <?php
    render_footer();
}
