<?php

declare(strict_types=1);

function gym_applications_page(): void
{
    $user = require_roles(['platform_admin']);
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $gymId = (int) post('gym_id');
        $action = post('action');

        $gymStmt = $pdo->prepare('SELECT g.name AS gym_name, g.owner_user_id, u.email, u.first_name, u.last_name FROM gyms g JOIN users u ON g.owner_user_id = u.user_id WHERE g.gym_id = ?');
        $gymStmt->execute([$gymId]);
        $gym = $gymStmt->fetch();

        if ($gym) {
            $ownerName = $gym['first_name'] . ' ' . $gym['last_name'];
            
            if ($action === 'approve') {
                $pdo->prepare('UPDATE gyms SET status = "approved" WHERE gym_id = ?')->execute([$gymId]);
                require_once __DIR__ . '/../../core/seeds.php';
                seed_reference_exercises();
                
                notify_user((int)$gym['owner_user_id'], 'system', 'Application Approved', "Your gym application for {$gym['gym_name']} has been approved.");
                Emails::sendGymApplicationApproved($gym['email'], $ownerName, $gym['gym_name']);
                
                flash('Gym application approved successfully. Default exercises have been seeded.', 'success');
            } elseif ($action === 'reject') {
                $pdo->prepare('UPDATE gyms SET status = "rejected" WHERE gym_id = ?')->execute([$gymId]);
                
                notify_user((int)$gym['owner_user_id'], 'system', 'Application Rejected', "Your gym application for {$gym['gym_name']} has been rejected.");
                Emails::sendGymApplicationRejected($gym['email'], $ownerName, $gym['gym_name']);
                
                flash('Gym application rejected.', 'success');
            }
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
                        <?php table_empty(7, 'No pending applications.'); ?>
                        <?php else: foreach ($applications as $app): ?>
                            <tr>
                                <td><strong><?= h($app['name']) ?></strong></td>
                                <td><?= h($app['first_name'] . ' ' . $app['last_name']) ?></td>
                                <td><?= h($app['email']) ?></td>
                                <td><?= h($app['address']) ?></td>
                                <td><?= h($app['contact_info'] ?? 'N/A') ?></td>
                                <td>
                                    <div style="display:flex; flex-direction:column; gap:6px; min-width: 170px;">
                                        <?php if (!empty($app['business_permit_url'])): ?>
                                            <button type="button" class="btn-sm btn-ghost" style="display:inline-flex; align-items:center; gap:6px; justify-content:flex-start; text-align:left; font-size:12px; padding:4px 8px;" onclick="viewDocument('<?= h(upload_url($app['business_permit_url'], 'permits')) ?>', 'Business Permit')">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                                <span>Business Permit</span>
                                            </button>
                                        <?php endif; ?>

                                        <?php if (!empty($app['barangay_clearance_url'])): ?>
                                            <button type="button" class="btn-sm btn-ghost" style="display:inline-flex; align-items:center; gap:6px; justify-content:flex-start; text-align:left; font-size:12px; padding:4px 8px;" onclick="viewDocument('<?= h(upload_url($app['barangay_clearance_url'], 'permits')) ?>', 'Barangay Clearance')">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
                                                <span>Barangay Clearance</span>
                                            </button>
                                        <?php endif; ?>

                                        <?php if (!empty($app['valid_id_url'])): 
                                            $idLabel = !empty($app['valid_id_type']) ? $app['valid_id_type'] : 'Valid ID';
                                        ?>
                                            <button type="button" class="btn-sm btn-ghost" style="display:inline-flex; align-items:center; gap:6px; justify-content:flex-start; text-align:left; font-size:12px; padding:4px 8px;" onclick="viewDocument('<?= h(upload_url($app['valid_id_url'], 'permits')) ?>', 'Valid ID - <?= h(addslashes($idLabel)) ?>')">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><line x1="15" y1="8" x2="17" y2="8"/><line x1="15" y1="12" x2="17" y2="12"/><line x1="7" y1="16" x2="17" y2="16"/></svg>
                                                <span title="<?= h($idLabel) ?>"><?= h(mb_strimwidth($idLabel, 0, 22, '...')) ?></span>
                                            </button>
                                        <?php endif; ?>

                                        <?php if (!empty($app['fire_safety_cert_url'])): ?>
                                            <button type="button" class="btn-sm btn-ghost" style="display:inline-flex; align-items:center; gap:6px; justify-content:flex-start; text-align:left; font-size:12px; padding:4px 8px;" onclick="viewDocument('<?= h(upload_url($app['fire_safety_cert_url'], 'permits')) ?>', 'Fire Safety Inspection Certificate')">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
                                                <span>Fire Safety Cert.</span>
                                            </button>
                                        <?php endif; ?>

                                        <?php if (!empty($app['fb_page_url'])): ?>
                                            <a href="<?= h($app['fb_page_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn-sm btn-ghost" style="display:inline-flex; align-items:center; gap:6px; justify-content:flex-start; text-align:left; text-decoration:none; font-size:12px; padding:4px 8px; color:var(--primary, #84cc16);">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                                                <span>Facebook Page ↗</span>
                                            </a>
                                        <?php endif; ?>

                                        <?php if (empty($app['business_permit_url']) && empty($app['valid_id_url']) && empty($app['barangay_clearance_url'])): ?>
                                            <span class="muted" style="font-size:12px;">No documents</span>
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
                html: `<div style="display: flex; justify-content: center; align-items: center; width: 100%; height: 70vh;"><img src="${url}" style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 4px;" alt="${docTitle}"></div>`,
                width: '80%',
                showCloseButton: true,
                showConfirmButton: false,
                background: 'var(--surface, #18251eff)',
                color: 'var(--text-color, #ffffff)'
            });
        }
    }
    </script>
<?php
    render_footer();
}
