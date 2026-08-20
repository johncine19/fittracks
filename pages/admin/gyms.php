<?php

declare(strict_types=1);

function gyms_page(): void
{
    $user = require_roles(['platform_admin']);
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $gymId = (int) post('gym_id');
        $action = post('action');

        if ($action === 'suspend') {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE gyms SET status = "suspended" WHERE gym_id = ?')->execute([$gymId]);
            $pdo->prepare('UPDATE users SET status = "suspended" WHERE user_id = (SELECT owner_user_id FROM gyms WHERE gym_id = ?)')
                ->execute([$gymId]);
            $pdo->commit();
            flash('Gym suspended successfully.', 'success');
        } elseif ($action === 'reactivate') {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE gyms SET status = "approved" WHERE gym_id = ?')->execute([$gymId]);
            $pdo->prepare('UPDATE users SET status = "active" WHERE user_id = (SELECT owner_user_id FROM gyms WHERE gym_id = ?)')
                ->execute([$gymId]);
            $pdo->commit();
            flash('Gym reactivated.', 'success');
        }
        redirect('gyms');
    }

    $gyms = $pdo->query('
        SELECT g.*, u.first_name, u.last_name, u.email 
        FROM gyms g 
        JOIN users u ON u.user_id = g.owner_user_id 
        WHERE g.status IN ("approved", "suspended") 
        ORDER BY g.created_at DESC
    ')->fetchAll();

    render_header('All Gyms', $user);
?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>All Gyms</h1>
                <p>Manage all approved and suspended gyms.</p>
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Gym Name</th>
                        <th>Owner</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th style="width: 150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$gyms): ?>
                        <tr><td colspan="5" class="text-center">No active or suspended gyms found.</td></tr>
                    <?php else: foreach ($gyms as $gym): ?>
                        <tr>
                            <td><strong><?= h($gym['name']) ?></strong></td>
                            <td><?= h($gym['first_name'] . ' ' . $gym['last_name']) ?></td>
                            <td><?= h($gym['email']) ?></td>
                            <td>
                                <?php if ($gym['status'] === 'approved'): ?>
                                    <span class="badge badge-active">Active</span>
                                <?php elseif ($gym['status'] === 'suspended'): ?>
                                    <span class="badge badge-inactive" style="color:var(--danger)">Suspended</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 8px;">
                                    <?php if ($gym['status'] === 'approved'): ?>
                                        <form method="post" action="index.php?page=gyms" style="margin:0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="gym_id" value="<?= (int) $gym['gym_id'] ?>">
                                            <input type="hidden" name="action" value="suspend">
                                            <button type="submit" class="btn-sm btn-secondary" style="color:var(--danger)" data-confirm="Are you sure you want to suspend this gym? Their members will lose access.">Suspend</button>
                                        </form>
                                    <?php elseif ($gym['status'] === 'suspended'): ?>
                                        <form method="post" action="index.php?page=gyms" style="margin:0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="gym_id" value="<?= (int) $gym['gym_id'] ?>">
                                            <input type="hidden" name="action" value="reactivate">
                                            <button type="submit" class="btn-sm btn-primary" data-confirm="Reactivate this gym?">Reactivate</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php
    render_footer();
}
