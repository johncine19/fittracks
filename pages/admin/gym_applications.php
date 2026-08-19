<?php

declare(strict_types=1);

function gym_applications_page(): void
{
    $user = require_roles(['platform_admin']);
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $gymId = (int) post('gym_id');
        $action = post('action');

        if ($action === 'approve') {
            $pdo->prepare('UPDATE gyms SET status = "approved" WHERE gym_id = ?')->execute([$gymId]);
            require_once __DIR__ . '/../../core/seeds.php';
            seed_exercises_for_gym($gymId);
            flash('Gym application approved successfully. Default exercises have been seeded.', 'success');
        } elseif ($action === 'reject') {
            $pdo->prepare('UPDATE gyms SET status = "rejected" WHERE gym_id = ?')->execute([$gymId]);
            flash('Gym application rejected.', 'success');
        }
        redirect('gym_applications');
    }

    $applications = $pdo->query('SELECT g.*, u.first_name, u.last_name, u.email FROM gyms g JOIN users u ON u.user_id = g.owner_user_id WHERE g.status = "pending" ORDER BY g.created_at DESC')->fetchAll();

    render_header('Gym Applications', $user);
?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Gym Applications</h1>
                <p>Review and approve pending gym registrations.</p>
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Gym Name</th>
                        <th>Owner</th>
                        <th>Email</th>
                        <th>Address</th>
                        <th>Contact Info</th>
                        <th>Documents</th>
                        <th style="width: 200px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$applications): ?>
                        <?php table_empty(6, 'No pending applications.'); ?>
                        <?php else: foreach ($applications as $app): ?>
                            <tr>
                                <td><strong><?= h($app['name']) ?></strong></td>
                                <td><?= h($app['first_name'] . ' ' . $app['last_name']) ?></td>
                                <td><?= h($app['email']) ?></td>
                                <td><?= h($app['address']) ?></td>
                                <td><?= h($app['contact_info'] ?? 'N/A') ?></td>
                                <td>
                                    <div style="display:flex; flex-direction:column; gap:4px;">
                                        <?php if ($app['business_permit_url']): ?>
                                            <button type="button" class="btn-sm btn-ghost" onclick="viewDocument('<?= h(upload_url($app['business_permit_url'], 'permits')) ?>', 'Business Permit')">Business Permit</button>
                                        <?php endif; ?>
                                        <?php if ($app['valid_id_url'] ?? null): ?>
                                            <button type="button" class="btn-sm btn-ghost" onclick="viewDocument('<?= h(upload_url($app['valid_id_url'], 'permits')) ?>', 'Valid ID')">Valid ID</button>
                                        <?php endif; ?>
                                        <?php if (!$app['business_permit_url'] && !($app['valid_id_url'] ?? null)): ?>
                                            <span class="muted">None</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 8px;">
                                        <form method="post" action="index.php?page=gym_applications" style="margin:0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="gym_id" value="<?= (int) $app['gym_id'] ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="btn-sm btn-primary" data-confirm="Approve this gym?">Approve</button>
                                        </form>
                                        <form method="post" action="index.php?page=gym_applications" style="margin:0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="gym_id" value="<?= (int) $app['gym_id'] ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="submit" class="btn-sm btn-secondary" style="color:var(--danger)" data-confirm="Reject this gym?">Reject</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                    <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <script>
    function viewDocument(url, docTitle = 'Document') {
        const ext = url.split('.').pop().toLowerCase().split('?')[0];
        
        if (ext === 'pdf') {
            Swal.fire({
                title: docTitle,
                html: `<iframe src="${url}" style="width:100%; height:70vh; border:none;"></iframe>`,
                width: '80%',
                showCloseButton: true,
                showConfirmButton: false,
                background: 'var(--surface-color, #18251eff)',
                color: 'var(--text-color, #ffffff)'
            });
        } else {
            Swal.fire({
                title: docTitle,
                imageUrl: url,
                imageAlt: docTitle,
                width: 'auto',
                showCloseButton: true,
                showConfirmButton: false,
                background: 'var(--surface-color, #18251eff)',
                color: 'var(--text-color, #ffffff)'
            });
        }
    }
    </script>
<?php
    render_footer();
}
