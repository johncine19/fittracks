<?php
declare(strict_types=1);

function admin_workouts_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner']);
    $pdo = db();

    // Check if viewing a specific plan
    $viewPlanId = (int) ($_GET['view_plan_id'] ?? 0);
    if ($viewPlanId) {
        $stmt = $pdo->prepare('
            SELECT p.*, m.first_name as member_first, m.last_name as member_last 
            FROM training_plans p
            JOIN users m ON p.member_user_id = m.user_id
            WHERE p.plan_id = ?
        ');
        $stmt->execute([$viewPlanId]);
        $plan = $stmt->fetch();
        
        if (!$plan) {
            flash('Workout plan not found.', 'error');
            redirect('admin_workouts');
        }
        
        render_header('View Workout Plan', $user);
        
        echo '<div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">';
        echo '<h1 style="margin:0;">Workout Plan for ' . h($plan['member_first'] . ' ' . $plan['member_last']) . '</h1>';
        echo '<a href="index.php?page=admin_workouts" class="button-link btn-secondary" style="display: inline-flex; align-items: center; gap: 8px;">';
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>';
        echo '<span>Back to all plans</span>';
        echo '</a>';
        echo '</div>';
        
        render_current_workout((int) $plan['member_user_id'], false, $viewPlanId);
        render_footer();
        return;
    }

    $page = max(1, (int)($_GET['p'] ?? 1));
    $limit = 15;
    $offset = ($page - 1) * $limit;

    $totalSql = 'SELECT COUNT(*) FROM training_plans WHERE trainer_id IS NOT NULL';
    $total = (int) scalar($totalSql);
    $totalPages = (int) ceil($total / $limit);

    $sql = '
        SELECT p.plan_id, p.title, p.goal, p.start_date, p.status, 
               m.first_name as member_first, m.last_name as member_last, m.profile_picture as member_pic,
               t.first_name as trainer_first, t.last_name as trainer_last, t.profile_picture as trainer_pic
        FROM training_plans p
        JOIN users m ON p.member_user_id = m.user_id
        JOIN trainer_profiles tp ON p.trainer_id = tp.trainer_id
        JOIN users t ON tp.user_id = t.user_id
        ORDER BY p.created_at DESC
        LIMIT ' . $limit . ' OFFSET ' . $offset;
        
    $rows = $pdo->query($sql)->fetchAll();

    render_header('Trainer Workout Plans', $user);
    ?>
    <div class="page-header">
        <h1>Trainer Workout Plans</h1>
        <p>View workout plans created by trainers for their assigned members.</p>
    </div>

    <div class="panel">
        <?php if (!$rows): ?>
            <p class="muted">No trainer workout plans found.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Plan ID</th>
                            <th>Member</th>
                            <th>Trainer</th>
                            <th>Goal</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>#<?= h((string)$row['plan_id']) ?></td>
                                <td style="font-weight:bold;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <?php if ($row['member_pic']): ?>
                                            <img src="<?= h(upload_url($row['member_pic'])) ?>" alt="Member" loading="lazy" decoding="async" style="width: 24px; height: 24px; border-radius: 50%; object-fit: cover;">
                                        <?php else: ?>
                                            <div style="width: 24px; height: 24px; border-radius: 50%; background: var(--line); display: flex; align-items: center; justify-content: center; font-size: 10px; color: var(--muted);"><?= h(substr($row['member_first'], 0, 1)) ?></div>
                                        <?php endif; ?>
                                        <?= h($row['member_first'] . ' ' . $row['member_last']) ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <?php if ($row['trainer_pic']): ?>
                                            <img src="<?= h(upload_url($row['trainer_pic'])) ?>" alt="Trainer" loading="lazy" decoding="async" style="width: 24px; height: 24px; border-radius: 50%; object-fit: cover;">
                                        <?php else: ?>
                                            <div style="width: 24px; height: 24px; border-radius: 50%; background: var(--line); display: flex; align-items: center; justify-content: center; font-size: 10px; color: var(--muted);"><?= h(substr($row['trainer_first'], 0, 1)) ?></div>
                                        <?php endif; ?>
                                        <?= h($row['trainer_first'] . ' ' . $row['trainer_last']) ?>
                                    </div>
                                </td>
                                <td><?= h(ucwords(str_replace('_', ' ', $row['goal']))) ?></td>
                                <td>
                                    <?php if ($row['status'] === 'active'): ?>
                                        <span class="badge" style="background: var(--lime); color: var(--bg);">Active</span>
                                    <?php elseif ($row['status'] === 'draft'): ?>
                                        <span class="badge" style="background: var(--warning); color: var(--bg);">Draft</span>
                                    <?php else: ?>
                                        <span class="badge"><?= h(ucwords($row['status'])) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h((string)$row['start_date']) ?></td>
                                <td>
                                    <a href="index.php?page=admin_workouts&view_plan_id=<?= $row['plan_id'] ?>" class="btn" style="padding:4px 8px; font-size:12px;">View Plan</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php render_pagination($page, $totalPages, '?page=admin_workouts'); ?>
        <?php endif; ?>
    </div>
    <?php
    render_footer();
}
