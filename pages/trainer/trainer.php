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
    $members = query_all('SELECT ca.*, u.first_name, u.last_name, u.email, u.profile_picture, mp.weight_kg, mp.primary_goal FROM trainer_assignments ca JOIN users u ON u.user_id = ca.member_user_id LEFT JOIN member_profiles mp ON mp.user_id = u.user_id WHERE ca.trainer_id = ? AND ca.status = "active"', [$coachId]);
    render_header('Clients', $user);
    echo '<section class="panel"><h1>Assigned clients</h1><div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px;">';
    echo <<<HTML
    <style>
    .t-btn-primary { background: var(--lime); color: var(--bg); font-weight: bold; width: 100%; border: none; cursor: pointer; padding: 10px; transition: all 0.2s ease; border-radius: 4px; }
    .t-btn-primary:hover { filter: brightness(0.85); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(163, 230, 53, 0.2); }
    .t-btn-secondary { background: color-mix(in srgb, var(--bg) 50%, transparent); border: 1px solid var(--line); color: var(--ink); padding: 10px; font-size: 13px; text-align: center; text-decoration: none; transition: all 0.2s ease; border-radius: 4px; }
    .t-btn-secondary:hover { background: color-mix(in srgb, var(--lime) 10%, transparent); border-color: var(--lime); color: var(--lime); transform: translateY(-1px); }
    .t-btn-send { padding: 6px 12px; background: var(--lime); color: var(--bg); font-weight: bold; border: none; font-size: 13px; transition: all 0.2s ease; border-radius: 4px; cursor: pointer; }
    .t-btn-send:hover { filter: brightness(0.85); transform: scale(1.05); }
    </style>
HTML;

    foreach ($members as $member) {
        $name = h($member['first_name'] . ' ' . $member['last_name']);
        $email = h($member['email']);
        $goal = h(ucwords(str_replace('_', ' ', $member['primary_goal'] ?? 'No goal')));
        $weight = h($member['weight_kg'] ?? '-');
        $memberId = (int) $member['member_user_id'];
        $csrf = csrf_field();
        $avatarHtml = render_avatar($member, 'large'); // Using large size for the card

        echo <<<HTML
        <article class="panel plan-card-glow" style="display: flex; flex-direction: column; gap: 1rem; background: var(--surface); padding: 1.5rem;">
            <div style="display: flex; gap: 15px; align-items: center;">
                <div>
                    {$avatarHtml}
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 1.2rem; color: var(--ink);">{$name}</h3>
                    <p style="color: var(--muted); font-size: 0.9rem; margin-top: 4px;">{$email}</p>
                </div>
            </div>
            
            <div style="font-size: 1.3rem; font-weight: bold; color: var(--lime); margin-top: 0.5rem;">
                {$goal}
            </div>
            
            <p style="font-size: 0.9rem; color: var(--muted); flex: 1;">
                Current recorded weight: {$weight} kg
            </p>
            
            <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 0.5rem;">
                <form method="post" style="margin: 0;">
                    {$csrf}
                    <input type="hidden" name="action" value="workout">
                    <input type="hidden" name="member_user_id" value="{$memberId}">
                    <button class="btn t-btn-primary">
                        Generate Plan
                    </button>
                </form>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <a class="btn t-btn-secondary" href="index.php?page=training&member_user_id={$memberId}">
                        Training Plan
                    </a>
                    <a class="btn t-btn-secondary" href="index.php?page=progress&member_user_id={$memberId}">
                        Progress
                    </a>
                </div>
                
                <form method="post" style="margin: 0; display: flex; gap: 10px; align-items: center; background: color-mix(in srgb, var(--bg) 50%, transparent); padding: 6px; border-radius: 6px; border: 1px solid var(--line);">
                    {$csrf}
                    <input type="hidden" name="action" value="message">
                    <input type="hidden" name="member_user_id" value="{$memberId}">
                    <input name="message_text" placeholder="Message client..." style="margin: 0; flex-grow: 1; border: none; background: transparent; padding: 4px; color: var(--ink); font-size: 13px; outline: none;" required>
                    <button type="submit" class="btn t-btn-send">
                        Send
                    </button>
                </form>
            </div>
        </article>
HTML;
    }
    if (!$members) echo '<p class="muted">No assigned clients yet. Admins can assign coaches from the Coaches page.</p>';
    echo '</div></section>';
    render_footer();
}
