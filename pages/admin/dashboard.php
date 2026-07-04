<?php

declare(strict_types=1);

function calc_revenue_trend(PDO $pdo): string
{
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
}

function calc_member_trend(PDO $pdo): string
{
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
}

function calc_checkin_trend(PDO $pdo): string
{
    $today = (int) $pdo->query(
        'SELECT COUNT(*) FROM attendance WHERE DATE(check_in_time)=CURDATE()'
    )->fetchColumn();
    $yesterday = (int) $pdo->query(
        'SELECT COUNT(*) FROM attendance WHERE DATE(check_in_time)=DATE_SUB(CURDATE(),INTERVAL 1 DAY)'
    )->fetchColumn();
    if ($yesterday == 0) return 'No data yesterday';
    $pct = round((($today - $yesterday) / $yesterday) * 100, 1);
    return ($pct >= 0 ? '▲ ' : '▼ ') . abs($pct) . '% vs yesterday';
}

function admin_dashboard(PDO $pdo): void
{
    $members      = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "member" AND status = "active"')->fetchColumn();
    $revenue = (float) $pdo->query(
        'SELECT SUM(revenue) FROM (
            SELECT amount AS revenue FROM payments WHERE status = "paid" AND payment_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01")
            UNION ALL
            SELECT amount_paid AS revenue FROM walk_in_transactions WHERE visit_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01")
        ) AS combined'
    )->fetchColumn();
    $classesToday = (int) $pdo->query('SELECT COUNT(*) FROM class_schedules WHERE DATE(start_datetime) = CURDATE()')->fetchColumn();
    $checkinsToday = (int) $pdo->query('SELECT COUNT(*) FROM attendance WHERE DATE(check_in_time) = CURDATE()')->fetchColumn();

    $revenueTrend = calc_revenue_trend($pdo);
    $memberTrend  = calc_member_trend($pdo);
    $checkinTrend = calc_checkin_trend($pdo);

    $monthStart = (new DateTime('first day of this month'))->modify('-5 months');
    $monthlyRows = query_all(
        'SELECT month_key, COALESCE(SUM(total), 0) AS total FROM (
            SELECT DATE_FORMAT(payment_date, "%Y-%m") AS month_key, amount AS total FROM payments WHERE status = "paid" AND payment_date >= ?
            UNION ALL
            SELECT DATE_FORMAT(visit_date, "%Y-%m") AS month_key, amount_paid AS total FROM walk_in_transactions WHERE visit_date >= ?
        ) AS combined GROUP BY month_key',
        [$monthStart->format('Y-m-01'), $monthStart->format('Y-m-01')]
    );
    $monthlyTotals = [];
    foreach ($monthlyRows as $row) {
        $monthlyTotals[$row['month_key']] = (float) $row['total'];
    }
    $monthLabels = $monthlyValues = [];
    for ($i = 0; $i < 6; $i++) {
        $month = (clone $monthStart)->modify('+' . $i . ' months');
        $monthLabels[]  = $month->format('M');
        $monthlyValues[] = $monthlyTotals[$month->format('Y-m')] ?? 0;
    }
    $weeklyRows = $pdo->query('SELECT DAYOFWEEK(check_in_time) AS weekday, COUNT(*) AS total FROM attendance WHERE check_in_time >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY weekday')->fetchAll();
    $weekMap = array_fill(1, 7, 0);
    foreach ($weeklyRows as $row) {
        $weekMap[(int) $row['weekday']] = (int) $row['total'];
    }
    $week = [
        'Mon' => $weekMap[2],
        'Tue' => $weekMap[3],
        'Wed' => $weekMap[4],
        'Thu' => $weekMap[5],
        'Fri' => $weekMap[6],
        'Sat' => $weekMap[7],
        'Sun' => $weekMap[1],
    ];
    $todayClasses = $pdo->query('SELECT c.class_name, c.capacity, s.start_datetime, COALESCE(CONCAT(u.first_name, " ", u.last_name), "Open trainer") AS trainer, (SELECT COUNT(*) FROM class_bookings b WHERE b.schedule_id = s.schedule_id AND b.booking_status = "booked") AS booked FROM class_schedules s JOIN classes c ON c.class_id = s.class_id LEFT JOIN users u ON u.user_id = c.instructor_id WHERE DATE(s.start_datetime) = CURDATE() ORDER BY s.start_datetime LIMIT 4')->fetchAll();
    $recent = $pdo->query('SELECT a.check_in_time, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name, u.profile_picture FROM attendance a JOIN users u ON u.user_id = a.user_id ORDER BY a.check_in_time DESC LIMIT 5')->fetchAll();

    // Revenue trend % for chart panel label
    $lastMonthRevenue = (float) $pdo->query(
        'SELECT SUM(revenue) FROM (
            SELECT amount AS revenue FROM payments WHERE status="paid" AND DATE_FORMAT(payment_date,"%Y-%m")=DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 1 MONTH),"%Y-%m")
            UNION ALL
            SELECT amount_paid AS revenue FROM walk_in_transactions WHERE DATE_FORMAT(visit_date,"%Y-%m")=DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 1 MONTH),"%Y-%m")
        ) AS combined'
    )->fetchColumn();
    $revPct = $lastMonthRevenue > 0
        ? round((($revenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1)
        : null;
    $revPctLabel = $revPct !== null ? ($revPct >= 0 ? '+' : '') . $revPct . '%' : '—';

    $chartPayload = [
        'revenue'  => ['labels' => $monthLabels, 'values' => $monthlyValues],
        'checkins' => ['labels' => array_keys($week), 'values' => array_values($week)],
    ];
?>
    <?php render_skeleton_stats(4); ?>
    <section class="dash-grid stats-row skeleton-content sk-display-grid">
        <?php dashboard_stat('Total members',    (string) $members,    'Active subscriptions',        $memberTrend,   'MB', true); ?>
        <?php dashboard_stat('Monthly revenue',  money($revenue),      'Billed this month',           $revenueTrend,  '$'); ?>
        <?php dashboard_stat('Classes today',    (string) $classesToday, 'With open schedules',       'Calendar updated', 'CL'); ?>
        <?php dashboard_stat('Check-ins today',  (string) $checkinsToday, 'Updated from attendance logs', $checkinTrend, 'AT'); ?>
    </section>
    <div class="skeleton-wrapper"><section class="dash-grid chart-row" style="margin-top:24px">
        <div class="sk-card" style="min-height:308px"><div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:18px"><div><div class="sk sk-title" style="width:140px;margin-bottom:6px"></div><div class="sk sk-text short" style="height:11px"></div></div><div class="sk sk-text" style="width:50px;height:24px;border-radius:999px;margin:0"></div></div><div class="sk sk-rect chart"></div></div>
        <div class="sk-card" style="min-height:308px"><div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:18px"><div><div class="sk sk-title" style="width:140px;margin-bottom:6px"></div><div class="sk sk-text short" style="height:11px"></div></div></div><div class="sk sk-rect chart"></div></div>
    </section></div>
    <section class="dash-grid chart-row skeleton-content sk-display-grid">
        <article class="panel chart-panel revenue-panel">
            <div class="panel-title">
                <div>
                    <h2>Revenue Trend</h2>
                    <p>Last 6 months</p>
                </div><span><?= h($revPctLabel) ?></span>
            </div>
            <div class="chart-canvas"><canvas id="revenueTrendChart"></canvas></div>
        </article>
        <article class="panel chart-panel">
            <div class="panel-title">
                <div>
                    <h2>Weekly Check-ins</h2>
                    <p>This week</p>
                </div>
            </div>
            <div class="chart-canvas compact"><canvas id="weeklyCheckinsChart"></canvas></div>
        </article>
    </section>
    <script>
        window.apexDashboardCharts = <?= json_encode($chartPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    </script>
    <script src="assets/dashboard-charts.js" defer></script>
    <div class="skeleton-wrapper"><section class="dash-grid lower-row" style="margin-top:24px">
        <div class="sk-card"><div class="sk sk-title" style="width:130px;margin-bottom:14px"></div><div class="list-stack" style="gap:10px"><?php for($s=0;$s<4;$s++): ?><div class="sk-list-item"><div class="sk" style="width:8px;height:8px;border-radius:50%"></div><div class="sk-list-item-lines"><div class="sk sk-text medium" style="margin:0"></div><div class="sk sk-text short" style="margin:0;height:11px"></div></div><div class="sk sk-text" style="width:40px;margin:0;height:11px"></div></div><?php endfor; ?></div></div>
        <div class="sk-card"><div class="sk sk-title" style="width:130px;margin-bottom:14px"></div><div class="list-stack" style="gap:10px"><?php for($s=0;$s<4;$s++): ?><div class="sk-list-item"><div class="sk sk-circle"></div><div class="sk-list-item-lines"><div class="sk sk-text medium" style="margin:0"></div><div class="sk sk-text short" style="margin:0;height:11px"></div></div><div class="sk sk-text" style="width:60px;margin:0;height:11px"></div></div><?php endfor; ?></div></div>
        <div class="sk-card"><div class="sk sk-title" style="width:150px;margin-bottom:14px"></div><div class="list-stack" style="gap:10px"><?php for($s=0;$s<4;$s++): ?><div class="sk-list-item"><div class="sk sk-circle"></div><div class="sk-list-item-lines"><div class="sk sk-text medium" style="margin:0"></div><div class="sk sk-text short" style="margin:0;height:11px"></div></div><div class="sk sk-text" style="width:60px;margin:0;height:11px"></div></div><?php endfor; ?></div></div>
        <div class="sk-card"><div class="sk sk-title" style="width:180px;margin-bottom:14px"></div><div class="list-stack" style="gap:10px"><?php for($s=0;$s<4;$s++): ?><div class="sk-list-item"><div class="sk sk-circle"></div><div class="sk-list-item-lines"><div class="sk sk-text medium" style="margin:0"></div><div class="sk sk-text short" style="margin:0;height:11px"></div></div><div class="sk sk-text" style="width:40px;margin:0;height:11px"></div></div><?php endfor; ?></div></div>
    </section></div>
    <section class="dash-grid lower-row skeleton-content sk-display-grid">
        <article class="panel">
            <h2>Today's Classes</h2>
            <div class="list-stack">
                <?php foreach ($todayClasses as $class): $pct = min(100, ((int) $class['booked'] / max(1, (int) $class['capacity'])) * 100); ?>
                    <div class="class-row">
                        <span class="dot"></span>
                        <div><strong><?= h($class['class_name']) ?></strong><small><?= h($class['trainer']) ?> - <?= h(date('h:i A', strtotime($class['start_datetime']))) ?></small></div>
                        <b><?= (int) $class['booked'] ?>/<?= (int) $class['capacity'] ?><small>booked</small></b>
                        <i><span style="width: <?= (int) $pct ?>%"></span></i>
                    </div>
                <?php endforeach;
                if (!$todayClasses): ?><p class="muted">No classes scheduled today.</p><?php endif; ?>
            </div>
        </article>
        <article class="panel">
            <h2>Recent Check-ins</h2>
            <div class="list-stack">
                <?php foreach ($recent as $row): ?>
                    <div class="checkin-row">
                        <?= render_avatar($row) ?>
                        <div><strong><?= h($row['member']) ?></strong><small>Check-in</small></div>
                        <time><?= h(date('M d, h:i A', strtotime($row['check_in_time']))) ?></time>
                    </div>
                <?php endforeach;
                if (!$recent): ?><p class="muted">No check-ins yet.</p><?php endif; ?>
            </div>
        </article>
        <article class="panel">
            <h2>Expiring Memberships</h2>
            <div class="list-stack">
                <?php
                $expiring = $pdo->query('SELECT m.end_date, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name, u.profile_picture FROM memberships m JOIN users u ON u.user_id = m.user_id WHERE m.status = "active" AND m.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY m.end_date ASC LIMIT 5')->fetchAll();
                foreach ($expiring as $row): ?>
                    <div class="checkin-row">
                        <?= render_avatar($row) ?>
                        <div><strong><?= h($row['member']) ?></strong><small>Expires</small></div>
                        <time style="color:var(--danger)"><?= h(date('M d, Y', strtotime($row['end_date']))) ?></time>
                    </div>
                <?php endforeach;
                if (!$expiring): ?><p class="muted">No upcoming expirations in the next 7 days.</p><?php endif; ?>
            </div>
        </article>
        <article class="panel">
            <h2>Inactive Members (At-Risk)</h2>
            <div class="list-stack">
                <?php
                $inactive = get_inactive_members(14);
                foreach ($inactive as $row): ?>
                    <div class="checkin-row">
                        <?= render_avatar($row) ?>
                        <div><strong><?= h($row['first_name'] . ' ' . $row['last_name']) ?></strong><small><?= $row['last_checkin'] ? date('M j', strtotime($row['last_checkin'])) : 'Never' ?></small></div>
                        <time style="color:var(--danger)"><?= (int)$row['days_inactive'] ?> days</time>
                    </div>
                <?php endforeach;
                if (!$inactive): ?><p class="muted">All members are actively checking in.</p><?php endif; ?>
            </div>
        </article>
    </section>
<?php
}

function dashboard(): void
{
    $user = require_login();
    render_header('Dashboard', $user);

    $pdo = db();
    if ($user['role'] === 'admin') {
        admin_dashboard($pdo);
    } elseif ($user['role'] === 'trainer') {
        render_skeleton_stats(2);
        echo '<div class="skeleton-content">';
        $stmt = $pdo->prepare('SELECT trainer_id FROM trainer_profiles WHERE user_id = ?');
        $stmt->execute([$user['user_id']]);
        $coachId = (int) ($stmt->fetchColumn() ?: 0);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM trainer_assignments WHERE trainer_id = ? AND status = "active"');
        $stmt->execute([$coachId]);
        metric_cards(['Assigned clients' => $stmt->fetchColumn(), 'Active training plans' => trainer_plan_count($coachId)]);
        echo '</div>';
    } else {
        $score    = get_cached_engagement_score((int) $user['user_id']);
        $category = get_engagement_category($score);
        $attendance = scalar('SELECT COUNT(*) FROM attendance WHERE user_id = ?', [$user['user_id']]);
        $progressLogs = scalar('SELECT COUNT(*) FROM progress_logs WHERE user_id = ?', [$user['user_id']]);
        $classBookings = scalar('SELECT COUNT(*) FROM class_bookings WHERE user_id = ?', [$user['user_id']]);

        $stmt = db()->prepare('SELECT fitness_tier FROM member_profiles WHERE user_id = ?');
        $stmt->execute([$user['user_id']]);
        $tier = (int) ($stmt->fetchColumn() ?: 1);
        $tierName = get_fitness_tier_name($tier);

        // Skeleton for member dashboard
        render_skeleton_banner();
        render_skeleton_stats(3);

        // Welcome Banner
        echo '<div class="skeleton-content animate-fade-in" style="background: linear-gradient(135deg, rgba(199,255,34,0.1) 0%, rgba(66,219,165,0.05) 100%); border: 1px solid rgba(199,255,34,0.3); border-radius: 12px; padding: 24px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); backdrop-filter: blur(16px);">';
        echo '<div><div style="display:flex; align-items:center; gap: 12px; margin-bottom: 4px;"><h2 style="margin: 0; font-size: 24px; color: var(--ink);">Welcome back, ' . h($user['first_name']) . '!</h2><span style="background: var(--accent, #7c5cfc); color: #fff; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; box-shadow: 0 2px 10px rgba(124,92,252,0.3);">' . h($tierName) . ' Tier</span></div>';
        echo '<p style="margin: 0; color: var(--muted); font-size: 14px;">Let\'s crush today\'s fitness goals. Here\'s your progress at a glance.</p></div>';
        
        // Hero Engagement Score
        echo '<div style="background: var(--panel-soft); padding: 12px 20px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.05); text-align: center;">';
        echo '<span style="display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em; color: var(--muted); margin-bottom: 4px;">Engagement Score</span>';
        echo '<strong style="display: block; font-size: 32px; color: var(--lime); line-height: 1;">' . $score . '<span style="font-size: 16px; color: var(--muted);">/100</span></strong>';
        echo '<span style="display: inline-block; margin-top: 6px; font-size: 11px; background: rgba(199,255,34,0.15); color: var(--lime); padding: 2px 8px; border-radius: 12px; font-weight: bold;">' . h(ucfirst(str_replace('_', ' ', $category))) . '</span>';
        echo '</div></div>';

        // Animated Metric Cards
        echo '<div class="skeleton-content metrics animate-fade-in delay-1" style="margin-bottom: 24px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">';
        $metrics = [
            'Attendance Records' => $attendance,
            'Progress Logs' => $progressLogs,
            'Class Bookings' => $classBookings,
        ];
        foreach ($metrics as $k => $v) {
            echo '<div class="metric" style="transition: transform 0.2s; cursor: default;" onmouseover="this.style.transform=\'translateY(-4px)\'" onmouseout="this.style.transform=\'translateY(0)\'">';
            echo '<span style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.1em;">' . h($k) . '</span>';
            echo '<strong style="font-size: 28px; color: var(--ink);">' . h((string)$v) . '</strong>';
            echo '</div>';
        }
        echo '</div>';

        echo '<div class="skeleton-content animate-fade-in delay-2">';
        render_current_workout($user['user_id'], true);
        echo '</div>';
        
        echo '<div class="skeleton-content animate-fade-in delay-3">';
        render_exercise_recommendations((int) $user['user_id'], true);
        echo '</div>';
    }
    render_footer();
}
