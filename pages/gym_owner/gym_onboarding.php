<?php
declare(strict_types=1);

function gym_onboarding_page(): void
{
    if (!defined('AUTH_PAGE')) define('AUTH_PAGE', true);
    
    $user = current_user();
    if (!$user) {
        redirect('register?role=gym_owner');
    } elseif ($user['role'] !== 'gym_owner') {
        redirect('dashboard');
    }

    $pdo = db();
    // Check if they already have a gym
    $gym = $pdo->query('SELECT status FROM gyms WHERE owner_user_id = ' . (int)$user['user_id'])->fetch();
    if ($gym) {
        if ($gym['status'] === 'pending') {
            redirect('gym_pending');
        } elseif ($gym['status'] === 'rejected') {
            redirect('gym_rejected');
        } else {
            redirect('dashboard');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $gymName = trim((string) post('gym_name'));
        $street = trim((string) post('street'));
        $barangay = trim((string) post('barangay'));
        $city = trim((string) post('city'));
        $province = trim((string) post('province'));
        $zipcode = substr(preg_replace('/[^0-9]/', '', (string) post('zipcode')), 0, 4);
        $gymContact = preg_replace('/[^0-9]/', '', (string) post('gym_contact_info'));
        $validIdType = trim((string) post('valid_id_type'));
        $validIdTypeOther = trim((string) post('valid_id_type_other'));
        $fbPageUrl = trim((string) post('fb_page_url'));

        $addressParts = array_filter([$street, $barangay, $city, $province, $zipcode], fn($val) => $val !== '');
        $gymAddress = implode(', ', $addressParts);

        if (!$gymName || !$street || !$barangay || !$city || !$province || !$zipcode || !$gymContact) {
            flash('Facility name, mobile number, and all address fields (street, barangay, city, province, and zipcode) are required.', 'danger');
        } elseif (strlen($gymContact) !== 11) {
            flash('Mobile number must be exactly 11 digits (e.g. 09123456789).', 'danger');
        } elseif (strlen($zipcode) !== 4) {
            flash('ZIP code must be exactly 4 digits (e.g. 7214).', 'danger');
        } elseif (empty($validIdType)) {
            flash('Please select your Valid Government ID type.', 'danger');
        } else {
            if ($validIdType === 'Other Valid Government ID' && !empty($validIdTypeOther)) {
                $validIdType = 'Other: ' . $validIdTypeOther;
            }

            if (!empty($fbPageUrl)) {
                if (!preg_match('~^(?:f|ht)tps?://~i', $fbPageUrl)) {
                    $fbPageUrl = 'https://' . $fbPageUrl;
                }
                if (!filter_var($fbPageUrl, FILTER_VALIDATE_URL)) {
                    flash('Please enter a valid Facebook Page URL (e.g. https://facebook.com/yourgym) or leave it empty.', 'danger');
                    $fbPageUrl = null;
                }
            } else {
                $fbPageUrl = null;
            }

            require_once __DIR__ . '/../../core/file_handler.php';
            
            try {
                // 1. Required: Business Permit
                $permitFilename = null;
                if (isset($_FILES['business_permit']) && $_FILES['business_permit']['error'] === UPLOAD_ERR_OK) {
                    $permitFilename = FileUpload::storeBusinessPermit($_FILES['business_permit'], (int)$user['user_id']);
                } else {
                    throw new Exception('Business Permit is required.');
                }

                // 2. Required: Barangay Clearance
                $brgyFilename = null;
                if (isset($_FILES['barangay_clearance']) && $_FILES['barangay_clearance']['error'] === UPLOAD_ERR_OK) {
                    $brgyFilename = FileUpload::storeBarangayClearance($_FILES['barangay_clearance'], (int)$user['user_id']);
                } else {
                    throw new Exception('Barangay Clearance is required.');
                }

                // 3. Required: Valid ID
                $validIdFilename = null;
                if (isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] === UPLOAD_ERR_OK) {
                    $validIdFilename = FileUpload::storeValidId($_FILES['valid_id'], (int)$user['user_id']);
                } else {
                    throw new Exception('Valid Government ID upload is required.');
                }

                // 4. Optional: Fire Safety Inspection Certificate
                $fireSafetyFilename = null;
                if (isset($_FILES['fire_safety_cert']) && $_FILES['fire_safety_cert']['error'] === UPLOAD_ERR_OK) {
                    $fireSafetyFilename = FileUpload::storeFireSafetyCert($_FILES['fire_safety_cert'], (int)$user['user_id']);
                }

                $stmt = $pdo->prepare('INSERT INTO gyms (
                    owner_user_id, name, address, contact_info,
                    permit_url, id_url, business_permit_url, valid_id_url,
                    valid_id_type, barangay_clearance_url, fire_safety_cert_url, fb_page_url,
                    status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")');
                $stmt->execute([
                    $user['user_id'],
                    $gymName,
                    $gymAddress,
                    $gymContact,
                    $permitFilename,
                    $validIdFilename,
                    $permitFilename,
                    $validIdFilename,
                    $validIdType,
                    $brgyFilename,
                    $fireSafetyFilename,
                    $fbPageUrl
                ]);

                audit_log($user['user_id'], 'submit_application', 'gym_owner', (string)$user['user_id']);
                
                $ownerName = $user['first_name'] . ' ' . $user['last_name'];
                notify_admins('system', 'New Gym Application', "A new gym application for '{$gymName}' was submitted by {$ownerName}. Please review it in the Gym Applications dashboard.");
                
                Emails::sendNewGymApplication($gymName, $ownerName);
                Emails::sendGymApplicationSubmitted((string)$user['email'], $ownerName, $gymName);

                flash('Your gym application has been submitted successfully! We have sent a confirmation email to ' . htmlspecialchars((string)$user['email']) . '.', 'success');
                redirect('gym_pending');

            } catch (Exception $e) {
                flash($e->getMessage(), 'danger');
            }
        }
    }

    // Suppress conflicting "Welcome back" flash toast on onboarding page
    if (isset($_SESSION['flash']['message']) && str_starts_with((string)$_SESSION['flash']['message'], 'Welcome back')) {
        unset($_SESSION['flash']);
    }

    render_header('Gym Onboarding', $user);
    ?>
    <style>
        /* Viewport & Wizard Frame Spacing - Compact & balanced */
        body:has(.onboarding-wizard-frame) .split-login-viewport,
        .split-login-viewport:has(.onboarding-wizard-frame) {
            padding: 14px 16px !important;
            min-height: 100vh !important;
            min-height: 100dvh !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
        }

        .onboarding-wizard-frame {
            margin: 0 auto !important;
            width: 100% !important;
            max-width: 700px !important;
        }

        .onboarding-brand-header {
            margin-bottom: 10px !important;
        }
        .onboarding-brand-icon svg {
            width: 30px !important;
            height: 30px !important;
        }
        .onboarding-brand-name {
            font-size: 20px !important;
        }
        .onboarding-brand-tagline {
            font-size: 9.5px !important;
            margin-top: 1px !important;
        }

        /* Card Padding & Sizing - Proportional 700px container */
        .split-login-card.onboarding-card {
            padding: 20px 28px 18px !important;
            border-radius: 18px !important;
            max-width: 700px !important;
            box-shadow: 0 20px 50px -15px rgba(0, 0, 0, 0.65), 0 0 0 1px rgba(255, 255, 255, 0.1) !important;
            box-sizing: border-box !important;
            overflow: hidden !important;
        }

        .onboarding-card .split-card-header {
            margin-bottom: 10px !important;
        }
        .onboarding-card .split-card-title {
            font-size: 1.5rem !important;
            margin: 0 0 2px !important;
            line-height: 1.15 !important;
        }
        .onboarding-card .split-card-subtitle {
            font-size: 12.5px !important;
            line-height: 1.2 !important;
        }

        /* Stepper */
        .onboarding-stepper {
            margin-bottom: 12px !important;
            gap: 10px !important;
        }
        .onboarding-stepper .stepper-item {
            gap: 4px !important;
        }
        .onboarding-stepper .stepper-label {
            font-size: 11.5px !important;
        }
        .onboarding-stepper .stepper-bar {
            height: 3.5px !important;
        }

        /* Section Title */
        .onboarding-card .onboarding-section-title {
            font-size: 10.5px !important;
            margin-top: 0 !important;
            margin-bottom: 8px !important;
            letter-spacing: 0.5px !important;
        }

        /* Form Structure & Strict Grid Containment */
        .onboarding-card .split-card-form {
            gap: 0 !important;
            width: 100% !important;
        }
        .onboarding-card .split-step-pane {
            gap: 0 !important;
            width: 100% !important;
        }
        .onboarding-card .split-form-row-2col {
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) !important;
            gap: 12px !important;
            margin-bottom: 8px !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }
        .onboarding-card .split-form-group {
            gap: 3px !important;
            min-width: 0 !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }
        .onboarding-card .split-form-group label {
            display: inline-flex !important;
            align-items: baseline !important;
            flex-direction: row !important;
            gap: 1px !important;
            font-size: 11.5px !important;
            font-weight: 600 !important;
            color: #334155 !important;
            margin-bottom: 1px !important;
            white-space: nowrap !important;
        }
        .onboarding-card .req-star {
            display: inline !important;
            color: #ef4444 !important;
            font-weight: 700 !important;
            margin-left: 1px !important;
            line-height: 1 !important;
        }
        .onboarding-card .opt-label {
            display: inline !important;
            font-size: 10.5px !important;
            color: #64748b !important;
        }

        /* Form Inputs */
        .onboarding-card .split-input-wrap {
            width: 100% !important;
            min-width: 0 !important;
            box-sizing: border-box !important;
        }
        .onboarding-card .split-input-wrap input,
        .onboarding-card .split-input-wrap select {
            height: 39px !important;
            padding: 7px 12px 7px 38px !important;
            font-size: 13px !important;
            border-radius: 9px !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }
        .onboarding-card .split-input-wrap select {
            padding-right: 28px !important;
            text-overflow: ellipsis !important;
            white-space: nowrap !important;
            overflow: hidden !important;
        }
        .onboarding-card .split-input-icon {
            left: 12px !important;
            width: 16px !important;
            height: 16px !important;
        }

        /* Upload Boxes (Step 2) - Strict Ellipsis & Overflow Protection */
        .onboarding-card .split-file-upload-box {
            min-height: 40px !important;
            padding: 5px 8px !important;
            border-radius: 8px !important;
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            overflow: hidden !important;
            box-sizing: border-box !important;
        }
        .onboarding-card .doc-upload-empty {
            gap: 8px !important;
            width: 100% !important;
            min-width: 0 !important;
        }
        .onboarding-card .doc-upload-empty .split-upload-icon {
            width: 18px !important;
            height: 18px !important;
            flex-shrink: 0 !important;
        }
        .onboarding-card .doc-upload-text {
            min-width: 0 !important;
            overflow: hidden !important;
        }
        .onboarding-card .doc-upload-text .split-upload-title {
            font-size: 11px !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }
        .onboarding-card .doc-upload-text .split-upload-desc {
            font-size: 9.5px !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }
        .onboarding-card .doc-upload-filled {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            overflow: hidden !important;
            align-items: center !important;
            gap: 8px !important;
            box-sizing: border-box !important;
        }
        .onboarding-card .doc-upload-filled[style*="display: none"],
        .onboarding-card .doc-upload-filled[style*="display:none"] {
            display: none !important;
        }
        .onboarding-card .doc-upload-empty[style*="display: none"],
        .onboarding-card .doc-upload-empty[style*="display:none"] {
            display: none !important;
        }
        .onboarding-card .doc-preview-thumb-wrap {
            width: 32px !important;
            height: 32px !important;
            flex-shrink: 0 !important;
        }
        .onboarding-card .doc-upload-meta {
            flex: 1 1 0% !important;
            min-width: 0 !important;
            max-width: 100% !important;
            overflow: hidden !important;
            text-align: left !important;
        }
        .onboarding-card .doc-upload-name {
            display: block !important;
            width: 100% !important;
            min-width: 0 !important;
            font-size: 11px !important;
            font-weight: 700 !important;
            color: #0f172a !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            line-height: 1.25 !important;
        }
        .onboarding-card .doc-upload-subinfo {
            display: flex !important;
            align-items: center !important;
            gap: 5px !important;
            font-size: 10px !important;
            min-width: 0 !important;
            white-space: nowrap !important;
            overflow: hidden !important;
        }
        .onboarding-card .doc-upload-actions {
            display: flex !important;
            align-items: center !important;
            gap: 4px !important;
            flex-shrink: 0 !important;
        }
        .onboarding-card .doc-action-btn.view {
            padding: 3px 7px !important;
            font-size: 10.5px !important;
        }
        .onboarding-card .doc-action-btn.remove {
            padding: 3px 6px !important;
            font-size: 13px !important;
        }

        /* Review Details (Step 3) */
        .onboarding-card .review-card {
            padding: 8px 12px !important;
            border-radius: 10px !important;
        }
        .onboarding-card .review-row {
            font-size: 11.5px !important;
            padding: 2px 0 !important;
        }
        .onboarding-card .review-trust-banner {
            padding: 6px 10px !important;
            font-size: 10.5px !important;
            margin-top: 6px !important;
        }

        /* Navigation Buttons */
        .onboarding-card .onboarding-nav-row {
            margin-top: 10px !important;
            gap: 8px !important;
        }
        .onboarding-card .split-submit-btn {
            padding: 10px 18px !important;
            font-size: 13.5px !important;
            font-weight: 700 !important;
            border-radius: 10px !important;
            height: 40px !important;
        }
        .onboarding-card .split-back-btn {
            padding: 9px 14px !important;
            font-size: 12.5px !important;
            border-radius: 10px !important;
            height: 40px !important;
        }

        /* Footer Finish Later */
        .onboarding-card .split-card-footer {
            margin-top: 8px !important;
            font-size: 12px !important;
        }
    </style>
    <div class="split-login-viewport">
        <div class="onboarding-wizard-frame">
            <!-- Top Brand Header -->
            <div class="onboarding-brand-header">
                <a href="index.php" class="onboarding-brand-link">
                    <div class="onboarding-brand-icon">
                        <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: 38px; height: 38px;">
                            <path d="M6 8 L32 8 L29 14 L15 14 L13 18 L26 18 L23 24 L10 24 L5 34 L1 34 L6 8 Z" fill="#84cc16" />
                            <polygon points="12,5 36,5 34,9 10,9" fill="#a3e635" opacity="0.8" />
                            <polygon points="2,32 10,32 8,36 0,36" fill="#65a30d" />
                        </svg>
                    </div>
                    <div class="onboarding-brand-text">
                        <span class="onboarding-brand-name">FIT<span>TRACK</span></span>
                        <span class="onboarding-brand-tagline">FACILITY VERIFICATION &amp; SETUP</span>
                    </div>
                </a>
            </div>

            <!-- Elevated Centered Wizard Card -->
            <div class="split-login-card onboarding-card">
                <div class="split-card-header">
                    <h2 class="split-card-title">Complete Setup</h2>
                    <p class="split-card-subtitle">Facility details &amp; verification for <span class="brand-highlight">FitTrack</span></p>
                </div>

                <!-- 3-Step Progress Stepper -->
                <div class="onboarding-stepper" id="onboarding-stepper">
                    <button type="button" class="stepper-item active" id="stepper-tab-1" onclick="goToStep(1)">
                        <span class="stepper-label">1. Facility Info</span>
                        <span class="stepper-bar"></span>
                    </button>
                    <button type="button" class="stepper-item" id="stepper-tab-2" onclick="goToStep(2)">
                        <span class="stepper-label">2. Verification Documents</span>
                        <span class="stepper-bar"></span>
                    </button>
                    <button type="button" class="stepper-item" id="stepper-tab-3" onclick="goToStep(3)">
                        <span class="stepper-label">3. Review &amp; Submit</span>
                        <span class="stepper-bar"></span>
                    </button>
                </div>

                    <form method="post" enctype="multipart/form-data" class="split-card-form" novalidate onsubmit="const btn = this.querySelector('button[type=submit]'); if (btn) { btn.disabled = true; btn.innerHTML = '<svg class=\'fitness-loader mini\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\' style=\'margin-right:8px;\'><line x1=\'6\' y1=\'12\' x2=\'18\' y2=\'12\'></line><rect x=\'4\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'18\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'2\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect><rect x=\'20\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect></svg> SUBMITTING...'; }">
                        <?= csrf_field() ?>

                        <!-- STEP 1: Facility Information -->
                        <div class="split-step-pane" id="onboarding-pane-1">
                            <div class="onboarding-section-title" style="margin-top: 0; margin-bottom: 12px;">Facility Information</div>

                            <!-- Row 1: Gym / Facility Name & Mobile Number -->
                            <div class="split-form-row-2col">
                                <div class="split-form-group">
                                    <label>Gym / Facility Name<span class="req-star">*</span></label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M3 21h18M3 7v14M21 7v14M9 21V11M15 21V11M9 7l3-4 3 4"></path>
                                        </svg>
                                        <input type="text" name="gym_name" id="gym_name" required placeholder="e.g. Velocity Fitness"
                                               value="<?= h(post('gym_name')) ?>"
                                               oninvalid="this.setCustomValidity('Please enter your gym name.')"
                                               oninput="this.setCustomValidity('')">
                                    </div>
                                </div>

                                <div class="split-form-group">
                                    <label>Mobile Number<span class="req-star">*</span></label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path>
                                        </svg>
                                        <input type="tel" inputmode="numeric" name="gym_contact_info" id="gym_contact_info" required
                                               pattern="[0-9]{11}" maxlength="11" placeholder="09123456789"
                                               value="<?= h(post('gym_contact_info')) ?>"
                                               title="Please enter an 11-digit mobile number (e.g. 09123456789)"
                                               onkeypress="return event.charCode >= 48 && event.charCode <= 57"
                                               oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11); this.setCustomValidity('');"
                                               oninvalid="this.setCustomValidity('Please enter an 11-digit mobile number.')">
                                    </div>
                                </div>
                            </div>

                            <!-- Row 2: Street Address & Barangay -->
                            <div class="split-form-row-2col">
                                <div class="split-form-group">
                                    <label>Street Address<span class="req-star">*</span></label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                            <polyline points="9 22 9 12 15 12 15 22"></polyline>
                                        </svg>
                                        <input type="text" name="street" id="street" required placeholder="Building No., Street / Road"
                                               value="<?= h(post('street')) ?>"
                                               oninvalid="this.setCustomValidity('Please enter the street address.')"
                                               oninput="this.setCustomValidity('')">
                                    </div>
                                </div>

                                <div class="split-form-group">
                                    <label>Barangay<span class="req-star">*</span></label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M12 22s-8-4.5-8-11.8A8 8 0 0 1 12 2a8 8 0 0 1 8 8.2c0 7.3-8 11.8-8 11.8z"></path>
                                            <circle cx="12" cy="10" r="3"></circle>
                                        </svg>
                                        <input type="text" name="barangay" id="barangay" required placeholder="e.g. Brgy. Poblacion"
                                               value="<?= h(post('barangay')) ?>"
                                               oninvalid="this.setCustomValidity('Please enter the barangay.')"
                                               oninput="this.setCustomValidity('')">
                                    </div>
                                </div>
                            </div>

                            <!-- Row 3: City & Province -->
                            <div class="split-form-row-2col">
                                <div class="split-form-group">
                                    <label>City / Municipality<span class="req-star">*</span></label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="4" y="2" width="16" height="20" rx="2" ry="2"></rect>
                                            <line x1="9" y1="22" x2="9" y2="22.01"></line>
                                            <line x1="15" y1="22" x2="15" y2="22.01"></line>
                                            <line x1="9" y1="18" x2="9" y2="18.01"></line>
                                            <line x1="15" y1="18" x2="15" y2="18.01"></line>
                                            <line x1="9" y1="14" x2="9" y2="14.01"></line>
                                            <line x1="15" y1="14" x2="15" y2="14.01"></line>
                                            <line x1="9" y1="10" x2="9" y2="10.01"></line>
                                            <line x1="15" y1="10" x2="15" y2="10.01"></line>
                                            <line x1="9" y1="6" x2="9" y2="6.01"></line>
                                            <line x1="15" y1="6" x2="15" y2="6.01"></line>
                                        </svg>
                                        <input type="text" name="city" id="city" required placeholder="e.g. Tangub City"
                                               value="<?= h(post('city')) ?>"
                                               oninvalid="this.setCustomValidity('Please enter the city or municipality.')"
                                               oninput="this.setCustomValidity('')">
                                    </div>
                                </div>

                                <div class="split-form-group">
                                    <label>Province<span class="req-star">*</span></label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="10"></circle>
                                            <line x1="2" y1="12" x2="22" y2="12"></line>
                                            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                                        </svg>
                                        <input type="text" name="province" id="province" required placeholder="e.g. Misamis Occidental"
                                               value="<?= h(post('province')) ?>"
                                               oninvalid="this.setCustomValidity('Please enter the province.')"
                                               oninput="this.setCustomValidity('')">
                                    </div>
                                </div>
                            </div>

                            <!-- Row 4: Zip Code -->
                            <div class="split-form-row-2col">
                                <div class="split-form-group">
                                    <label>ZIP / Postal Code<span class="req-star">*</span></label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <line x1="4" y1="9" x2="20" y2="9"></line>
                                            <line x1="4" y1="15" x2="20" y2="15"></line>
                                            <line x1="10" y1="3" x2="8" y2="21"></line>
                                            <line x1="16" y1="3" x2="14" y2="21"></line>
                                        </svg>
                                        <input type="tel" inputmode="numeric" name="zipcode" id="zipcode" required
                                               pattern="[0-9]{4}" maxlength="4" placeholder="e.g. 7214"
                                               value="<?= h(post('zipcode')) ?>"
                                               title="Please enter a 4-digit ZIP code (e.g. 7214)"
                                               onkeypress="return event.charCode >= 48 && event.charCode <= 57"
                                               oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 4); this.setCustomValidity('');"
                                               oninvalid="this.setCustomValidity('Please enter a 4-digit ZIP code.')">
                                    </div>
                                </div>
                            </div>

                            <!-- Step 1 Navigation Row -->
                            <div class="onboarding-nav-row">
                                <button type="button" class="split-submit-btn" onclick="goToStep(2)">
                                    <span>Continue to Documents</span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <line x1="5" y1="12" x2="19" y2="12"></line>
                                        <polyline points="12 5 19 12 12 19"></polyline>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- STEP 2: Verification Documents -->
                        <div class="split-step-pane step-hidden" id="onboarding-pane-2">
                            <div class="onboarding-section-title">Verification Documents</div>

                            <!-- Row 1: Valid ID Type & Valid ID Upload -->
                            <div class="split-form-row-2col">
                                <div class="split-form-group">
                                    <label>Valid ID Type<span class="req-star">*</span></label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                                            <circle cx="9" cy="10" r="2"></circle>
                                            <line x1="15" y1="8" x2="17" y2="8"></line>
                                            <line x1="15" y1="12" x2="17" y2="12"></line>
                                        </svg>
                                        <select name="valid_id_type" id="valid_id_type" required onchange="handleIdTypeChange(this.value)"
                                                oninvalid="this.setCustomValidity('Please select your valid ID type.')"
                                                oninput="this.setCustomValidity('')">
                                            <option value="">-- Choose ID Type --</option>
                                            <option value="Philippine National ID (PhilSys ID)" <?= post('valid_id_type') === 'Philippine National ID (PhilSys ID)' ? 'selected' : '' ?>>Philippine National ID (PhilSys ID)</option>
                                            <option value="Driver's License" <?= post('valid_id_type') === "Driver's License" ? 'selected' : '' ?>>Driver's License</option>
                                            <option value="Passport" <?= post('valid_id_type') === 'Passport' ? 'selected' : '' ?>>Passport</option>
                                            <option value="UMID (Unified Multi-Purpose ID)" <?= post('valid_id_type') === 'UMID (Unified Multi-Purpose ID)' ? 'selected' : '' ?>>UMID</option>
                                            <option value="TIN ID" <?= post('valid_id_type') === 'TIN ID' ? 'selected' : '' ?>>TIN ID</option>
                                            <option value="Postal ID" <?= post('valid_id_type') === 'Postal ID' ? 'selected' : '' ?>>Postal ID</option>
                                            <option value="SSS ID" <?= post('valid_id_type') === 'SSS ID' ? 'selected' : '' ?>>SSS ID</option>
                                            <option value="GSIS ID" <?= post('valid_id_type') === 'GSIS ID' ? 'selected' : '' ?>>GSIS ID</option>
                                            <option value="PRC ID" <?= post('valid_id_type') === 'PRC ID' ? 'selected' : '' ?>>PRC ID</option>
                                            <option value="Other Valid Government ID" <?= post('valid_id_type') === 'Other Valid Government ID' ? 'selected' : '' ?>>Other Valid Government ID</option>
                                        </select>
                                    </div>
                                    <div id="other-id-wrap" style="<?= post('valid_id_type') === 'Other Valid Government ID' ? '' : 'display: none;' ?> margin-top: 4px;">
                                        <input type="text" name="valid_id_type_other" id="valid_id_type_other"
                                               placeholder="Specify ID (e.g. Voter's ID)"
                                               value="<?= h(post('valid_id_type_other')) ?>"
                                               style="width: 100%; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 7px 10px; font-size: 12.5px; color: #0f172a; box-sizing: border-box;">
                                    </div>
                                </div>

                                <!-- Valid ID Upload Box -->
                                <div class="split-form-group">
                                    <label>Upload Valid ID<span class="req-star">*</span></label>
                                    <div class="split-file-upload-box" id="box-valid-id">
                                        <input type="file" name="valid_id" id="input-valid-id" required accept=".pdf,.jpg,.jpeg,.png,.webp"
                                               onchange="handleDocFileChosen(this, 'id', 'Valid Government ID')"
                                               oninvalid="this.setCustomValidity('Please upload your valid government ID.')"
                                               oninput="this.setCustomValidity('')">
                                        
                                        <!-- Empty State -->
                                        <div class="doc-upload-empty" id="empty-id">
                                            <svg class="split-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                                                <circle cx="9" cy="10" r="2"></circle>
                                                <line x1="15" y1="8" x2="17" y2="8"></line>
                                                <line x1="15" y1="12" x2="17" y2="12"></line>
                                                <line x1="7" y1="16" x2="17" y2="16"></line>
                                            </svg>
                                            <div class="doc-upload-text">
                                                <span class="split-upload-title" id="title-valid-id">Upload Valid ID</span>
                                                <span class="split-upload-desc">JPG, PNG or PDF (Max 5MB)</span>
                                            </div>
                                        </div>

                                        <!-- Filled / Inline Preview State -->
                                        <div class="doc-upload-filled" id="filled-id" style="display: none;">
                                            <div class="doc-preview-thumb-wrap" id="thumb-id" onclick="openDocPreview('input-valid-id', event)" title="Click to expand preview"></div>
                                            <div class="doc-upload-meta">
                                                <div class="doc-upload-name" id="filename-id">file.jpg</div>
                                                <div class="doc-upload-subinfo">
                                                    <span class="doc-upload-size" id="size-id">0 KB</span>
                                                    <span class="doc-upload-badge">✓ Attached</span>
                                                </div>
                                            </div>
                                            <div class="doc-upload-actions">
                                                <button type="button" class="doc-action-btn view" onclick="openDocPreview('input-valid-id', event)" title="Preview Full Document">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                    <span>View</span>
                                                </button>
                                                <button type="button" class="doc-action-btn remove" onclick="clearDocFile('input-valid-id', 'id', event)" title="Remove file">&times;</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Row 2: Business Permit & Barangay Clearance -->
                            <div class="split-form-row-2col">
                                <!-- Business Permit Upload Box -->
                                <div class="split-form-group">
                                    <label>Business Permit<span class="req-star">*</span></label>
                                    <div class="split-file-upload-box" id="box-permit">
                                        <input type="file" name="business_permit" id="input-business-permit" required accept=".pdf,.jpg,.jpeg,.png,.webp"
                                               onchange="handleDocFileChosen(this, 'permit', 'Business Permit')"
                                               oninvalid="this.setCustomValidity('Please upload your business permit.')"
                                               oninput="this.setCustomValidity('')">
                                        
                                        <!-- Empty State -->
                                        <div class="doc-upload-empty" id="empty-permit">
                                            <svg class="split-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                                <polyline points="14 2 14 8 20 8"></polyline>
                                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                                <line x1="16" y1="17" x2="8" y2="17"></line>
                                            </svg>
                                            <div class="doc-upload-text">
                                                <span class="split-upload-title">Business Permit</span>
                                                <span class="split-upload-desc">DTI, SEC or Mayor's Permit</span>
                                            </div>
                                        </div>

                                        <!-- Filled / Inline Preview State -->
                                        <div class="doc-upload-filled" id="filled-permit" style="display: none;">
                                            <div class="doc-preview-thumb-wrap" id="thumb-permit" onclick="openDocPreview('input-business-permit', event)" title="Click to expand preview"></div>
                                            <div class="doc-upload-meta">
                                                <div class="doc-upload-name" id="filename-permit">file.jpg</div>
                                                <div class="doc-upload-subinfo">
                                                    <span class="doc-upload-size" id="size-permit">0 KB</span>
                                                    <span class="doc-upload-badge">✓ Attached</span>
                                                </div>
                                            </div>
                                            <div class="doc-upload-actions">
                                                <button type="button" class="doc-action-btn view" onclick="openDocPreview('input-business-permit', event)" title="Preview Full Document">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                    <span>View</span>
                                                </button>
                                                <button type="button" class="doc-action-btn remove" onclick="clearDocFile('input-business-permit', 'permit', event)" title="Remove file">&times;</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Barangay Clearance Upload Box -->
                                <div class="split-form-group">
                                    <label>Barangay Clearance<span class="req-star">*</span></label>
                                    <div class="split-file-upload-box" id="box-barangay">
                                        <input type="file" name="barangay_clearance" id="input-barangay-clearance" required accept=".pdf,.jpg,.jpeg,.png,.webp"
                                               onchange="handleDocFileChosen(this, 'barangay', 'Barangay Clearance')"
                                               oninvalid="this.setCustomValidity('Please upload your Barangay Clearance.')"
                                               oninput="this.setCustomValidity('')">
                                        
                                        <!-- Empty State -->
                                        <div class="doc-upload-empty" id="empty-barangay">
                                            <svg class="split-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                                                <polyline points="9 12 11 14 15 10"></polyline>
                                            </svg>
                                            <div class="doc-upload-text">
                                                <span class="split-upload-title">Barangay Clearance</span>
                                                <span class="split-upload-desc">Current year clearance</span>
                                            </div>
                                        </div>

                                        <!-- Filled / Inline Preview State -->
                                        <div class="doc-upload-filled" id="filled-barangay" style="display: none;">
                                            <div class="doc-preview-thumb-wrap" id="thumb-barangay" onclick="openDocPreview('input-barangay-clearance', event)" title="Click to expand preview"></div>
                                            <div class="doc-upload-meta">
                                                <div class="doc-upload-name" id="filename-barangay">file.jpg</div>
                                                <div class="doc-upload-subinfo">
                                                    <span class="doc-upload-size" id="size-barangay">0 KB</span>
                                                    <span class="doc-upload-badge">✓ Attached</span>
                                                </div>
                                            </div>
                                            <div class="doc-upload-actions">
                                                <button type="button" class="doc-action-btn view" onclick="openDocPreview('input-barangay-clearance', event)" title="Preview Full Document">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                    <span>View</span>
                                                </button>
                                                <button type="button" class="doc-action-btn remove" onclick="clearDocFile('input-barangay-clearance', 'barangay', event)" title="Remove file">&times;</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Row 3: Fire Safety Cert. & Facebook Page Link -->
                            <div class="split-form-row-2col">
                                <!-- Fire Safety Inspection Certificate Upload Box -->
                                <div class="split-form-group">
                                    <label>Fire Safety Cert. <span class="opt-label">(Optional)</span></label>
                                    <div class="split-file-upload-box" id="box-fire-safety">
                                        <input type="file" name="fire_safety_cert" id="input-fire-safety" accept=".pdf,.jpg,.jpeg,.png,.webp"
                                               onchange="handleDocFileChosen(this, 'fire-safety', 'Fire Safety Inspection Certificate')">
                                        
                                        <!-- Empty State -->
                                        <div class="doc-upload-empty" id="empty-fire-safety">
                                            <svg class="split-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"></path>
                                            </svg>
                                            <div class="doc-upload-text">
                                                <span class="split-upload-title">Fire Safety Cert.</span>
                                                <span class="split-upload-desc">FSIC document (Optional)</span>
                                            </div>
                                        </div>

                                        <!-- Filled / Inline Preview State -->
                                        <div class="doc-upload-filled" id="filled-fire-safety" style="display: none;">
                                            <div class="doc-preview-thumb-wrap" id="thumb-fire-safety" onclick="openDocPreview('input-fire-safety', event)" title="Click to expand preview"></div>
                                            <div class="doc-upload-meta">
                                                <div class="doc-upload-name" id="filename-fire-safety">file.jpg</div>
                                                <div class="doc-upload-subinfo">
                                                    <span class="doc-upload-size" id="size-fire-safety">0 KB</span>
                                                    <span class="doc-upload-badge">✓ Attached</span>
                                                </div>
                                            </div>
                                            <div class="doc-upload-actions">
                                                <button type="button" class="doc-action-btn view" onclick="openDocPreview('input-fire-safety', event)" title="Preview Full Document">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                    <span>View</span>
                                                </button>
                                                <button type="button" class="doc-action-btn remove" onclick="clearDocFile('input-fire-safety', 'fire-safety', event)" title="Remove file">&times;</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Facebook Page Link Input -->
                                <div class="split-form-group">
                                    <label>Facebook Page <span class="opt-label">(Optional)</span></label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"></path>
                                        </svg>
                                        <input type="url" name="fb_page_url" id="fb_page_url" placeholder="https://facebook.com/yourgym"
                                               value="<?= h(post('fb_page_url')) ?>">
                                    </div>
                                    <span style="font-size: 11px; color: #64748b; margin-top: 1px;">Official public page or group</span>
                                </div>
                            </div>

                            <!-- Step 2 Navigation Row -->
                            <div class="onboarding-nav-row">
                                <button type="button" class="split-back-btn" onclick="goToStep(1)">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <line x1="19" y1="12" x2="5" y2="12"></line>
                                        <polyline points="12 19 5 12 12 5"></polyline>
                                    </svg>
                                    <span>Back</span>
                                </button>
                                <button type="button" class="split-submit-btn" onclick="goToStep(3)">
                                    <span>Continue to Review</span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <line x1="5" y1="12" x2="19" y2="12"></line>
                                        <polyline points="12 5 19 12 12 19"></polyline>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- STEP 3: Review & Submit -->
                        <div class="split-step-pane step-hidden" id="onboarding-pane-3">
                            <div class="onboarding-section-title">Review Application Details</div>

                            <!-- Review 2-Column Summary Grid -->
                            <div class="onboarding-review-grid">
                                <!-- Facility Info Summary Box -->
                                <div class="review-card">
                                    <div class="review-card-header">
                                        <div class="review-card-title-wrap">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M3 7v14M21 7v14M9 21V11M15 21V11M9 7l3-4 3 4"></path></svg>
                                            <span>Facility Information</span>
                                        </div>
                                        <button type="button" class="review-edit-btn" onclick="goToStep(1)">Edit</button>
                                    </div>
                                    <div class="review-card-body">
                                        <div class="review-row">
                                            <span class="review-label">Gym Name:</span>
                                            <span class="review-value" id="rev-gym-name">—</span>
                                        </div>
                                        <div class="review-row">
                                            <span class="review-label">Mobile Number:</span>
                                            <span class="review-value" id="rev-gym-contact">—</span>
                                        </div>
                                        <div class="review-row">
                                            <span class="review-label">Address:</span>
                                            <span class="review-value" id="rev-gym-address">—</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Documents Summary Box -->
                                <div class="review-card">
                                    <div class="review-card-header">
                                        <div class="review-card-title-wrap">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                                            <span>Verification Documents</span>
                                        </div>
                                        <button type="button" class="review-edit-btn" onclick="goToStep(2)">Edit</button>
                                    </div>
                                    <div class="review-card-body">
                                        <div class="review-row align-top">
                                            <span class="review-label">Valid ID:</span>
                                            <div class="review-value-stack">
                                                <span class="review-id-title" id="rev-id-type">—</span>
                                                <span class="review-file-badge" id="rev-id-file">No file</span>
                                            </div>
                                        </div>
                                        <div class="review-row">
                                            <span class="review-label">Business Permit:</span>
                                            <span class="review-value">
                                                <span class="review-file-badge" id="rev-permit-file">No file</span>
                                            </span>
                                        </div>
                                        <div class="review-row">
                                            <span class="review-label">Barangay Clearance:</span>
                                            <span class="review-value">
                                                <span class="review-file-badge" id="rev-brgy-file">No file</span>
                                            </span>
                                        </div>
                                        <div class="review-row">
                                            <span class="review-label">Fire Safety Cert:</span>
                                            <span class="review-value">
                                                <span class="review-file-badge optional" id="rev-fire-file">None (Optional)</span>
                                            </span>
                                        </div>
                                        <div class="review-row">
                                            <span class="review-label">Facebook Page:</span>
                                            <span class="review-value">
                                                <span id="rev-fb-page">None (Optional)</span>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Trust Notice -->
                            <div class="review-trust-banner">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                                    <polyline points="9 12 11 14 15 10"></polyline>
                                </svg>
                                <span>All submissions are reviewed by platform admins within 24-48 hours. By submitting, you confirm the provided documents are authentic.</span>
                            </div>

                            <!-- Step 3 Navigation Row -->
                            <div class="onboarding-nav-row">
                                <button type="button" class="split-back-btn" onclick="goToStep(2)">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <line x1="19" y1="12" x2="5" y2="12"></line>
                                        <polyline points="12 19 5 12 12 5"></polyline>
                                    </svg>
                                    <span>Back</span>
                                </button>
                                <button type="submit" class="split-submit-btn">
                                    <span>Submit Application</span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <line x1="5" y1="12" x2="19" y2="12"></line>
                                        <polyline points="12 5 19 12 12 19"></polyline>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="split-card-footer">
                            <div>Need to finish later? <a href="index.php?page=logout" class="signup-link">Sign out</a></div>
                        </div>
                    </form>

                    <!-- Document Lightbox Preview Modal -->
                    <div id="docPreviewModal" class="doc-lightbox-modal" style="display: none;" onclick="closeDocPreviewModal(event)">
                        <div class="doc-lightbox-dialog" onclick="event.stopPropagation()">
                            <div class="doc-lightbox-header">
                                <div class="doc-lightbox-title-wrap">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                                        <circle cx="8.5" cy="8.5" r="1.5"/>
                                        <polyline points="21 15 16 10 5 21"/>
                                    </svg>
                                    <span id="docLightboxTitle">Document Preview</span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <a id="docLightboxDownload" href="#" target="_blank" class="doc-action-btn view" style="text-decoration: none; padding: 5px 9px;" title="Open in new tab">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                        <span>New Tab</span>
                                    </a>
                                    <button type="button" class="doc-lightbox-close-btn" onclick="closeDocPreviewModal()" title="Close (Esc)">&times;</button>
                                </div>
                            </div>
                            <div class="doc-lightbox-body" id="docLightboxBody">
                                <!-- Preview image or iframe dynamically inserted -->
                            </div>
                            <div class="doc-lightbox-footer">
                                <div class="doc-lightbox-meta" id="docLightboxMeta">—</div>
                                <button type="button" class="doc-lightbox-btn" onclick="closeDocPreviewModal()">Done</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <script>
    let currentStep = 1;

    function formatFileSize(bytes) {
        if (!bytes || bytes === 0) return '0 KB';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    function handleDocFileChosen(input, key, docTitle) {
        const file = input && input.files && input.files[0];
        const boxMap = {
            'id': 'box-valid-id',
            'permit': 'box-permit',
            'barangay': 'box-barangay',
            'fire-safety': 'box-fire-safety'
        };
        const box = document.getElementById(boxMap[key] || ('box-' + key));
        const emptyEl = document.getElementById('empty-' + key);
        const filledEl = document.getElementById('filled-' + key);
        const thumbEl = document.getElementById('thumb-' + key);
        const nameEl = document.getElementById('filename-' + key);
        const sizeEl = document.getElementById('size-' + key);

        if (!file) {
            if (emptyEl) emptyEl.style.display = 'flex';
            if (filledEl) filledEl.style.display = 'none';
            if (box) box.classList.remove('has-file');
            return;
        }

        if (nameEl) {
            nameEl.textContent = file.name;
            nameEl.title = file.name;
        }
        if (sizeEl) sizeEl.textContent = formatFileSize(file.size);

        const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
        if (thumbEl) {
            thumbEl.innerHTML = '';
            if (isPdf) {
                thumbEl.innerHTML = `
                    <div class="doc-thumb-pdf">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="16" y1="13" x2="8" y2="13"></line>
                            <line x1="16" y1="17" x2="8" y2="17"></line>
                            <polyline points="10 9 9 9 8 9"></polyline>
                        </svg>
                        <span>PDF</span>
                    </div>
                `;
            } else {
                const objectUrl = URL.createObjectURL(file);
                const img = document.createElement('img');
                img.src = objectUrl;
                img.className = 'doc-thumb-img';
                img.alt = file.name;
                thumbEl.appendChild(img);
            }
        }

        if (emptyEl) emptyEl.style.display = 'none';
        if (filledEl) filledEl.style.display = 'flex';
        if (box) box.classList.add('has-file');
    }

    function clearDocFile(inputId, key, event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        const input = document.getElementById(inputId);
        if (input) {
            input.value = '';
        }
        const boxMap = {
            'id': 'box-valid-id',
            'permit': 'box-permit',
            'barangay': 'box-barangay',
            'fire-safety': 'box-fire-safety'
        };
        const box = document.getElementById(boxMap[key] || ('box-' + key));
        const emptyEl = document.getElementById('empty-' + key);
        const filledEl = document.getElementById('filled-' + key);
        const thumbEl = document.getElementById('thumb-' + key);

        if (emptyEl) emptyEl.style.display = 'flex';
        if (filledEl) filledEl.style.display = 'none';
        if (thumbEl) thumbEl.innerHTML = '';
        if (box) box.classList.remove('has-file');
    }

    function openDocPreview(inputId, event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        const input = document.getElementById(inputId);
        if (!input || !input.files || !input.files[0]) {
            return;
        }
        const file = input.files[0];
        const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
        const modal = document.getElementById('docPreviewModal');
        const titleEl = document.getElementById('docLightboxTitle');
        const bodyEl = document.getElementById('docLightboxBody');
        const metaEl = document.getElementById('docLightboxMeta');
        const downloadLink = document.getElementById('docLightboxDownload');

        if (!modal || !bodyEl) return;

        const fileUrl = URL.createObjectURL(file);

        const titleMap = {
            'input-valid-id': 'Valid Government ID',
            'input-business-permit': 'Business Permit',
            'input-barangay-clearance': 'Barangay Clearance',
            'input-fire-safety': 'Fire Safety Inspection Certificate'
        };
        const titlePrefix = titleMap[inputId] || 'Document';
        if (titleEl) titleEl.textContent = `${titlePrefix} — ${file.name}`;
        if (metaEl) metaEl.textContent = `${file.name} (${formatFileSize(file.size)})`;
        if (downloadLink) {
            downloadLink.href = fileUrl;
            downloadLink.download = file.name;
        }

        bodyEl.innerHTML = '';
        if (isPdf) {
            const frame = document.createElement('iframe');
            frame.src = fileUrl + '#toolbar=0';
            frame.className = 'doc-lightbox-frame';
            frame.title = file.name;
            bodyEl.appendChild(frame);
        } else {
            const img = document.createElement('img');
            img.src = fileUrl;
            img.className = 'doc-lightbox-img';
            img.alt = file.name;
            bodyEl.appendChild(img);
        }

        modal.style.display = 'flex';
    }

    function closeDocPreviewModal(event) {
        if (event && event.target && event.target.closest('.doc-lightbox-dialog') && event.target.tagName !== 'BUTTON' && !event.target.classList.contains('doc-lightbox-close-btn')) {
            return;
        }
        const modal = document.getElementById('docPreviewModal');
        const bodyEl = document.getElementById('docLightboxBody');
        if (modal) modal.style.display = 'none';
        if (bodyEl) bodyEl.innerHTML = '';
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeDocPreviewModal();
        }
    });

    function handleIdTypeChange(val) {
        const otherWrap = document.getElementById('other-id-wrap');
        const titleValidId = document.getElementById('title-valid-id');
        const otherInput = document.getElementById('valid_id_type_other');
        
        if (val === 'Other Valid Government ID') {
            if (otherWrap) otherWrap.style.display = 'block';
            if (otherInput) otherInput.focus();
            if (titleValidId) titleValidId.textContent = 'Upload Valid ID';
        } else {
            if (otherWrap) otherWrap.style.display = 'none';
            if (titleValidId) {
                titleValidId.textContent = val ? 'Upload ' + (val.length > 22 ? val.substring(0, 20) + '...' : val) : 'Upload Valid ID';
            }
        }
    }

    function goToStep(targetStep) {
        if (targetStep === currentStep) return;

        // Moving forward to Step 2: Validate Step 1
        if (targetStep >= 2) {
            const gn = document.getElementById('gym_name');
            const gc = document.getElementById('gym_contact_info');
            const st = document.getElementById('street');
            const bg = document.getElementById('barangay');
            const ct = document.getElementById('city');
            const pr = document.getElementById('province');
            const zc = document.getElementById('zipcode');

            if (gn && !gn.value.trim()) {
                goToStepDirect(1);
                gn.focus();
                gn.reportValidity();
                return;
            }
            if (gc) {
                const phoneDigits = gc.value.replace(/[^0-9]/g, '');
                if (phoneDigits.length !== 11) {
                    goToStepDirect(1);
                    gc.focus();
                    gc.setCustomValidity('Please enter an 11-digit mobile number (e.g. 09123456789).');
                    gc.reportValidity();
                    return;
                } else {
                    gc.setCustomValidity('');
                }
            }
            if (st && !st.value.trim()) {
                goToStepDirect(1);
                st.focus();
                st.reportValidity();
                return;
            }
            if (bg && !bg.value.trim()) {
                goToStepDirect(1);
                bg.focus();
                bg.reportValidity();
                return;
            }
            if (ct && !ct.value.trim()) {
                goToStepDirect(1);
                ct.focus();
                ct.reportValidity();
                return;
            }
            if (pr && !pr.value.trim()) {
                goToStepDirect(1);
                pr.focus();
                pr.reportValidity();
                return;
            }
            if (zc) {
                const zcDigits = zc.value.replace(/[^0-9]/g, '');
                if (zcDigits.length !== 4) {
                    goToStepDirect(1);
                    zc.focus();
                    zc.setCustomValidity('Please enter a 4-digit ZIP code.');
                    zc.reportValidity();
                    return;
                } else {
                    zc.setCustomValidity('');
                }
            }
        }

        // Moving forward to Step 3: Validate Step 2
        if (targetStep >= 3) {
            const idType = document.getElementById('valid_id_type');
            const idFile = document.getElementById('input-valid-id');
            const permitFile = document.getElementById('input-business-permit');
            const brgyFile = document.getElementById('input-barangay-clearance');

            if (idType && !idType.value.trim()) {
                goToStepDirect(2);
                idType.focus();
                idType.reportValidity();
                return;
            }

            const idOther = document.getElementById('valid_id_type_other');
            if (idType.value === 'Other Valid Government ID' && idOther && !idOther.value.trim()) {
                goToStepDirect(2);
                idOther.focus();
                idOther.reportValidity();
                return;
            }

            if (idFile && (!idFile.files || !idFile.files.length)) {
                goToStepDirect(2);
                idFile.focus();
                idFile.reportValidity();
                return;
            }

            if (permitFile && (!permitFile.files || !permitFile.files.length)) {
                goToStepDirect(2);
                permitFile.focus();
                permitFile.reportValidity();
                return;
            }

            if (brgyFile && (!brgyFile.files || !brgyFile.files.length)) {
                goToStepDirect(2);
                brgyFile.focus();
                brgyFile.reportValidity();
                return;
            }

            // Populate Step 3 Review details
            populateReviewData();
        }

        goToStepDirect(targetStep);
    }

    function goToStepDirect(step) {
        const pane1 = document.getElementById('onboarding-pane-1');
        const pane2 = document.getElementById('onboarding-pane-2');
        const pane3 = document.getElementById('onboarding-pane-3');
        const panes = [pane1, pane2, pane3];

        panes.forEach((pane, idx) => {
            if (!pane) return;
            if (idx + 1 === step) {
                pane.classList.remove('step-hidden');
            } else {
                pane.classList.add('step-hidden');
            }
        });

        // Update stepper tabs
        for (let i = 1; i <= 3; i++) {
            const tab = document.getElementById('stepper-tab-' + i);
            if (!tab) continue;
            tab.classList.remove('active', 'completed');
            if (i === step) {
                tab.classList.add('active');
            } else if (i < step) {
                tab.classList.add('completed');
            }
        }

        currentStep = step;

        // Smoothly scroll card into view
        const card = document.querySelector('.onboarding-card');
        if (card) {
            card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function populateReviewData() {
        const gn = document.getElementById('gym_name')?.value || '—';
        const gc = document.getElementById('gym_contact_info')?.value || '—';
        const st = document.getElementById('street')?.value.trim() || '';
        const bg = document.getElementById('barangay')?.value.trim() || '';
        const ct = document.getElementById('city')?.value.trim() || '';
        const pr = document.getElementById('province')?.value.trim() || '';
        const zc = document.getElementById('zipcode')?.value.trim() || '';
        const fullAddress = [st, bg, ct, pr, zc].filter(Boolean).join(', ') || '—';

        const idTypeVal = document.getElementById('valid_id_type')?.value || '—';
        const idOtherVal = document.getElementById('valid_id_type_other')?.value || '';
        const fullIdType = (idTypeVal === 'Other Valid Government ID' && idOtherVal) ? ('Other: ' + idOtherVal) : idTypeVal;

        const idFile = document.getElementById('input-valid-id')?.files[0]?.name || 'Attached';
        const permitFile = document.getElementById('input-business-permit')?.files[0]?.name || 'Attached';
        const brgyFile = document.getElementById('input-barangay-clearance')?.files[0]?.name || 'Attached';
        const fireFile = document.getElementById('input-fire-safety')?.files[0]?.name || null;
        const fbUrl = document.getElementById('fb_page_url')?.value || null;

        const revGymName = document.getElementById('rev-gym-name');
        const revGymContact = document.getElementById('rev-gym-contact');
        const revGymAddress = document.getElementById('rev-gym-address');
        if (revGymName) revGymName.textContent = gn;
        if (revGymContact) revGymContact.textContent = gc;
        if (revGymAddress) revGymAddress.textContent = fullAddress;

        const revIdType = document.getElementById('rev-id-type');
        const revIdFile = document.getElementById('rev-id-file');
        const revPermitFile = document.getElementById('rev-permit-file');
        const revBrgyFile = document.getElementById('rev-brgy-file');
        if (revIdType) {
            revIdType.textContent = fullIdType;
            revIdType.title = fullIdType;
        }
        if (revIdFile) {
            revIdFile.textContent = '✓ ' + idFile;
            revIdFile.title = 'Click to preview ' + idFile;
            revIdFile.classList.add('clickable');
            revIdFile.onclick = (e) => openDocPreview('input-valid-id', e);
        }
        if (revPermitFile) {
            revPermitFile.textContent = '✓ ' + permitFile;
            revPermitFile.title = 'Click to preview ' + permitFile;
            revPermitFile.classList.add('clickable');
            revPermitFile.onclick = (e) => openDocPreview('input-business-permit', e);
        }
        if (revBrgyFile) {
            revBrgyFile.textContent = '✓ ' + brgyFile;
            revBrgyFile.title = 'Click to preview ' + brgyFile;
            revBrgyFile.classList.add('clickable');
            revBrgyFile.onclick = (e) => openDocPreview('input-barangay-clearance', e);
        }

        const revFire = document.getElementById('rev-fire-file');
        if (revFire) {
            if (fireFile) {
                revFire.textContent = '✓ ' + fireFile;
                revFire.title = 'Click to preview ' + fireFile;
                revFire.classList.remove('optional');
                revFire.classList.add('clickable');
                revFire.onclick = (e) => openDocPreview('input-fire-safety', e);
            } else {
                revFire.textContent = 'None (Optional)';
                revFire.title = '';
                revFire.classList.add('optional');
                revFire.classList.remove('clickable');
                revFire.onclick = null;
            }
        }

        const revFb = document.getElementById('rev-fb-page');
        if (revFb) {
            if (fbUrl) {
                revFb.textContent = fbUrl;
                revFb.title = fbUrl;
                revFb.style.color = '#3b82f6';
            } else {
                revFb.textContent = 'None (Optional)';
                revFb.title = '';
                revFb.style.color = '#64748b';
            }
        }
    }

    function handleFileChosen(input, labelId, boxId) {
        const label = document.getElementById(labelId);
        const box   = document.getElementById(boxId);
        if (!input.files || !input.files[0]) {
            if (label) label.style.display = 'none';
            if (box) box.classList.remove('has-file');
            return;
        }
        const file = input.files[0];
        if (label) {
            label.textContent = '✓ ' + file.name;
            label.style.display = 'block';
        }
        if (box) {
            box.classList.add('has-file');
        }
    }


    </script>
    <?php
    render_footer();
}

