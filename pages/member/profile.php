<?php

declare(strict_types=1);

function profile_page(): void
{
    $user      = require_login();
    $is_member = $user['role'] === 'member';
    $profile   = $is_member ? member_profile((int) $user['user_id']) : null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['update_account'])) {
            $email    = trim((string) ($_POST['email']    ?? ''));
            $phone    = trim((string) ($_POST['phone']    ?? ''));
            $fname    = trim((string) ($_POST['first_name'] ?? ''));
            $lname    = trim((string) ($_POST['last_name']  ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            $validator = new Validator();
            $valid = $validator->validate($_POST, [
                'first_name' => 'required|min:1|max:100',
                'last_name'  => 'required|min:1|max:100',
                'email'      => 'required|email|max:255',
            ]);

            if ($valid && $password !== '' && !is_acceptable_password($password)) {
                $valid = false;
                $validator_error = 'New password must be at least 8 characters with a letter and a number, and not a common password.';
            }

            if ($valid && $email !== $user['email']) {
                $existing = scalar('SELECT user_id FROM users WHERE email = ? AND user_id != ?', [$email, $user['user_id']]);
                if ($existing) {
                    $valid = false;
                    $validator_error = 'That email is already in use by another account.';
                }
            }

            if (!$valid) {
                flash($validator_error ?? $validator->firstError(), 'danger');
                redirect('profile');
            }

            $updates = [];
            $params  = [];

            if ($fname !== $user['first_name']) {
                $updates[] = 'first_name = ?';
                $params[] = $fname;
            }
            if ($lname !== $user['last_name']) {
                $updates[] = 'last_name = ?';
                $params[] = $lname;
            }
            if ($email !== $user['email']) {
                $updates[] = 'email = ?';
                $params[] = $email;
            }
            if ($phone !== ($user['phone'] ?? '')) {
                $updates[] = 'phone = ?';
                $params[] = $phone;
            }
            if (!empty($password)) {
                $updates[] = 'password_hash = ?';
                $params[]  = password_hash($password, PASSWORD_DEFAULT);
            }

            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                try {
                    $filename = FileUpload::storeProfilePicture($_FILES['profile_picture'], (int) $user['user_id']);
                    $updates[] = 'profile_picture = ?';
                    $params[]  = $filename;
                } catch (RuntimeException $e) {
                    flash($e->getMessage(), 'danger');
                    redirect('profile');
                }
            }

            if ($updates) {
                $params[] = $user['user_id'];
                db()->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE user_id = ?')
                    ->execute($params);
                flash('Account details updated.', 'success');
            } else {
                flash('No changes made to account.', 'info');
            }
            redirect('profile');
        } elseif (isset($_POST['height_cm']) && $is_member) {
            $validator = new Validator();
            $valid = $validator->validate($_POST, [
                'height_cm' => 'numeric|min_num:100|max_num:250',
                'weight_kg' => 'numeric|min_num:20|max_num:300',
                'age'       => 'numeric|min_num:16|max_num:120',
                'neck_cm'   => 'numeric|min_num:20|max_num:100',
                'waist_cm'  => 'numeric|min_num:30|max_num:200',
                'hip_cm'    => 'numeric|min_num:30|max_num:200',
                'target_weight_kg'        => 'numeric|min_num:20|max_num:300',
                'target_body_fat_percent' => 'numeric|min_num:1|max_num:70',
            ]);
            
            if (!$valid) {
                flash($validator->firstError() ?? 'Invalid measurements provided.', 'danger');
                redirect('profile');
            }

            $oldGoal = $profile['primary_goal'] ?? '';
            $newGoal = post('primary_goal');
            
            save_member_profile((int) $user['user_id']);
            
            if ($oldGoal !== $newGoal || can_recalculate_workout((int) $user['user_id'])) {
                generate_workout_plan((int) $user['user_id']);
                notify_user((int) $user['user_id'], 'system', 'Workout plan updated', 'Your workout plan was recalculated from your updated physical profile.');
                flash('Physical profile and workout plan updated.', 'success');
            } else {
                flash('Physical profile updated.', 'success');
            }
            $hasMembership = scalar('SELECT 1 FROM memberships WHERE user_id = ? AND status IN ("active", "pending")', [$user['user_id']]);
            if (!$hasMembership) {
                redirect('gym_selection');
            }
            redirect('profile');
        } elseif (isset($_POST['update_system_settings']) && $user['role'] === 'admin') {
            $settings = $_POST['settings'] ?? [];
            $totalWeight = 0;
            if (isset($settings['engagement_weight_attendance'])) {
                foreach ($settings as $key => $val) {
                    if (str_starts_with($key, 'engagement_weight_')) {
                        $totalWeight += (int)$val;
                    }
                }
            }
            if (isset($settings['engagement_weight_attendance']) && $totalWeight !== 100) {
                flash("Engagement weights must equal exactly 100%. Currently: {$totalWeight}%", 'danger');
            } else {
                $currentInactivity = (int)(scalar("SELECT setting_value FROM system_settings WHERE setting_key = 'at_risk_inactivity_days'") ?: 3);
                $currentCooldown = (int)(scalar("SELECT setting_value FROM system_settings WHERE setting_key = 'at_risk_notification_cooldown'") ?: 14);
                $hasAtRiskChanges = false;
                
                if ((isset($settings['at_risk_inactivity_days']) && (int)$settings['at_risk_inactivity_days'] !== $currentInactivity) ||
                    (isset($settings['at_risk_notification_cooldown']) && (int)$settings['at_risk_notification_cooldown'] !== $currentCooldown)) {
                    $hasAtRiskChanges = true;
                }

                $canUpdateAtRisk = true;
                $blockedMsg = '';
                if ($hasAtRiskChanges) {
                    $lastUpdate = scalar("SELECT setting_value FROM system_settings WHERE setting_key = 'last_at_risk_settings_update'") ?: '2000-01-01 00:00:00';
                    if (strtotime($lastUpdate) > strtotime('-15 days')) {
                        $canUpdateAtRisk = false;
                        $daysLeft = 15 - floor((time() - strtotime($lastUpdate)) / 86400);
                        $blockedMsg = "Automated Notification settings can only be updated every 15 days. Please wait {$daysLeft} more days.";
                    } else {
                        $settings['last_at_risk_settings_update'] = date('Y-m-d H:i:s');
                    }
                }

                if ($hasAtRiskChanges && !$canUpdateAtRisk) {
                    unset($settings['at_risk_inactivity_days']);
                    unset($settings['at_risk_notification_cooldown']);
                    if (!empty($settings)) {
                        flash($blockedMsg . ' Other settings were saved.', 'warning');
                    } else {
                        flash($blockedMsg, 'danger');
                    }
                } else {
                    flash('System settings updated successfully.', 'success');
                }

                if (!empty($settings)) {
                    $stmt = db()->prepare('UPDATE system_settings SET setting_value = ?, updated_by = ?, updated_at = NOW() WHERE setting_key = ? AND setting_key != "last_at_risk_scan_date"');
                    foreach ($settings as $key => $val) {
                        $stmt->execute([(string) $val, $user['user_id'], $key]);
                    }
                    audit_log($user['user_id'], 'edit', 'system_settings', null, json_encode($settings));
                }
            }
            redirect('profile');
        } elseif (isset($_POST['request_transfer']) && $is_member) {
            $toGymId = (int) post('to_gym_id');
            $fromGymId = (int) post('from_gym_id');
            if ($toGymId && $fromGymId && $toGymId !== $fromGymId) {
                // Check if already has pending transfer
                $pending = scalar('SELECT transfer_id FROM member_transfers WHERE user_id = ? AND status IN ("pending_current_gym", "pending_receiving_gym")', [$user['user_id']]);
                if ($pending) {
                    flash('You already have a pending transfer request.', 'warning');
                } else {
                    db()->prepare('INSERT INTO member_transfers (user_id, from_gym_id, to_gym_id, status) VALUES (?, ?, ?, "pending_current_gym")')
                        ->execute([$user['user_id'], $fromGymId, $toGymId]);
                    
                    // Notify current gym owner
                    $currentGymOwner = scalar('SELECT owner_user_id FROM gyms WHERE gym_id = ?', [$fromGymId]);
                    if ($currentGymOwner) {
                        notify_user((int) $currentGymOwner, 'system', 'New Transfer Request', $user['first_name'] . ' requested a gym transfer. Please review in Member Transfers.');
                    }
                    
                    flash('Transfer request submitted successfully. Waiting for your current gym owner to approve.', 'success');
                }
            } else {
                flash('Invalid gym selected.', 'danger');
            }
            redirect('profile');
        } elseif (isset($_POST['switch_home_gym']) && $is_member) {
            $newGymId = (int) post('new_gym_id');
            $gym = db()->query("SELECT name FROM gyms WHERE gym_id = $newGymId AND status = 'approved'")->fetch();
            if ($gym) {
                db()->prepare("DELETE FROM gym_members WHERE user_id = ?")->execute([$user['user_id']]);
                db()->prepare("INSERT INTO gym_members (user_id, gym_id) VALUES (?, ?)")->execute([$user['user_id'], $newGymId]);
                $_SESSION['current_gym_id'] = $newGymId;
                flash('You have set ' . $gym['name'] . ' as your home gym. Your historical data (workouts, diet, progress) is now linked to this profile. To access trainers and classes here, please purchase a membership plan.', 'success');
            } else {
                flash('Invalid gym selected.', 'danger');
            }
            redirect('profile');
        }
    }

    $user = current_user();

    $allSettings = query_all('SELECT * FROM system_settings WHERE setting_key != "last_at_risk_scan_date" ORDER BY setting_key');
    $sysSettings = [];
    foreach ($allSettings as $s) {
        $sysSettings[$s['setting_key']] = $s;
    }

    $currentGymId = null;
    $currentGymName = '';
    $homeGymId = null;
    $homeGymName = 'None';
    if ($is_member) {
        $activeMem = db()->query("
            SELECT g.gym_id, g.name 
            FROM memberships m 
            JOIN membership_plans p ON p.plan_id = m.plan_id 
            JOIN gyms g ON g.gym_id = p.gym_id 
            WHERE m.user_id = {$user['user_id']} AND m.status = 'active' AND p.gym_id IS NOT NULL
            ORDER BY m.end_date DESC LIMIT 1
        ")->fetch();
        if ($activeMem) {
            $currentGymId = (int)$activeMem['gym_id'];
            $currentGymName = $activeMem['name'];
        }
        $homeGym = get_user_gym($user);
        if ($homeGym) {
            $homeGymId = (int)$homeGym['gym_id'];
            $homeGymName = $homeGym['name'];
        }
    }
    
    $allGyms = query_all('SELECT gym_id, name FROM gyms WHERE status = "approved" ORDER BY name ASC');

    render_header('Settings', $user);

    // Fetch membership info for gym affiliation display
    $membershipInfo = null;
    if ($is_member && $currentGymId) {
        $membershipInfo = db()->query("
            SELECT m.*, mp.name as plan_name, mp.duration_days, m.end_date,
                   DATEDIFF(m.end_date, CURDATE()) as days_remaining
            FROM memberships m
            JOIN membership_plans mp ON mp.plan_id = m.plan_id
            WHERE m.user_id = {$user['user_id']} AND m.status = 'active'
            ORDER BY m.end_date DESC LIMIT 1
        ")->fetch();
    }
?>
    <?php render_skeleton_profile(); ?>
    <div class="skeleton-content sk-display-block">

    <!-- Settings Header with Profile Hero -->
    <div class="settings-hero">
        <div class="settings-hero-bg"></div>
        <div class="settings-hero-content">
            <div class="settings-hero-avatar">
                <?php if (!empty($user['profile_picture'])): ?>
                    <img src="<?= h(upload_url($user['profile_picture'])) ?>" alt="Profile picture" loading="lazy" decoding="async">
                <?php else: ?>
                    <span class="settings-hero-initials"><?= h(initials($user)) ?></span>
                <?php endif; ?>
                <button type="button" class="settings-avatar-edit" onclick="document.getElementById('accountModal').showModal()" title="Edit profile">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                </button>
            </div>
            <div class="settings-hero-info">
                <h1 class="settings-hero-name"><?= h($user['first_name'] . ' ' . $user['last_name']) ?></h1>
                <div class="settings-hero-meta">
                    <span class="settings-hero-role"><?= h(ucfirst($user['role'])) ?></span>
                    <?php if ($is_member && $homeGymName !== 'None'): ?>
                        <span class="settings-hero-dot">·</span>
                        <span class="settings-hero-gym"><?= h($homeGymName) ?></span>
                    <?php endif; ?>
                </div>
                <div class="settings-hero-contact">
                    <span><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg> <?= h($user['email']) ?></span>
                    <?php if (!empty($user['phone'])): ?>
                        <span><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> <?= h($user['phone']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <nav class="settings-tabs" id="settingsTabs">
        <button class="settings-tab active" data-tab="account">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Account
        </button>
        <?php if ($is_member): ?>
        <button class="settings-tab" data-tab="physical">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            Physical Profile
        </button>
        <button class="settings-tab" data-tab="gym">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
            Gym Affiliation
        </button>
        <?php endif; ?>
        <button class="settings-tab" data-tab="preferences">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            Preferences
        </button>
        <?php if ($user['role'] === 'admin'): ?>
        <button class="settings-tab" data-tab="system">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            System
        </button>
        <?php endif; ?>
    </nav>

    <!-- Tab: Account -->
    <div class="settings-panel active" data-panel="account">
        <div class="settings-section">
            <div class="settings-section-header">
                <div>
                    <h2 class="settings-section-title">Account Details</h2>
                    <p class="settings-section-desc">Manage your personal information and login credentials.</p>
                </div>
                <button type="button" class="settings-edit-btn" onclick="document.getElementById('accountModal').showModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                    Edit
                </button>
            </div>
            
            <div class="settings-info-grid">
                <div class="settings-info-row">
                    <div class="settings-info-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </div>
                    <div class="settings-info-content">
                        <span class="settings-info-label">Full Name</span>
                        <span class="settings-info-value"><?= h($user['first_name'] . ' ' . $user['last_name']) ?></span>
                    </div>
                </div>
                <div class="settings-info-row">
                    <div class="settings-info-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                    </div>
                    <div class="settings-info-content">
                        <span class="settings-info-label">Email Address</span>
                        <span class="settings-info-value"><?= h($user['email']) ?></span>
                    </div>
                </div>
                <div class="settings-info-row">
                    <div class="settings-info-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    </div>
                    <div class="settings-info-content">
                        <span class="settings-info-label">Phone Number</span>
                        <span class="settings-info-value"><?= h($user['phone'] ?? 'Not set') ?></span>
                    </div>
                </div>
                <div class="settings-info-row">
                    <div class="settings-info-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </div>
                    <div class="settings-info-content">
                        <span class="settings-info-label">Password</span>
                        <span class="settings-info-value">••••••••</span>
                    </div>
                </div>
                <div class="settings-info-row">
                    <div class="settings-info-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>
                    </div>
                    <div class="settings-info-content">
                        <span class="settings-info-label">Account Type</span>
                        <span class="settings-info-value"><?= h(ucfirst($user['role'])) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab: Physical Profile -->
    <?php if ($is_member): ?>
    <div class="settings-panel" data-panel="physical">
        <div class="settings-section">
            <div class="settings-section-header">
                <div>
                    <h2 class="settings-section-title">Physical Profile</h2>
                    <p class="settings-section-desc">Keeping your physical profile up to date helps generate accurate workout & diet plans.</p>
                </div>
                <button type="button" class="settings-edit-btn" onclick="document.getElementById('physicalProfileModal').showModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                    Edit
                </button>
            </div>

            <!-- Body Measurements -->
            <h3 class="settings-group-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Body Measurements
            </h3>
            <div class="settings-stat-grid">
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, var(--teal) 15%, transparent); color: var(--teal);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h0M12 4h0M12 12h0M4 8l2.5 2L4 12M20 8l-2.5 2L20 12"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Height</span>
                        <span class="settings-stat-value"><?= h($profile['height_cm'] ?? '—') ?> <small>cm</small></span>
                    </div>
                </div>
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, var(--orange) 15%, transparent); color: var(--orange);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Weight</span>
                        <span class="settings-stat-value"><?= h($profile['weight_kg'] ?? '—') ?> <small>kg</small></span>
                    </div>
                </div>
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, var(--lime) 15%, transparent); color: var(--lime);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Age</span>
                        <span class="settings-stat-value"><?= h($profile['age'] ?? '—') ?> <small>yrs</small></span>
                    </div>
                </div>
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, #a78bfa 15%, transparent); color: #a78bfa;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Sex</span>
                        <span class="settings-stat-value"><?= h(ucwords($profile['biological_sex'] ?? '—')) ?></span>
                    </div>
                </div>
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, #60a5fa 15%, transparent); color: #60a5fa;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" x2="22" y1="12" y2="12"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Neck</span>
                        <span class="settings-stat-value"><?= !empty($profile['neck_cm']) ? h($profile['neck_cm']) . ' <small>cm</small>' : '—' ?></span>
                    </div>
                </div>
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, #f472b6 15%, transparent); color: #f472b6;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 18 0 9 9 0 0 0-18 0"/><path d="M12 8v8"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Waist</span>
                        <span class="settings-stat-value"><?= !empty($profile['waist_cm']) ? h($profile['waist_cm']) . ' <small>cm</small>' : '—' ?></span>
                    </div>
                </div>
                <?php if (($profile['biological_sex'] ?? '') === 'female'): ?>
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, #fb923c 15%, transparent); color: #fb923c;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 18 0 9 9 0 0 0-18 0"/><path d="M12 8v8"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Hip</span>
                        <span class="settings-stat-value"><?= !empty($profile['hip_cm']) ? h($profile['hip_cm']) . ' <small>cm</small>' : '—' ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Fitness Goals -->
            <h3 class="settings-group-title" style="margin-top: 2rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                Fitness Goals
            </h3>
            <div class="settings-stat-grid">
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, var(--lime) 15%, transparent); color: var(--lime);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Activity Level</span>
                        <span class="settings-stat-value"><?= h(ucwords(str_replace('_', ' ', $profile['activity_level'] ?? '—'))) ?></span>
                    </div>
                </div>
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, var(--teal) 15%, transparent); color: var(--teal);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Primary Goal</span>
                        <span class="settings-stat-value"><?= h(ucwords(str_replace('_', ' ', $profile['primary_goal'] ?? '—'))) ?></span>
                    </div>
                </div>
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, var(--orange) 15%, transparent); color: var(--orange);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" x2="6" y1="1" y2="4"/><line x1="10" x2="10" y1="1" y2="4"/><line x1="14" x2="14" y1="1" y2="4"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Dietary Preference</span>
                        <span class="settings-stat-value"><?= h(ucwords(str_replace('-', ' ', $profile['dietary_restrictions'] ?? 'None'))) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tab: Gym Affiliation -->
    <?php if ($is_member): ?>
    <div class="settings-panel" data-panel="gym">
        <div class="settings-section">
            <div class="settings-section-header">
                <div>
                    <h2 class="settings-section-title">Gym Affiliation</h2>
                    <p class="settings-section-desc">
                        <?php if ($currentGymId): ?>
                            Manage your active membership and transfer options.
                        <?php else: ?>
                            Set your home gym to access trainers, classes, and plans.
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Current Gym Card -->
            <div class="settings-gym-card <?= $currentGymId ? 'has-membership' : 'no-membership' ?>">
                <div class="settings-gym-card-accent"></div>
                <div class="settings-gym-card-body">
                    <div class="settings-gym-card-icon">
                        <?php if ($currentGymId || $homeGymId): ?>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
                        <?php else: ?>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="16"/><line x1="8" x2="16" y1="12" y2="12"/></svg>
                        <?php endif; ?>
                    </div>
                    <div class="settings-gym-card-info">
                        <span class="settings-gym-card-label"><?= $currentGymId ? 'Active Membership' : 'Home Gym' ?></span>
                        <span class="settings-gym-card-name"><?= h($currentGymId ? $currentGymName : $homeGymName) ?></span>
                        <?php if ($currentGymId && $membershipInfo): ?>
                            <div class="settings-gym-card-details">
                                <span class="settings-gym-badge active">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                    Active
                                </span>
                                <span class="settings-gym-plan"><?= h($membershipInfo['plan_name']) ?></span>
                                <?php if ($membershipInfo['days_remaining'] > 0): ?>
                                    <span class="settings-gym-days"><?= (int)$membershipInfo['days_remaining'] ?> days remaining</span>
                                <?php endif; ?>
                            </div>
                        <?php elseif (!$currentGymId): ?>
                            <div class="settings-gym-card-details">
                                <span class="settings-gym-badge inactive">No Active Plan</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($currentGymId): ?>
                <!-- Scenario B: Active Membership Transfer -->
                <?php 
                $pendingTransfer = db()->query("SELECT t.*, g.name as to_gym FROM member_transfers t JOIN gyms g ON g.gym_id = t.to_gym_id WHERE t.user_id = {$user['user_id']} AND t.status IN ('pending_current_gym', 'pending_receiving_gym')")->fetch();
                if ($pendingTransfer): 
                    $statusMsg = $pendingTransfer['status'] === 'pending_current_gym' ? 'Waiting for your current gym to approve the release.' : 'Waiting for the destination gym to accept.';
                    $statusColor = $pendingTransfer['status'] === 'pending_current_gym' ? 'var(--orange)' : 'var(--teal)';
                ?>
                    <div class="settings-transfer-status">
                        <div class="settings-transfer-status-icon" style="color: <?= $statusColor ?>;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <div>
                            <strong style="display:block;margin-bottom:2px;">Transfer Pending</strong>
                            <span class="muted" style="font-size:13px;">Transferring to <strong><?= h($pendingTransfer['to_gym']) ?></strong> — <?= h($statusMsg) ?></span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="settings-transfer-form-wrapper">
                        <h3 class="settings-group-title" style="margin-top:1.5rem;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                            Transfer Membership
                        </h3>
                        <form method="post" id="transfer-form" class="form" style="margin-bottom:0;" onsubmit="handleTransferSubmit(event);">
                            <?= csrf_field() ?>
                            <input type="hidden" name="request_transfer" value="1">
                            <input type="hidden" name="from_gym_id" value="<?= $currentGymId ?>">
                            <div class="settings-select-wrapper">
                                <label class="settings-select-label">Destination Gym</label>
                                <select name="to_gym_id" id="to_gym_id" class="settings-select" required>
                                    <option value="">Choose a gym...</option>
                                    <?php foreach ($allGyms as $g): if ((int)$g['gym_id'] === $currentGymId) continue; ?>
                                        <option value="<?= (int) $g['gym_id'] ?>"><?= h($g['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="settings-action-btn danger" style="margin-top:12px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                                Request Transfer
                            </button>
                        </form>
                    </div>
                    <script>
                    function handleTransferSubmit(e) {
                        e.preventDefault();
                        const select = document.getElementById('to_gym_id');
                        const selectedText = select.options[select.selectedIndex].text;
                        if (!select.value) return;

                        Swal.fire({
                            title: 'Transfer Membership?',
                            html: '<p style="font-size:14px;color:var(--muted);margin-bottom:15px;">You are requesting to transfer your active membership to <strong>' + selectedText + '</strong>.</p>' +
                                  '<div style="text-align:left;background:rgba(239, 68, 68, 0.1);border:1px solid rgba(239, 68, 68, 0.3);padding:12px 14px;border-radius:10px;">' +
                                  '<strong style="color:#ef4444;display:block;margin-bottom:6px;font-size:13px;">⚠ Before you proceed:</strong>' +
                                  '<ul style="margin:0;padding-left:18px;font-size:13px;color:var(--muted);line-height:1.7;">' +
                                  '<li>Your current gym owner must approve the release.</li>' +
                                  '<li>The destination gym must accept your transfer.</li>' +
                                  '<li>You will <strong>forfeit</strong> any remaining sessions, credits, or benefits at your current gym.</li>' +
                                  '</ul></div>',
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonColor: '#ef4444',
                            cancelButtonColor: 'transparent',
                            confirmButtonText: 'Yes, Request Transfer',
                            cancelButtonText: 'Cancel',
                            background: 'var(--surface-color, #18251eff)',
                            color: 'var(--text-color, #ffffff)',
                            customClass: { cancelButton: 'swal-skip-btn' }
                        }).then((result) => {
                            if (result.isConfirmed) {
                                document.getElementById('transfer-form').submit();
                            }
                        });
                    }
                    </script>
                <?php endif; ?>
            <?php else: ?>
                <!-- Scenario A: Switch Home Gym -->
                <div class="settings-transfer-form-wrapper">
                    <h3 class="settings-group-title" style="margin-top:1.5rem;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Switch Home Gym
                    </h3>
                    <p class="muted" style="font-size:13px;margin-bottom:12px;">
                        Since you don't have an active membership, you can freely switch your affiliation. Your historical data stays with your profile.
                    </p>
                    <form method="post" class="form" style="margin-bottom:0;" onsubmit="const btn = this.querySelector('button[type=submit]'); btn.disabled = true; btn.textContent = 'Updating...';">
                        <?= csrf_field() ?>
                        <input type="hidden" name="switch_home_gym" value="1">
                        <div class="settings-select-wrapper">
                            <label class="settings-select-label">New Home Gym</label>
                            <select name="new_gym_id" class="settings-select" required>
                                <option value="">Choose a gym...</option>
                                <?php foreach ($allGyms as $g): if ($homeGymId && (int)$g['gym_id'] === $homeGymId) continue; ?>
                                    <option value="<?= (int) $g['gym_id'] ?>"><?= h($g['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="settings-action-btn primary" style="margin-top:12px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            Set Home Gym
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tab: Preferences -->
    <div class="settings-panel" data-panel="preferences">
        <div class="settings-section">
            <div class="settings-section-header">
                <div>
                    <h2 class="settings-section-title">Preferences</h2>
                    <p class="settings-section-desc">Customize your app experience and visual settings.</p>
                </div>
            </div>
            
            <div class="settings-pref-list">
                <div class="settings-pref-item">
                    <div class="settings-pref-icon" style="background: color-mix(in srgb, var(--lime) 12%, transparent); color: var(--lime);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect width="15" height="14" x="1" y="5" rx="2" ry="2"/></svg>
                    </div>
                    <div class="settings-pref-content">
                        <strong>Video Background</strong>
                        <span class="muted" style="font-size:13px;">Show an animated video loop behind your dashboard.</span>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" id="toggleVideoBg" <?= (!isset($_COOKIE['fittracks_video_bg']) || $_COOKIE['fittracks_video_bg'] !== 'off') ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <?php if ($user['role'] === 'admin'): ?>
    <!-- Tab: System -->
    <div class="settings-panel" data-panel="system">
        <div class="settings-section">
            <div class="settings-section-header">
                <div>
                    <h2 class="settings-section-title">System Settings</h2>
                    <p class="settings-section-desc">Configure global system behaviors, multipliers, and engagement metrics.</p>
                </div>
                <button type="button" class="settings-edit-btn" onclick="document.getElementById('systemSettingsModal').showModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                    Configure
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ==================== MODALS ==================== -->

    <!-- Account Edit Modal -->
    <dialog id="accountModal" class="modal">
        <div class="modal-header">
            <h3>Edit Account Details</h3>
            <button class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
            </button>
        </div>
        <div class="modal-body">
            <form method="post" enctype="multipart/form-data" class="form grid-form" style="margin-bottom:0;" onsubmit="const btn = this.querySelector('button[type=submit]'); btn.disabled = true; btn.innerHTML = '<span class=\'loader\' style=\'width:16px;height:16px;border:2px solid var(--bg);border-bottom-color:transparent;border-radius:50%;display:inline-block;box-sizing:border-box;animation:rotation 1s linear infinite;margin-right:8px;vertical-align:-2px;\'></span> Saving...';">
                <?= csrf_field() ?>
                <input type="hidden" name="update_account" value="1">
                <div style="grid-column:1/-1;display:flex;align-items:center;gap:1rem;margin-bottom:0.5rem;">
                    <?php if (!empty($user['profile_picture'])): ?>
                        <img src="<?= h(upload_url($user['profile_picture'])) ?>" alt="Profile picture" loading="lazy" decoding="async" style="width:60px;height:60px;border-radius:50%;object-fit:cover;">
                    <?php else: ?>
                        <div style="width:60px;height:60px;border-radius:50%;background:var(--panel-soft);display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:var(--muted);"><?= h(initials($user)) ?></div>
                    <?php endif; ?>
                    <label>Profile Picture
                        <input type="file" name="profile_picture" accept="image/*">
                    </label>
                </div>
                <label>First name <input name="first_name" required value="<?= h($user['first_name']) ?>"></label>
                <label>Last name <input name="last_name" required value="<?= h($user['last_name']) ?>"></label>
                <label>Email <input type="email" name="email" required value="<?= h($user['email']) ?>"></label>
                <label>Phone <input name="phone" type="tel" maxlength="11" placeholder="09xxxxxxxxx" value="<?= h($user['phone'] ?? '') ?>" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)"></label>
                <label style="grid-column:1/-1">New password
                    <input type="password" name="password" placeholder="Leave blank to keep current">
                    <small class="muted" style="font-weight:400;">Min. 8 characters, with a letter and a number.</small>
                </label>
                <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:10px;margin-top:4px;">
                    <button type="button" onclick="this.closest('dialog').close()">Cancel</button>
                    <button type="submit" class="btn-primary">Save changes</button>
                </div>
            </form>
        </div>
    </dialog>

    <?php if ($is_member): ?>
    <!-- Physical Profile Edit Modal -->
    <dialog id="physicalProfileModal" class="modal">
        <div class="modal-header">
            <h3>Edit Physical Profile</h3>
            <button class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
            </button>
        </div>
        <div class="modal-body">
            <?php render_member_form('profile', $user, $profile); ?>
        </div>
    </dialog>
    <?php endif; ?>

    <?php if ($user['role'] === 'admin'): ?>
    <dialog id="systemSettingsModal" class="modal">
        <div class="modal-header">
            <h3>Global System Settings</h3>
            <button class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
            </button>
        </div>
        <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
            <form method="post" class="form" style="margin-bottom:0;" onsubmit="const btn = this.querySelector('button[type=submit]'); btn.disabled = true; btn.innerHTML = 'Saving...';">
                <?= csrf_field() ?>
                <input type="hidden" name="update_system_settings" value="1">
                <div class="card" style="padding: 15px; background: rgba(255, 255, 255, 0.02); border: 1px solid var(--line); border-radius: 12px; margin-bottom: 20px;">
                    <h4 style="margin-top: 0; font-size: 1.05rem; color: var(--lime); border-bottom: 1px solid var(--line); padding-bottom: 8px; margin-bottom: 15px;">Engagement Score</h4>
                    <?php 
                    $engagementSettings = array_filter($sysSettings, fn($key) => str_starts_with($key, 'engagement_'), ARRAY_FILTER_USE_KEY);
                    foreach ($engagementSettings as $key => $s): ?>
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-weight: 500; margin-bottom: 4px; color: var(--ink);"><?= h(ucwords(str_replace('_', ' ', str_replace('engagement_weight_', '', $key)))) ?></label>
                            <input type="number" name="settings[<?= h($key) ?>]" value="<?= h($s['setting_value']) ?>" step="any" class="form-control weight-input" style="width: 100%;" required>
                            <?php if ($s['description']): ?><div style="font-size: 0.8rem; color: var(--muted); margin-top: 2px;"><?= h($s['description']) ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <div id="weight-total-display" style="margin-top: 15px; font-weight: bold; padding: 10px; border-radius: 6px; text-align: right; background: rgba(0,0,0,0.2);">Total: <span id="weight-total-val">0</span>%</div>
                </div>
                <div class="card" style="padding: 15px; background: rgba(255, 255, 255, 0.02); border: 1px solid var(--line); border-radius: 12px; margin-bottom: 20px;">
                    <h4 style="margin-top: 0; font-size: 1.05rem; color: var(--lime); border-bottom: 1px solid var(--line); padding-bottom: 8px; margin-bottom: 15px;">Automated Notifications</h4>
                    <?php 
                    $notificationSettings = ['at_risk_inactivity_days', 'at_risk_notification_cooldown'];
                    foreach ($notificationSettings as $key):
                        if (!isset($sysSettings[$key])) continue;
                        $s = $sysSettings[$key];
                    ?>
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-weight: 500; margin-bottom: 4px; color: var(--ink);"><?= h(ucwords(str_replace('_', ' ', str_replace('at_risk_', '', $key)))) ?></label>
                            <input type="number" name="settings[<?= h($key) ?>]" value="<?= h($s['setting_value']) ?>" step="1" class="form-control" style="width: 100%;" required>
                            <?php if ($s['description']): ?><div style="font-size: 0.8rem; color: var(--muted); margin-top: 2px;"><?= h($s['description']) ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-actions" style="margin-top: 1.5rem;">
                    <button type="button" class="btn" style="background:transparent;border:1px solid var(--line);color:var(--ink);" onclick="this.closest('dialog').close()">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background: var(--lime); color: var(--bg); font-weight: bold;">Save Settings</button>
                </div>
            </form>
        </div>
    </dialog>
    <?php endif; ?>

    <!-- ==================== SCRIPTS ==================== -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // ── Tab Navigation ──
        const tabs = document.querySelectorAll('.settings-tab');
        const panels = document.querySelectorAll('.settings-panel');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const target = tab.dataset.tab;
                tabs.forEach(t => t.classList.remove('active'));
                panels.forEach(p => { p.classList.remove('active'); p.style.display = 'none'; });
                tab.classList.add('active');
                const panel = document.querySelector('[data-panel="' + target + '"]');
                if (panel) { 
                    panel.style.display = 'block';
                    // Trigger entrance animation
                    requestAnimationFrame(() => panel.classList.add('active'));
                }
            });
        });
        // Show first panel
        panels.forEach((p, i) => { if (i > 0) p.style.display = 'none'; });

        // ── Video BG Toggle ──
        const toggleVideoBg = document.getElementById('toggleVideoBg');
        if (toggleVideoBg) {
            toggleVideoBg.addEventListener('change', function(e) {
                const isEnabled = e.target.checked;
                document.cookie = "fittracks_video_bg=" + (isEnabled ? "on" : "off") + "; path=/; max-age=31536000";
                const video = document.getElementById('app-bg-video');
                if (!isEnabled && video) {
                    video.pause();
                    video.style.display = 'none';
                } else if (isEnabled) {
                    if (video) { video.style.display = 'block'; video.play(); }
                    else { location.reload(); }
                }
            });
        }

        // ── Weight Total Logic (Admin) ──
        const inputs = document.querySelectorAll('.weight-input');
        const display = document.getElementById('weight-total-val');
        const displayContainer = document.getElementById('weight-total-display');
        function updateTotal() {
            if (!display) return;
            let total = 0;
            inputs.forEach(input => { total += parseInt(input.value) || 0; });
            display.textContent = total;
            if (total === 100) {
                displayContainer.style.color = 'var(--lime)';
                displayContainer.style.border = '1px solid rgba(163, 230, 53, 0.3)';
            } else {
                displayContainer.style.color = '#ef4444';
                displayContainer.style.border = '1px solid rgba(239, 68, 68, 0.3)';
            }
        }
        inputs.forEach(input => input.addEventListener('input', updateTotal));
        updateTotal();
    });
    </script>

    <!-- ==================== STYLES ==================== -->
    <style>
    /* ── Settings Hero ── */
    .settings-hero {
        position: relative;
        border-radius: 16px;
        overflow: hidden;
        margin-bottom: 0;
        border: 1px solid var(--line);
    }
    .settings-hero-bg {
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg, 
            color-mix(in srgb, var(--lime) 12%, var(--bg)),
            color-mix(in srgb, var(--teal) 8%, var(--bg)),
            var(--bg));
        z-index: 0;
    }
    .settings-hero-content {
        position: relative;
        z-index: 1;
        display: flex;
        align-items: center;
        gap: 1.5rem;
        padding: 2rem;
    }
    .settings-hero-avatar {
        position: relative;
        flex-shrink: 0;
    }
    .settings-hero-avatar img,
    .settings-hero-initials {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid color-mix(in srgb, var(--lime) 30%, transparent);
        box-shadow: 0 0 25px color-mix(in srgb, var(--lime) 15%, transparent);
    }
    .settings-hero-initials {
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--lime);
        background: color-mix(in srgb, var(--lime) 10%, var(--bg));
    }
    .settings-avatar-edit {
        position: absolute;
        bottom: 0;
        right: 0;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: var(--lime);
        color: var(--bg);
        border: 2px solid var(--bg);
        display: grid;
        place-items: center;
        cursor: pointer;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .settings-avatar-edit:hover {
        transform: scale(1.1);
        box-shadow: 0 0 12px color-mix(in srgb, var(--lime) 40%, transparent);
    }
    .settings-hero-name {
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0 0 4px;
        letter-spacing: -0.3px;
    }
    .settings-hero-meta {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
    }
    .settings-hero-role {
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        padding: 3px 10px;
        border-radius: 20px;
        background: color-mix(in srgb, var(--lime) 15%, transparent);
        color: var(--lime);
    }
    .settings-hero-dot { color: var(--muted); font-size: 18px; }
    .settings-hero-gym { color: var(--muted); font-size: 13px; }
    .settings-hero-contact {
        display: flex;
        flex-wrap: wrap;
        gap: 14px;
        color: var(--muted);
        font-size: 13px;
    }
    .settings-hero-contact span {
        display: flex;
        align-items: center;
        gap: 5px;
    }

    /* ── Tab Navigation ── */
    .settings-tabs {
        display: flex;
        gap: 0;
        border-bottom: 1px solid var(--line);
        margin-bottom: 0;
        overflow-x: auto;
        scrollbar-width: none;
        -webkit-overflow-scrolling: touch;
    }
    .settings-tabs::-webkit-scrollbar { display: none; }
    .settings-tab {
        display: flex;
        align-items: center;
        gap: 7px;
        padding: 14px 20px;
        border: none;
        background: transparent;
        color: var(--muted);
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        white-space: nowrap;
        position: relative;
        transition: color 0.2s;
    }
    .settings-tab::after {
        content: '';
        position: absolute;
        bottom: -1px;
        left: 0;
        right: 0;
        height: 2px;
        border-radius: 2px 2px 0 0;
        background: transparent;
        transition: background 0.25s;
    }
    .settings-tab:hover { color: var(--ink); }
    .settings-tab.active {
        color: var(--lime);
    }
    .settings-tab.active::after {
        background: var(--lime);
    }

    /* ── Panel Content ── */
    .settings-panel {
        animation: settingsFadeIn 0.35s ease;
    }
    .settings-panel:not(.active) {
        display: none;
    }
    @keyframes settingsFadeIn {
        from { opacity: 0; transform: translateY(8px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .settings-section {
        padding: 2rem 0;
    }
    .settings-section-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 1.5rem;
        gap: 1rem;
    }
    .settings-section-title {
        margin: 0 0 4px;
        font-size: 1.25rem;
        font-weight: 700;
        letter-spacing: -0.3px;
    }
    .settings-section-desc {
        margin: 0;
        color: var(--muted);
        font-size: 13px;
        line-height: 1.5;
    }
    .settings-edit-btn {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        border-radius: 10px;
        border: 1px solid var(--line);
        background: transparent;
        color: var(--ink);
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        flex-shrink: 0;
    }
    .settings-edit-btn:hover {
        border-color: var(--lime);
        color: var(--lime);
        background: color-mix(in srgb, var(--lime) 5%, transparent);
    }

    /* ── Account Info Grid ── */
    .settings-info-grid {
        display: flex;
        flex-direction: column;
        gap: 2px;
        border-radius: 14px;
        overflow: hidden;
        border: 1px solid var(--line);
    }
    .settings-info-row {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px 18px;
        background: color-mix(in srgb, var(--panel-soft) 60%, transparent);
        transition: background 0.2s;
    }
    .settings-info-row:hover {
        background: color-mix(in srgb, var(--panel-soft) 100%, transparent);
    }
    .settings-info-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        background: color-mix(in srgb, var(--lime) 10%, transparent);
        color: var(--lime);
        display: grid;
        place-items: center;
        flex-shrink: 0;
    }
    .settings-info-content {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }
    .settings-info-label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--muted);
        margin-bottom: 2px;
    }
    .settings-info-value {
        font-size: 14px;
        font-weight: 500;
        color: var(--ink);
    }

    /* ── Stats Grid (Physical Profile) ── */
    .settings-group-title {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--muted);
        margin: 0 0 1rem;
    }
    .settings-stat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 12px;
    }
    .settings-stat-card {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 16px;
        border-radius: 12px;
        border: 1px solid var(--line);
        background: color-mix(in srgb, var(--panel-soft) 50%, transparent);
        transition: all 0.25s ease;
    }
    .settings-stat-card:hover {
        border-color: color-mix(in srgb, var(--lime) 25%, transparent);
        background: color-mix(in srgb, var(--panel-soft) 90%, transparent);
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }
    .settings-stat-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: grid;
        place-items: center;
        flex-shrink: 0;
    }
    .settings-stat-body {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }
    .settings-stat-label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--muted);
        margin-bottom: 2px;
    }
    .settings-stat-value {
        font-size: 1.15rem;
        font-weight: 700;
        color: var(--ink);
    }
    .settings-stat-value small {
        font-size: 0.75rem;
        font-weight: 400;
        color: var(--muted);
    }

    /* ── Gym Affiliation Card ── */
    .settings-gym-card {
        border-radius: 14px;
        overflow: hidden;
        border: 1px solid var(--line);
        position: relative;
    }
    .settings-gym-card.has-membership {
        border-color: color-mix(in srgb, var(--lime) 25%, transparent);
    }
    .settings-gym-card-accent {
        height: 4px;
        background: linear-gradient(90deg, var(--lime), var(--teal));
    }
    .settings-gym-card.no-membership .settings-gym-card-accent {
        background: linear-gradient(90deg, var(--muted), color-mix(in srgb, var(--muted) 50%, transparent));
    }
    .settings-gym-card-body {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 20px;
        background: color-mix(in srgb, var(--panel-soft) 60%, transparent);
    }
    .settings-gym-card-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        background: color-mix(in srgb, var(--lime) 10%, transparent);
        color: var(--lime);
        display: grid;
        place-items: center;
        flex-shrink: 0;
    }
    .settings-gym-card-info { min-width: 0; }
    .settings-gym-card-label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--muted);
        display: block;
        margin-bottom: 2px;
    }
    .settings-gym-card-name {
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--ink);
        display: block;
    }
    .settings-gym-card-details {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-top: 6px;
        flex-wrap: wrap;
    }
    .settings-gym-badge {
        font-size: 11px;
        font-weight: 600;
        padding: 3px 10px;
        border-radius: 20px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .settings-gym-badge.active {
        background: color-mix(in srgb, var(--lime) 15%, transparent);
        color: var(--lime);
    }
    .settings-gym-badge.inactive {
        background: color-mix(in srgb, var(--muted) 15%, transparent);
        color: var(--muted);
    }
    .settings-gym-plan, .settings-gym-days {
        font-size: 12px;
        color: var(--muted);
    }

    /* ── Transfer Status ── */
    .settings-transfer-status {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        margin-top: 1.25rem;
        padding: 16px 18px;
        border-radius: 12px;
        background: color-mix(in srgb, var(--orange) 6%, transparent);
        border: 1px solid color-mix(in srgb, var(--orange) 20%, transparent);
    }
    .settings-transfer-status-icon {
        flex-shrink: 0;
        margin-top: 2px;
        animation: pulse-icon 2s ease-in-out infinite;
    }
    @keyframes pulse-icon {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }

    /* ── Select Wrapper ── */
    .settings-select-wrapper { margin-bottom: 0; }
    .settings-select-label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--muted);
        margin-bottom: 6px;
    }
    .settings-select {
        width: 100%;
        padding: 12px 14px;
        border-radius: 10px;
        border: 1px solid var(--line);
        background: color-mix(in srgb, var(--panel-soft) 70%, transparent);
        color: var(--ink);
        font-size: 14px;
        transition: border-color 0.2s;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%238792ad' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
        padding-right: 36px;
    }
    .settings-select:focus {
        outline: none;
        border-color: var(--lime);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--lime) 12%, transparent);
    }

    /* ── Action Buttons ── */
    .settings-action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        padding: 12px 20px;
        border-radius: 10px;
        border: none;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .settings-action-btn.primary {
        background: var(--lime);
        color: var(--bg);
    }
    .settings-action-btn.primary:hover {
        box-shadow: 0 4px 20px color-mix(in srgb, var(--lime) 30%, transparent);
        transform: translateY(-1px);
    }
    .settings-action-btn.danger {
        background: color-mix(in srgb, var(--danger) 15%, transparent);
        color: var(--danger);
        border: 1px solid color-mix(in srgb, var(--danger) 25%, transparent);
    }
    .settings-action-btn.danger:hover {
        background: color-mix(in srgb, var(--danger) 25%, transparent);
        box-shadow: 0 4px 15px color-mix(in srgb, var(--danger) 15%, transparent);
        transform: translateY(-1px);
    }

    /* ── Preference List ── */
    .settings-pref-list {
        display: flex;
        flex-direction: column;
        gap: 2px;
        border-radius: 14px;
        overflow: hidden;
        border: 1px solid var(--line);
    }
    .settings-pref-item {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 16px 18px;
        background: color-mix(in srgb, var(--panel-soft) 60%, transparent);
        transition: background 0.2s;
    }
    .settings-pref-item:hover {
        background: color-mix(in srgb, var(--panel-soft) 100%, transparent);
    }
    .settings-pref-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: grid;
        place-items: center;
        flex-shrink: 0;
    }
    .settings-pref-content {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    /* ── Toggle Switch ── */
    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 48px;
        height: 24px;
        flex-shrink: 0;
    }
    .toggle-switch input { opacity: 0; width: 0; height: 0; }
    .toggle-slider {
        position: absolute;
        cursor: pointer;
        top: 0; left: 0; right: 0; bottom: 0;
        background-color: color-mix(in srgb, var(--muted) 40%, transparent);
        transition: .4s;
        border-radius: 24px;
    }
    .toggle-slider:before {
        position: absolute;
        content: "";
        height: 18px;
        width: 18px;
        left: 3px;
        bottom: 3px;
        background-color: var(--ink);
        transition: .4s cubic-bezier(0.16, 1, 0.3, 1);
        border-radius: 50%;
    }
    input:checked + .toggle-slider {
        background-color: var(--lime);
    }
    input:checked + .toggle-slider:before {
        transform: translateX(24px);
        background-color: var(--bg);
    }

    /* ── Responsive ── */
    @media (max-width: 600px) {
        .settings-hero-content { flex-direction: column; align-items: flex-start; padding: 1.25rem; }
        .settings-hero-avatar img,
        .settings-hero-initials { width: 64px; height: 64px; }
        .settings-hero-name { font-size: 1.2rem; }
        .settings-tab { padding: 12px 14px; font-size: 12px; }
        .settings-stat-grid { grid-template-columns: 1fr 1fr; }
        .settings-section { padding: 1.25rem 0; }
        .settings-section-header { flex-direction: column; }
    }
    </style>
    </div>
    </div>
<?php
    render_footer();
}
