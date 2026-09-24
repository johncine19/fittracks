<?php
declare(strict_types=1);

function gym_onboarding_page(): void
{
    if (!defined('AUTH_PAGE')) define('AUTH_PAGE', true);
    
    $user = current_user();
    if (!$user || $user['role'] !== 'gym_owner') {
        redirect('login');
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
        $gymAddress = trim((string) post('gym_address'));
        $gymContact = preg_replace('/[^0-9]/', '', (string) post('gym_contact_info'));
        $validIdType = trim((string) post('valid_id_type'));
        $validIdTypeOther = trim((string) post('valid_id_type_other'));
        $fbPageUrl = trim((string) post('fb_page_url'));

        if (!$gymName || !$gymAddress || !$gymContact) {
            flash('Facility name, mobile number, and complete address are required.', 'danger');
        } elseif (strlen($gymContact) !== 11) {
            flash('Mobile number must be exactly 11 digits (e.g. 09123456789).', 'danger');
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
        .onboarding-card .split-form-group label {
            display: inline-flex !important;
            align-items: baseline !important;
            flex-direction: row !important;
            gap: 1px !important;
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
                            <div class="step1-layout-grid">
                                <!-- Left Column: Form Fields -->
                                <div class="step1-form-col">
                                    <div class="onboarding-section-title">Facility Information</div>

                                    <div class="split-form-group">
                                        <label>Gym / Facility Name<span class="req-star" style="color: #ef4444; font-weight: 700; margin-left: 1px;">*</span></label>
                                        <div class="split-input-wrap auth-input-group">
                                            <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M3 21h18M3 7v14M21 7v14M9 21V11M15 21V11M9 7l3-4 3 4"></path>
                                            </svg>
                                            <input type="text" name="gym_name" id="gym_name" required placeholder="e.g. Iron Forge Gym"
                                                   value="<?= h(post('gym_name')) ?>"
                                                   oninvalid="this.setCustomValidity('Please enter your gym name.')"
                                                   oninput="this.setCustomValidity(''); updateFacilityLivePreview();">
                                        </div>
                                    </div>

                                    <div class="split-form-group">
                                        <label>Mobile Number<span class="req-star" style="color: #ef4444; font-weight: 700; margin-left: 1px;">*</span></label>
                                        <div class="split-input-wrap auth-input-group">
                                            <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path>
                                            </svg>
                                            <input type="tel" inputmode="numeric" name="gym_contact_info" id="gym_contact_info" required
                                                   pattern="[0-9]{11}" maxlength="11" placeholder="09123456789"
                                                   value="<?= h(post('gym_contact_info')) ?>"
                                                   title="Please enter an 11-digit mobile number (e.g. 09123456789)"
                                                   onkeypress="return event.charCode >= 48 && event.charCode <= 57"
                                                   oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11); this.setCustomValidity(''); updateFacilityLivePreview();"
                                                   oninvalid="this.setCustomValidity('Please enter an 11-digit mobile number.')">
                                        </div>
                                    </div>

                                    <div class="split-form-group">
                                        <label>Complete Facility Address<span class="req-star" style="color: #ef4444; font-weight: 700; margin-left: 1px;">*</span></label>
                                        <div class="split-input-wrap auth-input-group">
                                            <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                                <circle cx="12" cy="10" r="3"></circle>
                                            </svg>
                                            <input type="text" name="gym_address" id="gym_address" required placeholder="Unit, Street, Barangay, City, Province"
                                                   value="<?= h(post('gym_address')) ?>"
                                                   oninvalid="this.setCustomValidity('Please enter the full address of your gym.')"
                                                   oninput="this.setCustomValidity(''); updateFacilityLivePreview();">
                                        </div>
                                    </div>

                                    <!-- Step 1 Continue Button -->
                                    <button type="button" class="split-submit-btn" style="margin-top: 6px;" onclick="goToStep(2)">
                                        <span>Continue to Documents</span>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <line x1="5" y1="12" x2="19" y2="12"></line>
                                            <polyline points="12 5 19 12 12 19"></polyline>
                                        </svg>
                                    </button>
                                </div>

                                <!-- Right Column: Live Facility Preview Card -->
                                <div class="step1-preview-col">
                                    <div class="onboarding-section-title">Facility Profile Preview</div>
                                    
                                    <div class="facility-preview-card">
                                        <div class="facility-preview-header">
                                            <div class="facility-preview-badge">
                                                <span class="pulse-indicator"></span>
                                                <span>Live Preview</span>
                                            </div>
                                            <span class="facility-preview-status">Under Setup</span>
                                        </div>

                                        <div class="facility-preview-body">
                                            <div class="facility-preview-icon-wrap">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M3 21h18M3 7v14M21 7v14M9 21V11M15 21V11M9 7l3-4 3 4"></path>
                                                </svg>
                                            </div>
                                            <div class="facility-preview-meta">
                                                <h4 class="facility-preview-title" id="prev-gym-name">Iron Forge Gym</h4>
                                                <div class="facility-preview-item">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                                    <span id="prev-gym-contact">09123456789</span>
                                                </div>
                                                <div class="facility-preview-item">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                                    <span id="prev-gym-address">City / Province Address</span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="facility-preview-perks">
                                            <div class="perk-title">What happens after verification:</div>
                                            <div class="perk-item">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="#65a30d" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Listed on member search &amp; map directory</span>
                                            </div>
                                            <div class="perk-item">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="#65a30d" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Automated QR check-ins &amp; access control</span>
                                            </div>
                                            <div class="perk-item">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="#65a30d" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Direct membership subscriptions &amp; billing</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- STEP 2: Verification Documents -->
                        <div class="split-step-pane step-hidden" id="onboarding-pane-2">
                            <!-- 1. Government Identification -->
                            <div class="onboarding-section-title">Government Identification</div>
                            <div class="split-form-row-2col">
                                <div class="split-form-group">
                                    <label>Valid ID Type<span class="req-star" style="color: #ef4444; font-weight: 700; margin-left: 1px;">*</span></label>
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
                                    <label>Upload Valid ID<span class="req-star" style="color: #ef4444; font-weight: 700; margin-left: 1px;">*</span></label>
                                    <div class="split-file-upload-box" id="box-valid-id">
                                        <input type="file" name="valid_id" id="input-valid-id" required accept=".pdf,.jpg,.jpeg,.png"
                                               onchange="handleFileChosen(this, 'filename-id', 'box-valid-id')"
                                               oninvalid="this.setCustomValidity('Please upload your valid government ID.')"
                                               oninput="this.setCustomValidity('')">
                                        <svg class="split-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                                            <circle cx="9" cy="10" r="2"></circle>
                                            <line x1="15" y1="8" x2="17" y2="8"></line>
                                            <line x1="15" y1="12" x2="17" y2="12"></line>
                                            <line x1="7" y1="16" x2="17" y2="16"></line>
                                        </svg>
                                        <span class="split-upload-title" id="title-valid-id">Upload Valid ID</span>
                                        <span class="split-upload-desc">Image or PDF (Max 5MB)</span>
                                        <span class="split-upload-filename" id="filename-id"></span>
                                    </div>
                                </div>
                            </div>

                            <!-- 2. Required Business Documents -->
                            <div class="onboarding-section-title">Required Business Documents</div>
                            <div class="split-form-row-2col">
                                <!-- Business Permit Upload Box -->
                                <div class="split-form-group">
                                    <label>Business Permit<span class="req-star" style="color: #ef4444; font-weight: 700; margin-left: 1px;">*</span></label>
                                    <div class="split-file-upload-box" id="box-business-permit">
                                        <input type="file" name="business_permit" id="input-business-permit" required accept=".pdf,.jpg,.jpeg,.png"
                                               onchange="handleFileChosen(this, 'filename-permit', 'box-business-permit')"
                                               oninvalid="this.setCustomValidity('Please upload your business permit.')"
                                               oninput="this.setCustomValidity('')">
                                        <svg class="split-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                            <polyline points="14 2 14 8 20 8"></polyline>
                                            <line x1="16" y1="13" x2="8" y2="13"></line>
                                            <line x1="16" y1="17" x2="8" y2="17"></line>
                                            <polyline points="10 9 9 9 8 9"></polyline>
                                        </svg>
                                        <span class="split-upload-title">Business Permit</span>
                                        <span class="split-upload-desc">DTI, SEC or Mayor's Permit</span>
                                        <span class="split-upload-filename" id="filename-permit"></span>
                                    </div>
                                </div>

                                <!-- Barangay Clearance Upload Box -->
                                <div class="split-form-group">
                                    <label>Barangay Clearance<span class="req-star" style="color: #ef4444; font-weight: 700; margin-left: 1px;">*</span></label>
                                    <div class="split-file-upload-box" id="box-barangay-clearance">
                                        <input type="file" name="barangay_clearance" id="input-barangay-clearance" required accept=".pdf,.jpg,.jpeg,.png"
                                               onchange="handleFileChosen(this, 'filename-barangay', 'box-barangay-clearance')"
                                               oninvalid="this.setCustomValidity('Please upload your Barangay Clearance.')"
                                               oninput="this.setCustomValidity('')">
                                        <svg class="split-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                                            <polyline points="9 12 11 14 15 10"></polyline>
                                        </svg>
                                        <span class="split-upload-title">Barangay Clearance</span>
                                        <span class="split-upload-desc">Current year clearance</span>
                                        <span class="split-upload-filename" id="filename-barangay"></span>
                                    </div>
                                </div>
                            </div>

                            <!-- 3. Optional Verification & Social -->
                            <div class="onboarding-section-title">Optional Verification & Social</div>
                            <div class="split-form-row-2col">
                                <!-- Fire Safety Inspection Certificate Upload Box -->
                                <div class="split-form-group">
                                    <label>Fire Safety Cert. <span class="opt-label" style="font-weight: 400; color: #94a3b8; font-size: 11.5px; margin-left: 4px;">(Optional)</span></label>
                                    <div class="split-file-upload-box" id="box-fire-safety">
                                        <input type="file" name="fire_safety_cert" id="input-fire-safety" accept=".pdf,.jpg,.jpeg,.png"
                                               onchange="handleFileChosen(this, 'filename-fire-safety', 'box-fire-safety')">
                                        <svg class="split-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"></path>
                                        </svg>
                                        <span class="split-upload-title">Fire Safety Cert.</span>
                                        <span class="split-upload-desc">FSIC document (Optional)</span>
                                        <span class="split-upload-filename" id="filename-fire-safety"></span>
                                    </div>
                                </div>

                                <!-- Facebook Page Link Input -->
                                <div class="split-form-group">
                                    <label>Facebook Page <span class="opt-label" style="font-weight: 400; color: #94a3b8; font-size: 11.5px; margin-left: 4px;">(Optional)</span></label>
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
                </div>
            </div>
        </div>

    <script>
    let currentStep = 1;

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
            const ga = document.getElementById('gym_address');

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
            if (ga && !ga.value.trim()) {
                goToStepDirect(1);
                ga.focus();
                ga.reportValidity();
                return;
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
        const ga = document.getElementById('gym_address')?.value || '—';

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
        if (revGymAddress) revGymAddress.textContent = ga;

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
            revIdFile.title = idFile;
        }
        if (revPermitFile) {
            revPermitFile.textContent = '✓ ' + permitFile;
            revPermitFile.title = permitFile;
        }
        if (revBrgyFile) {
            revBrgyFile.textContent = '✓ ' + brgyFile;
            revBrgyFile.title = brgyFile;
        }

        const revFire = document.getElementById('rev-fire-file');
        if (revFire) {
            if (fireFile) {
                revFire.textContent = '✓ ' + fireFile;
                revFire.title = fireFile;
                revFire.classList.remove('optional');
            } else {
                revFire.textContent = 'None (Optional)';
                revFire.title = '';
                revFire.classList.add('optional');
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

    function updateFacilityLivePreview() {
        const gn = document.getElementById('gym_name')?.value.trim();
        const gc = document.getElementById('gym_contact_info')?.value.trim();
        const ga = document.getElementById('gym_address')?.value.trim();

        const prevName = document.getElementById('prev-gym-name');
        const prevContact = document.getElementById('prev-gym-contact');
        const prevAddress = document.getElementById('prev-gym-address');

        if (prevName) prevName.textContent = gn ? gn : 'Iron Forge Gym';
        if (prevContact) prevContact.textContent = gc ? gc : '09123456789';
        if (prevAddress) prevAddress.textContent = ga ? ga : 'City / Province Address';
    }

    document.addEventListener('DOMContentLoaded', updateFacilityLivePreview);
    </script>
    <?php
    render_footer();
}

