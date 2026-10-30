<?php
declare(strict_types=1);

function trainer_assessment_page(): void
{
    $user = require_roles(['trainer']);
    $pdo = db();
    
    $memberUserId = (int) ($_GET['member_user_id'] ?? 0);
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
              || isset($_GET['ajax']) 
              || isset($_POST['ajax']);

    if (!$memberUserId) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No member selected.']);
            exit;
        }
        flash('No member selected.', 'danger');
        redirect('trainer_members');
    }

    // Verify trainer is assigned to this member
    $coachId = ensure_coach_profile((int)$user['user_id']);
    $assignment = $pdo->prepare('SELECT 1 FROM trainer_assignments WHERE trainer_id = ? AND member_user_id = ? AND status = "active"');
    $assignment->execute([$coachId, $memberUserId]);
    if (!$assignment->fetchColumn()) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'You are not assigned to this member.']);
            exit;
        }
        flash('You are not assigned to this member.', 'danger');
        redirect('trainer_members');
    }

    $memberData = $pdo->prepare('SELECT * FROM users WHERE user_id = ?');
    $memberData->execute([$memberUserId]);
    $memberUser = $memberData->fetch();

    $profile = member_profile($memberUserId);
    if (!$profile) {
        $profile = ['biological_sex' => 'male', 'activity_level' => 'sedentary', 'primary_goal' => 'general_health', 'fitness_tier' => 1];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Keep these member-managed profile values unchanged, even if a request is altered.
        // Keep these member-managed profile values unchanged, even if a request is altered.
        $_POST['age'] = $profile['age'] ?? '';
        $_POST['biological_sex'] = $profile['biological_sex'] ?? 'male';
        $sex = (string)($_POST['biological_sex'] ?? 'male');
        if ($sex !== 'female') {
            unset($_POST['hip_cm']);
        }

        $validator = new Validator();
        $rules = [
            'height_cm' => 'numeric|min_num:100|max_num:250',
            'weight_kg' => 'numeric|min_num:20|max_num:300',
            'age'       => 'numeric|min_num:16|max_num:120',
            'neck_cm'   => 'numeric|min_num:20|max_num:100',
            'waist_cm'  => 'numeric|min_num:30|max_num:200',
        ];
        if ($sex === 'female') {
            $rules['hip_cm'] = 'numeric|min_num:30|max_num:200';
        }

        $valid = $validator->validate($_POST, $rules);
        if (!$valid) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $validator->firstError() ?? 'Invalid measurements provided.']);
                exit;
            }
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
            
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Fitness assessment updated for ' . ($memberUser['first_name'] ?? 'member') . '.',
                    'weight_kg' => $weight,
                    'height_cm' => (float) post('height_cm'),
                    'primary_goal' => post('primary_goal')
                ]);
                exit;
            }

            flash('Fitness assessment updated for ' . h($memberUser['first_name']) . '.', 'success');
            redirect('trainer_members');
        }
    }

    if (isset($_GET['modal'])) {
        render_member_form('profile', $memberUser, $profile, ['is_trainer_assessment' => true]);
        exit;
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
            <?php render_member_form('profile', $memberUser, $profile, ['is_trainer_assessment' => true]); ?>
        </div>
    </section>
<?php
    render_footer();
}
