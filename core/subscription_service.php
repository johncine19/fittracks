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
                    'Food & Nutrition Library',
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
                    'Member Engagement Scoring & Inactivity Alerts',
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
                'desc' => 'For high-volume gyms that need room to grow.',
                'popular' => false,
                'features' => [
                    'Unlimited Active Members',
                    'Unlimited Trainers',
                    'All Professional features, including classes, trainer tools, and advanced reports',
                    'Custom Gym Brand Color',
                    'End-of-Day Summary Emails',
                    'Staff Financial Visibility Controls',
                ],
            ],
        ];
    }

    $plans = [];
    $legacyBusinessBenefits = [
        'Multi-Branch Management & Centralized Dashboard',
        'Consolidated Cross-Branch Financial Reporting',
        'Full Compliance Security Audit Trail (IPs, Diffs)',
        'Custom App Brand Color & White-Label Theme',
        'Dedicated Account Manager & Priority Support',
    ];
    $businessBenefits = [
        'Unlimited Active Members',
        'Unlimited Trainers',
        'All Professional features, including classes, trainer tools, and advanced reports',
        'Custom Gym Brand Color',
        'End-of-Day Summary Emails',
        'Staff Financial Visibility Controls',
    ];

    foreach ($rows as $row) {
        $features = array_values(array_filter(array_map('trim', explode("\n", (string)$row['features']))));
        if (($row['plan_key'] ?? '') === 'business' && array_intersect($legacyBusinessBenefits, $features)) {
            $features = $businessBenefits;
        }
        // Keep existing installations' persisted plan copy aligned with the rule-based feature wording.
        $features = array_map(static fn(string $feature): string => str_replace(
            ['Member Engagement Scoring & Churn Risk Alerts', 'Member Engagement & Churn Risk Alerts'],
            ['Member Engagement Scoring & Inactivity Alerts', 'Member Engagement Scores & Inactivity Alerts'],
            $feature
        ), $features);
        $monthlyPrice = (float)$row['price'];
        $annualPrice = !empty($row['annual_price']) ? (float)$row['annual_price'] : round($monthlyPrice * 10, 2);
        $annualSavings = max(0, ($monthlyPrice * 12) - $annualPrice);
        $description = (string)$row['description'];
        if (($row['plan_key'] ?? '') === 'business' && str_contains(strtolower($description), 'multi-branch')) {
            $description = 'For high-volume gyms that need room to grow.';
        }

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
            'desc' => $description,
            'popular' => (bool)$row['is_popular'],
            'features' => $features,
            'raw_features' => implode("\n", $features),
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
        if (str_contains($rawPlan, 'free')) {
            return 'free';
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

function gym_staff_limit(array|int|null $gym = null): int
{
    $tier = gym_subscription_tier($gym);
    return match ($tier) {
        'free' => 0,
        'starter' => 1,
        'trial' => 2,
        'professional' => 5,
        'business' => PHP_INT_MAX,
        default => 0,
    };
}

function gym_active_staff_count(int $gymId): int
{
    if ($gymId <= 0) return 0;
    return (int) scalar(
        'SELECT COUNT(*) FROM gym_staff gs
         JOIN users u ON u.user_id = gs.user_id
         WHERE gs.gym_id = ? AND u.status = "active"',
        [$gymId]
    );
}

function gym_can_add_staff(int $gymId, ?array $gym = null): bool
{
    if (!$gym) {
        $gym = db()->query("SELECT * FROM gyms WHERE gym_id = " . (int)$gymId)->fetch(PDO::FETCH_ASSOC);
    }
    if (!$gym || !gym_has_feature('staff', $gym)) {
        return false;
    }
    $limit = gym_staff_limit($gym);
    if ($limit === PHP_INT_MAX) {
        return true;
    }
    $current = gym_active_staff_count($gymId);
    return $current < $limit;
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
        'staff' => 'starter',
        'users' => 'starter',
        'reports' => 'starter',
        'basic_reports' => 'starter',
        'export_csv' => 'starter',
        'renewal_reminders' => 'starter',
        'basic_renewal_reminders' => 'starter',
        'basic_activity' => 'starter',
        'basic_classes' => 'starter',
        'food_library' => 'starter',

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
        'audit_logs' => 'professional',
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
            'food_library' => 'Food & Nutrition Library',
        ];
        $featureName = $featureTitles[$feature] ?? ucwords(str_replace('_', ' ', $feature));
        $tier = gym_subscription_tier($gym);
        $planName = $tier === 'free' ? 'Free Tier' : ($tier !== 'none' ? ucfirst($tier) : 'Inactive');
        
        $neededTier = in_array($feature, ['custom_branding', 'multi_branch', 'full_audit_logs', 'staff_permissions'], true)
            ? 'Business'
            : (in_array($feature, ['trainers', 'trainer_assignments', 'commissions', 'classes', 'renewal_reminders', 'engagement_tracking', 'workouts', 'exercises', 'training', 'admin_workouts', 'advanced_reports', 'audit_logs', 'staff_activity'], true)
                ? 'Professional'
                : 'Starter');

        render_header('Upgrade Required', $user);
        ?>
        <div class="upgrade-required-card">
            <div class="upgrade-required-icon-wrap">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
            </div>
            <span class="upgrade-required-badge">
                <?= h($neededTier) ?> Plan Feature
            </span>
            <h1 class="upgrade-required-title">
                <?= h($featureName) ?>
            </h1>
            <p class="upgrade-required-desc">
                This module is not included in your current <strong class="plan-name"><?= h($planName) ?></strong> plan. Upgrade to the <strong class="tier-name"><?= h($neededTier) ?></strong> tier to unlock full access.
            </p>
            <div class="upgrade-required-actions">
                <a href="index.php?page=dashboard" class="upgrade-required-btn-secondary">
                    Back to Dashboard
                </a>
                <a href="index.php?page=gym_subscription" class="btn btn-primary upgrade-required-btn-primary">
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

    // Staff role permission levels (front_desk = masked when gym privacy mode is on, manager = full access)
    if (($user['role'] ?? '') === 'staff') {
        if (($user['staff_role'] ?? 'front_desk') === 'manager') {
            return false;
        }
        $gym = get_user_gym($user);
        return !empty($gym['staff_hide_financials']);
    }
    if (($user['role'] ?? '') === 'trainer') {
        // Trainer floor check



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
        SELECT COUNT(*) AS txn_count, COALESCE(SUM(p.amount), 0) AS total_revenue
        FROM payments p
        JOIN memberships m ON m.membership_id = p.membership_id
        JOIN membership_plans mp ON mp.plan_id = m.plan_id
        WHERE mp.gym_id = ? AND p.status = "paid" AND DATE(p.payment_date) = ?
    ');
    $subStmt->execute([$gymId, $targetDate]);
    $subData = $subStmt->fetch(PDO::FETCH_ASSOC) ?: ['txn_count' => 0, 'total_revenue' => 0];

    // 2. Walk-in Passes Today
    $walkStmt = $pdo->prepare('
        SELECT COUNT(*) AS txn_count, COALESCE(SUM(amount_paid), 0) AS total_revenue
        FROM walk_in_transactions
        WHERE gym_id = ? AND DATE(visit_date) = ?
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
        FROM memberships m
        JOIN membership_plans mp ON mp.plan_id = m.plan_id
        WHERE mp.gym_id = ? AND m.status = "active" AND DATE(m.end_date) = ?
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
        'eod_window' => gym_get_eod_window_status($gymId),
    ];
}

/**
 * Return EOD dispatch status, 5:00 PM evening window state, and sender transparency details.
 */
function gym_get_eod_window_status(int $gymId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('
        SELECT g.last_eod_email_sent_at, g.last_eod_email_sent_by,
               g.eod_gate_time, g.pending_eod_gate_time, g.eod_gate_effective_date,
               u.first_name, u.last_name, u.role, u.staff_role
        FROM gyms g
        LEFT JOIN users u ON u.user_id = g.last_eod_email_sent_by
        WHERE g.gym_id = ?
    ');
    $stmt->execute([$gymId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Promote pending gate time if the scheduled effective date has arrived (today or earlier)
    if (!empty($row['pending_eod_gate_time']) && !empty($row['eod_gate_effective_date']) && strtotime((string)$row['eod_gate_effective_date']) <= strtotime('today')) {
        $pdo->prepare('UPDATE gyms SET eod_gate_time = pending_eod_gate_time, pending_eod_gate_time = NULL, eod_gate_effective_date = NULL WHERE gym_id = ?')
            ->execute([$gymId]);
        $row['eod_gate_time'] = $row['pending_eod_gate_time'];
        $row['pending_eod_gate_time'] = null;
        $row['eod_gate_effective_date'] = null;
    }

    $activeGateTime = !empty($row['eod_gate_time']) ? (string)$row['eod_gate_time'] : '17:00:00';
    $currentTime = date('H:i:s');
    $isWindowOpen = ($currentTime >= $activeGateTime);
    $windowOpenFormatted = date('g:i A', strtotime("1970-01-01 $activeGateTime"));
    $pendingGateFormatted = !empty($row['pending_eod_gate_time']) ? date('g:i A', strtotime("1970-01-01 " . $row['pending_eod_gate_time'])) : null;
    $pendingEffectiveFormatted = !empty($row['eod_gate_effective_date']) ? date('M j, Y', strtotime((string)$row['eod_gate_effective_date'])) : null;

    $lastSentAt = $row['last_eod_email_sent_at'] ?? null;
    $alreadySentToday = false;
    $lastSentFormatted = null;
    $sentByName = null;
    $sentByRole = null;

    if ($lastSentAt) {
        $sentDate = date('Y-m-d', strtotime($lastSentAt));
        $today = date('Y-m-d');
        if ($sentDate === $today) {
            $alreadySentToday = true;
            $lastSentFormatted = date('g:i A', strtotime($lastSentAt));
        } else {
            $lastSentFormatted = date('M j, Y g:i A', strtotime($lastSentAt));
        }

        if (!empty($row['first_name'])) {
            $sentByName = trim($row['first_name'] . ' ' . ($row['last_name'] ?? ''));
            $sentByRole = !empty($row['staff_role']) ? $row['staff_role'] : ucfirst($row['role'] ?? 'Staff');
        }
    }

    return [
        'is_window_open' => $isWindowOpen,
        'window_open_time' => $windowOpenFormatted,
        'raw_gate_time' => substr($activeGateTime, 0, 5),
        'has_pending_change' => !empty($pendingGateFormatted),
        'pending_gate_time' => $pendingGateFormatted,
        'pending_effective_date' => $pendingEffectiveFormatted,
        'current_time' => $currentTime,
        'already_sent_today' => $alreadySentToday,
        'last_sent_at' => $lastSentAt,
        'last_sent_formatted' => $lastSentFormatted,
        'last_sent_by_name' => $sentByName,
        'last_sent_by_role' => $sentByRole,
        'can_send' => ($isWindowOpen && !$alreadySentToday),
    ];
}

/**
 * Update the last EOD email sent timestamp and sender user ID for a gym.
 */
function gym_record_eod_email_sent(int $gymId, ?int $senderUserId = null): void
{
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE gyms SET last_eod_email_sent_at = NOW(), last_eod_email_sent_by = ? WHERE gym_id = ?');
    $stmt->execute([$senderUserId, $gymId]);
}

/**
 * Send executive End-of-Day Daily Settlement email to gym owner.
 */
function gym_send_eod_summary_email(int $gymId, ?string $date = null, bool $immediate = false, ?int $senderUserId = null): bool
{
    $pdo = db();
    $gym = $pdo->query('SELECT g.*, u.email AS owner_email, u.first_name, u.last_name FROM gyms g JOIN users u ON u.user_id = g.owner_user_id WHERE g.gym_id = ' . (int)$gymId)->fetch(PDO::FETCH_ASSOC);
    if (!$gym || empty($gym['owner_email'])) {
        return false;
    }

    $summary = gym_get_eod_summary($gymId, $date);
    $gymName = trim((string)($gym['name'] ?? 'FitTrack Gym'));
    $ownerName = trim(((string)($gym['first_name'] ?? '')) . ' ' . ((string)($gym['last_name'] ?? ''))) ?: 'Gym Owner';

    $senderInfo = null;
    if ($senderUserId) {
        $senderStmt = $pdo->prepare('SELECT first_name, last_name, role, staff_role FROM users WHERE user_id = ?');
        $senderStmt->execute([$senderUserId]);
        $sUser = $senderStmt->fetch(PDO::FETCH_ASSOC);
        if ($sUser) {
            $senderInfo = [
                'name' => trim($sUser['first_name'] . ' ' . ($sUser['last_name'] ?? '')),
                'role' => !empty($sUser['staff_role']) ? $sUser['staff_role'] : ucfirst($sUser['role'] ?? 'Staff'),
                'time' => date('g:i A')
            ];
        }
    }

    $sent = Emails::sendEODSummary($gym['owner_email'], $ownerName, $gymName, $summary, $immediate, $senderInfo);
    if ($sent) {
        gym_record_eod_email_sent($gymId, $senderUserId);
    }
    return $sent;
}

/** Queue one EOD email for each eligible gym for the requested local report date. */
function gym_queue_automated_eod_summaries(?string $date = null): array
{
    $pdo = db();
    $reportDate = $date ? date('Y-m-d', strtotime($date)) : date('Y-m-d', strtotime('yesterday'));
    $result = ['queued' => 0, 'already_queued' => 0, 'disabled_or_ineligible' => 0, 'failed' => 0];
    $gyms = $pdo->query("SELECT * FROM gyms WHERE status = 'approved' AND COALESCE(eod_email_summary, 1) = 1 ORDER BY gym_id")->fetchAll(PDO::FETCH_ASSOC);
    $claim = $pdo->prepare("INSERT IGNORE INTO gym_eod_email_log (gym_id, report_date, status) VALUES (?, ?, 'queued')");
    $release = $pdo->prepare('DELETE FROM gym_eod_email_log WHERE gym_id = ? AND report_date = ? AND status = \'queued\'');

    foreach ($gyms as $gym) {
        if (!gym_has_feature('eod_summary', $gym)) {
            $result['disabled_or_ineligible']++;
            continue;
        }

        $gymId = (int)$gym['gym_id'];
        $claim->execute([$gymId, $reportDate]);
        if ($claim->rowCount() === 0) {
            $result['already_queued']++;
            continue;
        }

        try {
            if (gym_send_eod_summary_email($gymId, $reportDate, false)) {
                $result['queued']++;
            } else {
                $release->execute([$gymId, $reportDate]);
                $result['failed']++;
            }
        } catch (Throwable $e) {
            $release->execute([$gymId, $reportDate]);
            error_log('Failed to queue EOD summary for gym ' . $gymId . ': ' . $e->getMessage());
            $result['failed']++;
        }
    }

    return $result;
}

/**
 * Automatically update expired gym subscription statuses in the database.
 * Changes 'active' or 'trialing' to 'expired' if subscription_renewal_date < CURDATE().
 */
function auto_expire_lapsed_gym_subscriptions(?int $targetGymId = null): int
{
    $pdo = db();
    try {
        if ($targetGymId !== null && $targetGymId > 0) {
            $stmt = $pdo->prepare("UPDATE gyms SET subscription_status = 'expired' WHERE gym_id = ? AND status = 'approved' AND subscription_status IN ('active', 'trialing') AND subscription_renewal_date IS NOT NULL AND subscription_renewal_date < CURDATE()");
            $stmt->execute([$targetGymId]);
            return (int) $stmt->rowCount();
        }
        $stmt = $pdo->prepare("UPDATE gyms SET subscription_status = 'expired' WHERE status = 'approved' AND subscription_status IN ('active', 'trialing') AND subscription_renewal_date IS NOT NULL AND subscription_renewal_date < CURDATE()");
        $stmt->execute();
        return (int) $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('Error in auto_expire_lapsed_gym_subscriptions: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Automatically update expired member memberships in the database.
 * Changes 'active' to 'expired' if end_date < CURDATE().
 * Also synchronizes trainer assignments if any plans lapsed.
 */
function auto_expire_lapsed_member_subscriptions(?int $targetUserId = null): int
{
    $pdo = db();
    try {
        if ($targetUserId !== null && $targetUserId > 0) {
            $stmt = $pdo->prepare("UPDATE memberships SET status = 'expired' WHERE user_id = ? AND status = 'active' AND end_date < CURDATE()");
            $stmt->execute([$targetUserId]);
            $count = (int) $stmt->rowCount();
            if ($count > 0 && function_exists('sync_trainer_assignments_with_memberships')) {
                sync_trainer_assignments_with_memberships($targetUserId);
            }
            if ($count > 0 && function_exists('pause_plans_for_lapsed_memberships')) {
                pause_plans_for_lapsed_memberships($targetUserId);
            }
            return $count;
        }

        $stmt = $pdo->prepare("UPDATE memberships SET status = 'expired' WHERE status = 'active' AND end_date < CURDATE()");
        $stmt->execute();
        $count = (int) $stmt->rowCount();
        if ($count > 0 && function_exists('sync_trainer_assignments_with_memberships')) {
            sync_trainer_assignments_with_memberships(null);
        }
        if ($count > 0 && function_exists('pause_plans_for_lapsed_memberships')) {
            pause_plans_for_lapsed_memberships(null);
        }
        return $count;
    } catch (Throwable $e) {
        error_log('Error in auto_expire_lapsed_member_subscriptions: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Dispatch automated 3-day renewal reminder emails to members whose active plans expire soon.
 */
function process_member_renewal_reminder_emails(?int $onlyUserId = null): int
{
    $pdo = db();
    $userFilter = $onlyUserId !== null ? 'AND m.user_id = ' . (int)$onlyUserId : '';
    $sql = "
        SELECT m.membership_id, m.user_id, m.end_date, p.plan_name, p.gym_id, g.name AS gym_name,
               u.email, CONCAT(u.first_name, ' ', u.last_name) AS member_name
        FROM memberships m
        JOIN membership_plans p ON p.plan_id = m.plan_id
        JOIN gyms g ON g.gym_id = p.gym_id
        JOIN users u ON u.user_id = m.user_id
        LEFT JOIN renewal_email_log rel ON rel.recipient_type = 'member' 
             AND rel.target_id = m.membership_id 
             AND rel.notice_type = 'reminder' 
             AND rel.reference_date = m.end_date
        WHERE m.status = 'active'
          AND m.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
          AND rel.id IS NULL
          {$userFilter}
    ";

    $candidates = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $sent = 0;

    $claimStmt = $pdo->prepare("INSERT IGNORE INTO renewal_email_log (recipient_type, target_id, notice_type, reference_date) VALUES ('member', ?, 'reminder', ?)");

    foreach ($candidates as $row) {
        if (empty($row['email'])) {
            continue;
        }

        if (!empty($row['gym_id'])) {
            $gymRow = $pdo->query("SELECT * FROM gyms WHERE gym_id = " . (int)$row['gym_id'])->fetch(PDO::FETCH_ASSOC);
            if ($gymRow && !gym_has_feature('renewal_reminders', $gymRow)) {
                continue;
            }
        }

        $claimStmt->execute([(int)$row['membership_id'], $row['end_date']]);
        if ($claimStmt->rowCount() > 0) {
            $daysLeft = (int)ceil((strtotime($row['end_date']) - strtotime(date('Y-m-d'))) / 86400);
            Emails::sendMemberRenewalReminder(
                (string)$row['email'],
                (string)$row['member_name'],
                (string)$row['gym_name'],
                (string)$row['plan_name'],
                (string)$row['end_date'],
                $daysLeft
            );
            $sent++;
        }
    }

    return $sent;
}

/**
 * Dispatch automated expiration emails to members whose plans have expired.
 */
function process_member_plan_expired_emails(?int $onlyUserId = null): int
{
    $pdo = db();
    $userFilter = $onlyUserId !== null ? 'AND m.user_id = ' . (int)$onlyUserId : '';
    $sql = "
        SELECT m.membership_id, m.user_id, m.end_date, p.plan_name, g.name AS gym_name,
               u.email, CONCAT(u.first_name, ' ', u.last_name) AS member_name
        FROM memberships m
        JOIN membership_plans p ON p.plan_id = m.plan_id
        JOIN gyms g ON g.gym_id = p.gym_id
        JOIN users u ON u.user_id = m.user_id
        LEFT JOIN renewal_email_log rel ON rel.recipient_type = 'member' 
             AND rel.target_id = m.membership_id 
             AND rel.notice_type = 'expired' 
             AND rel.reference_date = m.end_date
        WHERE m.status IN ('active', 'expired')
          AND m.end_date < CURDATE()
          AND m.end_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
          AND rel.id IS NULL
          AND NOT EXISTS (
              SELECT 1 FROM memberships m2 
              WHERE m2.user_id = m.user_id AND m2.status = 'active' AND m2.end_date >= CURDATE()
          )
          {$userFilter}
    ";

    $candidates = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $sent = 0;

    $claimStmt = $pdo->prepare("INSERT IGNORE INTO renewal_email_log (recipient_type, target_id, notice_type, reference_date) VALUES ('member', ?, 'expired', ?)");

    foreach ($candidates as $row) {
        if (empty($row['email'])) {
            continue;
        }

        $claimStmt->execute([(int)$row['membership_id'], $row['end_date']]);
        if ($claimStmt->rowCount() > 0) {
            Emails::sendMemberPlanExpiredNotice(
                (string)$row['email'],
                (string)$row['member_name'],
                (string)$row['gym_name'],
                (string)$row['plan_name'],
                (string)$row['end_date']
            );
            $sent++;
        }
    }

    return $sent;
}

/**
 * Dispatch automated 7-day renewal reminder emails to Gym Owners for their platform SaaS subscription.
 */
function process_gym_owner_renewal_reminder_emails(): int
{
    $pdo = db();
    $sql = "
        SELECT g.gym_id, g.name AS gym_name, g.subscription_plan, g.subscription_renewal_date,
               u.email, CONCAT(u.first_name, ' ', u.last_name) AS owner_name
        FROM gyms g
        JOIN users u ON u.user_id = g.owner_user_id
        LEFT JOIN renewal_email_log rel ON rel.recipient_type = 'gym_owner'
             AND rel.target_id = g.gym_id
             AND rel.notice_type = 'reminder'
             AND rel.reference_date = g.subscription_renewal_date
        WHERE g.status = 'approved'
          AND g.subscription_status = 'active'
          AND g.subscription_renewal_date IS NOT NULL
          AND g.subscription_renewal_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND rel.id IS NULL
    ";

    $candidates = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $plans = get_platform_subscription_plans();
    $sent = 0;

    $claimStmt = $pdo->prepare("INSERT IGNORE INTO renewal_email_log (recipient_type, target_id, notice_type, reference_date) VALUES ('gym_owner', ?, 'reminder', ?)");

    foreach ($candidates as $row) {
        if (empty($row['email'])) {
            continue;
        }

        $planKey = strtolower((string)$row['subscription_plan']);
        $planMeta = $plans[$planKey] ?? ['name' => ucfirst($planKey), 'price' => 599.00];
        $planName = $planMeta['name'] ?? ucfirst($planKey);
        $amount = (float)($planMeta['price'] ?? 599.00);

        $claimStmt->execute([(int)$row['gym_id'], $row['subscription_renewal_date']]);
        if ($claimStmt->rowCount() > 0) {
            $daysLeft = (int)ceil((strtotime($row['subscription_renewal_date']) - strtotime(date('Y-m-d'))) / 86400);
            Emails::sendGymOwnerRenewalReminder(
                (string)$row['email'],
                (string)$row['owner_name'],
                (string)$row['gym_name'],
                $planName,
                (string)$row['subscription_renewal_date'],
                $daysLeft,
                $amount,
                'monthly'
            );
            $sent++;
        }
    }

    return $sent;
}

/**
 * Dispatch automated expiration notice emails to Gym Owners whose platform SaaS subscription has expired.
 */
function process_gym_owner_plan_expired_emails(): int
{
    $pdo = db();
    $sql = "
        SELECT g.gym_id, g.name AS gym_name, g.subscription_plan, g.subscription_renewal_date,
               u.email, CONCAT(u.first_name, ' ', u.last_name) AS owner_name
        FROM gyms g
        JOIN users u ON u.user_id = g.owner_user_id
        LEFT JOIN renewal_email_log rel ON rel.recipient_type = 'gym_owner'
             AND rel.target_id = g.gym_id
             AND rel.notice_type = 'expired'
             AND rel.reference_date = g.subscription_renewal_date
        WHERE g.status = 'approved'
          AND g.subscription_status = 'expired'
          AND g.subscription_renewal_date IS NOT NULL
          AND g.subscription_renewal_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
          AND rel.id IS NULL
    ";

    $candidates = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $plans = get_platform_subscription_plans();
    $sent = 0;

    $claimStmt = $pdo->prepare("INSERT IGNORE INTO renewal_email_log (recipient_type, target_id, notice_type, reference_date) VALUES ('gym_owner', ?, 'expired', ?)");

    foreach ($candidates as $row) {
        if (empty($row['email'])) {
            continue;
        }

        $planKey = strtolower((string)$row['subscription_plan']);
        $planMeta = $plans[$planKey] ?? ['name' => ucfirst($planKey)];
        $planName = $planMeta['name'] ?? ucfirst($planKey);

        $claimStmt->execute([(int)$row['gym_id'], $row['subscription_renewal_date']]);
        if ($claimStmt->rowCount() > 0) {
            Emails::sendGymOwnerPlanExpiredNotice(
                (string)$row['email'],
                (string)$row['owner_name'],
                (string)$row['gym_name'],
                $planName,
                (string)$row['subscription_renewal_date']
            );
            $sent++;
        }
    }

    return $sent;
}

/**
 * Master runner for all renewal reminder and expiration notices.
 */
function process_all_renewal_email_notices(): array
{
    return [
        'member_reminders' => process_member_renewal_reminder_emails(),
        'member_expired'   => process_member_plan_expired_emails(),
        'gym_reminders'    => process_gym_owner_renewal_reminder_emails(),
        'gym_expired'      => process_gym_owner_plan_expired_emails(),
    ];
}

