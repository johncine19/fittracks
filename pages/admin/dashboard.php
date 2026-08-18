<?php

declare(strict_types=1);

function calc_revenue_trend(PDO $pdo): string
{
    return Cache::remember('dashboard_revenue_trend', 300, function () use ($pdo) {
        $thisMonth = (float) $pdo->query(
            'SELECT SUM(revenue) FROM (
                SELECT amount AS revenue FROM payments WHERE status="paid" AND DATE_FORMAT(payment_date,"%Y-%m")=DATE_FORMAT(CURDATE(),"%Y-%m")
                UNION ALL
                SELECT amount_paid AS revenue FROM walk_in_transactions WHERE DATE_FORMAT(visit_date,"%Y-%m")=DATE_FORMAT(CURDATE(),"%Y-%m")
            ) AS combined'
        )->fetchColumn();
        $lastMonth = (float) $pdo->query(
            'SELECT SUM(revenue) FROM (
                SELECT amount AS revenue FROM payments WHERE status="paid" AND DATE_FORMAT(payment_date,"%Y-%m")=DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 1 MONTH),"%Y-%m")
                UNION ALL
                SELECT amount_paid AS revenue FROM walk_in_transactions WHERE DATE_FORMAT(visit_date,"%Y-%m")=DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 1 MONTH),"%Y-%m")
            ) AS combined'
        )->fetchColumn();
        if ($lastMonth == 0) return 'No data last month';
        $pct = round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1);
        return ($pct >= 0 ? '▲ ' : '▼ ') . abs($pct) . '% vs last month';
    });
}

function calc_member_trend(PDO $pdo): string
{
    return Cache::remember('dashboard_member_trend', 300, function () use ($pdo) {
        $thisMonth = (int) $pdo->query(
            'SELECT COUNT(*) FROM users WHERE role="member" AND status="active"
             AND DATE_FORMAT(created_at,"%Y-%m")=DATE_FORMAT(CURDATE(),"%Y-%m")'
        )->fetchColumn();
        $lastMonth = (int) $pdo->query(
            'SELECT COUNT(*) FROM users WHERE role="member" AND status="active"
             AND DATE_FORMAT(created_at,"%Y-%m")=DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 1 MONTH),"%Y-%m")'
        )->fetchColumn();
        if ($lastMonth == 0) return $thisMonth > 0 ? '+' . $thisMonth . ' new this month' : 'No new members yet';
        $pct = round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1);
        return ($pct >= 0 ? '▲ ' : '▼ ') . abs($pct) . '% vs last month';
    });
}

function calc_checkin_trend(PDO $pdo): string
{
    return Cache::remember('dashboard_checkin_trend', 300, function () use ($pdo) {
        $today = (int) $pdo->query(
            'SELECT COUNT(*) FROM attendance WHERE DATE(check_in_time)=CURDATE()'
        )->fetchColumn();
        $yesterday = (int) $pdo->query(
            'SELECT COUNT(*) FROM attendance WHERE DATE(check_in_time)=DATE_SUB(CURDATE(),INTERVAL 1 DAY)'
        )->fetchColumn();
        if ($yesterday == 0) return 'No data yesterday';
        $pct = round((($today - $yesterday) / $yesterday) * 100, 1);
        return ($pct >= 0 ? '▲ ' : '▼ ') . abs($pct) . '% vs yesterday';
    });
}

function dashboard(): void
{
    $user = require_login();
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
    } elseif ($user['role'] === 'member') {
        require_once 'pages/member/dashboard.php';
        member_dashboard($pdo, $user);
    }

    render_footer();
}
