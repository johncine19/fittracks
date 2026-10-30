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
        $secondaryValidIdType = trim((string) post('secondary_valid_id_type'));
        $secondaryValidIdTypeOther = trim((string) post('secondary_valid_id_type_other'));
        $fbPageUrl = trim((string) post('fb_page_url'));
        $payoutChannel = trim((string) post('payout_channel')) ?: 'gcash';
        $payoutAccountNumber = preg_replace('/[^0-9]/', '', (string) post('payout_account_number'));
        $payoutAccountName = trim((string) post('payout_account_name'));

        $addressParts = array_filter([$street, $barangay, $city, $province, $zipcode], fn($val) => $val !== '');
        $gymAddress = implode(', ', $addressParts);

        $isSecondary = is_secondary_id($validIdType);

        if (!$gymName || !$street || !$barangay || !$city || !$province || !$zipcode) {
            flash('Facility name and all address fields (street, barangay, city, province, and zipcode) are required.', 'danger');
        } elseif ($gymContact !== '' && strlen($gymContact) !== 11) {
            flash('Gym contact number must be exactly 11 digits (e.g. 09123456789) if provided.', 'danger');
        } elseif (strlen($zipcode) !== 4) {
            flash('ZIP code must be exactly 4 digits (e.g. 7214).', 'danger');
        } elseif (empty($validIdType)) {
            flash('Please select your Valid Government ID type.', 'danger');
        } elseif ($isSecondary && empty($secondaryValidIdType)) {
            flash('Under FitTrack policy, selecting a Secondary ID requires one additional supporting government ID.', 'danger');
        } elseif ($isSecondary && strcasecmp($validIdType, $secondaryValidIdType) === 0) {
            flash('Your supporting ID must be a different ID type from your first selection.', 'danger');
        } else {
            if ($validIdType === 'Other Valid Government ID' && !empty($validIdTypeOther)) {
                $validIdType = 'Other: ' . $validIdTypeOther;
            }
            if ($secondaryValidIdType === 'Other Valid Government ID' && !empty($secondaryValidIdTypeOther)) {
                $secondaryValidIdType = 'Other: ' . $secondaryValidIdTypeOther;
            }

            if (!empty($fbPageUrl)) {
                if (!preg_match('~^(?:f|ht)tps?://~i', $fbPageUrl)) {
                    $fbPageUrl = 'https://' . $fbPageUrl;
                }
                if (!filter_var($fbPageUrl, FILTER_VALIDATE_URL)) {
                    flash('Please enter a valid Facebook Page URL (e.g. https://facebook.com/yourgym) or leave it empty.', 'danger');
                    $fbPageUrl = null;
                } else {
                    // Block non-http(s) protocols like javascript:, data:, vbscript:
                    $parsedScheme = strtolower(parse_url($fbPageUrl, PHP_URL_SCHEME) ?? '');
                    if (!in_array($parsedScheme, ['http', 'https'], true)) {
                        flash('Only http:// and https:// URLs are allowed for the Facebook Page URL.', 'danger');
                        $fbPageUrl = null;
                    }
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

                // 3. Required: Main Valid ID
                $validIdFilename = null;
                if (isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] === UPLOAD_ERR_OK) {
                    $validIdFilename = FileUpload::storeValidId($_FILES['valid_id'], (int)$user['user_id']);
                } else {
                    throw new Exception('Valid Government ID upload is required.');
                }

                // 3b. Required if Secondary: Supporting Government ID
                $secondaryValidIdFilename = null;
                if ($isSecondary) {
                    if (isset($_FILES['secondary_valid_id']) && $_FILES['secondary_valid_id']['error'] === UPLOAD_ERR_OK) {
                        $secondaryValidIdFilename = FileUpload::storeValidId($_FILES['secondary_valid_id'], (int)$user['user_id']);
                    } else {
                        throw new Exception('Supporting Government ID upload is required when using a Secondary ID.');
                    }
                }

                // 4. Optional: Fire Safety Inspection Certificate
                $fireSafetyFilename = null;
                if (isset($_FILES['fire_safety_cert']) && $_FILES['fire_safety_cert']['error'] === UPLOAD_ERR_OK) {
                    $fireSafetyFilename = FileUpload::storeFireSafetyCert($_FILES['fire_safety_cert'], (int)$user['user_id']);
                }

                // Check available columns in gyms table to support both schemas gracefully
                static $gymColumns = null;
                if ($gymColumns === null) {
                    try {
                        $gymColumns = $pdo->query('SHOW COLUMNS FROM gyms')->fetchAll(PDO::FETCH_COLUMN);
                    } catch (Throwable) {
                        $gymColumns = [];
                    }
                }

                $fields = ['owner_user_id', 'name', 'address', 'contact_info', 'fb_page_url', 'status'];
                $placeholders = ['?', '?', '?', '?', '?', '"pending"'];
                $params = [
                    $user['user_id'],
                    $gymName,
                    $gymAddress,
                    $gymContact !== '' ? $gymContact : null,
                    $fbPageUrl
                ];

                // Legacy column compatibility if present in table
                if (in_array('business_permit_url', $gymColumns, true)) {
                    $fields[] = 'business_permit_url';
                    $placeholders[] = '?';
                    $params[] = $permitFilename;
                }
                if (in_array('valid_id_url', $gymColumns, true)) {
                    $fields[] = 'valid_id_url';
                    $placeholders[] = '?';
                    $params[] = $validIdFilename;
                }
                if (in_array('valid_id_type', $gymColumns, true)) {
                    $fields[] = 'valid_id_type';
                    $placeholders[] = '?';
                    $params[] = $validIdType;
                }
                if (in_array('barangay_clearance_url', $gymColumns, true)) {
                    $fields[] = 'barangay_clearance_url';
                    $placeholders[] = '?';
                    $params[] = $brgyFilename;
                }
                if (in_array('fire_safety_cert_url', $gymColumns, true)) {
                    $fields[] = 'fire_safety_cert_url';
                    $placeholders[] = '?';
                    $params[] = $fireSafetyFilename;
                }

                if (in_array('secondary_valid_id_url', $gymColumns, true)) {
                    $fields[] = 'secondary_valid_id_url';
                    $placeholders[] = '?';
                    $params[] = $secondaryValidIdFilename;
                }
                if (in_array('secondary_valid_id_type', $gymColumns, true)) {
                    $fields[] = 'secondary_valid_id_type';
                    $placeholders[] = '?';
                    $params[] = $isSecondary ? $secondaryValidIdType : null;
                }

                // Legacy column compatibility if present in table
                if (in_array('permit_url', $gymColumns, true)) {
                    $fields[] = 'permit_url';
                    $placeholders[] = '?';
                    $params[] = $permitFilename;
                }
                if (in_array('id_url', $gymColumns, true)) {
                    $fields[] = 'id_url';
                    $placeholders[] = '?';
                    $params[] = $validIdFilename;
                }
                if (in_array('payout_channel', $gymColumns, true)) {
                    $fields[] = 'payout_channel';
                    $placeholders[] = '?';
                    $params[] = $payoutChannel;
                }
                if (in_array('payout_account_number', $gymColumns, true)) {
                    $fields[] = 'payout_account_number';
                    $placeholders[] = '?';
                    $params[] = $payoutAccountNumber ?: null;
                }
                if (in_array('payout_account_name', $gymColumns, true)) {
                    $fields[] = 'payout_account_name';
                    $placeholders[] = '?';
                    $params[] = $payoutAccountName ?: null;
                }

                $insertSql = 'INSERT INTO gyms (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
                $stmt = $pdo->prepare($insertSql);
                $stmt->execute($params);
                $newGymId = (int) $pdo->lastInsertId();

                if (function_exists('save_gym_verification_documents')) {
                    save_gym_verification_documents($newGymId, [
                        'business_permit_url'    => $permitFilename,
                        'valid_id_url'           => $validIdFilename,
                        'valid_id_type'          => $validIdType,
                        'secondary_valid_id_url' => $secondaryValidIdFilename,
                        'secondary_valid_id_type' => $isSecondary ? $secondaryValidIdType : null,
                        'barangay_clearance_url' => $brgyFilename,
                        'fire_safety_cert_url'   => $fireSafetyFilename,
                    ]);
                }
                if (function_exists('save_gym_payout_account')) {
                    save_gym_payout_account($newGymId, [
                        'payout_channel'        => $payoutChannel,
                        'payout_account_number' => $payoutAccountNumber ?: null,
                        'payout_account_name'   => $payoutAccountName ?: null,
                    ]);
                }
                if (function_exists('save_gym_settings')) {
                    save_gym_settings($newGymId, []);
                }

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

    $idTypes = get_verification_id_types(true);
    $primaryIdTypes = array_values(array_filter($idTypes, fn($i) => ($i['category'] ?? '') === 'primary'));
    $secondaryIdTypes = array_values(array_filter($idTypes, fn($i) => ($i['category'] ?? '') === 'secondary'));

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
            overflow: visible !important;
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
        .stepper-label-short {
            display: none !important;
        }
        .stepper-label-full {
            display: inline !important;
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
            position: relative !important;
        }
        .onboarding-card .split-input-wrap input,
        .onboarding-card .split-input-wrap select {
            height: 40px !important;
            padding: 7px 12px 7px 38px !important;
            font-size: 13px !important;
            border-radius: 9px !important;
            width: 100% !important;
            box-sizing: border-box !important;
            border: 1px solid #111111 !important;
            background: #ffffff !important;
            color: #0f172a !important;
            transition: border-color 0.15s ease, box-shadow 0.15s ease !important;
        }
        .onboarding-card .split-input-wrap input:focus,
        .onboarding-card .split-input-wrap select:focus {
            border-color: #84cc16 !important;
            outline: none !important;
            box-shadow: 0 0 0 2px rgba(132, 204, 22, 0.2) !important;
        }
        .onboarding-card .split-input-wrap input::placeholder {
            color: #94a3b8 !important;
        }
        .onboarding-card .split-input-wrap select {
            padding-right: 28px !important;
            text-overflow: ellipsis !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            max-width: 100% !important;
            width: 100% !important;
        }
        .onboarding-card .split-input-wrap select optgroup {
            font-size: 12px !important;
            font-weight: 700 !important;
            color: #475569 !important;
            padding: 4px 0 !important;
        }
        .onboarding-card .split-input-wrap select option {
            font-size: 13px !important;
            color: #0f172a !important;
            padding: 4px 6px !important;
        }
        .onboarding-card .split-input-icon {
            position: absolute !important;
            left: 12px !important;
            top: 20px !important;
            transform: translateY(-50%) !important;
            width: 16px !important;
            height: 16px !important;
            color: #64748b !important;
            pointer-events: none !important;
            z-index: 10 !important;
        }

        /* FitDropdown Custom Styles for Onboarding Card */
        .onboarding-card .fit-dropdown-wrap {
            --ink: #0f172a !important;
            --muted: #64748b !important;
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            position: relative !important;
        }
        .onboarding-card .fit-dropdown-trigger {
            width: 100% !important;
            height: 40px !important;
            padding: 7px 12px 7px 38px !important;
            font-size: 13px !important;
            border-radius: 9px !important;
            background: #ffffff !important;
            border: 1px solid #111111 !important;
            color: #0f172a !important;
            box-sizing: border-box !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            transition: border-color 0.15s ease, box-shadow 0.15s ease !important;
        }
        .onboarding-card .fit-dropdown-trigger:hover,
        .onboarding-card .fit-dropdown-trigger:focus,
        .onboarding-card .fit-dropdown-trigger.open {
            border-color: #84cc16 !important;
            box-shadow: 0 0 0 2px rgba(132, 204, 22, 0.2) !important;
            outline: none !important;
        }
        .onboarding-card .fit-dropdown-trigger.error {
            border-color: #ef4444 !important;
            box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.25) !important;
        }
        .onboarding-card .fit-dropdown-trigger-content {
            font-size: 13px !important;
            color: #0f172a !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            white-space: nowrap !important;
            flex: 1 !important;
            text-align: left !important;
        }
        .onboarding-card .fit-dropdown-trigger-content span {
            color: #0f172a !important;
            font-size: 13px !important;
            font-weight: 500 !important;
        }
        .onboarding-card .fit-dropdown-placeholder {
            color: #64748b !important;
            font-size: 13px !important;
        }
        .onboarding-card .fit-dropdown-wrap svg,
        .onboarding-card .fit-dropdown-trigger svg,
        .onboarding-card .fit-dropdown-chevron {
            position: static !important;
            left: auto !important;
            right: auto !important;
            top: auto !important;
            transform: none !important;
            width: 16px !important;
            height: 16px !important;
            color: #64748b !important;
            pointer-events: none !important;
            flex-shrink: 0 !important;
            display: block !important;
        }
        .onboarding-card .fit-dropdown-trigger.open .fit-dropdown-chevron {
            transform: rotate(180deg) !important;
        }
        .onboarding-card .fit-dropdown-menu {
            position: absolute !important;
            top: calc(100% + 4px) !important;
            left: 0 !important;
            right: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
            min-width: 100% !important;
            box-sizing: border-box !important;
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 10px !important;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.15), 0 4px 10px rgba(0, 0, 0, 0.08) !important;
            z-index: 99999 !important;
            overflow: hidden !important;
        }
        .onboarding-card .fit-dropdown-list {
            max-height: 200px !important;
            overflow-y: auto !important;
            padding: 4px !important;
            background: #ffffff !important;
            scrollbar-width: thin !important;
            scrollbar-color: #cbd5e1 #f8fafc !important;
        }
        .onboarding-card .fit-dropdown-list::-webkit-scrollbar {
            width: 6px !important;
        }
        .onboarding-card .fit-dropdown-list::-webkit-scrollbar-track {
            background: #f8fafc !important;
            border-radius: 4px !important;
        }
        .onboarding-card .fit-dropdown-list::-webkit-scrollbar-thumb {
            background: #cbd5e1 !important;
            border-radius: 4px !important;
        }
        .onboarding-card .fit-dropdown-list::-webkit-scrollbar-thumb:hover {
            background: #94a3b8 !important;
        }
        .onboarding-card .fit-dropdown-header {
            padding: 7px 10px 4px 10px !important;
            font-size: 10.5px !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.05em !important;
            color: #475569 !important;
            background: #f1f5f9 !important;
            border-radius: 4px !important;
            margin: 4px 0 2px 0 !important;
            border-top: none !important;
        }
        .onboarding-card .fit-dropdown-header:first-child {
            margin-top: 0 !important;
        }
        .onboarding-card .fit-dropdown-item,
        .onboarding-card .fit-dropdown-item span,
        .onboarding-card .fit-dropdown-item div {
            color: #0f172a !important;
        }
        .onboarding-card .fit-dropdown-item {
            padding: 8px 10px !important;
            border-radius: 6px !important;
            font-size: 12.5px !important;
            margin-bottom: 2px !important;
            transition: background 0.15s ease !important;
            cursor: pointer !important;
        }
        .onboarding-card .fit-dropdown-item:hover,
        .onboarding-card .fit-dropdown-item.active {
            background: #f1f5f9 !important;
        }
        .onboarding-card .fit-dropdown-item:hover span,
        .onboarding-card .fit-dropdown-item.active span {
            color: #0f172a !important;
        }
        .onboarding-card .fit-dropdown-item.selected {
            background: rgba(132, 204, 22, 0.14) !important;
        }
        .onboarding-card .fit-dropdown-item.selected span {
            color: #15803d !important;
            font-weight: 700 !important;
        }

        /* Upload Boxes (Step 2) - Strict Ellipsis & Overflow Protection */
        .onboarding-card .split-file-upload-box {
            min-height: 40px !important;
            padding: 5px 8px !important;
            border-radius: 9px !important;
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            overflow: hidden !important;
            box-sizing: border-box !important;
            border: 1.5px dashed #334155 !important;
            background: #f8fafc !important;
            transition: all 0.15s ease !important;
        }
        .onboarding-card .split-file-upload-box:hover {
            border-color: #84cc16 !important;
            background: rgba(132, 204, 22, 0.04) !important;
        }
        .onboarding-card .split-file-upload-box.has-file {
            border-style: solid !important;
            border-color: #111111 !important;
            background: #ffffff !important;
        }
        .onboarding-card .split-file-upload-box.box-locked {
            opacity: 0.65 !important;
            background: #f1f5f9 !important;
            border-style: dashed !important;
            border-color: #94a3b8 !important;
            cursor: not-allowed !important;
        }
        .onboarding-card .split-file-upload-box.box-locked:hover {
            border-color: #ef4444 !important;
            background: rgba(239, 68, 68, 0.04) !important;
        }
        .onboarding-card .split-file-upload-box.box-locked input[type="file"] {
            cursor: not-allowed !important;
        }
        .shake-highlight {
            animation: shakeHighlight 0.45s ease-in-out !important;
            border-color: #ef4444 !important;
            box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.25) !important;
        }
        @keyframes shakeHighlight {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
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
            height: 22px !important;
            padding: 2px 7px !important;
            font-size: 10.5px !important;
            font-weight: 700 !important;
            background: linear-gradient(135deg, #84cc16 0%, #65a30d 100%) !important;
            color: #ffffff !important;
            border: none !important;
            border-radius: 5px !important;
            box-shadow: 0 2px 5px rgba(101, 163, 13, 0.28) !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 4px !important;
            line-height: 1 !important;
            box-sizing: border-box !important;
            transition: all 0.15s ease !important;
            cursor: pointer !important;
        }
        .onboarding-card .doc-action-btn.view:hover {
            filter: brightness(1.08) !important;
            box-shadow: 0 3px 8px rgba(101, 163, 13, 0.4) !important;
            transform: translateY(-1px) !important;
        }
        .onboarding-card .doc-action-btn.view:active {
            transform: translateY(0) !important;
        }
        .onboarding-card .doc-action-btn.view svg {
            width: 11px !important;
            height: 11px !important;
            color: #ffffff !important;
            stroke: currentColor !important;
        }
        .onboarding-card .doc-action-btn.remove {
            height: 22px !important;
            width: 22px !important;
            padding: 0 !important;
            font-size: 13px !important;
            font-weight: 700 !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 5px !important;
            background: #ffffff !important;
            color: #64748b !important;
            line-height: 1 !important;
            box-sizing: border-box !important;
            transition: all 0.15s ease !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            cursor: pointer !important;
        }
        .onboarding-card .doc-action-btn.remove:hover {
            border-color: #ef4444 !important;
            background: #fef2f2 !important;
            color: #ef4444 !important;
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
        .onboarding-card .review-row.align-top {
            align-items: flex-start !important;
        }
        .onboarding-card #rev-payout-dest {
            word-break: normal !important;
            overflow-wrap: break-word !important;
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
            border: 1px solid #111111 !important;
            background: #ffffff !important;
            color: #0f172a !important;
        }

        /* Footer Finish Later */
        .onboarding-card .split-card-footer {
            margin-top: 8px !important;
            font-size: 12px !important;
        }

        /* Payout Destination Box (Step 2) */
        .onboarding-card .onboarding-payout-box {
            margin-top: 10px !important;
            padding: 10px 12px !important;
            border-radius: 12px !important;
            background: #f8fafc !important;
            border: 1px solid #111111 !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 8px !important;
            box-sizing: border-box !important;
            width: 100% !important;
        }
        .onboarding-card .onboarding-payout-header {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            gap: 6px !important;
            flex-wrap: wrap !important;
        }
        .onboarding-card .onboarding-payout-title {
            display: inline-flex !important;
            align-items: center !important;
            gap: 5px !important;
            font-size: 12px !important;
            font-weight: 700 !important;
            color: #0f172a !important;
        }
        .onboarding-card .onboarding-payout-title svg {
            color: #84cc16 !important;
            flex-shrink: 0 !important;
        }
        .onboarding-card .onboarding-payout-badge {
            font-size: 9.5px !important;
            font-weight: 600 !important;
            color: #4d7c0f !important;
            background: rgba(132, 204, 22, 0.15) !important;
            border: 1px solid rgba(132, 204, 22, 0.3) !important;
            padding: 1px 6px !important;
            border-radius: 4px !important;
            white-space: nowrap !important;
        }
        .onboarding-card .onboarding-payout-desc {
            font-size: 11px !important;
            color: #64748b !important;
            line-height: 1.35 !important;
            margin: 0 !important;
        }
        .onboarding-card .onboarding-payout-grid {
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) !important;
            gap: 10px !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }
        .onboarding-card .onboarding-payout-box .split-form-group label {
            white-space: normal !important;
        }
        .onboarding-card .payout-field-hint {
            font-size: 10px !important;
            color: #94a3b8 !important;
            margin-top: 1px !important;
            line-height: 1.25 !important;
            display: block !important;
        }

        /* Real-time Policy Badge & Supporting ID Container */
        .onboarding-card .id-policy-badge {
            margin: 2px 0 12px 0 !important;
            padding: 9px 13px !important;
            border-radius: 9px !important;
            font-size: 12px !important;
            line-height: 1.45 !important;
            display: flex !important;
            align-items: center !important;
            gap: 9px !important;
            width: 100% !important;
            box-sizing: border-box !important;
            transition: all 0.2s ease !important;
        }
        .onboarding-card .id-policy-badge.is-primary {
            color: #15803d !important;
            background: #f0fdf4 !important;
            border: 1px solid #bbf7d0 !important;
        }
        .onboarding-card .id-policy-badge.is-secondary {
            color: #c2410c !important;
            background: #fff7ed !important;
            border: 1px solid #fed7aa !important;
        }
        .onboarding-card .id-policy-badge svg {
            flex-shrink: 0 !important;
            width: 16px !important;
            height: 16px !important;
        }

        .onboarding-card .supporting-id-container {
            margin-bottom: 14px !important;
            padding: 13px 15px !important;
            background: #f8fafc !important;
            border: 1.5px dashed #cbd5e1 !important;
            border-radius: 12px !important;
            box-sizing: border-box !important;
            width: 100% !important;
        }
        .onboarding-card .supporting-id-banner-header {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            gap: 8px !important;
            margin-bottom: 5px !important;
            flex-wrap: wrap !important;
        }
        .onboarding-card .supporting-id-banner-title {
            font-weight: 700 !important;
            font-size: 13px !important;
            color: #0f172a !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
        }
        .onboarding-card .supporting-id-banner-pill {
            font-size: 11px !important;
            font-weight: 600 !important;
            color: #c2410c !important;
            background: #ffedd5 !important;
            border: 1px solid #fed7aa !important;
            padding: 2px 8px !important;
            border-radius: 99px !important;
            white-space: nowrap !important;
        }
        .onboarding-card .supporting-id-banner-desc {
            font-size: 11.5px !important;
            color: #475569 !important;
            margin: 0 0 10px 0 !important;
            line-height: 1.4 !important;
        }

        @media (max-width: 640px) {
            body:has(.onboarding-wizard-frame) .split-login-viewport,
            .split-login-viewport:has(.onboarding-wizard-frame),
            .split-login-viewport {
                padding: 12px 10px !important;
            }
            .split-login-card.onboarding-card {
                padding: 18px 16px 16px !important;
                border-radius: 14px !important;
            }
            .stepper-label-short {
                display: inline !important;
            }
            .stepper-label-full {
                display: none !important;
            }
            .onboarding-stepper {
                gap: 6px !important;
                margin-bottom: 12px !important;
            }
            .onboarding-stepper .stepper-item {
                gap: 3px !important;
            }
            .onboarding-stepper .stepper-label {
                font-size: 11px !important;
                white-space: nowrap !important;
            }

            /* Stack all 2-column form rows into a single column on mobile */
            .onboarding-card .split-form-row-2col {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
                margin-bottom: 10px !important;
            }

            /* Better touch targets & display for mobile file uploads */
            .onboarding-card .split-file-upload-box {
                min-height: 46px !important;
                padding: 8px 12px !important;
            }
            .onboarding-card .doc-upload-empty {
                gap: 10px !important;
            }
            .onboarding-card .doc-upload-empty .split-upload-icon {
                width: 20px !important;
                height: 20px !important;
            }
            .onboarding-card .doc-upload-text .split-upload-title {
                font-size: 12px !important;
            }
            .onboarding-card .doc-upload-text .split-upload-desc {
                font-size: 10.5px !important;
            }

            /* Mobile supporting ID container adjustments */
            .onboarding-card .supporting-id-container {
                padding: 12px 13px !important;
                margin-bottom: 12px !important;
            }
            .onboarding-card .supporting-id-banner-header {
                gap: 6px !important;
            }
            .onboarding-card .supporting-id-banner-title {
                font-size: 12.5px !important;
            }
            .onboarding-card .supporting-id-banner-pill {
                font-size: 10.5px !important;
                padding: 2px 7px !important;
            }
            .onboarding-card .supporting-id-banner-desc {
                font-size: 11px !important;
                margin-bottom: 9px !important;
            }

            /* Policy badge mobile spacing */
            .onboarding-card .id-policy-badge {
                padding: 8px 11px !important;
                font-size: 11.5px !important;
                margin: 0 0 10px 0 !important;
            }

            .onboarding-card .review-row {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 2px !important;
                padding: 5px 0 !important;
                border-bottom: 1px dashed #e2e8f0 !important;
            }
            .onboarding-card .review-row:last-child {
                border-bottom: none !important;
            }
            .onboarding-card .review-row .review-label {
                font-size: 11px !important;
                color: #64748b !important;
                white-space: normal !important;
            }
            .onboarding-card .review-row .review-value,
            .onboarding-card .review-row .review-value-stack {
                width: 100% !important;
                text-align: left !important;
                justify-content: flex-start !important;
                align-items: flex-start !important;
                font-size: 12px !important;
                word-break: normal !important;
                overflow-wrap: break-word !important;
            }
            .onboarding-card #rev-payout-dest {
                word-break: normal !important;
                overflow-wrap: break-word !important;
                line-height: 1.35 !important;
            }
            .onboarding-card .onboarding-payout-grid {
                grid-template-columns: 1fr !important;
                gap: 8px !important;
            }
            .onboarding-card .onboarding-payout-box {
                padding: 10px !important;
            }
        }
    </style>
    <div class="split-login-viewport">
        <div class="onboarding-wizard-frame">
            <!-- Top Brand Header -->
            <div class="onboarding-brand-header">
                <a href="index.php" class="onboarding-brand-link">
                    <div class="onboarding-brand-icon">
                        <img src="assets/images/favicon.png?v=2" alt="FitTrack" style="width: 38px; height: 38px; object-fit: contain; border-radius: 9px; display: block;">
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
                        <span class="stepper-label">
                            <span class="stepper-label-full">1. Facility Info</span>
                            <span class="stepper-label-short">1. Facility</span>
                        </span>
                        <span class="stepper-bar"></span>
                    </button>
                    <button type="button" class="stepper-item" id="stepper-tab-2" onclick="goToStep(2)">
                        <span class="stepper-label">
                            <span class="stepper-label-full">2. Verification Documents</span>
                            <span class="stepper-label-short">2. Documents</span>
                        </span>
                        <span class="stepper-bar"></span>
                    </button>
                    <button type="button" class="stepper-item" id="stepper-tab-3" onclick="goToStep(3)">
                        <span class="stepper-label">
                            <span class="stepper-label-full">3. Review &amp; Submit</span>
                            <span class="stepper-label-short">3. Review</span>
                        </span>
                        <span class="stepper-bar"></span>
                    </button>
                </div>

                    <form method="post" enctype="multipart/form-data" class="split-card-form" id="gymOnboardingForm" novalidate onsubmit="const btn = this.querySelector('button[type=submit]'); if (btn) { btn.disabled = true; btn.innerHTML = '<svg class=\'fitness-loader mini\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\' style=\'margin-right:8px;\'><line x1=\'6\' y1=\'12\' x2=\'18\' y2=\'12\'></line><rect x=\'4\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'18\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'2\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect><rect x=\'20\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect></svg> SUBMITTING...'; }">
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
                                    <label>Gym Contact Number <span class="opt-label">(Optional)</span></label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path>
                                        </svg>
                                        <input type="tel" inputmode="numeric" name="gym_contact_info" id="gym_contact_info"
                                               pattern="[0-9]{11}" maxlength="11" placeholder="09123456789"
                                               value="<?= h(post('gym_contact_info')) ?>"
                                               title="Please enter an 11-digit mobile number (e.g. 09123456789) if provided"
                                               onkeypress="return event.charCode >= 48 && event.charCode <= 57"
                                               oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11); this.setCustomValidity('');">
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
                                    <div class="split-input-wrap">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                                            <circle cx="9" cy="10" r="2"></circle>
                                            <line x1="15" y1="8" x2="17" y2="8"></line>
                                            <line x1="15" y1="12" x2="17" y2="12"></line>
                                        </svg>
                                        <div id="validIdFitDropdownContainer"></div>
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
                                    <label id="label-valid-id">Upload Valid ID<span class="req-star">*</span></label>
                                    <div class="split-file-upload-box <?= empty(post('valid_id_type')) ? 'box-locked' : '' ?>" id="box-valid-id" onclick="handleValidIdBoxClick(event)" title="<?= empty(post('valid_id_type')) ? 'Please choose a Valid ID Type first' : 'Click to choose file' ?>">
                                        <input type="file" name="valid_id" id="input-valid-id" required accept=".pdf,.jpg,.jpeg,.png,.webp"
                                               onclick="return handleValidIdClick(event)"
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
                                                <span class="split-upload-desc" id="desc-valid-id"><?= empty(post('valid_id_type')) ? 'Select ID type first' : 'JPG, PNG or PDF (Max 5MB)' ?></span>
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

                            <!-- Real-time ID Policy Status Banner (Full-Width) -->
                            <div id="id-policy-badge" class="id-policy-badge" style="display: none;"></div>

                            <!-- Conditional Supporting ID Container (Shown when Secondary ID selected) -->
                            <div id="supporting-id-container" class="supporting-id-container" style="display: none;">
                                <div class="supporting-id-banner-header">
                                    <div class="supporting-id-banner-title">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ea580c" stroke-width="2.2" style="flex-shrink: 0;"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                        <span>Supporting Government ID <span class="req-star">*</span></span>
                                    </div>
                                    <span class="supporting-id-banner-pill">Required (+1 ID)</span>
                                </div>
                                <p class="supporting-id-banner-desc">
                                    Select and upload an additional accepted government ID (Primary or Secondary) distinct from the one selected above.
                                </p>

                                <div class="split-form-row-2col">
                                    <div class="split-form-group">
                                        <label>Supporting ID Type<span class="req-star">*</span></label>
                                        <div class="split-input-wrap">
                                            <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><line x1="15" y1="8" x2="17" y2="8"/><line x1="15" y1="12" x2="17" y2="12"/></svg>
                                            <div id="secondaryValidIdFitDropdownContainer"></div>
                                        </div>
                                        <div id="secondary-other-id-wrap" style="<?= post('secondary_valid_id_type') === 'Other Valid Government ID' ? '' : 'display: none;' ?> margin-top: 4px;">
                                            <input type="text" name="secondary_valid_id_type_other" id="secondary_valid_id_type_other"
                                                   placeholder="Specify ID (e.g. PhilHealth ID, Postal ID)"
                                                   value="<?= h(post('secondary_valid_id_type_other')) ?>"
                                                   style="width: 100%; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 7px 10px; font-size: 12.5px; color: #0f172a; box-sizing: border-box;">
                                        </div>
                                    </div>

                                    <div class="split-form-group">
                                        <label>Upload Supporting ID<span class="req-star">*</span></label>
                                        <div class="split-file-upload-box box-locked" id="box-secondary-id" onclick="handleSecondaryIdBoxClick(event)" title="Please choose a Supporting ID Type first">
                                            <input type="file" name="secondary_valid_id" id="input-secondary-id" accept=".pdf,.jpg,.jpeg,.png,.webp"
                                                   onclick="return handleSecondaryIdClick(event)"
                                                   onchange="handleDocFileChosen(this, 'sec_id', 'Supporting Government ID')"
                                                   oninvalid="this.setCustomValidity('Please upload your supporting government ID.')"
                                                   oninput="this.setCustomValidity('')">
                                            
                                            <!-- Empty State -->
                                            <div class="doc-upload-empty" id="empty-sec_id">
                                                <svg class="split-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                                                    <circle cx="9" cy="10" r="2"></circle>
                                                    <line x1="15" y1="8" x2="17" y2="8"></line>
                                                    <line x1="15" y1="12" x2="17" y2="12"></line>
                                                    <line x1="7" y1="16" x2="17" y2="16"></line>
                                                </svg>
                                                <div class="doc-upload-text">
                                                    <span class="split-upload-title" id="title-sec_id">Upload Supporting ID</span>
                                                    <span class="split-upload-desc" id="desc-sec_id">Select ID type first</span>
                                                </div>
                                            </div>

                                            <!-- Filled / Inline Preview State -->
                                            <div class="doc-upload-filled" id="filled-sec_id" style="display: none;">
                                                <div class="doc-preview-thumb-wrap" id="thumb-sec_id" onclick="openDocPreview('input-secondary-id', event)" title="Click to expand preview"></div>
                                                <div class="doc-upload-meta">
                                                    <div class="doc-upload-name" id="filename-sec_id">file.jpg</div>
                                                    <div class="doc-upload-subinfo">
                                                        <span class="doc-upload-size" id="size-sec_id">0 KB</span>
                                                        <span class="doc-upload-badge">✓ Attached</span>
                                                    </div>
                                                </div>
                                                <div class="doc-upload-actions">
                                                    <button type="button" class="doc-action-btn view" onclick="openDocPreview('input-secondary-id', event)" title="Preview Full Document">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                        <span>View</span>
                                                    </button>
                                                    <button type="button" class="doc-action-btn remove" onclick="clearDocFile('input-secondary-id', 'sec_id', event)" title="Remove file">&times;</button>
                                                </div>
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

                            <!-- Online Settlement & Payout Information Box -->
                            <div class="onboarding-payout-box">
                                <div class="onboarding-payout-header">
                                    <div class="onboarding-payout-title">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                                        <span>Payout Destination</span>
                                        <span class="opt-label">(Optional)</span>
                                    </div>
                                    <span class="onboarding-payout-badge">For Member Payments</span>
                                </div>
                                <p class="onboarding-payout-desc">
                                    Where member payments (GCash, Maya, cards) will be deposited.
                                </p>

                                <div class="onboarding-payout-grid">
                                    <div class="split-form-group">
                                        <label>Payout Method</label>
                                        <div class="split-input-wrap auth-input-group">
                                            <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                                            <select name="payout_channel" id="payout_channel">
                                                <option value="gcash" <?= post('payout_channel') === 'gcash' || !post('payout_channel') ? 'selected' : '' ?>>GCash</option>
                                                <option value="maya" <?= post('payout_channel') === 'maya' ? 'selected' : '' ?>>Maya</option>
                                                <option value="bank_transfer" <?= post('payout_channel') === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="split-form-group">
                                        <label>Account / GCash No.</label>
                                        <div class="split-input-wrap auth-input-group">
                                            <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                            <input type="tel" name="payout_account_number" id="payout_account_number"
                                                   placeholder="e.g. 09171234567"
                                                   value="<?= h(post('payout_account_number')) ?>">
                                        </div>
                                        <span class="payout-field-hint">11-digit GCash/Maya number</span>
                                    </div>
                                </div>

                                <div class="split-form-group" style="margin-top: 1px;">
                                    <label>Account Holder Name</label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                        <input type="text" name="payout_account_name" id="payout_account_name"
                                               placeholder="e.g. Juan Dela Cruz"
                                               value="<?= h(post('payout_account_name')) ?>">
                                    </div>
                                    <span class="payout-field-hint">Must match verified account name</span>
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
                                            <span class="review-label">Gym Contact Number:</span>
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
                                        <div class="review-row align-top" id="rev-sec-id-row" style="display: none;">
                                            <span class="review-label">Supporting ID:</span>
                                            <div class="review-value-stack">
                                                <span class="review-id-title" id="rev-sec-id-type">—</span>
                                                <span class="review-file-badge" id="rev-sec-id-file">No file</span>
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
                                        <div class="review-row review-payout-row align-top">
                                            <span class="review-label">Payout Destination:</span>
                                            <span class="review-value">
                                                <span id="rev-payout-dest">Not specified</span>
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

    const ONBOARDING_USER_ID = <?= (int)$user['user_id'] ?>;
    const ONBOARDING_STORAGE_KEY = 'fittrack_onboarding_draft_' + ONBOARDING_USER_ID;
    const ONBOARDING_IDB_NAME = 'FitTrackOnboardingDB';
    const ONBOARDING_IDB_STORE = 'draft_files';

    const VERIFICATION_ID_TYPES = <?= json_encode($idTypes) ?>;
    const INITIAL_VALID_ID_VAL = <?= json_encode((string) post('valid_id_type')) ?>;
    const INITIAL_SEC_VALID_ID_VAL = <?= json_encode((string) post('secondary_valid_id_type')) ?>;

    function buildIdDropdownItems(primaryList, secondaryList) {
        const items = [];
        if (primaryList && primaryList.length > 0) {
            items.push({ label: 'Primary IDs', isHeader: true });
            primaryList.forEach(p => {
                let disp = p.id_name;
                if (disp === 'Philippine National ID (PhilSys ID)') disp = 'Philippine National ID (PhilSys)';
                else if (disp === 'UMID (Unified Multi-Purpose ID)') disp = 'UMID Card';
                items.push({ id: p.id_name, label: disp });
            });
        }
        if (secondaryList && secondaryList.length > 0) {
            items.push({ label: 'Secondary IDs', isHeader: true });
            secondaryList.forEach(s => {
                items.push({ id: s.id_name, label: s.id_name });
            });
        }
        return items;
    }

    const initialPrimaryTypes = VERIFICATION_ID_TYPES.filter(i => i.category === 'primary');
    const initialSecondaryTypes = VERIFICATION_ID_TYPES.filter(i => i.category === 'secondary');
    const mainIdDropdownItems = buildIdDropdownItems(initialPrimaryTypes, initialSecondaryTypes);

    window.validIdFitDropdown = new FitDropdown({
        container: '#validIdFitDropdownContainer',
        name: 'valid_id_type',
        id: 'valid_id_type',
        placeholder: '-- Choose ID Type --',
        searchable: false,
        items: mainIdDropdownItems,
        value: INITIAL_VALID_ID_VAL,
        onChange: function(item) {
            const val = item ? (item.id || '') : '';
            handleIdTypeChange(val);
            saveDraftText();
        }
    });

    window.secondaryValidIdFitDropdown = new FitDropdown({
        container: '#secondaryValidIdFitDropdownContainer',
        name: 'secondary_valid_id_type',
        id: 'secondary_valid_id_type',
        placeholder: '-- Choose Supporting ID Type --',
        searchable: false,
        items: [],
        value: INITIAL_SEC_VALID_ID_VAL,
        onChange: function(item) {
            const val = item ? (item.id || '') : '';
            handleSecondaryIdTypeChange(val);
            saveDraftText();
        }
    });

    function getIdTypeCategory(nameOrCode) {
        if (!nameOrCode) return null;
        const clean = nameOrCode.trim().toLowerCase();
        const found = VERIFICATION_ID_TYPES.find(i => i.id_name.toLowerCase() === clean || i.id_code.toLowerCase() === clean);
        if (found) return found.category;
        if (clean.startsWith('other')) return 'secondary';
        return 'secondary';
    }

    function populateSecondaryIdOptions(selectedPrimaryVal) {
        if (!window.secondaryValidIdFitDropdown) return;
        const currentVal = window.secondaryValidIdFitDropdown.getValue();
        const filteredPrimary = VERIFICATION_ID_TYPES.filter(i => 
            i.category === 'primary' && (!selectedPrimaryVal || i.id_name.toLowerCase() !== selectedPrimaryVal.trim().toLowerCase())
        );
        const filteredSecondary = VERIFICATION_ID_TYPES.filter(i => 
            i.category === 'secondary' && (!selectedPrimaryVal || i.id_name.toLowerCase() !== selectedPrimaryVal.trim().toLowerCase())
        );

        const items = buildIdDropdownItems(filteredPrimary, filteredSecondary);
        window.secondaryValidIdFitDropdown.setItems(items);

        if (currentVal && selectedPrimaryVal && currentVal.toLowerCase() !== selectedPrimaryVal.trim().toLowerCase()) {
            window.secondaryValidIdFitDropdown.select(currentVal, true);
        } else {
            window.secondaryValidIdFitDropdown.select('', true);
        }
    }

    function getOnboardingDb() {
        return new Promise((resolve, reject) => {
            if (!window.indexedDB) {
                return reject(new Error('IndexedDB not supported'));
            }
            const req = indexedDB.open(ONBOARDING_IDB_NAME, 1);
            req.onupgradeneeded = (e) => {
                const db = e.target.result;
                if (!db.objectStoreNames.contains(ONBOARDING_IDB_STORE)) {
                    db.createObjectStore(ONBOARDING_IDB_STORE);
                }
            };
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }

    async function saveDraftFile(key, file) {
        if (!file) return;
        try {
            const db = await getOnboardingDb();
            const tx = db.transaction(ONBOARDING_IDB_STORE, 'readwrite');
            tx.objectStore(ONBOARDING_IDB_STORE).put({
                name: file.name,
                type: file.type,
                lastModified: file.lastModified,
                blob: file
            }, 'user_' + ONBOARDING_USER_ID + '_' + key);
        } catch (err) {
            console.warn('Could not save draft file to IndexedDB', err);
        }
    }

    async function removeDraftFile(key) {
        try {
            const db = await getOnboardingDb();
            const tx = db.transaction(ONBOARDING_IDB_STORE, 'readwrite');
            tx.objectStore(ONBOARDING_IDB_STORE).delete('user_' + ONBOARDING_USER_ID + '_' + key);
        } catch (err) {}
    }

    let saveDraftTimer = null;
    function saveDraftText() {
        clearTimeout(saveDraftTimer);
        saveDraftTimer = setTimeout(() => {
            try {
                const draft = {
                    gym_name: document.getElementById('gym_name')?.value || '',
                    gym_contact_info: document.getElementById('gym_contact_info')?.value || '',
                    street: document.getElementById('street')?.value || '',
                    barangay: document.getElementById('barangay')?.value || '',
                    city: document.getElementById('city')?.value || '',
                    province: document.getElementById('province')?.value || '',
                    zipcode: document.getElementById('zipcode')?.value || '',
                    valid_id_type: document.getElementById('valid_id_type')?.value || '',
                    valid_id_type_other: document.getElementById('valid_id_type_other')?.value || '',
                    secondary_valid_id_type: document.getElementById('secondary_valid_id_type')?.value || '',
                    secondary_valid_id_type_other: document.getElementById('secondary_valid_id_type_other')?.value || '',
                    fb_page_url: document.getElementById('fb_page_url')?.value || '',
                    payout_channel: document.getElementById('payout_channel')?.value || 'gcash',
                    payout_account_number: document.getElementById('payout_account_number')?.value || '',
                    payout_account_name: document.getElementById('payout_account_name')?.value || '',
                    currentStep: currentStep
                };
                localStorage.setItem(ONBOARDING_STORAGE_KEY, JSON.stringify(draft));
            } catch (err) {}
        }, 150);
    }

    async function restoreDraftData() {
        // 1. Restore text & select fields from localStorage
        try {
            const raw = localStorage.getItem(ONBOARDING_STORAGE_KEY);
            if (raw) {
                const saved = JSON.parse(raw);
                if (saved && typeof saved === 'object') {
                    const fields = [
                        'gym_name', 'gym_contact_info', 'street', 'barangay', 'city', 'province',
                        'zipcode', 'valid_id_type_other', 'secondary_valid_id_type_other', 'fb_page_url',
                        'payout_channel', 'payout_account_number', 'payout_account_name'
                    ];
                    fields.forEach(f => {
                        const el = document.getElementById(f);
                        if (el && (!el.value || el.value.trim() === '')) {
                            if (saved[f] !== undefined && saved[f] !== null) {
                                el.value = saved[f];
                            }
                        }
                    });

                    // Main ID Type
                    if (saved.valid_id_type && window.validIdFitDropdown) {
                        window.validIdFitDropdown.select(saved.valid_id_type, true);
                    } else if (saved.valid_id_type) {
                        const idTypeEl = document.getElementById('valid_id_type');
                        if (idTypeEl) idTypeEl.value = saved.valid_id_type;
                        handleIdTypeChange(saved.valid_id_type);
                    }

                    // Supporting ID Type
                    if (saved.secondary_valid_id_type && window.secondaryValidIdFitDropdown) {
                        window.secondaryValidIdFitDropdown.select(saved.secondary_valid_id_type, true);
                    } else if (saved.secondary_valid_id_type) {
                        const secIdTypeEl = document.getElementById('secondary_valid_id_type');
                        if (secIdTypeEl) secIdTypeEl.value = saved.secondary_valid_id_type;
                        handleSecondaryIdTypeChange(saved.secondary_valid_id_type);
                    }

                    // Restore Step
                    if (saved.currentStep && saved.currentStep > 1) {
                        goToStepDirect(saved.currentStep);
                    }
                }
            }

            // Sync ID policy UI if pre-selected via PHP post or draft
            const currentMainId = window.validIdFitDropdown ? window.validIdFitDropdown.getValue() : (document.getElementById('valid_id_type')?.value || '');
            if (currentMainId) {
                handleIdTypeChange(currentMainId);
                const currentSecId = window.secondaryValidIdFitDropdown ? window.secondaryValidIdFitDropdown.getValue() : (document.getElementById('secondary_valid_id_type')?.value || '');
                if (currentSecId) {
                    handleSecondaryIdTypeChange(currentSecId);
                }
            }
        } catch (err) {
            console.warn('Could not restore draft text', err);
        }

        // 2. Restore file inputs from IndexedDB
        try {
            const db = await getOnboardingDb();
            const tx = db.transaction(ONBOARDING_IDB_STORE, 'readonly');
            const store = tx.objectStore(ONBOARDING_IDB_STORE);
            const docConfigs = [
                { key: 'id', inputId: 'input-valid-id', title: 'Valid ID' },
                { key: 'sec_id', inputId: 'input-secondary-id', title: 'Supporting Government ID' },
                { key: 'permit', inputId: 'input-business-permit', title: 'Business Permit' },
                { key: 'barangay', inputId: 'input-barangay-clearance', title: 'Barangay Clearance' },
                { key: 'fire-safety', inputId: 'input-fire-safety', title: 'Fire Safety Cert.' }
            ];

            docConfigs.forEach(cfg => {
                const req = store.get('user_' + ONBOARDING_USER_ID + '_' + cfg.key);
                req.onsuccess = () => {
                    const item = req.result;
                    if (item && item.blob) {
                        const input = document.getElementById(cfg.inputId);
                        if (input && (!input.files || input.files.length === 0)) {
                            try {
                                const file = new File([item.blob], item.name, {
                                    type: item.type || 'application/octet-stream',
                                    lastModified: item.lastModified || Date.now()
                                });
                                const dt = new DataTransfer();
                                dt.items.add(file);
                                input.files = dt.files;
                                handleDocFileChosen(input, cfg.key, cfg.title);
                                if (currentStep === 3) {
                                    populateReviewData();
                                }
                            } catch (e) {
                                console.warn('DataTransfer file restore error', e);
                            }
                        }
                    }
                };
            });
        } catch (err) {}
    }

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
            'sec_id': 'box-secondary-id',
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
            removeDraftFile(key);
            return;
        }

        saveDraftFile(key, file);

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
        removeDraftFile(key);
        const boxMap = {
            'id': 'box-valid-id',
            'sec_id': 'box-secondary-id',
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
            'input-secondary-id': 'Supporting Government ID',
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

    function handleValidIdClick(e) {
        const val = window.validIdFitDropdown ? window.validIdFitDropdown.getValue() : (document.getElementById('valid_id_type')?.value || '');
        if (!val.trim()) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            if (window.validIdFitDropdown) {
                window.validIdFitDropdown.setError(true);
                window.validIdFitDropdown.trigger?.focus();
                window.validIdFitDropdown.open();
                window.validIdFitDropdown.trigger?.classList.remove('shake-highlight');
                void window.validIdFitDropdown.trigger?.offsetWidth;
                window.validIdFitDropdown.trigger?.classList.add('shake-highlight');
                setTimeout(() => window.validIdFitDropdown.trigger?.classList.remove('shake-highlight'), 600);
            }
            return false;
        }

        if (val === 'Other Valid Government ID') {
            const otherInput = document.getElementById('valid_id_type_other');
            if (otherInput && !otherInput.value.trim()) {
                if (e) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                otherInput.focus();
                otherInput.setCustomValidity('Please specify your other valid ID type.');
                otherInput.reportValidity();

                otherInput.classList.remove('shake-highlight');
                void otherInput.offsetWidth;
                otherInput.classList.add('shake-highlight');
                setTimeout(() => otherInput.classList.remove('shake-highlight'), 600);
                return false;
            }
        }
        return true;
    }

    function handleValidIdBoxClick(e) {
        if (!e) return;
        if (e.target && (e.target.closest('.doc-action-btn') || e.target.closest('.doc-preview-thumb-wrap'))) {
            return;
        }
        const val = window.validIdFitDropdown ? window.validIdFitDropdown.getValue() : (document.getElementById('valid_id_type')?.value || '');
        if (!val.trim()) {
            handleValidIdClick(e);
        }
    }

    function handleIdTypeChange(val) {
        val = (val || '').trim();
        const otherWrap = document.getElementById('other-id-wrap');
        const titleValidId = document.getElementById('title-valid-id');
        const descValidId = document.getElementById('desc-valid-id');
        const otherInput = document.getElementById('valid_id_type_other');
        const box = document.getElementById('box-valid-id');
        const policyBadge = document.getElementById('id-policy-badge');
        const supportingWrap = document.getElementById('supporting-id-container');

        if (window.validIdFitDropdown) {
            window.validIdFitDropdown.setError(false);
            window.validIdFitDropdown.trigger?.classList.remove('shake-highlight');
        }

        if (val === 'Other Valid Government ID') {
            if (otherWrap) otherWrap.style.display = 'block';
            if (otherInput) otherInput.focus();
            if (titleValidId) titleValidId.textContent = 'Upload Valid ID';
            if (descValidId) descValidId.textContent = 'JPG, PNG or PDF (Max 5MB)';
            if (box) {
                box.classList.remove('box-locked');
                box.title = 'Click to choose file';
            }
        } else if (val) {
            if (otherWrap) otherWrap.style.display = 'none';
            if (titleValidId) {
                titleValidId.textContent = 'Upload ' + (val.length > 22 ? val.substring(0, 20) + '...' : val);
            }
            if (descValidId) descValidId.textContent = 'JPG, PNG or PDF (Max 5MB)';
            if (box) {
                box.classList.remove('box-locked');
                box.title = 'Click to choose file';
            }
        } else {
            if (otherWrap) otherWrap.style.display = 'none';
            if (titleValidId) titleValidId.textContent = 'Upload Valid ID';
            if (descValidId) descValidId.textContent = 'Select ID type first';
            if (box) {
                box.classList.add('box-locked');
                box.title = 'Please choose a Valid ID Type first';
            }
        }

        // Classification handling
        const category = getIdTypeCategory(val);
        if (!val) {
            if (policyBadge) policyBadge.style.display = 'none';
            if (supportingWrap) supportingWrap.style.display = 'none';
        } else if (category === 'primary') {
            if (policyBadge) {
                policyBadge.style.display = 'flex';
                policyBadge.className = 'id-policy-badge is-primary';
                policyBadge.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;"><polyline points="20 6 9 17 4 12"/></svg> <span><strong>Primary ID Accepted</strong> — Single ID is sufficient for verification.</span>';
            }
            if (supportingWrap) supportingWrap.style.display = 'none';
        } else {
            // Secondary ID: Requires supporting ID
            if (policyBadge) {
                policyBadge.style.display = 'flex';
                policyBadge.className = 'id-policy-badge is-secondary';
                policyBadge.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> <span><strong>Secondary ID Selected</strong> — 1 additional supporting government ID is required below.</span>';
            }
            if (supportingWrap) {
                supportingWrap.style.display = 'block';
                populateSecondaryIdOptions(val);
            }
        }
    }

    function handleSecondaryIdTypeChange(val) {
        val = (val || '').trim();
        const otherWrap = document.getElementById('secondary-other-id-wrap');
        const titleSecId = document.getElementById('title-sec_id');
        const descSecId = document.getElementById('desc-sec_id');
        const otherInput = document.getElementById('secondary_valid_id_type_other');
        const box = document.getElementById('box-secondary-id');

        if (window.secondaryValidIdFitDropdown) {
            window.secondaryValidIdFitDropdown.setError(false);
            window.secondaryValidIdFitDropdown.trigger?.classList.remove('shake-highlight');
        }

        if (val === 'Other Valid Government ID') {
            if (otherWrap) otherWrap.style.display = 'block';
            if (otherInput) otherInput.focus();
            if (titleSecId) titleSecId.textContent = 'Upload Supporting ID';
            if (descSecId) descSecId.textContent = 'JPG, PNG or PDF (Max 5MB)';
            if (box) {
                box.classList.remove('box-locked');
                box.title = 'Click to choose file';
            }
        } else if (val) {
            if (otherWrap) otherWrap.style.display = 'none';
            if (titleSecId) {
                titleSecId.textContent = 'Upload ' + (val.length > 22 ? val.substring(0, 20) + '...' : val);
            }
            if (descSecId) descSecId.textContent = 'JPG, PNG or PDF (Max 5MB)';
            if (box) {
                box.classList.remove('box-locked');
                box.title = 'Click to choose file';
            }
        } else {
            if (otherWrap) otherWrap.style.display = 'none';
            if (titleSecId) titleSecId.textContent = 'Upload Supporting ID';
            if (descSecId) descSecId.textContent = 'Select ID type first';
            if (box) {
                box.classList.add('box-locked');
                box.title = 'Please choose a Supporting ID Type first';
            }
        }
    }

    function handleSecondaryIdClick(e) {
        const val = window.secondaryValidIdFitDropdown ? window.secondaryValidIdFitDropdown.getValue() : (document.getElementById('secondary_valid_id_type')?.value || '');
        if (!val.trim()) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            if (window.secondaryValidIdFitDropdown) {
                window.secondaryValidIdFitDropdown.setError(true);
                window.secondaryValidIdFitDropdown.trigger?.focus();
                window.secondaryValidIdFitDropdown.open();
                window.secondaryValidIdFitDropdown.trigger?.classList.remove('shake-highlight');
                void window.secondaryValidIdFitDropdown.trigger?.offsetWidth;
                window.secondaryValidIdFitDropdown.trigger?.classList.add('shake-highlight');
                setTimeout(() => window.secondaryValidIdFitDropdown.trigger?.classList.remove('shake-highlight'), 600);
            }
            return false;
        }

        if (val === 'Other Valid Government ID') {
            const otherInput = document.getElementById('secondary_valid_id_type_other');
            if (otherInput && !otherInput.value.trim()) {
                if (e) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                otherInput.focus();
                otherInput.setCustomValidity('Please specify your other supporting ID type.');
                otherInput.reportValidity();
                otherInput.classList.remove('shake-highlight');
                void otherInput.offsetWidth;
                otherInput.classList.add('shake-highlight');
                setTimeout(() => otherInput.classList.remove('shake-highlight'), 600);
                return false;
            }
        }
        return true;
    }

    function handleSecondaryIdBoxClick(e) {
        if (!e) return;
        if (e.target && (e.target.closest('.doc-action-btn') || e.target.closest('.doc-preview-thumb-wrap'))) {
            return;
        }
        const val = window.secondaryValidIdFitDropdown ? window.secondaryValidIdFitDropdown.getValue() : (document.getElementById('secondary_valid_id_type')?.value || '');
        if (!val.trim()) {
            handleSecondaryIdClick(e);
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
            if (gc && gc.value.trim() !== '') {
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
            } else if (gc) {
                gc.setCustomValidity('');
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
            const idTypeVal = window.validIdFitDropdown ? window.validIdFitDropdown.getValue() : (document.getElementById('valid_id_type')?.value || '');
            const idFile = document.getElementById('input-valid-id');
            const permitFile = document.getElementById('input-business-permit');
            const brgyFile = document.getElementById('input-barangay-clearance');

            if (!idTypeVal.trim()) {
                goToStepDirect(2);
                if (window.validIdFitDropdown) {
                    window.validIdFitDropdown.setError(true);
                    window.validIdFitDropdown.trigger?.focus();
                    window.validIdFitDropdown.open();
                }
                return;
            }

            const idOther = document.getElementById('valid_id_type_other');
            if (idTypeVal === 'Other Valid Government ID' && idOther && !idOther.value.trim()) {
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

            // Secondary ID requirement check
            const category = getIdTypeCategory(idTypeVal);
            if (category === 'secondary') {
                const secIdVal = window.secondaryValidIdFitDropdown ? window.secondaryValidIdFitDropdown.getValue() : (document.getElementById('secondary_valid_id_type')?.value || '');
                const secIdOther = document.getElementById('secondary_valid_id_type_other');
                const secIdFile = document.getElementById('input-secondary-id');

                if (!secIdVal.trim()) {
                    goToStepDirect(2);
                    if (window.secondaryValidIdFitDropdown) {
                        window.secondaryValidIdFitDropdown.setError(true);
                        window.secondaryValidIdFitDropdown.trigger?.focus();
                        window.secondaryValidIdFitDropdown.open();
                    }
                    return;
                }

                if (secIdVal.toLowerCase() === idTypeVal.toLowerCase()) {
                    goToStepDirect(2);
                    if (window.secondaryValidIdFitDropdown) {
                        window.secondaryValidIdFitDropdown.setError(true);
                        window.secondaryValidIdFitDropdown.trigger?.focus();
                        window.secondaryValidIdFitDropdown.open();
                    }
                    return;
                }

                if (secIdVal === 'Other Valid Government ID' && secIdOther && !secIdOther.value.trim()) {
                    goToStepDirect(2);
                    secIdOther.focus();
                    secIdOther.setCustomValidity('Please specify your other supporting valid ID type.');
                    secIdOther.reportValidity();
                    return;
                }

                if (!secIdFile || !secIdFile.files || !secIdFile.files.length) {
                    goToStepDirect(2);
                    secIdFile.focus();
                    secIdFile.setCustomValidity('Please upload your supporting government ID document.');
                    secIdFile.reportValidity();
                    return;
                }
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
        saveDraftText();

        // Smoothly scroll card into view
        const card = document.querySelector('.onboarding-card');
        if (card) {
            card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function populateReviewData() {
        const gn = document.getElementById('gym_name')?.value || '—';
        const gc = document.getElementById('gym_contact_info')?.value.trim() || 'Not provided';
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

        const category = getIdTypeCategory(idTypeVal);
        const revIdType = document.getElementById('rev-id-type');
        const revIdFile = document.getElementById('rev-id-file');
        const revSecRow = document.getElementById('rev-sec-id-row');
        const revSecIdType = document.getElementById('rev-sec-id-type');
        const revSecIdFile = document.getElementById('rev-sec-id-file');

        if (revIdType) {
            const badgeLabel = category === 'primary' ? ' (Primary ID)' : ' (Secondary ID)';
            revIdType.textContent = fullIdType + badgeLabel;
            revIdType.title = fullIdType;
        }
        if (revIdFile) {
            revIdFile.textContent = '✓ ' + idFile;
            revIdFile.title = 'Click to preview ' + idFile;
            revIdFile.classList.add('clickable');
            revIdFile.onclick = (e) => openDocPreview('input-valid-id', e);
        }

        if (category === 'secondary') {
            if (revSecRow) revSecRow.style.display = 'flex';
            const secIdTypeVal = document.getElementById('secondary_valid_id_type')?.value || '—';
            const secIdOtherVal = document.getElementById('secondary_valid_id_type_other')?.value || '';
            const fullSecIdType = (secIdTypeVal === 'Other Valid Government ID' && secIdOtherVal) ? ('Other: ' + secIdOtherVal) : secIdTypeVal;
            const secFile = document.getElementById('input-secondary-id')?.files[0]?.name || 'Attached';

            if (revSecIdType) {
                revSecIdType.textContent = fullSecIdType;
                revSecIdType.title = fullSecIdType;
            }
            if (revSecIdFile) {
                revSecIdFile.textContent = '✓ ' + secFile;
                revSecIdFile.title = 'Click to preview ' + secFile;
                revSecIdFile.classList.add('clickable');
                revSecIdFile.onclick = (e) => openDocPreview('input-secondary-id', e);
            }
        } else {
            if (revSecRow) revSecRow.style.display = 'none';
        }

        const revPermitFile = document.getElementById('rev-permit-file');
        const revBrgyFile = document.getElementById('rev-brgy-file');
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

        const revPayout = document.getElementById('rev-payout-dest');
        if (revPayout) {
            const pChan = document.getElementById('payout_channel')?.value || 'gcash';
            const pNum  = document.getElementById('payout_account_number')?.value.trim() || '';
            const pName = document.getElementById('payout_account_name')?.value.trim() || '';
            if (pNum) {
                const chanLabel = pChan === 'gcash' ? 'GCash' : (pChan === 'maya' ? 'Maya' : 'Bank Transfer');
                revPayout.textContent = chanLabel + ': ' + pNum + (pName ? ' (' + pName + ')' : '');
                revPayout.style.color = '#0f172a';
                revPayout.style.fontWeight = '600';
            } else {
                revPayout.textContent = 'Not specified (Can set up later)';
                revPayout.style.color = '#64748b';
                revPayout.style.fontWeight = 'normal';
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

    // Auto-save & auto-restore draft across page refreshes
    const onboardingForm = document.getElementById('gymOnboardingForm') || document.querySelector('.split-card-form');
    if (onboardingForm) {
        onboardingForm.addEventListener('input', saveDraftText);
        onboardingForm.addEventListener('change', saveDraftText);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restoreDraftData);
    } else {
        restoreDraftData();
    }
    </script>
    <?php
    render_footer();
}

