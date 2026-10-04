<?php

declare(strict_types=1);

function profile_page(): void
{
    $user      = require_login();
    $is_member = $user['role'] === 'member';
    $profile   = $is_member ? member_profile((int) $user['user_id']) : null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['update_account'])) {
            $email    = strtolower(trim((string) ($_POST['email']    ?? '')));
            $phone    = preg_replace('/[^0-9]/', '', (string) ($_POST['phone'] ?? ''));
            $fname    = mb_convert_case(trim((string) ($_POST['first_name'] ?? '')), MB_CASE_TITLE, 'UTF-8');
            $lname    = mb_convert_case(trim((string) ($_POST['last_name']  ?? '')), MB_CASE_TITLE, 'UTF-8');
            $password = (string) ($_POST['password'] ?? '');

            $_POST['email'] = $email;
            $_POST['phone'] = $phone;
            $validator = new Validator();
            $valid = $validator->validate($_POST, [
                'first_name' => 'required|min:1|max:100',
                'last_name'  => 'required|min:1|max:100',
                'email'      => 'required|email|max:255',
                'phone'      => 'required|digits:11',
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
                'height_cm'                     => 'numeric|min_num:100|max_num:250',
                'weight_kg'                     => 'numeric|min_num:20|max_num:300',
                'age'                           => 'numeric|min_num:16|max_num:120',
                'neck_cm'                       => 'numeric|min_num:20|max_num:100',
                'waist_cm'                      => 'numeric|min_num:30|max_num:200',
                'hip_cm'                        => 'numeric|min_num:30|max_num:200',
                'chest_cm'                      => 'numeric|min_num:30|max_num:200',
                'arm_cm'                        => 'numeric|min_num:10|max_num:100',
                'target_weight_kg'              => 'numeric|min_num:20|max_num:300',
                'target_body_fat_percent'       => 'numeric|min_num:1|max_num:70',
                'target_arm_cm'                 => 'numeric|min_num:15|max_num:70',
                'target_chest_cm'               => 'numeric|min_num:40|max_num:180',
                'target_waist_cm'               => 'numeric|min_num:30|max_num:200',
                'current_strength_max_kg'       => 'numeric|min_num:0|max_num:500',
                'target_strength_max_kg'        => 'numeric|min_num:1|max_num:500',
                'current_endurance_distance_km' => 'numeric|min_num:0.1|max_num:200',
                'current_endurance_time_mins'   => 'numeric|min_num:1|max_num:1440',
                'target_endurance_distance_km'  => 'numeric|min_num:0.1|max_num:200',
                'target_endurance_time_mins'    => 'numeric|min_num:1|max_num:1440',
                'weekly_workout_target'         => 'numeric|min_num:1|max_num:7',
                'preferred_duration_mins'       => 'numeric|min_num:15|max_num:180',
            ]);
            
            $newGoal = (string) post('primary_goal');
            $currStrength = (float) post('current_strength_max_kg');
            $targStrength = (float) post('target_strength_max_kg');
            if ($valid && $currStrength > 0 && $targStrength > 0 && $targStrength <= $currStrength) {
                $goalCat = resolve_goal_category($newGoal);
                if ($goalCat === 'increasing_strength') {
                    $valid = false;
                    $validator_error = 'Target should be greater than your current maximum.';
                }
            }
            
            if (!$valid) {
                flash($validator_error ?? ($validator->firstError() ?? 'Invalid measurements provided.'), 'danger');
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
            $hasGym = scalar('SELECT 1 FROM gym_members WHERE user_id = ?', [$user['user_id']]);
            if (!$hasMembership && !$hasGym) {
                redirect('gym_selection');
            }
            redirect('profile');
        } elseif (isset($_POST['update_platform_settings']) && $user['role'] === 'platform_admin') {
            $keys = [
                'platform_name', 
                'contact_email', 
                'registration_enabled',
                'at_risk_inactivity_days',
                'at_risk_notification_cooldown'
            ];
            $pdo = db();
            foreach ($keys as $key) {
                if (isset($_POST[$key])) {
                    $pdo->prepare('REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)')
                        ->execute([$key, trim((string)$_POST[$key])]);
                }
            }
            $submittedSettings = array_intersect_key($_POST, array_flip($keys));
            $submittedSettings = array_map(static fn($value) => trim((string)$value), $submittedSettings);
            audit_log((int)$user['user_id'], 'edit', 'platform_settings', null, json_encode($submittedSettings));
            flash('Platform settings updated successfully.', 'success');
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

    // Gym owner subscription, billing metrics & ratings
    $ownerGym = null;
    $ownerGymId = null;
    $gymRevenue = 0.0;
    $platformFee = 0.0;
    $netRevenue = 0.0;
    $ownerTrialInfo = null;
    $gymRatingStats = null;
    $recentGymReviews = [];
    $myPlatformReview = null;
    $platformStats = null;
    $platformRatingStats = null;
    $recentPlatformFeedback = [];
    if (($user['role'] ?? '') === 'platform_admin') {
        $platformRatingStats = get_platform_rating_stats();
        $recentPlatformFeedback = get_platform_reviews(6);
    }
    if (in_array(($user['role'] ?? ''), ['gym_owner', 'admin'], true)) {
        $ownerGym = db()->query('SELECT * FROM gyms WHERE owner_user_id = ' . (int)$user['user_id'])->fetch(PDO::FETCH_ASSOC);
        if (!$ownerGym && !empty($user['gym_id'])) {
            $ownerGym = db()->query('SELECT * FROM gyms WHERE gym_id = ' . (int)$user['gym_id'])->fetch(PDO::FETCH_ASSOC);
        }
        if ($ownerGym) {
            $ownerGymId = (int)$ownerGym['gym_id'];
            $ownerTrialInfo = gym_trial_info($ownerGym);
            $gymRevenue = (float) db()->query(
                'SELECT SUM(revenue) FROM (
                    SELECT p.amount AS revenue FROM payments p JOIN memberships m ON m.membership_id = p.membership_id JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE mp.gym_id = ' . $ownerGymId . ' AND p.status = "paid" AND p.payment_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01")
                    UNION ALL
                    SELECT amount_paid AS revenue FROM walk_in_transactions WHERE gym_id = ' . $ownerGymId . ' AND visit_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01")
                ) AS combined'
            )->fetchColumn();
            $platformFee = $gymRevenue * 0.01;
            $netRevenue = $gymRevenue - $platformFee;

            $gymRatingStats   = get_gym_rating_stats($ownerGymId);
            $recentGymReviews = get_gym_reviews($ownerGymId, 5);
        }
        $myPlatformReview = get_owner_platform_review((int)$user['user_id']);
        $platformStats    = get_platform_rating_stats();
    }

    render_header('Settings', $user);

    // Fetch membership info for gym affiliation display
    $membershipInfo = null;
    if ($is_member && $currentGymId) {
        $membershipInfo = db()->query("
            SELECT m.*, mp.plan_name as plan_name, mp.duration_days, m.end_date,
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
        <?php if ($user['role'] === 'platform_admin'): ?>
        <button class="settings-tab" data-tab="platform_settings">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            Platform Settings
        </button>
        <button class="settings-tab" data-tab="platform_feedback">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            Feedback
        </button>
        <?php endif; ?>
        <?php if ($user['role'] === 'gym_owner'): ?>
        <button class="settings-tab" data-tab="subscription">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
            Subscription & Billing
        </button>
        <?php endif; ?>
        <button class="settings-tab" data-tab="preferences">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            Preferences
        </button>
        <?php if (in_array(($user['role'] ?? ''), ['gym_owner', 'admin'], true)): ?>
        <button class="settings-tab" data-tab="ratings_feedback">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            Rating &amp; Feedback
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
                <div style="display: flex; gap: 8px; align-items: center;">
                    <a href="index.php?page=setup_review" class="settings-edit-btn" style="text-decoration:none;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                        Review Setup
                    </a>
                    <button type="button" class="settings-edit-btn" onclick="document.getElementById('physicalProfileModal').showModal()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                        Edit
                    </button>
                </div>
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
                <?php if (!empty($profile['chest_cm'])): ?>
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, #60a5fa 15%, transparent); color: #60a5fa;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" x2="22" y1="12" y2="12"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Chest</span>
                        <span class="settings-stat-value"><?= h(number_format((float)$profile['chest_cm'], 1)) ?> <small>cm</small></span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($profile['arm_cm'])): ?>
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, #a78bfa 15%, transparent); color: #a78bfa;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Arm</span>
                        <span class="settings-stat-value"><?= h(number_format((float)$profile['arm_cm'], 1)) ?> <small>cm</small></span>
                    </div>
                </div>
                <?php endif; ?>
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

            <!-- Targets & Objectives -->
            <?php 
            $hasAnyTarget = !empty($profile['target_strength_max_kg']) || 
                            !empty($profile['target_weight_kg']) || 
                            !empty($profile['target_body_fat_percent']) || 
                            !empty($profile['weekly_workout_target']) || 
                            !empty($profile['target_endurance_distance_km']) || 
                            !empty($profile['target_endurance_time_mins']) || 
                            !empty($profile['target_arm_cm']) || 
                            !empty($profile['target_chest_cm']) || 
                            !empty($profile['target_waist_cm']);
            if ($hasAnyTarget):
            ?>
            <h3 class="settings-group-title" style="margin-top: 2rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                Targets & Objectives
            </h3>
            <div class="settings-stat-grid">
                <?php if (!empty($profile['target_strength_max_kg'])): 
                    $stEx = $profile['target_exercise'] ?? 'Bench Press';
                ?>
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, var(--lime) 15%, transparent); color: var(--lime);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6.5 6.5 11 11"/><path d="m21 21-1-1"/><path d="m3 3 1 1"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/><path d="m3 10 7-7"/><path d="m14 21 7-7"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Strength Target (<?= h($stEx) ?>)</span>
                        <span class="settings-stat-value"><?= h(number_format((float)$profile['target_strength_max_kg'], 1)) ?> <small>kg PR</small></span>
                        <?php if (!empty($profile['current_strength_max_kg'])): ?>
                            <span class="settings-stat-hint" style="font-size: 11px; color: var(--muted);">Current: <?= h(number_format((float)$profile['current_strength_max_kg'], 1)) ?> kg</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($profile['target_weight_kg'])): ?>
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, var(--orange) 15%, transparent); color: var(--orange);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Target Weight</span>
                        <span class="settings-stat-value"><?= h(number_format((float)$profile['target_weight_kg'], 1)) ?> <small>kg</small></span>
                        <?php if (!empty($profile['weight_kg'])): 
                            $diff = (float)$profile['target_weight_kg'] - (float)$profile['weight_kg'];
                        ?>
                            <span class="settings-stat-hint" style="font-size: 11px; color: var(--muted);"><?= $diff > 0 ? '+' : '' ?><?= number_format($diff, 1) ?> kg from current</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($profile['target_body_fat_percent'])): ?>
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, #f43f5e 15%, transparent); color: #f43f5e;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Target Body Fat</span>
                        <span class="settings-stat-value"><?= h(number_format((float)$profile['target_body_fat_percent'], 1)) ?> <small>%</small></span>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($profile['weekly_workout_target'])): ?>
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, var(--lime) 15%, transparent); color: var(--lime);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Workout Frequency</span>
                        <span class="settings-stat-value"><?= (int)$profile['weekly_workout_target'] ?> <small>days / wk</small></span>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($profile['preferred_duration_mins'])): ?>
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, var(--lime) 15%, transparent); color: var(--lime);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Session Duration</span>
                        <span class="settings-stat-value"><?= (int)$profile['preferred_duration_mins'] ?> <small>mins</small></span>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($profile['target_endurance_distance_km']) || !empty($profile['target_endurance_time_mins'])): 
                    $act = $profile['endurance_activity'] ?? 'Running';
                ?>
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, var(--teal) 15%, transparent); color: var(--teal);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Endurance (<?= h($act) ?>)</span>
                        <span class="settings-stat-value">
                            <?= !empty($profile['target_endurance_distance_km']) ? h(number_format((float)$profile['target_endurance_distance_km'], 1)) . ' <small>km</small>' : '' ?>
                            <?= (!empty($profile['target_endurance_distance_km']) && !empty($profile['target_endurance_time_mins'])) ? ' / ' : '' ?>
                            <?= !empty($profile['target_endurance_time_mins']) ? h($profile['target_endurance_time_mins']) . ' <small>min</small>' : '' ?>
                        </span>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($profile['target_arm_cm'])): ?>
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, #a78bfa 15%, transparent); color: #a78bfa;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Target Arm Size</span>
                        <span class="settings-stat-value"><?= h(number_format((float)$profile['target_arm_cm'], 1)) ?> <small>cm</small></span>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($profile['target_chest_cm'])): ?>
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, #60a5fa 15%, transparent); color: #60a5fa;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" x2="22" y1="12" y2="12"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Target Chest Size</span>
                        <span class="settings-stat-value"><?= h(number_format((float)$profile['target_chest_cm'], 1)) ?> <small>cm</small></span>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($profile['target_waist_cm'])): ?>
                <div class="settings-stat-card">
                    <div class="settings-stat-icon" style="background: color-mix(in srgb, #f472b6 15%, transparent); color: #f472b6;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 18 0 9 9 0 0 0-18 0"/><path d="M12 8v8"/></svg>
                    </div>
                    <div class="settings-stat-body">
                        <span class="settings-stat-label">Target Waist Size</span>
                        <span class="settings-stat-value"><?= h(number_format((float)$profile['target_waist_cm'], 1)) ?> <small>cm</small></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
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
                            View your active gym membership details.
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

            <?php if (!$currentGymId): ?>
                <!-- Switch Home Gym -->
                <div class="settings-homegym-form-wrapper">
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

    <?php if ($user['role'] === 'platform_admin'): ?>
    <!-- Tab: Platform Settings -->
    <div class="settings-panel" data-panel="platform_settings">
        <div class="settings-section">
            <div class="settings-section-header">
                <div>
                    <h2 class="settings-section-title">Platform Settings</h2>
                    <p class="settings-section-desc">Manage global platform configurations, support contact, and registration availability.</p>
                </div>
            </div>

            <div class="platform-settings-cards">
                <article class="platform-settings-card">
                    <div class="platform-settings-card-mark" aria-hidden="true">01</div>
                    <div class="platform-settings-card-copy">
                        <span class="platform-settings-eyebrow">CORE CONFIGURATION</span>
                        <h3>General platform</h3>
                        <p>Manage your platform identity, support contact, and new account registration.</p>
                    </div>
                    <button type="button" class="platform-settings-open" onclick="document.getElementById('generalPlatformSettingsModal').showModal()">Configure <span aria-hidden="true">&rarr;</span></button>
                </article>
                <article class="platform-settings-card">
                    <div class="platform-settings-card-mark" aria-hidden="true">02</div>
                    <div class="platform-settings-card-copy">
                        <span class="platform-settings-eyebrow">MEMBER ENGAGEMENT</span>
                        <h3>Inactive member reminders</h3>
                        <p>Set when members are flagged inactive and the wait between reminder messages.</p>
                    </div>
                    <button type="button" class="platform-settings-open" onclick="document.getElementById('inactiveMemberSettingsModal').showModal()">Configure <span aria-hidden="true">&rarr;</span></button>
                </article>
            </div>

            <dialog id="generalPlatformSettingsModal" class="modal platform-settings-modal" onclick="if (event.target === this) this.close();">
                <div class="modal-header">
                    <div><span class="platform-settings-eyebrow">CORE CONFIGURATION</span><h3>General platform</h3></div>
                    <button type="button" class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <form method="post" class="form grid-form platform-settings-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="update_platform_settings" value="1">
                        <label>Platform Name<input type="text" name="platform_name" value="<?= h($sysSettings['platform_name']['setting_value'] ?? 'FITTRACKS') ?>" required></label>
                        <label>Support Contact Email<input type="email" name="contact_email" value="<?= h($sysSettings['contact_email']['setting_value'] ?? 'support@fittracks.com') ?>" required></label>
                        <label class="platform-settings-full">New account registration
                            <select name="registration_enabled">
                                <option value="1" <?= ($sysSettings['registration_enabled']['setting_value'] ?? '1') === '1' ? 'selected' : '' ?>>Open - new gyms and members can register</option>
                                <option value="0" <?= ($sysSettings['registration_enabled']['setting_value'] ?? '1') === '0' ? 'selected' : '' ?>>Closed - pause new registrations</option>
                            </select>
                        </label>
                        <div class="platform-settings-modal-actions">
                            <button type="button" class="platform-settings-cancel" onclick="this.closest('dialog').close()">Cancel</button>
                            <button type="submit" class="btn-primary">Save changes</button>
                        </div>
                    </form>
                </div>
            </dialog>

            <dialog id="inactiveMemberSettingsModal" class="modal platform-settings-modal" onclick="if (event.target === this) this.close();">
                <div class="modal-header">
                    <div><span class="platform-settings-eyebrow">MEMBER ENGAGEMENT</span><h3>Inactive member reminders</h3></div>
                    <button type="button" class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <form method="post" class="form grid-form platform-settings-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="update_platform_settings" value="1">
                        <label>Inactivity threshold (days)
                            <input type="number" min="1" max="90" name="at_risk_inactivity_days" value="<?= h($sysSettings['at_risk_inactivity_days']['setting_value'] ?? '3') ?>" required>
                            <span class="platform-settings-help">Days without a check-in before a member is flagged as inactive.</span>
                        </label>
                        <label>Reminder cooldown (days)
                            <input type="number" min="1" max="180" name="at_risk_notification_cooldown" value="<?= h($sysSettings['at_risk_notification_cooldown']['setting_value'] ?? '14') ?>" required>
                            <span class="platform-settings-help">Minimum wait before sending another reminder to the same member.</span>
                        </label>
                        <div class="platform-settings-modal-actions">
                            <button type="button" class="platform-settings-cancel" onclick="this.closest('dialog').close()">Cancel</button>
                            <button type="submit" class="btn-primary">Save changes</button>
                        </div>
                    </form>
                </div>
            </dialog>

        </div>
    </div>
    <?php endif; ?>

    <?php if ($user['role'] === 'platform_admin'): ?>
    <!-- Tab: Platform Feedback -->
    <div class="settings-panel" data-panel="platform_feedback">
        <div class="settings-section">
            <div class="settings-section-header" style="margin-top:2rem;">
                <div>
                    <h2 class="settings-section-title">Commercial Gym Owner Feedback</h2>
                    <p class="settings-section-desc">Live ratings submitted by gym operators regarding system usability, features, and platform services.</p>
                </div>
                <a href="index.php?page=landing#testimonials" target="_blank" rel="noopener" class="btn btn-secondary" style="font-size:12px; padding:5px 12px; text-decoration:none;">View Live on Landing Page &rarr;</a>
            </div>
            <div class="platform-rating-summary">
                <div class="platform-rating-overall">
                    <div class="platform-rating-number"><?= number_format($platformRatingStats['avg_rating'], 1) ?></div>
                    <div>
                        <div class="platform-rating-title"><?= render_star_rating((float)$platformRatingStats['avg_rating'], 16, false) ?><strong>Overall Platform Score</strong></div>
                        <span style="font-size:12px; color:var(--muted);">Based on <?= (int)$platformRatingStats['total_reviews'] ?> verified reviews from approved gym owners</span>
                    </div>
                </div>
                <div class="platform-rating-metrics">
                    <?php foreach (['System UX' => 'avg_system', 'Features' => 'avg_features', 'Service Quality' => 'avg_service'] as $label => $key): ?>
                        <div style="text-align:center; padding:0 10px;"><div style="font-size:17px; font-weight:800; color:var(--lime);"><?= number_format($platformRatingStats[$key], 1) ?>★</div><div style="font-size:11px; color:var(--muted); text-transform:uppercase;"><?= h($label) ?></div></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(min(320px, 100%), 1fr)); gap:14px;">
                <?php foreach ($recentPlatformFeedback as $pf): ?>
                    <div style="background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.04); border-radius:10px; padding:14px; display:flex; flex-direction:column; justify-content:space-between;">
                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;"><div><strong style="font-size:13.5px; display:block; color:#fff;"><?= h($pf['gym_name'] ?? 'Commercial Gym') ?></strong><small style="font-size:11.5px; color:var(--muted);"><?= h(($pf['first_name'] ?? '') . ' ' . ($pf['last_name'] ?? '')) ?> (Owner)</small></div><?= render_star_rating((float)$pf['rating'], 14, false) ?></div>
                            <?php if (!empty($pf['review'])): ?><p style="font-size:12.5px; line-height:1.45; color:rgba(255,255,255,0.85); margin:0 0 10px; font-style:italic;">&ldquo;<?= h($pf['review']) ?>&rdquo;</p><?php endif; ?>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center; font-size:11px; color:var(--muted); border-top:1px solid rgba(255,255,255,0.04); padding-top:8px; margin-top:6px;"><div style="display:flex; gap:8px;"><span>UX: <b style="color:var(--lime);"><?= (int)($pf['system_experience'] ?? 5) ?>★</b></span><span>Feat: <b style="color:var(--lime);"><?= (int)($pf['features_rating'] ?? 5) ?>★</b></span><span>Serv: <b style="color:var(--lime);"><?= (int)($pf['service_rating'] ?? 5) ?>★</b></span></div><time><?= h(date('M d, Y', strtotime($pf['created_at']))) ?></time></div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$recentPlatformFeedback): ?><p class="muted" style="grid-column:1/-1; text-align:center; padding:20px;">No platform reviews submitted yet.</p><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($user['role'] === 'gym_owner' && $ownerGym): 
        $ownerGymId = (int)$ownerGym['gym_id'];
        $subPlan = $ownerGym['subscription_plan'] ?: 'No Active Plan';
        $subStatus = $ownerGym['subscription_status'] ?: 'inactive';
        $subRenewal = !empty($ownerGym['subscription_renewal_date']) ? date('M j, Y', strtotime($ownerGym['subscription_renewal_date'])) : 'N/A';
        
        $allPlatformPlans = get_platform_subscription_plans();
        $matchedPlan = null;
        foreach ($allPlatformPlans as $p) {
            if (strtolower($p['name']) === strtolower((string)$subPlan) || strtolower($p['key']) === strtolower((string)$subPlan)) {
                $matchedPlan = $p;
                break;
            }
        }

        $badgeBg = 'rgba(255, 255, 255, 0.08)';
        $badgeColor = 'var(--ink)';
        $badgeText = ucfirst((string)$subStatus);
        $subCta = 'Choose Subscription';

        if ($ownerTrialInfo['is_trial_active']) {
            $badgeBg = 'rgba(132, 204, 22, 0.15)';
            $badgeColor = 'var(--lime, #84cc16)';
            $badgeText = 'Free Trial (' . $ownerTrialInfo['days_left'] . 'd left)';
            $subCta = 'Upgrade Plan';
        } elseif ($subStatus === 'active') {
            $badgeBg = 'color-mix(in srgb, var(--teal) 15%, transparent)';
            $badgeColor = 'var(--teal)';
            $badgeText = 'Active Paid';
            $subCta = 'Manage Subscription';
        } elseif ($ownerTrialInfo['is_free']) {
            $badgeBg = 'rgba(255, 255, 255, 0.08)';
            $badgeColor = 'var(--muted)';
            $badgeText = 'Free Tier (Limited)';
            $subCta = 'Upgrade to Unlock Full Features';
        }

        $memberLimit = gym_member_limit($ownerGym);
        $activeMemberCount = $ownerGymId ? gym_active_member_count($ownerGymId) : 0;
        $usagePercent = ($memberLimit > 0 && $memberLimit !== PHP_INT_MAX) 
            ? min(100, round(($activeMemberCount / $memberLimit) * 100)) 
            : null;
        $trainerLimit = gym_trainer_limit($ownerGym);
        $activeTrainerCount = $ownerGymId ? gym_active_trainer_count($ownerGymId) : 0;
    ?>
    <!-- Tab: Subscription & Billing -->
    <div class="settings-panel" data-panel="subscription">
        <div class="settings-section">
            <div class="settings-section-header">
                <div>
                    <h2 class="settings-section-title">Subscription & Platform Fees</h2>
                    <p class="settings-section-desc">Manage your commercial subscription, member/trainer limits, and platform fee breakdown.</p>
                </div>
                <a href="index.php?page=gym_subscription" class="btn btn-primary" style="padding: 8px 18px; border-radius: 10px; font-weight: 700; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
                    <span><?= h($subCta) ?></span>
                    <span>&rarr;</span>
                </a>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-top: 10px;">
                <!-- Card 1: Subscription & Plan -->
                <div style="border-radius: 14px; border: 1px solid var(--line); background: color-mix(in srgb, var(--panel-soft) 60%, transparent); padding: 22px; display: flex; flex-direction: column;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; gap: 10px;">
                        <div>
                            <span style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); font-weight: 700;">Current Plan</span>
                            <h3 style="margin: 4px 0 0; font-size: 1.25rem; font-weight: 800; color: var(--ink);">
                                <?php if ($ownerTrialInfo['is_trial_active']): ?>
                                    Free Trial (50 Members &bull; 2 Trainers)
                                <?php elseif ($ownerTrialInfo['is_free']): ?>
                                    Free Tier
                                <?php else: ?>
                                    <?= h($subPlan) ?><?= $subPlan !== 'No Active Plan' ? ' Plan' : '' ?>
                                <?php endif; ?>
                            </h3>
                        </div>
                        <span class="badge" style="background: <?= $badgeBg ?>; color: <?= $badgeColor ?>; font-weight: 700; font-size: 11px; padding: 4px 10px; border-radius: 20px; white-space: nowrap;">
                            <?= h($badgeText) ?>
                        </span>
                    </div>

                    <p style="color: var(--muted); font-size: 13px; line-height: 1.5; margin: 0 0 16px;">
                        <?php if ($ownerTrialInfo['is_trial_active']): ?>
                            Evaluation trial active &bull; Uninterrupted access to classes, workouts, and reporting.
                        <?php elseif ($ownerTrialInfo['is_free']): ?>
                            Basic operations only (QR scanner, check-in, memberships, up to 25 members).
                        <?php elseif ($matchedPlan): ?>
                            <?= h($matchedPlan['price_label']) ?> / month &bull; <?= h($matchedPlan['desc']) ?>
                        <?php else: ?>
                            <?= h($subPlan) ?>
                        <?php endif; ?>
                    </p>

                    <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 18px; font-size: 12.5px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--muted); flex-shrink: 0;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <?php if ($ownerTrialInfo['is_trial_active']): ?>
                            <span style="color: var(--lime); font-weight: 600;">Trial ends on <?= h($subRenewal) ?></span>
                        <?php elseif ($subRenewal !== 'N/A' && $subStatus === 'active'): ?>
                            <span style="color: var(--muted);">Renews on <?= h($subRenewal) ?></span>
                        <?php else: ?>
                            <span style="color: var(--muted);">No active expiration date</span>
                        <?php endif; ?>
                    </div>

                    <!-- Member & Trainer Capacity Progress -->
                    <div style="margin-top: auto; padding: 14px; border-radius: 10px; background: rgba(255,255,255,0.03); border: 1px solid var(--line);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; font-size: 13px;">
                            <span style="color: var(--muted);">Member Capacity</span>
                            <strong style="color: var(--ink);">
                                <?= $activeMemberCount ?> / <?= $memberLimit === PHP_INT_MAX ? '∞ Unlimited' : $memberLimit ?>
                                <?= $usagePercent !== null ? "({$usagePercent}%)" : '' ?>
                            </strong>
                        </div>
                        <?php if ($usagePercent !== null): ?>
                            <div style="width: 100%; height: 6px; background: rgba(255,255,255,0.08); border-radius: 3px; overflow: hidden; margin-bottom: 10px;">
                                <div style="width: <?= $usagePercent ?>%; height: 100%; background: <?= $usagePercent >= 90 ? 'var(--danger, #ef4444)' : 'var(--lime, #84cc16)' ?>; transition: width 0.3s ease;"></div>
                            </div>
                        <?php endif; ?>

                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(255,255,255,0.06); font-size: 12.5px;">
                            <span style="color: var(--muted);">Trainer Capacity</span>
                            <strong style="color: var(--ink);">
                                <?php if ($trainerLimit === PHP_INT_MAX): ?>
                                    <?= $activeTrainerCount ?> / ∞ Unlimited
                                <?php elseif ($trainerLimit > 0): ?>
                                    <?= $activeTrainerCount ?> / <?= $trainerLimit ?>
                                <?php else: ?>
                                    0 (Locked &bull; Pro only)
                                <?php endif; ?>
                            </strong>
                        </div>
                    </div>
                </div>

                <!-- Card 2: Platform Earnings / Fees -->
                <div style="border-radius: 14px; border: 1px solid var(--line); background: color-mix(in srgb, var(--panel-soft) 60%, transparent); padding: 22px; display: flex; flex-direction: column;">
                    <span style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); font-weight: 700; margin-bottom: 4px;">Billing & Revenue</span>
                    <h3 style="margin: 0 0 16px; font-size: 1.25rem; font-weight: 800; color: var(--ink);">Platform Earnings / Fees</h3>

                    <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 16px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 10px; border-bottom: 1px solid var(--line); font-size: 13.5px;">
                            <span style="color: var(--muted);">Total Revenue This Month:</span>
                            <strong style="color: var(--ink);"><?= money($gymRevenue) ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 10px; border-bottom: 1px solid var(--line); font-size: 13.5px;">
                            <span style="color: var(--danger, #ef4444);">Transaction Fees (1%):</span>
                            <strong style="color: var(--danger, #ef4444);">-<?= money($platformFee) ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 16px; padding-top: 4px;">
                            <span style="font-weight: 700; color: var(--ink);">Gym Net Amount:</span>
                            <strong style="color: var(--teal); font-size: 1.2rem;"><?= money($netRevenue) ?></strong>
                        </div>
                    </div>

                    <div style="margin-top: auto; padding: 12px; border-radius: 10px; background: rgba(255,255,255,0.03); border: 1px solid var(--line);">
                        <p style="font-size: 12px; color: var(--muted); margin: 0; line-height: 1.45;">
                            FitTrack platform fees are calculated as 1% of your recorded revenue (both online GCash and cash walk-ins).
                        </p>
                    </div>
                </div>
            </div>
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

    <!-- Tab: Rating & Feedback -->
    <?php if (in_array(($user['role'] ?? ''), ['gym_owner', 'admin'], true)): ?>
    <div class="settings-panel" data-panel="ratings_feedback">
        <div class="settings-section">
            <div class="settings-section-header" style="flex-wrap:wrap; gap:12px;">
                <div>
                    <h2 class="settings-section-title" style="display:flex; align-items:center; gap:8px;">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--lime);"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        Two-Way Rating &amp; Feedback Hub
                    </h2>
                    <p class="settings-section-desc">
                        Track member ratings for your gym &bull; Share your feedback on the FitTrack platform
                    </p>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <span class="rating-badge-active">
                        Two-Way Separation Active
                    </span>
                </div>
            </div>

            <!-- Two-Way Grid -->
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap:22px; margin-top:20px;">
                <!-- Card 1: Members -> Your Gym -->
                <div class="rating-hub-card">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px;">
                        <div>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <span class="rating-badge-gym">Members &rarr; Your Gym</span>
                            </div>
                            <h3 style="font-size:17px; font-weight:700; margin:6px 0 0 0; color:var(--ink);">Member Ratings &amp; Reviews</h3>
                            <p style="font-size:12px; color:var(--muted); margin:2px 0 0 0;">Ratings contributed exclusively by your enrolled gym members</p>
                        </div>
                        <?php if ($ownerGymId): ?>
                        <a href="index.php?page=gym_profile#sec-ratings" class="btn btn-secondary" style="font-size:12px; padding:5px 12px; text-decoration:none; white-space:nowrap;">View All &rarr;</a>
                        <?php endif; ?>
                    </div>

                    <?php if ($gymRatingStats && $ownerGymId): ?>
                        <!-- Score Summary Box -->
                        <div class="rating-summary-box">
                            <div style="text-align:center; min-width:85px;">
                                <div class="rating-big-num">
                                    <?= number_format($gymRatingStats['avg_rating'], 1) ?>
                                </div>
                                <div style="margin-top:6px; display:flex; justify-content:center;">
                                    <?= render_star_rating($gymRatingStats['avg_rating'], 14, false) ?>
                                </div>
                                <div style="font-size:11px; color:var(--muted); margin-top:4px;">
                                    <?= $gymRatingStats['total_reviews'] ?> <?= $gymRatingStats['total_reviews'] === 1 ? 'review' : 'reviews' ?>
                                </div>
                            </div>

                            <!-- Star Breakdown Progress Bars -->
                            <div class="rating-summary-divider">
                                <?php for ($s = 5; $s >= 1; $s--): 
                                    $pct = $gymRatingStats['breakdown_pct'][$s] ?? 0;
                                    $cnt = $gymRatingStats['breakdown'][$s] ?? 0;
                                ?>
                                    <div style="display:flex; align-items:center; gap:8px; font-size:11px;">
                                        <span style="width:20px; color:var(--muted); text-align:right; font-weight:600;"><?= $s ?>★</span>
                                        <div class="rating-bar-track">
                                            <div style="height:100%; width:<?= $pct ?>%; background:linear-gradient(90deg, #f59e0b, #fbbf24); border-radius:999px;"></div>
                                        </div>
                                        <span style="width:22px; color:var(--muted); font-size:10px;"><?= $cnt ?></span>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <!-- Recent Member Reviews Feed -->
                        <div style="flex:1; display:flex; flex-direction:column;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                                <div style="font-size:12px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.5px;">
                                    Recent Member Reviews
                                </div>
                                <?php if (!empty($recentGymReviews)): ?>
                                    <span style="font-size:11px; color:var(--muted); font-weight:600;">
                                        Latest <?= count($recentGymReviews) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="rating-reviews-scroll-list" style="display:flex; flex-direction:column; gap:10px; max-height:240px; overflow-y:auto; padding-right:4px;">
                                <?php if (!empty($recentGymReviews)): ?>
                                    <?php foreach ($recentGymReviews as $rev): ?>
                                        <div class="rating-review-card">
                                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                                <div style="display:flex; align-items:center; gap:8px;">
                                                    <?= render_avatar($rev) ?>
                                                    <div>
                                                        <strong style="font-size:12.5px; display:block; color:var(--ink);"><?= h(($rev['first_name'] ?? '') . ' ' . ($rev['last_name'] ?? '')) ?></strong>
                                                        <span style="font-size:10.5px; color:var(--muted);"><?= h(date('M d, Y', strtotime($rev['created_at']))) ?></span>
                                                    </div>
                                                </div>
                                                <div>
                                                    <?= render_star_rating((float)$rev['rating'], 13, false) ?>
                                                </div>
                                            </div>
                                            <?php if (!empty($rev['review'])): ?>
                                                <p class="rating-review-quote">
                                                    &ldquo;<?= h($rev['review']) ?>&rdquo;
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="rating-empty-box">
                                        <p style="font-size:13px; color:var(--muted); margin:0; font-weight:500;">No member reviews yet.</p>
                                        <span style="font-size:11.5px; color:var(--muted); display:block; margin-top:4px;">When your enrolled members rate your gym in their portal, their feedback will show here.</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($ownerGymId && ($gymRatingStats['total_reviews'] ?? 0) > 0): ?>
                                <div style="margin-top:12px; padding-top:10px; border-top:1px solid var(--line);">
                                    <a href="index.php?page=gym_profile#sec-ratings" class="btn btn-secondary btn-sm" style="width:100%; justify-content:center; font-size:12px; gap:6px;">
                                        View All Member Reviews (<?= (int)$gymRatingStats['total_reviews'] ?>) &rarr;
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="rating-empty-box" style="padding:32px 16px;">
                            <p style="font-size:13px; color:var(--muted); margin:0;">No gym associated yet.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Card 2: Gym Owner -> FitTrack Platform -->
                <div id="platform-feedback-card" class="rating-hub-card rating-platform-card">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px;">
                        <div>
                            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                <span class="rating-badge-owner">Gym Owner &rarr; FitTrack</span>
                                <span class="rating-badge-featured">
                                    Featured on Landing Page
                                </span>
                            </div>
                            <h3 style="font-size:17px; font-weight:700; margin:6px 0 0 0; color:var(--ink);">Rate the FitTrack Platform</h3>
                            <p style="font-size:12px; color:var(--muted); margin:2px 0 0 0;">
                                Your rating &amp; testimonial appear on the FitTrack landing page to help other gym owners!
                            </p>
                        </div>
                        <?php if ($myPlatformReview): ?>
                            <span class="rating-badge-live">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                Feedback Live
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Existing Review Summary / Toggle if already reviewed -->
                    <?php if ($myPlatformReview): ?>
                        <div id="platform-review-view" class="rating-existing-review">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <?= render_star_rating((float)$myPlatformReview['rating'], 16, true) ?>
                                    <span style="font-size:11px; color:var(--muted);">&bull; Submitted <?= h(date('M d, Y', strtotime($myPlatformReview['updated_at'] ?? $myPlatformReview['created_at']))) ?></span>
                                </div>
                                <button type="button" onclick="togglePlatformReviewEdit(true)" class="btn btn-secondary" style="font-size:11px; padding:2px 8px;">
                                    Edit Feedback &#9998;
                                </button>
                            </div>
                            <?php if (!empty($myPlatformReview['review'])): ?>
                                <p style="font-size:13px; line-height:1.45; margin:0 0 8px 0; font-style:italic;" class="rating-review-quote">
                                    &ldquo;<?= h($myPlatformReview['review']) ?>&rdquo;
                                </p>
                            <?php endif; ?>
                            <div class="rating-meta-row" style="display:flex; gap:12px; font-size:11px; color:var(--muted); flex-wrap:wrap; border-top:1px solid rgba(255,255,255,0.06); padding-top:8px;">
                                <?php if (!empty($myPlatformReview['system_experience'])): ?>
                                    <span>System UX: <strong style="color:#f59e0b;"><?= (int)$myPlatformReview['system_experience'] ?>★</strong></span>
                                <?php endif; ?>
                                <?php if (!empty($myPlatformReview['features_rating'])): ?>
                                    <span>Features: <strong style="color:#f59e0b;"><?= (int)$myPlatformReview['features_rating'] ?>★</strong></span>
                                <?php endif; ?>
                                <?php if (!empty($myPlatformReview['service_rating'])): ?>
                                    <span>Service: <strong style="color:#f59e0b;"><?= (int)$myPlatformReview['service_rating'] ?>★</strong></span>
                                <?php endif; ?>
                                <a href="index.php?page=landing#testimonials" target="_blank" class="rating-landing-link" style="margin-left:auto; text-decoration:none; font-weight:600;">
                                    View on Landing Page &rarr;
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Platform Review Form -->
                    <form id="platform-review-form" method="POST" action="index.php" style="<?= $myPlatformReview ? 'display:none;' : '' ?> flex:1; display:<?= $myPlatformReview ? 'none' : 'flex' ?>; flex-direction:column; gap:12px;">
                        <input type="hidden" name="action" value="submit_platform_review">
                        <input type="hidden" name="submit_platform_review" value="1">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="gym_id" value="<?= (int)($ownerGymId ?? 0) ?>">
                        <input type="hidden" name="redirect_to" value="index.php?page=profile&tab=ratings_feedback#platform-feedback-card">
                        <input type="hidden" name="rating" id="owner-overall-rating" value="<?= (int)($myPlatformReview['rating'] ?? 5) ?>">
                        <input type="hidden" name="system_experience" id="owner-system-rating" value="<?= (int)($myPlatformReview['system_experience'] ?? 5) ?>">
                        <input type="hidden" name="features_rating" id="owner-features-rating" value="<?= (int)($myPlatformReview['features_rating'] ?? 5) ?>">
                        <input type="hidden" name="service_rating" id="owner-service-rating" value="<?= (int)($myPlatformReview['service_rating'] ?? 5) ?>">

                        <!-- Overall Platform Rating Selector -->
                        <div>
                            <label class="rating-field-label" style="font-size:12px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.5px; display:block; margin-bottom:6px;">
                                Overall FitTrack Platform Rating <span style="color:var(--danger)">*</span>
                            </label>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <div id="owner-star-picker" style="display:flex; gap:4px; cursor:pointer;">
                                    <?php 
                                    $currOverall = (int)($myPlatformReview['rating'] ?? 5);
                                    for ($i = 1; $i <= 5; $i++): 
                                        $isFilled = $i <= $currOverall;
                                    ?>
                                        <button type="button" class="owner-star-btn" data-val="<?= $i ?>" onclick="setPlatformRating('overall', <?= $i ?>)" style="background:none; border:none; padding:2px; cursor:pointer; color:<?= $isFilled ? '#f59e0b' : 'var(--star-unfilled, #4b5563)' ?>; transition:transform 0.15s ease, color 0.15s ease;" title="<?= $i ?> Star<?= $i > 1 ? 's' : '' ?>">
                                            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                                                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                                            </svg>
                                        </button>
                                    <?php endfor; ?>
                                </div>
                                <span id="owner-rating-text" class="rating-score-label">
                                    <?= $currOverall ?> / 5 Stars
                                </span>
                            </div>
                        </div>

                        <!-- Sub-Ratings Row (System UX, Features, Service) -->
                        <div class="rating-subratings-box">
                            <div>
                                <span style="font-size:11px; color:var(--muted); display:block; margin-bottom:4px; font-weight:600;">System UX:</span>
                                <div class="sub-star-group" data-target="owner-system-rating" style="display:flex; gap:2px;">
                                    <?php $currSys = (int)($myPlatformReview['system_experience'] ?? 5);
                                    for ($i = 1; $i <= 5; $i++): ?>
                                        <span class="sub-star" data-val="<?= $i ?>" onclick="setSubRating('owner-system-rating', <?= $i ?>, this)" style="cursor:pointer; font-size:14px; color:<?= $i <= $currSys ? '#f59e0b' : 'var(--star-unfilled, #4b5563)' ?>;">★</span>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <div>
                                <span style="font-size:11px; color:var(--muted); display:block; margin-bottom:4px; font-weight:600;">Features:</span>
                                <div class="sub-star-group" data-target="owner-features-rating" style="display:flex; gap:2px;">
                                    <?php $currFeat = (int)($myPlatformReview['features_rating'] ?? 5);
                                    for ($i = 1; $i <= 5; $i++): ?>
                                        <span class="sub-star" data-val="<?= $i ?>" onclick="setSubRating('owner-features-rating', <?= $i ?>, this)" style="cursor:pointer; font-size:14px; color:<?= $i <= $currFeat ? '#f59e0b' : 'var(--star-unfilled, #4b5563)' ?>;">★</span>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <div>
                                <span style="font-size:11px; color:var(--muted); display:block; margin-bottom:4px; font-weight:600;">Service:</span>
                                <div class="sub-star-group" data-target="owner-service-rating" style="display:flex; gap:2px;">
                                    <?php $currServ = (int)($myPlatformReview['service_rating'] ?? 5);
                                    for ($i = 1; $i <= 5; $i++): ?>
                                        <span class="sub-star" data-val="<?= $i ?>" onclick="setSubRating('owner-service-rating', <?= $i ?>, this)" style="cursor:pointer; font-size:14px; color:<?= $i <= $currServ ? '#f59e0b' : 'var(--star-unfilled, #4b5563)' ?>;">★</span>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Written Review Comment -->
                        <div>
                            <label for="platform-review-text" class="rating-field-label" style="font-size:12px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.5px; display:block; margin-bottom:6px;">
                                Written Platform Review / Testimonial <span style="font-size:11px; font-weight:normal; text-transform:none; color:var(--muted);">(Optional &bull; featured on landing page)</span>
                            </label>
                            <textarea id="platform-review-text" name="review" rows="3" class="form-control rating-textarea" placeholder="Share your experience using FitTrack for your gym operations, attendance, billing, or member engagement..."><?= h($myPlatformReview['review'] ?? '') ?></textarea>
                        </div>

                        <div style="display:flex; align-items:center; justify-content:space-between; margin-top:auto; padding-top:6px;">
                            <?php if ($myPlatformReview): ?>
                                <button type="button" onclick="togglePlatformReviewEdit(false)" class="btn btn-secondary" style="font-size:12px; padding:6px 12px;">Cancel</button>
                            <?php else: ?>
                                <span style="font-size:11px; color:var(--muted);">Publicly attributed to your gym name</span>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary" style="font-size:12px; padding:8px 18px; font-weight:700; display:inline-flex; align-items:center; gap:6px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                <?= $myPlatformReview ? 'Update Platform Review' : 'Publish Review to Landing Page' ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ==================== MODALS ==================== -->

    <!-- Account Edit Modal -->
    <dialog id="accountModal" class="modal" onclick="if (event.target === this) this.close();">
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
                <label>First name <input name="first_name" required value="<?= h($user['first_name']) ?>" autocapitalize="words" style="text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())"></label>
                <label>Last name <input name="last_name" required value="<?= h($user['last_name']) ?>" autocapitalize="words" style="text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())"></label>
                <label>Email <input type="email" name="email" required value="<?= h($user['email']) ?>" style="text-transform: lowercase;" oninput="this.value = this.value.toLowerCase()" onblur="this.value = this.value.trim().toLowerCase()"></label>
                <label>Mobile Number * <input name="phone" type="tel" pattern="[0-9]{11}" maxlength="11" required title="Please enter exactly 11 digits" placeholder="09123456789" value="<?= h($user['phone'] ?? '') ?>" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)"></label>
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
    <dialog id="physicalProfileModal" class="modal" onclick="if (event.target === this) this.close();">
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

    <!-- ==================== SCRIPTS ==================== -->
    <script>
    function togglePlatformReviewEdit(showEdit) {
        const form = document.getElementById('platform-review-form');
        const view = document.getElementById('platform-review-view');
        if (form) form.style.display = showEdit ? 'flex' : 'none';
        if (view) view.style.display = showEdit ? 'none' : 'block';
    }

    function setPlatformRating(aspect, val) {
        const inp = document.getElementById('owner-overall-rating');
        if (inp) inp.value = val;
        const btns = document.querySelectorAll('#owner-star-picker .owner-star-btn');
        btns.forEach((btn, idx) => {
            const starVal = idx + 1;
            btn.style.color = starVal <= val ? '#f59e0b' : 'var(--star-unfilled, #4b5563)';
        });
        const textEl = document.getElementById('owner-rating-text');
        if (textEl) textEl.textContent = val + ' / 5 Stars';
    }

    function setSubRating(targetInputId, val, clickedEl) {
        const inp = document.getElementById(targetInputId);
        if (inp) inp.value = val;
        const parent = clickedEl.parentElement;
        if (parent) {
            const stars = parent.querySelectorAll('.sub-star');
            stars.forEach((s, idx) => {
                s.style.color = (idx + 1) <= val ? '#f59e0b' : 'var(--star-unfilled, #4b5563)';
            });
        }
    }

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

        // URL hash or tab query parameter activation
        const urlParams = new URLSearchParams(window.location.search);
        let initialTab = (urlParams.get('tab') || window.location.hash.replace('#', '') || '').toLowerCase();
        if (window.location.hash === '#platform-feedback-card' || window.location.hash === '#platform-review-form') {
            initialTab = 'ratings_feedback';
        }
        if (initialTab) {
            const matchedTab = document.querySelector(`.settings-tab[data-tab="${initialTab}"]`);
            if (matchedTab) {
                matchedTab.click();
            }
        }

        const presetRating = parseInt(urlParams.get('rating'), 10);
        if (presetRating >= 1 && presetRating <= 5) {
            setPlatformRating('overall', presetRating);
        }
        if (window.location.hash === '#platform-feedback-card' || window.location.hash === '#platform-review-form') {
            togglePlatformReviewEdit(true);
            const target = document.getElementById('platform-feedback-card');
            if (target) {
                setTimeout(() => target.scrollIntoView({ behavior: 'smooth', block: 'center' }), 200);
            }
        }

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
    .settings-hero .settings-avatar-edit {
        min-width: 0;
        min-height: 0;
        padding: 0;
        line-height: 1;
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
    .platform-rating-summary {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 20px;
        padding: 18px 24px;
        margin: 16px 0 20px;
        background: rgba(255,255,255,0.03);
        border: 1px solid rgba(255,255,255,0.06);
        border-radius: 12px;
    }
    .platform-rating-overall { display: flex; align-items: center; gap: 18px; min-width: 0; }
    .platform-rating-number { flex: 0 0 auto; color: #fff; font-size: 42px; font-weight: 900; line-height: 1; }
    .platform-rating-copy { min-width: 0; }
    .platform-rating-title { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin-bottom: 4px; }
    .platform-rating-title strong { color: var(--lime); font-size: 13px; }
    .platform-rating-metrics {
        display: grid !important;
        grid-template-columns: repeat(3, minmax(80px, 1fr));
        gap: 12px;
        align-items: center;
    }
    .platform-rating-metric { min-width: 0; padding: 0 6px; text-align: center; }
    .platform-rating-metric > div { color: var(--lime); font-size: 17px; font-weight: 800; }
    .platform-rating-metric > span { display: block; color: var(--muted); font-size: 11px; text-transform: uppercase; }
    @media (max-width: 760px) {
        .platform-rating-summary { grid-template-columns: 1fr; gap: 18px; padding: 18px; }
        .platform-rating-metrics { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
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
        color: var(--ink);
        background: color-mix(in srgb, var(--lime) 16%, var(--panel));
        font-weight: 700;
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

    .platform-settings-cards {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
        margin-top: 1rem;
    }
    .platform-settings-card {
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        min-height: 220px;
        padding: 22px;
        overflow: hidden;
        border: 1px solid var(--line);
        border-radius: 14px;
        background:
            radial-gradient(circle at 100% 0, color-mix(in srgb, var(--lime) 9%, transparent), transparent 48%),
            color-mix(in srgb, var(--panel) 88%, transparent);
        transition: border-color 0.2s, transform 0.2s, box-shadow 0.2s;
    }
    .platform-settings-card:hover {
        border-color: color-mix(in srgb, var(--lime) 48%, var(--line));
        transform: translateY(-2px);
        box-shadow: 0 12px 30px rgba(0,0,0,0.12);
    }
    .platform-settings-card-mark {
        display: grid;
        place-items: center;
        width: 38px;
        height: 38px;
        margin-bottom: 18px;
        border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
        border-radius: 11px;
        background: color-mix(in srgb, var(--lime) 10%, transparent);
        color: var(--lime);
        font-size: 20px;
    }
    .platform-settings-eyebrow {
        color: var(--lime);
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 0.09em;
    }
    .platform-settings-card-copy h3 {
        margin: 5px 0 6px;
        color: var(--ink);
        font-size: 16px;
        letter-spacing: -0.02em;
    }
    .platform-settings-card-copy p {
        max-width: 38ch;
        margin: 0;
        color: var(--muted);
        font-size: 12px;
        line-height: 1.55;
    }
    .platform-settings-open {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        min-height: 34px;
        margin-top: auto;
        padding: 7px 0 0;
        border: 0;
        background: transparent;
        color: var(--ink);
        font-size: 12px;
        font-weight: 750;
    }
    .platform-settings-open span { color: var(--lime); font-size: 16px; transition: transform 0.2s; }
    .platform-settings-open:hover { background: transparent; color: var(--lime); }
    .platform-settings-open:hover span { transform: translateX(3px); }
    .platform-settings-modal { width: min(560px, calc(100vw - 28px)); }
    .platform-settings-modal .modal-header { align-items: center; }
    .platform-settings-modal .modal-header h3 { margin: 4px 0 0; color: var(--ink); font-size: 18px; }
    .platform-settings-modal .modal-body { padding-top: 18px; }
    .platform-settings-modal .platform-settings-form { max-width: none; margin: 0; }
    .platform-settings-modal .platform-settings-form > label { grid-column: auto; }
    .platform-settings-modal .platform-settings-form > .platform-settings-full,
    .platform-settings-modal .platform-settings-modal-actions { grid-column: 1 / -1; }
    .platform-settings-modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 4px;
        padding-top: 14px;
        border-top: 1px solid var(--line);
    }
    .platform-settings-cancel {
        border: 1px solid var(--line);
        background: transparent;
        color: var(--ink);
    }
    .platform-settings-cancel:hover { border-color: var(--lime); background: color-mix(in srgb, var(--lime) 7%, transparent); }
    .platform-settings-form {
        max-width: 860px;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
        margin-top: 1rem;
        padding: 0;
    }
    .platform-settings-form > h3,
    .platform-settings-form > label:nth-of-type(3),
    .platform-settings-form > div {
        grid-column: 1 / -1;
    }
    .platform-settings-form > h3 {
        display: flex;
        align-items: center;
        gap: 9px;
        margin: 18px 0 2px !important;
        padding: 0 0 10px !important;
        border-bottom: 1px solid var(--line);
        color: var(--ink);
        font-size: 0.92rem !important;
        font-weight: 800;
        letter-spacing: 0.01em;
    }
    .platform-settings-form > h3::before {
        content: '';
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--lime);
        box-shadow: 0 0 12px color-mix(in srgb, var(--lime) 45%, transparent);
        flex: 0 0 auto;
    }
    .platform-settings-form > h3:first-of-type { margin-top: 0 !important; }
    .platform-settings-form > label {
        align-content: start;
        padding: 14px;
        border: 1px solid var(--line);
        border-left: 2px solid color-mix(in srgb, var(--lime) 38%, var(--line));
        border-radius: 10px;
        background: color-mix(in srgb, var(--panel-soft) 70%, transparent);
        transition: border-color 0.18s, background 0.18s, box-shadow 0.18s;
    }
    .platform-settings-form > label:focus-within {
        border-color: color-mix(in srgb, var(--lime) 65%, var(--line));
        border-left-color: var(--lime);
        background: color-mix(in srgb, var(--lime) 5%, var(--panel));
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--lime) 8%, transparent);
    }
    .platform-settings-form input,
    .platform-settings-form select { min-height: 44px; }
    .platform-settings-form > div:last-child {
        margin-top: 6px !important;
        padding-top: 14px;
        border-top: 1px solid var(--line);
    }
    .platform-settings-form button {
        min-height: 42px;
        padding: 10px 20px;
        border-radius: 9px;
        box-shadow: 0 5px 18px color-mix(in srgb, var(--lime) 16%, transparent);
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

    /* ── Two-Way Rating & Feedback Hub ── */
    :root {
        --star-unfilled: #4b5563;
        --star-empty: rgba(255, 255, 255, 0.22);
    }
    [data-theme="light"] {
        --star-unfilled: #cbd5e1;
        --star-empty: #cbd5e1;
    }

    .rating-hub-card {
        border-radius: 14px;
        border: 1px solid var(--line);
        background: color-mix(in srgb, var(--panel-soft) 60%, transparent);
        padding: 22px;
        display: flex;
        flex-direction: column;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }
    [data-theme="light"] .rating-hub-card {
        background: #ffffff !important;
        border: 1px solid #e2e8f0 !important;
        box-shadow: 0 4px 18px rgba(15, 23, 42, 0.05) !important;
    }

    .rating-platform-card {
        border: 1px solid rgba(199, 255, 34, 0.25);
    }
    [data-theme="light"] .rating-platform-card {
        border: 1px solid #cbd5e1 !important;
        box-shadow: 0 4px 18px rgba(15, 23, 42, 0.06) !important;
    }

    .rating-badge-active {
        font-size: 12px;
        padding: 4px 12px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: #cbd5e1;
        font-weight: 600;
    }
    [data-theme="light"] .rating-badge-active {
        background: #f1f5f9 !important;
        border: 1px solid #cbd5e1 !important;
        color: #475569 !important;
    }

    .rating-badge-gym,
    .rating-badge-owner {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 700;
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid rgba(255, 255, 255, 0.12);
        color: #cbd5e1;
        padding: 3px 9px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    [data-theme="light"] .rating-badge-gym,
    [data-theme="light"] .rating-badge-owner {
        background: #f1f5f9 !important;
        border: 1px solid #cbd5e1 !important;
        color: #334155 !important;
    }

    .rating-badge-featured {
        font-size: 11px;
        color: var(--muted);
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.08);
        padding: 3px 9px;
        border-radius: 999px;
        font-weight: 500;
    }
    [data-theme="light"] .rating-badge-featured {
        background: #f8fafc !important;
        border: 1px solid #e2e8f0 !important;
        color: #64748b !important;
    }

    .rating-badge-live {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 11px;
        color: #22c55e;
        background: rgba(34, 197, 94, 0.1);
        border: 1px solid rgba(34, 197, 94, 0.25);
        padding: 3px 8px;
        border-radius: 6px;
        font-weight: 600;
        white-space: nowrap;
    }
    [data-theme="light"] .rating-badge-live {
        background: #f0fdf4 !important;
        border: 1px solid #bbf7d0 !important;
        color: #15803d !important;
    }

    .rating-summary-box {
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 18px;
        display: flex;
        align-items: center;
        gap: 20px;
    }
    [data-theme="light"] .rating-summary-box {
        background: #f8fafc !important;
        border: 1px solid #e2e8f0 !important;
    }

    .rating-big-num {
        font-size: 36px;
        font-weight: 800;
        line-height: 1;
        color: #ffffff;
    }
    [data-theme="light"] .rating-big-num {
        color: #0f172a !important;
    }

    .rating-summary-divider {
        flex: 1;
        border-left: 1px solid rgba(255, 255, 255, 0.06);
        padding-left: 16px;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    [data-theme="light"] .rating-summary-divider {
        border-left: 1px solid #e2e8f0 !important;
    }

    .rating-bar-track {
        flex: 1;
        height: 6px;
        background: rgba(255, 255, 255, 0.08);
        border-radius: 999px;
        overflow: hidden;
    }
    [data-theme="light"] .rating-bar-track {
        background: #e2e8f0 !important;
    }

    .rating-reviews-scroll-list {
        scrollbar-width: thin;
        scrollbar-color: rgba(255, 255, 255, 0.15) transparent;
    }
    .rating-reviews-scroll-list::-webkit-scrollbar {
        width: 5px;
    }
    .rating-reviews-scroll-list::-webkit-scrollbar-track {
        background: transparent;
    }
    .rating-reviews-scroll-list::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.15);
        border-radius: 4px;
    }
    [data-theme="light"] .rating-reviews-scroll-list {
        scrollbar-color: #cbd5e1 transparent;
    }
    [data-theme="light"] .rating-reviews-scroll-list::-webkit-scrollbar-thumb {
        background: #cbd5e1;
    }

    .rating-review-card {
        padding: 12px;
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid rgba(255, 255, 255, 0.05);
        border-radius: 10px;
    }
    [data-theme="light"] .rating-review-card {
        background: #f8fafc !important;
        border: 1px solid #e2e8f0 !important;
    }

    .rating-review-quote {
        font-size: 12.5px;
        line-height: 1.45;
        color: rgba(255, 255, 255, 0.85);
        margin: 6px 0 0 0;
        font-style: italic;
    }
    [data-theme="light"] .rating-review-quote {
        color: #334155 !important;
    }

    .rating-empty-box {
        text-align: center;
        padding: 28px 16px;
        background: rgba(255, 255, 255, 0.02);
        border: 1px dashed rgba(255, 255, 255, 0.08);
        border-radius: 10px;
    }
    [data-theme="light"] .rating-empty-box {
        background: #f8fafc !important;
        border: 1px dashed #cbd5e1 !important;
    }

    .rating-score-label {
        font-size: 13px;
        font-weight: 700;
        color: var(--lime);
    }
    [data-theme="light"] .rating-score-label {
        color: #d97706 !important;
    }

    .rating-subratings-box {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
        gap: 10px;
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid rgba(255, 255, 255, 0.05);
        border-radius: 8px;
        padding: 10px;
    }
    [data-theme="light"] .rating-subratings-box {
        background: #f8fafc !important;
        border: 1px solid #e2e8f0 !important;
    }

    .rating-field-label {
        color: var(--muted);
    }
    [data-theme="light"] .rating-field-label {
        color: #334155 !important;
    }

    .rating-textarea {
        width: 100%;
        box-sizing: border-box;
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        color: #f8fafc;
        padding: 10px;
        font-size: 13px;
        resize: vertical;
    }
    [data-theme="light"] .rating-textarea {
        background: #ffffff !important;
        border: 1px solid #cbd5e1 !important;
        color: #0f172a !important;
    }
    [data-theme="light"] .rating-textarea::placeholder {
        color: #94a3b8 !important;
    }

    .rating-existing-review {
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: 10px;
        padding: 14px;
        margin-bottom: 14px;
    }
    [data-theme="light"] .rating-existing-review {
        background: #f8fafc !important;
        border: 1px solid #e2e8f0 !important;
    }
    [data-theme="light"] .rating-existing-review .rating-meta-row {
        border-top: 1px solid #e2e8f0 !important;
    }

    .rating-landing-link {
        color: var(--lime);
    }
    [data-theme="light"] .rating-landing-link {
        color: #0284c7 !important;
    }

    /* ── Responsive ── */
    @media (max-width: 600px) {
        .platform-settings-cards { grid-template-columns: 1fr; gap: 12px; }
        .platform-settings-card { min-height: 0; padding: 18px; }
        .platform-settings-modal .platform-settings-form { grid-template-columns: 1fr; }
        .platform-settings-modal .platform-settings-form > label { grid-column: 1 / -1; }
        .platform-settings-form { grid-template-columns: 1fr; gap: 12px; }
        .settings-hero-content { flex-direction: row; align-items: center; gap: 14px; padding: 16px; }
        .settings-hero-avatar img,
        .settings-hero-initials { width: 54px; height: 54px; }
        .settings-hero .settings-avatar-edit { width: 18px; height: 18px; min-width: 18px; min-height: 18px; padding: 0; right: -2px; bottom: -2px; border-width: 1px; }
        .settings-hero .settings-avatar-edit svg { width: 10px; height: 10px; }
        .settings-hero-info {
            min-width: 0;
            flex: 1;
            position: relative;
            padding-right: 150px;
        }
        .settings-hero-name { font-size: 1.05rem; margin-bottom: 3px; }
        .settings-hero-meta { flex-wrap: wrap; gap: 5px; margin-bottom: 6px; }
        .settings-hero-role { padding: 2px 8px; font-size: 10px; }
        .settings-hero-gym { font-size: 11px; }
        .settings-hero-contact {
            position: absolute;
            top: 50%;
            right: 0;
            max-width: 145px;
            transform: translateY(-50%);
            flex-direction: column;
            align-items: flex-start;
            gap: 7px;
            font-size: 10px;
        }
        .settings-tab { padding: 12px 14px; font-size: 12px; }
        .settings-stat-grid { grid-template-columns: 1fr 1fr; }
        .settings-section { padding: 1.25rem 0; }
        .settings-section-header { flex-direction: column; }
    }
    @media (max-width: 380px) {
        .settings-hero-content { gap: 10px; padding: 12px; }
        .settings-hero-avatar img,
        .settings-hero-initials { width: 46px; height: 46px; }
        .settings-hero-info { padding-right: 132px; }
        .settings-hero-name { font-size: 0.95rem; }
        .settings-hero-contact { max-width: 128px; font-size: 9px; }
    }
    </style>
    </div>
    </div>
<?php
    render_footer();
}
