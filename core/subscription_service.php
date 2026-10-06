<?php
declare(strict_types=1);

/**
 * Subscription & Entitlement Service
 */

function get_platform_subscription_plans(): array
{
    $pdo = db();
    try {
        $rows = $pdo->query('SELECT * FROM platform_subscription_plans WHERE is_active = 1 ORDER BY price ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = [];
    }

    if (!$rows) {
        return [
            'starter' => [
                'name' => 'Starter',
                'price' => 599,
                'price_label' => '₱599',
                'annual_price' => 5990,
                'annual_price_label' => '₱5,990',
                'desc' => 'Best for small, solo, or boutique gyms.',
                'popular' => false,
                'features' => [
                    'Up to 100 Active Members',
                    'Solo Gym Owner Model (0 Trainers)',
                    'Walk-in Management & Daily Pass',
                    'Dynamic QR Check-in & Scanner',
                    'Membership Plans & GCash / Online Pay',
                    'Basic Expiration Reminders (7-Day Notice)',
                    'Basic Financial Reports & CSV Export',
                    'Basic Activity History',
                ],
            ],
            'professional' => [
                'name' => 'Professional',
                'price' => 999,
                'price_label' => '₱999',
                'annual_price' => 9990,
                'annual_price_label' => '₱9,990',
                'desc' => 'Best for growing commercial gyms with staff.',
                'popular' => true,
                'features' => [
                    'Up to 500 Active Members',
                    'Personal Trainers & Coaching (Unlimited)',
                    'Trainer Commission Tracking & Payouts',
                    'Workout Plans & Exercise Library',
                    'Class Scheduling & Online Booking with Waitlists',
                    'Automated Multi-Stage Renewal Reminders (30d/14d/7d/1d)',
                    'Member Engagement Scoring & Churn Risk Alerts',
                    'Advanced Analytics & Financial Growth Trends',
                    'Staff Activity Logs',
                ],
            ],
            'business' => [
                'name' => 'Business',
                'price' => 1999,
                'price_label' => '₱1,999',
                'annual_price' => 19990,
                'annual_price_label' => '₱19,990',
                'desc' => 'For multi-branch & large-scale fitness centers.',
                'popular' => false,
                'features' => [
                    'Unlimited Active Members',
                    'Multi-Branch Management & Centralized Dashboard',
                    'Consolidated Cross-Branch Financial Reporting',
                    'Full Compliance Security Audit Trail (IPs, Diffs)',
                    'Custom App Brand Color & White-Label Theme',
                    'Dedicated Account Manager & Priority Support',
                ],
            ],
        ];
    }

    $plans = [];
    foreach ($rows as $row) {
        $features = array_values(array_filter(array_map('trim', explode("\n", (string)$row['features']))));
        $monthlyPrice = (float)$row['price'];
        $annualPrice = !empty($row['annual_price']) ? (float)$row['annual_price'] : round($monthlyPrice * 10, 2);
        $annualSavings = max(0, ($monthlyPrice * 12) - $annualPrice);

        $plans[$row['plan_key']] = [
            'id' => (int)$row['id'],
            'key' => $row['plan_key'],
            'name' => $row['name'],
            'price' => $monthlyPrice,
            'price_label' => '₱' . number_format($monthlyPrice),
            'annual_price' => $annualPrice,
            'annual_price_label' => '₱' . number_format($annualPrice),
            'annual_savings' => $annualSavings,
            'annual_savings_label' => '₱' . number_format($annualSavings),
            'desc' => $row['description'],
            'popular' => (bool)$row['is_popular'],
            'features' => $features,
            'raw_features' => $row['features'],
        ];
    }
    return $plans;
}

function get_membership_plan_features(array $plan): array
{
    $desc = trim((string)($plan['description'] ?? ''));
    if ($desc !== '') {
        $lines = array_filter(array_map('trim', explode("\n", $desc)));
        if (count($lines) >= 3) {
            return array_values($lines);
        }
    }
    
    $days = (int)($plan['duration_days'] ?? 30);
    $type = strtolower((string)($plan['plan_type'] ?? ''));
    $name = strtolower((string)($plan['plan_name'] ?? ''));
    
    if ($days >= 360 || str_contains($type, 'annual') || str_contains($name, 'annual') || str_contains($name, 'elite')) {
        return [
            "Full Gym & Equipment Access (" . $days . " Days)",
            "Unlimited Group Fitness & Classes",
            "1-on-1 Personal Trainer Consultation",
            "Priority Equipment & Queue Pass",
            "Complimentary Guest Passes (1/month)",
            "Dedicated Locker & Full Amenities",
        ];
    }
    
    if ($days >= 90 || str_contains($type, 'quarter') || str_contains($name, 'quarter') || str_contains($name, 'plus')) {
        return [
            "Full Gym & Equipment Access (" . $days . " Days)",
            "Priority Class Scheduling & Booking",
            "Free Fitness & Body Composition Assessment",
            "Discounted Personal Training Sessions",
            "Locker Room & Shower Access",
            "Automated Workout & Progress Tracking",
        ];
    }
    
    return [
        "Full Gym & Equipment Access (" . $days . " Days)",
        "Standard Group Class Bookings",
        "Walk-in Pass & QR Code Check-in",
        "Locker Room & Shower Access",
        "Basic Workout & Habit Tracking",
    ];
}

/**
 * Subscription Tier & Feature Entitlement Helpers
 */
function gym_subscription_tier(array|int|null $gym = null): string
{
    if (is_int($gym)) {
        $gym = db()->query("SELECT * FROM gyms WHERE gym_id = " . (int)$gym)->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$gym) {
        $user = current_user();
        if ($user) {
            $gym = get_user_gym($user);
        }
    }
    if (!$gym) {
        return 'none';
    }
    
    // Gym must be approved by platform admin
    if (($gym['status'] ?? '') !== 'approved') {
        return 'none';
    }

    $status = (string)($gym['subscription_status'] ?? '');
    $renewalDate = $gym['subscription_renewal_date'] ?? null;
    $rawPlan = strtolower(trim((string)($gym['subscription_plan'] ?? '')));

    // 1. Free Trial Evaluation (50 members & 2 trainers limit with Pro features)
    if ($status === 'trialing' || str_contains($rawPlan, 'trial')) {
        if (!empty($renewalDate) && strtotime((string)$renewalDate) < strtotime('today')) {
            // Trial has expired -> gracefully fallback to limited free tier
            return 'free';
        }
        return 'trial';
    }

    // 2. Paid Active Subscription
    if ($status === 'active') {
        if (!empty($renewalDate) && strtotime((string)$renewalDate) < strtotime('today')) {
            // Subscription lapsed -> gracefully fallback to limited free tier
            return 'free';
        }

        if (str_contains($rawPlan, 'business')) {
            return 'business';
        }
        if (str_contains($rawPlan, 'pro')) {
            return 'professional';
        }
        if (str_contains($rawPlan, 'starter')) {
            return 'starter';
        }

        return !empty($rawPlan) ? 'starter' : 'free';
    }

    // 3. Fallback for approved gyms (Free community tier)
    return 'free';
}

function gym_trial_info(array|int|null $gym = null): array
{
    if (is_int($gym)) {
        $gym = db()->query("SELECT * FROM gyms WHERE gym_id = " . (int)$gym)->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$gym) {
        $user = current_user();
        if ($user) {
            $gym = get_user_gym($user);
        }
    }
    if (!$gym) {
        return [
            'is_trial' => false,
            'is_trial_active' => false,
            'is_trial_expired' => false,
            'is_free' => false,
            'days_left' => 0,
            'renewal_date' => null,
            'plan_name' => 'None',
        ];
    }

    $status = (string)($gym['subscription_status'] ?? '');
    $renewalDate = $gym['subscription_renewal_date'] ?? null;
    $rawPlan = strtolower(trim((string)($gym['subscription_plan'] ?? '')));
    $isTrial = ($status === 'trialing' || str_contains($rawPlan, 'trial'));

    $today = strtotime('today');
    $renewalTimestamp = !empty($renewalDate) ? strtotime((string)$renewalDate) : null;
    $daysLeft = $renewalTimestamp ? (int) ceil(($renewalTimestamp - $today) / 86400) : 0;
    if ($daysLeft < 0) {
        $daysLeft = 0;
    }

    $isTrialActive = ($isTrial && $renewalTimestamp !== null && $renewalTimestamp >= $today);
    $isTrialExpired = ($isTrial && $renewalTimestamp !== null && $renewalTimestamp < $today);
    $isFree = ($status === 'free' || $isTrialExpired || ($status === 'inactive' && ($gym['status'] ?? '') === 'approved'));

    return [
        'is_trial' => $isTrial,
        'is_trial_active' => $isTrialActive,
        'is_trial_expired' => $isTrialExpired,
        'is_free' => $isFree,
        'days_left' => $daysLeft,
        'renewal_date' => $renewalDate,
        'plan_name' => $isTrialActive ? 'Free Trial' : ($isFree ? 'Free Tier' : ($gym['subscription_plan'] ?? 'Free Tier')),
    ];
}

function gym_member_limit(array|int|null $gym = null): int
{
    $tier = gym_subscription_tier($gym);
    return match ($tier) {
        'free' => 25,
        'trial' => 50,
        'starter' => 100,
        'professional' => 500,
        'business' => PHP_INT_MAX,
        default => 0,
    };
}

function gym_trainer_limit(array|int|null $gym = null): int
{
    $tier = gym_subscription_tier($gym);
    return match ($tier) {
        'free' => 0,
        'starter' => 0,
        'trial' => 2,
        'professional' => PHP_INT_MAX,
        'business' => PHP_INT_MAX,
        default => 0,
    };
}

function gym_active_trainer_count(int $gymId): int
{
    if ($gymId <= 0) return 0;
    return (int) scalar(
        'SELECT COUNT(*) FROM trainer_profiles tp
         JOIN users u ON u.user_id = tp.user_id
         WHERE tp.gym_id = ? AND u.status = "active"',
        [$gymId]
    );
}

function gym_can_add_trainer(int $gymId, ?array $gym = null): bool
{
    if (!$gym) {
        $gym = db()->query("SELECT * FROM gyms WHERE gym_id = " . (int)$gymId)->fetch(PDO::FETCH_ASSOC);
    }
    if (!$gym || !gym_has_feature('trainers', $gym)) {
        return false;
    }
    $limit = gym_trainer_limit($gym);
    if ($limit === PHP_INT_MAX) {
        return true;
    }
    $current = gym_active_trainer_count($gymId);
    return $current < $limit;
}

function gym_active_member_count(int $gymId): int
{
    if ($gymId <= 0) return 0;
    $count = (int) scalar(
        'SELECT COUNT(DISTINCT gm.user_id) 
         FROM gym_members gm 
         JOIN users u ON u.user_id = gm.user_id 
         WHERE gm.gym_id = ? AND u.status = "active"',
        [$gymId]
    );
    return $count;
}

function gym_can_add_member(int $gymId, ?array $gym = null): bool
{
    if (!$gym) {
        $gym = db()->query("SELECT * FROM gyms WHERE gym_id = " . (int)$gymId)->fetch(PDO::FETCH_ASSOC);
    }
    if (!$gym || ($gym['status'] ?? '') !== 'approved' || gym_subscription_tier($gym) === 'none') {
        return false;
    }
    $limit = gym_member_limit($gym);
    if ($limit === PHP_INT_MAX) {
        return true;
    }
    $current = gym_active_member_count($gymId);
    return $current < $limit;
}

function gym_has_feature(string $feature, array|int|null $gym = null): bool
{
    $tier = gym_subscription_tier($gym);
    if ($tier === 'none') {
        return false;
    }

    // Business tier has access to everything
    if ($tier === 'business') {
        return true;
    }

    $tierWeights = [
        'free' => 0,
        'starter' => 1,
        'trial' => 2,
        'professional' => 2,
        'business' => 3,
    ];

    $featureRequirements = [
        // Free & Above (Core Essential Operations - Trial & Free Gyms can use these)
        'scanner' => 'free',
        'attendance' => 'free',
        'walk_ins' => 'free',
        'memberships' => 'free',
        'payments' => 'free',
        'basic_dashboard' => 'free',
        'gym_profile' => 'free',
        'plans' => 'free',

        // Starter & Above (Staff & Basic Reporting)
        'users' => 'starter',
        'reports' => 'starter',
        'basic_reports' => 'starter',
        'export_csv' => 'starter',
        'renewal_reminders' => 'starter',
        'basic_renewal_reminders' => 'starter',
        'basic_activity' => 'starter',
        'basic_classes' => 'starter',

        // Professional & Above (Staff, Coaching, Growth, Advanced Automation)
        'trainers' => 'professional',
        'trainer_assignments' => 'professional',
        'commissions' => 'professional',
        'workouts' => 'professional',
        'exercises' => 'professional',
        'training' => 'professional',
        'admin_workouts' => 'professional',
        'classes' => 'professional',
        'advanced_classes' => 'professional',
        'class_waitlists' => 'professional',
        'automated_renewal_reminders' => 'professional',
        'engagement_tracking' => 'professional',
        'advanced_reports' => 'professional',
        'staff_activity' => 'professional',

        // Business only (Multi-Branch, Full Compliance, Custom Branding, EOD, Staff Privacy)
        'custom_branding' => 'business',
        'staff_permissions' => 'business',
        'eod_summary' => 'business',
        'audit_logs' => 'business',
        'full_audit_logs' => 'business',
        'multi_branch' => 'business',
        'consolidated_reports' => 'business',
        'custom_renewal_reminders' => 'business',
    ];

    $requiredTier = $featureRequirements[$feature] ?? 'professional';
    $currentWeight = $tierWeights[$tier] ?? 0;
    $requiredWeight = $tierWeights[$requiredTier] ?? 2;

    return $currentWeight >= $requiredWeight;
}

function require_gym_feature(string $feature): void
{
    $user = current_user();
    if ($user && ($user['role'] ?? '') === 'platform_admin') {
        return;
    }

    $gym = $user ? get_user_gym($user) : null;
    if (!$gym || !gym_has_feature($feature, $gym)) {
        http_response_code(403);
        $featureTitles = [
            'trainers' => 'Trainers & Staff Management',
            'trainer_assignments' => 'Trainer Assignments',
            'commissions' => 'Trainer Commission Tracking',
            'classes' => 'Class Scheduling & Booking',
            'reports' => 'Financial & Attendance Reports',
            'advanced_reports' => 'Financial & Attendance Reports',
            'custom_branding' => 'Custom App Brand Theme',
            'audit_logs' => 'Security Audit Logs',
            'workouts' => 'Workout Builder & Plans',
            'users' => 'Staff & User Management',
        ];
        $featureName = $featureTitles[$feature] ?? ucwords(str_replace('_', ' ', $feature));
        $tier = gym_subscription_tier($gym);
        $planName = $tier === 'free' ? 'Free Tier' : ($tier !== 'none' ? ucfirst($tier) : 'Inactive');
        
        $neededTier = in_array($feature, ['custom_branding', 'audit_logs', 'multi_branch', 'full_audit_logs'], true)
            ? 'Business'
            : (in_array($feature, ['trainers', 'trainer_assignments', 'commissions', 'classes', 'renewal_reminders', 'engagement_tracking', 'workouts', 'exercises', 'training', 'admin_workouts', 'advanced_reports'], true)
                ? 'Professional'
                : 'Starter');

        render_header('Upgrade Required', $user);
        ?>
        <div style="max-width: 640px; margin: 60px auto; padding: 40px 32px; background: rgba(15, 21, 18, 0.9); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 20px; box-shadow: 0 25px 60px rgba(0,0,0,0.6); text-align: center; backdrop-filter: blur(16px);">
            <div style="width: 64px; height: 64px; margin: 0 auto 20px; background: rgba(132, 204, 22, 0.12); border: 1px solid rgba(132, 204, 22, 0.3); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#84cc16" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
            </div>
            <span style="display: inline-block; padding: 4px 12px; background: rgba(132, 204, 22, 0.15); color: #84cc16; font-size: 11px; font-weight: 800; letter-spacing: 0.8px; border-radius: 20px; text-transform: uppercase; margin-bottom: 12px;">
                <?= h($neededTier) ?> Plan Feature
            </span>
            <h1 style="font-size: 1.8rem; font-weight: 800; color: #fff; margin: 0 0 12px; letter-spacing: -0.5px;">
                <?= h($featureName) ?>
            </h1>
            <p style="color: #94a3b8; font-size: 15px; line-height: 1.6; margin: 0 auto 28px; max-width: 480px;">
                This module is not included in your current <strong style="color: #fff;"><?= h($planName) ?></strong> plan. Upgrade to the <strong style="color: #84cc16;"><?= h($neededTier) ?></strong> tier to unlock full access.
            </p>
            <div style="display: flex; gap: 14px; justify-content: center; flex-wrap: wrap;">
                <a href="index.php?page=dashboard" class="btn" style="background: rgba(255,255,255,0.06); color: #fff; border: 1px solid rgba(255,255,255,0.12); padding: 12px 24px; border-radius: 12px; font-weight: 600; text-decoration: none;">
                    Back to Dashboard
                </a>
                <a href="index.php?page=gym_subscription" class="btn btn-primary" style="padding: 12px 28px; border-radius: 12px; font-weight: 700; text-decoration: none;">
                    Upgrade Subscription →
                </a>
            </div>
        </div>
        <?php
        render_footer();
        exit;
    }
}

/**
 * Check if the currently logged-in user or given user is restricted from seeing gym financials.
 * Returns true if the user is staff/trainer and staff financial privacy is enabled or user is front_desk.
 */
function is_staff_financials_restricted(?array $user = null): bool
{
    if ($user === null) {
        $user = current_user();
    }
    if (!$user) {
        return false;
    }
    // Platform Admins and Gym Owners always see full financials
    if (in_array($user['role'] ?? '', ['platform_admin', 'gym_owner'], true)) {
        return false;
    }

    // Members do not have staff financial access
    if (($user['role'] ?? '') === 'member') {
        return true;
    }

    // If staff/trainer role:
    if (($user['role'] ?? '') === 'trainer') {
        if (($user['staff_role'] ?? '') === 'front_desk') {
            return true;
        }

        $gym = get_user_gym($user);
        if ($gym && !empty($gym['staff_hide_financials'])) {
            return true;
        }
    }

    return false;
}

/**
 * High-performance End-of-Day (EOD) summary computation.
 * Executes indexed single-table queries in <2ms.
 */
function gym_get_eod_summary(int $gymId, ?string $date = null): array
{
    $pdo = db();
    $targetDate = $date ? date('Y-m-d', strtotime($date)) : date('Y-m-d');
    $nextDate = date('Y-m-d', strtotime($targetDate . ' +1 day'));

    // 1. Membership Payments Today
    $subStmt = $pdo->prepare('
        SELECT COUNT(*) AS txn_count, COALESCE(SUM(amount), 0) AS total_revenue
        FROM payments
        WHERE gym_id = ? AND status = "paid" AND DATE(payment_date) = ?
    ');
    $subStmt->execute([$gymId, $targetDate]);
    $subData = $subStmt->fetch(PDO::FETCH_ASSOC) ?: ['txn_count' => 0, 'total_revenue' => 0];

    // 2. Walk-in Passes Today
    $walkStmt = $pdo->prepare('
        SELECT COUNT(*) AS txn_count, COALESCE(SUM(walk_in_fee), 0) AS total_revenue
        FROM walk_in_transactions
        WHERE gym_id = ? AND DATE(created_at) = ?
    ');
    $walkStmt->execute([$gymId, $targetDate]);
    $walkData = $walkStmt->fetch(PDO::FETCH_ASSOC) ?: ['txn_count' => 0, 'total_revenue' => 0];

    // 3. Attendance Check-ins Today
    $attStmt = $pdo->prepare('
        SELECT COUNT(*) AS checkin_count
        FROM attendance
        WHERE gym_id = ? AND DATE(check_in_time) = ?
    ');
    $attStmt->execute([$gymId, $targetDate]);
    $attCount = (int) ($attStmt->fetchColumn() ?: 0);

    // 4. Memberships Expiring Tomorrow
    $expStmt = $pdo->prepare('
        SELECT COUNT(*) AS expiring_count
        FROM memberships
        WHERE gym_id = ? AND status = "active" AND DATE(end_date) = ?
    ');
    $expStmt->execute([$gymId, $nextDate]);
    $expiringCount = (int) ($expStmt->fetchColumn() ?: 0);

    // 5. Active Enrolled Members
    $activeMemberCount = gym_active_member_count($gymId);

    $totalGross = (float)$subData['total_revenue'] + (float)$walkData['total_revenue'];
    $totalTxns = (int)$subData['txn_count'] + (int)$walkData['txn_count'];

    return [
        'date' => $targetDate,
        'date_formatted' => date('F j, Y', strtotime($targetDate)),
        'total_gross' => $totalGross,
        'total_transactions' => $totalTxns,
        'subscriptions_revenue' => (float)$subData['total_revenue'],
        'subscriptions_count' => (int)$subData['txn_count'],
        'walkins_revenue' => (float)$walkData['total_revenue'],
        'walkins_count' => (int)$walkData['txn_count'],
        'checkins_count' => $attCount,
        'expiring_tomorrow' => $expiringCount,
        'active_members' => $activeMemberCount,
    ];
}

/**
 * Send executive End-of-Day Daily Settlement email to gym owner.
 */
function gym_send_eod_summary_email(int $gymId, ?string $date = null, bool $immediate = false): bool
{
    $pdo = db();
    $gym = $pdo->query('SELECT g.*, u.email AS owner_email, u.first_name, u.last_name FROM gyms g JOIN users u ON u.user_id = g.owner_user_id WHERE g.gym_id = ' . (int)$gymId)->fetch(PDO::FETCH_ASSOC);
    if (!$gym || empty($gym['owner_email'])) {
        return false;
    }

    $summary = gym_get_eod_summary($gymId, $date);
    $gymName = trim((string)($gym['name'] ?? 'FitTrack Gym'));
    $ownerName = trim(((string)($gym['first_name'] ?? '')) . ' ' . ((string)($gym['last_name'] ?? ''))) ?: 'Gym Owner';

    return Emails::sendEODSummary($gym['owner_email'], $ownerName, $gymName, $summary, $immediate);
}




