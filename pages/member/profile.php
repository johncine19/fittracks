<?php

declare(strict_types=1);

require_once __DIR__ . '/profile_actions.php';

function profile_page(): void
{
    $user      = require_login();
    $is_member = $user['role'] === 'member';
    $profile   = $is_member ? member_profile((int) $user['user_id']) : null;

    handle_profile_post_actions($user, $profile, $is_member);

    $user = current_user();

    $sysSettings = [];
    $allPolicyIdTypes = [];
    if (($user['role'] ?? '') === 'platform_admin') {
        $allSettings = query_all('SELECT * FROM system_settings WHERE setting_key != "last_at_risk_scan_date" ORDER BY setting_key');
        foreach ($allSettings as $s) {
            $sysSettings[$s['setting_key']] = $s;
        }
        require_once __DIR__ . '/../../core/verification_policy.php';
        $allPolicyIdTypes = get_verification_id_types(false);
    }

    $currentGymId = null;
    $currentGymName = '';
    $homeGymId = null;
    $homeGymName = 'None';
    if ($is_member) {
        $homeGym = get_user_gym($user);
        if ($homeGym) {
            $homeGymId = (int)$homeGym['gym_id'];
            $homeGymName = $homeGym['name'];
        }

        // Active membership scoped to the active facility
        $activeMemStmt = db()->prepare("
            SELECT g.gym_id, g.name, mp.plan_name, mp.duration_days, m.end_date,
                   DATEDIFF(m.end_date, CURDATE()) as days_remaining
            FROM memberships m 
            JOIN membership_plans mp ON mp.plan_id = m.plan_id 
            JOIN gyms g ON g.gym_id = mp.gym_id 
            WHERE m.user_id = ? " . ($homeGymId > 0 ? " AND mp.gym_id = ?" : "") . " 
              AND m.status = 'active' AND m.end_date >= CURDATE()
            ORDER BY m.end_date DESC LIMIT 1
        ");
        $activeMemStmt->execute($homeGymId > 0 ? [(int)$user['user_id'], $homeGymId] : [(int)$user['user_id']]);
        $activeMem = $activeMemStmt->fetch();
        if ($activeMem) {
            $currentGymId = (int)$activeMem['gym_id'];
            $currentGymName = $activeMem['name'];
        }

        $pendingMem = null;
        if (!$activeMem && $homeGymId > 0) {
            $pendingMemStmt = db()->prepare("
                SELECT g.gym_id, g.name, mp.plan_name, mp.duration_days, m.status, m.created_at
                FROM memberships m
                JOIN membership_plans mp ON mp.plan_id = m.plan_id
                JOIN gyms g ON g.gym_id = mp.gym_id
                WHERE m.user_id = ? AND mp.gym_id = ? AND m.status = 'pending'
                ORDER BY m.membership_id DESC LIMIT 1
            ");
            $pendingMemStmt->execute([(int)$user['user_id'], $homeGymId]);
            $pendingMem = $pendingMemStmt->fetch();
        }
    }

    $allGyms = ($is_member && !$currentGymId)
        ? query_all('SELECT gym_id, name FROM gyms WHERE status = "approved" ORDER BY name ASC')
        : [];

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
            $platformFee = 0.0;
            $netRevenue = $gymRevenue;

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
        $membershipInfo = $activeMem ?: db()->query("
            SELECT m.*, mp.plan_name as plan_name, mp.duration_days, m.end_date,
                   DATEDIFF(m.end_date, CURDATE()) as days_remaining
            FROM memberships m
            JOIN membership_plans mp ON mp.plan_id = m.plan_id
            WHERE m.user_id = {$user['user_id']} AND mp.gym_id = {$currentGymId} AND m.status = 'active' AND m.end_date >= CURDATE()
            ORDER BY m.end_date DESC LIMIT 1
        ")->fetch();
    }
?>
    <link rel="stylesheet" href="<?= h(asset_url('css/pages/profile.css')) ?>">
    <?php render_skeleton_profile(); ?>
    <div class="skeleton-content sk-display-block">

        <!-- Settings Header with Profile Hero -->
        <div class="settings-hero live-animated-banner<?= ($user['role'] === 'gym_owner' && !empty($ownerGym['cover_image_url'])) ? ' has-gym-cover' : '' ?>">
            <div class="settings-hero-bg<?= ($user['role'] === 'gym_owner' && !empty($ownerGym['cover_image_url'])) ? ' has-gym-cover' : '' ?>" <?php if ($user['role'] === 'gym_owner' && !empty($ownerGym['cover_image_url'])): ?> style="background-image: linear-gradient(90deg, rgba(7, 10, 15, 0.88) 0%, rgba(7, 10, 15, 0.64) 48%, rgba(7, 10, 15, 0.42) 100%), url('<?= h(upload_url($ownerGym['cover_image_url'])) ?>');" <?php endif; ?>></div>
            <div class="settings-hero-content">
                <div class="settings-hero-avatar">
                    <?php if (!empty($user['profile_picture'])): ?>
                        <img src="<?= h(upload_url($user['profile_picture'])) ?>" alt="Profile picture" loading="lazy" decoding="async">
                    <?php else: ?>
                        <span class="settings-hero-initials"><?= h(initials($user)) ?></span>
                    <?php endif; ?>
                    <button type="button" class="settings-avatar-edit" onclick="document.getElementById('accountModal').showModal()" title="Edit profile">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" />
                        </svg>
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
                        <span><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect width="20" height="16" x="2" y="4" rx="2" />
                                <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
                            </svg> <?= h($user['email']) ?></span>
                        <?php if (!empty($user['phone'])): ?>
                            <span><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
                                </svg> <?= h($user['phone']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <nav class="settings-tabs" id="settingsTabs">
            <button class="settings-tab active" data-tab="account">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                    <circle cx="12" cy="7" r="4" />
                </svg>
                Account
            </button>
            <?php if ($is_member): ?>
                <button class="settings-tab" data-tab="physical">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
                    </svg>
                    Physical Profile
                </button>
            <?php endif; ?>
            <?php if ($user['role'] === 'platform_admin'): ?>
                <button class="settings-tab" data-tab="platform_settings">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3" />
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
                    </svg>
                    Platform Settings
                </button>
                <button class="settings-tab" data-tab="platform_feedback">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
                    </svg>
                    Feedback
                </button>
            <?php endif; ?>
            <?php if ($user['role'] === 'gym_owner'): ?>
                <button class="settings-tab" data-tab="subscription">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect width="20" height="14" x="2" y="5" rx="2" />
                        <line x1="2" x2="22" y1="10" y2="10" />
                    </svg>
                    Subscription & Billing
                </button>
            <?php endif; ?>

            <?php if (in_array(($user['role'] ?? ''), ['gym_owner', 'admin'], true)): ?>
                <button class="settings-tab" data-tab="ratings_feedback">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
                    </svg>
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
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" />
                        </svg>
                        Edit
                    </button>
                </div>

                <div class="settings-info-grid">
                    <div class="settings-info-row">
                        <div class="settings-info-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                <circle cx="12" cy="7" r="4" />
                            </svg>
                        </div>
                        <div class="settings-info-content">
                            <span class="settings-info-label">Full Name</span>
                            <span class="settings-info-value"><?= h($user['first_name'] . ' ' . $user['last_name']) ?></span>
                        </div>
                    </div>
                    <div class="settings-info-row">
                        <div class="settings-info-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect width="20" height="16" x="2" y="4" rx="2" />
                                <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
                            </svg>
                        </div>
                        <div class="settings-info-content">
                            <span class="settings-info-label">Email Address</span>
                            <span class="settings-info-value"><?= h($user['email']) ?></span>
                        </div>
                    </div>
                    <div class="settings-info-row">
                        <div class="settings-info-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
                            </svg>
                        </div>
                        <div class="settings-info-content">
                            <span class="settings-info-label">Phone Number</span>
                            <span class="settings-info-value"><?= h($user['phone'] ?? 'Not set') ?></span>
                        </div>
                    </div>
                    <div class="settings-info-row">
                        <div class="settings-info-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                            </svg>
                        </div>
                        <div class="settings-info-content">
                            <span class="settings-info-label">Password</span>
                            <span class="settings-info-value">••••••••</span>
                        </div>
                    </div>
                    <div class="settings-info-row">
                        <div class="settings-info-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                                <circle cx="9" cy="7" r="4" />
                                <line x1="19" x2="19" y1="8" y2="14" />
                                <line x1="22" x2="16" y1="11" y2="11" />
                            </svg>
                        </div>
                        <div class="settings-info-content">
                            <span class="settings-info-label">Account Type</span>
                            <span class="settings-info-value"><?= h(ucfirst($user['role'])) ?></span>
                        </div>
                    </div>
                </div>

                <div class="danger-zone-wrapper" <?= in_array($user['role'] ?? '', ['member', 'gym_owner'], true) ? '' : 'style="display:none;"' ?>>
                    <div class="danger-zone-header">
                        <div class="danger-zone-badge">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
                                <line x1="12" y1="9" x2="12" y2="13" />
                                <line x1="12" y1="17" x2="12.01" y2="17" />
                            </svg>
                            Danger Zone
                        </div>
                        <p class="danger-zone-sub">Sensitive operational controls and irreversible account actions.</p>
                    </div>

                    <?php if ($is_member): ?>
                        <?php
                        $uid = (int) $user['user_id'];
                        $memberGymsList = query_all('
                            SELECT DISTINCT g.gym_id, g.name,
                                   (SELECT mp.plan_name
                                    FROM memberships m
                                    JOIN membership_plans mp ON mp.plan_id = m.plan_id
                                    WHERE m.user_id = ? AND mp.gym_id = g.gym_id AND m.status = "active" AND m.end_date >= CURDATE()
                                    ORDER BY m.end_date DESC LIMIT 1) AS active_plan_name,
                                   (SELECT mp.plan_name
                                    FROM memberships m
                                    JOIN membership_plans mp ON mp.plan_id = m.plan_id
                                    WHERE m.user_id = ? AND mp.gym_id = g.gym_id AND m.status = "pending"
                                    ORDER BY m.membership_id DESC LIMIT 1) AS pending_plan_name
                            FROM gyms g
                            WHERE g.gym_id IN (
                                SELECT gym_id FROM gym_members WHERE user_id = ?
                                UNION
                                SELECT mp.gym_id FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = ? AND m.status IN ("active", "pending")
                            )
                            ORDER BY g.name ASC
                        ', [$uid, $uid, $uid, $uid]);
                        ?>
                        <?php if (!empty($memberGymsList)): ?>
                            <div class="danger-zone-card" style="margin-bottom: 12px; flex-direction: column; align-items: stretch; gap: 14px;">
                                <div class="danger-zone-info">
                                    <h3 class="danger-zone-title">Leave / Unlink From Gym Facilities (<?= count($memberGymsList) ?>)</h3>
                                    <p class="danger-zone-desc">
                                        Cleanly unlink from any gym facility where you no longer have an active or pending membership so its staff and trainers can no longer see you in their roster.
                                    </p>
                                </div>
                                <div class="danger-facility-list">
                                    <?php foreach ($memberGymsList as $mg):
                                        $mgId = (int) $mg['gym_id'];
                                        $mgName = (string) $mg['name'];
                                        $hasActive = !empty($mg['active_plan_name']);
                                        $hasPending = !$hasActive && !empty($mg['pending_plan_name']);
                                        $canLeaveThisGym = !$hasActive && !$hasPending;
                                        $isCurrentActiveFacility = ($homeGymId === $mgId);
                                    ?>
                                        <div class="danger-facility-row">
                                            <div class="danger-facility-meta">
                                                <strong class="danger-facility-name"><?= h($mgName) ?></strong>
                                                <?php if ($isCurrentActiveFacility): ?>
                                                    <span class="danger-pill danger-pill-selected">Selected Facility</span>
                                                <?php endif; ?>
                                                <?php if ($hasActive): ?>
                                                    <span class="danger-pill danger-pill-locked">
                                                        Locked &bull; Active (<?= h((string) $mg['active_plan_name']) ?>)
                                                    </span>
                                                <?php elseif ($hasPending): ?>
                                                    <span class="danger-pill danger-pill-pending">
                                                        Locked &bull; Pending (<?= h((string) $mg['pending_plan_name']) ?>)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="danger-pill danger-pill-neutral">
                                                        No Active Plan
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <button
                                                    type="button"
                                                    class="btn-danger-zone btn-danger-zone-sm"
                                                    <?= $canLeaveThisGym ? 'data-gym-id="' . (int)$mgId . '" data-gym-name="' . h($mgName) . '" onclick="openLeaveGymModal(this.dataset.gymId, this.dataset.gymName)"' : 'disabled title="Cannot leave ' . h($mgName) . ' while a membership is active or pending"' ?>
                                                >
                                                    Leave <?= h($mgName) ?>
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="danger-zone-card" style="margin-bottom: 12px;">
                            <div class="danger-zone-info">
                                <h3 class="danger-zone-title">Reset / Clear Health &amp; Workout History</h3>
                                <p class="danger-zone-desc">
                                    Wipe all logged workout sessions, daily macro/meal logs, and body progress history so you can start fresh while keeping your account and gym membership intact.
                                </p>
                            </div>
                            <div class="danger-zone-action">
                                <button type="button" class="btn-danger-zone" onclick="document.getElementById('resetHealthModal').showModal()">
                                    Reset Health Data
                                </button>
                            </div>
                        </div>
                    <?php elseif (($user['role'] ?? '') === 'gym_owner' && $ownerGymId): ?>
                        <?php
                        $isGymPaused = is_gym_paused($ownerGymId);
                        $currentPauseNotice = get_gym_pause_notice($ownerGymId);
                        ?>
                        <div class="danger-zone-card" style="margin-bottom: 12px;">
                            <div class="danger-zone-info">
                                <h3 class="danger-zone-title">
                                    Temporarily Pause / Close Gym (Maintenance / Holiday Mode)
                                    <span style="display:inline-block; margin-left:8px; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:700; <?= $isGymPaused ? 'background:rgba(245,158,11,0.18); color:#fbbf24; border:1px solid rgba(245,158,11,0.35);' : 'background:rgba(16,185,129,0.15); color:#6ee7b7; border:1px solid rgba(16,185,129,0.35);' ?>">
                                        <?= $isGymPaused ? 'Paused (Hidden)' : 'Live in Directory' ?>
                                    </span>
                                </h3>
                                <p class="danger-zone-desc">
                                    Hide <strong><?= h($ownerGym['name'] ?? 'your gym') ?></strong> from the public "Browse Gyms / Gym Map" directory while renovating or closed temporarily so new members don't apply or pay while closed. Existing members retain access.
                                </p>
                                <?php if ($isGymPaused): ?>
                                    <div style="margin-top: 10px; font-size: 12px; color: #fbbf24; background: rgba(245,158,11,0.1); border-left: 3px solid #fbbf24; padding: 8px 12px; border-radius: 6px;">
                                        <strong>Active Notice to Members:</strong> <?= h($currentPauseNotice) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="danger-zone-action">
                                <?php if ($isGymPaused): ?>
                                    <form method="post" style="margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_gym_pause">
                                        <button type="submit" class="btn-danger-zone">
                                            Resume Public Listing
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button type="button" class="btn-danger-zone" onclick="document.getElementById('pauseGymModal').showModal()">
                                        Pause Gym Listing
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="danger-zone-card">
                        <div class="danger-zone-info">
                            <h3 class="danger-zone-title">Deactivate &amp; Delete <?= ($user['role'] ?? '') === 'gym_owner' ? 'Gym Facility / Owner' : 'Member' ?> Account</h3>
                            <p class="danger-zone-desc">
                                <?php if (($user['role'] ?? '') === 'gym_owner'): ?>
                                    Permanently deactivate your gym facility listing, archive membership plans, and deactivate associated staff and trainer accounts while leaving member accounts intact. Automatically blocked if your gym still has active paid member subscriptions. Requires password + rate-limited 6-digit email verification code.
                                <?php else: ?>
                                    Revoke active sessions, unlink Google sign-in, clear personal biometric data, and anonymize your account while keeping historical payment receipts intact for gym accounting. Blocked while an active paid membership is running. Requires password + rate-limited 6-digit email verification code.
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="danger-zone-action">
                            <button type="button" class="btn-danger-zone" onclick="openDeleteAccountModal()">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="3 6 5 6 21 6" />
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                    <line x1="10" y1="11" x2="10" y2="17" />
                                    <line x1="14" y1="11" x2="14" y2="17" />
                                </svg>
                                Delete Account
                            </button>
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
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" />
                            </svg>
                            Edit
                        </button>
                    </div>

                    <?php
                    $fmtNum = static function ($val, int $decimals = 2): string {
                        if ($val === null || $val === '' || !is_numeric((string)$val)) {
                            return '—';
                        }
                        $formatted = number_format((float)$val, $decimals, '.', '');
                        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
                    };

                    $heightCm = (float)($profile['height_cm'] ?? 0);
                    $weightKg = (float)($profile['weight_kg'] ?? 0);
                    $bmiVal = null;
                    $bmiLabel = '—';
                    $bmiClass = 'is-neutral';
                    if ($heightCm > 0 && $weightKg > 0) {
                        $hMeters = $heightCm / 100.0;
                        $bmiVal = $weightKg / ($hMeters * $hMeters);
                        if ($bmiVal < 18.5) {
                            $bmiLabel = 'Underweight';
                            $bmiClass = 'is-info';
                        } elseif ($bmiVal < 25.0) {
                            $bmiLabel = 'Normal';
                            $bmiClass = 'is-good';
                        } elseif ($bmiVal < 30.0) {
                            $bmiLabel = 'Overweight';
                            $bmiClass = 'is-warn';
                        } else {
                            $bmiLabel = 'Obese';
                            $bmiClass = 'is-alert';
                        }
                    }

                    $prefDayNums = [];
                    if (!empty($profile['preferred_workout_days'])) {
                        $prefDayNums = array_values(array_unique(array_filter(
                            array_map('intval', explode(',', (string)$profile['preferred_workout_days'])),
                            static fn(int $d): bool => $d >= 1 && $d <= 7
                        )));
                        sort($prefDayNums);
                    }
                    $weekDaysMap = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

                    $hasAnyTarget = !empty($profile['target_strength_max_kg']) ||
                        !empty($profile['target_weight_kg']) ||
                        !empty($profile['target_body_fat_percent']) ||
                        !empty($profile['weekly_workout_target']) ||
                        !empty($profile['preferred_workout_days']) ||
                        !empty($profile['preferred_duration_mins']) ||
                        !empty($profile['target_endurance_distance_km']) ||
                        !empty($profile['target_endurance_time_mins']) ||
                        !empty($profile['target_arm_cm']) ||
                        !empty($profile['target_chest_cm']) ||
                        !empty($profile['target_waist_cm']);
                    ?>

                    <style>
                        .phys-bento-stack {
                            display: flex;
                            flex-direction: column;
                            gap: 16px;
                            margin-top: 6px;
                        }
                        .phys-vitals-strip {
                            display: grid;
                            grid-template-columns: repeat(5, minmax(0, 1fr));
                            background: color-mix(in srgb, var(--panel-soft, #f8fafc) 72%, var(--panel, #ffffff));
                            border: 1px solid var(--line, #e2e8f0);
                            border-radius: 16px;
                            padding: 16px 6px;
                        }
                        .phys-vital-item {
                            padding: 4px 16px;
                            border-right: 1px solid var(--line, #e2e8f0);
                            display: flex;
                            flex-direction: column;
                            justify-content: center;
                            gap: 4px;
                            min-width: 0;
                        }
                        .phys-vital-item:last-child {
                            border-right: none;
                        }
                        .phys-vital-label {
                            font-size: 11px;
                            font-weight: 700;
                            letter-spacing: 0.06em;
                            text-transform: uppercase;
                            color: var(--muted, #64748b);
                        }
                        .phys-vital-value-row {
                            display: flex;
                            align-items: baseline;
                            gap: 5px;
                            flex-wrap: wrap;
                        }
                        .phys-vital-value {
                            font-size: 1.32rem;
                            font-weight: 800;
                            color: var(--ink, #0f172a);
                            line-height: 1.15;
                            letter-spacing: -0.02em;
                        }
                        .phys-vital-unit {
                            font-size: 12px;
                            font-weight: 600;
                            color: var(--muted, #64748b);
                        }
                        .phys-vital-sub {
                            font-size: 11.5px;
                            color: var(--muted, #64748b);
                        }
                        .phys-bmi-pill {
                            display: inline-flex;
                            align-items: center;
                            padding: 2px 8px;
                            border-radius: 999px;
                            font-size: 10.5px;
                            font-weight: 700;
                            letter-spacing: 0.02em;
                        }
                        .phys-bmi-pill.is-good { background: rgba(16, 185, 129, 0.14); color: #059669; }
                        .phys-bmi-pill.is-info { background: rgba(59, 130, 246, 0.14); color: #2563eb; }
                        .phys-bmi-pill.is-warn { background: rgba(245, 158, 11, 0.16); color: #d97706; }
                        .phys-bmi-pill.is-alert { background: rgba(239, 68, 68, 0.14); color: #dc2626; }
                        .phys-bmi-pill.is-neutral { background: rgba(148, 163, 184, 0.16); color: var(--muted, #64748b); }
                        [data-theme="dark"] .phys-bmi-pill.is-good { color: #34d399; }
                        [data-theme="dark"] .phys-bmi-pill.is-info { color: #60a5fa; }
                        [data-theme="dark"] .phys-bmi-pill.is-warn { color: #fbbf24; }
                        [data-theme="dark"] .phys-bmi-pill.is-alert { color: #f87171; }

                        .phys-bento-grid {
                            display: grid;
                            grid-template-columns: 1fr 1.3fr;
                            gap: 16px;
                            align-items: stretch;
                        }
                        .phys-bento-card {
                            background: color-mix(in srgb, var(--panel-soft, #f8fafc) 65%, var(--panel, #ffffff));
                            border: 1px solid var(--line, #e2e8f0);
                            border-radius: 16px;
                            padding: 20px;
                            display: flex;
                            flex-direction: column;
                            gap: 16px;
                        }
                        .phys-card-head {
                            display: flex;
                            align-items: center;
                            justify-content: space-between;
                            gap: 12px;
                            flex-wrap: wrap;
                        }
                        .phys-card-head-left {
                            display: flex;
                            align-items: center;
                            gap: 11px;
                        }
                        .phys-card-icon {
                            width: 36px;
                            height: 36px;
                            border-radius: 10px;
                            display: inline-flex;
                            align-items: center;
                            justify-content: center;
                            flex-shrink: 0;
                        }
                        .phys-card-icon.is-blue {
                            background: rgba(59, 130, 246, 0.12);
                            color: #2563eb;
                        }
                        .phys-card-icon.is-lime {
                            background: color-mix(in srgb, var(--lime, #84cc16) 18%, transparent);
                            color: #4d7c0f;
                        }
                        .phys-card-icon.is-indigo {
                            background: rgba(99, 102, 241, 0.12);
                            color: #4f46e5;
                        }
                        [data-theme="dark"] .phys-card-icon.is-blue { color: #60a5fa; }
                        [data-theme="dark"] .phys-card-icon.is-lime { color: var(--lime, #a3e635); }
                        [data-theme="dark"] .phys-card-icon.is-indigo { color: #818cf8; }

                        .phys-card-title {
                            margin: 0;
                            font-size: 0.95rem;
                            font-weight: 700;
                            color: var(--ink, #0f172a);
                        }
                        .phys-card-subtitle {
                            margin: 2px 0 0 0;
                            font-size: 12px;
                            color: var(--muted, #64748b);
                        }
                        .phys-measure-list {
                            display: flex;
                            flex-direction: column;
                        }
                        .phys-measure-row {
                            display: flex;
                            align-items: center;
                            justify-content: space-between;
                            gap: 12px;
                            padding: 10px 2px;
                            border-bottom: 1px dashed var(--line, #e2e8f0);
                        }
                        .phys-measure-row:first-child { padding-top: 2px; }
                        .phys-measure-row:last-child { border-bottom: none; padding-bottom: 2px; }
                        .phys-measure-name {
                            font-size: 13px;
                            font-weight: 600;
                            color: var(--muted, #64748b);
                        }
                        .phys-measure-right {
                            display: flex;
                            align-items: center;
                            gap: 8px;
                        }
                        .phys-measure-val {
                            font-size: 15px;
                            font-weight: 700;
                            color: var(--ink, #0f172a);
                        }
                        .phys-measure-val small {
                            font-size: 12px;
                            font-weight: 600;
                            color: var(--muted, #64748b);
                        }
                        .phys-target-chip {
                            font-size: 11px;
                            font-weight: 600;
                            padding: 2px 8px;
                            border-radius: 999px;
                            background: color-mix(in srgb, var(--lime, #84cc16) 14%, transparent);
                            color: #4d7c0f;
                        }
                        [data-theme="dark"] .phys-target-chip { color: var(--lime, #a3e635); }

                        .phys-goal-hero {
                            padding: 14px 16px;
                            border-radius: 12px;
                            background: color-mix(in srgb, var(--lime, #84cc16) 8%, var(--panel, #ffffff));
                            border: 1px solid color-mix(in srgb, var(--lime, #84cc16) 30%, var(--line, #e2e8f0));
                            display: flex;
                            align-items: center;
                            justify-content: space-between;
                            gap: 12px;
                            flex-wrap: wrap;
                        }
                        .phys-goal-hero-label {
                            display: block;
                            font-size: 11px;
                            font-weight: 700;
                            letter-spacing: 0.06em;
                            text-transform: uppercase;
                            color: var(--muted, #64748b);
                            margin-bottom: 3px;
                        }
                        .phys-goal-hero-val {
                            font-size: 1.15rem;
                            font-weight: 800;
                            color: var(--ink, #0f172a);
                        }
                        .phys-goal-tag {
                            font-size: 10.5px;
                            font-weight: 700;
                            text-transform: uppercase;
                            letter-spacing: 0.05em;
                            padding: 4px 10px;
                            border-radius: 999px;
                            background: color-mix(in srgb, var(--lime, #84cc16) 18%, transparent);
                            color: #4d7c0f;
                        }
                        [data-theme="dark"] .phys-goal-tag { color: var(--lime, #a3e635); }

                        .phys-goal-subgrid {
                            display: grid;
                            grid-template-columns: repeat(3, minmax(0, 1fr));
                            gap: 12px;
                            padding-top: 4px;
                        }
                        .phys-goal-subitem {
                            padding-right: 12px;
                            border-right: 1px solid var(--line, #e2e8f0);
                            min-width: 0;
                        }
                        .phys-goal-subitem:last-child {
                            padding-right: 0;
                            border-right: none;
                        }
                        .phys-subitem-label {
                            display: block;
                            font-size: 11px;
                            font-weight: 700;
                            text-transform: uppercase;
                            letter-spacing: 0.05em;
                            color: var(--muted, #64748b);
                            margin-bottom: 4px;
                        }
                        .phys-subitem-val {
                            display: block;
                            font-size: 14px;
                            font-weight: 700;
                            color: var(--ink, #0f172a);
                            line-height: 1.3;
                        }

                        .phys-schedule-badges {
                            display: flex;
                            align-items: center;
                            gap: 8px;
                            flex-wrap: wrap;
                        }
                        .phys-meta-pill {
                            display: inline-flex;
                            align-items: center;
                            gap: 6px;
                            padding: 5px 11px;
                            border-radius: 999px;
                            font-size: 12px;
                            font-weight: 700;
                            background: var(--panel, #ffffff);
                            border: 1px solid var(--line, #e2e8f0);
                            color: var(--ink, #0f172a);
                        }
                        .phys-meta-pill span {
                            color: var(--muted, #64748b);
                            font-weight: 600;
                        }
                        .phys-week-row {
                            display: flex;
                            align-items: center;
                            justify-content: space-between;
                            gap: 12px;
                            flex-wrap: wrap;
                        }
                        .phys-week-label {
                            font-size: 12px;
                            font-weight: 700;
                            color: var(--muted, #64748b);
                            text-transform: uppercase;
                            letter-spacing: 0.05em;
                        }
                        .phys-week-pills {
                            display: flex;
                            align-items: center;
                            gap: 6px;
                            flex-wrap: wrap;
                        }
                        .phys-day-pill {
                            min-width: 44px;
                            text-align: center;
                            padding: 6px 10px;
                            border-radius: 10px;
                            font-size: 12px;
                            font-weight: 700;
                            border: 1px solid var(--line, #e2e8f0);
                            background: var(--panel, #ffffff);
                            color: var(--muted, #94a3b8);
                            opacity: 0.65;
                        }
                        .phys-day-pill.is-active {
                            opacity: 1;
                            background: color-mix(in srgb, var(--lime, #84cc16) 18%, var(--panel, #ffffff));
                            border-color: color-mix(in srgb, var(--lime, #84cc16) 55%, transparent);
                            color: #3f6212;
                        }
                        [data-theme="dark"] .phys-day-pill.is-active {
                            color: var(--lime, #a3e635);
                        }

                        .phys-targets-row {
                            display: grid;
                            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
                            gap: 12px;
                            padding-top: 14px;
                            border-top: 1px dashed var(--line, #e2e8f0);
                        }
                        .phys-target-cell {
                            display: flex;
                            flex-direction: column;
                            gap: 3px;
                        }
                        .phys-target-hint {
                            font-size: 11.5px;
                            color: var(--muted, #64748b);
                        }

                        @media (max-width: 960px) {
                            .phys-vitals-strip {
                                grid-template-columns: repeat(3, minmax(0, 1fr));
                                row-gap: 12px;
                            }
                            .phys-vital-item:nth-child(3) {
                                border-right: none;
                            }
                            .phys-bento-grid {
                                grid-template-columns: 1fr;
                            }
                        }
                        @media (max-width: 600px) {
                            .phys-vitals-strip {
                                grid-template-columns: repeat(2, minmax(0, 1fr));
                                padding: 14px 4px;
                            }
                            .phys-vital-item {
                                border-right: none;
                                padding: 6px 12px;
                            }
                            .phys-goal-subgrid {
                                grid-template-columns: 1fr;
                                gap: 10px;
                            }
                            .phys-goal-subitem {
                                border-right: none;
                                border-bottom: 1px dashed var(--line, #e2e8f0);
                                padding-right: 0;
                                padding-bottom: 8px;
                            }
                            .phys-goal-subitem:last-child {
                                border-bottom: none;
                                padding-bottom: 0;
                            }
                        }
                    </style>

                    <div class="phys-bento-stack">
                        <!-- 1. Unified Top Vitals Strip -->
                        <div class="phys-vitals-strip">
                            <div class="phys-vital-item">
                                <span class="phys-vital-label">Height</span>
                                <div class="phys-vital-value-row">
                                    <span class="phys-vital-value"><?= h($fmtNum($profile['height_cm'] ?? null)) ?></span>
                                    <?php if (!empty($profile['height_cm'])): ?><span class="phys-vital-unit">cm</span><?php endif; ?>
                                </div>
                            </div>
                            <div class="phys-vital-item">
                                <span class="phys-vital-label">Weight</span>
                                <div class="phys-vital-value-row">
                                    <span class="phys-vital-value"><?= h($fmtNum($profile['weight_kg'] ?? null)) ?></span>
                                    <?php if (!empty($profile['weight_kg'])): ?><span class="phys-vital-unit">kg</span><?php endif; ?>
                                </div>
                                <?php if (!empty($profile['target_weight_kg'])): ?>
                                    <span class="phys-vital-sub">Goal: <?= h($fmtNum($profile['target_weight_kg'], 1)) ?> kg</span>
                                <?php endif; ?>
                            </div>
                            <div class="phys-vital-item">
                                <span class="phys-vital-label">Age</span>
                                <div class="phys-vital-value-row">
                                    <span class="phys-vital-value"><?= h($fmtNum($profile['age'] ?? null, 0)) ?></span>
                                    <?php if (!empty($profile['age'])): ?><span class="phys-vital-unit">yrs</span><?php endif; ?>
                                </div>
                            </div>
                            <div class="phys-vital-item">
                                <span class="phys-vital-label">Sex</span>
                                <div class="phys-vital-value-row">
                                    <span class="phys-vital-value"><?= h(!empty($profile['biological_sex']) ? ucwords((string)$profile['biological_sex']) : '—') ?></span>
                                </div>
                            </div>
                            <div class="phys-vital-item">
                                <span class="phys-vital-label">BMI</span>
                                <div class="phys-vital-value-row">
                                    <span class="phys-vital-value"><?= $bmiVal !== null ? h(number_format($bmiVal, 1)) : '—' ?></span>
                                    <?php if ($bmiVal !== null): ?>
                                        <span class="phys-bmi-pill <?= $bmiClass ?>"><?= h($bmiLabel) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Middle Bento Grid: Circumferences & Fitness Focus -->
                        <div class="phys-bento-grid">
                            <!-- Body Circumference Card -->
                            <div class="phys-bento-card">
                                <div class="phys-card-head">
                                    <div class="phys-card-head-left">
                                        <div class="phys-card-icon is-blue">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M21 6H3" />
                                                <path d="M21 18H3" />
                                                <path d="M7 6v4" />
                                                <path d="M12 6v6" />
                                                <path d="M17 6v4" />
                                            </svg>
                                        </div>
                                        <div>
                                            <h3 class="phys-card-title">Body Circumference</h3>
                                            <p class="phys-card-subtitle">Tape measurements in centimeters</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="phys-measure-list">
                                    <div class="phys-measure-row">
                                        <span class="phys-measure-name">Neck</span>
                                        <div class="phys-measure-right">
                                            <span class="phys-measure-val"><?= !empty($profile['neck_cm']) ? h($fmtNum($profile['neck_cm'])) . ' <small>cm</small>' : '—' ?></span>
                                        </div>
                                    </div>
                                    <div class="phys-measure-row">
                                        <span class="phys-measure-name">Waist</span>
                                        <div class="phys-measure-right">
                                            <?php if (!empty($profile['target_waist_cm'])): ?>
                                                <span class="phys-target-chip">Goal: <?= h($fmtNum($profile['target_waist_cm'], 1)) ?> cm</span>
                                            <?php endif; ?>
                                            <span class="phys-measure-val"><?= !empty($profile['waist_cm']) ? h($fmtNum($profile['waist_cm'])) . ' <small>cm</small>' : '—' ?></span>
                                        </div>
                                    </div>
                                    <?php if (($profile['biological_sex'] ?? '') === 'female' || !empty($profile['hip_cm'])): ?>
                                        <div class="phys-measure-row">
                                            <span class="phys-measure-name">Hip</span>
                                            <div class="phys-measure-right">
                                                <span class="phys-measure-val"><?= !empty($profile['hip_cm']) ? h($fmtNum($profile['hip_cm'])) . ' <small>cm</small>' : '—' ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($profile['chest_cm']) || !empty($profile['target_chest_cm'])): ?>
                                        <div class="phys-measure-row">
                                            <span class="phys-measure-name">Chest</span>
                                            <div class="phys-measure-right">
                                                <?php if (!empty($profile['target_chest_cm'])): ?>
                                                    <span class="phys-target-chip">Goal: <?= h($fmtNum($profile['target_chest_cm'], 1)) ?> cm</span>
                                                <?php endif; ?>
                                                <span class="phys-measure-val"><?= !empty($profile['chest_cm']) ? h($fmtNum($profile['chest_cm'], 1)) . ' <small>cm</small>' : '—' ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($profile['arm_cm']) || !empty($profile['target_arm_cm'])): ?>
                                        <div class="phys-measure-row">
                                            <span class="phys-measure-name">Arm</span>
                                            <div class="phys-measure-right">
                                                <?php if (!empty($profile['target_arm_cm'])): ?>
                                                    <span class="phys-target-chip">Goal: <?= h($fmtNum($profile['target_arm_cm'], 1)) ?> cm</span>
                                                <?php endif; ?>
                                                <span class="phys-measure-val"><?= !empty($profile['arm_cm']) ? h($fmtNum($profile['arm_cm'], 1)) . ' <small>cm</small>' : '—' ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Fitness Focus Card -->
                            <div class="phys-bento-card">
                                <div class="phys-card-head">
                                    <div class="phys-card-head-left">
                                        <div class="phys-card-icon is-lime">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />
                                            </svg>
                                        </div>
                                        <div>
                                            <h3 class="phys-card-title">Fitness Focus</h3>
                                            <p class="phys-card-subtitle">Training goal &amp; nutrition preferences</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="phys-goal-hero">
                                    <div>
                                        <span class="phys-goal-hero-label">Primary Goal</span>
                                        <span class="phys-goal-hero-val"><?= h(!empty($profile['primary_goal']) ? ucwords(str_replace('_', ' ', (string)$profile['primary_goal'])) : '—') ?></span>
                                    </div>
                                    <span class="phys-goal-tag">Active Focus</span>
                                </div>

                                <div class="phys-goal-subgrid">
                                    <div class="phys-goal-subitem">
                                        <span class="phys-subitem-label">Activity Level</span>
                                        <span class="phys-subitem-val"><?= h(!empty($profile['activity_level']) ? ucwords(str_replace('_', ' ', (string)$profile['activity_level'])) : '—') ?></span>
                                    </div>
                                    <div class="phys-goal-subitem">
                                        <span class="phys-subitem-label">Secondary Focus</span>
                                        <span class="phys-subitem-val"><?= !empty($profile['secondary_focus']) ? h(ucwords(str_replace('_', ' ', (string)$profile['secondary_focus']))) : '—' ?></span>
                                    </div>
                                    <div class="phys-goal-subitem">
                                        <span class="phys-subitem-label">Dietary Preference</span>
                                        <span class="phys-subitem-val"><?= h(ucwords(str_replace('-', ' ', (string)($profile['dietary_restrictions'] ?? 'None')))) ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Bottom Bento Card: Weekly Schedule & Objectives -->
                        <?php if ($hasAnyTarget): ?>
                            <div class="phys-bento-card">
                                <div class="phys-card-head">
                                    <div class="phys-card-head-left">
                                        <div class="phys-card-icon is-indigo">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <rect width="18" height="18" x="3" y="4" rx="2" ry="2" />
                                                <line x1="16" x2="16" y1="2" y2="6" />
                                                <line x1="8" x2="8" y1="2" y2="6" />
                                                <line x1="3" x2="21" y1="10" y2="10" />
                                            </svg>
                                        </div>
                                        <div>
                                            <h3 class="phys-card-title">Training Schedule &amp; Objectives</h3>
                                            <p class="phys-card-subtitle">Weekly commitment and performance benchmarks</p>
                                        </div>
                                    </div>
                                    <div class="phys-schedule-badges">
                                        <?php if (!empty($profile['weekly_workout_target'])): ?>
                                            <span class="phys-meta-pill">
                                                <?= (int)$profile['weekly_workout_target'] ?> <span>days / week</span>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($profile['preferred_duration_mins'])): ?>
                                            <span class="phys-meta-pill">
                                                <?= (int)$profile['preferred_duration_mins'] ?> <span>mins / session</span>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="phys-week-row">
                                    <span class="phys-week-label">Committed Days</span>
                                    <?php if (!empty($prefDayNums)): ?>
                                        <div class="phys-week-pills" aria-label="Committed workout days">
                                            <?php foreach ($weekDaysMap as $dayIndex => $dayLabel):
                                                $isActiveDay = in_array($dayIndex, $prefDayNums, true);
                                            ?>
                                                <span class="phys-day-pill<?= $isActiveDay ? ' is-active' : '' ?>"><?= h($dayLabel) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="phys-meta-pill">Flexible Schedule</span>
                                    <?php endif; ?>
                                </div>

                                <?php
                                $hasPerfTargets = !empty($profile['target_strength_max_kg']) ||
                                    !empty($profile['target_weight_kg']) ||
                                    !empty($profile['target_body_fat_percent']) ||
                                    !empty($profile['target_endurance_distance_km']) ||
                                    !empty($profile['target_endurance_time_mins']);
                                if ($hasPerfTargets):
                                ?>
                                    <div class="phys-targets-row">
                                        <?php if (!empty($profile['target_strength_max_kg'])):
                                            $stEx = $profile['target_exercise'] ?? 'Bench Press';
                                        ?>
                                            <div class="phys-target-cell">
                                                <span class="phys-subitem-label">Strength Target (<?= h($stEx) ?>)</span>
                                                <span class="phys-subitem-val"><?= h($fmtNum($profile['target_strength_max_kg'], 1)) ?> kg PR</span>
                                                <?php if (!empty($profile['current_strength_max_kg'])): ?>
                                                    <span class="phys-target-hint">Current: <?= h($fmtNum($profile['current_strength_max_kg'], 1)) ?> kg</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($profile['target_weight_kg'])): ?>
                                            <div class="phys-target-cell">
                                                <span class="phys-subitem-label">Target Weight</span>
                                                <span class="phys-subitem-val"><?= h($fmtNum($profile['target_weight_kg'], 1)) ?> kg</span>
                                                <?php if (!empty($profile['weight_kg'])):
                                                    $diff = (float)$profile['target_weight_kg'] - (float)$profile['weight_kg'];
                                                ?>
                                                    <span class="phys-target-hint"><?= $diff > 0 ? '+' : '' ?><?= number_format($diff, 1) ?> kg from current</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($profile['target_body_fat_percent'])): ?>
                                            <div class="phys-target-cell">
                                                <span class="phys-subitem-label">Target Body Fat</span>
                                                <span class="phys-subitem-val"><?= h($fmtNum($profile['target_body_fat_percent'], 1)) ?>%</span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($profile['target_endurance_distance_km']) || !empty($profile['target_endurance_time_mins'])):
                                            $act = $profile['endurance_activity'] ?? 'Running';
                                        ?>
                                            <div class="phys-target-cell">
                                                <span class="phys-subitem-label">Endurance (<?= h($act) ?>)</span>
                                                <span class="phys-subitem-val">
                                                    <?= !empty($profile['target_endurance_distance_km']) ? h($fmtNum($profile['target_endurance_distance_km'], 1)) . ' km' : '' ?>
                                                    <?= (!empty($profile['target_endurance_distance_km']) && !empty($profile['target_endurance_time_mins'])) ? ' / ' : '' ?>
                                                    <?= !empty($profile['target_endurance_time_mins']) ? h((string)$profile['target_endurance_time_mins']) . ' min' : '' ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
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
                        <article class="platform-settings-card">
                            <div class="platform-settings-card-mark" aria-hidden="true">03</div>
                            <div class="platform-settings-card-copy">
                                <span class="platform-settings-eyebrow">SECURITY & VERIFICATION</span>
                                <h3>ID verification policy</h3>
                                <p>Configure accepted gym owner government IDs and classification rules.</p>
                            </div>
                            <button type="button" class="platform-settings-open" onclick="document.getElementById('idPolicyModal').showModal()">Configure <span aria-hidden="true">&rarr;</span></button>
                        </article>
                    </div>

                    <dialog id="generalPlatformSettingsModal" class="modal platform-settings-modal" onclick="if (event.target === this) this.close();">
                        <div class="modal-header">
                            <div><span class="platform-settings-eyebrow">CORE CONFIGURATION</span>
                                <h3>General platform</h3>
                            </div>
                            <button type="button" class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">&times;</button>
                        </div>
                        <div class="modal-body">
                            <form method="post" class="form grid-form platform-settings-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="update_platform_settings" value="1">
                                <label><span class="label-title">Platform Name <span class="req-star">*</span></span><input type="text" name="platform_name" value="<?= h($sysSettings['platform_name']['setting_value'] ?? 'FITTRACKS') ?>" required></label>
                                <label><span class="label-title">Support Contact Email <span class="req-star">*</span></span><input type="email" name="contact_email" value="<?= h($sysSettings['contact_email']['setting_value'] ?? 'support@fittracks.com') ?>" required></label>
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
                            <div><span class="platform-settings-eyebrow">MEMBER ENGAGEMENT</span>
                                <h3>Inactive member reminders</h3>
                            </div>
                            <button type="button" class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">&times;</button>
                        </div>
                        <div class="modal-body">
                            <form method="post" class="form grid-form platform-settings-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="update_platform_settings" value="1">
                                <label><span class="label-title">Inactivity threshold (days) <span class="req-star">*</span></span>
                                    <input type="number" min="1" max="90" name="at_risk_inactivity_days" value="<?= h($sysSettings['at_risk_inactivity_days']['setting_value'] ?? '3') ?>" required>
                                    <span class="platform-settings-help">Days without a check-in before a member is flagged as inactive.</span>
                                </label>
                                <label><span class="label-title">Reminder cooldown (days) <span class="req-star">*</span></span>
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

                    <!-- ID Verification Policy Modal -->
                    <dialog id="idPolicyModal" class="modal platform-settings-modal" style="max-width: 780px;" onclick="if (event.target === this) this.close();">
                        <div class="modal-header">
                            <div>
                                <span class="platform-settings-eyebrow">SECURITY & VERIFICATION</span>
                                <h3 style="display:flex; align-items:center; gap:8px;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                    ID Verification Policy Configuration
                                </h3>
                                <p style="margin: 4px 0 0; font-size: 13px; color: var(--muted);">
                                    Configure accepted gym owner IDs and whether an additional supporting ID is required.
                                </p>
                            </div>
                            <button type="button" class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">&times;</button>
                        </div>
                        <div class="modal-body" style="padding: 20px 24px; max-height: 75vh; overflow-y: auto;">
                            <!-- Informational banner -->
                            <div style="background: rgba(132, 204, 22, 0.08); border: 1px solid rgba(132, 204, 22, 0.25); border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 13px; line-height: 1.5; color: var(--ink);">
                                <strong>Classification Rules:</strong><br>
                                • <strong>Primary IDs</strong>: 1 ID upload is sufficient to verify gym owner identity.<br>
                                • <strong>Secondary IDs</strong>: Require 1 additional supporting ID (Primary or another Secondary, non-duplicate).
                            </div>

                            <!-- Existing IDs Table -->
                            <div style="overflow-x: auto; border: 1px solid var(--line); border-radius: 8px;">
                                <table style="width: 100%; border-collapse: collapse; font-size: 13px; margin: 0;">
                                    <thead>
                                        <tr style="background: rgba(255,255,255,0.03); border-bottom: 1px solid var(--line); text-align: left;">
                                            <th style="padding: 10px 14px; font-weight: 600;">Accepted ID Document</th>
                                            <th style="padding: 10px 14px; font-weight: 600;">Classification</th>
                                            <th style="padding: 10px 14px; font-weight: 600; text-align: center;">Status</th>
                                            <th style="padding: 10px 14px; font-weight: 600; text-align: right;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($allPolicyIdTypes as $pType): 
                                            $typeId = (int)($pType['id'] ?? $pType['id_type_id'] ?? 0);
                                        ?>
                                        <tr style="border-bottom: 1px solid color-mix(in srgb, var(--line) 40%, transparent);">
                                            <td style="padding: 10px 14px; vertical-align: middle;">
                                                <div style="font-weight: 600; color: var(--ink);"><?= h($pType['id_name']) ?></div>
                                                <div style="font-size: 11px; color: var(--muted); font-family: monospace;"><?= h($pType['id_code']) ?></div>
                                            </td>
                                            <td style="padding: 10px 14px; vertical-align: middle;">
                                                <form id="policy-form-<?= $typeId ?>" method="post" action="index.php?page=profile&tab=platform_settings" style="margin: 0;">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="update_id_policy">
                                                    <input type="hidden" name="id_type_id" value="<?= $typeId ?>">
                                                </form>
                                                <select name="category" form="policy-form-<?= $typeId ?>" class="form-control" style="padding: 5px 8px; font-size: 12px; border-radius: 6px; background: var(--bg); color: var(--ink); border: 1px solid var(--line);">
                                                    <option value="primary" <?= $pType['category'] === 'primary' ? 'selected' : '' ?>>Primary (1 ID sufficient)</option>
                                                    <option value="secondary" <?= $pType['category'] === 'secondary' ? 'selected' : '' ?>>Secondary (Requires +1 ID)</option>
                                                </select>
                                            </td>
                                            <td style="padding: 10px 14px; vertical-align: middle; text-align: center;">
                                                <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 12px; margin: 0;">
                                                    <input type="checkbox" name="is_active" value="1" form="policy-form-<?= $typeId ?>" <?= !empty($pType['is_active']) ? 'checked' : '' ?>>
                                                    <span>Active</span>
                                                </label>
                                            </td>
                                            <td style="padding: 10px 14px; vertical-align: middle; text-align: right;">
                                                <button type="submit" form="policy-form-<?= $typeId ?>" class="btn-sm btn-primary" style="padding: 4px 12px; font-size: 12px; border-radius: 6px; cursor: pointer;">
                                                    Save
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Add New ID Form -->
                            <div style="margin-top: 20px; padding: 16px; background: rgba(255,255,255,0.02); border: 1px dashed var(--line); border-radius: 8px;">
                                <h4 style="margin: 0 0 10px 0; font-size: 13px; font-weight: 600; color: var(--ink); display: flex; align-items: center; gap: 6px;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                    Add New Accepted Government ID
                                </h4>
                                <form method="post" action="index.php?page=profile&tab=platform_settings" style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin: 0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="add_id_type">
                                    <div style="flex: 1; min-width: 220px;">
                                        <label style="font-size: 11px; color: var(--muted); margin-bottom: 4px; display: block;">ID Document Name *</label>
                                        <input type="text" name="new_id_name" class="form-control" placeholder="e.g. Alien Certificate of Registration (ACR)" required style="padding: 6px 10px; font-size: 12px; border-radius: 6px;">
                                    </div>
                                    <div style="width: 200px;">
                                        <label style="font-size: 11px; color: var(--muted); margin-bottom: 4px; display: block;">Classification *</label>
                                        <select name="new_category" class="form-control" style="padding: 6px 10px; font-size: 12px; border-radius: 6px;">
                                            <option value="primary">Primary (1 ID sufficient)</option>
                                            <option value="secondary" selected>Secondary (Requires +1 ID)</option>
                                        </select>
                                    </div>
                                    <div>
                                        <button type="submit" class="btn btn-primary" style="padding: 7px 14px; font-size: 12px; font-weight: 600; border-radius: 6px;">
                                            + Add ID
                                        </button>
                                    </div>
                                </form>
                            </div>
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
                                <div style="text-align:center; padding:0 10px;">
                                    <div style="font-size:17px; font-weight:800; color:var(--lime);"><?= number_format($platformRatingStats[$key], 1) ?>★</div>
                                    <div style="font-size:11px; color:var(--muted); text-transform:uppercase;"><?= h($label) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(min(320px, 100%), 1fr)); gap:14px;">
                        <?php foreach ($recentPlatformFeedback as $pf): ?>
                            <div class="platform-feedback-card">
                                <div>
                                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                                        <div>
                                            <strong class="platform-feedback-gym"><?= h($pf['gym_name'] ?? 'Commercial Gym') ?></strong>
                                            <small style="font-size:11.5px; color:var(--muted);"><?= h(($pf['first_name'] ?? '') . ' ' . ($pf['last_name'] ?? '')) ?> (Owner)</small>
                                        </div>
                                        <?= render_star_rating((float)$pf['rating'], 14, false) ?>
                                    </div>
                                    <?php if (!empty($pf['review'])): ?>
                                        <p class="platform-feedback-quote">&ldquo;<?= h($pf['review']) ?>&rdquo;</p>
                                    <?php endif; ?>
                                </div>
                                <div class="platform-feedback-footer">
                                    <div style="display:flex; gap:8px;">
                                        <span>UX: <b style="color:var(--lime);"><?= (int)($pf['system_experience'] ?? 5) ?>★</b></span>
                                        <span>Feat: <b style="color:var(--lime);"><?= (int)($pf['features_rating'] ?? 5) ?>★</b></span>
                                        <span>Serv: <b style="color:var(--lime);"><?= (int)($pf['service_rating'] ?? 5) ?>★</b></span>
                                    </div>
                                    <time><?= h(date('M d, Y', strtotime($pf['created_at']))) ?></time>
                                </div>
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
            if ($subStatus === 'active' && !empty($ownerGym['subscription_renewal_date']) && strtotime((string)$ownerGym['subscription_renewal_date']) < strtotime('today')) {
                $subStatus = 'expired';
            }
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
                    <div class="settings-section-header settings-sub-header">
                        <div class="settings-sub-header-info">
                            <h2 class="settings-section-title">Subscription & Revenue</h2>
                            <p class="settings-section-desc">Manage your commercial subscription, member/trainer limits, and monthly revenue overview.</p>
                        </div>
                        <a href="index.php?page=gym_subscription" class="settings-sub-cta">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect width="20" height="14" x="2" y="5" rx="2"/>
                                <line x1="2" x2="22" y1="10" y2="10"/>
                            </svg>
                            <span><?= h($subCta) ?></span>
                            <svg class="sub-cta-arrow" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m9 18 6-6-6-6"/>
                            </svg>
                        </a>
                    </div>

                    <div class="settings-sub-grid">
                        <!-- Card 1: Subscription & Plan -->
                        <div class="settings-sub-card">
                            <div class="settings-sub-plan-header" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; gap: 10px;">
                                <div style="min-width: 0; flex: 1;">
                                    <span style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); font-weight: 700;">Current Plan</span>
                                    <h3 class="settings-sub-plan-title" style="margin: 4px 0 0; font-size: 1.25rem; font-weight: 800; color: var(--ink); line-height: 1.25;">
                                        <?php if ($ownerTrialInfo['is_trial_active']): ?>
                                            Free Trial
                                        <?php elseif ($ownerTrialInfo['is_free']): ?>
                                            Free Tier
                                        <?php else: ?>
                                            <?= h($subPlan) ?><?= $subPlan !== 'No Active Plan' ? ' Plan' : '' ?>
                                        <?php endif; ?>
                                    </h3>
                                    <?php if ($ownerTrialInfo['is_trial_active']): ?>
                                        <div style="font-size: 12px; color: var(--muted); margin-top: 3px; font-weight: 500; line-height: 1.4;">
                                            50 Members &bull; 2 Trainers &bull; 2 Staff
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <span class="badge" style="background: <?= $badgeBg ?>; color: <?= $badgeColor ?>; font-weight: 700; font-size: 11px; padding: 4px 10px; border-radius: 20px; white-space: nowrap; flex-shrink: 0; margin-top: 2px;">
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
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--muted); flex-shrink: 0;">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <polyline points="12 6 12 12 16 14"></polyline>
                                </svg>
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

                        <!-- Card 2: Revenue & Earnings -->
                        <div class="settings-sub-card">
                            <span style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); font-weight: 700; margin-bottom: 4px;">Billing & Revenue</span>
                            <h3 style="margin: 0 0 16px; font-size: 1.25rem; font-weight: 800; color: var(--ink);">Revenue & Earnings</h3>

                            <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 16px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 10px; border-bottom: 1px solid var(--line); font-size: 13.5px;">
                                    <span style="color: var(--muted);">Total Revenue This Month:</span>
                                    <strong style="color: var(--ink);"><?= money($gymRevenue) ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 10px; border-bottom: 1px solid var(--line); font-size: 13.5px;">
                                    <span style="color: var(--muted);">Platform Transaction Fee:</span>
                                    <strong style="color: var(--lime, #84cc16);">₱0.00 (0% Fee)</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 16px; padding-top: 4px;">
                                    <span style="font-weight: 700; color: var(--ink);">Gym Net Amount:</span>
                                    <strong style="color: var(--teal); font-size: 1.2rem;"><?= money($gymRevenue) ?></strong>
                                </div>
                            </div>

                            <div style="margin-top: auto; padding: 12px; border-radius: 10px; background: rgba(255,255,255,0.03); border: 1px solid var(--line);">
                                <p style="font-size: 12px; color: var(--muted); margin: 0; line-height: 1.45;">
                                    FitTrack charges 0% platform transaction fees on your gym revenue. You keep 100% of your recorded earnings (both online GCash and cash walk-ins).
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>



        <!-- Tab: Rating & Feedback -->
        <?php if (in_array(($user['role'] ?? ''), ['gym_owner', 'admin'], true)): ?>
            <div class="settings-panel" data-panel="ratings_feedback">
                <div class="settings-section">
                    <div class="settings-section-header" style="flex-wrap:wrap; gap:12px;">
                        <div>
                            <h2 class="settings-section-title" style="display:flex; align-items:center; gap:8px;">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--lime);">
                                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
                                </svg>
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
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                            <polyline points="20 6 9 17 4 12" />
                                        </svg>
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
                                                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
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
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                            <polyline points="20 6 9 17 4 12" />
                                        </svg>
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
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <form method="post" enctype="multipart/form-data" class="form grid-form" style="margin-bottom:0;" onsubmit="const btn = this.querySelector('button[type=submit]'); if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }">
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
                    <label><span class="label-title">First name <span class="req-star">*</span></span> <input name="first_name" required value="<?= h($user['first_name']) ?>" autocapitalize="words" style="text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\b\w/g, function(l){ return l.toUpperCase(); })"></label>
                    <label><span class="label-title">Last name <span class="req-star">*</span></span> <input name="last_name" required value="<?= h($user['last_name']) ?>" autocapitalize="words" style="text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\b\w/g, function(l){ return l.toUpperCase(); })"></label>
                    <label><span class="label-title">Email <span class="req-star">*</span></span> <input type="email" name="email" required value="<?= h($user['email']) ?>" style="text-transform: lowercase;" oninput="this.value = this.value.toLowerCase()" onblur="this.value = this.value.trim().toLowerCase()"></label>
                    <label><span class="label-title">Mobile Number <span class="req-star">*</span></span> <input name="phone" type="tel" pattern="09[0-9]{9}" maxlength="11" required title="Please enter a valid 11-digit Philippine mobile number starting with 09 (e.g. 09123456789)" placeholder="09123456789" value="<?= h($user['phone'] ?? '') ?>" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11); this.setCustomValidity('');"></label>
                    <label style="grid-column:1/-1">New password
                        <input type="password" name="password" placeholder="Leave blank to keep current">
                        <small class="muted" style="font-weight:400;">Min. 8 characters, with a letter and a number.</small>
                    </label>
                    <div class="account-modal-actions">
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
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                </div>
                <div class="modal-body">
                    <?php render_member_form('profile', $user, $profile); ?>
                </div>
            </dialog>
        <?php endif; ?>

        <?php if ($is_member): ?>
            <!-- Leave Gym Facility Confirmation Modal -->
            <dialog id="leaveGymModal" class="modal" onclick="if (event.target === this) this.close();">
                <div class="modal-header danger-modal-header">
                    <h3 id="leaveGymModalTitle">Leave Gym Facility?</h3>
                    <button class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                </div>
                <div class="modal-body danger-modal-body">
                    <p class="danger-step-desc">
                        You will be unlinked from <strong id="leaveGymModalName"><?= h($homeGymName) ?></strong> and removed from their active member and trainer rosters. Your personal workout and progress history will remain on your FITTRACKS account.
                    </p>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="leave_gym_facility">
                        <input type="hidden" name="leave_gym_id" id="leaveGymIdInput" value="<?= (int) $homeGymId ?>">
                        <div class="account-modal-actions">
                            <button type="button" onclick="this.closest('dialog').close()">Cancel</button>
                            <button type="submit" class="btn-danger-solid">
                                <span class="btn-text-full">Confirm &amp; Leave Gym</span>
                                <span class="btn-text-short">Leave Gym</span>
                            </button>
                        </div>
                    </form>
                </div>
            </dialog>

            <!-- Reset Health Data Modal -->
            <dialog id="resetHealthModal" class="modal" onclick="if (event.target === this) this.close();">
                <div class="modal-header danger-modal-header">
                    <h3>Reset Health &amp; Workout History</h3>
                    <button class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                </div>
                <div class="modal-body danger-modal-body">
                    <div class="danger-warning-box">
                        <strong>This will permanently erase:</strong>
                        <ul class="danger-impact-list">
                            <li>All logged workout sessions and exercise completion history</li>
                            <li>All daily meal and macro intake logs</li>
                            <li>All body weight and measurement progress logs</li>
                        </ul>
                    </div>
                    <form method="post" style="margin-top: 8px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reset_health_data">
                        <?php if (!empty($user['password_hash'])): ?>
                            <div class="danger-field-group">
                                <label>Current Password <span class="req-star">*</span></label>
                                <input type="password" name="current_password" required placeholder="Enter your current password">
                            </div>
                        <?php endif; ?>
                        <div class="danger-field-group">
                            <label>Type <code>RESET</code> to confirm <span class="req-star">*</span></label>
                            <input type="text" name="confirm_reset_text" required placeholder="Type RESET in capital letters" autocomplete="off">
                        </div>
                        <div class="account-modal-actions">
                            <button type="button" onclick="this.closest('dialog').close()">Cancel</button>
                            <button type="submit" class="btn-danger-solid">
                                <span class="btn-text-full">Wipe Health Data</span>
                                <span class="btn-text-short">Reset Data</span>
                            </button>
                        </div>
                    </form>
                </div>
            </dialog>
        <?php endif; ?>

        <?php if (in_array($user['role'] ?? '', ['member', 'gym_owner'], true)): ?>
            <!-- Delete Account Verification Modal (Danger Zone) -->
            <dialog id="deleteAccountModal" class="modal" onclick="if (event.target === this) this.close();">
                <div class="modal-header danger-modal-header">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span class="danger-modal-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
                                <line x1="12" y1="9" x2="12" y2="13" />
                                <line x1="12" y1="17" x2="12.01" y2="17" />
                            </svg>
                        </span>
                        <h3>Delete Account Permanently</h3>
                    </div>
                    <button class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                </div>
                <div class="modal-body danger-modal-body">
                    <div id="deleteAccountAlert" class="danger-modal-alert" style="display:none;"></div>

                    <!-- Step 1: Password Check & Request Email OTP -->
                    <div id="deleteAccountStep1">
                        <div class="danger-warning-box">
                            <strong>Warning: This action is permanent and irreversible.</strong>
                            <?php if (($user['role'] ?? '') === 'gym_owner'): ?>
                                <ul class="danger-impact-list">
                                    <li>Your gym facility listing and membership plans will be deactivated immediately.</li>
                                    <li>All staff and trainer accounts belonging to your gym will be permanently deactivated.</li>
                                    <li>Member accounts will remain active and intact (they will only be disaffiliated from this gym).</li>
                                    <li><strong>Financial Guardrail:</strong> Deletion is automatically blocked if your gym still has active paid member subscriptions.</li>
                                    <li>Your personal login credentials will be permanently deactivated and anonymized while keeping historical revenue receipts intact.</li>
                                </ul>
                            <?php else: ?>
                                <ul class="danger-impact-list">
                                    <li><strong>Active Membership Guardrail:</strong> Deletion is blocked while you have an active paid gym membership.</li>
                                    <li>Your physical profile, body measurements, workout progress logs, and meal logs will be permanently erased.</li>
                                    <li>Your account will be deactivated and anonymized while keeping historical payment receipts intact for gym accounting.</li>
                                </ul>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($user['password_hash'])): ?>
                            <div class="danger-field-group" style="margin-top: 12px;">
                                <label for="deleteCurrentPasswordInput">Confirm Current Password <span class="req-star">*</span></label>
                                <input
                                    type="password"
                                    id="deleteCurrentPasswordInput"
                                    placeholder="Enter your current password"
                                    autocomplete="current-password"
                                    required
                                >
                            </div>
                        <?php endif; ?>

                        <p class="danger-step-desc">
                            To verify that you are the one deleting this account, we will send a rate-limited <strong>6-digit verification code</strong> to <strong><?= h($user['email']) ?></strong>.
                        </p>
                        <div class="account-modal-actions">
                            <button type="button" onclick="document.getElementById('deleteAccountModal').close()">Cancel</button>
                            <button type="button" id="btnSendDeleteOtp" class="btn-danger-solid" onclick="requestDeleteAccountOtp()">
                                <span class="btn-text-full">Send Verification Code</span>
                                <span class="btn-text-short">Send Code</span>
                            </button>
                        </div>
                    </div>

                    <!-- Step 2: Verify OTP & Confirm DELETE -->
                    <form id="deleteAccountStep2" method="post" onsubmit="submitDeleteAccountConfirm(event)" style="display:none;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="confirm_delete_account">
                        <input type="hidden" name="ajax" value="1">

                        <p class="danger-step-desc" id="deleteOtpSentNotice">
                            Enter the 6-digit verification code sent to <strong><?= h($user['email']) ?></strong> (expires in 10 minutes).
                        </p>

                        <div class="danger-field-group">
                            <label for="deleteOtpInput">6-Digit Email Verification Code <span class="req-star">*</span></label>
                            <input
                                type="text"
                                id="deleteOtpInput"
                                name="otp_code"
                                inputmode="numeric"
                                pattern="[0-9]{6}"
                                maxlength="6"
                                placeholder="000000"
                                autocomplete="one-time-code"
                                required
                                class="danger-otp-input"
                                oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6)"
                            >
                        </div>

                        <div class="danger-field-group">
                            <label for="deleteConfirmTextInput">Type <code>DELETE</code> to confirm <span class="req-star">*</span></label>
                            <input
                                type="text"
                                id="deleteConfirmTextInput"
                                name="confirm_text"
                                placeholder="Type DELETE in capital letters"
                                autocomplete="off"
                                required
                                class="danger-confirm-input"
                            >
                        </div>

                        <div class="danger-resend-row">
                            <span class="muted" style="font-size:12px;">Didn't receive the email?</span>
                            <button type="button" id="btnResendDeleteOtp" class="btn-link-danger" onclick="requestDeleteAccountOtp()" disabled>
                                Resend Code
                            </button>
                        </div>

                        <div class="account-modal-actions">
                            <button type="button" onclick="document.getElementById('deleteAccountModal').close()">Cancel</button>
                            <button type="submit" id="btnConfirmDeleteAccount" class="btn-danger-solid">
                                <span class="btn-text-full">Verify &amp; Permanently Delete</span>
                                <span class="btn-text-short">Confirm Delete</span>
                            </button>
                        </div>
                    </form>
                </div>
            </dialog>
        <?php endif; ?>

        <?php if (($user['role'] ?? '') === 'gym_owner' && $ownerGymId): ?>
            <dialog id="pauseGymModal" class="modal" onclick="if (event.target === this) this.close();" style="width: min(92vw, 500px); max-width: calc(100vw - 24px); box-sizing: border-box; margin: auto; border-radius: 14px; overflow-x: hidden;">
                <div class="modal-header danger-modal-header" style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:16px 18px;">
                    <div style="display:flex; align-items:flex-start; gap:10px; min-width:0; flex:1;">
                        <span class="danger-modal-icon" style="background: rgba(245,158,11,0.18); color: #fbbf24; border-color: rgba(245,158,11,0.35); flex-shrink: 0;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                        </span>
                        <div style="min-width:0; flex:1;">
                            <h3 style="margin:0; font-size:15.5px; font-weight:700; color:var(--ink); line-height:1.3; word-break:break-word;">Pause Gym Listing</h3>
                            <p style="margin:2px 0 0; font-size:11.5px; color:var(--muted); line-height:1.3;">Maintenance &amp; Holiday Mode</p>
                        </div>
                    </div>
                    <button type="button" class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close" style="flex-shrink:0;">&times;</button>
                </div>
                <div class="modal-body danger-modal-body" style="padding:16px 18px; box-sizing:border-box; max-width:100%; overflow-x:hidden;">
                    <form method="post" style="margin:0; width:100%; max-width:100%; box-sizing:border-box;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_gym_pause">

                        <p style="margin:0 0 12px; font-size:12.5px; line-height:1.45; color:var(--ink);">
                            <strong><?= h($ownerGym['name'] ?? 'Your gym') ?></strong> will be hidden from the public directory. Your active members will receive an in-app notification and see an alert banner on their dashboard.
                        </p>

                        <div style="margin-bottom: 12px; width:100%; max-width:100%; box-sizing:border-box;">
                            <label for="pausePresetSelect" style="display:block; font-size:11.5px; font-weight:700; margin-bottom:5px; color:var(--ink); text-transform:uppercase; letter-spacing:0.04em;">
                                Quick Message Presets
                            </label>
                            <div style="position:relative; width:100%; max-width:100%; box-sizing:border-box;">
                                <select id="pausePresetSelect" class="pause-preset-select" onchange="document.getElementById('pauseNoticeInput').value = this.value;">
                                    <option value="Our public listings and new sign-ups are temporarily paused for maintenance/holiday mode. Your active member access remains normal. See you at your workout!">Standard: Directory Paused (Member Access Normal)</option>
                                    <option value="We are temporarily closed for facility maintenance and upgrades. Public listings are paused. Active memberships will resume automatically upon reopening.">Facility Upgrade: Temporarily Closed</option>
                                    <option value="We are taking a brief holiday break. Public sign-ups are paused during this period. Have a great holiday!">Holiday Break: Closed for Holiday</option>
                                </select>
                            </div>
                        </div>

                        <div style="margin-bottom: 14px; width:100%; max-width:100%; box-sizing:border-box;">
                            <label for="pauseNoticeInput" style="display:block; font-size:11.5px; font-weight:700; margin-bottom:5px; color:var(--ink); text-transform:uppercase; letter-spacing:0.04em;">
                                Member In-App Notice Message (Stored in Database)
                            </label>
                            <textarea id="pauseNoticeInput" name="pause_notice" rows="3" maxlength="255" class="pause-notice-textarea"><?= h(get_gym_pause_notice($ownerGymId)) ?></textarea>
                            <div style="font-size:11px; color:var(--muted); margin-top:4px;">
                                Up to 255 characters. Displayed on member dashboards &amp; in-app notification bell.
                            </div>
                        </div>

                        <div class="account-modal-actions" style="margin-top:14px;">
                            <button type="button" onclick="this.closest('dialog').close()">Cancel</button>
                            <button type="submit" class="btn-danger-zone">
                                Confirm &amp; Pause Gym
                            </button>
                        </div>
                    </form>
                </div>
            </dialog>
        <?php endif; ?>

        <!-- ==================== SCRIPTS ==================== -->
        <?php
        $gymDropdownOptions = [];
        foreach ($allGyms as $g) {
            if ($homeGymId && (int)$g['gym_id'] === $homeGymId) continue;
            $gymDropdownOptions[] = [
                'id' => (int)$g['gym_id'],
                'label' => $g['name']
            ];
        }
        ?>
        <!-- Profile Configuration & Script -->
        <script>
            window.PROFILE_CONFIG = {
                gymOptions: <?= json_encode($gymDropdownOptions) ?>
            };
        </script>
        <script src="<?= h(asset_url('js/pages/profile.js')) ?>"></script>
    </div>
<?php
    render_footer();
}
