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
            db()->prepare('INSERT INTO training_plans (member_user_id, trainer_id, title, goal, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$memberId, $coachId, $title, post('goal'), post('start_date'), post('end_date') ?: null]);
            if ($memberId) {
                $trainerName = $user['first_name'] . ' ' . $user['last_name'];
                notify_user($memberId, 'system', 'Training plan created', $trainerName . ' created a training plan: ' . $title . '.');
            }
            flash('Training plan created.');
        } elseif ($action === 'edit_plan') {
            $plan_id = (int) post('plan_id');
            db()->prepare('UPDATE training_plans SET member_user_id=?, title=?, goal=?, start_date=?, end_date=? WHERE plan_id=? AND trainer_id=?')
                ->execute([$memberId, $title, post('goal'), post('start_date'), post('end_date') ?: null, $plan_id, $coachId]);
            flash('Training plan updated.');
        } elseif ($action === 'delete_plan') {
            $plan_id = (int) post('plan_id');
            db()->prepare('DELETE FROM training_plans WHERE plan_id=? AND trainer_id=?')->execute([$plan_id, $coachId]);
            flash('Training plan deleted.');
        }
        redirect('training');
    }
    $members = query_all('SELECT ca.member_user_id, CONCAT(u.first_name, " ", u.last_name) AS name FROM trainer_assignments ca JOIN users u ON u.user_id = ca.member_user_id WHERE ca.trainer_id = ? AND ca.status = "active"', [$coachId]);
    $plans = query_all('SELECT tp.*, CONCAT(u.first_name, " ", u.last_name) AS member FROM training_plans tp JOIN users u ON u.user_id = tp.member_user_id WHERE tp.trainer_id = ? ORDER BY tp.created_at DESC', [$coachId]);
    render_header('Training', $user);
    ?>
    <div class="skeleton-wrapper">
        <section class="panel">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
                <div class="sk sk-title" style="width:200px;margin-bottom:8px"></div>
                <div class="sk sk-rect" style="width:100px;height:36px;border-radius:18px"></div>
            </div>
            <?php render_skeleton_table(7, 4); ?>
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
            $p['actions'] = '<button onclick="editPlan(' . $safeJson . ')" class="btn btn-secondary" style="padding:4px 8px;font-size:12px;margin-right:4px;">Edit</button>' .
                            '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete this training plan?\');">' .
                            $csrfStr . '<input type="hidden" name="action" value="delete_plan"><input type="hidden" name="plan_id" value="'.$p['plan_id'].'">' .
                            '<button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:12px;">Delete</button></form>';
            return $p;
        }, $plans);
        echo render_simple_table($tableRows, ['member', 'title', 'goal', 'start_date', 'end_date', 'status', 'actions']);
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
                    <select name="member_user_id" class="form-control" style="width:100%;box-sizing:border-box" required>
                        <?php foreach ($members as $member): ?>
                            <option value="<?= (int) $member['member_user_id'] ?>" <?= $memberId === (int) $member['member_user_id'] ? 'selected' : '' ?>><?= h($member['name']) ?></option>
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
    <script>
    function editPlan(p) {
        let membersOptions = '';
        <?php foreach ($members as $member): ?>
            membersOptions += `<option value="<?= (int) $member['member_user_id'] ?>"><?= h($member['name']) ?></option>`;
        <?php endforeach; ?>

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
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
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
    </script>
    <?php
    render_footer();
}
