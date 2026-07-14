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
        $brandColor = trim((string) post('brand_color'));

        if ($brandColor !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $brandColor)) {
            $brandColor = null;
        }

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

        $logoUrl = $gym['logo_url'] ?? null;
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            try {
                $uploadedLogo = FileUpload::storeGymLogo($_FILES['logo'], (int) $gym['gym_id']);
                if ($logoUrl && file_exists(__DIR__ . '/../../assets/uploads/' . $logoUrl)) {
                    @unlink(__DIR__ . '/../../assets/uploads/' . $logoUrl);
                }
                $logoUrl = $uploadedLogo;
            } catch (RuntimeException $e) {
                flash($e->getMessage(), 'danger');
            }
        }

        if ($name && $address) {
            $pdo->prepare('UPDATE gyms SET name = ?, address = ?, contact_info = ?, business_permit_url = ?, logo_url = ?, brand_color = ? WHERE gym_id = ?')
                ->execute([$name, $address, $contact, $permitUrl, $logoUrl, $brandColor, $gym['gym_id']]);
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
                <p>Manage your gym's public details, logo, and brand color theme.</p>
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
                <h4 style="margin-top:0;">Gym Logo</h4>
                <?php if (!empty($gym['logo_url'])): ?>
                    <div style="margin-bottom: 12px; display: flex; align-items: center; gap: 15px;">
                        <img src="assets/uploads/<?= h($gym['logo_url']) ?>" alt="Logo Preview" style="height: 60px; max-width: 150px; object-fit: contain; background: rgba(0,0,0,0.2); padding: 5px; border-radius: 4px; border: 1px solid var(--line);">
                        <p style="font-size: 13px; color: var(--muted); margin: 0;">
                            Current Logo: <?= h($gym['logo_url']) ?>
                        </p>
                    </div>
                <?php else: ?>
                    <p style="font-size: 13px; color: var(--muted); margin-bottom: 10px;">No custom logo uploaded. Default brand logo will be used.</p>
                <?php endif; ?>
                
                <label style="margin: 0;">Upload New Logo (JPG, PNG, GIF, WEBP)
                    <input type="file" name="logo" accept="image/*">
                </label>
            </div>

            <label style="grid-column: 1 / -1;">Brand Theme Color
                <input type="color" name="brand_color" value="<?= h($gym['brand_color'] ?? '#c7ff22') ?>" style="display: block; width: 100%; height: 40px; padding: 4px; cursor: pointer; border-radius: 8px; border: 1px solid var(--line); background: var(--bg);">
                <span style="font-size: 11px; color: var(--muted); margin-top: 4px; display: block;">Pick a custom color to style your dashboard buttons, active nav elements, highlights, and icons.</span>
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
