<?php
declare(strict_types=1);

function training_page(): void
{
    $user = require_roles(['trainer', 'gym_owner', 'platform_admin']);
    $coachId = ensure_coach_profile((int) $user['user_id']);
    $memberId = (int) ($_GET['member_user_id'] ?? post('member_user_id', 0));
    $pdo = db();

    $gymId = null;
    if ($user['role'] === 'gym_owner') {
        $gymId = $user['gym_id'] ?? scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]) ?? scalar('SELECT gym_id FROM gyms ORDER BY gym_id ASC LIMIT 1');
    }

    // ----------------------------------------------------
    // POST ACTIONS (Add, Edit, Delete, Duplicate, Renew)
    // ----------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = post('action', '');
        $title = trim((string) post('title'));
        
        if ($action === 'add_plan') {
            $targetMemberId = (int) post('member_user_id', 0);
            if ($targetMemberId <= 0 && $memberId > 0) {
                $targetMemberId = $memberId;
            }
            if ($targetMemberId <= 0 || !scalar('SELECT 1 FROM users WHERE user_id = ?', [$targetMemberId])) {
                flash('Cannot create plan: Please select a valid active member.', 'error');
                redirect('training');
            }
            $pdo->prepare('INSERT INTO training_plans (member_user_id, trainer_id, title, goal, start_date, end_date, status) VALUES (?, ?, ?, ?, NULL, NULL, "draft")')
                ->execute([$targetMemberId, $coachId, $title ?: 'Workout Plan', post('goal')]);
            
            // Go straight to workout builder
            header('Location: index.php?page=workout_builder&member_user_id=' . $targetMemberId);
            exit;
        } elseif ($action === 'edit_plan') {
            $plan_id = (int) post('plan_id');
            $targetMemberId = (int) post('member_user_id', 0);
            if ($targetMemberId <= 0) {
                $targetMemberId = (int) scalar('SELECT member_user_id FROM training_plans WHERE plan_id = ?', [$plan_id]);
            }
            if ($targetMemberId <= 0 || !scalar('SELECT 1 FROM users WHERE user_id = ?', [$targetMemberId])) {
                flash('Cannot update plan: Invalid member reference.', 'error');
                redirect('training');
            }
            $pdo->prepare('UPDATE training_plans SET member_user_id=?, title=?, goal=?, start_date=?, end_date=? WHERE plan_id=?')
                ->execute([$targetMemberId, $title, post('goal'), post('start_date'), post('end_date') ?: null, $plan_id]);
            flash('Training plan updated.', 'success');
        } elseif ($action === 'delete_plan') {
            $plan_id = (int) post('plan_id');
            if ($plan_id > 0) {
                // Determine authorization to delete
                $canDelete = false;
                if ($user['role'] === 'platform_admin') {
                    $canDelete = true;
                } elseif ($user['role'] === 'gym_owner') {
                    $planMember = scalar('SELECT member_user_id FROM training_plans WHERE plan_id = ?', [$plan_id]);
                    $isMemberInGym = $planMember && scalar('SELECT 1 FROM gym_members WHERE user_id = ? AND gym_id = ?', [$planMember, $gymId]);
                    $isTrainerInGym = scalar('SELECT 1 FROM training_plans tp JOIN trainer_profiles tpr ON tp.trainer_id = tpr.trainer_id JOIN gym_members gm ON tpr.user_id = gm.user_id WHERE tp.plan_id = ? AND gm.gym_id = ?', [$plan_id, $gymId]);
                    if ($isMemberInGym || $isTrainerInGym || scalar('SELECT 1 FROM training_plans WHERE plan_id = ? AND trainer_id = ?', [$plan_id, $coachId])) {
                        $canDelete = true;
                    }
                } else {
                    // Trainer can delete their own plans
                    if (scalar('SELECT 1 FROM training_plans WHERE plan_id = ? AND trainer_id = ?', [$plan_id, $coachId])) {
                        $canDelete = true;
                    }
                }

                if ($canDelete) {
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare('DELETE FROM exercise_completions WHERE plan_id = ?')->execute([$plan_id]);
                        $pdo->prepare('DELETE FROM training_plan_exercises WHERE plan_id = ?')->execute([$plan_id]);
                        $pdo->prepare('DELETE FROM training_plans WHERE plan_id = ?')->execute([$plan_id]);
                        $pdo->commit();
                        flash('Training plan permanently deleted.', 'info');
                    } catch (\Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        flash('Failed to delete training plan: ' . $e->getMessage(), 'error');
                    }
                } else {
                    flash('You are not authorized to delete this training plan.', 'error');
                }
            }
        } elseif ($action === 'duplicate_plan') {
            $source_plan_id = (int) post('plan_id');
            $target_member_id = (int) post('target_member_id', 0);
            if ($target_member_id <= 0 || !scalar('SELECT 1 FROM users WHERE user_id = ?', [$target_member_id])) {
                flash('Cannot duplicate plan: Please select a valid target member.', 'error');
                redirect('training');
            }
            
            $source_plan = $pdo->prepare('SELECT title, goal FROM training_plans WHERE plan_id = ?');
            $source_plan->execute([$source_plan_id]);
            $planData = $source_plan->fetch();
            
            if ($planData) {
                // Get active membership for target
                $membership = $pdo->query('SELECT start_date, end_date FROM memberships WHERE user_id = ' . $target_member_id . ' AND status = "active" ORDER BY end_date DESC LIMIT 1')->fetch();
                $startDate = $membership ? $membership['start_date'] : date('Y-m-d');
                $endDate = $membership ? $membership['end_date'] : date('Y-m-d', strtotime('+4 weeks'));
                
                $newTitle = $planData['title'] . ' (Copy)';
                
                $pdo->prepare('INSERT INTO training_plans (member_user_id, trainer_id, title, goal, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, "draft")')
                    ->execute([$target_member_id, $coachId, $newTitle, $planData['goal'], $startDate, $endDate]);
                $newPlanId = $pdo->lastInsertId();
                
                $pdo->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, target_weight_kg, rest_seconds, notes, tempo, rpe) 
                               SELECT ?, exercise_id, day_of_week, sequence_order, sets, reps, target_weight_kg, rest_seconds, notes, tempo, rpe FROM training_plan_exercises WHERE plan_id = ?')
                    ->execute([$newPlanId, $source_plan_id]);
                    
                flash('Training plan duplicated successfully as a draft.', 'success');
            }
        } elseif ($action === 'renew_plan') {
            $source_plan_id = (int) post('plan_id');
            
            $source_plan = $pdo->prepare('SELECT * FROM training_plans WHERE plan_id = ?');
            $source_plan->execute([$source_plan_id]);
            $planData = $source_plan->fetch();
            
            if ($planData) {
                $renewMemberId = (int) $planData['member_user_id'];
                if ($renewMemberId <= 0 || !scalar('SELECT 1 FROM users WHERE user_id = ?', [$renewMemberId])) {
                    flash('Cannot renew plan: Associated member account is not found.', 'error');
                    redirect('training');
                }
                $oldEndDate = strtotime($planData['end_date'] ?? date('Y-m-d'));
                $newStart = date('Y-m-d', strtotime('+1 day', $oldEndDate));
                if ($newStart < date('Y-m-d')) {
                    $newStart = date('Y-m-d');
                }
                $newEnd = date('Y-m-d', strtotime('+4 weeks', strtotime($newStart)));
                
                $pdo->prepare('INSERT INTO training_plans (member_user_id, trainer_id, title, goal, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, "draft")')
                    ->execute([$renewMemberId, $coachId, $planData['title'] . ' (Phase 2)', $planData['goal'], $newStart, $newEnd]);
                $newPlanId = $pdo->lastInsertId();
                
                $pdo->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, target_weight_kg, rest_seconds, notes, tempo, rpe) 
                               SELECT ?, exercise_id, day_of_week, sequence_order, sets, reps, target_weight_kg, rest_seconds, notes, tempo, rpe FROM training_plan_exercises WHERE plan_id = ?')
                    ->execute([$newPlanId, $source_plan_id]);
                    
                flash('Training plan renewed as a new draft Phase 2.', 'success');
            }
        }
        $returnTab = trim((string) post('return_tab', ''));
        if ($returnTab) {
            redirect('training&tab=' . urlencode($returnTab));
        }
        redirect('training');
    }

    // ----------------------------------------------------
    // TAB 1 DATA: Eligible Members & Created Plans
    // ----------------------------------------------------
    if ($user['role'] === 'gym_owner' && $gymId) {
        $members = query_all('SELECT gm.user_id AS member_user_id, u.first_name, u.last_name, u.email, u.profile_picture, CONCAT(u.first_name, " ", u.last_name) AS name, mp.primary_goal, EXISTS (SELECT 1 FROM memberships m WHERE m.user_id = u.user_id AND m.status = "active" AND m.end_date >= CURRENT_DATE) AS has_membership FROM gym_members gm JOIN users u ON u.user_id = gm.user_id LEFT JOIN member_profiles mp ON mp.user_id = u.user_id WHERE gm.gym_id = ? AND u.status = "active" AND NOT EXISTS (SELECT 1 FROM training_plans tp WHERE tp.member_user_id = gm.user_id AND tp.trainer_id = ? AND tp.status IN ("active", "draft"))', [$gymId, $coachId]);
        $allGymMembers = query_all('SELECT gm.user_id AS member_user_id, u.first_name, u.last_name, u.email, u.profile_picture, CONCAT(u.first_name, " ", u.last_name) AS name FROM gym_members gm JOIN users u ON u.user_id = gm.user_id WHERE gm.gym_id = ? AND u.status = "active" ORDER BY u.first_name ASC', [$gymId]);
    } elseif ($user['role'] === 'trainer') {
        $members = query_all('SELECT ca.member_user_id, u.first_name, u.last_name, u.email, u.profile_picture, CONCAT(u.first_name, " ", u.last_name) AS name, mp.primary_goal, EXISTS (SELECT 1 FROM memberships m WHERE m.user_id = u.user_id AND m.status = "active" AND m.end_date >= CURRENT_DATE) AS has_membership FROM trainer_assignments ca JOIN users u ON u.user_id = ca.member_user_id LEFT JOIN member_profiles mp ON mp.user_id = u.user_id WHERE ca.trainer_id = ? AND ca.status = "active" AND NOT EXISTS (SELECT 1 FROM training_plans tp WHERE tp.member_user_id = ca.member_user_id AND tp.trainer_id = ca.trainer_id AND tp.status IN ("active", "draft"))', [$coachId]);
        $allGymMembers = query_all('SELECT ca.member_user_id, u.first_name, u.last_name, u.email, u.profile_picture, CONCAT(u.first_name, " ", u.last_name) AS name FROM trainer_assignments ca JOIN users u ON u.user_id = ca.member_user_id WHERE ca.trainer_id = ? AND ca.status = "active" ORDER BY u.first_name ASC', [$coachId]);
    } else {
        $members = query_all('SELECT u.user_id AS member_user_id, u.first_name, u.last_name, u.email, u.profile_picture, CONCAT(u.first_name, " ", u.last_name) AS name, mp.primary_goal, 1 AS has_membership FROM users u LEFT JOIN member_profiles mp ON mp.user_id = u.user_id WHERE u.role = "member" AND u.status = "active" LIMIT 100');
        $allGymMembers = query_all('SELECT u.user_id AS member_user_id, u.first_name, u.last_name, u.email, u.profile_picture, CONCAT(u.first_name, " ", u.last_name) AS name FROM users u WHERE u.role = "member" AND u.status = "active" ORDER BY u.first_name ASC LIMIT 100');
    }

    $planMembersData = array_map(function($m) {
        $first = trim((string)($m['first_name'] ?? ''));
        $last  = trim((string)($m['last_name'] ?? ''));
        $name  = trim($first . ' ' . $last) ?: ($m['name'] ?? 'Member');
        $ini   = (!empty($first) ? strtoupper(substr($first, 0, 1)) : '') . (!empty($last) ? strtoupper(substr($last, 0, 1)) : '');
        $statusText = empty($m['has_membership']) ? 'No Membership' : 'Active Member';
        $sub = !empty($m['email']) ? ($m['email'] . ' • ' . $statusText) : $statusText;
        return [
            'id'       => (int) $m['member_user_id'],
            'label'    => $name,
            'subtitle' => $sub,
            'initials' => $ini ?: 'M',
            'avatar'   => !empty($m['profile_picture']) ? upload_url($m['profile_picture']) : null,
            'goal'     => (string) ($m['primary_goal'] ?? '')
        ];
    }, $members ?: []);

    $plans = query_all('
        SELECT tp.*, 
               CONCAT(u.first_name, " ", u.last_name) AS member,
               u.profile_picture AS member_pic,
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
            <?php if (!empty($plans)): ?>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('planModal').style.display='flex'" style="display:inline-flex; align-items:center; gap:6px; font-weight:700;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <span>Add Plan</span>
            </button>
            <?php endif; ?>
        </div>

        <?php if (empty($plans)): ?>
            <div style="padding: 40px 20px; text-align: center; color: var(--muted); background: color-mix(in srgb, var(--surface) 60%, var(--bg)); border-radius: 10px; border: 1px dashed var(--line);">
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="opacity:0.6; margin-bottom:10px;"><path d="M6 4v16"/><path d="M10 4v16"/><path d="M6 12h4"/><path d="M14 4v16"/><path d="M18 4v16"/><path d="M14 12h4"/></svg>
                <p style="margin:0 0 8px 0; font-weight:700; color:var(--ink);">No training plans created yet</p>
                <p style="margin:0 0 16px 0; font-size:13px;">Assign a new routine to a member and build their exercise schedule.</p>
                <button type="button" class="btn btn-primary" onclick="document.getElementById('planModal').style.display='flex'">+ Add First Plan</button>
            </div>
        <?php else: ?>
            <?php
            $csrfStr = csrf_field();
            $tableRows = array_map(function($p) use ($csrfStr) {
                $safeJson = htmlspecialchars(json_encode($p));
                $isDraft = ($p['status'] === 'draft');
                $titleAttr = addslashes(h($p['title'] ?: 'Workout Plan'));
                
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
                    
                    $renewBtn = ($daysLeft <= 3) ? '<button type="button" class="btn btn-primary btn-renew-plan" data-plan-id="'.$p['plan_id'].'" data-plan-title="'.h($p['title'] ?: 'Workout Plan').'" style="padding:4px 8px;font-size:12px;margin-right:4px;">Renew</button>' : '';
                    $buildBtn = '';
                }
                
                $feedbackStr = $p['latest_feedback'] ? h(substr($p['latest_feedback'], 0, 30)) . (strlen($p['latest_feedback']) > 30 ? '...' : '') : '<i style="color:var(--muted);">None</i>';
                $p['feedback'] = '<span style="font-size:12px; display:inline-block; max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">' . $feedbackStr . '</span>';
                
                $duplicateBtn = '<button type="button" class="btn btn-secondary btn-duplicate-plan" data-plan-id="'.$p['plan_id'].'" data-plan-title="'.h($p['title'] ?: 'Workout Plan').'" style="padding:4px 8px;font-size:12px;margin-right:4px;">Duplicate</button>';
                
                $deleteBtn = '<button type="button" class="btn btn-danger btn-delete-plan" data-plan-id="'.$p['plan_id'].'" data-plan-title="'.h($p['title'] ?: 'Workout Plan').'" style="padding:4px 8px;font-size:12px;display:inline-flex;align-items:center;gap:4px;">' .
                             '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>' .
                             '<span>Delete</span></button>';

                $p['actions'] = '<div style="display:flex; gap:4px; flex-wrap:wrap; align-items:center;">' . 
                                $renewBtn . $buildBtn . $duplicateBtn . 
                                '<button type="button" onclick="editPlan(' . $safeJson . ')" class="btn btn-secondary" style="padding:4px 8px;font-size:12px;margin-right:4px;">Edit</button>' .
                                $deleteBtn . '</div>';
                return $p;
            }, $plans);
            ?>

            <!-- Desktop View: Standard Rich Table -->
            <div class="plans-desktop-table">
                <?= render_simple_table($tableRows, ['member', 'title', 'start_date', 'end_date', 'status', 'progress', 'adherence', 'feedback', 'actions']) ?>
            </div>

            <!-- Mobile View: Modern Optimized Cards -->
            <div class="training-plans-mobile-cards">
                <?php foreach ($plans as $p): 
                    $safeJson = htmlspecialchars(json_encode($p));
                    $isDraft = ($p['status'] === 'draft');
                    $statusKey = strtolower((string)$p['status']);
                    
                    $startDate = !empty($p['start_date']) ? date('M j, Y', strtotime($p['start_date'])) : 'N/A';
                    $endDate = !empty($p['end_date']) ? date('M j, Y', strtotime($p['end_date'])) : 'N/A';
                    
                    $daysLeft = null;
                    $isExpiring = false;
                    $isExpired = false;
                    $elapsedWeeks = 0;
                    $totalWeeks = 0;
                    $adherencePercent = 0;
                    
                    if (!$isDraft && !empty($p['start_date']) && !empty($p['end_date'])) {
                        $startTs = strtotime($p['start_date']);
                        $endTs = strtotime($p['end_date']);
                        $now = time();
                        $totalWeeks = max(1, (int) ceil(($endTs - $startTs) / (7 * 86400)));
                        $elapsedWeeks = max(1, (int) ceil(($now - $startTs) / (7 * 86400)));
                        if ($now < $startTs) $elapsedWeeks = 0;
                        if ($now > $endTs) $elapsedWeeks = $totalWeeks;
                        
                        $expectedTotal = (int)($p['expected_weekly'] ?? 0) * $elapsedWeeks;
                        $completed = (int)($p['completed_count'] ?? 0);
                        $adherencePercent = $expectedTotal > 0 ? min(100, round(($completed / $expectedTotal) * 100)) : ($elapsedWeeks > 0 ? 0 : 100);
                        
                        $daysLeft = ($endTs - $now) / 86400;
                        $isExpiring = ($daysLeft <= 3 && $daysLeft >= 0);
                        $isExpired = ($daysLeft < 0);
                    }
                ?>
                    <div class="trainer-mcard">
                        <div class="trainer-mcard-header">
                            <div class="trainer-mcard-user">
                                <?php if (!empty($p['member_pic'])): ?>
                                    <img src="<?= h(upload_url($p['member_pic'])) ?>" alt="Member" class="trainer-mcard-avatar" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <div class="trainer-mcard-initial"><?= h(substr($p['member'] ?? 'M', 0, 1)) ?></div>
                                <?php endif; ?>
                                <div>
                                    <div class="trainer-mcard-name"><?= h($p['member']) ?></div>
                                    <div class="trainer-mcard-sub"><?= h($p['title'] ?: 'Workout Routine') ?></div>
                                </div>
                            </div>
                            <div>
                                <?php if ($statusKey === 'active'): ?>
                                    <span class="badge badge-active">Active</span>
                                <?php elseif ($statusKey === 'draft'): ?>
                                    <span class="badge badge-draft">Draft</span>
                                <?php else: ?>
                                    <span class="badge badge-other"><?= h(ucfirst($statusKey)) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($p['goal'])): ?>
                            <div class="trainer-mcard-goal">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                                <span>Goal: <strong><?= h(ucwords(str_replace('_', ' ', (string)$p['goal']))) ?></strong></span>
                            </div>
                        <?php endif; ?>

                        <div class="trainer-mcard-grid">
                            <div class="trainer-mcard-stat">
                                <span class="mcard-stat-lbl">Start Date</span>
                                <span class="mcard-stat-val"><?= $startDate ?></span>
                            </div>
                            <div class="trainer-mcard-stat">
                                <span class="mcard-stat-lbl">End Date</span>
                                <span class="mcard-stat-val">
                                    <?= $endDate ?>
                                    <?php if ($isExpiring): ?>
                                        <span style="color:#f59e0b; font-size:11px; font-weight:700;">(Expiring)</span>
                                    <?php elseif ($isExpired): ?>
                                        <span style="color:var(--danger, #ef4444); font-size:11px; font-weight:700;">(Expired)</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php if (!$isDraft): ?>
                                <div class="trainer-mcard-stat">
                                    <span class="mcard-stat-lbl">Progress</span>
                                    <span class="mcard-stat-val">Week <?= $elapsedWeeks ?> of <?= $totalWeeks ?></span>
                                </div>
                                <div class="trainer-mcard-stat">
                                    <span class="mcard-stat-lbl">Adherence</span>
                                    <div style="display:flex; align-items:center; gap:6px; margin-top:3px;">
                                        <div style="flex:1; background:rgba(128,128,128,0.2); height:6px; border-radius:3px; overflow:hidden;">
                                            <div style="width:<?= $adherencePercent ?>%; background:var(--lime); height:100%;"></div>
                                        </div>
                                        <span style="font-size:11.5px; font-weight:700; color:var(--ink);"><?= $adherencePercent ?>%</span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($p['latest_feedback'])): ?>
                            <div class="trainer-mcard-feedback">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0; margin-top:2px;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                <span>"<?= h($p['latest_feedback']) ?>"</span>
                            </div>
                        <?php endif; ?>

                        <div class="trainer-mcard-actions">
                            <?php if ($isDraft): ?>
                                <form method="get" action="index.php" style="flex:1 1 120px;">
                                    <input type="hidden" name="page" value="workout_builder">
                                    <input type="hidden" name="member_user_id" value="<?= (int)$p['member_user_id'] ?>">
                                    <button type="submit" class="btn btn-primary btn-mcard" style="width:100%;">Build Workout</button>
                                </form>
                            <?php elseif ($daysLeft !== null && $daysLeft <= 3): ?>
                                <button type="button" class="btn btn-primary btn-mcard btn-renew-plan" data-plan-id="<?= (int)$p['plan_id'] ?>" data-plan-title="<?= h($p['title'] ?: 'Workout Routine') ?>" style="flex:1 1 100px;">Renew</button>
                            <?php endif; ?>

                            <button type="button" class="btn btn-secondary btn-mcard btn-duplicate-plan" data-plan-id="<?= (int)$p['plan_id'] ?>" data-plan-title="<?= h($p['title'] ?: 'Workout Routine') ?>">Duplicate</button>
                            <button type="button" onclick="editPlan(<?= $safeJson ?>)" class="btn btn-secondary btn-mcard">Edit</button>
                            <button type="button" class="btn btn-danger btn-mcard btn-delete-plan" data-plan-id="<?= (int)$p['plan_id'] ?>" data-plan-title="<?= h($p['title'] ?: 'Workout Routine') ?>" style="display:inline-flex; align-items:center; gap:5px;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                <span>Delete</span>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
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
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <a href="index.php?page=workout_builder&member_user_id=<?= (int)$viewPlan['member_user_id'] ?>" class="btn btn-primary" style="font-size: 13px; font-weight: 700; padding: 8px 16px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; min-height: 38px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                        <span>Open in Workout Builder</span>
                    </a>
                    <button type="button" class="btn btn-danger btn-delete-plan" data-plan-id="<?= (int)$viewPlanId ?>" data-plan-title="<?= h($viewPlan['title'] ?: 'Workout Routine') ?>" style="font-size: 13px; font-weight: 700; padding: 8px 16px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; min-height: 38px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        <span>Delete Plan</span>
                    </button>
                </div>
            </div>
        </div>

        <?php render_current_workout((int) $viewPlan['member_user_id'], false, $viewPlanId); ?>

    <?php else: ?>
        <!-- All Workout Plans Directory -->
        <section class="panel">
            <div class="all-workouts-header-wrap" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:12px;">
                <div>
                    <h2 style="margin:0; font-size:1.25rem; color:var(--ink);">All Workout Plans</h2>
                    <p class="muted" style="margin:4px 0 0 0; font-size:13px;">Complete directory of workout regimens created for members.</p>
                </div>
                <div class="all-workouts-toolbar">
                    <div class="all-workout-filters-wrap" id="all-workouts-filters">
                        <button type="button" class="all-workout-filter-pill active" onclick="setAllWorkoutsStatusFilter('all', this)">All (<?= count($allPlans) ?>)</button>
                        <button type="button" class="all-workout-filter-pill" onclick="setAllWorkoutsStatusFilter('active', this)">Active (<?= count(array_filter($allPlans, fn($p) => strtolower((string)$p['status']) === 'active')) ?>)</button>
                        <button type="button" class="all-workout-filter-pill" onclick="setAllWorkoutsStatusFilter('draft', this)">Draft (<?= count(array_filter($allPlans, fn($p) => strtolower((string)$p['status']) === 'draft')) ?>)</button>
                        <button type="button" class="all-workout-filter-pill" onclick="setAllWorkoutsStatusFilter('other', this)">Expired / Other (<?= count(array_filter($allPlans, fn($p) => !in_array(strtolower((string)$p['status']), ['active', 'draft'], true))) ?>)</button>
                    </div>
                    <div class="all-workouts-search-wrap">
                        <input type="text" id="all-workouts-search" oninput="filterAllWorkouts()" placeholder="Search member, trainer, or plan title..." style="width:100%; box-sizing:border-box; padding:7px 12px 7px 32px; font-size:12.5px; border-radius:8px; border:1px solid var(--line); background:var(--panel); color:var(--ink);">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--muted); pointer-events:none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    </div>
                </div>
            </div>

            <?php if (empty($allPlans)): ?>
                <div style="padding: 40px 20px; text-align: center; color: var(--muted); background: color-mix(in srgb, var(--surface) 60%, var(--bg)); border-radius: 10px; border: 1px dashed var(--line);">
                    <p style="margin:0; font-style: italic;">No workout plans recorded in the system yet.</p>
                </div>
            <?php else: ?>
                <!-- Desktop View: Standard Directory Table -->
                <div class="all-workouts-desktop-table table-responsive">
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
                                            <span class="badge badge-active">Active</span>
                                        <?php elseif ($statusKey === 'draft'): ?>
                                            <span class="badge badge-draft">Draft</span>
                                        <?php else: ?>
                                            <span class="badge badge-other"><?= h(ucfirst($statusKey)) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:12px; font-size:12.5px; color:var(--muted);">
                                        <?= (int) $row['exercise_count'] ?> movements
                                    </td>
                                    <td style="padding:12px; font-size:12px; color:var(--muted); white-space:nowrap;">
                                        <?= $row['start_date'] ? date('M j, Y', strtotime($row['start_date'])) : 'Pending' ?>
                                    </td>
                                    <td style="padding:12px; text-align:right;">
                                        <div style="display:inline-flex; gap:6px; justify-content:flex-end; align-items:center;">
                                            <a href="index.php?page=training&tab=all&view_plan_id=<?= $row['plan_id'] ?>" class="btn btn-secondary" style="padding:5px 10px; font-size:12px; display:inline-flex; align-items:center; gap:4px;">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                                                <span>View Plan</span>
                                            </a>
                                            <?php if ($statusKey === 'draft'): ?>
                                                <a href="index.php?page=workout_builder&member_user_id=<?= $row['member_user_id'] ?>" class="btn btn-primary" style="padding:5px 10px; font-size:12px;">
                                                    Build
                                                </a>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-danger btn-delete-plan" data-plan-id="<?= (int)$row['plan_id'] ?>" data-plan-title="<?= h($row['title'] ?: 'Workout Routine') ?>" style="padding:5px 10px; font-size:12px; display:inline-flex; align-items:center; gap:4px;" title="Delete Plan">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                                <span>Delete</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile View: Modern Optimized Cards (Search & Filter Compatible) -->
                <div class="all-workouts-mobile-cards" id="all-workouts-mobile-cards">
                    <?php foreach ($allPlans as $row): 
                        $statusKey = strtolower((string)$row['status']);
                        $filterStatusCategory = in_array($statusKey, ['active', 'draft'], true) ? $statusKey : 'other';
                        $searchBlob = strtolower($row['member'] . ' ' . $row['trainer'] . ' ' . $row['title'] . ' ' . $row['goal'] . ' #' . $row['plan_id']);
                    ?>
                        <div class="all-workout-card all-workout-row" data-status="<?= h($filterStatusCategory) ?>" data-search="<?= h($searchBlob) ?>">
                            <div class="trainer-mcard-header">
                                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                    <span class="mcard-plan-id">#<?= h((string)$row['plan_id']) ?></span>
                                    <span class="mcard-plan-title"><?= h($row['title'] ?: 'Workout Plan') ?></span>
                                </div>
                                <div>
                                    <?php if ($statusKey === 'active'): ?>
                                        <span class="badge badge-active">Active</span>
                                    <?php elseif ($statusKey === 'draft'): ?>
                                        <span class="badge badge-draft">Draft</span>
                                    <?php else: ?>
                                        <span class="badge badge-other"><?= h(ucfirst($statusKey)) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!empty($row['goal'])): ?>
                                <div class="trainer-mcard-goal">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                                    <span>Goal: <strong><?= h(ucwords(str_replace('_', ' ', (string)$row['goal']))) ?></strong></span>
                                </div>
                            <?php endif; ?>

                            <div class="trainer-mcard-grid">
                                <div class="trainer-mcard-stat">
                                    <span class="mcard-stat-lbl">Member</span>
                                    <div style="display:flex; align-items:center; gap:6px; margin-top:2px;">
                                        <?php if ($row['member_pic']): ?>
                                            <img src="<?= h(upload_url($row['member_pic'])) ?>" alt="Member" style="width:20px; height:20px; border-radius:50%; object-fit:cover;" loading="lazy" decoding="async">
                                        <?php else: ?>
                                            <div style="width:20px; height:20px; border-radius:50%; background:var(--line); display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:700; color:var(--muted);"><?= h(substr($row['member'] ?? 'M', 0, 1)) ?></div>
                                        <?php endif; ?>
                                        <span style="font-weight:600; color:var(--ink); font-size:13px;"><?= h($row['member']) ?></span>
                                    </div>
                                </div>
                                <div class="trainer-mcard-stat">
                                    <span class="mcard-stat-lbl">Trainer</span>
                                    <div style="display:flex; align-items:center; gap:6px; margin-top:2px;">
                                        <?php if ($row['trainer_pic']): ?>
                                            <img src="<?= h(upload_url($row['trainer_pic'])) ?>" alt="Trainer" style="width:20px; height:20px; border-radius:50%; object-fit:cover;" loading="lazy" decoding="async">
                                        <?php else: ?>
                                            <div style="width:20px; height:20px; border-radius:50%; background:var(--line); display:flex; align-items:center; justify-content:center; font-size:9px; color:var(--muted);"><?= h(substr($row['trainer'] ?? 'T', 0, 1)) ?></div>
                                        <?php endif; ?>
                                        <span style="font-size:12.5px; color:var(--muted);"><?= h($row['trainer'] ?: 'Assigned Coach') ?></span>
                                    </div>
                                </div>
                                <div class="trainer-mcard-stat">
                                    <span class="mcard-stat-lbl">Exercises</span>
                                    <span class="mcard-stat-val"><?= (int)$row['exercise_count'] ?> movements</span>
                                </div>
                                <div class="trainer-mcard-stat">
                                    <span class="mcard-stat-lbl">Start Date</span>
                                    <span class="mcard-stat-val"><?= $row['start_date'] ? date('M j, Y', strtotime($row['start_date'])) : 'Pending' ?></span>
                                </div>
                            </div>

                            <div class="trainer-mcard-actions">
                                <a href="index.php?page=training&tab=all&view_plan_id=<?= (int)$row['plan_id'] ?>" class="btn btn-secondary btn-mcard" style="flex:1 1 110px; justify-content:center; display:inline-flex; align-items:center; gap:6px;">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                                    <span>View Plan</span>
                                </a>
                                <?php if ($statusKey === 'draft'): ?>
                                    <a href="index.php?page=workout_builder&member_user_id=<?= (int)$row['member_user_id'] ?>" class="btn btn-primary btn-mcard" style="flex:1 1 80px; justify-content:center; text-align:center;">
                                        Build
                                    </a>
                                <?php endif; ?>
                                <button type="button" class="btn btn-danger btn-mcard btn-delete-plan" data-plan-id="<?= (int)$row['plan_id'] ?>" data-plan-title="<?= h($row['title'] ?: 'Workout Routine') ?>" style="flex:1 1 80px; justify-content:center; display:inline-flex; align-items:center; gap:5px;">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                    <span>Delete</span>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
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
<div id="planModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);backdrop-filter:blur(5px);align-items:center;justify-content:center;z-index:10000;padding:1rem;">
    <div style="background:var(--panel);padding:24px 28px;border-radius:14px;width:100%;max-width:460px;box-shadow:0 24px 50px rgba(0,0,0,0.4);border:1px solid var(--line);position:relative;overflow:visible;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
            <div>
                <h2 style="margin:0; font-size:1.35rem; font-weight:700; color:var(--ink)">Add Training Plan</h2>
                <p class="muted" style="margin:2px 0 0 0; font-size:12.5px;">Assign a new workout regimen to a gym member</p>
            </div>
            <button type="button" onclick="document.getElementById('planModal').style.display='none'" style="background:transparent;border:none;color:var(--muted);font-size:1.5rem;cursor:pointer;padding:0;line-height:1;transition:color 0.2s;" onmouseover="this.style.color='var(--ink)'" onmouseout="this.style.color='var(--muted)'">&times;</button>
        </div>
        <form id="addPlanForm" method="post" class="form" style="display:flex;flex-direction:column;gap:14px;position:relative;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_plan">
            <div>
                <label style="display:block; color:var(--muted); font-size:13px; font-weight:600; margin-bottom:6px;">Member *</label>
                <div id="planMemberWrap"></div>
            </div>
            <label style="display:block; color:var(--muted); font-size:13px; font-weight:600">Plan Title 
                <input name="title" class="form-control" placeholder="e.g., Personalized Strength & Hypertrophy" style="width:100%;box-sizing:border-box;margin-top:5px;background:var(--panel);color:var(--ink);border:1.5px solid var(--line);border-radius:8px;padding:9px 12px;" required>
            </label>
            <label style="display:block; color:var(--muted); font-size:13px; font-weight:600">Goal 
                <input id="planGoalInput" name="goal" class="form-control" placeholder="e.g., muscle_gain, fat_loss" style="width:100%;box-sizing:border-box;margin-top:5px;background:var(--panel);color:var(--ink);border:1.5px solid var(--line);border-radius:8px;padding:9px 12px;">
            </label>
            <div style="display:flex;justify-content:center;align-items:center;gap:12px;margin-top:14px;padding-top:14px;border-top:1px solid var(--line);flex-wrap:wrap;">
                <button type="button" onclick="document.getElementById('planModal').style.display='none'" class="btn btn-secondary" style="background:var(--panel-soft);color:var(--ink);border:1px solid var(--line);padding:9px 22px;border-radius:8px;font-size:13px;font-weight:600;min-height:42px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="padding:9px 24px;border-radius:8px;font-size:13px;font-weight:700;min-height:42px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;">Create & Build Plan</button>
            </div>
        </form>
    </div>
</div>

<link rel="stylesheet" href="<?= h(asset_url('css/pages/training.css')) ?>">

<!-- Training Dashboard Configuration & Script -->
<script>
window.TRAINING_CONFIG = {
    csrfToken: <?= json_encode(csrf_token()) ?>,
    planMembersData: <?= json_encode($planMembersData ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    allGymMembers: <?= json_encode(array_values(array_map(function($m) {
        return [
            'member_user_id' => (int) $m['member_user_id'],
            'name' => $m['name']
        ];
    }, $allGymMembers ?? [])), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
};
</script>
<script src="<?= h(asset_url('js/pages/training.js')) ?>"></script>

<?php
    render_footer();
}
