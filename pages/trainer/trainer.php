<?php
declare(strict_types=1);

// Helper: count active training plans created by a trainer.
function trainer_plan_count(int $coachId): int
{
    return (int) scalar('SELECT COUNT(*) FROM training_plans WHERE trainer_id = ? AND status = "active"', [$coachId]);
}

// Helper: ensure a trainer_profiles row exists for the given user and return trainer_id.
function ensure_coach_profile(int $userId): int
{
    $coachId = (int) scalar('SELECT trainer_id FROM trainer_profiles WHERE user_id = ?', [$userId]);
    if (!$coachId) {
        db()->prepare('INSERT INTO trainer_profiles (user_id) VALUES (?)')->execute([$userId]);
        $coachId = (int) db()->lastInsertId();
    }
    return $coachId;
}

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
        } else {
            $memberUserId = (int) post('member_user_id');
            $text = trim((string) post('message_text'));
            db()->prepare('INSERT INTO trainer_messages (sender_id, recipient_id, message_text) VALUES (?, ?, ?)')->execute([$user['user_id'], $memberUserId, $text]);
            $trainerName = $user['first_name'] . ' ' . $user['last_name'];
            notify_user($memberUserId, 'coach_message', 'Message from your trainer', $trainerName . ': ' . $text);
            flash('Message sent.');
        }
        redirect('trainer_members');
    }
    $members = query_all('SELECT ca.*, u.first_name, u.last_name, u.email, mp.weight_kg, mp.primary_goal FROM trainer_assignments ca JOIN users u ON u.user_id = ca.member_user_id LEFT JOIN member_profiles mp ON mp.user_id = u.user_id WHERE ca.trainer_id = ? AND ca.status = "active"', [$coachId]);
    render_header('Clients', $user);
    echo '<section class="panel"><h1>Assigned clients</h1><div class="cards">';
    foreach ($members as $member) {
        echo '<article class="mini-card"><h3>' . h($member['first_name'] . ' ' . $member['last_name']) . '</h3><p>' . h($member['email']) . '</p><p>' . h($member['primary_goal'] ?? 'No goal') . ' / ' . h($member['weight_kg'] ?? '-') . ' kg</p><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="workout"><input type="hidden" name="member_user_id" value="' . (int) $member['member_user_id'] . '"><button>Generate workout</button></form><a class="button-link" href="index.php?page=training&member_user_id=' . (int) $member['member_user_id'] . '">Training plan</a><a class="button-link" href="index.php?page=progress&member_user_id=' . (int) $member['member_user_id'] . '">Progress</a><form method="post" class="form">' . csrf_field() . '<input type="hidden" name="action" value="message"><input type="hidden" name="member_user_id" value="' . (int) $member['member_user_id'] . '"><input name="message_text" placeholder="Message client"><button>Send</button></form></article>';
    }
    if (!$members) echo '<p class="muted">No assigned clients yet. Admins can assign coaches from the Coaches page.</p>';
    echo '</div></section>';
    render_footer();
}
