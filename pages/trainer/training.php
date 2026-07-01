<?php
declare(strict_types=1);

function training_page(): void
{
    $user = require_roles(['trainer']);
    $coachId = ensure_coach_profile((int) $user['user_id']);
    $memberId = (int) ($_GET['member_user_id'] ?? post('member_user_id', 0));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $title = trim((string) post('title'));
        db()->prepare('INSERT INTO training_plans (member_user_id, trainer_id, title, goal, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?)')->execute([$memberId, $coachId, $title, post('goal'), post('start_date'), post('end_date') ?: null]);
        if ($memberId) {
            $trainerName = $user['first_name'] . ' ' . $user['last_name'];
            notify_user($memberId, 'system', 'Training plan created', $trainerName . ' created a training plan: ' . $title . '.');
        }
        flash('Training plan created.');
        redirect('training');
    }
    $members = query_all('SELECT ca.member_user_id, CONCAT(u.first_name, " ", u.last_name) AS name FROM trainer_assignments ca JOIN users u ON u.user_id = ca.member_user_id WHERE ca.trainer_id = ? AND ca.status = "active"', [$coachId]);
    $plans = query_all('SELECT tp.*, CONCAT(u.first_name, " ", u.last_name) AS member FROM training_plans tp JOIN users u ON u.user_id = tp.member_user_id WHERE tp.trainer_id = ? ORDER BY tp.created_at DESC', [$coachId]);
    render_header('Training', $user);
    ?>
    <section class="panel">
        <h1>Training plans</h1>
        <form method="post" class="form inline-form">
            <?= csrf_field() ?>
            <select name="member_user_id"><?php foreach ($members as $member): ?><option value="<?= (int) $member['member_user_id'] ?>" <?= $memberId === (int) $member['member_user_id'] ? 'selected' : '' ?>><?= h($member['name']) ?></option><?php endforeach; ?></select>
            <input name="title" placeholder="Plan title" required><input name="goal" placeholder="Goal"><input name="start_date" type="date" required value="<?= h(date('Y-m-d')) ?>"><input name="end_date" type="date"><button>Create</button>
        </form>
        <?= render_simple_table($plans, ['member', 'title', 'goal', 'start_date', 'end_date', 'status']) ?>
    </section>
    <?php
    render_footer();
}
