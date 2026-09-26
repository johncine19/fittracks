<?php
declare(strict_types=1);

function trainers_page(): void
{
    $user = require_roles(['member']);
    
    $isGymMember = db()->prepare('SELECT 1 FROM gym_members WHERE user_id = ?');
    $isGymMember->execute([$user['user_id']]);
    if (!$isGymMember->fetchColumn()) {
        flash('Please select a gym first to view this page.', 'warning');
        redirect('gym_selection');
    }
    
    $hasActivePlan = (bool) scalar("SELECT 1 FROM memberships WHERE user_id = ? AND status = 'active' AND end_date >= CURDATE()", [$user['user_id']]);
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'request_appointment') {
        $trainerId = (int) post('trainer_id');
        
        $assignedDate = date('Y-m-d H:i:s');
        $endedDate = null;
        if (!$hasActivePlan) {
            $reqDate = post('appointment_date');
            if (!$reqDate) {
                flash('Please select an appointment date.', 'danger');
                redirect('trainers');
            }
            $assignedDate = date('Y-m-d H:i:s', strtotime($reqDate));
            $endedDate = $assignedDate;
        }
        
        // Check if there is already an active or pending assignment globally
        $existing = scalar("SELECT assignment_id FROM trainer_assignments WHERE member_user_id = ? AND status IN ('active', 'pending_admin', 'pending_trainer')", [$user['user_id']]);
        
        if ($existing) {
            flash('You already have an active or pending trainer appointment. You must end it before requesting a new one.', 'danger');
        } else {
            db()->prepare('INSERT INTO trainer_assignments (trainer_id, member_user_id, assigned_date, ended_date, status) VALUES (?, ?, ?, ?, "pending_trainer")')->execute([$trainerId, $user['user_id'], $assignedDate, $endedDate]);
            
            // Notify the specific trainer
            $trainerUserId = scalar('SELECT user_id FROM trainer_profiles WHERE trainer_id = ?', [$trainerId]);
            if ($trainerUserId) {
                $dateStr = date('M j, Y g:i A', strtotime($assignedDate));
                notify_user((int) $trainerUserId, 'system', 'New Appointment Request', $user['first_name'] . ' ' . $user['last_name'] . ' has requested an appointment with you for ' . $dateStr . '.');
            }
            
            flash('Your appointment request has been sent directly to the trainer for approval.');
        }
        redirect('trainers');
    }

    $currentGym = get_user_gym($user);
    $gymId = $currentGym ? (int) $currentGym['gym_id'] : 0;
    
    $trainers = query_all('SELECT tp.trainer_id, u.user_id, u.first_name, u.last_name, u.profile_picture, tp.specialization, tp.bio, (SELECT COUNT(*) FROM attendance WHERE user_id = u.user_id AND check_out_time IS NULL AND DATE(check_in_time) = CURDATE()) as is_present FROM trainer_profiles tp JOIN users u ON u.user_id = tp.user_id WHERE u.status = "active" AND tp.gym_id = ?', [$gymId]);
    
    $stmt = db()->prepare("
        SELECT ca.*, 
               u.first_name AS coach_fn, 
               u.last_name AS coach_ln, 
               u.profile_picture AS coach_picture,
               tp.specialization,
               (CASE WHEN ca.group_id IS NOT NULL AND ca.group_id != '' 
                     THEN (SELECT COUNT(*) FROM trainer_assignments WHERE group_id = ca.group_id AND status = 'active') 
                     ELSE 1 END) AS group_members_count
        FROM trainer_assignments ca
        JOIN trainer_profiles tp ON tp.trainer_id = ca.trainer_id
        JOIN users u ON u.user_id = tp.user_id
        WHERE ca.member_user_id = ? AND ca.status IN ('active', 'pending_admin', 'pending_trainer')
        ORDER BY ca.assigned_date DESC
    ");
    $stmt->execute([$user['user_id']]);
    $activeAssignments = $stmt->fetchAll();
    $existingAssignment = $activeAssignments[0] ?? null;
    
    render_header('Trainers', $user);
    ?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Trainers</h1>
                <p>Browse our list of professional trainers and request an appointment.</p>
            </div>
        </div>

        <?php if ($activeAssignments): ?>
            <div style="margin-top: 15px; margin-bottom: 24px; display: flex; flex-direction: column; gap: 12px;">
                <?php foreach ($activeAssignments as $assign): 
                    $cData = ['first_name' => $assign['coach_fn'], 'last_name' => $assign['coach_ln'], 'profile_picture' => $assign['coach_picture']];
                    $isGroup = !empty($assign['group_id']) && (int)$assign['group_members_count'] > 1;
                ?>
                    <div style="background: var(--surface); border: 1px solid var(--lime); border-radius: 12px; padding: 18px 20px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px; box-shadow: 0 4px 20px rgba(132, 204, 22, 0.08);">
                        <div style="display: flex; align-items: center; gap: 14px;">
                            <?= render_avatar($cData, 'medium') ?>
                            <div>
                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: var(--ink);">
                                        <?= h($assign['activity_title'] ?: 'Trainer Assignment') ?>
                                    </h3>
                                    <span class="badge badge-<?= str_replace(' ', '_', $assign['status']) ?>">
                                        <?= $assign['status'] === 'active' ? 'Active Assignment' : h(ucwords(str_replace('_', ' ', $assign['status']))) ?>
                                    </span>
                                    <?php if ($isGroup): ?>
                                        <span class="badge" style="background: rgba(132, 204, 22, 0.12); color: var(--lime); border: 1px solid rgba(132, 204, 22, 0.25); font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 12px; display: inline-flex; align-items: center; gap: 4px;">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                            Group Activity (<?= (int)$assign['group_members_count'] ?> Members)
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <p style="margin: 4px 0 0; font-size: 13px; color: var(--muted);">
                                    Coach <strong style="color: var(--ink);"><?= h($assign['coach_fn'] . ' ' . $assign['coach_ln']) ?></strong> &bull; <?= h($assign['specialization'] ?: 'General Fitness') ?> &bull; Assigned <?= date('M j, Y', strtotime($assign['assigned_date'])) ?>
                                </p>
                            </div>
                        </div>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <a href="index.php?page=messages" class="btn-sm btn-ghost" style="text-decoration: none; padding: 8px 14px; font-weight: 600; border: 1px solid var(--line); border-radius: 6px; display: inline-flex; align-items: center; gap: 6px; color: var(--ink);">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                                Message Trainer
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr)); gap: 20px; margin-top: 20px;">
            <?php foreach ($trainers as $trainer): ?>
                <div class="card" style="padding: 20px; border-radius: 12px; background: var(--bg); border: 1px solid var(--line); display: flex; flex-direction: column; align-items: center; text-align: center; position: relative;">
                    <?php if ($trainer['is_present']): ?>
                        <div style="position: absolute; top: 12px; right: 12px; background: rgba(34, 197, 94, 0.1); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.2); padding: 4px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; display: flex; align-items: center; gap: 4px;" title="Trainer is currently checked in at the gym">
                            <span style="width: 6px; height: 6px; background: #22c55e; border-radius: 50%; display: inline-block; box-shadow: 0 0 4px #22c55e;"></span> At the gym
                        </div>
                    <?php endif; ?>
                    <?= render_avatar(['first_name' => $trainer['first_name'], 'last_name' => $trainer['last_name'], 'profile_picture' => $trainer['profile_picture']], 'large') ?>
                    <h3 style="margin: 15px 0 5px;"><?= h($trainer['first_name'] . ' ' . $trainer['last_name']) ?></h3>
                    <div style="color: var(--lime); font-size: 14px; font-weight: 500; margin-bottom: 15px;"><?= h($trainer['specialization'] ?? 'General Trainer') ?></div>
                    <p style="color: var(--muted); font-size: 14px; flex-grow: 1; margin-bottom: 20px;"><?= h($trainer['bio'] ?? 'No bio available.') ?></p>
                    
                    <form id="form-trainer-<?= $trainer['trainer_id'] ?>" method="post" style="width: 100%;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="request_appointment">
                        <input type="hidden" name="trainer_id" value="<?= (int) $trainer['trainer_id'] ?>">
                        <?php if ($existingAssignment): ?>
                            <?php if ($existingAssignment['trainer_id'] == $trainer['trainer_id']): ?>
                                <button type="button" class="btn" style="width: 100%; background: rgba(199,255,34,0.1); color: var(--lime); border: 1px solid var(--lime); cursor: not-allowed; font-weight: 600;" disabled>
                                    <?= $existingAssignment['status'] === 'active' ? 'Currently Assigned' : 'Request Pending' ?>
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn" style="width: 100%; background: var(--surface); color: var(--muted); border: 1px solid var(--line); cursor: not-allowed;" disabled>Request Appointment</button>
                            <?php endif; ?>
                        <?php elseif (!$hasActivePlan): ?>
                            <input type="hidden" name="appointment_date" id="date-<?= $trainer['trainer_id'] ?>" value="">
                            <button type="button" onclick="requestTrainerDate(<?= $trainer['trainer_id'] ?>)" class="btn" style="width: 100%; background: var(--lime); color: var(--bg); border: none; font-weight: 600;">Request Appointment</button>
                        <?php else: ?>
                            <button type="submit" class="btn" style="width: 100%; background: var(--lime); color: var(--bg); border: none; font-weight: 600;">Request Appointment</button>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if (!$trainers): ?>
            <div class="empty-state">
                <p>No trainers available at the moment.</p>
            </div>
        <?php endif; ?>
    </section>

    <script>
    function requestTrainerDate(trainerId) {
        Swal.fire({
            title: 'Appointment Date',
            html: '<div style="text-align:left; margin-top:10px;"><label style="font-size: 14px; color: var(--muted); margin-bottom: 8px; display: block;">Select a date and time for your appointment:</label><input type="datetime-local" id="swal-input-date" class="form-control" style="width:100%; box-sizing: border-box;" min="<?= date('Y-m-d\TH:i') ?>"><div style="margin-top:14px;padding:10px 14px;border-radius:8px;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.2);display:flex;align-items:flex-start;gap:8px;"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span style="font-size:13px;color:#f59e0b;line-height:1.4;">Please prepare your payment for the session. You will pay the trainer directly at the appointment.</span></div></div>',
            focusConfirm: false,
            showCancelButton: true,
            confirmButtonText: 'Submit Request',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const val = document.getElementById('swal-input-date').value;
                if (!val) {
                    Swal.showValidationMessage('Please select a date');
                    return false;
                }
                return val;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('date-' + trainerId).value = result.value;
                document.getElementById('form-trainer-' + trainerId).submit();
            }
        });
    }
    </script>
    <?php
    render_footer();
}
