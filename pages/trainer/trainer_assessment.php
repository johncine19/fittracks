<?php
declare(strict_types=1);

function trainer_assessment_page(): void
{
    $user = require_roles(['trainer']);
    $pdo = db();
    
    $memberUserId = (int) get('member_user_id');
    if (!$memberUserId) {
        flash('No member selected.', 'danger');
        redirect('trainer_members');
    }

    // Verify trainer is assigned to this member
    $coachId = ensure_coach_profile((int)$user['user_id']);
    $assignment = $pdo->prepare('SELECT 1 FROM trainer_assignments WHERE trainer_id = ? AND member_user_id = ? AND status = "active"');
    $assignment->execute([$coachId, $memberUserId]);
    if (!$assignment->fetchColumn()) {
        flash('You are not assigned to this member.', 'danger');
        redirect('trainer_members');
    }

    $memberData = $pdo->prepare('SELECT * FROM users WHERE user_id = ?');
    $memberData->execute([$memberUserId]);
    $memberUser = $memberData->fetch();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $validator = new Validator();
        $valid = $validator->validate($_POST, [
            'height_cm' => 'numeric|min_num:100|max_num:250',
            'weight_kg' => 'numeric|min_num:20|max_num:300',
            'age'       => 'numeric|min_num:16|max_num:120',
            'neck_cm'   => 'numeric|min_num:20|max_num:100',
            'waist_cm'  => 'numeric|min_num:30|max_num:200',
            'hip_cm'    => 'numeric|min_num:30|max_num:200',
        ]);
        
        if (!$valid) {
            flash($validator->firstError() ?? 'Invalid measurements provided.', 'danger');
        } else {
            save_member_profile($memberUserId);
            
            // If weight is provided, log it
            $weight = (float) post('weight_kg');
            if ($weight > 0) {
                $pdo->prepare('INSERT INTO progress_logs (user_id, log_date, weight_kg) VALUES (?, CURDATE(), ?) ON DUPLICATE KEY UPDATE weight_kg = VALUES(weight_kg)')
                    ->execute([$memberUserId, $weight]);
            }
            
            notify_user($memberUserId, 'system', 'Assessment Updated', 'Your trainer updated your physical assessment profile.');
            flash('Fitness assessment updated for ' . h($memberUser['first_name']) . '.', 'success');
            redirect("index.php?page=trainer_assessment&member_user_id={$memberUserId}");
        }
    }

    $profile = member_profile($memberUserId);
    if (!$profile) {
        $profile = ['biological_sex' => 'male', 'activity_level' => 'sedentary', 'primary_goal' => 'general_health', 'fitness_tier' => 1];
    }

    render_header('Fitness Assessment - ' . h($memberUser['first_name']), $user);
?>
    <section class="panel" style="max-width: 600px; margin: 0 auto;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
            <h2>Assessment: <?= h($memberUser['first_name'] . ' ' . $memberUser['last_name']) ?></h2>
            <a href="index.php?page=trainer_members" class="btn btn-ghost" style="padding: 5px 10px; font-size: 13px;">&larr; Back to Clients</a>
        </div>
        
        <p class="muted">Update the physical profile for this member. Changes will affect their generated workouts and allow you to track their progress.</p>
        
        <div style="margin-top: 20px;">
            <?php render_member_form('profile', $memberUser, $profile); ?>
        </div>
    </section>
<?php
    render_footer();
}
