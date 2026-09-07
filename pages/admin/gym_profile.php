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

    $canCustomBrand = gym_has_feature('custom_branding', $gym);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $name = trim((string) post('name'));
        $address = trim((string) post('address'));
        $contact = trim((string) post('contact_info'));
        if ($canCustomBrand) {
            $brandColor = trim((string) post('brand_color'));
            if ($brandColor !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $brandColor)) {
                $brandColor = null;
            }
        } else {
            $brandColor = $gym['brand_color'] ?? null;
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

        // Handle Gallery Uploads
        if (isset($_FILES['gallery_images']) && is_array($_FILES['gallery_images']['tmp_name'])) {
            $currentGalleryCount = $pdo->query('SELECT COUNT(*) FROM gym_images WHERE gym_id = ' . (int)$gym['gym_id'])->fetchColumn();
            $files = $_FILES['gallery_images'];
            $uploadCount = 0;
            
            for ($i = 0; $i < count($files['tmp_name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    if ($currentGalleryCount + $uploadCount >= 10) {
                        flash('You can only upload a maximum of 10 images.', 'warning');
                        break;
                    }
                    
                    $singleFile = [
                        'name' => $files['name'][$i],
                        'type' => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error' => $files['error'][$i],
                        'size' => $files['size'][$i],
                    ];
                    
                    try {
                        $uploadedImage = FileUpload::storeGymGalleryImage($singleFile, (int) $gym['gym_id']);
                        $pdo->prepare('INSERT INTO gym_images (gym_id, image_url) VALUES (?, ?)')
                            ->execute([$gym['gym_id'], $uploadedImage]);
                        $uploadCount++;
                    } catch (RuntimeException $e) {
                        flash('Gallery Upload Error: ' . $e->getMessage(), 'danger');
                    }
                }
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

    if (isset($_POST['delete_gallery_image'])) {
        $imageId = (int) $_POST['image_id'];
        $image = $pdo->prepare('SELECT * FROM gym_images WHERE id = ? AND gym_id = ?');
        $image->execute([$imageId, $gym['gym_id']]);
        $image = $image->fetch();
        
        if ($image) {
            FileUpload::deleteGymGalleryImage($image['image_url']);
            $pdo->prepare('DELETE FROM gym_images WHERE id = ?')->execute([$imageId]);
            flash('Image deleted successfully.', 'success');
        }
        redirect('gym_profile');
    }

    $galleryImages = $pdo->query('SELECT * FROM gym_images WHERE gym_id = ' . (int)$gym['gym_id'] . ' ORDER BY created_at DESC')->fetchAll();
    $gymTier = gym_subscription_tier($gym);
    $memberCount = gym_active_member_count((int)$gym['gym_id']);
    $memberLimit = gym_member_limit($gym);

    render_header('Gym Profile', $user);
?>
    <style>
        #gym-profile-form {
            padding-bottom: 70px;
        }

        .profile-page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--line);
        }

        .profile-header-main {
            flex: 1;
            min-width: 260px;
        }

        .profile-title {
            margin: 0 0 6px;
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--ink);
            letter-spacing: -0.5px;
            word-break: break-word;
        }

        .profile-subtitle {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.45;
        }

        .profile-meta-badges {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .tier-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            background: color-mix(in srgb, var(--lime, #84cc16) 12%, transparent);
            color: var(--lime, #84cc16);
            border: 1px solid color-mix(in srgb, var(--lime, #84cc16) 28%, transparent);
            white-space: nowrap;
        }

        .capacity-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: var(--panel-soft);
            color: var(--muted);
            border: 1px solid var(--line);
            white-space: nowrap;
        }

        .profile-actions-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .action-btn-preview {
            background: var(--panel) !important;
            color: var(--ink) !important;
            border: 1px solid var(--line) !important;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
            transition: all 0.2s ease;
        }

        .action-btn-preview:hover {
            background: var(--panel-soft) !important;
            border-color: var(--lime, #84cc16) !important;
            color: var(--ink) !important;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            gap: 24px;
        }

        .profile-column {
            min-width: 0;
        }

        .profile-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            margin-bottom: 24px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            box-sizing: border-box;
            width: 100%;
        }

        .profile-card:hover {
            border-color: color-mix(in srgb, var(--lime, #84cc16) 30%, var(--line));
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
        }

        .profile-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--line);
            flex-wrap: wrap;
            gap: 8px;
        }

        .profile-card-title {
            margin: 0;
            font-size: 15px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--lime, #84cc16);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .input-icon-wrap {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            color: var(--muted);
            pointer-events: none;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .input-with-icon {
            width: 100% !important;
            padding-left: 42px !important;
            padding-right: 14px !important;
            box-sizing: border-box !important;
            height: 44px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: var(--panel-soft);
            color: var(--ink);
            font-size: 14.5px;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }

        .input-with-icon:focus {
            outline: none;
            border-color: var(--lime, #84cc16);
            background: var(--panel);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--lime, #84cc16) 18%, transparent);
        }

        .profile-input-group {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .field-label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 6px;
            color: var(--ink);
        }

        .field-hint {
            display: block;
            font-size: 11.5px;
            color: var(--muted);
            margin-top: 4px;
            line-height: 1.4;
        }

        /* Modern upload zone */
        .modern-dropzone {
            border: 2px dashed var(--line);
            border-radius: 12px;
            padding: 24px 16px;
            text-align: center;
            cursor: pointer;
            background: color-mix(in srgb, var(--panel-soft) 40%, transparent);
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            box-sizing: border-box;
            width: 100%;
        }

        .modern-dropzone:hover,
        .modern-dropzone.drag-over {
            border-color: var(--lime, #84cc16) !important;
            background: color-mix(in srgb, var(--lime, #84cc16) 8%, var(--panel)) !important;
            box-shadow: 0 0 16px color-mix(in srgb, var(--lime, #84cc16) 20%, transparent);
        }

        .modern-dropzone svg {
            color: var(--muted);
            transition: color 0.2s ease;
        }

        .modern-dropzone:hover svg,
        .modern-dropzone.drag-over svg {
            color: var(--lime, #84cc16);
        }

        .modern-dropzone input[type="file"] {
            display: none;
        }

        .logo-upload-group {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 14px;
        }

        .logo-avatar-wrap {
            position: relative;
            width: 76px;
            height: 76px;
            border-radius: 12px;
            background: var(--panel-soft);
            border: 2px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            box-shadow: var(--shadow);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .logo-avatar-wrap:hover {
            border-color: var(--lime, #84cc16);
            transform: scale(1.03);
        }

        .logo-avatar-wrap .logo-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.65);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            gap: 2px;
            text-align: center;
            padding: 4px;
        }

        .logo-avatar-wrap:hover .logo-overlay {
            opacity: 1;
        }

        .logo-upload-details {
            flex: 1;
            min-width: 0;
        }

        .doc-badge-row {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 16px;
            border-radius: 10px;
            background: var(--panel-soft);
            border: 1px solid var(--line);
            margin-bottom: 14px;
            box-sizing: border-box;
            width: 100%;
        }

        .doc-badge-icon {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            background: color-mix(in srgb, var(--lime, #84cc16) 12%, transparent);
            border: 1px solid color-mix(in srgb, var(--lime, #84cc16) 25%, transparent);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--lime, #84cc16);
            flex-shrink: 0;
            overflow: hidden;
        }

        .doc-badge-text {
            flex: 1;
            min-width: 0;
        }

        .doc-badge-title {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--ink);
            margin-bottom: 2px;
        }

        .doc-badge-filename {
            font-size: 12px;
            color: var(--muted);
            word-break: break-all;
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .color-picker-row {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 14px;
            border-radius: 10px;
            background: var(--panel-soft);
            border: 1px solid var(--line);
            margin-bottom: 14px;
            box-sizing: border-box;
            width: 100%;
        }

        .color-picker-info {
            flex: 1;
            min-width: 0;
        }

        .color-swatch-grid {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .color-swatch {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            border: 2px solid transparent;
            cursor: pointer;
            transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
            touch-action: manipulation;
            flex-shrink: 0;
        }

        .color-swatch:hover {
            transform: scale(1.15);
        }

        .color-swatch.active {
            border-color: var(--ink);
            box-shadow: 0 0 10px color-mix(in srgb, var(--ink) 40%, transparent);
        }

        .gallery-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            gap: 14px;
            margin-top: 16px;
        }

        .gallery-thumb-item {
            position: relative;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid var(--line);
            aspect-ratio: 1;
            background: var(--panel-soft);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .gallery-thumb-item:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .gallery-thumb-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .gallery-delete-btn {
            position: absolute;
            top: 6px;
            right: 6px;
            background: rgba(239, 68, 68, 0.9);
            border: none;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 18px;
            line-height: 1;
            transition: background 0.15s, transform 0.15s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.5);
            touch-action: manipulation;
        }

        .gallery-delete-btn:hover {
            background: #ef4444;
            transform: scale(1.1);
        }

        .queued-files-preview {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
        }

        .queued-chip {
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 6px;
            background: color-mix(in srgb, var(--lime, #84cc16) 12%, transparent);
            color: var(--lime, #84cc16);
            border: 1px solid color-mix(in srgb, var(--lime, #84cc16) 25%, transparent);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            max-width: 100%;
            word-break: break-all;
        }

        .sticky-save-bar {
            margin-top: 24px;
            padding: 16px 24px;
            border-radius: 14px;
            background: color-mix(in srgb, var(--panel) 94%, transparent);
            border: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            position: sticky;
            bottom: max(16px, env(safe-area-inset-bottom, 16px));
            z-index: 30;
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            box-shadow: var(--shadow);
            box-sizing: border-box;
            width: 100%;
        }

        .save-bar-status-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        /* Responsive Breakpoints */
        @media (max-width: 1024px) {
            .profile-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }

        @media (max-width: 768px) {
            .input-with-icon {
                font-size: 16px !important; /* Prevents auto-zoom on iOS */
            }

            .profile-page-header {
                flex-direction: column;
                align-items: stretch;
                gap: 14px;
                margin-bottom: 20px;
                padding-bottom: 16px;
            }

            .profile-title {
                font-size: 1.5rem;
            }

            .profile-actions-bar {
                width: 100%;
                display: flex;
                flex-direction: row;
                gap: 10px;
            }

            .profile-actions-bar a.btn,
            .profile-actions-bar button.btn {
                flex: 1 1 0;
                min-width: 0;
                justify-content: center;
                text-align: center;
                padding: 10px 14px;
                font-size: 13px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .profile-card {
                padding: 20px 16px;
                border-radius: 14px;
                margin-bottom: 20px;
            }

            .gallery-grid {
                grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
                gap: 12px;
            }
        }

        @media (max-width: 540px) {
            .profile-title {
                font-size: 1.35rem;
            }

            .profile-actions-bar {
                flex-direction: column;
                gap: 8px;
            }

            .profile-actions-bar a.btn,
            .profile-actions-bar button.btn {
                width: 100%;
                flex: none;
            }

            .doc-badge-row {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
                padding: 14px;
            }

            .doc-badge-row .doc-badge-action {
                width: 100%;
                justify-content: center;
                text-align: center;
            }

            .logo-upload-group {
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 14px;
            }

            .logo-upload-details {
                width: 100%;
                display: flex;
                flex-direction: column;
                align-items: center;
            }

            .logo-upload-details label.btn {
                width: 100%;
                justify-content: center;
                box-sizing: border-box;
            }

            .color-picker-row {
                flex-wrap: wrap;
                gap: 10px;
            }

            #live-accent-chip {
                width: 100%;
                text-align: center;
                justify-content: center;
                box-sizing: border-box;
            }

            .gallery-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }

            .sticky-save-bar {
                flex-direction: column;
                align-items: stretch;
                padding: 14px 16px;
                bottom: max(10px, env(safe-area-inset-bottom, 10px));
                gap: 10px;
            }

            .save-bar-status-wrap {
                justify-content: center;
                text-align: center;
                font-size: 12px;
            }

            .sticky-save-bar #save-btn {
                width: 100%;
                justify-content: center;
                padding: 12px;
                font-size: 14px;
            }

            #gym-profile-form {
                padding-bottom: 90px;
            }
        }

        @media (max-width: 360px) {
            .profile-card {
                padding: 16px 12px;
            }

            .tier-pill, .capacity-pill {
                font-size: 11px;
                padding: 3px 8px;
            }

            .modern-dropzone {
                padding: 18px 10px;
            }
        }
    </style>

    <div class="profile-page-header">
        <div class="profile-header-main">
            <h1 class="profile-title">
                Gym Profile & Settings
            </h1>
            <p class="profile-subtitle">
                Manage your facility details, visual branding, business license, and photo gallery.
            </p>
            <div class="profile-meta-badges">
                <span class="tier-pill">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                    <?= h(ucfirst($gymTier)) ?> Plan
                </span>
                <span class="capacity-pill">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    Members: <?= $memberCount ?> / <?= $memberLimit === PHP_INT_MAX ? '∞' : $memberLimit ?>
                </span>
                <?php if ($gymTier !== 'business'): ?>
                    <a href="index.php?page=gym_subscription" style="font-size: 12px; font-weight: 700; color: var(--lime, #84cc16); text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                        Upgrade Plan →
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="profile-actions-bar">
            <a href="index.php?page=view_gym&gym_id=<?= (int)$gym['gym_id'] ?>" target="_blank" class="btn btn-secondary action-btn-preview" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; font-size: 13.5px; border-radius: 10px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <span>Preview Public Gym Page</span>
            </a>
            <button type="button" id="top-save-btn" onclick="document.getElementById('gym-profile-form').requestSubmit();" class="btn btn-primary action-btn-save" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 22px; font-size: 14px; font-weight: 700; border-radius: 10px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                <span>Save Changes</span>
            </button>
        </div>
    </div>

    <form method="post" enctype="multipart/form-data" id="gym-profile-form" onsubmit="handleFormSubmit(this)">
        <?= csrf_field() ?>
        
        <div class="profile-grid">
            <div class="profile-column">
                <div class="profile-card">
                    <div class="profile-card-header">
                        <h3 class="profile-card-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                            General Information
                        </h3>
                        <small style="color: var(--muted); font-size: 12px;">Basic facility details</small>
                    </div>

                    <div class="profile-input-group">
                        <div>
                            <label class="field-label">Gym Facility Name <span style="color: var(--danger, #ef4444);">*</span></label>
                            <div class="input-icon-wrap">
                                <span class="input-icon">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                </span>
                                <input type="text" name="name" class="input-with-icon" value="<?= h($gym['name']) ?>" required placeholder="e.g. Iron Fitness Center">
                            </div>
                        </div>

                        <div>
                            <label class="field-label">Physical Address / Location <span style="color: var(--danger, #ef4444);">*</span></label>
                            <div class="input-icon-wrap">
                                <span class="input-icon">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                </span>
                                <input type="text" name="address" class="input-with-icon" value="<?= h($gym['address']) ?>" required placeholder="e.g. 123 Fitness Ave, District 4">
                            </div>
                        </div>

                        <div>
                            <label class="field-label">Contact Information</label>
                            <div class="input-icon-wrap">
                                <span class="input-icon">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                </span>
                                <input type="text" name="contact_info" class="input-with-icon" value="<?= h($gym['contact_info']) ?>" placeholder="e.g. +63 912 345 6789 / info@gym.com">
                            </div>
                            <span class="field-hint">Phone or official email displayed to prospective gym members.</span>
                        </div>
                    </div>
                </div>
                
                <div class="profile-card">
                    <div class="profile-card-header">
                        <h3 class="profile-card-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            Business License & Permit
                        </h3>
                        <?php if (!empty($gym['business_permit_url'])): ?>
                            <span style="font-size: 11px; font-weight: 700; color: var(--teal, #10b981); background: color-mix(in srgb, var(--teal, #10b981) 14%, transparent); border: 1px solid color-mix(in srgb, var(--teal, #10b981) 28%, transparent); padding: 3px 8px; border-radius: 6px;">
                                Permit on File
                            </span>
                        <?php else: ?>
                            <span style="font-size: 11px; font-weight: 700; color: var(--danger, #ef4444); background: color-mix(in srgb, var(--danger, #ef4444) 14%, transparent); border: 1px solid color-mix(in srgb, var(--danger, #ef4444) 28%, transparent); padding: 3px 8px; border-radius: 6px;">
                                Missing Permit
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($gym['business_permit_url'])): 
                        $permitExt = strtolower(pathinfo($gym['business_permit_url'], PATHINFO_EXTENSION));
                        $isPdf = $permitExt === 'pdf';
                        $isImg = in_array($permitExt, ['jpg', 'jpeg', 'png', 'webp']);
                    ?>
                        <div class="doc-badge-row">
                            <div class="doc-badge-icon">
                                <?php if ($isImg): ?>
                                    <img src="assets/permits/<?= h($gym['business_permit_url']) ?>" alt="Permit" style="width: 100%; height: 100%; object-fit: cover;">
                                <?php elseif ($isPdf): ?>
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                <?php else: ?>
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                <?php endif; ?>
                            </div>
                            <div class="doc-badge-text">
                                <div class="doc-badge-title">Official Permit Document</div>
                                <span class="doc-badge-filename">
                                    <?= h($gym['business_permit_url']) ?>
                                </span>
                            </div>
                            <a href="assets/permits/<?= h($gym['business_permit_url']) ?>" target="_blank" class="btn btn-secondary doc-badge-action" style="font-size: 12.5px; padding: 7px 14px; border-radius: 8px; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; background: var(--panel); border: 1px solid var(--line); color: var(--ink);">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                <span>View Document</span>
                            </a>
                        </div>
                    <?php else: ?>
                        <div style="background: color-mix(in srgb, var(--danger, #ef4444) 8%, transparent); border: 1px dashed color-mix(in srgb, var(--danger, #ef4444) 30%, transparent); color: var(--danger, #ef4444); border-radius: 10px; padding: 14px; font-size: 13px; margin-bottom: 16px; display:flex; align-items:center; gap:10px;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <span>No business permit uploaded yet. Upload your license or permit below to maintain verified status.</span>
                        </div>
                    <?php endif; ?>

                    <label class="modern-dropzone" id="permit-dropzone">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <span id="permit-label-text" style="font-size: 13.5px; font-weight: 600; color: var(--ink);">
                            <?= !empty($gym['business_permit_url']) ? 'Click or Drag to Replace Permit' : 'Click or Drag to Upload Business Permit' ?>
                        </span>
                        <span style="font-size: 11.5px; color: var(--muted);">Supports PDF, JPG, PNG (Max 10MB)</span>
                        <input type="file" name="business_permit" id="permit-file-input" accept=".pdf,.jpg,.jpeg,.png">
                    </label>
                </div>
            </div>
            
            <div class="profile-column">
                <div class="profile-card">
                    <div class="profile-card-header">
                        <h3 class="profile-card-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M21 12H3M12 3v18"/></svg>
                            Branding Customization
                        </h3>
                        <small style="color: var(--muted); font-size: 12px;">Visual identity</small>
                    </div>
                    
                    <div style="margin-bottom: 24px; padding-bottom: 22px; border-bottom: 1px solid var(--line);">
                        <label class="field-label">Gym Trademark Logo</label>
                        <span class="field-hint" style="margin-bottom: 14px;">Displayed in your gym portal navigation, member app, and receipts.</span>
                        
                        <div class="logo-upload-group">
                            <div class="logo-avatar-wrap" id="logo-avatar-wrap" title="Click to upload a new logo">
                                <img id="logo-preview-img" 
                                     src="<?= !empty($gym['logo_url']) ? h(upload_url($gym['logo_url'])) : 'data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'76\' height=\'76\' viewBox=\'0 0 76 76\'><rect width=\'76\' height=\'76\' rx=\'12\' fill=\'%23334155\'/><text x=\'50%25\' y=\'54%25\' font-size=\'22\' font-weight=\'bold\' fill=\'%2384cc16\' text-anchor=\'middle\' dominant-baseline=\'middle\'>FT</text></svg>' ?>" 
                                     alt="Logo Preview" 
                                     style="width: 100%; height: 100%; object-fit: contain; padding: 6px; box-sizing: border-box;">
                                <div class="logo-overlay">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                    <span>Change</span>
                                </div>
                            </div>
                            <div class="logo-upload-details">
                                <div id="logo-status-text" style="font-size: 13.5px; font-weight: 700; color: var(--ink); margin-bottom: 4px;">
                                    <?= !empty($gym['logo_url']) ? 'Active Logo Loaded' : 'Using Default FitTrack Icon' ?>
                                </div>
                                <span style="font-size: 12px; color: var(--muted); display: block; margin-bottom: 10px;">Square aspect ratio (e.g. 500x500 PNG) recommended.</span>
                                
                                <label class="btn btn-secondary" style="font-size: 12px; padding: 6px 14px; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; background: var(--panel); border: 1px solid var(--line); color: var(--ink);">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                    <span>Select New Logo</span>
                                    <input type="file" name="logo" id="logo-file-input" accept="image/*" style="display: none;">
                                </label>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; flex-wrap: wrap; gap: 6px;">
                            <label class="field-label" style="margin-bottom: 0;">Brand Color Theme</label>
                            <?php if (!$canCustomBrand): ?>
                                <span style="font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 4px; background: color-mix(in srgb, var(--lime, #84cc16) 14%, transparent); color: var(--lime, #84cc16); border: 1px solid color-mix(in srgb, var(--lime, #84cc16) 28%, transparent); letter-spacing: 0.5px;">BUSINESS PLAN</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!$canCustomBrand): ?>
                            <div style="background: color-mix(in srgb, var(--panel-soft) 70%, transparent); border: 1px dashed var(--line); border-radius: 12px; padding: 16px; margin-top: 8px;">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--lime, #84cc16)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                                    <strong style="font-size: 13.5px; color: var(--ink);">Custom Theme Locked</strong>
                                </div>
                                <p style="margin: 0 0 12px; font-size: 12.5px; color: var(--muted); line-height: 1.5;">
                                    Custom accent color palettes are exclusive to the <strong style="color: var(--ink);">Business Tier</strong>. Upgrade to automatically apply your gym's custom branding across all member, scanner, and navigation views.
                                </p>
                                <a href="index.php?page=gym_subscription" class="btn btn-primary" style="font-size: 12.5px; padding: 8px 16px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                                    Upgrade to Business Plan →
                                </a>
                            </div>
                        <?php else: ?>
                            <span class="field-hint" style="margin-bottom: 12px;">Choose a preset accent or click the color picker for a custom HEX code.</span>
                            
                            <div class="color-picker-row">
                                <input type="color" name="brand_color" id="brand_color_picker" value="<?= h($gym['brand_color'] ?? '#c7ff22') ?>" style="width: 44px; height: 38px; padding: 0; cursor: pointer; border-radius: 8px; border: 1px solid var(--line); background: transparent; flex-shrink: 0;">
                                <div class="color-picker-info">
                                    <div style="font-size: 13px; font-weight: 700; color: var(--ink);">Accent Color Picker</div>
                                    <code id="hex-label" style="font-size: 12px; color: var(--muted); font-family: monospace;"><?= h($gym['brand_color'] ?? '#c7ff22') ?></code>
                                </div>
                                <div id="live-accent-chip" style="padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; color: #0b110e; background: <?= h($gym['brand_color'] ?? '#c7ff22') ?>; box-shadow: 0 2px 10px rgba(0,0,0,0.3); white-space: nowrap;">
                                    Active Preview
                                </div>
                            </div>

                            <div class="color-swatch-grid">
                                <?php 
                                    $presets = [
                                        '#84cc16' => 'Lime Green',
                                        '#7c5cfc' => 'Royal Purple',
                                        '#10b981' => 'Emerald Green',
                                        '#ef4444' => 'Crimson Red',
                                        '#f97316' => 'Sunset Orange',
                                        '#06b6d4' => 'Cyan Blue',
                                        '#3b82f6' => 'Electric Blue',
                                        '#eab308' => 'Gold Yellow',
                                    ];
                                    $currentColor = $gym['brand_color'] ?? '#84cc16';
                                    foreach ($presets as $hex => $label):
                                        $isActive = strtolower($currentColor) === strtolower($hex);
                                ?>
                                    <div class="color-swatch <?= $isActive ? 'active' : '' ?>" 
                                         data-color="<?= h($hex) ?>" 
                                         style="background-color: <?= h($hex) ?>;" 
                                         title="<?= h($label) ?>"></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <?php 
            $currentGalleryCount = count($galleryImages);
            $remainingSlots = 10 - $currentGalleryCount;
        ?>

        <div class="profile-card" style="margin-top: 4px;">
            <div class="gallery-card-header">
                <div>
                    <h3 class="profile-card-title" style="margin-bottom: 4px;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        Gym Photo Gallery
                    </h3>
                    <span style="font-size: 12.5px; color: var(--muted);">Showcase your gym equipment, training floors, and workout spaces to prospective members.</span>
                </div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span class="capacity-pill" style="font-size: 12px; font-weight: 700;">
                        <?= $currentGalleryCount ?> / 10 Photos
                    </span>
                </div>
            </div>
            
            <?php if ($remainingSlots <= 0): ?>
                <div style="background: color-mix(in srgb, var(--orange, #f59e0b) 12%, transparent); border: 1px dashed color-mix(in srgb, var(--orange, #f59e0b) 35%, transparent); border-radius: 12px; padding: 18px; text-align: center; color: var(--ink); margin-bottom: 20px;">
                    <div style="display:flex; align-items:center; justify-content:center; gap:8px; margin-bottom:4px;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--orange, #f59e0b)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <strong style="font-size: 14px; color: var(--ink);">Photo Gallery Limit Reached (10 / 10)</strong>
                    </div>
                    <p style="margin: 0; font-size: 12.5px; color: var(--muted);">
                        You have reached the maximum allowed photos. Delete one or more existing photos below to upload replacements.
                    </p>
                </div>
            <?php else: ?>
                <div style="margin-bottom: 20px;">
                    <label class="modern-dropzone" id="gallery-dropzone">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <span id="gallery-label-text" style="font-size: 14px; font-weight: 700; color: var(--ink);">Click or Drag Photos to Upload</span>
                        <span style="font-size: 11.5px; color: var(--muted);">You can upload up to <?= $remainingSlots ?> more photo<?= $remainingSlots > 1 ? 's' : '' ?> (JPG, PNG, WEBP).</span>
                        <input type="file" name="gallery_images[]" id="gallery-file-input" accept=".jpg,.jpeg,.png,.webp" multiple>
                    </label>
                    <div id="gallery-queued-container" class="queued-files-preview"></div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($galleryImages)): ?>
                <div>
                    <label style="font-size: 13px; font-weight: 700; color: var(--ink); margin-bottom: 12px; display: block;">
                        Current Uploaded Gallery (<?= count($galleryImages) ?>)
                    </label>
                    <div class="gallery-grid">
                        <?php foreach ($galleryImages as $img): ?>
                            <div class="gallery-thumb-item" id="gallery-item-<?= $img['id'] ?>">
                                <img src="<?= h(upload_url($img['image_url'])) ?>" alt="Gallery Photo" loading="lazy" decoding="async">
                                <button type="button" 
                                        class="gallery-delete-btn"
                                        onclick="confirmGalleryDelete(<?= $img['id'] ?>)"
                                        title="Delete this image">
                                    &times;
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <input type="hidden" name="image_id" id="delete_image_id" value="">

        <div class="sticky-save-bar">
            <div class="save-bar-status-wrap">
                <span id="unsaved-indicator" style="width: 10px; height: 10px; border-radius: 50%; background: #64748b; display: inline-block; transition: background 0.2s; flex-shrink: 0;"></span>
                <span id="save-bar-status" style="font-size: 13px; color: var(--muted);">All changes up to date</span>
            </div>
            <button type="submit" id="save-btn" class="btn btn-primary" style="padding: 12px 34px; font-size: 14.5px; font-weight: 700; border-radius: 10px; display: inline-flex; align-items: center; gap: 8px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                <span>Save Profile Changes</span>
            </button>
        </div>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('gym-profile-form');
            const unsavedDot = document.getElementById('unsaved-indicator');
            const saveStatus = document.getElementById('save-bar-status');
            let isDirty = false;

            function markDirty() {
                if (!isDirty) {
                    isDirty = true;
                    if (unsavedDot) unsavedDot.style.background = 'var(--orange, #d97706)';
                    if (saveStatus) {
                        saveStatus.innerHTML = '<strong style="color:var(--orange, #d97706);">● Unsaved changes</strong> — click Save Changes to apply';
                    }
                }
            }

            if (form) {
                form.querySelectorAll('input, select, textarea').forEach(el => {
                    el.addEventListener('input', markDirty);
                    el.addEventListener('change', markDirty);
                });
            }

            // Logo preview & click-to-upload
            const logoWrap = document.getElementById('logo-avatar-wrap');
            const logoInput = document.getElementById('logo-file-input');
            const logoImg = document.getElementById('logo-preview-img');
            const logoStatus = document.getElementById('logo-status-text');

            if (logoWrap && logoInput) {
                logoWrap.addEventListener('click', () => logoInput.click());
            }

            if (logoInput && logoImg) {
                logoInput.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        const file = this.files[0];
                        if (file.size > 5 * 1024 * 1024) {
                            Swal.fire('File Too Large', 'Please select a logo image under 5MB.', 'warning');
                            this.value = '';
                            return;
                        }
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            logoImg.src = e.target.result;
                            if (logoStatus) {
                                logoStatus.textContent = 'Selected: ' + file.name;
                                logoStatus.style.color = 'var(--lime, #84cc16)';
                            }
                        };
                        reader.readAsDataURL(file);
                        markDirty();
                    }
                });
            }

            // Drag and drop helper
            function setupDragDrop(zone, input, onDropCallback) {
                if (!zone || !input) return;
                ['dragenter', 'dragover'].forEach(eventName => {
                    zone.addEventListener(eventName, (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        zone.classList.add('drag-over');
                    }, false);
                });
                ['dragleave', 'drop'].forEach(eventName => {
                    zone.addEventListener(eventName, (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        zone.classList.remove('drag-over');
                    }, false);
                });
                zone.addEventListener('drop', (e) => {
                    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                        input.files = e.dataTransfer.files;
                        input.dispatchEvent(new Event('change'));
                        if (typeof onDropCallback === 'function') onDropCallback(e.dataTransfer.files);
                    }
                }, false);
            }

            const permitDropzone = document.getElementById('permit-dropzone');
            const permitInput = document.getElementById('permit-file-input');
            const permitLabel = document.getElementById('permit-label-text');

            setupDragDrop(permitDropzone, permitInput);

            if (permitInput && permitLabel) {
                permitInput.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        const file = this.files[0];
                        if (file.size > 10 * 1024 * 1024) {
                            Swal.fire('File Too Large', 'Permit file must be 10MB or less.', 'warning');
                            this.value = '';
                            return;
                        }
                        permitLabel.textContent = 'Selected: ' + file.name;
                        permitLabel.style.color = 'var(--lime, #84cc16)';
                        markDirty();
                    }
                });
            }

            const galleryDropzone = document.getElementById('gallery-dropzone');
            const galleryInput = document.getElementById('gallery-file-input');
            const galleryLabel = document.getElementById('gallery-label-text');
            const queuedContainer = document.getElementById('gallery-queued-container');
            const remainingSlots = <?= (int) ($remainingSlots ?? 10) ?>;

            setupDragDrop(galleryDropzone, galleryInput);

            if (galleryInput) {
                galleryInput.addEventListener('change', function() {
                    if (queuedContainer) queuedContainer.innerHTML = '';
                    if (this.files && this.files.length > 0) {
                        if (this.files.length > remainingSlots) {
                            Swal.fire('Upload Limit', `You can only upload up to ${remainingSlots} more photo(s). Please choose fewer files.`, 'warning');
                            this.value = '';
                            if (galleryLabel) {
                                galleryLabel.textContent = 'Click or Drag Photos to Upload';
                                galleryLabel.style.color = 'var(--ink)';
                            }
                            return;
                        }

                        if (galleryLabel) {
                            galleryLabel.textContent = this.files.length + ' photo(s) queued for upload';
                            galleryLabel.style.color = 'var(--lime, #84cc16)';
                        }
                        for (let i = 0; i < this.files.length; i++) {
                            const chip = document.createElement('span');
                            chip.className = 'queued-chip';
                            chip.textContent = '✓ ' + this.files[i].name;
                            queuedContainer.appendChild(chip);
                        }
                        markDirty();
                    }
                });
            }

            const colorPicker = document.getElementById('brand_color_picker');
            const hexLabel = document.getElementById('hex-label');
            const accentChip = document.getElementById('live-accent-chip');
            const swatches = document.querySelectorAll('.color-swatch');

            function applyColor(hex) {
                if (colorPicker) colorPicker.value = hex;
                if (hexLabel) hexLabel.textContent = hex.toUpperCase();
                if (accentChip) accentChip.style.backgroundColor = hex;
                swatches.forEach(s => {
                    if (s.dataset.color.toLowerCase() === hex.toLowerCase()) {
                        s.classList.add('active');
                    } else {
                        s.classList.remove('active');
                    }
                });
                markDirty();
            }

            if (colorPicker) {
                colorPicker.addEventListener('input', function() {
                    applyColor(this.value);
                });
            }

            swatches.forEach(swatch => {
                swatch.addEventListener('click', function() {
                    applyColor(this.dataset.color);
                });
            });
        });

        function handleFormSubmit(form) {
            const saveBtn = document.getElementById('save-btn');
            const topSaveBtn = document.getElementById('top-save-btn');
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.innerHTML = '<span style="display:inline-block;animation:spin 1s linear infinite;">⏳</span> Saving Profile...';
            }
            if (topSaveBtn) {
                topSaveBtn.disabled = true;
                topSaveBtn.innerHTML = '<span style="display:inline-block;animation:spin 1s linear infinite;">⏳</span> Saving...';
            }
        }

        function confirmGalleryDelete(imageId) {
            Swal.fire({
                title: 'Delete this image?',
                text: "This photo will be removed from your gallery immediately.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    
                    const csrfInput = document.querySelector('input[name="csrf_token"]');
                    if (csrfInput) {
                        const csrfClone = document.createElement('input');
                        csrfClone.type = 'hidden';
                        csrfClone.name = 'csrf_token';
                        csrfClone.value = csrfInput.value;
                        form.appendChild(csrfClone);
                    }
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'delete_gallery_image';
                    actionInput.value = '1';
                    form.appendChild(actionInput);
                    
                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'image_id';
                    idInput.value = imageId;
                    form.appendChild(idInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
    </script>
<?php
    render_footer();
}
