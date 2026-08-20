<?php
declare(strict_types=1);

// Helper: count active training plans created by a trainer.
function trainer_plan_count(int $coachId): int
{
    return (int) scalar('SELECT COUNT(*) FROM training_plans WHERE trainer_id = ? AND status = "active"', [$coachId]);
}

// Helper: ensure a trainer_profiles row exists for the given user and return trainer_id.

function trainer_members_page(): void
{
    $user = require_roles(['trainer']);
    $coachId = ensure_coach_profile((int) $user['user_id']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (post('action') === 'workout') {
            $memberUserId = (int) post('member_user_id');
            generate_workout_plan($memberUserId, $coachId);
            $trainerName = $user['first_name'] . ' ' . $user['last_name'];
            notify_user($memberUserId, 'system', 'New workout plan', $trainerName . ' generated a personalised workout plan for you.');
            flash('Workout plan generated for client.');
        } elseif (post('action') === 'accept_appointment') {
            $assignmentId = (int) post('assignment_id');
            db()->prepare('UPDATE trainer_assignments SET status = "active" WHERE assignment_id = ?')->execute([$assignmentId]);
            $stmt = db()->prepare('SELECT member_user_id, assigned_by FROM trainer_assignments WHERE assignment_id = ?');
            $stmt->execute([$assignmentId]);
            $assignment = $stmt->fetch();
            if ($assignment) {
                notify_user((int)$assignment['member_user_id'], 'system', 'Appointment Accepted', 'Your trainer appointment request was accepted by the trainer.');
                if ($assignment['assigned_by']) {
                    notify_user((int)$assignment['assigned_by'], 'system', 'Appointment Accepted', 'Trainer ' . $user['first_name'] . ' accepted the appointment request.');
                }
                grant_retroactive_commission((int)$assignment['member_user_id']);
            }
            flash('Appointment accepted.');
        } elseif (post('action') === 'reject_appointment') {
            $assignmentId = (int) post('assignment_id');
            $reason = post('rejection_reason');
            db()->prepare('UPDATE trainer_assignments SET status = "rejected", rejection_reason = ? WHERE assignment_id = ?')->execute([$reason, $assignmentId]);
            $stmt = db()->prepare('SELECT member_user_id, assigned_by FROM trainer_assignments WHERE assignment_id = ?');
            $stmt->execute([$assignmentId]);
            $assignment = $stmt->fetch();
            if ($assignment) {
                $reasonText = $reason ? ' Reason: ' . $reason : '';
                notify_user((int)$assignment['member_user_id'], 'system', 'Appointment Rejected', 'Your trainer appointment request was rejected by the trainer.' . $reasonText);
                
                // Notify all admins (or just the one who assigned if available)
                $admins = query_all('SELECT user_id FROM users WHERE role = "admin" AND status = "active"');
                foreach ($admins as $admin) {
                    notify_user((int)$admin['user_id'], 'system', 'Appointment Rejected', 'Trainer ' . $user['first_name'] . ' rejected the appointment request.' . $reasonText);
                }
            }
            flash('Appointment rejected.');
        }
        redirect('trainer_members');
    }
    $members = query_all('SELECT ca.*, u.first_name, u.last_name, u.email, u.profile_picture, mp.weight_kg, mp.primary_goal FROM trainer_assignments ca JOIN users u ON u.user_id = ca.member_user_id LEFT JOIN member_profiles mp ON mp.user_id = u.user_id WHERE ca.trainer_id = ? AND ca.status = "active"', [$coachId]);
    $pending_requests = query_all('SELECT ca.*, u.first_name, u.last_name, u.email, u.profile_picture, mp.weight_kg, mp.primary_goal FROM trainer_assignments ca JOIN users u ON u.user_id = ca.member_user_id LEFT JOIN member_profiles mp ON mp.user_id = u.user_id WHERE ca.trainer_id = ? AND ca.status = "pending_trainer"', [$coachId]);
    
    render_header('Clients', $user);
    
    if ($pending_requests): ?>
        <section class="panel">
            <h1>Pending Appointments</h1>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr)); gap: 20px; margin-bottom: 30px;">
            <?php foreach ($pending_requests as $req):
                $name = h($req['first_name'] . ' ' . $req['last_name']);
                $email = h($req['email']);
                $goal = h(ucwords(str_replace('_', ' ', $req['primary_goal'] ?? 'No goal')));
                $weight = h($req['weight_kg'] ?? '-');
                $assignmentId = (int) $req['assignment_id'];
                $avatarHtml = render_avatar($req, 'large');
                $csrf = csrf_field();
                
                $dateTimeText = 'Immediate (Ongoing)';
                if ($req['assigned_date'] && $req['ended_date']) {
                    $dateTimeText = date('M j, Y g:i A', strtotime($req['assigned_date']));
                } elseif ($req['assigned_date']) {
                    $dateTimeText = date('M j, Y', strtotime($req['assigned_date'])) . ' (Ongoing)';
                }
            ?>
                <article class="panel plan-card-glow" style="display: flex; flex-direction: column; gap: 1rem; background: var(--surface); padding: 1.5rem; border: 1px solid var(--lime);">
                    <div style="display: flex; gap: 15px; align-items: center;">
                        <div><?= $avatarHtml ?></div>
                        <div>
                            <h3 style="margin: 0; font-size: 1.2rem; color: var(--ink);"><?= $name ?></h3>
                            <p style="color: var(--muted); font-size: 0.9rem; margin-top: 4px;"><?= $email ?></p>
                        </div>
                    </div>
                    <div style="font-size: 1.1rem; font-weight: bold; color: var(--lime); margin-top: 0.5rem;">Goal: <?= $goal ?></div>
                    <p style="font-size: 0.9rem; color: var(--muted); margin-bottom: 0;">Current weight: <?= $weight ?> kg</p>
                    <div style="background: rgba(163, 230, 53, 0.1); border-radius: 8px; padding: 10px; border: 1px solid rgba(163, 230, 53, 0.2); flex: 1;">
                        <div style="font-size: 0.8rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Appointment Date</div>
                        <div style="font-size: 0.95rem; font-weight: 500; color: var(--ink);"><?= $dateTimeText ?></div>
                    </div>
                    <div style="display: flex; gap: 10px; margin-top: 0.5rem;">
                        <form method="post" style="flex: 1;">
                            <?= $csrf ?>
                            <input type="hidden" name="action" value="accept_appointment">
                            <input type="hidden" name="assignment_id" value="<?= $assignmentId ?>">
                            <button class="btn" style="width: 100%; background: var(--lime); color: var(--bg); font-weight: bold;">Accept</button>
                        </form>
                        <button class="btn btn-danger" style="flex: 1;" onclick="rejectAppointment(<?= $assignmentId ?>)">Reject</button>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h1>Assigned clients</h1>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr)); gap: 20px;">
        <style>
        .t-btn-primary { background: var(--lime); color: var(--bg); font-weight: bold; width: 100%; border: none; cursor: pointer; padding: 10px; transition: all 0.2s ease; border-radius: 4px; }
        .t-btn-primary:hover { filter: brightness(0.85); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(163, 230, 53, 0.2); }
        .t-btn-secondary { background: color-mix(in srgb, var(--bg) 50%, transparent); border: 1px solid var(--line); color: var(--ink); padding: 10px; font-size: 13px; text-align: center; text-decoration: none; transition: all 0.2s ease; border-radius: 4px; }
        .t-btn-secondary:hover { background: color-mix(in srgb, var(--lime) 10%, transparent); border-color: var(--lime); color: var(--lime); transform: translateY(-1px); }
        .t-btn-send { padding: 6px 12px; background: var(--lime); color: var(--bg); font-weight: bold; border: none; font-size: 13px; transition: all 0.2s ease; border-radius: 4px; cursor: pointer; }
        .t-btn-send:hover { filter: brightness(0.85); transform: scale(1.05); }
        </style>
        
        <?php foreach ($members as $member):
            $name = h($member['first_name'] . ' ' . $member['last_name']);
            $email = h($member['email']);
            $goal = h(ucwords(str_replace('_', ' ', $member['primary_goal'] ?? 'No goal')));
            $weight = h($member['weight_kg'] ?? '-');
            $memberId = (int) $member['member_user_id'];
            $csrf = csrf_field();
            $avatarHtml = render_avatar($member, 'large');
            
            $dateTimeText = 'Immediate (Ongoing)';
            if ($member['assigned_date'] && $member['ended_date']) {
                $dateTimeText = date('M j, Y g:i A', strtotime($member['assigned_date']));
            } elseif ($member['assigned_date']) {
                $dateTimeText = date('M j, Y', strtotime($member['assigned_date'])) . ' (Ongoing)';
            }
        ?>
            <article class="panel plan-card-glow" style="display: flex; flex-direction: column; gap: 1rem; background: var(--surface); padding: 1.5rem;">
                <div style="display: flex; gap: 15px; align-items: center;">
                    <div><?= $avatarHtml ?></div>
                    <div>
                        <h3 style="margin: 0; font-size: 1.2rem; color: var(--ink);"><?= $name ?></h3>
                        <p style="color: var(--muted); font-size: 0.9rem; margin-top: 4px;"><?= $email ?></p>
                    </div>
                </div>
                
                <div style="font-size: 1.3rem; font-weight: bold; color: var(--lime); margin-top: 0.5rem;">
                    <?= $goal ?>
                </div>
                
                <p style="font-size: 0.9rem; color: var(--muted); margin-bottom: 0;">
                    Current recorded weight: <?= $weight ?> kg
                </p>
                
                <div style="background: rgba(163, 230, 53, 0.05); border-radius: 8px; padding: 10px; border: 1px solid rgba(163, 230, 53, 0.1); flex: 1;">
                    <div style="font-size: 0.8rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Assignment Date</div>
                    <div style="font-size: 0.95rem; font-weight: 500; color: var(--ink);"><?= $dateTimeText ?></div>
                </div>
                
                <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 0.5rem;">
                    <a href="index.php?page=trainer_assessment&member_user_id=<?= $memberId ?>" class="btn t-btn-secondary" style="text-align:center; text-decoration:none; display: block; border-color: var(--lime); color: var(--lime);">
                        Update Assessment
                    </a>
                    <a href="index.php?page=workout_builder&member_user_id=<?= $memberId ?>" class="btn t-btn-primary" style="text-align:center; text-decoration:none;">
                        Generate Workout
                    </a>
                    <a href="index.php?page=diet_builder&member_user_id=<?= $memberId ?>" class="btn t-btn-primary" style="text-align:center; text-decoration:none; background: #3b82f6; color: white;">
                        Build Diet Plan
                    </a>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <a class="btn t-btn-secondary" href="index.php?page=training&member_user_id=<?= $memberId ?>">
                            Training Plan
                        </a>
                        <a class="btn t-btn-secondary" href="index.php?page=progress&member_user_id=<?= $memberId ?>">
                            Progress
                        </a>
                    </div>
                    
                    <a class="btn t-btn-secondary" href="index.php?page=messages&chat=<?= $memberId ?>" style="display: flex; gap: 8px; justify-content: center; align-items: center; border-color: transparent; background: color-mix(in srgb, var(--bg) 80%, transparent);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                        </svg>
                        Message Client
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$members): ?>
            <p class="muted">No assigned clients yet. Admins can assign trainers from the Trainers page.</p>
        <?php endif; ?>
        </div>
    </section>
    <script>
    function rejectAppointment(assignmentId) {
        Swal.fire({
            title: 'Reject Appointment',
            input: 'textarea',
            inputLabel: 'Reason for rejection (optional)',
            inputPlaceholder: 'Please state your reason...',
            showCancelButton: true,
            confirmButtonText: 'Reject',
            confirmButtonColor: 'var(--danger)',
            background: 'var(--surface)',
            color: 'var(--ink)'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject_appointment">
                    <input type="hidden" name="assignment_id" value="${assignmentId}">
                    <input type="hidden" name="rejection_reason" value="${result.value || ''}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    }
    </script>
    <?php
    render_footer();
}
