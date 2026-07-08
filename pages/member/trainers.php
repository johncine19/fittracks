<?php
declare(strict_types=1);

function trainers_page(): void
{
    $user = require_roles(['member']);
    
    $hasActivePlan = (bool) scalar("SELECT 1 FROM memberships WHERE user_id = ? AND status = 'active' AND end_date >= CURDATE()", [$user['user_id']]);
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'request_appointment') {
        $trainerId = (int) post('trainer_id');
        
        $assignedDate = date('Y-m-d');
        $endedDate = null;
        if (!$hasActivePlan) {
            $reqDate = post('appointment_date');
            if (!$reqDate) {
                flash('Please select an appointment date.', 'danger');
                redirect('trainers');
            }
            $assignedDate = $reqDate;
            $endedDate = $reqDate;
        }
        
        // Check if there is already an active or pending assignment globally
        $existing = scalar("SELECT assignment_id FROM trainer_assignments WHERE member_user_id = ? AND status IN ('active', 'pending_admin', 'pending_trainer')", [$user['user_id']]);
        
        if ($existing) {
            flash('You already have an active or pending trainer appointment. You must end it before requesting a new one.', 'danger');
        } else {
            db()->prepare('INSERT INTO trainer_assignments (trainer_id, member_user_id, assigned_date, ended_date, status) VALUES (?, ?, ?, ?, "pending_admin")')->execute([$trainerId, $user['user_id'], $assignedDate, $endedDate]);
            
            // Notify admins
            $admins = query_all('SELECT user_id FROM users WHERE role = "admin" AND status = "active"');
            foreach ($admins as $admin) {
                notify_user((int) $admin['user_id'], 'system', 'Trainer Appointment Request', $user['first_name'] . ' ' . $user['last_name'] . ' has requested an appointment.');
            }
            
            flash('Your appointment request has been sent to the admin for approval.');
        }
        redirect('trainers');
    }

    $trainers = query_all('SELECT tp.trainer_id, u.user_id, u.first_name, u.last_name, u.profile_picture, tp.specialization, tp.bio, (SELECT COUNT(*) FROM attendance WHERE user_id = u.user_id AND check_out_time IS NULL AND DATE(check_in_time) = CURDATE()) as is_present FROM trainer_profiles tp JOIN users u ON u.user_id = tp.user_id WHERE u.status = "active"');
    
    render_header('Trainers', $user);
    ?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Trainers</h1>
                <p>Browse our list of professional trainers and request an appointment.</p>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-top: 20px;">
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
                        <?php if (!$hasActivePlan): ?>
                            <input type="hidden" name="appointment_date" id="date-<?= $trainer['trainer_id'] ?>" value="">
                            <button type="button" onclick="requestTrainerDate(<?= $trainer['trainer_id'] ?>)" class="btn" style="width: 100%; background: var(--surface); color: var(--ink); border: 1px solid var(--line);">Request Appointment</button>
                        <?php else: ?>
                            <button type="submit" class="btn" style="width: 100%; background: var(--surface); color: var(--ink); border: 1px solid var(--line);">Request Appointment</button>
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
            html: '<div style="text-align:left; margin-top:10px;"><label style="font-size: 14px; color: var(--muted); margin-bottom: 8px; display: block;">Select a date for your appointment:</label><input type="date" id="swal-input-date" class="form-control" style="width:100%; box-sizing: border-box;" min="<?= date('Y-m-d') ?>"></div>',
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
