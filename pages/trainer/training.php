<?php
declare(strict_types=1);

function training_page(): void
{
    $user = require_roles(['trainer']);
    $coachId = ensure_coach_profile((int) $user['user_id']);
    $memberId = (int) ($_GET['member_user_id'] ?? post('member_user_id', 0));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action', 'add_plan');
        $title = trim((string) post('title'));
        
        if ($action === 'add_plan') {
            db()->prepare('INSERT INTO training_plans (member_user_id, trainer_id, title, goal, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, "draft")')
                ->execute([$memberId, $coachId, $title, post('goal'), post('start_date'), post('end_date') ?: null]);
            
            // Go straight to workout builder
            redirect('workout_builder&member_user_id=' . $memberId);
        } elseif ($action === 'edit_plan') {
            $plan_id = (int) post('plan_id');
            db()->prepare('UPDATE training_plans SET member_user_id=?, title=?, goal=?, start_date=?, end_date=? WHERE plan_id=? AND trainer_id=?')
                ->execute([$memberId, $title, post('goal'), post('start_date'), post('end_date') ?: null, $plan_id, $coachId]);
            flash('Training plan updated.');
        } elseif ($action === 'delete_plan') {
            $plan_id = (int) post('plan_id');
            db()->prepare('DELETE FROM training_plans WHERE plan_id=? AND trainer_id=?')->execute([$plan_id, $coachId]);
            flash('Training plan deleted.');
        } elseif ($action === 'duplicate_plan') {
            $source_plan_id = (int) post('plan_id');
            $target_member_id = (int) post('target_member_id');
            
            $source_plan = db()->prepare('SELECT title, goal FROM training_plans WHERE plan_id = ? AND trainer_id = ?');
            $source_plan->execute([$source_plan_id, $coachId]);
            $planData = $source_plan->fetch();
            
            if ($planData && $target_member_id) {
                // Get active membership for target
                $membership = db()->query('SELECT start_date, end_date FROM memberships WHERE user_id = ' . $target_member_id . ' AND status = "active" ORDER BY end_date DESC LIMIT 1')->fetch();
                $startDate = $membership ? $membership['start_date'] : date('Y-m-d');
                $endDate = $membership ? $membership['end_date'] : date('Y-m-d', strtotime('+4 weeks'));
                
                $newTitle = $planData['title'] . ' (Copy)';
                
                db()->prepare('INSERT INTO training_plans (member_user_id, trainer_id, title, goal, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, "draft")')
                    ->execute([$target_member_id, $coachId, $newTitle, $planData['goal'], $startDate, $endDate]);
                $newPlanId = db()->lastInsertId();
                
                db()->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, rest_seconds) 
                               SELECT ?, exercise_id, day_of_week, sequence_order, sets, reps, rest_seconds FROM training_plan_exercises WHERE plan_id = ?')
                    ->execute([$newPlanId, $source_plan_id]);
                    
                flash('Training plan duplicated successfully as a draft.', 'success');
            }
        } elseif ($action === 'renew_plan') {
            $source_plan_id = (int) post('plan_id');
            
            $source_plan = db()->prepare('SELECT * FROM training_plans WHERE plan_id = ? AND trainer_id = ?');
            $source_plan->execute([$source_plan_id, $coachId]);
            $planData = $source_plan->fetch();
            
            if ($planData) {
                $oldEndDate = strtotime($planData['end_date'] ?? date('Y-m-d'));
                $newStart = date('Y-m-d', strtotime('+1 day', $oldEndDate));
                if ($newStart < date('Y-m-d')) {
                    $newStart = date('Y-m-d');
                }
                $newEnd = date('Y-m-d', strtotime('+4 weeks', strtotime($newStart)));
                
                db()->prepare('INSERT INTO training_plans (member_user_id, trainer_id, title, goal, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, "draft")')
                    ->execute([$planData['member_user_id'], $coachId, $planData['title'] . ' (Phase 2)', $planData['goal'], $newStart, $newEnd]);
                $newPlanId = db()->lastInsertId();
                
                db()->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, rest_seconds) 
                               SELECT ?, exercise_id, day_of_week, sequence_order, sets, reps, rest_seconds FROM training_plan_exercises WHERE plan_id = ?')
                    ->execute([$newPlanId, $source_plan_id]);
                    
                flash('Training plan renewed as a new draft Phase 2.', 'success');
            }
        }
        redirect('training');
    }
    $members = query_all('SELECT ca.member_user_id, CONCAT(u.first_name, " ", u.last_name) AS name, mp.primary_goal FROM trainer_assignments ca JOIN users u ON u.user_id = ca.member_user_id LEFT JOIN member_profiles mp ON mp.user_id = u.user_id WHERE ca.trainer_id = ? AND ca.status = "active" AND NOT EXISTS (SELECT 1 FROM training_plans tp WHERE tp.member_user_id = ca.member_user_id AND tp.trainer_id = ca.trainer_id AND tp.status IN ("active", "draft"))', [$coachId]);
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
    render_header('Training', $user);
    ?>
    <div class="skeleton-wrapper">
        <section class="panel">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
                <div class="sk sk-title" style="width:200px;margin-bottom:8px"></div>
                <div class="sk sk-rect" style="width:100px;height:36px;border-radius:18px"></div>
            </div>
            <?php render_skeleton_table(10, 4); ?>
        </section>
    </div>
    <section class="panel skeleton-content sk-display-block">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
            <h1 style="margin:0">Training plans</h1>
            <button type="button" onclick="document.getElementById('planModal').style.display='flex'">+ Add Plan</button>
        </div>
        <?php
        $csrfStr = csrf_field();
        $tableRows = array_map(function($p) use ($csrfStr) {
            $safeJson = htmlspecialchars(json_encode($p));
            
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
            
            $feedbackStr = $p['latest_feedback'] ? h(substr($p['latest_feedback'], 0, 30)) . (strlen($p['latest_feedback']) > 30 ? '...' : '') : '<i style="color:var(--muted);">None</i>';
            $p['feedback'] = '<span style="font-size:12px; display:inline-block; max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">' . $feedbackStr . '</span>';
            
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
                                           
            $duplicateBtn = '<button type="button" onclick="openDuplicateModal('.$p['plan_id'].')" class="btn btn-secondary" style="padding:4px 8px;font-size:12px;margin-right:4px;">Duplicate</button>';
            
            $p['actions'] = '<div style="display:flex; gap:4px; flex-wrap:wrap;">' . 
                            $renewBtn . $duplicateBtn . 
                            '<button type="button" onclick="editPlan(' . $safeJson . ')" class="btn btn-secondary" style="padding:4px 8px;font-size:12px;">Edit</button>' .
                            '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete this training plan?\');">' .
                            $csrfStr . '<input type="hidden" name="action" value="delete_plan"><input type="hidden" name="plan_id" value="'.$p['plan_id'].'">' .
                            '<button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:12px;">Delete</button></form></div>';
            return $p;
        }, $plans);
        echo render_simple_table($tableRows, ['member', 'title', 'start_date', 'end_date', 'status', 'progress', 'adherence', 'feedback', 'actions']);
        ?>
    </section>

    <!-- Add Training Plan Modal -->
    <div id="planModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);align-items:center;justify-content:center;z-index:1000;padding:1rem">
        <div style="background:#0f172a;padding:2rem;border-radius:8px;width:100%;max-width:400px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.5);border:1px solid #334155">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
                <h2 style="margin:0; font-size:1.5rem; color:var(--ink)">Add Training Plan</h2>
                <button type="button" onclick="document.getElementById('planModal').style.display='none'" style="background:transparent;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;padding:0">&times;</button>
            </div>
            <form method="post" class="form" style="display:flex;flex-direction:column;gap:12px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_plan">
                <label style="display:block; color:var(--muted); font-size:14px">Member
                    <select name="member_user_id" class="form-control" style="width:100%;box-sizing:border-box" required onchange="updateGoalField(this)">
                        <option value="" data-goal="">-- Select Member --</option>
                        <?php foreach ($members as $member): ?>
                            <option value="<?= (int) $member['member_user_id'] ?>" data-goal="<?= h($member['primary_goal'] ?? '') ?>" <?= $memberId === (int) $member['member_user_id'] ? 'selected' : '' ?>><?= h($member['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="display:block; color:var(--muted); font-size:14px">Plan title 
                    <input name="title" class="form-control" placeholder="e.g. Personalized Workout Plan" style="width:100%;box-sizing:border-box" required>
                </label>
                <label style="display:block; color:var(--muted); font-size:14px">Goal 
                    <input name="goal" class="form-control" placeholder="e.g. muscle_gain" style="width:100%;box-sizing:border-box">
                </label>
                <label style="display:block; color:var(--muted); font-size:14px">Start date 
                    <input name="start_date" type="date" class="form-control" required value="<?= h(date('Y-m-d')) ?>" style="width:100%;box-sizing:border-box">
                </label>
                <label style="display:block; color:var(--muted); font-size:14px">End date 
                    <input name="end_date" type="date" class="form-control" style="width:100%;box-sizing:border-box">
                </label>
                <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:1rem">
                    <button type="button" onclick="document.getElementById('planModal').style.display='none'" style="background:transparent;color:#94a3b8;border:1px solid #475569">Cancel</button>
                    <button>Create Plan</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Training Plan Modal -->
    <div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);align-items:center;justify-content:center;z-index:1000;padding:1rem">
        <div style="background:#0f172a;padding:2rem;border-radius:8px;width:100%;max-width:400px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.5);border:1px solid #334155">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
                <h2 style="margin:0; font-size:1.5rem; color:var(--ink)">Edit Training Plan</h2>
                <button type="button" onclick="document.getElementById('editModal').style.display='none'" style="background:transparent;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;padding:0">&times;</button>
            </div>
            <form id="editForm" method="post" style="display:flex;flex-direction:column;gap:12px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_plan">
                <input type="hidden" name="plan_id" id="edit_plan_id">
                <label style="display:block; color:var(--muted); font-size:14px">Member
                    <select name="member_user_id" id="edit_member_id" class="form-control" style="width:100%;box-sizing:border-box" required onchange="updateGoalField(this)">
                        <?php foreach ($members as $member): ?>
                            <option value="<?= (int) $member['member_user_id'] ?>" data-goal="<?= h($member['primary_goal'] ?? '') ?>"><?= h($member['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="display:block; color:var(--muted); font-size:14px">Title 
                    <input name="title" id="edit_title" class="form-control" style="width:100%;box-sizing:border-box" required>
                </label>
                <label style="display:block; color:var(--muted); font-size:14px">Goal 
                    <input name="goal" id="edit_goal" class="form-control" style="width:100%;box-sizing:border-box">
                </label>
                <label style="display:block; color:var(--muted); font-size:14px">Start Date 
                    <input name="start_date" id="edit_start_date" type="date" class="form-control" style="width:100%;box-sizing:border-box" required>
                </label>
                <label style="display:block; color:var(--muted); font-size:14px">End Date 
                    <input name="end_date" id="edit_end_date" type="date" class="form-control" style="width:100%;box-sizing:border-box">
                </label>
                <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:1rem">
                    <button type="button" onclick="document.getElementById('editModal').style.display='none'" style="background:transparent;color:#94a3b8;border:1px solid #475569">Cancel</button>
                    <button class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    <script>
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
    });

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
                    <label style="display:block; color: var(--muted); font-size: 14px;">Member
                        <select name="member_user_id" id="ep_member" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                            ${membersOptions}
                        </select>
                    </label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Plan title * 
                        <input name="title" id="ep_title" class="form-control" required style="width: 100%; box-sizing: border-box;">
                    </label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Goal 
                        <input name="goal" id="ep_goal" class="form-control" style="width: 100%; box-sizing: border-box;">
                    </label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Start date * 
                        <input name="start_date" id="ep_start" type="date" class="form-control" required style="width: 100%; box-sizing: border-box;">
                    </label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">End date 
                        <input name="end_date" id="ep_end" type="date" class="form-control" style="width: 100%; box-sizing: border-box;">
                    </label>
                </form>
            `,
            didOpen: () => {
                document.getElementById('ep_id').value = p.plan_id;
                document.getElementById('ep_member').value = p.member_user_id;
                document.getElementById('ep_title').value = p.title;
                document.getElementById('ep_goal').value = p.goal || '';
                document.getElementById('ep_start').value = p.start_date;
                document.getElementById('ep_end').value = p.end_date || '';
            },
            showCancelButton: true,
            confirmButtonText: 'Save Changes',
            confirmButtonColor: 'var(--lime)',
            cancelButtonColor: '#334155',
            background: '#0f172a',
            color: 'var(--ink)',
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
                    <p style="margin:0; color:var(--ink); font-size:14px;">Select the member you want to duplicate this plan to.</p>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Target Member *
                        <select name="target_member_id" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                            <option value="">-- Select Member --</option>
                            ${membersOptions}
                        </select>
                    </label>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Duplicate',
            confirmButtonColor: 'var(--lime)',
            cancelButtonColor: '#334155',
            background: '#0f172a',
            color: 'var(--ink)',
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
