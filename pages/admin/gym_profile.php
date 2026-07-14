<?php

declare(strict_types=1);

function gym_profile_page(): void
{
    $user = require_roles(['gym_owner']);
    $pdo = db();

    $gym = $pdo->query('SELECT * FROM gyms WHERE owner_user_id = ' . (int)$user['user_id'] . ' LIMIT 1')->fetch();
    
    if (!$gym) {
        flash('No gym found for your account. Please complete registration if you haven\'t.', 'danger');
        redirect('dashboard');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim((string) post('name'));
        $address = trim((string) post('address'));
        $contact = trim((string) post('contact_info'));

        $permitUrl = $gym['business_permit_url'];
        if (isset($_FILES['business_permit']) && $_FILES['business_permit']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['business_permit']['tmp_name'];
            $ext = pathinfo($_FILES['business_permit']['name'], PATHINFO_EXTENSION);
            $filename = uniqid('permit_') . '.' . $ext;
            if (move_uploaded_file($tmp, __DIR__ . '/../../assets/permits/' . $filename)) {
                $permitUrl = $filename;
            } else {
                flash('Failed to upload business permit.', 'danger');
            }
        }

        if ($name && $address) {
            $pdo->prepare('UPDATE gyms SET name = ?, address = ?, contact_info = ?, business_permit_url = ? WHERE gym_id = ?')
                ->execute([$name, $address, $contact, $permitUrl, $gym['gym_id']]);
            flash('Gym profile updated successfully.', 'success');
            redirect('gym_profile');
        } else {
            flash('Name and address are required.', 'danger');
        }
    }

    render_header('Gym Profile', $user);
?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Gym Profile</h1>
                <p>Manage your gym's public details and business permit.</p>
            </div>
        </div>

        <form method="post" enctype="multipart/form-data" class="form grid-form" style="max-width: 600px;">
            <?= csrf_field() ?>
            
            <label style="grid-column: 1 / -1;">Gym Name
                <input type="text" name="name" value="<?= h($gym['name']) ?>" required>
            </label>

            <label style="grid-column: 1 / -1;">Address
                <input type="text" name="address" value="<?= h($gym['address']) ?>" required>
            </label>

            <label style="grid-column: 1 / -1;">Contact Info
                <input type="text" name="contact_info" value="<?= h($gym['contact_info']) ?>" placeholder="Phone or Email">
            </label>

            <div style="grid-column: 1 / -1; background: rgba(255,255,255,0.02); padding: 16px; border-radius: 8px; margin-top: 10px;">
                <h4 style="margin-top:0;">Business Permit</h4>
                <?php if ($gym['business_permit_url']): ?>
                    <p style="font-size: 13px; color: var(--muted); margin-bottom: 10px;">
                        Current Document: <a href="assets/permits/<?= h($gym['business_permit_url']) ?>" target="_blank" style="color: var(--lime);"><?= h($gym['business_permit_url']) ?></a>
                    </p>
                <?php else: ?>
                    <p style="font-size: 13px; color: var(--danger); margin-bottom: 10px;">No document uploaded.</p>
                <?php endif; ?>
                
                <label style="margin: 0;">Upload New Document (PDF, JPG, PNG)
                    <input type="file" name="business_permit" accept=".pdf,.jpg,.jpeg,.png">
                </label>
            </div>

            <button type="submit" class="btn-primary" style="grid-column: 1 / -1; margin-top: 15px;">Save Changes</button>
        </form>
    </section>
<?php
    render_footer();
}
