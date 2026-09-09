<?php
declare(strict_types=1);

function training_page(): void
{
    $user = require_roles(['trainer', 'gym_owner', 'platform_admin']);
    $coachId = ensure_coach_profile((int) $user['user_id']);
    $memberId = (int) ($_GET['member_user_id'] ?? post('member_user_id', 0));
    $pdo = db();

    // ----------------------------------------------------
    // POST ACTIONS (Add, Edit, Delete, Duplicate, Renew)
    // ----------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = post('action', 'add_plan');
        $title = trim((string) post('title'));
        
        if ($action === 'add_plan') {
            $pdo->prepare('INSERT INTO training_plans (member_user_id, trainer_id, title, goal, start_date, end_date, status) VALUES (?, ?, ?, ?, NULL, NULL, "draft")')
                ->execute([$memberId, $coachId, $title, post('goal')]);
            
            // Go straight to workout builder
            header('Location: index.php?page=workout_builder&member_user_id=' . $memberId);
            exit;
        } elseif ($action === 'edit_plan') {
            $plan_id = (int) post('plan_id');
            $pdo->prepare('UPDATE training_plans SET member_user_id=?, title=?, goal=?, start_date=?, end_date=? WHERE plan_id=? AND trainer_id=?')
                ->execute([$memberId, $title, post('goal'), post('start_date'), post('end_date') ?: null, $plan_id, $coachId]);
            flash('Training plan updated.', 'success');
        } elseif ($action === 'delete_plan') {
            $plan_id = (int) post('plan_id');
            $pdo->prepare('DELETE FROM training_plans WHERE plan_id=? AND trainer_id=?')->execute([$plan_id, $coachId]);
            flash('Training plan deleted.', 'info');
        } elseif ($action === 'duplicate_plan') {
            $source_plan_id = (int) post('plan_id');
            $target_member_id = (int) post('target_member_id');
            
            $source_plan = $pdo->prepare('SELECT title, goal FROM training_plans WHERE plan_id = ? AND trainer_id = ?');
            $source_plan->execute([$source_plan_id, $coachId]);
            $planData = $source_plan->fetch();
            
            if ($planData && $target_member_id) {
                // Get active membership for target
                $membership = $pdo->query('SELECT start_date, end_date FROM memberships WHERE user_id = ' . $target_member_id . ' AND status = "active" ORDER BY end_date DESC LIMIT 1')->fetch();
                $startDate = $membership ? $membership['start_date'] : date('Y-m-d');
                $endDate = $membership ? $membership['end_date'] : date('Y-m-d', strtotime('+4 weeks'));
                
                $newTitle = $planData['title'] . ' (Copy)';
                
                $pdo->prepare('INSERT INTO training_plans (member_user_id, trainer_id, title, goal, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, "draft")')
                    ->execute([$target_member_id, $coachId, $newTitle, $planData['goal'], $startDate, $endDate]);
                $newPlanId = $pdo->lastInsertId();
                
                $pdo->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, rest_seconds) 
                               SELECT ?, exercise_id, day_of_week, sequence_order, sets, reps, rest_seconds FROM training_plan_exercises WHERE plan_id = ?')
                    ->execute([$newPlanId, $source_plan_id]);
                    
                flash('Training plan duplicated successfully as a draft.', 'success');
            }
        } elseif ($action === 'renew_plan') {
            $source_plan_id = (int) post('plan_id');
            
            $source_plan = $pdo->prepare('SELECT * FROM training_plans WHERE plan_id = ? AND trainer_id = ?');
            $source_plan->execute([$source_plan_id, $coachId]);
            $planData = $source_plan->fetch();
            
            if ($planData) {
                $oldEndDate = strtotime($planData['end_date'] ?? date('Y-m-d'));
                $newStart = date('Y-m-d', strtotime('+1 day', $oldEndDate));
                if ($newStart < date('Y-m-d')) {
                    $newStart = date('Y-m-d');
                }
                $newEnd = date('Y-m-d', strtotime('+4 weeks', strtotime($newStart)));
                
                $pdo->prepare('INSERT INTO training_plans (member_user_id, trainer_id, title, goal, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, "draft")')
                    ->execute([$planData['member_user_id'], $coachId, $planData['title'] . ' (Phase 2)', $planData['goal'], $newStart, $newEnd]);
                $newPlanId = $pdo->lastInsertId();
                
                $pdo->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, rest_seconds) 
                               SELECT ?, exercise_id, day_of_week, sequence_order, sets, reps, rest_seconds FROM training_plan_exercises WHERE plan_id = ?')
                    ->execute([$newPlanId, $source_plan_id]);
                    
                flash('Training plan renewed as a new draft Phase 2.', 'success');
            }
        }
        redirect('training');
    }

    // ----------------------------------------------------
    // TAB 1 DATA: Eligible Members & Created Plans
    // ----------------------------------------------------
    $gymId = null;
    if ($user['role'] === 'gym_owner') {
        $gymId = $user['gym_id'] ?? scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]) ?? scalar('SELECT gym_id FROM gyms ORDER BY gym_id ASC LIMIT 1');
        $members = query_all('SELECT gm.user_id AS member_user_id, CONCAT(u.first_name, " ", u.last_name) AS name, mp.primary_goal, EXISTS (SELECT 1 FROM memberships m WHERE m.user_id = u.user_id AND m.status = "active" AND m.end_date >= CURRENT_DATE) AS has_membership FROM gym_members gm JOIN users u ON u.user_id = gm.user_id LEFT JOIN member_profiles mp ON mp.user_id = u.user_id WHERE gm.gym_id = ? AND u.status = "active" AND NOT EXISTS (SELECT 1 FROM training_plans tp WHERE tp.member_user_id = gm.user_id AND tp.trainer_id = ? AND tp.status IN ("active", "draft"))', [$gymId, $coachId]);
    } elseif ($user['role'] === 'trainer') {
        $members = query_all('SELECT ca.member_user_id, CONCAT(u.first_name, " ", u.last_name) AS name, mp.primary_goal, EXISTS (SELECT 1 FROM memberships m WHERE m.user_id = u.user_id AND m.status = "active" AND m.end_date >= CURRENT_DATE) AS has_membership FROM trainer_assignments ca JOIN users u ON u.user_id = ca.member_user_id LEFT JOIN member_profiles mp ON mp.user_id = u.user_id WHERE ca.trainer_id = ? AND ca.status = "active" AND NOT EXISTS (SELECT 1 FROM training_plans tp WHERE tp.member_user_id = ca.member_user_id AND tp.trainer_id = ca.trainer_id AND tp.status IN ("active", "draft"))', [$coachId]);
    } else {
        $members = query_all('SELECT u.user_id AS member_user_id, CONCAT(u.first_name, " ", u.last_name) AS name, mp.primary_goal, 1 AS has_membership FROM users u LEFT JOIN member_profiles mp ON mp.user_id = u.user_id WHERE u.role = "member" AND u.status = "active" LIMIT 100');
    }

    $plans = query_all('
        SELECT tp.*, 
               CONCAT(u.first_name, " ", u.last_name) AS member,
               (SELECT COUNT(*) FROM training_plan_exercises WHERE plan_id = tp.plan_id) AS expected_weekly,
               (SELECT COUNT(*) FROM exercise_completions WHERE plan_id = tp.plan_id) AS completed_count,
               (SELECT message_text FROM trainer_messages WHERE sender_id = tp.member_user_id AND recipient_id = ? ORDER BY sent_at DESC LIMIT 1) AS latest_feedback
        FROM training_plans tp 
        JOIN users u ON u.user_id = tp.member_user_id 
        WHERE tp.trainer_id = ? 
        ORDER BY tp.created_at DESC
    ', [$user['user_id'], $coachId]);

    // ----------------------------------------------------
    // TAB 2 DATA: All Gym Workouts & Inspection
    // ----------------------------------------------------
    $viewPlanId = (int) ($_GET['view_plan_id'] ?? 0);
    $viewPlan = null;
    if ($viewPlanId > 0) {
        $stmtView = $pdo->prepare('
            SELECT p.*, m.first_name as member_first, m.last_name as member_last, m.profile_picture as member_pic,
                   t.first_name as trainer_first, t.last_name as trainer_last
            FROM training_plans p
            JOIN users m ON p.member_user_id = m.user_id
            LEFT JOIN trainer_profiles tp ON p.trainer_id = tp.trainer_id
            LEFT JOIN users t ON tp.user_id = t.user_id
            WHERE p.plan_id = ?
        ');
        $stmtView->execute([$viewPlanId]);
        $viewPlan = $stmtView->fetch();
    }

    if ($user['role'] === 'gym_owner' && $gymId) {
        $allPlans = query_all('
            SELECT p.*, 
                   CONCAT(m.first_name, " ", m.last_name) AS member,
                   m.profile_picture AS member_pic,
                   CONCAT(t.first_name, " ", t.last_name) AS trainer,
                   t.profile_picture AS trainer_pic,
                   (SELECT COUNT(*) FROM training_plan_exercises WHERE plan_id = p.plan_id) AS exercise_count
            FROM training_plans p
            JOIN users m ON m.user_id = p.member_user_id
            LEFT JOIN trainer_profiles tp ON p.trainer_id = tp.trainer_id
            LEFT JOIN users t ON tp.user_id = t.user_id
            WHERE m.user_id IN (SELECT user_id FROM gym_members WHERE gym_id = ?)
               OR t.user_id IN (SELECT user_id FROM gym_members WHERE gym_id = ?)
            ORDER BY p.created_at DESC
        ', [$gymId, $gymId]);
    } elseif ($user['role'] === 'trainer') {
        $allPlans = query_all('
            SELECT p.*, 
                   CONCAT(m.first_name, " ", m.last_name) AS member,
                   m.profile_picture AS member_pic,
                   CONCAT(t.first_name, " ", t.last_name) AS trainer,
                   t.profile_picture AS trainer_pic,
                   (SELECT COUNT(*) FROM training_plan_exercises WHERE plan_id = p.plan_id) AS exercise_count
            FROM training_plans p
            JOIN users m ON m.user_id = p.member_user_id
            LEFT JOIN trainer_profiles tp ON p.trainer_id = tp.trainer_id
            LEFT JOIN users t ON tp.user_id = t.user_id
            WHERE p.trainer_id = ?
            ORDER BY p.created_at DESC
        ', [$coachId]);
    } else {
        $allPlans = query_all('
            SELECT p.*, 
                   CONCAT(m.first_name, " ", m.last_name) AS member,
                   m.profile_picture AS member_pic,
                   CONCAT(t.first_name, " ", t.last_name) AS trainer,
                   t.profile_picture AS trainer_pic,
                   (SELECT COUNT(*) FROM training_plan_exercises WHERE plan_id = p.plan_id) AS exercise_count
            FROM training_plans p
            JOIN users m ON m.user_id = p.member_user_id
            LEFT JOIN trainer_profiles tp ON p.trainer_id = tp.trainer_id
            LEFT JOIN users t ON tp.user_id = t.user_id
            ORDER BY p.created_at DESC
        ');
    }

    $allCountActive = 0;
    $allCountDraft = 0;
    $allCountExpired = 0;
    foreach ($allPlans as $ap) {
        $st = strtolower((string)$ap['status']);
        if ($st === 'active') $allCountActive++;
        elseif ($st === 'draft') $allCountDraft++;
        else $allCountExpired++;
    }

    $activeTab = ($_GET['tab'] ?? '') === 'all' || $viewPlanId ? 'all' : 'create';

    render_header('Workouts', $user);
?>

<div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="margin: 0 0 5px 0;">Workouts</h1>
        <p class="muted" style="margin: 0;">Create customized training routines and manage member workout plans across your gym.</p>
    </div>
</div>

<!-- ==================================================== -->
<!-- TOP VIEW SWITCHER: CREATE WORKOUTS VS ALL WORKOUTS   -->
<!-- ==================================================== -->
<div class="workout-main-nav-wrap">
    <div class="workout-main-nav">
        <button type="button" class="workout-nav-pill <?= $activeTab === 'create' ? 'active' : '' ?>" id="btn-view-create" onclick="switchWorkoutView('create')">
            <div class="workout-nav-pill-title-row">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 4v16"/><path d="M10 4v16"/><path d="M6 12h4"/><path d="M14 4v16"/><path d="M18 4v16"/><path d="M14 12h4"/>
                </svg>
                <span class="workout-nav-title-full">Create Workouts</span>
                <span class="workout-nav-title-short">Create</span>
            </div>
            <span class="workout-nav-badge create-badge"><?= count($plans) ?> Plans</span>
        </button>
        <button type="button" class="workout-nav-pill <?= $activeTab === 'all' ? 'active' : '' ?>" id="btn-view-all" onclick="switchWorkoutView('all')">
            <div class="workout-nav-pill-title-row">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><line x1="8" y1="11" x2="16" y2="11"/><line x1="8" y1="15" x2="16" y2="15"/>
                </svg>
                <span class="workout-nav-title-full">All Workouts</span>
                <span class="workout-nav-title-short">All Plans</span>
            </div>
            <span class="workout-nav-badge all-badge"><?= count($allPlans) ?> Total</span>
        </button>
    </div>
</div>

<!-- ==================================================== -->
<!-- VIEW 1: CREATE WORKOUTS (PLANS CREATION & ASSIGNMENT)-->
<!-- ==================================================== -->
<div id="view-create-workouts" class="workout-view-panel" style="<?= $activeTab === 'create' ? 'display:block;' : 'display:none;' ?>">
    <section class="panel">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:12px;">
            <div>
                <h2 style="margin:0; font-size:1.25rem; color:var(--ink);">My Training Plans</h2>
                <p class="muted" style="margin:3px 0 0 0; font-size:13px;">Manage workouts assigned to your active training roster.</p>
            </div>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('planModal').style.display='flex'" style="display:inline-flex; align-items:center; gap:6px; font-weight:700;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <span>+ Add Plan</span>
            </button>
        </div>

        <?php if (empty($plans)): ?>
            <div style="padding: 40px 20px; text-align: center; color: var(--muted); background: color-mix(in srgb, var(--surface) 60%, var(--bg)); border-radius: 10px; border: 1px dashed var(--line);">
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="opacity:0.6; margin-bottom:10px;"><path d="M6 4v16"/><path d="M10 4v16"/><path d="M6 12h4"/><path d="M14 4v16"/><path d="M18 4v16"/><path d="M14 12h4"/></svg>
                <p style="margin:0 0 8px 0; font-weight:700; color:var(--ink);">No training plans created yet</p>
                <p style="margin:0 0 16px 0; font-size:13px;">Click "+ Add Plan" to assign a new routine to a member and build their exercise schedule.</p>
                <button type="button" class="btn btn-primary" onclick="document.getElementById('planModal').style.display='flex'">+ Add First Plan</button>
            </div>
        <?php else: ?>
            <?php
            $csrfStr = csrf_field();
            $tableRows = array_map(function($p) use ($csrfStr) {
                $safeJson = htmlspecialchars(json_encode($p));
                $isDraft = ($p['status'] === 'draft');
                
                if ($isDraft) {
                    $p['progress'] = '<div style="font-size:12px; color:var(--muted); min-width:80px;">N/A</div>';
                    $p['adherence'] = '<div style="font-size:12px; color:var(--muted); min-width:120px;">N/A</div>';
                    $p['start_date'] = '<span style="color:var(--muted);"><small>N/A</small></span>';
                    $p['end_date'] = '<span style="color:var(--muted);"><small>N/A</small></span>';
                    
                    $renewBtn = '';
                    $buildBtn = '<form method="get" action="index.php" style="display:inline;">' .
                                '<input type="hidden" name="page" value="workout_builder">' .
                                '<input type="hidden" name="member_user_id" value="' . $p['member_user_id'] . '">' .
                                '<button type="submit" class="btn btn-primary" style="padding:4px 8px;font-size:12px;margin-right:4px;cursor:pointer;">Build Workout</button></form>';
                } else {
                    // Calculate Progress & Adherence
                    $startDate = strtotime($p['start_date'] ?? date('Y-m-d'));
                    $endDate = strtotime($p['end_date'] ?? date('Y-m-d'));
                    $now = time();
                    
                    $totalWeeks = max(1, ceil(($endDate - $startDate) / (7 * 86400)));
                    $elapsedWeeks = max(1, ceil(($now - $startDate) / (7 * 86400)));
                    if ($now < $startDate) $elapsedWeeks = 0;
                    if ($now > $endDate) $elapsedWeeks = $totalWeeks;
                    
                    $p['progress'] = '<div style="font-size:12px; color:var(--muted); min-width:80px;">Week ' . $elapsedWeeks . ' of ' . $totalWeeks . '</div>';
                    
                    $expectedTotal = (int)$p['expected_weekly'] * $elapsedWeeks;
                    $completed = (int)$p['completed_count'];
                    $adherencePercent = $expectedTotal > 0 ? min(100, round(($completed / $expectedTotal) * 100)) : ($elapsedWeeks > 0 ? 0 : 100);
                    
                    $p['adherence'] = '<div style="display:flex;align-items:center;gap:8px; min-width:120px;">
                                           <div style="flex:1;background:#334155;height:6px;border-radius:3px;overflow:hidden;">
                                               <div style="width:'.$adherencePercent.'%;background:var(--lime);height:100%;"></div>
                                           </div>
                                           <span style="font-size:12px;">'.$adherencePercent.'%</span>
                                       </div>';
                                       
                    // Expiry Alert
                    $daysLeft = ($endDate - $now) / 86400;
                    if ($daysLeft <= 3 && $daysLeft >= 0) {
                        $p['end_date'] = '<span style="color:orange; font-weight:600;">' . h($p['end_date']) . ' <br><small>(Expiring)</small></span>';
                    } elseif ($daysLeft < 0) {
                        $p['end_date'] = '<span style="color:var(--red); font-weight:600;">' . h($p['end_date']) . ' <br><small>(Expired)</small></span>';
                    }
                    
                    $renewBtn = ($daysLeft <= 3) ? '<form method="post" style="display:inline;" onsubmit="return confirm(\'Renew this plan for another 4 weeks?\');">' .
                                                   $csrfStr . '<input type="hidden" name="action" value="renew_plan"><input type="hidden" name="plan_id" value="'.$p['plan_id'].'">' .
                                                   '<button type="submit" class="btn btn-primary" style="padding:4px 8px;font-size:12px;margin-right:4px;">Renew</button></form>' : '';
                    $buildBtn = '';
                }
                
                $feedbackStr = $p['latest_feedback'] ? h(substr($p['latest_feedback'], 0, 30)) . (strlen($p['latest_feedback']) > 30 ? '...' : '') : '<i style="color:var(--muted);">None</i>';
                $p['feedback'] = '<span style="font-size:12px; display:inline-block; max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">' . $feedbackStr . '</span>';
                
                $duplicateBtn = '<button type="button" onclick="openDuplicateModal('.$p['plan_id'].')" class="btn btn-secondary" style="padding:4px 8px;font-size:12px;margin-right:4px;">Duplicate</button>';
                
                $p['actions'] = '<div style="display:flex; gap:4px; flex-wrap:wrap;">' . 
                                $renewBtn . $buildBtn . $duplicateBtn . 
                                '<button type="button" onclick="editPlan(' . $safeJson . ')" class="btn btn-secondary" style="padding:4px 8px;font-size:12px;">Edit</button>' .
                                '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete this training plan?\');">' .
                                $csrfStr . '<input type="hidden" name="action" value="delete_plan"><input type="hidden" name="plan_id" value="'.$p['plan_id'].'">' .
                                '<button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:12px;">Delete</button></form></div>';
                return $p;
            }, $plans);
            echo render_simple_table($tableRows, ['member', 'title', 'start_date', 'end_date', 'status', 'progress', 'adherence', 'feedback', 'actions']);
            ?>
        <?php endif; ?>
    </section>
</div>

<!-- ==================================================== -->
<!-- VIEW 2: ALL WORKOUTS (DIRECTORY & INSPECTION)        -->
<!-- ==================================================== -->
<div id="view-all-workouts" class="workout-view-panel" style="<?= $activeTab === 'all' ? 'display:block;' : 'display:none;' ?>">
    <?php if ($viewPlanId && $viewPlan): ?>
        <!-- Single Workout Plan Inspection View -->
        <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <a href="index.php?page=training&tab=all" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                    <span>Back to All Workouts</span>
                </a>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <span class="badge" style="background: <?= $viewPlan['status'] === 'active' ? 'var(--lime)' : ($viewPlan['status'] === 'draft' ? '#f59e0b' : 'var(--line)') ?>; color: var(--bg); font-weight: bold;">
                    <?= h(ucfirst($viewPlan['status'])) ?>
                </span>
            </div>
        </div>

        <div class="panel" style="margin-bottom: 20px;">
            <div class="view-plan-header-card" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 14px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <?php if ($viewPlan['member_pic']): ?>
                        <img src="<?= h(upload_url($viewPlan['member_pic'])) ?>" alt="Member" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <div style="width: 44px; height: 44px; border-radius: 50%; background: var(--line); display: flex; align-items: center; justify-content: center; font-size: 16px; font-weight: bold; color: var(--ink);">
                            <?= h(substr($viewPlan['member_first'], 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <h2 style="margin: 0; font-size: 1.25rem; color: var(--ink);"><?= h($viewPlan['member_first'] . ' ' . $viewPlan['member_last']) ?></h2>
                        <p class="muted" style="margin: 2px 0 0 0; font-size: 12.5px;">
                            Trainer: <strong><?= h($viewPlan['trainer_first'] . ' ' . $viewPlan['trainer_last']) ?></strong> • Goal: <strong><?= h(ucwords(str_replace('_', ' ', $viewPlan['goal']))) ?></strong>
                        </p>
                    </div>
                </div>
                <div>
                    <a href="index.php?page=workout_builder&member_user_id=<?= (int)$viewPlan['member_user_id'] ?>" class="btn btn-primary" style="font-size: 13px; font-weight: 700;">
                        Open in Workout Builder
                    </a>
                </div>
            </div>
        </div>

        <?php render_current_workout((int) $viewPlan['member_user_id'], false, $viewPlanId); ?>

    <?php else: ?>
        <!-- All Workout Plans Directory -->
        <section class="panel">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:12px;">
                <div>
                    <h2 style="margin:0; font-size:1.25rem; color:var(--ink);">All Workout Plans</h2>
                    <p class="muted" style="margin:3px 0 0 0; font-size:13px;">Complete directory of workout regimens created for members.</p>
                </div>
            </div>

            <!-- Instant Real-Time Search & Status Filter Toolbar -->
            <div class="all-workouts-toolbar" style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:16px; flex-wrap:wrap;">
                <div class="all-workout-filters-wrap" style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                    <button type="button" class="all-workout-filter-pill active" onclick="setAllWorkoutsStatusFilter('all', this)">
                        All (<?= count($allPlans) ?>)
                    </button>
                    <button type="button" class="all-workout-filter-pill" onclick="setAllWorkoutsStatusFilter('active', this)">
                        Active (<?= $allCountActive ?>)
                    </button>
                    <button type="button" class="all-workout-filter-pill" onclick="setAllWorkoutsStatusFilter('draft', this)">
                        Draft (<?= $allCountDraft ?>)
                    </button>
                    <button type="button" class="all-workout-filter-pill" onclick="setAllWorkoutsStatusFilter('other', this)">
                        Expired / Other (<?= $allCountExpired ?>)
                    </button>
                </div>
                <div class="all-workouts-search-wrap" style="position:relative; flex:1 1 240px; max-width:340px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--muted); pointer-events:none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="all-workouts-search" oninput="filterAllWorkouts()" placeholder="Search member, trainer, or plan title..." style="width:100%; box-sizing:border-box; padding:8px 12px 8px 34px; border-radius:8px; border:1px solid var(--line); background:var(--panel); color:var(--ink); font-size:13px;">
                </div>
            </div>

            <?php if (empty($allPlans)): ?>
                <div style="padding: 40px 20px; text-align: center; color: var(--muted); background: color-mix(in srgb, var(--surface) 60%, var(--bg)); border-radius: 10px; border: 1px dashed var(--line);">
                    <p style="margin:0; font-style: italic;">No workout plans recorded in the system yet.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr>
                                <th style="text-align:left; padding:10px 12px;">Plan ID</th>
                                <th style="text-align:left; padding:10px 12px;">Member</th>
                                <th style="text-align:left; padding:10px 12px;">Trainer</th>
                                <th style="text-align:left; padding:10px 12px;">Title & Goal</th>
                                <th style="text-align:left; padding:10px 12px;">Status</th>
                                <th style="text-align:left; padding:10px 12px;">Exercises</th>
                                <th style="text-align:left; padding:10px 12px;">Dates</th>
                                <th style="text-align:right; padding:10px 12px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="all-workouts-tbody">
                            <?php foreach ($allPlans as $row): 
                                $statusKey = strtolower((string)$row['status']);
                                $filterStatusCategory = in_array($statusKey, ['active', 'draft'], true) ? $statusKey : 'other';
                                $searchBlob = strtolower($row['member'] . ' ' . $row['trainer'] . ' ' . $row['title'] . ' ' . $row['goal'] . ' #' . $row['plan_id']);
                            ?>
                                <tr class="all-workout-row" data-status="<?= h($filterStatusCategory) ?>" data-search="<?= h($searchBlob) ?>" style="border-bottom:1px solid var(--line);">
                                    <td style="padding:12px; font-weight:700; color:var(--muted);">#<?= h((string)$row['plan_id']) ?></td>
                                    <td style="padding:12px;">
                                        <div style="display: flex; align-items: center; gap: 9px;">
                                            <?php if ($row['member_pic']): ?>
                                                <img src="<?= h(upload_url($row['member_pic'])) ?>" alt="Member" loading="lazy" decoding="async" style="width: 28px; height: 28px; border-radius: 50%; object-fit: cover;">
                                            <?php else: ?>
                                                <div style="width: 28px; height: 28px; border-radius: 50%; background: var(--line); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: var(--muted);"><?= h(substr($row['member'] ?? 'M', 0, 1)) ?></div>
                                            <?php endif; ?>
                                            <strong style="color:var(--ink); font-size:13.5px;"><?= h($row['member']) ?></strong>
                                        </div>
                                    </td>
                                    <td style="padding:12px;">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <?php if ($row['trainer_pic']): ?>
                                                <img src="<?= h(upload_url($row['trainer_pic'])) ?>" alt="Trainer" loading="lazy" decoding="async" style="width: 24px; height: 24px; border-radius: 50%; object-fit: cover;">
                                            <?php else: ?>
                                                <div style="width: 24px; height: 24px; border-radius: 50%; background: var(--line); display: flex; align-items: center; justify-content: center; font-size: 10px; color: var(--muted);"><?= h(substr($row['trainer'] ?? 'T', 0, 1)) ?></div>
                                            <?php endif; ?>
                                            <span style="font-size:13px; color:var(--ink);"><?= h($row['trainer'] ?: 'Assigned Coach') ?></span>
                                        </div>
                                    </td>
                                    <td style="padding:12px;">
                                        <strong style="display:block; font-size:13px; color:var(--ink);"><?= h($row['title'] ?: 'Workout Plan') ?></strong>
                                        <span class="muted" style="font-size:11.5px;"><?= h(ucwords(str_replace('_', ' ', (string)$row['goal']))) ?></span>
                                    </td>
                                    <td style="padding:12px;">
                                        <?php if ($statusKey === 'active'): ?>
                                            <span class="badge" style="background: var(--lime); color: var(--bg); font-weight: 700;">Active</span>
                                        <?php elseif ($statusKey === 'draft'): ?>
                                            <span class="badge" style="background: #f59e0b; color: #000; font-weight: 700;">Draft</span>
                                        <?php else: ?>
                                            <span class="badge" style="background: var(--line); color: var(--muted);"><?= h(ucfirst($statusKey)) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:12px; font-size:12.5px; color:var(--muted);">
                                        <?= (int) $row['exercise_count'] ?> movements
                                    </td>
                                    <td style="padding:12px; font-size:12px; color:var(--muted); white-space:nowrap;">
                                        <?= $row['start_date'] ? date('M j, Y', strtotime($row['start_date'])) : 'Pending' ?>
                                    </td>
                                    <td style="padding:12px; text-align:right;">
                                        <div style="display:inline-flex; gap:6px; justify-content:flex-end;">
                                            <a href="index.php?page=training&tab=all&view_plan_id=<?= $row['plan_id'] ?>" class="btn btn-secondary" style="padding:5px 10px; font-size:12px; display:inline-flex; align-items:center; gap:4px;">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                                                <span>View Plan</span>
                                            </a>
                                            <?php if ($statusKey === 'draft'): ?>
                                                <a href="index.php?page=workout_builder&member_user_id=<?= $row['member_user_id'] ?>" class="btn btn-primary" style="padding:5px 10px; font-size:12px;">
                                                    Build
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="all-workouts-no-results" style="display:none; padding:30px; text-align:center; color:var(--muted);">
                    No workout plans match the current search or status filter.
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<!-- ==================================================== -->
<!-- MODAL: ADD TRAINING PLAN                             -->
<!-- ==================================================== -->
<div id="planModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);backdrop-filter:blur(5px);align-items:center;justify-content:center;z-index:10000;padding:1rem">
    <div style="background:var(--panel);padding:24px 28px;border-radius:14px;width:100%;max-width:460px;box-shadow:0 24px 50px rgba(0,0,0,0.4);border:1px solid var(--line)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
            <div>
                <h2 style="margin:0; font-size:1.35rem; font-weight:700; color:var(--ink)">Add Training Plan</h2>
                <p class="muted" style="margin:2px 0 0 0; font-size:12.5px;">Assign a new workout regimen to a gym member</p>
            </div>
            <button type="button" onclick="document.getElementById('planModal').style.display='none'" style="background:transparent;border:none;color:var(--muted);font-size:1.5rem;cursor:pointer;padding:0;line-height:1;transition:color 0.2s;" onmouseover="this.style.color='var(--ink)'" onmouseout="this.style.color='var(--muted)'">&times;</button>
        </div>
        <form method="post" class="form" style="display:flex;flex-direction:column;gap:14px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_plan">
            <label style="display:block; color:var(--muted); font-size:13px; font-weight:600">Member
                <select name="member_user_id" class="form-control" style="width:100%;box-sizing:border-box;margin-top:5px;background:var(--panel);color:var(--ink);border:1.5px solid var(--line);border-radius:8px;padding:9px 12px;" required onchange="updateGoalField(this)">
                    <option value="" data-goal="">-- Select Member --</option>
                    <?php foreach ($members as $member): ?>
                        <?php $statusText = empty($member['has_membership']) ? ' (No Membership)' : ' (Active Member)'; ?>
                        <option value="<?= (int) $member['member_user_id'] ?>" data-goal="<?= h($member['primary_goal'] ?? '') ?>" <?= $memberId === (int) $member['member_user_id'] ? 'selected' : '' ?>><?= h($member['name']) . $statusText ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:block; color:var(--muted); font-size:13px; font-weight:600">Plan Title 
                <input name="title" class="form-control" placeholder="e.g., Personalized Strength & Hypertrophy" style="width:100%;box-sizing:border-box;margin-top:5px;background:var(--panel);color:var(--ink);border:1.5px solid var(--line);border-radius:8px;padding:9px 12px;" required>
            </label>
            <label style="display:block; color:var(--muted); font-size:13px; font-weight:600">Goal 
                <input name="goal" class="form-control" placeholder="e.g., muscle_gain, fat_loss" style="width:100%;box-sizing:border-box;margin-top:5px;background:var(--panel);color:var(--ink);border:1.5px solid var(--line);border-radius:8px;padding:9px 12px;">
            </label>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px;padding-top:14px;border-top:1px solid var(--line)">
                <button type="button" onclick="document.getElementById('planModal').style.display='none'" class="btn btn-secondary" style="background:var(--panel-soft);color:var(--ink);border:1px solid var(--line);padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="padding:8px 20px;font-size:13px;font-weight:700;">Create & Build Plan</button>
            </div>
        </form>
    </div>
</div>

<style>
/* ==================================================== */
/* TOP VIEW SWITCHER TABS SYSTEM STYLING                */
/* ==================================================== */
.workout-main-nav-wrap {
    margin-bottom: 22px;
}
.workout-main-nav {
    display: inline-flex;
    background: var(--surface, #121721);
    border: 1px solid var(--line, rgba(255,255,255,0.1));
    border-radius: 12px;
    padding: 4px;
    gap: 6px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    max-width: 100%;
    flex-wrap: wrap;
}
.workout-nav-pill {
    background: transparent;
    border: 1px solid transparent;
    color: var(--muted, #8792ad);
    padding: 9px 18px;
    border-radius: 9px;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 9px;
    transition: all 0.22s ease;
}
.workout-nav-pill:hover {
    color: var(--ink, #ffffff);
    background: color-mix(in srgb, var(--ink, #ffffff) 5%, transparent);
}
.workout-nav-pill.active {
    background: var(--panel-soft, rgba(255,255,255,0.06));
    color: var(--ink, #ffffff);
    border-color: color-mix(in srgb, var(--lime, #c7ff22) 40%, var(--line, rgba(255,255,255,0.1)));
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.1);
}
[data-theme="light"] .workout-nav-pill.active {
    background: #f1f5f9;
    color: #0f172a;
    border-color: var(--line, #e2e8f0);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}
.workout-nav-pill-title-row {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.workout-nav-title-full {
    display: inline;
}
.workout-nav-title-short {
    display: none;
}
@media (max-width: 640px) {
    .workout-nav-title-full { display: none; }
    .workout-nav-title-short { display: inline; }
}
.workout-nav-badge {
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 12px;
    letter-spacing: 0.2px;
}
.workout-nav-pill.active .create-badge {
    background: color-mix(in srgb, var(--lime, #c7ff22) 18%, transparent);
    color: var(--lime, #c7ff22);
    border: 1px solid color-mix(in srgb, var(--lime, #c7ff22) 35%, transparent);
}
.workout-nav-pill:not(.active) .create-badge {
    background: color-mix(in srgb, var(--ink, #ffffff) 6%, transparent);
    color: var(--muted, #8792ad);
}
.workout-nav-pill.active .all-badge {
    background: color-mix(in srgb, #38bdf8 18%, transparent);
    color: #38bdf8;
    border: 1px solid color-mix(in srgb, #38bdf8 35%, transparent);
}
.workout-nav-pill:not(.active) .all-badge {
    background: color-mix(in srgb, var(--ink, #ffffff) 6%, transparent);
    color: var(--muted, #8792ad);
}
.workout-view-panel {
    animation: workoutViewFade 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes workoutViewFade {
    0% { opacity: 0; transform: translateY(6px); }
    100% { opacity: 1; transform: translateY(0); }
}

/* Responsive Table Wrappers */
.table-wrap,
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    width: 100%;
    border-radius: 8px;
}
.table-wrap table,
.table-responsive table {
    min-width: 780px;
    width: 100%;
}

/* Toolbar & Filter Pills for All Workouts */
.all-workout-filter-pill {
    background: transparent;
    border: 1px solid var(--line);
    border-radius: 20px;
    color: var(--muted);
    padding: 5px 12px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}
.all-workout-filter-pill:hover {
    color: var(--ink);
    border-color: color-mix(in srgb, var(--ink) 25%, var(--line));
}
.all-workout-filter-pill.active {
    background: color-mix(in srgb, var(--lime) 15%, transparent);
    color: var(--lime);
    border-color: var(--lime);
}

/* Multi-Device Responsive Breakpoints */
@media (max-width: 768px) {
    .all-workouts-toolbar {
        flex-direction: column;
        align-items: stretch !important;
        gap: 12px;
    }
    .all-workout-filters-wrap {
        display: flex;
        align-items: center;
        gap: 6px;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        padding-bottom: 4px;
        max-width: 100%;
    }
    .all-workout-filter-pill {
        white-space: nowrap;
        flex-shrink: 0;
    }
    .all-workouts-search-wrap {
        width: 100% !important;
        max-width: 100% !important;
        flex: 1 1 100% !important;
    }
    .view-plan-header-card {
        flex-direction: column;
        align-items: stretch !important;
        gap: 14px;
    }
    .view-plan-header-card .btn {
        width: 100%;
        text-align: center;
        justify-content: center;
    }
}

@media (max-width: 640px) {
    .workout-main-nav {
        display: flex;
        width: 100%;
        box-sizing: border-box;
    }
    .workout-nav-pill {
        flex: 1 1 0;
        justify-content: center;
        padding: 8px 10px;
        font-size: 12.5px;
        gap: 6px;
    }
    .workout-nav-title-full { display: none; }
    .workout-nav-title-short { display: inline; }
    .workout-nav-badge { padding: 1px 6px; font-size: 10px; }
}

@media (max-width: 480px) {
    #planModal > div {
        padding: 20px 16px !important;
        max-width: 100% !important;
    }
}
</style>

<script>
// Tab Switching Controller
function switchWorkoutView(view) {
    const btnCreate  = document.getElementById('btn-view-create');
    const btnAll     = document.getElementById('btn-view-all');
    const viewCreate = document.getElementById('view-create-workouts');
    const viewAll    = document.getElementById('view-all-workouts');

    if (view === 'all') {
        btnAll?.classList.add('active');
        btnCreate?.classList.remove('active');
        if (viewAll) viewAll.style.display = 'block';
        if (viewCreate) viewCreate.style.display = 'none';
        try { localStorage.setItem('fittracks_workouts_active_tab', 'all'); } catch (e) {}
        try { history.replaceState(null, '', 'index.php?page=training&tab=all'); } catch (e) {}
    } else {
        btnCreate?.classList.add('active');
        btnAll?.classList.remove('active');
        if (viewCreate) viewCreate.style.display = 'block';
        if (viewAll) viewAll.style.display = 'none';
        try { localStorage.setItem('fittracks_workouts_active_tab', 'create'); } catch (e) {}
        try { history.replaceState(null, '', 'index.php?page=training&tab=create'); } catch (e) {}
    }
}

// Client-Side Search & Filter for All Workouts Table
window.activeAllWorkoutsStatusFilter = 'all';

function filterAllWorkouts() {
    const searchInput = document.getElementById('all-workouts-search');
    const q = (searchInput?.value || '').toLowerCase().trim();
    const filter = window.activeAllWorkoutsStatusFilter || 'all';
    const rows = document.querySelectorAll('.all-workout-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const rowSearch = (row.dataset.search || '').toLowerCase();
        const rowStatus = (row.dataset.status || '').toLowerCase();

        const matchesSearch = !q || rowSearch.includes(q);
        const matchesStatus = (filter === 'all') || (rowStatus === filter);

        if (matchesSearch && matchesStatus) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const noResults = document.getElementById('all-workouts-no-results');
    if (noResults) {
        noResults.style.display = (visibleCount === 0) ? 'block' : 'none';
    }
}

function setAllWorkoutsStatusFilter(status, btn) {
    window.activeAllWorkoutsStatusFilter = status;
    document.querySelectorAll('.all-workout-filter-pill').forEach(el => el.classList.remove('active'));
    if (btn) btn.classList.add('active');
    filterAllWorkouts();
}

function updateGoalField(selectElement) {
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const goalInput = selectElement.closest('form').querySelector('input[name="goal"]');
    if (goalInput && selectedOption && selectedOption.dataset.goal) {
        goalInput.value = selectedOption.dataset.goal;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const select = document.querySelector('select[name="member_user_id"]');
    if (select && select.value) {
        updateGoalField(select);
    }

    // Auto-restore saved tab unless specific URL query parameter overrides it
    const urlParams = new URLSearchParams(window.location.search);
    const forcedTab = urlParams.get('tab');
    const hasViewPlan = urlParams.get('view_plan_id');

    if (hasViewPlan || forcedTab === 'all') {
        switchWorkoutView('all');
    } else if (forcedTab === 'create') {
        switchWorkoutView('create');
    } else {
        const saved = localStorage.getItem('fittracks_workouts_active_tab');
        if (saved === 'all') {
            switchWorkoutView('all');
        }
    }
});

// Edit Training Plan Modal (SweetAlert2)
function editPlan(p) {
    let membersOptions = '';
    <?php foreach ($members as $member): ?>
        membersOptions += `<option value="<?= (int) $member['member_user_id'] ?>"><?= h($member['name']) ?></option>`;
    <?php endforeach; ?>
    
    <?php $csrfStr = csrf_field(); ?>

    Swal.fire({
        title: 'Edit Training Plan',
        html: `
            <form id="editPlanForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                <?= $csrfStr ?>
                <input type="hidden" name="action" value="edit_plan">
                <input type="hidden" name="plan_id" id="ep_id">
                <label style="display:block; color: var(--muted); font-size: 13px; font-weight:600;">Member
                    <select name="member_user_id" id="ep_member" class="form-control" style="width: 100%; box-sizing: border-box; margin-top:5px; background:var(--panel); color:var(--ink); border:1px solid var(--line); border-radius:8px; padding:8px 12px;" required>
                        ${membersOptions}
                    </select>
                </label>
                <label style="display:block; color: var(--muted); font-size: 13px; font-weight:600;">Plan Title * 
                    <input name="title" id="ep_title" class="form-control" required style="width: 100%; box-sizing: border-box; margin-top:5px; background:var(--panel); color:var(--ink); border:1px solid var(--line); border-radius:8px; padding:8px 12px;">
                </label>
                <label style="display:block; color: var(--muted); font-size: 13px; font-weight:600;">Goal 
                    <input name="goal" id="ep_goal" class="form-control" style="width: 100%; box-sizing: border-box; margin-top:5px; background:var(--panel); color:var(--ink); border:1px solid var(--line); border-radius:8px; padding:8px 12px;">
                </label>
                <label style="display:block; color: var(--muted); font-size: 13px; font-weight:600;">Start Date * 
                    <input name="start_date" id="ep_start" type="date" class="form-control" required style="width: 100%; box-sizing: border-box; margin-top:5px; background:var(--panel); color:var(--ink); border:1px solid var(--line); border-radius:8px; padding:8px 12px;">
                </label>
                <label style="display:block; color: var(--muted); font-size: 13px; font-weight:600;">End Date 
                    <input name="end_date" id="ep_end" type="date" class="form-control" style="width: 100%; box-sizing: border-box; margin-top:5px; background:var(--panel); color:var(--ink); border:1px solid var(--line); border-radius:8px; padding:8px 12px;">
                </label>
            </form>
        `,
        didOpen: () => {
            document.getElementById('ep_id').value = p.plan_id;
            document.getElementById('ep_member').value = p.member_user_id;
            document.getElementById('ep_title').value = p.title;
            document.getElementById('ep_goal').value = p.goal || '';
            document.getElementById('ep_start').value = p.start_date || '';
            document.getElementById('ep_end').value = p.end_date || '';
        },
        showCancelButton: true,
        confirmButtonText: 'Save Changes',
        confirmButtonColor: 'var(--lime, #c7ff22)',
        cancelButtonColor: 'transparent',
        background: getComputedStyle(document.documentElement).getPropertyValue('--panel-bg').trim() || '#121721',
        color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
        preConfirm: () => {
            const form = document.getElementById('editPlanForm');
            if (!form.title.value || !form.start_date.value) {
                Swal.showValidationMessage('Title and start date are required');
                return false;
            }
            form.submit();
        }
    });
}

// Duplicate Plan Modal (SweetAlert2)
function openDuplicateModal(planId) {
    let membersOptions = '';
    <?php foreach ($members as $member): ?>
        membersOptions += `<option value="<?= (int) $member['member_user_id'] ?>"><?= h($member['name']) ?></option>`;
    <?php endforeach; ?>
    
    <?php $csrfStr = csrf_field(); ?>

    Swal.fire({
        title: 'Duplicate Plan',
        html: `
            <form id="duplicatePlanForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                <?= $csrfStr ?>
                <input type="hidden" name="action" value="duplicate_plan">
                <input type="hidden" name="plan_id" value="${planId}">
                <p style="margin:0; color:var(--ink); font-size:13.5px;">Select the member to clone this workout structure to:</p>
                <label style="display:block; color: var(--muted); font-size: 13px; font-weight:600;">Target Member *
                    <select name="target_member_id" class="form-control" style="width: 100%; box-sizing: border-box; margin-top:5px; background:var(--panel); color:var(--ink); border:1px solid var(--line); border-radius:8px; padding:8px 12px;" required>
                        <option value="">-- Select Target Member --</option>
                        ${membersOptions}
                    </select>
                </label>
            </form>
        `,
        showCancelButton: true,
        confirmButtonText: 'Duplicate as Draft',
        confirmButtonColor: 'var(--lime, #c7ff22)',
        cancelButtonColor: 'transparent',
        background: getComputedStyle(document.documentElement).getPropertyValue('--panel-bg').trim() || '#121721',
        color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#ffffff',
        preConfirm: () => {
            const form = document.getElementById('duplicatePlanForm');
            if (!form.target_member_id.value) {
                Swal.showValidationMessage('Please select a target member');
                return false;
            }
            form.submit();
        }
    });
}
</script>

<?php
    render_footer();
}
