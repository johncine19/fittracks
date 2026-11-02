<?php

declare(strict_types=1);

function calc_revenue_trend(PDO $pdo, ?int $gymId = null): string
{
    $cacheKey = 'dashboard_revenue_trend' . ($gymId ? '_' . $gymId : '_platform');
    return Cache::remember($cacheKey, 300, function () use ($pdo, $gymId) {
        $thisMonthStart = date('Y-m-01');
        $lastMonthStart = (new DateTime('first day of last month'))->format('Y-m-01');
        if ($gymId === null) {
            // Platform Admin: revenue is from gym owner subscription payments
            $stmt = $pdo->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN payment_date >= ? THEN amount ELSE 0 END), 0) AS this_month,
                    COALESCE(SUM(CASE WHEN payment_date >= ? AND payment_date < ? THEN amount ELSE 0 END), 0) AS last_month
                FROM gym_subscription_payments
                WHERE status = 'paid' AND payment_date >= ?
            ");
            $stmt->execute([$thisMonthStart, $lastMonthStart, $thisMonthStart, $lastMonthStart]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $thisMonth = (float) ($row['this_month'] ?? 0);
            $lastMonth = (float) ($row['last_month'] ?? 0);
        } else {
            $stmt = $pdo->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN rev_date >= ? THEN revenue ELSE 0 END), 0) AS this_month,
                    COALESCE(SUM(CASE WHEN rev_date >= ? AND rev_date < ? THEN revenue ELSE 0 END), 0) AS last_month
                FROM (
                    SELECT p.amount AS revenue, p.payment_date AS rev_date
                    FROM payments p
                    JOIN memberships m ON m.membership_id = p.membership_id
                    JOIN membership_plans mp ON mp.plan_id = m.plan_id
                    WHERE p.status = 'paid' AND mp.gym_id = ? AND p.payment_date >= ?
                    UNION ALL
                    SELECT amount_paid AS revenue, visit_date AS rev_date
                    FROM walk_in_transactions
                    WHERE gym_id = ? AND visit_date >= ?
                ) AS combined
            ");
            $stmt->execute([$thisMonthStart, $lastMonthStart, $thisMonthStart, $gymId, $lastMonthStart, $gymId, $lastMonthStart]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $thisMonth = (float) ($row['this_month'] ?? 0);
            $lastMonth = (float) ($row['last_month'] ?? 0);
        }
        if ($lastMonth == 0) return $thisMonth > 0 ? money($thisMonth) . ' this month' : 'No data last month';
        $pct = round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1);
        return ($pct >= 0 ? '▲ ' : '▼ ') . abs($pct) . '% vs last month';
    });
}

function calc_member_trend(PDO $pdo, ?int $gymId = null): string
{
    $cacheKey = 'dashboard_member_trend' . ($gymId ? '_' . $gymId : '');
    return Cache::remember($cacheKey, 300, function () use ($pdo, $gymId) {
        $thisMonthStart = date('Y-m-01');
        $lastMonthStart = (new DateTime('first day of last month'))->format('Y-m-01');
        $gymJoin = $gymId ? " JOIN gym_members gm ON gm.user_id = u.user_id AND gm.gym_id = " . (int)$gymId : "";
        $stmt = $pdo->prepare("
            SELECT
                COUNT(DISTINCT CASE WHEN u.created_at >= ? THEN u.user_id END) AS this_month,
                COUNT(DISTINCT CASE WHEN u.created_at >= ? AND u.created_at < ? THEN u.user_id END) AS last_month
            FROM users u
            $gymJoin
            WHERE u.role = 'member' AND u.status = 'active' AND u.created_at >= ?
        ");
        $stmt->execute([$thisMonthStart, $lastMonthStart, $thisMonthStart, $lastMonthStart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $thisMonth = (int) ($row['this_month'] ?? 0);
        $lastMonth = (int) ($row['last_month'] ?? 0);
        if ($lastMonth == 0) return $thisMonth > 0 ? '+' . $thisMonth . ' new this month' : 'No new signups yet';
        if ($thisMonth == 0) return '0 new this month';
        $pct = round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1);
        return ($pct >= 0 ? '▲ ' : '▼ ') . abs($pct) . '% vs last month';
    });
}

function calc_checkin_trend(PDO $pdo, ?int $gymId = null): string
{
    $cacheKey = 'dashboard_checkin_trend' . ($gymId ? '_' . $gymId : '');
    return Cache::remember($cacheKey, 300, function () use ($pdo, $gymId) {
        $gymFilter = $gymId ? " AND gym_id = " . (int)$gymId : "";
        $row = $pdo->query("
            SELECT
                COALESCE(SUM(CASE WHEN check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY THEN 1 ELSE 0 END), 0) AS today_cnt,
                COALESCE(SUM(CASE WHEN check_in_time >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND check_in_time < CURDATE() THEN 1 ELSE 0 END), 0) AS yesterday_cnt
            FROM attendance
            WHERE check_in_time >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND check_in_time < CURDATE() + INTERVAL 1 DAY $gymFilter
        ")->fetch(PDO::FETCH_ASSOC) ?: [];
        $today = (int) ($row['today_cnt'] ?? 0);
        $yesterday = (int) ($row['yesterday_cnt'] ?? 0);
        if ($yesterday == 0) return 'No data yesterday';
        $pct = round((($today - $yesterday) / $yesterday) * 100, 1);
        return ($pct >= 0 ? '▲ ' : '▼ ') . abs($pct) . '% vs yesterday';
    });
}

function dashboard(): void
{
    $user = require_login();

    // Return staff EOD API responses before the dashboard renders its HTML shell.
    if (($user['role'] ?? '') === 'staff' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array(post('action'), ['get_eod_data', 'send_eod_email'], true)) {
        header('Content-Type: application/json; charset=utf-8');
        verify_csrf();
        try {
            $gym = get_user_gym($user);
            $gymId = (int)($gym['gym_id'] ?? 0);
            if ($gymId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Your staff account is not assigned to a gym.']);
                exit;
            }

            if (post('action') === 'get_eod_data') {
                $summary = gym_get_eod_summary($gymId);
                $isMasked = is_staff_financials_restricted($user);
                $summary['is_masked'] = $isMasked;
                if ($isMasked) {
                    $summary['total_gross_display'] = '••••••';
                    $summary['subscriptions_revenue_display'] = '••••••';
                    $summary['walkins_revenue_display'] = '••••••';
                    // Zero raw currency fields to prevent network inspection
                    $summary['total_gross'] = 0;
                    $summary['subscriptions_revenue'] = 0;
                    $summary['walkins_revenue'] = 0;
                } else {
                    $summary['total_gross_display'] = '₱' . number_format((float)($summary['total_gross'] ?? 0), 2);
                    $summary['subscriptions_revenue_display'] = '₱' . number_format((float)($summary['subscriptions_revenue'] ?? 0), 2);
                    $summary['walkins_revenue_display'] = '₱' . number_format((float)($summary['walkins_revenue'] ?? 0), 2);
                }
                $gymRowStmt = db()->prepare('SELECT name, logo_url, brand_color FROM gyms WHERE gym_id = ?');
                $gymRowStmt->execute([$gymId]);
                echo json_encode(['success' => true, 'data' => $summary, 'gym' => $gymRowStmt->fetch(PDO::FETCH_ASSOC)]);
            } else {
                $wStatus = gym_get_eod_window_status($gymId);
                if (!$wStatus['is_window_open']) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'The End-of-Day settlement email unlocks at 5:00 PM when daily shift closing begins.'
                    ]);
                    exit;
                }
                if ($wStatus['already_sent_today']) {
                    $by = !empty($wStatus['last_sent_by_name']) ? " by {$wStatus['last_sent_by_name']}" : '';
                    echo json_encode([
                        'success' => false,
                        'error' => "An EOD settlement email was already sent today at {$wStatus['last_sent_formatted']}{$by}."
                    ]);
                    exit;
                }
                $sent = gym_send_eod_summary_email($gymId, null, true, (int)($user['user_id'] ?? 0));
                echo json_encode([
                    'success' => $sent,
                    'message' => $sent ? 'Daily Settlement summary dispatched to the gym owner.' : 'Unable to send the settlement email. Please check the gym email and SMTP settings.'
                ]);
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Unable to process the settlement request: ' . $e->getMessage()]);
        }
        exit;
    }

    // AJAX API for Dashboard Attendance Activity & Peak Hours
    if (isset($_GET['action']) && $_GET['action'] === 'attendance_activity_api') {
        require_once 'pages/member/dashboard.php';
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(get_dashboard_attendance_activity_data($user, $_GET));
        exit;
    }

    render_header('Dashboard', $user);

    $pdo = db();
    if ($user['role'] === 'platform_admin') {
        require_once 'pages/platform_admin/dashboard.php';
        platform_admin_dashboard($pdo, $user);
    } elseif ($user['role'] === 'admin' || $user['role'] === 'gym_owner') {
        require_once 'pages/gym_owner/dashboard.php';
        admin_dashboard($pdo, $user);
    } elseif ($user['role'] === 'trainer') {
        require_once 'pages/trainer/dashboard.php';
        trainer_dashboard($pdo, $user);
    } elseif ($user['role'] === 'staff') {
        require_once 'pages/staff/dashboard.php';
        staff_dashboard($pdo, $user);
    } elseif ($user['role'] === 'member') {
        require_once 'pages/member/dashboard.php';
        member_dashboard($pdo, $user);
    }

    render_footer();
}
