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
    <style>
        .profile-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 24px;
            margin-top: 20px;
        }
        
        @media (max-width: 850px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .profile-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.02) 0%, rgba(255, 255, 255, 0.005) 100%);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 24px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            margin-bottom: 24px;
        }
        
        .profile-card h3 {
            margin: 0 0 18px 0;
            font-size: 15px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--lime);
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid var(--line);
            padding-bottom: 12px;
        }
        
        .profile-card h3 svg {
            color: var(--lime);
            flex-shrink: 0;
        }
        
        .profile-input-group {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .custom-file-upload {
            border: 2px dashed var(--line);
            border-radius: 10px;
            padding: 22px;
            text-align: center;
            cursor: pointer;
            background: rgba(255, 255, 255, 0.01);
            transition: all 0.25s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }
        
        .custom-file-upload:hover {
            border-color: var(--lime);
            background: rgba(199, 255, 34, 0.02);
        }
        
        .custom-file-upload svg {
            color: var(--muted);
            transition: color 0.25s ease;
        }
        
        .custom-file-upload:hover svg {
            color: var(--lime);
        }
        
        .custom-file-upload input[type="file"] {
            display: none;
        }
        
        .preview-container {
            display: flex;
            align-items: center;
            gap: 16px;
            background: rgba(255, 255, 255, 0.015);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 14px;
        }
        
        .preview-container img {
            height: 60px;
            width: 60px;
            object-fit: cover;
            border-radius: 6px;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid var(--line);
        }
        
        .preview-info {
            flex: 1;
            min-width: 0;
        }
        
        .preview-info p {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
            color: var(--ink);
        }
        
        .preview-info span {
            font-size: 11px;
            color: var(--muted);
            display: block;
            word-break: break-all;
        }
        
        .color-picker-wrapper {
            display: flex;
            align-items: center;
            gap: 16px;
            background: rgba(255, 255, 255, 0.015);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 12px;
        }
        
        .color-swatch-grid {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        
        .color-swatch {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: 2px solid transparent;
            cursor: pointer;
            transition: transform 0.2s ease, border-color 0.2s ease;
        }
        
        .color-swatch:hover {
            transform: scale(1.15);
        }
        
        .color-swatch.active {
            border-color: #ffffff;
            box-shadow: 0 0 10px rgba(255, 255, 255, 0.4);
        }
    </style>

    <div class="page-header">
        <h1>Gym Administration Profile</h1>
        <p>Branding, customization options, metadata, and license documents for your gym.</p>
    </div>

    <form method="post" enctype="multipart/form-data" id="gym-profile-form">
        <?= csrf_field() ?>
        
        <div class="profile-grid">
            <!-- Left Column: Details -->
            <div class="profile-column">
                <div class="profile-card">
                    <h3>
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                        General Information
                    </h3>
                    <div class="profile-input-group">
                        <label>Gym Name
                            <input type="text" name="name" value="<?= h($gym['name']) ?>" required>
                        </label>

                        <label>Address
                            <input type="text" name="address" value="<?= h($gym['address']) ?>" required>
                        </label>

                        <label>Contact Info
                            <input type="text" name="contact_info" value="<?= h($gym['contact_info']) ?>" placeholder="Phone or Email">
                        </label>
                    </div>
                </div>
                
                <div class="profile-card">
                    <h3>
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                        Business Permit
                    </h3>
                    
                    <?php if (!empty($gym['business_permit_url'])): 
                        $isPdf = strtolower(pathinfo($gym['business_permit_url'], PATHINFO_EXTENSION)) === 'pdf';
                    ?>
                        <div class="preview-container">
                            <div style="width: 42px; height: 42px; border-radius: 6px; background: rgba(255,255,255,0.03); border:1px solid var(--line); display:flex; align-items:center; justify-content:center; color: var(--lime); flex-shrink: 0;">
                                <?php if ($isPdf): ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                                <?php else: ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                <?php endif; ?>
                            </div>
                            <div class="preview-info">
                                <p>Uploaded License Permit</p>
                                <span><?= h($gym['business_permit_url']) ?></span>
                            </div>
                            <a href="assets/permits/<?= h($gym['business_permit_url']) ?>" target="_blank" class="button-link btn-secondary btn-sm" style="min-height:30px;">View Document</a>
                        </div>
                    <?php else: ?>
                        <div style="background: rgba(255,77,93,0.05); border: 1px dashed rgba(255,77,93,0.25); color: var(--danger); border-radius: 8px; padding: 12px; font-size: 13px; margin-bottom: 14px; font-weight:700; display:flex; align-items:center; gap:8px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            No permit uploaded yet.
                        </div>
                    <?php endif; ?>

                    <label class="custom-file-upload">
                        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <span>Choose Permit File (PDF, JPG, PNG)</span>
                        <input type="file" name="business_permit" accept=".pdf,.jpg,.jpeg,.png">
                    </label>
                </div>
            </div>
            
            <!-- Right Column: Personalization -->
            <div class="profile-column">
                <div class="profile-card">
                    <h3>
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M21 12H3M12 3v18"/></svg>
                        Branding Customization
                    </h3>
                    
                    <div style="margin-bottom: 20px;">
                        <h4 style="margin: 0 0 10px 0; font-size: 13px; color: var(--muted); text-transform:uppercase; letter-spacing:0.04em;">Gym Logo</h4>
                        <?php if (!empty($gym['logo_url'])): ?>
                            <div class="preview-container">
                                <img src="assets/uploads/<?= h($gym['logo_url']) ?>" alt="Logo Preview">
                                <div class="preview-info">
                                    <p>Active Logo Trademark</p>
                                    <span><?= h($gym['logo_url']) ?></span>
                                </div>
                            </div>
                        <?php else: ?>
                            <p style="font-size: 13px; color: var(--muted); margin: 0 0 14px 0;">No trademark logo uploaded. Default brand logo will be used.</p>
                        <?php endif; ?>
                        
                        <label class="custom-file-upload">
                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            <span>Choose Logo Image (JPG, PNG, GIF, WEBP)</span>
                            <input type="file" name="logo" accept="image/*">
                        </label>
                    </div>

                    <div>
                        <h4 style="margin: 0 0 4px 0; font-size: 13px; color: var(--muted); text-transform:uppercase; letter-spacing:0.04em;">Brand Color Theme</h4>
                        <span style="font-size: 11px; color: var(--muted); margin-bottom: 12px; display: block;">Select a preset theme or pick a custom hex value.</span>
                        
                        <div class="color-picker-wrapper">
                            <input type="color" name="brand_color" id="brand_color_picker" value="<?= h($gym['brand_color'] ?? '#c7ff22') ?>" style="width: 48px; height: 38px; padding: 2px; cursor: pointer; border-radius: 6px; border: 1px solid var(--line); background: transparent; flex-shrink:0;">
                            <div style="flex:1;">
                                <span style="font-size: 12px; font-weight:700; color:var(--ink);">Custom Accent Picker</span>
                            </div>
                        </div>

                        <div class="color-swatch-grid">
                            <?php 
                                $presets = [
                                    '#c7ff22' => 'var(--lime)',
                                    '#7c5cfc' => 'Royal Purple',
                                    '#10b981' => 'Emerald Green',
                                    '#ff4d5d' => 'Crimson Red',
                                    '#ff9548' => 'Sunset Orange',
                                    '#06b6d4' => 'Teal Blue'
                                ];
                                $currentColor = $gym['brand_color'] ?? '#c7ff22';
                                foreach ($presets as $hex => $label):
                                    $isActive = strtolower($currentColor) === strtolower($hex);
                            ?>
                                <div class="color-swatch <?= $isActive ? 'active' : '' ?>" 
                                     data-color="<?= h($hex) ?>" 
                                     style="background-color: <?= h($hex) ?>;" 
                                     title="<?= h($label) ?>"></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div style="margin-top: 10px; display: flex; justify-content: flex-end;">
            <button type="submit" class="btn-primary" style="padding: 12px 32px; min-height: 46px;">Save Changes</button>
        </div>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Update labels dynamically on file selection
            const fileInputs = document.querySelectorAll('input[type="file"]');
            fileInputs.forEach(input => {
                input.addEventListener('change', function(e) {
                    const label = this.closest('.custom-file-upload').querySelector('span');
                    if (this.files && this.files.length > 0) {
                        label.textContent = 'Selected: ' + this.files[0].name;
                        label.style.color = 'var(--lime)';
                    }
                });
            });
            
            // Preset theme colors click trigger
            const colorInput = document.getElementById('brand_color_picker');
            const swatches = document.querySelectorAll('.color-swatch');
            
            swatches.forEach(swatch => {
                swatch.addEventListener('click', function() {
                    const color = this.dataset.color;
                    if (colorInput) {
                        colorInput.value = color;
                    }
                    swatches.forEach(s => s.classList.remove('active'));
                    this.classList.add('active');
                });
            });
        });
    </script>
<?php
    render_footer();
}
