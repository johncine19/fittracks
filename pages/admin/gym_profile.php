<?php

declare(strict_types=1);

function clean_doc_name(?string $url): string
{
    if (!$url) {
        return '';
    }
    $path = parse_url($url, PHP_URL_PATH) ?: $url;
    $basename = basename($path);
    if (strlen($basename) > 26) {
        $ext = pathinfo($basename, PATHINFO_EXTENSION);
        return substr($basename, 0, 18) . '...' . ($ext ? '.' . $ext : '');
    }
    return $basename;
}

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

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !isset($_POST['delete_gallery_image'])) {
        $name = trim((string) post('name'));
        $address = trim((string) post('address'));
        $contact = trim((string) post('contact_info'));
        $walkInFee = max(0.0, (float) (post('walk_in_fee') !== null ? post('walk_in_fee') : 100.0));
        $inactivityThreshold = post('inactivity_threshold_days') !== '' && post('inactivity_threshold_days') !== null 
            ? max(1, (int) post('inactivity_threshold_days')) 
            : null;
        $inactivityCooldown = post('inactivity_cooldown_days') !== '' && post('inactivity_cooldown_days') !== null 
            ? max(1, (int) post('inactivity_cooldown_days')) 
            : null;
        $autoInactivityAlerts = isset($_POST['auto_inactivity_alerts']) ? 1 : 0;
        
        if ($canCustomBrand) {
            $brandColor = trim((string) post('brand_color'));
            if ($brandColor !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $brandColor)) {
                $brandColor = null;
            }
        } else {
            $brandColor = $gym['brand_color'] ?? null;
        }

        // Business Permit
        $permitUrl = $gym['business_permit_url'];
        if (isset($_FILES['business_permit']) && $_FILES['business_permit']['error'] === UPLOAD_ERR_OK) {
            try {
                $permitUrl = FileUpload::storeBusinessPermit($_FILES['business_permit'], (int)$gym['gym_id']);
            } catch (RuntimeException $e) {
                flash('Permit upload failed: ' . $e->getMessage(), 'danger');
            }
        }

        // Barangay Clearance
        $brgyUrl = $gym['barangay_clearance_url'] ?? null;
        if (isset($_FILES['barangay_clearance']) && $_FILES['barangay_clearance']['error'] === UPLOAD_ERR_OK) {
            try {
                $brgyUrl = FileUpload::storeBarangayClearance($_FILES['barangay_clearance'], (int)$gym['gym_id']);
            } catch (RuntimeException $e) {
                flash('Barangay Clearance upload failed: ' . $e->getMessage(), 'danger');
            }
        }

        // Fire Safety Inspection Certificate
        $fireSafetyUrl = $gym['fire_safety_cert_url'] ?? null;
        if (isset($_FILES['fire_safety_cert']) && $_FILES['fire_safety_cert']['error'] === UPLOAD_ERR_OK) {
            try {
                $fireSafetyUrl = FileUpload::storeFireSafetyCert($_FILES['fire_safety_cert'], (int)$gym['gym_id']);
            } catch (RuntimeException $e) {
                flash('Fire Safety Certificate upload failed: ' . $e->getMessage(), 'danger');
            }
        }

        // Gym Logo
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

        // Gallery Uploads
        if (isset($_FILES['gallery_images']) && is_array($_FILES['gallery_images']['tmp_name'])) {
            $currentGalleryCount = (int)$pdo->query('SELECT COUNT(*) FROM gym_images WHERE gym_id = ' . (int)$gym['gym_id'])->fetchColumn();
            $files = $_FILES['gallery_images'];
            $uploadCount = 0;
            
            for ($i = 0; $i < count($files['tmp_name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    if ($currentGalleryCount + $uploadCount >= 10) {
                        flash('You can only upload a maximum of 10 gallery images.', 'warning');
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

        if ($name && $address && $contact) {
            $pdo->prepare('UPDATE gyms SET name = ?, address = ?, contact_info = ?, walk_in_fee = ?, inactivity_threshold_days = ?, inactivity_cooldown_days = ?, auto_inactivity_alerts = ?, business_permit_url = ?, barangay_clearance_url = ?, fire_safety_cert_url = ?, logo_url = ?, brand_color = ? WHERE gym_id = ?')
                ->execute([$name, $address, $contact, $walkInFee, $inactivityThreshold, $inactivityCooldown, $autoInactivityAlerts, $permitUrl, $brgyUrl, $fireSafetyUrl, $logoUrl, $brandColor, $gym['gym_id']]);
            flash('Gym settings updated successfully.', 'success');
            redirect('gym_profile');
        } else {
            flash('Facility name, physical address, and contact information are required.', 'danger');
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
            flash('Photo deleted from gallery successfully.', 'success');
        }
        redirect('gym_profile');
    }

    $galleryImages = $pdo->query('SELECT * FROM gym_images WHERE gym_id = ' . (int)$gym['gym_id'] . ' ORDER BY created_at DESC')->fetchAll();
    $gymTier = gym_subscription_tier($gym);
    $memberCount = gym_active_member_count((int)$gym['gym_id']);
    $memberLimit = gym_member_limit($gym);
    $currentGalleryCount = count($galleryImages);
    $remainingSlots = max(0, 10 - $currentGalleryCount);

    $gymRatingStats = get_gym_rating_stats((int)$gym['gym_id']);
    $gymMemberReviews = get_gym_reviews((int)$gym['gym_id'], 100);

    render_header('Gym Profile & Settings', $user);
?>
    <style>
        .profile-settings-wrapper {
            width: 100%;
            margin: 0;
            box-sizing: border-box;
        }

        #gym-profile-form {
            padding-bottom: 75px;
            width: 100%;
            box-sizing: border-box;
        }

        .profile-page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 18px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--line);
        }

        .profile-header-main {
            flex: 1;
            min-width: 240px;
        }

        .profile-title {
            margin: 0 0 4px;
            font-size: 1.65rem;
            font-weight: 800;
            color: var(--ink);
            letter-spacing: -0.5px;
            word-break: break-word;
        }

        .profile-subtitle {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.4;
        }

        .profile-meta-badges {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .tier-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: color-mix(in srgb, var(--lime, #84cc16) 12%, transparent);
            color: var(--lime, #84cc16);
            border: 1px solid color-mix(in srgb, var(--lime, #84cc16) 28%, transparent);
            white-space: nowrap;
        }

        .capacity-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 600;
            background: var(--panel-soft);
            color: var(--muted);
            border: 1px solid var(--line);
            white-space: nowrap;
        }

        .action-btn-preview {
            background: var(--panel) !important;
            color: var(--ink) !important;
            border: 1px solid var(--line) !important;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
            transition: all 0.2s ease;
            font-size: 13px !important;
            padding: 8px 16px !important;
            border-radius: 9px !important;
        }

        .action-btn-preview:hover {
            background: var(--panel-soft) !important;
            border-color: var(--lime, #84cc16) !important;
            color: var(--ink) !important;
        }

        /* 2-Column Settings Layout */
        .gym-settings-layout {
            display: grid;
            grid-template-columns: 220px minmax(0, 1fr);
            gap: 22px;
            align-items: start;
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }

        .settings-nav-sidebar {
            min-width: 0;
            width: 100%;
            box-sizing: border-box;
            position: relative;
        }

        .settings-nav-wrapper {
            position: relative;
            width: 100%;
        }

        .nav-scroll-btn {
            display: none;
        }

        /* Sidebar Tabs Nav - Strict 2-Column Grid Alignment */
        .settings-nav-sticky {
            position: sticky;
            top: 86px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 8px 6px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            box-sizing: border-box;
        }

        .settings-nav-header {
            font-size: 10.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--muted);
            padding: 6px 12px 6px;
        }

        .settings-nav-link {
            display: grid;
            grid-template-columns: 22px 1fr;
            align-items: center;
            column-gap: 12px;
            padding: 9px 12px;
            border-radius: 9px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 600;
            background: transparent;
            border: 1px solid transparent;
            cursor: pointer;
            text-align: left;
            width: 100%;
            transition: all 0.18s ease;
            box-sizing: border-box;
        }

        .settings-nav-link .nav-icon {
            width: 22px;
            height: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .settings-nav-link .nav-icon svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            color: var(--muted);
            transition: color 0.18s;
        }

        .settings-nav-link .nav-label,
        .settings-nav-link .settings-tab-label {
            display: block !important;
            white-space: nowrap;
            line-height: 1.2;
            text-align: left;
        }

        .settings-nav-link:hover {
            color: var(--ink);
            background: var(--panel-soft);
        }

        .settings-nav-link:hover .nav-icon svg {
            color: var(--ink);
        }

        .settings-nav-link.active {
            color: var(--lime, #84cc16);
            background: color-mix(in srgb, var(--lime, #84cc16) 12%, transparent);
            border-color: color-mix(in srgb, var(--lime, #84cc16) 24%, transparent);
            font-weight: 700;
        }

        .settings-nav-link.active .nav-icon svg {
            color: var(--lime, #84cc16);
        }

        .settings-nav-divider {
            height: 1px;
            background: var(--line);
            margin: 4px 6px;
        }

        /* Content Container */
        .settings-content-flow {
            min-width: 0;
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 16px;
            box-sizing: border-box;
        }

        /* When 'All Sections' is active: compact 2-column layout */
        .settings-content-flow.view-all-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 16px;
        }

        .settings-content-flow.view-all-grid .span-full {
            grid-column: 1 / -1;
        }

        /* Profile Card - Roomy & Premium */
        .profile-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 22px 24px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            box-sizing: border-box;
            width: 100%;
            min-width: 0;
        }

        .profile-card:hover {
            border-color: color-mix(in srgb, var(--lime, #84cc16) 25%, var(--line));
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
            font-size: 13.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--lime, #84cc16);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* General Info Form Grid - Generous Breathing Room */
        .general-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 22px 20px;
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
            height: 42px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: var(--panel-soft);
            color: var(--ink);
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }

        .input-with-icon:focus {
            outline: none;
            border-color: var(--lime, #84cc16);
            background: var(--panel);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--lime, #84cc16) 18%, transparent);
        }

        textarea.input-with-icon {
            height: auto !important;
            min-height: 60px;
            padding-top: 10px !important;
            padding-bottom: 10px !important;
            resize: vertical;
            line-height: 1.45;
            font-family: inherit;
        }

        .input-icon-wrap.has-textarea {
            align-items: flex-start;
        }

        .input-icon-wrap.has-textarea .input-icon {
            top: 13px;
        }

        .field-label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 7px;
            color: var(--ink);
            line-height: 1.35;
            letter-spacing: 0.01em;
        }

        .field-hint {
            display: block;
            font-size: 11.5px;
            color: var(--muted);
            margin-top: 6px;
            line-height: 1.45;
        }

        /* Overview Section Manage Buttons */
        .btn-manage-section {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 15px;
            font-size: 12.5px;
            font-weight: 700;
            border-radius: 9px;
            background: color-mix(in srgb, var(--panel-soft) 80%, transparent);
            border: 1px solid var(--line);
            color: var(--ink);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-manage-section:hover {
            background: var(--panel);
            border-color: var(--lime, #84cc16);
            color: var(--lime, #84cc16);
            transform: translateY(-1px);
        }

        /* Overview Rows - Clean Component System */
        .overview-doc-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 14px;
        }

        .overview-doc-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            border-radius: 12px;
            background: var(--panel-soft);
            border: 1px solid var(--line);
            gap: 14px;
            box-sizing: border-box;
            transition: border-color 0.2s, background 0.2s;
        }

        .overview-doc-item:hover {
            border-color: color-mix(in srgb, var(--lime, #84cc16) 24%, var(--line));
        }

        .overview-doc-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            flex: 1;
        }

        .overview-doc-icon {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .overview-doc-icon.is-verified {
            background: color-mix(in srgb, var(--teal, #10b981) 14%, transparent);
            color: var(--teal, #10b981);
            border: 1px solid color-mix(in srgb, var(--teal, #10b981) 28%, transparent);
        }

        .overview-doc-icon.is-missing {
            background: color-mix(in srgb, var(--danger, #ef4444) 14%, transparent);
            color: var(--danger, #ef4444);
            border: 1px solid color-mix(in srgb, var(--danger, #ef4444) 28%, transparent);
        }

        .overview-doc-icon.is-optional {
            background: color-mix(in srgb, var(--muted) 12%, transparent);
            color: var(--muted);
            border: 1px solid var(--line);
        }

        .overview-doc-icon.is-theme {
            background: color-mix(in srgb, var(--lime, #84cc16) 14%, transparent);
            color: var(--lime, #84cc16);
            border: 1px solid color-mix(in srgb, var(--lime, #84cc16) 28%, transparent);
        }

        .overview-doc-info {
            min-width: 0;
            flex: 1;
        }

        .overview-doc-name {
            font-size: 13px;
            font-weight: 700;
            color: var(--ink);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .overview-doc-status {
            font-size: 11.5px;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 5px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            margin-top: 2px;
        }

        /* Compact Gallery Strip */
        .overview-gallery-strip {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .overview-gallery-thumb {
            width: 68px;
            height: 68px;
            border-radius: 9px;
            overflow: hidden;
            border: 1px solid var(--line);
            background: var(--panel-soft);
            position: relative;
            flex-shrink: 0;
            cursor: pointer;
        }

        .overview-gallery-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.2s;
        }

        .overview-gallery-thumb:hover img {
            transform: scale(1.08);
        }

        .overview-gallery-more {
            width: 68px;
            height: 68px;
            border-radius: 9px;
            background: color-mix(in srgb, var(--panel-soft) 90%, transparent);
            border: 1px dashed var(--line);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            color: var(--lime, #84cc16);
            flex-shrink: 0;
            cursor: pointer;
        }

        .overview-doc-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .logo-avatar-wrap {
            position: relative;
            width: 36px;
            height: 36px;
            border-radius: 9px;
            background: var(--panel-soft);
            border: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .logo-avatar-wrap:hover {
            border-color: var(--lime, #84cc16);
        }

        .logo-avatar-wrap .logo-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.65);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s;
            color: #fff;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .logo-avatar-wrap:hover .logo-overlay {
            opacity: 1;
        }

        .color-picker-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 12px;
            border-radius: 9px;
            background: var(--panel-soft);
            border: 1px solid var(--line);
            box-sizing: border-box;
        }

        .color-swatch-grid {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .color-swatch {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            border: 2px solid transparent;
            cursor: pointer;
            transition: transform 0.15s ease;
            flex-shrink: 0;
        }

        .color-swatch:hover {
            transform: scale(1.15);
        }

        .color-swatch.active {
            border-color: var(--ink);
            box-shadow: 0 0 8px color-mix(in srgb, var(--ink) 40%, transparent);
        }

        /* Switch */
        .gym-switch-label {
            position: relative;
            display: inline-block;
            width: 42px;
            height: 24px;
            flex-shrink: 0;
            margin: 0;
            cursor: pointer;
        }
        .gym-switch-label input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .gym-switch-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background-color: var(--line);
            transition: .25s;
            border-radius: 24px;
        }
        .gym-switch-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .25s;
            border-radius: 50%;
        }
        .gym-switch-label input:checked + .gym-switch-slider {
            background-color: var(--lime, #84cc16);
        }
        .gym-switch-label input:checked + .gym-switch-slider:before {
            transform: translateX(18px);
            background-color: #06090e;
        }

        /* Single Sticky Save Bar - Compact */
        .sticky-save-bar {
            margin-top: 20px;
            padding: 12px 20px;
            border-radius: 12px;
            background: color-mix(in srgb, var(--panel) 94%, transparent);
            border: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            position: sticky;
            bottom: max(14px, env(safe-area-inset-bottom, 14px));
            z-index: 30;
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            box-sizing: border-box;
            width: 100%;
        }

        /* Custom Modal Backdrop */
        .custom-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(5, 8, 12, 0.82);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            padding: 20px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
            box-sizing: border-box;
        }

        .custom-modal-backdrop.is-open {
            opacity: 1;
            pointer-events: auto;
        }

        .custom-modal-dialog {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 16px;
            width: 100%;
            max-width: 640px;
            max-height: 86vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 25px 60px rgba(0,0,0,0.55);
            transform: scale(0.96) translateY(10px);
            transition: transform 0.2s ease;
            overflow: hidden;
            box-sizing: border-box;
        }

        .custom-modal-backdrop.is-open .custom-modal-dialog {
            transform: scale(1) translateY(0);
        }

        .custom-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 18px 22px;
            border-bottom: 1px solid var(--line);
            background: color-mix(in srgb, var(--panel-soft) 40%, transparent);
            gap: 14px;
        }

        .custom-modal-title {
            margin: 0 0 3px;
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--ink);
        }

        .custom-modal-subtitle {
            margin: 0;
            font-size: 12.5px;
            color: var(--muted);
            line-height: 1.35;
        }

        .custom-modal-close {
            background: transparent;
            border: none;
            color: var(--muted);
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 6px;
        }

        .custom-modal-close:hover {
            color: var(--ink);
            background: var(--panel-soft);
        }

        .custom-modal-body {
            padding: 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .custom-modal-footer {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 10px;
            padding: 14px 20px;
            border-top: 1px solid var(--line);
            background: color-mix(in srgb, var(--panel-soft) 40%, transparent);
        }

        .modern-dropzone {
            border: 2px dashed var(--line);
            border-radius: 10px;
            padding: 16px;
            text-align: center;
            cursor: pointer;
            background: color-mix(in srgb, var(--panel-soft) 40%, transparent);
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 5px;
            box-sizing: border-box;
            width: 100%;
        }

        .modern-dropzone:hover,
        .modern-dropzone.drag-over {
            border-color: var(--lime, #84cc16) !important;
            background: color-mix(in srgb, var(--lime, #84cc16) 8%, var(--panel)) !important;
        }

        .modern-dropzone input[type="file"] {
            display: none;
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(115px, 1fr));
            gap: 12px;
        }

        .gallery-thumb-item {
            position: relative;
            border-radius: 9px;
            overflow: hidden;
            border: 1px solid var(--line);
            aspect-ratio: 1;
            background: var(--panel-soft);
        }

        .gallery-thumb-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .gallery-delete-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(239, 68, 68, 0.9);
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 15px;
            line-height: 1;
        }

        .queued-files-preview {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .queued-chip {
            font-size: 11.5px;
            padding: 3px 8px;
            border-radius: 5px;
            background: color-mix(in srgb, var(--lime, #84cc16) 12%, transparent);
            color: var(--lime, #84cc16);
            border: 1px solid color-mix(in srgb, var(--lime, #84cc16) 25%, transparent);
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        /* Fade-in Animation for smooth tab switching */
        .settings-tab-pane {
            animation: fadeInPane 0.18s ease-in-out;
        }

        @keyframes fadeInPane {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .mobile-section-selector {
            display: none;
        }

        /* Responsive Breakpoints */
        @media (max-width: 900px) {
            .gym-settings-layout {
                grid-template-columns: minmax(0, 1fr) !important;
                gap: 16px;
                width: 100%;
            }

            .settings-nav-sidebar {
                min-width: 0;
                width: 100%;
                position: sticky;
                top: 56px;
                z-index: 45;
                margin-bottom: 4px;
            }

            /* Hide horizontal scrolling tabs strip completely on mobile */
            .settings-nav-sticky {
                display: none !important;
            }

            /* Full-width clean section dropdown */
            .mobile-section-selector {
                display: block;
                position: relative;
                width: 100%;
            }

            .mobile-selector-trigger {
                width: 100%;
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 11px 16px;
                background: color-mix(in srgb, var(--panel) 96%, transparent);
                border: 1px solid color-mix(in srgb, var(--lime, #84cc16) 32%, var(--line));
                border-radius: 12px;
                color: var(--ink);
                cursor: pointer;
                box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
                backdrop-filter: blur(16px);
                -webkit-backdrop-filter: blur(16px);
                transition: all 0.2s ease;
                box-sizing: border-box;
            }

            .mobile-selector-trigger:hover,
            .mobile-selector-trigger.is-open {
                border-color: var(--lime, #84cc16);
                background: var(--panel);
            }

            .selector-current-info {
                display: flex;
                align-items: center;
                gap: 10px;
                min-width: 0;
            }

            .selector-current-icon {
                width: 22px;
                height: 22px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: var(--lime, #84cc16);
                flex-shrink: 0;
            }

            .selector-current-icon svg {
                width: 17px;
                height: 17px;
            }

            .selector-current-text {
                font-size: 14px;
                font-weight: 700;
                color: var(--ink);
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .selector-chevron {
                display: flex;
                align-items: center;
                justify-content: center;
                color: var(--lime, #84cc16);
                transition: transform 0.2s ease;
                flex-shrink: 0;
                margin-left: 8px;
            }

            .mobile-selector-trigger.is-open .selector-chevron {
                transform: rotate(180deg);
            }

            .mobile-selector-menu {
                position: absolute;
                top: calc(100% + 6px);
                left: 0;
                right: 0;
                background: var(--panel);
                border: 1px solid color-mix(in srgb, var(--lime, #84cc16) 32%, var(--line));
                border-radius: 14px;
                padding: 6px;
                box-shadow: 0 14px 34px rgba(0, 0, 0, 0.6);
                backdrop-filter: blur(20px);
                -webkit-backdrop-filter: blur(20px);
                display: none;
                flex-direction: column;
                gap: 3px;
                z-index: 60;
                box-sizing: border-box;
            }

            .mobile-selector-menu.is-open {
                display: flex;
                animation: dropdownSlide 0.18s cubic-bezier(0.16, 1, 0.3, 1);
            }

            @keyframes dropdownSlide {
                from { opacity: 0; transform: translateY(-8px); }
                to { opacity: 1; transform: translateY(0); }
            }

            .mobile-menu-item {
                display: grid;
                grid-template-columns: 24px 1fr 20px;
                align-items: center;
                column-gap: 12px;
                padding: 10px 12px;
                border-radius: 9px;
                background: transparent;
                border: 1px solid transparent;
                color: var(--muted);
                font-size: 13.5px;
                font-weight: 600;
                cursor: pointer;
                text-align: left;
                width: 100%;
                transition: all 0.15s ease;
                box-sizing: border-box;
            }

            .mobile-menu-item .nav-icon {
                width: 24px;
                height: 24px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: var(--muted);
            }

            .mobile-menu-item .nav-icon svg {
                width: 16px;
                height: 16px;
            }

            .mobile-menu-item .nav-label,
            .mobile-menu-item .settings-tab-label {
                display: block !important;
                white-space: nowrap;
                color: var(--ink);
            }

            .mobile-menu-item .menu-item-check {
                opacity: 0;
                color: var(--lime, #84cc16);
                font-weight: 800;
                text-align: right;
                font-size: 13px;
            }

            .mobile-menu-item:hover {
                background: var(--panel-soft);
                color: var(--ink);
            }

            .mobile-menu-item:hover .nav-icon {
                color: var(--ink);
            }

            .mobile-menu-item.active {
                background: color-mix(in srgb, var(--lime, #84cc16) 12%, transparent);
                border-color: color-mix(in srgb, var(--lime, #84cc16) 24%, transparent);
                color: var(--lime, #84cc16);
                font-weight: 700;
            }

            .mobile-menu-item.active .nav-icon {
                color: var(--lime, #84cc16);
            }

            .mobile-menu-item.active .menu-item-check {
                opacity: 1;
            }

            .settings-content-flow.view-all-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        @media (max-width: 600px) {
            .profile-page-header {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                margin-bottom: 12px;
                padding-bottom: 12px;
            }

            .profile-title {
                font-size: 1.35rem !important;
                margin-bottom: 2px;
            }

            .profile-subtitle {
                display: none;
            }

            .action-btn-preview {
                width: 100%;
                justify-content: center;
            }

            .profile-card {
                padding: 20px 16px;
                border-radius: 14px;
            }

            .general-form-grid {
                grid-template-columns: 1fr !important;
                gap: 22px !important;
            }

            .overview-doc-item {
                flex-wrap: wrap;
                gap: 10px;
            }

            .contact-input-wrap,
            .branding-theme-col,
            .reminders-toggle-card,
            .gallery-empty-state {
                max-width: 100% !important;
            }

            .branding-compact-grid {
                justify-items: start;
                text-align: left;
            }

            /* 46px min touch height & 16px font to prevent iOS auto-zoom */
            .input-with-icon {
                height: 46px !important;
                font-size: 16px !important;
                padding-left: 42px !important;
            }

            textarea.input-with-icon {
                height: auto !important;
                min-height: 64px !important;
                padding-top: 11px !important;
                padding-bottom: 11px !important;
                line-height: 1.45 !important;
            }

            .input-icon-wrap.has-textarea .input-icon {
                top: 14px;
            }

            .btn-manage-section {
                width: 100%;
                justify-content: center;
                height: 42px;
            }

            /* Fixed native mobile bottom bar */
            .sticky-save-bar {
                position: fixed !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                width: 100% !important;
                border-radius: 16px 16px 0 0 !important;
                border-left: none !important;
                border-right: none !important;
                border-bottom: none !important;
                padding: 10px 16px max(14px, env(safe-area-inset-bottom, 14px)) !important;
                margin: 0 !important;
                z-index: 99 !important;
                box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.45) !important;
                background: color-mix(in srgb, var(--panel) 98%, transparent) !important;
                backdrop-filter: blur(16px);
                -webkit-backdrop-filter: blur(16px);
            }

            #gym-profile-form {
                padding-bottom: 95px !important;
            }

            .sticky-save-bar #save-btn {
                width: 100%;
                justify-content: center;
                height: 44px;
                font-size: 14px;
            }

            .custom-modal-backdrop {
                padding: 8px;
            }

            .custom-modal-dialog {
                max-height: 92vh;
                border-radius: 14px;
            }
        }
    </style>

    <div class="profile-settings-wrapper">
        <div class="profile-page-header">
            <div class="profile-header-main">
                <h1 class="profile-title">
                    <?= h($gym['name'] ?: 'Facility Settings') ?>
                </h1>
                <p class="profile-subtitle">
                    Facility information, visual branding, business permits, and media gallery.
                </p>
                <div class="profile-meta-badges">
                    <span class="tier-pill">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                        <?= h(ucfirst($gymTier)) ?> Plan
                    </span>
                    <span class="capacity-pill">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                        Active Members: <?= $memberCount ?>
                    </span>
                    <span class="capacity-pill" style="color: #fbbf24; border-color: rgba(251, 191, 36, 0.3); background: rgba(251, 191, 36, 0.08);">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="#fbbf24" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        Rating: <?= number_format((float)$gymRatingStats['avg_rating'], 1) ?> (<?= (int)$gymRatingStats['total_reviews'] ?>)
                    </span>
                    <?php if ($gymTier !== 'business'): ?>
                        <a href="index.php?page=gym_subscription" style="font-size: 11.5px; font-weight: 700; color: var(--lime, #84cc16); text-decoration: none; display: inline-flex; align-items: center; gap: 3px;">
                            Upgrade Plan →
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="profile-actions-bar">
                <a href="index.php?page=view_gym&gym_id=<?= (int)$gym['gym_id'] ?>" target="_blank" class="btn btn-secondary action-btn-preview" style="display: inline-flex; align-items: center; gap: 6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    <span>Preview Public Page</span>
                </a>
            </div>
        </div>

        <form method="post" enctype="multipart/form-data" id="gym-profile-form" onsubmit="handleFormSubmit(this)">
            <?= csrf_field() ?>

            <div class="gym-settings-layout">
                <!-- Sidebar Navigation Tabs -->
                <aside class="settings-nav-sidebar">
                    <!-- Mobile Clean Section Dropdown Selector (<= 900px) -->
                    <div class="mobile-section-selector" id="mobile-section-selector">
                        <button type="button" class="mobile-selector-trigger" id="mobile-selector-trigger" onclick="toggleMobileNavDropdown()" aria-haspopup="true" aria-expanded="false">
                            <span class="selector-current-info">
                                <span class="selector-current-icon" id="mobile-current-icon">
                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                </span>
                                <span class="selector-current-text" id="mobile-current-label">General Info</span>
                            </span>
                            <span class="selector-chevron">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                            </span>
                        </button>
                        <div class="mobile-selector-menu" id="mobile-selector-menu">
                            <button type="button" class="mobile-menu-item active" data-tab="general" onclick="selectMobileTab('general')">
                                <span class="nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span>
                                <span class="settings-tab-label nav-label">General Info</span>
                                <span class="menu-item-check">✓</span>
                            </button>
                            <button type="button" class="mobile-menu-item" data-tab="branding" onclick="selectMobileTab('branding')">
                                <span class="nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M21 12H3M12 3v18"/></svg></span>
                                <span class="settings-tab-label nav-label">Branding</span>
                                <span class="menu-item-check">✓</span>
                            </button>
                            <button type="button" class="mobile-menu-item" data-tab="documents" onclick="selectMobileTab('documents')">
                                <span class="nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></span>
                                <span class="settings-tab-label nav-label">Documents</span>
                                <span class="menu-item-check">✓</span>
                            </button>
                            <button type="button" class="mobile-menu-item" data-tab="reminders" onclick="selectMobileTab('reminders')">
                                <span class="nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg></span>
                                <span class="settings-tab-label nav-label">Reminders</span>
                                <span class="menu-item-check">✓</span>
                            </button>
                            <button type="button" class="mobile-menu-item" data-tab="gallery" onclick="selectMobileTab('gallery')">
                                <span class="nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></span>
                                <span class="settings-tab-label nav-label">Photo Gallery</span>
                                <span class="menu-item-check">✓</span>
                            </button>
                            <button type="button" class="mobile-menu-item" data-tab="ratings" onclick="selectMobileTab('ratings')">
                                <span class="nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="#fbbf24" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></span>
                                <span class="settings-tab-label nav-label">Member Reviews (<?= (int)$gymRatingStats['total_reviews'] ?>)</span>
                                <span class="menu-item-check">✓</span>
                            </button>
                            <div class="settings-nav-divider"></div>
                            <button type="button" class="mobile-menu-item" data-tab="all" onclick="selectMobileTab('all')">
                                <span class="nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg></span>
                                <span class="settings-tab-label nav-label">Show All Sections</span>
                                <span class="menu-item-check">✓</span>
                            </button>
                        </div>
                    </div>

                    <!-- Desktop Sidebar Sticky Nav (> 900px) -->
                    <div class="settings-nav-wrapper" id="settings-nav-wrapper">
                        <div class="settings-nav-sticky" id="settings-nav-sticky">
                            <span class="settings-nav-header">Settings</span>
                            <button type="button" class="settings-nav-link active" data-tab="general" onclick="switchSettingsTab('general', this)">
                                <span class="nav-icon">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                </span>
                                <span class="settings-tab-label nav-label">General Info</span>
                            </button>
                            <button type="button" class="settings-nav-link" data-tab="branding" onclick="switchSettingsTab('branding', this)">
                                <span class="nav-icon">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M21 12H3M12 3v18"/></svg>
                                </span>
                                <span class="settings-tab-label nav-label">Branding</span>
                            </button>
                            <button type="button" class="settings-nav-link" data-tab="documents" onclick="switchSettingsTab('documents', this)">
                                <span class="nav-icon">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                                </span>
                                <span class="settings-tab-label nav-label">Documents</span>
                            </button>
                            <button type="button" class="settings-nav-link" data-tab="reminders" onclick="switchSettingsTab('reminders', this)">
                                <span class="nav-icon">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                                </span>
                                <span class="settings-tab-label nav-label">Reminders</span>
                            </button>
                            <button type="button" class="settings-nav-link" data-tab="gallery" onclick="switchSettingsTab('gallery', this)">
                                <span class="nav-icon">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                </span>
                                <span class="settings-tab-label nav-label">Photo Gallery</span>
                            </button>
                            <button type="button" class="settings-nav-link" data-tab="ratings" onclick="switchSettingsTab('ratings', this)">
                                <span class="nav-icon">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#fbbf24" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                </span>
                                <span class="settings-tab-label nav-label">Member Reviews (<?= (int)$gymRatingStats['total_reviews'] ?>)</span>
                            </button>
                            <div class="settings-nav-divider"></div>
                            <button type="button" class="settings-nav-link" data-tab="all" onclick="switchSettingsTab('all', this)" title="Show all sections together in a 2-column view">
                                <span class="nav-icon">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                                </span>
                                <span class="settings-tab-label nav-label">Show All</span>
                            </button>
                        </div>
                    </div>
                </aside>

                <!-- Main Content Container -->
                <div class="settings-content-flow" id="settings-content-flow">

                    <!-- 1. GENERAL INFORMATION -->
                    <section class="profile-card settings-tab-pane span-full" id="sec-general">
                        <div class="profile-card-header">
                            <h3 class="profile-card-title">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                General Information
                            </h3>
                            <small style="color: var(--muted); font-size: 11.5px;">Facility details</small>
                        </div>                        <div class="general-form-grid">
                            <div>
                                <label class="field-label">Gym Facility Name <span style="color: var(--danger, #ef4444);">*</span></label>
                                <div class="input-icon-wrap">
                                    <span class="input-icon">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    </span>
                                    <input type="text" name="name" class="input-with-icon" value="<?= h($gym['name']) ?>" required placeholder="e.g. Iron Fitness Center">
                                </div>
                                <span class="field-hint">Official commercial name displayed to members.</span>
                            </div>

                            <div>
                                <label class="field-label">Walk-in / Day Pass Fee (PHP)</label>
                                <div class="input-icon-wrap">
                                    <span class="input-icon" style="font-weight: 800; font-size: 14px; color: var(--lime, #84cc16);">₱</span>
                                    <input type="number" step="0.01" min="0" name="walk_in_fee" class="input-with-icon" value="<?= h((string)($gym['walk_in_fee'] ?? '100.00')) ?>" placeholder="100.00">
                                </div>
                                <span class="field-hint">Rate charged for visitors at scanner.</span>
                            </div>

                            <div>
                                <label class="field-label">Physical Address / Location <span style="color: var(--danger, #ef4444);">*</span></label>
                                <div class="input-icon-wrap has-textarea">
                                    <span class="input-icon">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                    </span>
                                    <textarea name="address" class="input-with-icon" rows="2" required placeholder="e.g. 123 Fitness Ave, District 4"><?= h($gym['address']) ?></textarea>
                                </div>
                                <span class="field-hint">Street address, building number, and city.</span>
                            </div>

                            <div>
                                <label class="field-label">Contact Information / Mobile Number <span style="color: var(--danger, #ef4444);">*</span></label>
                                <div class="input-icon-wrap">
                                    <span class="input-icon">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                    </span>
                                    <input type="text" name="contact_info" class="input-with-icon" value="<?= h($gym['contact_info']) ?>" required placeholder="e.g. 09123456789 / info@gym.com">
                                </div>
                                <span class="field-hint">Phone number or support email for inquiries.</span>
                            </div>
                        </div>
                    </section>

                    <!-- 2. BRANDING CUSTOMIZATION -->
                    <section class="profile-card settings-tab-pane" id="sec-branding">
                        <div class="profile-card-header">
                            <div>
                                <h3 class="profile-card-title" style="margin-bottom: 2px;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M21 12H3M12 3v18"/></svg>
                                    Branding Customization
                                </h3>
                                <span style="font-size: 11.5px; color: var(--muted);">Visual identity, logo badge, and interface colors.</span>
                            </div>
                            <?php if ($canCustomBrand): ?>
                                <span style="font-size: 10.5px; font-weight: 700; color: var(--lime, #84cc16); background: color-mix(in srgb, var(--lime, #84cc16) 14%, transparent); border: 1px solid color-mix(in srgb, var(--lime, #84cc16) 28%, transparent); padding: 2px 7px; border-radius: 5px;">
                                    Business Active
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="overview-doc-list">
                            <!-- Item 1: Gym Logo -->
                            <div class="overview-doc-item">
                                <div class="overview-doc-left">
                                    <div class="logo-avatar-wrap" id="logo-avatar-wrap" title="Click to upload logo" onclick="document.getElementById('logo-file-input').click();">
                                        <img id="logo-preview-img" 
                                             src="<?= !empty($gym['logo_url']) ? h(upload_url($gym['logo_url'])) : 'data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'44\' height=\'44\' viewBox=\'0 0 44 44\'><rect width=\'44\' height=\'44\' rx=\'9\' fill=\'%23334155\'/><text x=\'50%25\' y=\'54%25\' font-size=\'15\' font-weight=\'bold\' fill=\'%2384cc16\' text-anchor=\'middle\' dominant-baseline=\'middle\'>FT</text></svg>' ?>" 
                                             alt="Logo" 
                                             style="width: 100%; height: 100%; object-fit: contain; padding: 2px; box-sizing: border-box;">
                                        <div class="logo-overlay">Change</div>
                                    </div>
                                    <div class="overview-doc-info">
                                        <div class="overview-doc-name">Gym Brand Logo</div>
                                        <div class="overview-doc-status">
                                            <?php if (!empty($gym['logo_url'])): ?>
                                                <span style="color: var(--teal, #10b981); font-weight: 600;">✅ Active logo on file</span>
                                            <?php else: ?>
                                                <span style="color: var(--muted);">Default initials placeholder shown</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="overview-doc-right">
                                    <label class="btn btn-secondary" style="font-size: 11.5px; padding: 6px 12px; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                        <span>Select Image</span>
                                        <input type="file" name="logo" id="logo-file-input" accept="image/*" style="display: none;">
                                    </label>
                                </div>
                            </div>

                            <!-- Item 2: Accent Theme Color -->
                            <div class="overview-doc-item">
                                <div class="overview-doc-left">
                                    <div class="overview-doc-icon is-theme">
                                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 2a7 7 0 0 0 7 7c0 2-2 3-2 3s.5 1 .5 2a3.5 3.5 0 0 1-5 3.2"/></svg>
                                    </div>
                                    <div class="overview-doc-info">
                                        <div class="overview-doc-name">Accent Color Theme</div>
                                        <div class="overview-doc-status">
                                            <?php if (!$canCustomBrand): ?>
                                                <span style="color: var(--muted);">Unlocked on Business Tier — personalize theme highlights</span>
                                            <?php else: ?>
                                                <span style="color: var(--teal, #10b981); font-weight: 600;">Theme: <code id="hex-label" style="font-family: monospace; font-size: 11px;"><?= h($gym['brand_color'] ?? '#84cc16') ?></code></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="overview-doc-right">
                                    <?php if (!$canCustomBrand): ?>
                                        <a href="index.php?page=gym_subscription" class="tier-pill" style="text-decoration: none; font-size: 11px; padding: 4px 10px;">
                                            Business Plan
                                        </a>
                                    <?php else: ?>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <input type="color" name="brand_color" id="brand_color_picker" value="<?= h($gym['brand_color'] ?? '#84cc16') ?>" style="width: 32px; height: 28px; padding: 0; cursor: pointer; border-radius: 6px; border: 1px solid var(--line); background: transparent; flex-shrink: 0;">
                                            <div class="color-swatch-grid" style="margin: 0;">
                                                <?php 
                                                    $presets = ['#84cc16', '#7c5cfc', '#10b981', '#3b82f6'];
                                                    $currentColor = $gym['brand_color'] ?? '#84cc16';
                                                    foreach ($presets as $hex):
                                                        $isActive = strtolower($currentColor) === strtolower($hex);
                                                ?>
                                                    <div class="color-swatch <?= $isActive ? 'active' : '' ?>" 
                                                         data-color="<?= h($hex) ?>" 
                                                         style="background-color: <?= h($hex) ?>; width: 22px; height: 22px;"></div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </section>

                <!-- 3. BUSINESS LICENSE & PERMIT -->
                <section class="profile-card settings-tab-pane" id="sec-documents">
                    <div class="profile-card-header">
                        <div>
                            <h3 class="profile-card-title" style="margin-bottom: 2px;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                                Business License & Permit
                            </h3>
                            <span style="font-size: 11.5px; color: var(--muted);">Compliance & verification records.</span>
                        </div>
                        <?php if (!empty($gym['business_permit_url'])): ?>
                            <span style="font-size: 10.5px; font-weight: 700; color: var(--teal, #10b981); background: color-mix(in srgb, var(--teal, #10b981) 14%, transparent); border: 1px solid color-mix(in srgb, var(--teal, #10b981) 28%, transparent); padding: 2px 7px; border-radius: 5px;">
                                Permit on File
                            </span>
                        <?php else: ?>
                            <span style="font-size: 10.5px; font-weight: 700; color: var(--danger, #ef4444); background: color-mix(in srgb, var(--danger, #ef4444) 14%, transparent); border: 1px solid color-mix(in srgb, var(--danger, #ef4444) 28%, transparent); padding: 2px 7px; border-radius: 5px;">
                                Missing
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Clean overview list of files -->
                    <div class="overview-doc-list">
                        <!-- Business Permit -->
                        <div class="overview-doc-item">
                            <div class="overview-doc-left">
                                <div class="overview-doc-icon <?= !empty($gym['business_permit_url']) ? 'is-verified' : 'is-missing' ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                </div>
                                <div class="overview-doc-info">
                                    <div class="overview-doc-name">Business / Mayor's Permit</div>
                                    <div class="overview-doc-status" title="<?= h($gym['business_permit_url'] ?? '') ?>">
                                        <?php if (!empty($gym['business_permit_url'])): ?>
                                            <span style="color: var(--teal, #10b981); font-weight: 600;">✅ <?= h(clean_doc_name($gym['business_permit_url'])) ?></span>
                                        <?php else: ?>
                                            <span style="color: var(--danger, #ef4444); font-weight: 600;">⚠️ Missing</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if (!empty($gym['business_permit_url'])): ?>
                                <a href="assets/permits/<?= h($gym['business_permit_url']) ?>" target="_blank" class="btn btn-secondary" style="font-size: 11px; padding: 4px 9px; border-radius: 6px;">
                                    View
                                </a>
                            <?php endif; ?>
                        </div>

                        <!-- Barangay Clearance -->
                        <div class="overview-doc-item">
                            <div class="overview-doc-left">
                                <div class="overview-doc-icon <?= !empty($gym['barangay_clearance_url']) ? 'is-verified' : 'is-optional' ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                </div>
                                <div class="overview-doc-info">
                                    <div class="overview-doc-name">Barangay Clearance</div>
                                    <div class="overview-doc-status" title="<?= h($gym['barangay_clearance_url'] ?? '') ?>">
                                        <?php if (!empty($gym['barangay_clearance_url'])): ?>
                                            <span style="color: var(--teal, #10b981); font-weight: 600;">✅ <?= h(clean_doc_name($gym['barangay_clearance_url'])) ?></span>
                                        <?php else: ?>
                                            <span style="color: var(--muted);">⚠️ Not uploaded</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if (!empty($gym['barangay_clearance_url'])): ?>
                                <a href="assets/permits/<?= h($gym['barangay_clearance_url']) ?>" target="_blank" class="btn btn-secondary" style="font-size: 11px; padding: 4px 9px; border-radius: 6px;">
                                    View
                                </a>
                            <?php endif; ?>
                        </div>

                        <!-- Fire Safety Certificate -->
                        <div class="overview-doc-item">
                            <div class="overview-doc-left">
                                <div class="overview-doc-icon <?= !empty($gym['fire_safety_cert_url']) ? 'is-verified' : 'is-optional' ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                </div>
                                <div class="overview-doc-info">
                                    <div class="overview-doc-name">Fire Safety Inspection <span style="font-size: 10.5px; color: var(--muted);">(Optional)</span></div>
                                    <div class="overview-doc-status" title="<?= h($gym['fire_safety_cert_url'] ?? '') ?>">
                                        <?php if (!empty($gym['fire_safety_cert_url'])): ?>
                                            <span style="color: var(--teal, #10b981); font-weight: 600;">✅ <?= h(clean_doc_name($gym['fire_safety_cert_url'])) ?></span>
                                        <?php else: ?>
                                            <span style="color: var(--muted);">Optional</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if (!empty($gym['fire_safety_cert_url'])): ?>
                                <a href="assets/permits/<?= h($gym['fire_safety_cert_url']) ?>" target="_blank" class="btn btn-secondary" style="font-size: 11px; padding: 4px 9px; border-radius: 6px;">
                                    View
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <button type="button" class="btn-manage-section" onclick="openCustomModal('modal-documents')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        <span>Manage Documents</span>
                    </button>
                </section>

                <!-- 4. MEMBER INACTIVITY & REMINDERS -->
                <section class="profile-card settings-tab-pane" id="sec-reminders">
                    <div class="profile-card-header">
                        <div>
                            <h3 class="profile-card-title" style="margin-bottom: 2px;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                                Inactivity & Churn Reminders
                            </h3>
                            <span style="font-size: 11.5px; color: var(--muted);">Retention triggers and automated follow-ups.</span>
                        </div>
                        <span class="tier-pill" style="font-size: 10.5px; padding: 2px 8px;">Retention</span>
                    </div>

                    <div class="overview-doc-list">
                        <!-- Item 1: Auto Alerts Toggle -->
                        <div class="overview-doc-item">
                            <div class="overview-doc-left">
                                <div class="overview-doc-icon is-theme">
                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                                </div>
                                <div class="overview-doc-info">
                                    <div class="overview-doc-name">Automated "We Miss You!" Reminders</div>
                                    <div class="overview-doc-status">
                                        <span>Automatically alert members who stop attending workouts</span>
                                    </div>
                                </div>
                            </div>
                            <div class="overview-doc-right">
                                <label class="gym-switch-label" title="Toggle reminders">
                                    <input type="checkbox" name="auto_inactivity_alerts" value="1" <?= ($gym['auto_inactivity_alerts'] ?? 1) ? 'checked' : '' ?>>
                                    <span class="gym-switch-slider"></span>
                                </label>
                            </div>
                        </div>

                        <!-- Item 2: Inactivity Threshold -->
                        <div class="overview-doc-item">
                            <div class="overview-doc-left">
                                <div class="overview-doc-icon is-optional">
                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                </div>
                                <div class="overview-doc-info">
                                    <div class="overview-doc-name">Inactivity Threshold</div>
                                    <div class="overview-doc-status">
                                        <span>Absence days before sending alert (Default: 3 days)</span>
                                    </div>
                                </div>
                            </div>
                            <div class="overview-doc-right" style="width: 140px;">
                                <div class="input-icon-wrap">
                                    <span class="input-icon">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                    </span>
                                    <input type="number" min="1" max="90" name="inactivity_threshold_days" class="input-with-icon" value="<?= h((string)($gym['inactivity_threshold_days'] ?? '')) ?>" placeholder="3 days" style="height: 36px; font-size: 13px;">
                                </div>
                            </div>
                        </div>

                        <!-- Item 3: Re-send Cooldown -->
                        <div class="overview-doc-item">
                            <div class="overview-doc-left">
                                <div class="overview-doc-icon is-optional">
                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                                </div>
                                <div class="overview-doc-info">
                                    <div class="overview-doc-name">Re-send Cooldown</div>
                                    <div class="overview-doc-status">
                                        <span>Waiting period between repeated reminders (Default: 14 days)</span>
                                    </div>
                                </div>
                            </div>
                            <div class="overview-doc-right" style="width: 140px;">
                                <div class="input-icon-wrap">
                                    <span class="input-icon">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                                    </span>
                                    <input type="number" min="1" max="180" name="inactivity_cooldown_days" class="input-with-icon" value="<?= h((string)($gym['inactivity_cooldown_days'] ?? '')) ?>" placeholder="14 days" style="height: 36px; font-size: 13px;">
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 5. GYM PHOTO GALLERY -->
                <section class="profile-card settings-tab-pane" id="sec-gallery">
                    <div class="profile-card-header">
                        <div>
                            <h3 class="profile-card-title" style="margin-bottom: 2px;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                Gym Photo Gallery
                            </h3>
                            <span style="font-size: 11.5px; color: var(--muted);">Showcase your gym facilities and equipment.</span>
                        </div>
                        <span class="capacity-pill" style="font-size: 11px; font-weight: 700;">
                            <?= $currentGalleryCount ?> / 10 Photos
                        </span>
                    </div>

                    <div class="overview-doc-list">
                        <div class="overview-doc-item">
                            <div class="overview-doc-left">
                                <div class="overview-doc-icon <?= $currentGalleryCount > 0 ? 'is-verified' : 'is-theme' ?>">
                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                </div>
                                <div class="overview-doc-info">
                                    <div class="overview-doc-name">Facility & Equipment Media</div>
                                    <div class="overview-doc-status">
                                        <?php if ($currentGalleryCount > 0): ?>
                                            <span style="color: var(--teal, #10b981); font-weight: 600;">✅ <?= $currentGalleryCount ?> photo(s) active on public page</span>
                                        <?php else: ?>
                                            <span style="color: var(--muted);">No photos uploaded yet — click to add gallery photos</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="overview-doc-right">
                                <button type="button" class="btn btn-secondary" onclick="openCustomModal('modal-gallery')" style="font-size: 11.5px; padding: 6px 12px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px;">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                    <span>Manage Photos</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($galleryImages)): ?>
                        <div class="overview-gallery-strip" style="margin-top: 12px; margin-bottom: 0;">
                            <?php 
                                $previewLimit = 6;
                                $previewImages = array_slice($galleryImages, 0, $previewLimit);
                                foreach ($previewImages as $pImg): 
                            ?>
                                <div class="overview-gallery-thumb" onclick="openCustomModal('modal-gallery')" title="Manage photos">
                                    <img src="<?= h(upload_url($pImg['image_url'])) ?>" alt="Preview" loading="lazy">
                                </div>
                            <?php endforeach; ?>

                            <?php if ($currentGalleryCount > $previewLimit): ?>
                                <div class="overview-gallery-more" onclick="openCustomModal('modal-gallery')" title="View all photos">
                                    <span>+<?= $currentGalleryCount - $previewLimit ?></span>
                                    <span style="font-size: 9.5px; text-transform: uppercase;">More</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- 6. MEMBER REVIEWS & RATINGS TAB -->
                <section class="profile-card settings-tab-pane" id="sec-ratings" style="display: none;">
                    <div class="card-header">
                        <div class="card-header-icon" style="background: rgba(245, 158, 11, 0.12); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.25);">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="#fbbf24" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        </div>
                        <div class="card-header-title-wrap">
                            <h2 class="card-title">Member Ratings & Reviews</h2>
                            <p class="card-desc">Authentic ratings and feedback submitted by your gym members.</p>
                        </div>
                        <a href="index.php?page=view_gym&gym_id=<?= (int)$gym['gym_id'] ?>#gym-ratings-section" target="_blank" class="btn btn-secondary" style="font-size: 12px; padding: 6px 12px; gap: 5px; margin-left: auto;">
                            <span>View Public Page</span>
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                        </a>
                    </div>

                    <!-- Summary Stats Card -->
                    <div style="display: grid; grid-template-columns: minmax(200px, 260px) 1fr; gap: 20px; background: var(--panel-soft); border: 1px solid var(--line); border-radius: 14px; padding: 20px; margin-bottom: 20px;">
                        <div style="display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; border-right: 1px solid var(--line); padding-right: 20px;">
                            <div style="font-size: 46px; font-weight: 900; color: #fbbf24; line-height: 1; letter-spacing: -1px; margin-bottom: 6px;">
                                <?= number_format((float)$gymRatingStats['avg_rating'], 1) ?>
                            </div>
                            <?= render_star_rating($gymRatingStats['avg_rating'], 'md') ?>
                            <div style="font-size: 12px; color: var(--muted); margin-top: 6px; font-weight: 600;">
                                <?= (int)$gymRatingStats['total_reviews'] ?> total member <?= (int)$gymRatingStats['total_reviews'] === 1 ? 'review' : 'reviews' ?>
                            </div>
                        </div>

                        <!-- Star breakdown -->
                        <div style="display: flex; flex-direction: column; justify-content: center; gap: 8px;">
                            <?php for ($s = 5; $s >= 1; $s--): 
                                $bCount = $gymRatingStats['breakdown'][$s] ?? 0;
                                $bPct = $gymRatingStats['breakdown_pct'][$s] ?? 0;
                            ?>
                                <div style="display: flex; align-items: center; gap: 10px; font-size: 12px;">
                                    <span style="width: 32px; color: var(--muted); font-weight: 600;"><?= $s ?> ★</span>
                                    <div style="flex: 1; height: 8px; border-radius: 999px; background: var(--line); overflow: hidden;">
                                        <div style="height: 100%; width: <?= $bPct ?>%; background: #fbbf24; border-radius: 999px; transition: width 0.3s ease;"></div>
                                    </div>
                                    <span style="width: 70px; text-align: right; color: var(--muted); font-size: 11.5px;"><?= $bCount ?> (<?= $bPct ?>%)</span>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <!-- Reviews List -->
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <h3 style="margin: 0; font-size: 14px; font-weight: 700; color: var(--ink);">Recent Member Feedback</h3>
                        <?php if (!empty($gymMemberReviews)): ?>
                            <?php foreach ($gymMemberReviews as $rev): ?>
                                <div style="background: var(--panel-soft); border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px;">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 8px;">
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div style="width: 36px; height: 36px; border-radius: 10px; background: color-mix(in srgb, var(--lime) 15%, transparent); color: var(--lime); font-weight: 800; font-size: 13px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; overflow: hidden;">
                                                <?php if (!empty($rev['profile_picture'])): ?>
                                                    <img src="<?= h($rev['profile_picture']) ?>" alt="<?= h($rev['first_name']) ?>" style="width:100%;height:100%;object-fit:cover;">
                                                <?php else: ?>
                                                    <?= h(strtoupper(substr($rev['first_name'], 0, 1) . substr($rev['last_name'], 0, 1))) ?>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <div style="font-size: 13.5px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 6px;">
                                                    <?= h($rev['first_name'] . ' ' . $rev['last_name']) ?>
                                                    <span style="font-size: 10.5px; font-weight: 600; color: var(--muted); background: var(--panel); border: 1px solid var(--line); padding: 1px 6px; border-radius: 4px;">
                                                        <?= $rev['is_enrolled'] ? 'Enrolled Member' : 'Visitor' ?>
                                                    </span>
                                                </div>
                                                <div style="font-size: 11px; color: var(--muted);"><?= date('M j, Y • g:i A', strtotime($rev['updated_at'] ?: $rev['created_at'])) ?></div>
                                            </div>
                                        </div>
                                        <div>
                                            <?= render_star_rating((float) $rev['rating'], 'sm') ?>
                                        </div>
                                    </div>
                                    <?php if (!empty($rev['review'])): ?>
                                        <div style="font-size: 13px; color: var(--ink); line-height: 1.5; padding: 10px 14px; background: var(--panel); border-radius: 8px; border: 1px solid var(--line);">
                                            <?= nl2br(h($rev['review'])) ?>
                                        </div>
                                    <?php else: ?>
                                        <div style="font-size: 12px; font-style: italic; color: var(--muted);">
                                            Rated <?= (int) $rev['rating'] ?> out of 5 stars (no written comment provided)
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 32px 16px; background: var(--panel-soft); border: 1px dashed var(--line); border-radius: 12px;">
                                <div style="font-size: 26px; color: #fbbf24; margin-bottom: 6px;">★</div>
                                <h4 style="margin: 0 0 4px; font-size: 14px; color: var(--ink);">No reviews received yet</h4>
                                <p style="margin: 0; font-size: 12.5px; color: var(--muted);">Encourage your members to rate their workouts and facility experience.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

            </div>
        </div>

        <input type="hidden" name="image_id" id="delete_image_id" value="">

        <!-- SINGLE STICKY SAVE CHANGES BAR -->
        <div class="sticky-save-bar">
            <div class="save-bar-status-wrap">
                <span id="unsaved-indicator" style="width: 8px; height: 8px; border-radius: 50%; background: #64748b; display: inline-block; transition: background 0.2s; flex-shrink: 0;"></span>
                <span id="save-bar-status" style="font-size: 12.5px; color: var(--muted);">All changes up to date</span>
            </div>
            <button type="submit" id="save-btn" class="btn btn-primary" style="padding: 9px 24px; font-size: 13.5px; font-weight: 700; border-radius: 9px; display: inline-flex; align-items: center; gap: 7px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                <span>Save Profile Changes</span>
            </button>
        </div>

        <!-- ======================================================== -->
        <!-- MODAL 1: MANAGE BUSINESS DOCUMENTS                      -->
        <!-- ======================================================== -->
        <div class="custom-modal-backdrop" id="modal-documents" onclick="handleBackdropClick(event, 'modal-documents')">
            <div class="custom-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="modal-documents-title">
                <div class="custom-modal-header">
                    <div>
                        <h3 class="custom-modal-title" id="modal-documents-title">Manage Business Documents</h3>
                        <p class="custom-modal-subtitle">Upload or update verification compliance files.</p>
                    </div>
                    <button type="button" class="custom-modal-close" onclick="closeCustomModal('modal-documents')" aria-label="Close modal">&times;</button>
                </div>
                <div class="custom-modal-body">
                    <!-- 1. Business Permit -->
                    <div style="background: var(--panel-soft); border: 1px solid var(--line); border-radius: 12px; padding: 14px; display: flex; flex-direction: column; gap: 10px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                            <div>
                                <strong style="font-size: 13px; color: var(--ink); display: block;">Business / Mayor's Permit</strong>
                                <span style="font-size: 11.5px; color: var(--muted);">Primary permit for verified badge.</span>
                            </div>
                            <?php if (!empty($gym['business_permit_url'])): ?>
                                <span style="font-size: 10.5px; font-weight: 700; color: var(--teal, #10b981); background: color-mix(in srgb, var(--teal, #10b981) 14%, transparent); border: 1px solid color-mix(in srgb, var(--teal, #10b981) 28%, transparent); padding: 2px 7px; border-radius: 5px;">
                                    ✅ On File
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($gym['business_permit_url'])): ?>
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; background: var(--panel); border: 1px solid var(--line); border-radius: 7px; padding: 8px 12px;">
                                <span style="font-size: 12px; color: var(--ink); font-weight: 600;" title="<?= h($gym['business_permit_url']) ?>">
                                    <?= h(clean_doc_name($gym['business_permit_url'])) ?>
                                </span>
                                <a href="assets/permits/<?= h($gym['business_permit_url']) ?>" target="_blank" class="btn btn-secondary" style="font-size: 11px; padding: 3px 8px; border-radius: 5px;">
                                    View
                                </a>
                            </div>
                        <?php endif; ?>

                        <label class="modern-dropzone" id="permit-dropzone" style="padding: 12px;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            <span id="permit-label-text" style="font-size: 12.5px; font-weight: 700; color: var(--ink);">
                                <?= !empty($gym['business_permit_url']) ? 'Click to Replace Permit' : 'Click or Drag to Upload Permit' ?>
                            </span>
                            <span style="font-size: 10.5px; color: var(--muted);">PDF, JPG, PNG (Max 10MB)</span>
                            <input type="file" name="business_permit" id="permit-file-input" accept=".pdf,.jpg,.jpeg,.png">
                        </label>
                    </div>

                    <!-- 2. Barangay Clearance -->
                    <div style="background: var(--panel-soft); border: 1px solid var(--line); border-radius: 12px; padding: 14px; display: flex; flex-direction: column; gap: 10px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                            <div>
                                <strong style="font-size: 13px; color: var(--ink); display: block;">Barangay Clearance</strong>
                                <span style="font-size: 11.5px; color: var(--muted);">Local municipal clearance.</span>
                            </div>
                            <?php if (!empty($gym['barangay_clearance_url'])): ?>
                                <span style="font-size: 10.5px; font-weight: 700; color: var(--teal, #10b981); background: color-mix(in srgb, var(--teal, #10b981) 14%, transparent); border: 1px solid color-mix(in srgb, var(--teal, #10b981) 28%, transparent); padding: 2px 7px; border-radius: 5px;">
                                    ✅ On File
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($gym['barangay_clearance_url'])): ?>
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; background: var(--panel); border: 1px solid var(--line); border-radius: 7px; padding: 8px 12px;">
                                <span style="font-size: 12px; color: var(--ink); font-weight: 600;" title="<?= h($gym['barangay_clearance_url']) ?>">
                                    <?= h(clean_doc_name($gym['barangay_clearance_url'])) ?>
                                </span>
                                <a href="assets/permits/<?= h($gym['barangay_clearance_url']) ?>" target="_blank" class="btn btn-secondary" style="font-size: 11px; padding: 3px 8px; border-radius: 5px;">
                                    View
                                </a>
                            </div>
                        <?php endif; ?>

                        <label class="modern-dropzone" id="brgy-dropzone" style="padding: 12px;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            <span id="brgy-label-text" style="font-size: 12.5px; font-weight: 700; color: var(--ink);">
                                <?= !empty($gym['barangay_clearance_url']) ? 'Click to Replace Clearance' : 'Click or Drag to Upload Clearance' ?>
                            </span>
                            <span style="font-size: 10.5px; color: var(--muted);">PDF, JPG, PNG (Max 10MB)</span>
                            <input type="file" name="barangay_clearance" id="brgy-file-input" accept=".pdf,.jpg,.jpeg,.png">
                        </label>
                    </div>

                    <!-- 3. Fire Safety Certificate -->
                    <div style="background: var(--panel-soft); border: 1px solid var(--line); border-radius: 12px; padding: 14px; display: flex; flex-direction: column; gap: 10px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                            <div>
                                <strong style="font-size: 13px; color: var(--ink); display: block;">Fire Safety Inspection Certificate (Optional)</strong>
                                <span style="font-size: 11.5px; color: var(--muted);">BFP inspection certificate.</span>
                            </div>
                            <?php if (!empty($gym['fire_safety_cert_url'])): ?>
                                <span style="font-size: 10.5px; font-weight: 700; color: var(--teal, #10b981); background: color-mix(in srgb, var(--teal, #10b981) 14%, transparent); border: 1px solid color-mix(in srgb, var(--teal, #10b981) 28%, transparent); padding: 2px 7px; border-radius: 5px;">
                                    ✅ On File
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($gym['fire_safety_cert_url'])): ?>
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; background: var(--panel); border: 1px solid var(--line); border-radius: 7px; padding: 8px 12px;">
                                <span style="font-size: 12px; color: var(--ink); font-weight: 600;" title="<?= h($gym['fire_safety_cert_url']) ?>">
                                    <?= h(clean_doc_name($gym['fire_safety_cert_url'])) ?>
                                </span>
                                <a href="assets/permits/<?= h($gym['fire_safety_cert_url']) ?>" target="_blank" class="btn btn-secondary" style="font-size: 11px; padding: 3px 8px; border-radius: 5px;">
                                    View
                                </a>
                            </div>
                        <?php endif; ?>

                        <label class="modern-dropzone" id="fire-dropzone" style="padding: 12px;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            <span id="fire-label-text" style="font-size: 12.5px; font-weight: 700; color: var(--ink);">
                                <?= !empty($gym['fire_safety_cert_url']) ? 'Click to Replace Certificate' : 'Click or Drag to Upload Certificate' ?>
                            </span>
                            <span style="font-size: 10.5px; color: var(--muted);">PDF, JPG, PNG (Max 10MB)</span>
                            <input type="file" name="fire_safety_cert" id="fire-file-input" accept=".pdf,.jpg,.jpeg,.png">
                        </label>
                    </div>
                </div>
                <div class="custom-modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCustomModal('modal-documents')">
                        Cancel
                    </button>
                    <button type="button" class="btn btn-primary" onclick="submitProfileForm()">
                        Save Documents
                    </button>
                </div>
            </div>
        </div>

        <!-- ======================================================== -->
        <!-- MODAL 2: MANAGE GYM PHOTOS                              -->
        <!-- ======================================================== -->
        <div class="custom-modal-backdrop" id="modal-gallery" onclick="handleBackdropClick(event, 'modal-gallery')">
            <div class="custom-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="modal-gallery-title" style="max-width: 720px;">
                <div class="custom-modal-header">
                    <div>
                        <h3 class="custom-modal-title" id="modal-gallery-title">Manage Gym Photo Gallery</h3>
                        <p class="custom-modal-subtitle">Upload or remove facility photos. Maximum 10 photos.</p>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span class="capacity-pill" style="font-size: 11px; font-weight: 700;">
                            <?= $currentGalleryCount ?> / 10
                        </span>
                        <button type="button" class="custom-modal-close" onclick="closeCustomModal('modal-gallery')" aria-label="Close modal">&times;</button>
                    </div>
                </div>
                <div class="custom-modal-body">
                    <?php if ($remainingSlots <= 0): ?>
                        <div style="background: color-mix(in srgb, var(--orange, #f59e0b) 12%, transparent); border: 1px dashed color-mix(in srgb, var(--orange, #f59e0b) 35%, transparent); border-radius: 10px; padding: 14px; text-align: center; color: var(--ink);">
                            <strong style="font-size: 13px; display: block; margin-bottom: 2px;">Photo Limit Reached (10 / 10)</strong>
                            <span style="font-size: 11.5px; color: var(--muted);">Delete existing photos below to upload new ones.</span>
                        </div>
                    <?php else: ?>
                        <div>
                            <label class="modern-dropzone" id="gallery-dropzone">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                <span id="gallery-label-text" style="font-size: 13px; font-weight: 700; color: var(--ink);">Click or Drag Photos to Upload</span>
                                <span style="font-size: 11px; color: var(--muted);">You can upload up to <?= $remainingSlots ?> more photo<?= $remainingSlots > 1 ? 's' : '' ?> (JPG, PNG, WEBP).</span>
                                <input type="file" name="gallery_images[]" id="gallery-file-input" accept=".jpg,.jpeg,.png,.webp" multiple>
                            </label>
                            <div id="gallery-queued-container" class="queued-files-preview"></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($galleryImages)): ?>
                        <div>
                            <label style="font-size: 12.5px; font-weight: 700; color: var(--ink); margin-bottom: 10px; display: block;">
                                Uploaded Facility Photos (<?= count($galleryImages) ?>)
                            </label>
                            <div class="gallery-grid">
                                <?php foreach ($galleryImages as $index => $img): ?>
                                    <div class="gallery-thumb-item" id="gallery-item-<?= $img['id'] ?>">
                                        <img src="<?= h(upload_url($img['image_url'])) ?>" alt="Photo #<?= $index + 1 ?>" loading="lazy" decoding="async">
                                        <button type="button" 
                                                class="gallery-delete-btn"
                                                onclick="confirmGalleryDelete(<?= $img['id'] ?>)"
                                                title="Delete this photo">
                                            &times;
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="custom-modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCustomModal('modal-gallery')">
                        Close
                    </button>
                    <button type="button" class="btn btn-primary" id="gallery-modal-save-btn" onclick="submitProfileForm()">
                        Upload Selected Photos
                    </button>
                </div>
            </div>
        </div>

    </form>
    </div>

    <script>
        function openCustomModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.add('is-open');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeCustomModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.remove('is-open');
                document.body.style.overflow = '';
            }
        }

        function handleBackdropClick(event, id) {
            if (event.target && event.target.id === id) {
                closeCustomModal(id);
            }
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeCustomModal('modal-documents');
                closeCustomModal('modal-gallery');
            }
        });

        // Nav Horizontal Scrolling & Overflow Cues
        function checkNavScrollState() {
            const nav = document.getElementById('settings-nav-sticky');
            const wrapper = document.getElementById('settings-nav-wrapper');
            if (!nav || !wrapper) return;

            const hasOverflow = nav.scrollWidth > nav.clientWidth + 4;
            const isAtStart = nav.scrollLeft <= 6;
            const isAtEnd = nav.scrollLeft + nav.clientWidth >= nav.scrollWidth - 8;

            wrapper.classList.toggle('has-overflow-left', hasOverflow && !isAtStart);
            wrapper.classList.toggle('has-overflow-right', hasOverflow && !isAtEnd);
        }

        const tabMeta = {
            general: {
                label: 'General Info',
                svg: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>'
            },
            branding: {
                label: 'Branding',
                svg: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M21 12H3M12 3v18"/></svg>'
            },
            documents: {
                label: 'Documents',
                svg: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>'
            },
            reminders: {
                label: 'Reminders',
                svg: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>'
            },
            gallery: {
                label: 'Photo Gallery',
                svg: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>'
            },
            ratings: {
                label: 'Member Reviews',
                svg: '<svg width="17" height="17" viewBox="0 0 24 24" fill="#fbbf24" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>'
            },
            all: {
                label: 'Show All Sections',
                svg: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>'
            }
        };

        function toggleMobileNavDropdown() {
            const menu = document.getElementById('mobile-selector-menu');
            const trigger = document.getElementById('mobile-selector-trigger');
            if (!menu || !trigger) return;
            const isOpen = menu.classList.contains('is-open');
            if (isOpen) {
                closeMobileNavDropdown();
            } else {
                menu.classList.add('is-open');
                trigger.classList.add('is-open');
                trigger.setAttribute('aria-expanded', 'true');
            }
        }

        function closeMobileNavDropdown() {
            const menu = document.getElementById('mobile-selector-menu');
            const trigger = document.getElementById('mobile-selector-trigger');
            if (menu) menu.classList.remove('is-open');
            if (trigger) {
                trigger.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
            }
        }

        function selectMobileTab(tabName) {
            switchSettingsTab(tabName);
            closeMobileNavDropdown();
        }

        document.addEventListener('click', function(e) {
            const selector = document.getElementById('mobile-section-selector');
            if (selector && !selector.contains(e.target)) {
                closeMobileNavDropdown();
            }
        });

        // Tab Switching
        function switchSettingsTab(tabName, linkEl) {
            const panes = document.querySelectorAll('.settings-tab-pane');
            const links = document.querySelectorAll('.settings-nav-link');
            const contentFlow = document.getElementById('settings-content-flow');

            links.forEach(l => l.classList.remove('active'));
            let activeEl = linkEl;
            if (activeEl) {
                activeEl.classList.add('active');
            } else {
                activeEl = document.querySelector(`.settings-nav-link[data-tab="${tabName}"]`);
                if (activeEl) activeEl.classList.add('active');
            }

            // Sync Mobile Dropdown Trigger Display & Selection Checkmark
            const meta = tabMeta[tabName];
            if (meta) {
                const curLabel = document.getElementById('mobile-current-label');
                const curIcon = document.getElementById('mobile-current-icon');
                if (curLabel) curLabel.textContent = meta.label;
                if (curIcon) curIcon.innerHTML = meta.svg;
            }

            const mobileItems = document.querySelectorAll('.mobile-menu-item');
            mobileItems.forEach(item => {
                item.classList.toggle('active', item.dataset.tab === tabName);
            });

            if (tabName === 'all') {
                if (contentFlow) contentFlow.classList.add('view-all-grid');
                panes.forEach(p => {
                    p.style.display = 'block';
                });
            } else {
                if (contentFlow) contentFlow.classList.remove('view-all-grid');
                panes.forEach(p => {
                    if (p.id === 'sec-' + tabName) {
                        p.style.display = 'block';
                    } else {
                        p.style.display = 'none';
                    }
                });
            }

            try {
                localStorage.setItem('fittracks_gym_settings_tab', tabName);
            } catch (e) {}
        }

        function submitProfileForm() {
            const form = document.getElementById('gym-profile-form');
            if (form) form.requestSubmit();
        }

        function handleFormSubmit(form) {
            const saveBtn = document.getElementById('save-btn');
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.innerHTML = '<span style="display:inline-block;animation:spin 1s linear infinite;">⏳</span> Saving...';
            }
        }

        function confirmGalleryDelete(imageId) {
            Swal.fire({
                title: 'Delete this photo?',
                text: "This image will be removed from your gym gallery.",
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

        document.addEventListener('DOMContentLoaded', function() {
            // Restore active tab (default to 'general' so page is never too long)
            let activeTab = 'general';
            try {
                activeTab = localStorage.getItem('fittracks_gym_settings_tab') || 'general';
            } catch (e) {}
            switchSettingsTab(activeTab);

            const form = document.getElementById('gym-profile-form');
            const unsavedDot = document.getElementById('unsaved-indicator');
            const saveStatus = document.getElementById('save-bar-status');
            let isDirty = false;

            function markDirty() {
                if (!isDirty) {
                    isDirty = true;
                    if (unsavedDot) unsavedDot.style.background = 'var(--orange, #d97706)';
                    if (saveStatus) {
                        saveStatus.innerHTML = '<strong style="color:var(--orange, #d97706);">● Unsaved changes</strong> — click Save to apply';
                    }
                }
            }

            if (form) {
                form.querySelectorAll('input, select, textarea').forEach(el => {
                    el.addEventListener('input', markDirty);
                    el.addEventListener('change', markDirty);
                });
            }

            // Logo preview
            const logoWrap = document.getElementById('logo-avatar-wrap');
            const logoInput = document.getElementById('logo-file-input');
            const logoImg = document.getElementById('logo-preview-img');

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
                        };
                        reader.readAsDataURL(file);
                        markDirty();
                    }
                });
            }

            function setupDragDrop(zone, input) {
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
                    }
                }, false);
            }

            function bindFileInput(zoneId, inputId, labelId, maxMb, docTitle) {
                const zone = document.getElementById(zoneId);
                const input = document.getElementById(inputId);
                const label = document.getElementById(labelId);
                if (!input) return;

                setupDragDrop(zone, input);

                input.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        const file = this.files[0];
                        if (file.size > maxMb * 1024 * 1024) {
                            Swal.fire('File Too Large', `${docTitle} file must be ${maxMb}MB or less.`, 'warning');
                            this.value = '';
                            return;
                        }
                        if (label) {
                            label.textContent = 'Selected: ' + file.name;
                            label.style.color = 'var(--lime, #84cc16)';
                        }
                        markDirty();
                    }
                });
            }

            bindFileInput('permit-dropzone', 'permit-file-input', 'permit-label-text', 10, 'Business Permit');
            bindFileInput('brgy-dropzone', 'brgy-file-input', 'brgy-label-text', 10, 'Barangay Clearance');
            bindFileInput('fire-dropzone', 'fire-file-input', 'fire-label-text', 10, 'Fire Safety Certificate');

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
                            Swal.fire('Upload Limit', `You can only upload up to ${remainingSlots} more photo(s).`, 'warning');
                            this.value = '';
                            if (galleryLabel) {
                                galleryLabel.textContent = 'Click or Drag Photos to Upload';
                                galleryLabel.style.color = 'var(--ink)';
                            }
                            return;
                        }

                        if (galleryLabel) {
                            galleryLabel.textContent = this.files.length + ' photo(s) queued';
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
    </script>
<?php
    render_footer();
}
