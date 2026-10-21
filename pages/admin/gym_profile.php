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
        $latitude = post('latitude') !== null && post('latitude') !== '' ? (float)post('latitude') : null;
        $longitude = post('longitude') !== null && post('longitude') !== '' ? (float)post('longitude') : null;
        $geofenceRadius = post('geofence_radius_meters') !== null && post('geofence_radius_meters') !== '' 
            ? max(20, min(1000, (int)post('geofence_radius_meters'))) 
            : 100;
        $contact = trim((string) post('contact_info'));
        $walkInFee = max(0.0, (float) (post('walk_in_fee') !== null ? post('walk_in_fee') : 100.0));
        $inactivityThreshold = post('inactivity_threshold_days') !== '' && post('inactivity_threshold_days') !== null 
            ? max(1, (int) post('inactivity_threshold_days')) 
            : null;
        $inactivityCooldown = post('inactivity_cooldown_days') !== '' && post('inactivity_cooldown_days') !== null 
            ? max(1, (int) post('inactivity_cooldown_days')) 
            : null;
        $autoInactivityAlerts = isset($_POST['auto_inactivity_alerts']) ? 1 : 0;

        $fbGeneral = post('fb_page_url') !== null ? trim((string)post('fb_page_url')) : null;
        $fbModal = post('modal_fb_page_url') !== null ? trim((string)post('modal_fb_page_url')) : null;
        $fbRaw = $fbModal !== null && $fbModal !== ($gym['fb_page_url'] ?? '')
            ? $fbModal
            : ($fbGeneral !== null ? $fbGeneral : $fbModal);
        $fbPageUrl = trim((string)$fbRaw);
        if ($fbPageUrl !== '') {
            if (!preg_match('~^https?://~i', $fbPageUrl)) {
                $fbPageUrl = 'https://' . $fbPageUrl;
            }
        } else {
            $fbPageUrl = null;
        }

        $payoutChannel = trim((string) post('payout_channel')) ?: ($gym['payout_channel'] ?? 'gcash');
        $payoutAccountNumber = post('payout_account_number') !== null ? trim((string) post('payout_account_number')) : ($gym['payout_account_number'] ?? null);
        $payoutAccountNumber = $payoutAccountNumber !== '' ? $payoutAccountNumber : null;
        $payoutAccountName = post('payout_account_name') !== null ? trim((string) post('payout_account_name')) : ($gym['payout_account_name'] ?? null);
        $payoutAccountName = $payoutAccountName !== '' ? $payoutAccountName : null;
        
        $pendingGateTime = $gym['pending_eod_gate_time'] ?? null;
        $pendingGateEffectiveDate = $gym['eod_gate_effective_date'] ?? null;

        if ($canCustomBrand) {
            $brandColor = trim((string) post('brand_color'));
            if ($brandColor !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $brandColor)) {
                $brandColor = null;
            }
            $staffHideFinancials = isset($_POST['staff_hide_financials']) ? 1 : 0;
            $eodEmailSummary = isset($_POST['eod_email_summary']) ? 1 : 0;
            $publicDescription = trim((string) post('public_description'));
            $publicDescription = mb_substr($publicDescription, 0, 1000);

            if (isset($_POST['eod_gate_time'])) {
                $postedGateTime = trim((string)post('eod_gate_time'));
                if (preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $postedGateTime)) {
                    $postedGateTimeFull = $postedGateTime . ':00';
                    $currentActiveGate = !empty($gym['eod_gate_time']) ? (string)$gym['eod_gate_time'] : '17:00:00';
                    if ($postedGateTimeFull !== $currentActiveGate) {
                        // Schedule new gate time for tomorrow
                        $pendingGateTime = $postedGateTimeFull;
                        $pendingGateEffectiveDate = date('Y-m-d', strtotime('+1 day'));
                    } else {
                        // Reset pending if owner selected the currently active gate time
                        $pendingGateTime = null;
                        $pendingGateEffectiveDate = null;
                    }
                }
            }
        } else {
            $brandColor = $gym['brand_color'] ?? null;
            $staffHideFinancials = (int)($gym['staff_hide_financials'] ?? 0);
            $eodEmailSummary = (int)($gym['eod_email_summary'] ?? 1);
            $publicDescription = $gym['public_description'] ?? null;
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

        // Public gym profile cover image (Business branding feature)
        $coverImageUrl = $gym['cover_image_url'] ?? null;
        if ($canCustomBrand && isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
            try {
                $uploadedCover = FileUpload::storeGymCoverImage($_FILES['cover_image'], (int)$gym['gym_id']);
                if ($coverImageUrl) {
                    FileUpload::deleteGymGalleryImage($coverImageUrl);
                }
                $coverImageUrl = $uploadedCover;
            } catch (RuntimeException $e) {
                flash('Cover image upload failed: ' . $e->getMessage(), 'danger');
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

        if ($name && $address) {
            $contactInfoVal = $contact !== '' ? $contact : null;
            $pdo->prepare('UPDATE gyms SET name = ?, address = ?, contact_info = ?, fb_page_url = ?, walk_in_fee = ?, inactivity_threshold_days = ?, inactivity_cooldown_days = ?, auto_inactivity_alerts = ?, business_permit_url = ?, barangay_clearance_url = ?, fire_safety_cert_url = ?, logo_url = ?, cover_image_url = ?, public_description = ?, brand_color = ?, staff_hide_financials = ?, eod_email_summary = ?, pending_eod_gate_time = ?, eod_gate_effective_date = ?, payout_channel = ?, payout_account_number = ?, payout_account_name = ?, latitude = ?, longitude = ?, geofence_radius_meters = ? WHERE gym_id = ?')
                ->execute([$name, $address, $contactInfoVal, $fbPageUrl, $walkInFee, $inactivityThreshold, $inactivityCooldown, $autoInactivityAlerts, $permitUrl, $brgyUrl, $fireSafetyUrl, $logoUrl, $coverImageUrl, $publicDescription, $brandColor, $staffHideFinancials, $eodEmailSummary, $pendingGateTime, $pendingGateEffectiveDate, $payoutChannel, $payoutAccountNumber, $payoutAccountName, $latitude, $longitude, $geofenceRadius, $gym['gym_id']]);
            flash('Gym settings updated successfully.', 'success');
            redirect('gym_profile');
        } else {
            flash('Facility name and physical address are required.', 'danger');
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
            redirect('gym_profile?open_gallery=1&gallery_deleted=1');
        }
        redirect('gym_profile?open_gallery=1');
    }

    $galleryImages = $pdo->query('SELECT * FROM gym_images WHERE gym_id = ' . (int)$gym['gym_id'] . ' ORDER BY created_at DESC')->fetchAll();
    $gymTier = gym_subscription_tier($gym);
    $currentGalleryCount = count($galleryImages);
    $remainingSlots = max(0, 10 - $currentGalleryCount);

    $gymRatingStats = get_gym_rating_stats((int)$gym['gym_id']);
    $gymMemberReviews = get_gym_reviews((int)$gym['gym_id'], 100);

    render_header('Gym Profile & Settings', $user);
?>
    <link rel="stylesheet" href="<?= h(asset_url('css/pages/gym_profile.css')) ?>">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
    /* Gym Owner Facility Pin Map follows Gym Owner's data-theme preference */
    [data-theme="dark"] #owner-pin-map .leaflet-tile-pane {
        filter: invert(100%) hue-rotate(180deg) brightness(88%) contrast(90%);
    }
    [data-theme="light"] #owner-pin-map .leaflet-tile-pane {
        filter: none !important;
    }
    [data-theme="light"] #owner-pin-map {
        background: #f8fafc !important;
        border-color: #cbd5e1 !important;
    }
    [data-theme="light"] #owner-pin-map .leaflet-bar {
        border-color: #cbd5e1 !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06) !important;
    }
    [data-theme="light"] #owner-pin-map .leaflet-bar a {
        background: #ffffff !important;
        color: #1e293b !important;
        border-bottom-color: #f1f5f9 !important;
    }
    [data-theme="light"] #owner-pin-map .leaflet-bar a:hover {
        background: #f8fafc !important;
        color: var(--lime) !important;
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

        <form method="post" enctype="multipart/form-data" id="gym-profile-form" onsubmit="return handleFormSubmit(this, event)">
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

                            <div class="span-full" style="grid-column: 1 / -1;">
                                <label class="field-label">Physical Address / Location <span style="color: var(--danger, #ef4444);">*</span></label>
                                <div class="address-input-action-row">
                                    <div class="input-icon-wrap has-textarea">
                                        <span class="input-icon">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                        </span>
                                        <textarea name="address" id="gym_address" class="input-with-icon" rows="2" required placeholder="e.g. 123 Fitness Ave, District 4"><?= h($gym['address']) ?></textarea>
                                    </div>
                                    <button type="button" class="btn-detect-loc-trigger" id="btn-address-use-loc" title="Fill physical address and map pin from current device location">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                                        <span class="btn-loc-text">Use My Current Location</span>
                                    </button>
                                </div>
                                <span class="field-hint">Street address, building number, and city.</span>
                            </div>

                            <div class="span-full" style="grid-column: 1 / -1; background: rgba(255, 255, 255, 0.02); border: 1px solid var(--line, rgba(255, 255, 255, 0.1)); border-radius: 12px; padding: 18px; margin-top: 4px; margin-bottom: 6px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                                    <div>
                                        <label class="field-label" style="font-size: 13.5px; font-weight: 700; color: var(--ink); margin: 0; display: flex; align-items: center; gap: 6px;">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--lime, #84cc16);"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                                            Geolocation Coordinates &amp; Map Pin
                                        </label>
                                        <span class="field-hint" style="margin-top: 2px;">
                                            Used for the Member <em>"Nearest to Me"</em> interactive map view and attendance QR geofencing.
                                        </span>
                                    </div>
                                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                        <button type="button" class="btn btn-secondary" id="btn-detect-owner-loc" style="padding: 6px 14px; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; border-radius: 8px;" title="Autodetect GPS position from this device">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
                                            <span id="btn-detect-loc-text">Use My Current Location</span>
                                        </button>
                                    </div>
                                </div>

                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-top: 10px;">
                                    <div>
                                        <label class="field-label" style="font-size: 12px;">Latitude</label>
                                        <div class="input-icon-wrap">
                                            <span class="input-icon" style="font-size: 11px; font-weight: 800; color: var(--lime, #84cc16);">LAT</span>
                                            <input type="number" step="0.00000001" name="latitude" id="gym_latitude" class="input-with-icon" value="<?= h((string)($gym['latitude'] ?? '')) ?>" placeholder="e.g. 14.599512">
                                        </div>
                                    </div>

                                    <div>
                                        <label class="field-label" style="font-size: 12px;">Longitude</label>
                                        <div class="input-icon-wrap">
                                            <span class="input-icon" style="font-size: 11px; font-weight: 800; color: var(--lime, #84cc16);">LNG</span>
                                            <input type="number" step="0.00000001" name="longitude" id="gym_longitude" class="input-with-icon" value="<?= h((string)($gym['longitude'] ?? '')) ?>" placeholder="e.g. 120.984222">
                                        </div>
                                    </div>

                                    <div>
                                        <label class="field-label" style="font-size: 12px;">QR Check-In Geofence Radius (meters)</label>
                                        <div class="input-icon-wrap">
                                            <span class="input-icon" style="font-size: 11px; font-weight: 800; color: var(--muted);">±m</span>
                                            <input type="number" min="20" max="1000" name="geofence_radius_meters" id="gym_geofence" class="input-with-icon" value="<?= h((string)($gym['geofence_radius_meters'] ?? '100')) ?>" placeholder="100">
                                        </div>
                                    </div>
                                </div>

                                <!-- Interactive Pin Picker Map -->
                                <div style="margin-top: 14px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                        <span style="font-size: 11.5px; color: var(--muted); font-weight: 600;">
                                            Drag the pin or click anywhere on the map to set your gym's physical location:
                                        </span>
                                    </div>
                                    <div id="owner-pin-map" style="height: 250px; width: 100%; border-radius: 10px; border: 1px solid rgba(255,255,255,0.12); background: #0c1017; overflow: hidden; position: relative; z-index: 1;"></div>
                                </div>
                            </div>

                            <div>
                                <label class="field-label">Gym Contact Number / Email <span class="opt-label" style="font-weight: 400; color: var(--muted); font-size: 11.5px; margin-left: 4px;">(Optional)</span></label>
                                <div class="input-icon-wrap">
                                    <span class="input-icon">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                    </span>
                                    <input type="text" name="contact_info" class="input-with-icon" value="<?= h($gym['contact_info'] ?? '') ?>" placeholder="e.g. 09123456789 / info@gym.com">
                                </div>
                                <span class="field-hint">Phone number or support email for inquiries.</span>
                            </div>

                            <div>
                                <label class="field-label" for="fb_page_url">Facebook / Social Media Page</label>
                                <div class="input-icon-wrap">
                                    <span class="input-icon">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                    </span>
                                    <input type="text" name="fb_page_url" id="fb_page_url" class="input-with-icon" value="<?= h($gym['fb_page_url'] ?? '') ?>" placeholder="https://facebook.com/yourgympage">
                                </div>
                                <span class="field-hint">Official Facebook or social profile link for prospective members.</span>
                            </div>

                            <div class="span-full" style="grid-column: 1 / -1; margin-top: 14px; padding-top: 16px; border-top: 1px dashed var(--line, #cbd5e1);">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px; flex-wrap: wrap; gap: 6px;">
                                    <label class="field-label" style="font-size: 13.5px; font-weight: 700; color: var(--ink); margin: 0;">
                                        Online Member Payments Payout Destination
                                    </label>
                                    <span style="font-size: 11px; font-weight: 700; color: var(--lime); background: rgba(132, 204, 22, 0.12); border: 1px solid rgba(132, 204, 22, 0.28); padding: 2px 8px; border-radius: 4px;">
                                        Xendit Settlement
                                    </span>
                                </div>
                                <span class="field-hint" style="margin-bottom: 12px; display: block; line-height: 1.45;">
                                    When members pay for memberships online (via GCash, Maya, cards, or bank), earnings collected in your XenPlatform sub-account are disbursed to this destination.
                                </span>

                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px;">
                                    <div>
                                        <label class="field-label">Payout Channel</label>
                                        <div class="input-icon-wrap">
                                            <span class="input-icon">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                                            </span>
                                            <select name="payout_channel" class="input-with-icon" style="padding-left: 36px; height: 38px;">
                                                <option value="gcash" <?= ($gym['payout_channel'] ?? 'gcash') === 'gcash' ? 'selected' : '' ?>>GCash</option>
                                                <option value="maya" <?= ($gym['payout_channel'] ?? '') === 'maya' ? 'selected' : '' ?>>Maya</option>
                                                <option value="bank_transfer" <?= ($gym['payout_channel'] ?? '') === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="field-label">Account / Mobile Number</label>
                                        <div class="input-icon-wrap">
                                            <span class="input-icon">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                            </span>
                                            <input type="text" name="payout_account_number" class="input-with-icon" value="<?= h($gym['payout_account_number'] ?? '') ?>" placeholder="e.g. 09171234567">
                                        </div>
                                        <span class="field-hint">Your 11-digit GCash mobile number or bank account.</span>
                                    </div>

                                    <div>
                                        <label class="field-label">Account Holder Full Name</label>
                                        <div class="input-icon-wrap">
                                            <span class="input-icon">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                            </span>
                                            <input type="text" name="payout_account_name" class="input-with-icon" value="<?= h($gym['payout_account_name'] ?? '') ?>" placeholder="e.g. Juan Dela Cruz">
                                        </div>
                                        <span class="field-hint">Exact name on verified GCash / Bank account.</span>
                                    </div>
                                </div>
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

                        <div class="overview-doc-list branding-scroll-list">
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
                            <div class="overview-doc-item is-accent-theme">
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
                                                <span style="color: var(--teal, #10b981); font-weight: 600;">Theme: <code id="hex-label"><?= h($gym['brand_color'] ?? '#84cc16') ?></code></span>
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
                                                    $presets = ['#84cc16', '#7c5cfc', '#10b981', '#3b82f6', '#f97316', '#ec4899', '#14b8a6', '#f43f5e'];
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

                            <div class="public-branding-editor">
                                <div class="public-branding-heading">
                                    <div class="overview-doc-name">Public Gym Page</div>
                                    <span class="field-hint">Add a cover photo and a short introduction to help members recognize your gym.</span>
                                </div>
                                <?php if (!$canCustomBrand): ?>
                                    <a href="index.php?page=gym_subscription" class="tier-pill public-branding-upgrade">Available on Business</a>
                                <?php else: ?>
                                    <div class="public-branding-fields">
                                        <div class="public-cover-column">
                                            <label class="field-label public-description-label" for="cover-image-input">Cover photo</label>
                                            <div class="public-cover-preview" id="public-cover-preview">
                                                <?php if (!empty($gym['cover_image_url'])): ?>
                                                    <img id="public-cover-preview-img" src="<?= h(upload_url($gym['cover_image_url'])) ?>" alt="Current gym cover image">
                                                <?php else: ?>
                                                    <img id="public-cover-preview-img" src="" alt="Cover image preview" hidden>
                                                    <span id="public-cover-placeholder">Your cover photo preview</span>
                                                <?php endif; ?>
                                                <button type="button" class="public-cover-change" id="public-cover-change" aria-label="Change cover photo">Change cover photo</button>
                                            </div>
                                            <input type="file" name="cover_image" id="cover-image-input" accept="image/jpeg,image/png,image/webp,image/gif" class="public-cover-input">
                                        </div>
                                        <div class="public-description-column">
                                            <label class="field-label public-description-label" for="public-description-input">Public description</label>
                                            <textarea name="public_description" id="public-description-input" class="public-description-input" rows="4" maxlength="1000" placeholder="Tell members what makes your gym special."><?= h($gym['public_description'] ?? '') ?></textarea>
                                            <span class="field-hint">Up to 1,000 characters. This appears on your public gym page.</span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Item 3: Automated Midnight EOD Revenue Digest -->
                            <div class="overview-doc-item">
                                <div class="overview-doc-left">
                                    <div class="overview-doc-icon" style="background: rgba(16, 185, 129, 0.12); color: #10b981;">
                                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                    </div>
                                    <div class="overview-doc-info">
                                        <div class="overview-doc-name">Midnight Daily Settlement Email</div>
                                        <div class="overview-doc-status">
                                            <?php if (!$canCustomBrand): ?>
                                                <span style="color: var(--muted);">Unlocked on Business Tier — automatic revenue &amp; check-in digest to owner</span>
                                            <?php else: ?>
                                                <span style="color: var(--muted);">Dispatches daily gross revenue, walk-ins, and expiring members summary</span>
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
                                        <label class="gym-switch-label" title="Toggle Daily Settlement Email">
                                            <input type="checkbox" name="eod_email_summary" value="1" <?= (!isset($gym['eod_email_summary']) || $gym['eod_email_summary']) ? 'checked' : '' ?>>
                                            <span class="gym-switch-slider"></span>
                                        </label>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Item 4: Staff Financial Privacy Mode -->
                            <div class="overview-doc-item">
                                <div class="overview-doc-left">
                                    <div class="overview-doc-icon" style="background: rgba(244, 63, 94, 0.12); color: #f43f5e;">
                                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    </div>
                                    <div class="overview-doc-info">
                                        <div class="overview-doc-name">Staff Financial Privacy Mode</div>
                                        <div class="overview-doc-status">
                                            <?php if (!$canCustomBrand): ?>
                                                <span style="color: var(--muted);">Unlocked on Business Tier — hide net revenues &amp; financial reports from staff</span>
                                            <?php else: ?>
                                                <span style="color: var(--muted);">Masks financial totals from Front Desk Cashiers while allowing check-ins</span>
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
                                        <label class="gym-switch-label" title="Toggle Staff Financial Privacy">
                                            <input type="checkbox" name="staff_hide_financials" value="1" <?= (!empty($gym['staff_hide_financials'])) ? 'checked' : '' ?>>
                                            <span class="gym-switch-slider"></span>
                                        </label>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Item 5: EOD Gate Opening Time -->
                            <div class="overview-doc-item">
                                <div class="overview-doc-left">
                                    <div class="overview-doc-icon" style="background: rgba(245, 158, 11, 0.12); color: #f59e0b;">
                                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    </div>
                                    <div class="overview-doc-info">
                                        <div class="overview-doc-name">EOD Settlement Gate Opening</div>
                                        <div class="overview-doc-status">
                                            <?php if (!$canCustomBrand): ?>
                                                <span style="color: var(--muted);">Unlocked on Business Tier — customize the daily hour when Z-Reading settlement emails unlock</span>
                                            <?php else: ?>
                                                <span style="color: var(--muted);">Daily cutoff hour when EOD settlement email unlocks for staff &amp; owners (Default: 5:00 PM)</span>
                                                <?php if (!empty($gym['pending_eod_gate_time']) && !empty($gym['eod_gate_effective_date'])): ?>
                                                    <div style="margin-top: 5px; font-size: 11.5px; color: #f59e0b; font-weight: 600; display: flex; align-items: center; gap: 5px;">
                                                        <span>⏱️</span>
                                                        <span>Scheduled change to <strong><?= date('g:i A', strtotime("1970-01-01 " . $gym['pending_eod_gate_time'])) ?></strong> takes effect tomorrow (<?= date('M j, Y', strtotime((string)$gym['eod_gate_effective_date'])) ?>)</span>
                                                    </div>
                                                <?php endif; ?>
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
                                        <?php
                                            $activeGate = !empty($gym['eod_gate_time']) ? substr((string)$gym['eod_gate_time'], 0, 5) : '17:00';
                                            $selectedGate = !empty($gym['pending_eod_gate_time']) ? substr((string)$gym['pending_eod_gate_time'], 0, 5) : $activeGate;
                                            $timeOptions = [
                                                '12:00' => '12:00 PM (Noon)',
                                                '13:00' => '1:00 PM',
                                                '14:00' => '2:00 PM',
                                                '15:00' => '3:00 PM',
                                                '16:00' => '4:00 PM',
                                                '17:00' => '5:00 PM (Default)',
                                                '18:00' => '6:00 PM',
                                                '19:00' => '7:00 PM',
                                                '20:00' => '8:00 PM',
                                                '21:00' => '9:00 PM',
                                                '22:00' => '10:00 PM',
                                                '23:00' => '11:00 PM',
                                            ];
                                        ?>
                                        <select name="eod_gate_time" id="eod_gate_time_select" data-current-gate="<?= h($activeGate) ?>" style="padding: 7px 12px; border-radius: 8px; font-size: 12.5px; font-weight: 600; background: var(--surface); color: var(--ink); border: 1px solid var(--line); cursor: pointer; max-width: 170px;">
                                            <?php foreach ($timeOptions as $val => $lbl): ?>
                                                <option value="<?= $val ?>" <?= $val === $selectedGate ? 'selected' : '' ?>><?= $lbl ?></option>
                                            <?php endforeach; ?>
                                        </select>
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
                                <a href="<?= h(upload_url($gym['business_permit_url'], 'permits')) ?>" target="_blank" class="btn btn-secondary" style="font-size: 11px; padding: 4px 9px; border-radius: 6px;">
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
                                <a href="<?= h(upload_url($gym['barangay_clearance_url'], 'permits')) ?>" target="_blank" class="btn btn-secondary" style="font-size: 11px; padding: 4px 9px; border-radius: 6px;">
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
                                <a href="<?= h(upload_url($gym['fire_safety_cert_url'], 'permits')) ?>" target="_blank" class="btn btn-secondary" style="font-size: 11px; padding: 4px 9px; border-radius: 6px;">
                                    View
                                </a>
                            <?php endif; ?>
                        </div>

                        <!-- Facebook / Social Media Link -->
                        <div class="overview-doc-item">
                            <div class="overview-doc-left">
                                <div class="overview-doc-icon <?= !empty($gym['fb_page_url']) ? 'is-verified' : 'is-optional' ?>" style="<?= !empty($gym['fb_page_url']) ? 'background: color-mix(in srgb, #1877f2 15%, transparent); color: #1877f2; border-color: color-mix(in srgb, #1877f2 35%, transparent);' : '' ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                </div>
                                <div class="overview-doc-info">
                                    <div class="overview-doc-name">Facebook Page / Social Link <span style="font-size: 10.5px; color: var(--muted);">(Optional)</span></div>
                                    <div class="overview-doc-status" title="<?= h($gym['fb_page_url'] ?? '') ?>">
                                        <?php if (!empty($gym['fb_page_url'])): ?>
                                            <span style="color: var(--teal, #10b981); font-weight: 600;">✅ Linked</span>
                                        <?php else: ?>
                                            <span style="color: var(--muted);">Not linked yet</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if (!empty($gym['fb_page_url'])): ?>
                                <a href="<?= h($gym['fb_page_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary" style="font-size: 11px; padding: 4px 9px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;">
                                    <span>Visit</span>
                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
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
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 20px; background: var(--panel-soft); border: 1px solid var(--line); border-radius: 14px; padding: 20px; margin-bottom: 20px; overflow: hidden; box-sizing: border-box;">
                        <div style="display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; border-right: 1px solid var(--line); padding-right: 20px; min-width: 0;">
                            <div style="font-size: 46px; font-weight: 900; color: #fbbf24; line-height: 1; letter-spacing: -1px; margin-bottom: 6px;">
                                <?= number_format((float)$gymRatingStats['avg_rating'], 1) ?>
                            </div>
                            <?= render_star_rating($gymRatingStats['avg_rating'], 'md') ?>
                            <div style="font-size: 12px; color: var(--muted); margin-top: 6px; font-weight: 600;">
                                <?= (int)$gymRatingStats['total_reviews'] ?> total member <?= (int)$gymRatingStats['total_reviews'] === 1 ? 'review' : 'reviews' ?>
                            </div>
                        </div>

                        <!-- Star breakdown -->
                        <div style="display: flex; flex-direction: column; justify-content: center; gap: 8px; min-width: 0;">
                            <?php for ($s = 5; $s >= 1; $s--): 
                                $bCount = $gymRatingStats['breakdown'][$s] ?? 0;
                                $bPct = $gymRatingStats['breakdown_pct'][$s] ?? 0;
                            ?>
                                <div style="display: flex; align-items: center; gap: 8px; font-size: 12px; min-width: 0;">
                                    <span style="width: 28px; flex-shrink: 0; color: var(--muted); font-weight: 600;"><?= $s ?> ★</span>
                                    <div style="flex: 1; min-width: 0; height: 8px; border-radius: 999px; background: var(--line); overflow: hidden;">
                                        <div style="height: 100%; width: <?= $bPct ?>%; background: #fbbf24; border-radius: 999px; transition: width 0.3s ease;"></div>
                                    </div>
                                    <span style="flex-shrink: 0; width: 62px; text-align: right; color: var(--muted); font-size: 11.5px;"><?= $bCount ?> (<?= $bPct ?>%)</span>
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
                                <a href="<?= h(upload_url($gym['business_permit_url'], 'permits')) ?>" target="_blank" class="btn btn-secondary" style="font-size: 11px; padding: 3px 8px; border-radius: 5px;">
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
                                <a href="<?= h(upload_url($gym['barangay_clearance_url'], 'permits')) ?>" target="_blank" class="btn btn-secondary" style="font-size: 11px; padding: 3px 8px; border-radius: 5px;">
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
                                <a href="<?= h(upload_url($gym['fire_safety_cert_url'], 'permits')) ?>" target="_blank" class="btn btn-secondary" style="font-size: 11px; padding: 3px 8px; border-radius: 5px;">
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

                    <!-- 4. Facebook Page / Social Media Link -->
                    <div style="background: var(--panel-soft); border: 1px solid var(--line); border-radius: 12px; padding: 14px; display: flex; flex-direction: column; gap: 10px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                            <div>
                                <strong style="font-size: 13px; color: var(--ink); display: block;">Facebook Page / Social Link (Optional)</strong>
                                <span style="font-size: 11.5px; color: var(--muted);">Facility public profile for verification and member discovery.</span>
                            </div>
                            <?php if (!empty($gym['fb_page_url'])): ?>
                                <span style="font-size: 10.5px; font-weight: 700; color: var(--teal, #10b981); background: color-mix(in srgb, var(--teal, #10b981) 14%, transparent); border: 1px solid color-mix(in srgb, var(--teal, #10b981) 28%, transparent); padding: 2px 7px; border-radius: 5px;">
                                    ✅ Linked
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="input-icon-wrap">
                            <span class="input-icon">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                            </span>
                            <input type="text" name="modal_fb_page_url" id="modal_fb_page_url" class="input-with-icon" value="<?= h($gym['fb_page_url'] ?? '') ?>" placeholder="https://facebook.com/yourgympage">
                        </div>
                        <span class="field-hint" style="font-size: 11px; color: var(--muted); margin-top: -4px;">e.g., https://facebook.com/ironfitnesscenter</span>
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

    <!-- Gym Profile Configuration & Script -->
    <script>
    window.GYM_PROFILE_CONFIG = {
        remainingSlots: <?= (int) ($remainingSlots ?? 10) ?>
    };
    </script>
    <script src="<?= h(asset_url('js/pages/gym_profile.js')) ?>"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const mapContainer = document.getElementById('owner-pin-map');
        const latInput = document.getElementById('gym_latitude');
        const lngInput = document.getElementById('gym_longitude');
        const btnDetect = document.getElementById('btn-detect-owner-loc');
        const btnDetectText = document.getElementById('btn-detect-loc-text');

        if (!mapContainer || typeof L === 'undefined') return;

        let initLat = parseFloat(latInput.value);
        let initLng = parseFloat(lngInput.value);
        let hasCoords = !isNaN(initLat) && !isNaN(initLng);

        // Fallback default: Manila Metro Center
        const center = hasCoords ? [initLat, initLng] : [14.5995, 120.9842];
        const zoom = hasCoords ? 15 : 12;

        const map = L.map('owner-pin-map', {
            center: center,
            zoom: zoom,
            zoomControl: true,
            attributionControl: false
        });

        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors'
        }).addTo(map);

        let marker = null;

        function setPin(lat, lng, pan = false) {
            lat = parseFloat(lat.toFixed(8));
            lng = parseFloat(lng.toFixed(8));
            latInput.value = lat;
            lngInput.value = lng;

            if (marker) {
                marker.setLatLng([lat, lng]);
            } else {
                const icon = L.divIcon({
                    className: 'gym-marker-wrap',
                    html: '<div style="background:#c7ff22; width:28px; height:28px; border-radius:50% 50% 50% 0; transform:rotate(-45deg); display:flex; align-items:center; justify-content:center; box-shadow:0 0 12px rgba(199,255,34,0.6); border:2px solid #000; cursor:grab;"><span style="width:8px; height:8px; border-radius:50%; background:#000; display:block;"></span></div>',
                    iconSize: [28, 28],
                    iconAnchor: [14, 28]
                });
                marker = L.marker([lat, lng], { draggable: true, icon: icon }).addTo(map);

                marker.on('dragend', function (e) {
                    const pos = e.target.getLatLng();
                    setPin(pos.lat, pos.lng, false);
                });
            }

            if (pan) {
                map.flyTo([lat, lng], Math.max(map.getZoom(), 15), { duration: 0.8 });
            }
        }

        if (hasCoords) {
            setPin(initLat, initLng, false);
        }

        map.on('click', function (e) {
            setPin(e.latlng.lat, e.latlng.lng, false);
        });

        [latInput, lngInput].forEach(inp => {
            inp.addEventListener('input', function () {
                const lat = parseFloat(latInput.value);
                const lng = parseFloat(lngInput.value);
                if (!isNaN(lat) && !isNaN(lng)) {
                    setPin(lat, lng, true);
                }
            });
        });

        const addressInput = document.getElementById('gym_address');
        const btnAddressLoc = document.getElementById('btn-address-use-loc');

        async function fetchAddressFromCoords(lat, lng) {
            try {
                const url = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`;
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return null;
                const data = await res.json();
                if (data && data.display_name) {
                    return data.display_name;
                }
            } catch (err) {
                console.warn('Reverse geocoding error:', err);
            }
            return null;
        }

        function triggerLocationAndAddressDetection(triggerBtn) {
            if (!navigator.geolocation) {
                alert('Geolocation is not supported by your browser.');
                return;
            }

            const allLocBtns = document.querySelectorAll('.btn-detect-loc-trigger, #btn-detect-owner-loc');
            allLocBtns.forEach(b => {
                const txt = b.querySelector('.btn-loc-text') || b.querySelector('#btn-detect-loc-text') || b;
                txt.textContent = 'Detecting GPS & Address...';
            });

            navigator.geolocation.getCurrentPosition(
                async function (pos) {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;

                    // 1. Move map pin & update Lat/Lng inputs
                    setPin(lat, lng, true);

                    // 2. Reverse-geocode to get street address
                    const resolvedAddress = await fetchAddressFromCoords(lat, lng);
                    if (resolvedAddress && addressInput) {
                        addressInput.value = resolvedAddress;
                        addressInput.style.borderColor = 'var(--lime, #84cc16)';
                        setTimeout(() => { addressInput.style.borderColor = ''; }, 2000);
                    }

                    allLocBtns.forEach(b => {
                        const txt = b.querySelector('.btn-loc-text') || b.querySelector('#btn-detect-loc-text') || b;
                        txt.textContent = '✓ Location & Address Set!';
                    });

                    setTimeout(() => {
                        allLocBtns.forEach(b => {
                            const txt = b.querySelector('.btn-loc-text') || b.querySelector('#btn-detect-loc-text') || b;
                            txt.textContent = 'Use My Current Location';
                        });
                    }, 2500);
                },
                function (err) {
                    allLocBtns.forEach(b => {
                        const txt = b.querySelector('.btn-loc-text') || b.querySelector('#btn-detect-loc-text') || b;
                        txt.textContent = 'Use My Current Location';
                    });
                    let msg = 'Unable to retrieve device position: ' + err.message;
                    if (err.code === 1) {
                        msg = 'Location permission was denied in your browser. Please allow location access in your address bar.';
                    }
                    alert(msg);
                },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        }

        if (btnDetect) {
            btnDetect.addEventListener('click', function () {
                triggerLocationAndAddressDetection(this);
            });
        }

        if (btnAddressLoc) {
            btnAddressLoc.addEventListener('click', function () {
                triggerLocationAndAddressDetection(this);
            });
        }

        // Invalidate map size after DOM settles
        setTimeout(function () {
            map.invalidateSize();
        }, 300);
    });
    </script>
<?php
    render_footer();
}
