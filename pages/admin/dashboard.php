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
    $recent = $pdo->query('SELECT a.check_in_time, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name, u.profile_picture FROM attendance a JOIN users u ON u.user_id = a.user_id ORDER BY a.check_in_time DESC LIMIT 15')->fetchAll();

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
        <?php 
        $iconMembers = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>';
        $iconRevenue = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 21V3h7.5a4.5 4.5 0 1 1 0 9H7" /><line x1="4" y1="8" x2="17" y2="8" /><line x1="4" y1="12" x2="17" y2="12" /></svg>';
        $iconClasses = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>';
        $iconCheckins = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>';
        
        dashboard_stat('Total members',    (string) $members,    'Current members',        $memberTrend,   $iconMembers, true);
        dashboard_stat('Monthly revenue',  money($revenue),      'Billed this month',           $revenueTrend,  $iconRevenue);
        dashboard_stat('Classes today',    (string) $classesToday, 'With open schedules',       'Calendar updated', $iconClasses);
        dashboard_stat('Check-ins today',  (string) $checkinsToday, 'Updated from attendance logs', $checkinTrend, $iconCheckins);
        ?>
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
                </div><span<?= ($revPct !== null && $revPct < 0) ? ' class="trend-down-bg"' : '' ?>><?= h($revPctLabel) ?></span>
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
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h2>Today's Classes</h2>
                <div id="class-nav" style="display:none; gap:4px; align-items:center;">
                    <button class="btn btn-secondary" onclick="prevClassSlide()" style="padding:2px 8px; font-size:12px;">&#8592;</button>
                    <span id="class-page-indicator" style="font-size:12px; color:var(--muted); margin:0 4px;"></span>
                    <button class="btn btn-secondary" onclick="nextClassSlide()" style="padding:2px 8px; font-size:12px;">&#8594;</button>
                </div>
            </div>
            <div class="list-stack" id="class-carousel">
                <?php foreach ($todayClasses as $index => $class): $pct = min(100, ((int) $class['booked'] / max(1, (int) $class['capacity'])) * 100); ?>
                    <div class="class-row class-slide" style="display: <?= $index < 3 ? 'flex' : 'none' ?>;">
                        <span class="dot"></span>
                        <div><strong><?= h($class['class_name']) ?></strong><small><?= h($class['trainer']) ?> - <?= h(date('h:i A', strtotime($class['start_datetime']))) ?></small></div>
                        <b><?= (int) $class['booked'] ?>/<?= (int) $class['capacity'] ?><small>booked</small></b>
                        <i><span style="width: <?= (int) $pct ?>%"></span></i>
                    </div>
                <?php endforeach;
                if (!$todayClasses): ?><p class="muted">No classes scheduled today.</p><?php endif; ?>
            </div>
            <?php if (count($todayClasses) > 3): ?>
            <script>
                document.getElementById('class-nav').style.display = 'flex';
                let classPage = 0;
                const classRows = document.querySelectorAll('.class-slide');
                const classMaxPage = Math.ceil(classRows.length / 3) - 1;
                const classIndicator = document.getElementById('class-page-indicator');
                
                function updateClassCarousel() {
                    classRows.forEach((row, i) => {
                        row.style.display = (i >= classPage * 3 && i < (classPage + 1) * 3) ? 'flex' : 'none';
                    });
                    if (classIndicator) {
                        classIndicator.textContent = (classPage + 1) + ' / ' + (classMaxPage + 1);
                    }
                }
                
                updateClassCarousel();

                function nextClassSlide() {
                    if (classPage < classMaxPage) { classPage++; updateClassCarousel(); }
                }
                function prevClassSlide() {
                    if (classPage > 0) { classPage--; updateClassCarousel(); }
                }
            </script>
            <?php endif; ?>
        </article>
        <article class="panel">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h2>Recent Check-ins</h2>
                <div id="checkin-nav" style="display:none; gap:4px; align-items:center;">
                    <button class="btn btn-secondary" onclick="prevCheckinSlide()" style="padding:2px 8px; font-size:12px;">&#8592;</button>
                    <span id="checkin-page-indicator" style="font-size:12px; color:var(--muted); margin:0 4px;"></span>
                    <button class="btn btn-secondary" onclick="nextCheckinSlide()" style="padding:2px 8px; font-size:12px;">&#8594;</button>
                </div>
            </div>
            <div class="list-stack" id="checkin-carousel">
                <?php foreach ($recent as $index => $row): ?>
                    <div class="checkin-row checkin-slide" style="display: <?= $index < 3 ? 'flex' : 'none' ?>;">
                        <?= render_avatar($row) ?>
                        <div><strong><?= h($row['member']) ?></strong><small>Check-in</small></div>
                        <time><?= h(date('M d, h:i A', strtotime($row['check_in_time']))) ?></time>
                    </div>
                <?php endforeach;
                if (!$recent): ?><p class="muted">No check-ins yet.</p><?php endif; ?>
            </div>
            <?php if (count($recent) > 3): ?>
            <script>
                document.getElementById('checkin-nav').style.display = 'flex';
                let checkinPage = 0;
                const checkinRows = document.querySelectorAll('.checkin-slide');
                const checkinMaxPage = Math.ceil(checkinRows.length / 3) - 1;
                const checkinIndicator = document.getElementById('checkin-page-indicator');
                
                function updateCheckinCarousel() {
                    checkinRows.forEach((row, i) => {
                        row.style.display = (i >= checkinPage * 3 && i < (checkinPage + 1) * 3) ? 'flex' : 'none';
                    });
                    if (checkinIndicator) {
                        checkinIndicator.textContent = (checkinPage + 1) + ' / ' + (checkinMaxPage + 1);
                    }
                }
                
                // Initialize indicator
                updateCheckinCarousel();

                function nextCheckinSlide() {
                    if (checkinPage < checkinMaxPage) { checkinPage++; updateCheckinCarousel(); }
                }
                function prevCheckinSlide() {
                    if (checkinPage > 0) { checkinPage--; updateCheckinCarousel(); }
                }
            </script>
            <?php endif; ?>
        </article>
        <article class="panel">
            <h2>Expiring Memberships</h2>
            <div class="list-stack">
                <?php
                $expiring = $pdo->query('SELECT m.end_date, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name, u.profile_picture FROM memberships m JOIN users u ON u.user_id = m.user_id WHERE m.status = "active" AND m.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY) ORDER BY m.end_date ASC LIMIT 5')->fetchAll();
                foreach ($expiring as $row): ?>
                    <div class="checkin-row">
                        <?= render_avatar($row) ?>
                        <div><strong><?= h($row['member']) ?></strong><small>Expires</small></div>
                        <time style="color:var(--danger)"><?= h(date('M d, Y', strtotime($row['end_date']))) ?></time>
                    </div>
                <?php endforeach;
                if (!$expiring): ?><p class="muted">No upcoming expirations in the next 3 days.</p><?php endif; ?>
            </div>
        </article>
        <article class="panel">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h2>Inactive Members (At-Risk)</h2>
                <div id="inactive-nav" style="display:none; gap:4px; align-items:center;">
                    <button class="btn btn-secondary" onclick="prevInactiveSlide()" style="padding:2px 8px; font-size:12px;">&#8592;</button>
                    <span id="inactive-page-indicator" style="font-size:12px; color:var(--muted); margin:0 4px;"></span>
                    <button class="btn btn-secondary" onclick="nextInactiveSlide()" style="padding:2px 8px; font-size:12px;">&#8594;</button>
                </div>
            </div>
            <div class="list-stack" id="inactive-carousel">
                <?php
                $inactive = get_inactive_members(15);
                foreach ($inactive as $index => $row): ?>
                    <div class="checkin-row inactive-slide" style="display: <?= $index < 3 ? 'flex' : 'none' ?>;">
                        <?= render_avatar($row) ?>
                        <div><strong><?= h($row['first_name'] . ' ' . $row['last_name']) ?></strong><small><?= $row['last_checkin'] ? date('M j', strtotime($row['last_checkin'])) : 'Never' ?></small></div>
                        <time style="color:var(--danger)"><?= (int)$row['days_inactive'] ?> days</time>
                    </div>
                <?php endforeach;
                if (!$inactive): ?><p class="muted">All members are actively checking in.</p><?php endif; ?>
            </div>
            <?php if (count($inactive) > 3): ?>
            <script>
                document.getElementById('inactive-nav').style.display = 'flex';
                let inactivePage = 0;
                const inactiveRows = document.querySelectorAll('.inactive-slide');
                const inactiveMaxPage = Math.ceil(inactiveRows.length / 3) - 1;
                const inactiveIndicator = document.getElementById('inactive-page-indicator');
                
                function updateInactiveCarousel() {
                    inactiveRows.forEach((row, i) => {
                        row.style.display = (i >= inactivePage * 3 && i < (inactivePage + 1) * 3) ? 'flex' : 'none';
                    });
                    if (inactiveIndicator) {
                        inactiveIndicator.textContent = (inactivePage + 1) + ' / ' + (inactiveMaxPage + 1);
                    }
                }
                
                updateInactiveCarousel();

                function nextInactiveSlide() {
                    if (inactivePage < inactiveMaxPage) { inactivePage++; updateInactiveCarousel(); }
                }
                function prevInactiveSlide() {
                    if (inactivePage > 0) { inactivePage--; updateInactiveCarousel(); }
                }
            </script>
            <?php endif; ?>
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
        render_skeleton_banner();
        render_skeleton_stats(4);
        render_skeleton_cards(3);
        echo '<div class="skeleton-content">';

        $stmt = $pdo->prepare('SELECT trainer_id FROM trainer_profiles WHERE user_id = ?');
        $stmt->execute([$user['user_id']]);
        $coachId = (int) ($stmt->fetchColumn() ?: 0);

        // Gather stats
        $clientCount = (int) scalar('SELECT COUNT(*) FROM trainer_assignments WHERE trainer_id = ? AND status = "active"', [$coachId]);
        $pendingCount = (int) scalar('SELECT COUNT(*) FROM trainer_assignments WHERE trainer_id = ? AND status = "pending_trainer"', [$coachId]);
        $planCount = trainer_plan_count($coachId);
        $totalEarnings = (float) scalar('SELECT COALESCE(SUM(amount), 0) FROM trainer_commissions WHERE trainer_id = ? AND status = "paid"', [$coachId]);
        $pendingEarnings = (float) scalar('SELECT COALESCE(SUM(amount), 0) FROM trainer_commissions WHERE trainer_id = ? AND status = "pending"', [$coachId]);

        // Upcoming appointments (today & future)
        $upcomingAppointments = query_all(
            'SELECT ca.assignment_id, ca.assigned_date, ca.ended_date, ca.status,
                    u.first_name, u.last_name, u.profile_picture, u.email
             FROM trainer_assignments ca
             JOIN users u ON u.user_id = ca.member_user_id
             WHERE ca.trainer_id = ? AND ca.status IN ("active", "pending_trainer")
               AND DATE(ca.assigned_date) >= CURDATE()
             ORDER BY ca.assigned_date ASC
             LIMIT 5',
            [$coachId]
        );

        // Recent clients
        $recentClients = query_all(
            'SELECT ca.assigned_date, ca.status, u.first_name, u.last_name, u.profile_picture, u.email,
                    mp.primary_goal
             FROM trainer_assignments ca
             JOIN users u ON u.user_id = ca.member_user_id
             LEFT JOIN member_profiles mp ON mp.user_id = u.user_id
             WHERE ca.trainer_id = ? AND ca.status = "active"
             ORDER BY ca.assigned_date DESC
             LIMIT 4',
            [$coachId]
        );

        // Welcome Banner
        $greeting = 'Good morning';
        $hour = (int) date('G');
        if ($hour >= 12 && $hour < 17) $greeting = 'Good afternoon';
        elseif ($hour >= 17) $greeting = 'Good evening';
        ?>
        </div>

        <!-- Welcome Banner -->
        <div class="skeleton-content animate-fade-in" style="background: linear-gradient(135deg, rgba(199,255,34,0.12) 0%, rgba(66,219,165,0.06) 100%); border: 1px solid rgba(199,255,34,0.25); border-radius: 16px; padding: 28px 32px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; box-shadow: 0 4px 24px rgba(0,0,0,0.15); backdrop-filter: blur(16px);">
            <div>
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
                    <h2 style="margin: 0; font-size: 26px; color: var(--ink);"><?= h($greeting) ?>, <?= h($user['first_name']) ?>!</h2>
                    <span style="background: var(--lime); color: var(--bg); padding: 3px 12px; border-radius: 12px; font-size: 12px; font-weight: bold; letter-spacing: 0.5px;">TRAINER</span>
                </div>
                <p style="margin: 0; color: var(--muted); font-size: 14px; line-height: 1.5;">
                    <?php if ($pendingCount > 0): ?>
                        You have <strong style="color: var(--lime);"><?= $pendingCount ?></strong> pending appointment request<?= $pendingCount > 1 ? 's' : '' ?> waiting for your review.
                    <?php else: ?>
                        All caught up! No pending requests at the moment.
                    <?php endif; ?>
                </p>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="index.php?page=trainer_members" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-size: 14px; transition: all 0.2s;">
                    View Clients
                </a>
            </div>
        </div>

        <!-- 4 Metric Cards -->
        <div class="skeleton-content animate-fade-in delay-1" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
            <!-- Assigned Clients -->
            <div class="trainer-metric-card" style="background: var(--bg); border: 1px solid var(--line); border-radius: 12px; padding: 20px; transition: transform 0.2s, box-shadow 0.2s; cursor: default; box-shadow: 0 2px 8px rgba(0,0,0,0.08);" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 8px 24px rgba(0,0,0,0.15)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)'">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                    <div style="width: 36px; height: 36px; background: rgba(199,255,34,0.15); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="var(--lime)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <span style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted);">Active Clients</span>
                </div>
                <strong style="font-size: 32px; color: var(--ink); display: block;"><?= $clientCount ?></strong>
            </div>

            <!-- Pending Requests -->
            <div class="trainer-metric-card" style="background: var(--bg); border: 1px solid <?= $pendingCount > 0 ? 'rgba(245, 158, 11, 0.4)' : 'var(--line)' ?>; border-radius: 12px; padding: 20px; transition: transform 0.2s, box-shadow 0.2s; cursor: default; box-shadow: 0 2px 8px rgba(0,0,0,0.08);" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 8px 24px rgba(0,0,0,0.15)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)'">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                    <div style="width: 36px; height: 36px; background: rgba(245, 158, 11, 0.15); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <span style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted);">Pending Requests</span>
                </div>
                <strong style="font-size: 32px; color: <?= $pendingCount > 0 ? '#f59e0b' : 'var(--ink)' ?>; display: block;"><?= $pendingCount ?></strong>
            </div>

            <!-- Training Plans -->
            <div class="trainer-metric-card" style="background: var(--bg); border: 1px solid var(--line); border-radius: 12px; padding: 20px; transition: transform 0.2s, box-shadow 0.2s; cursor: default; box-shadow: 0 2px 8px rgba(0,0,0,0.08);" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 8px 24px rgba(0,0,0,0.15)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)'">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                    <div style="width: 36px; height: 36px; background: rgba(124, 92, 252, 0.15); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="#7c5cfc" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    </div>
                    <span style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted);">Training Plans</span>
                </div>
                <strong style="font-size: 32px; color: var(--ink); display: block;"><?= $planCount ?></strong>
            </div>

            <!-- Total Earnings -->
            <div class="trainer-metric-card" style="background: var(--bg); border: 1px solid var(--line); border-radius: 12px; padding: 20px; transition: transform 0.2s, box-shadow 0.2s; cursor: default; box-shadow: 0 2px 8px rgba(0,0,0,0.08);" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 8px 24px rgba(0,0,0,0.15)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)'">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                    <div style="width: 36px; height: 36px; background: rgba(34, 197, 94, 0.15); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: bold; color: #22c55e;">₱</div>
                    <span style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted);">Total Earned</span>
                </div>
                <strong style="font-size: 32px; color: var(--ink); display: block;">₱<?= number_format($totalEarnings, 0) ?></strong>
                <?php if ($pendingEarnings > 0): ?>
                    <span style="font-size: 12px; color: #f59e0b; margin-top: 4px; display: block;">₱<?= number_format($pendingEarnings, 0) ?> pending</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Two-Column: Upcoming Appointments & Recent Clients -->
        <div class="skeleton-content animate-fade-in delay-2" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
            
            <!-- Upcoming Appointments -->
            <section class="panel" style="padding: 0; overflow: hidden;">
                <div style="padding: 20px 24px 16px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center;">
                    <h2 style="margin: 0; font-size: 18px; color: var(--ink);">Upcoming Appointments</h2>
                    <?php if ($pendingCount > 0): ?>
                        <a href="index.php?page=trainer_members" style="font-size: 13px; color: var(--lime); text-decoration: none;">View all →</a>
                    <?php endif; ?>
                </div>
                <div style="padding: 0 24px 20px;">
                    <?php if ($upcomingAppointments): ?>
                        <?php foreach ($upcomingAppointments as $apt): ?>
                            <?php
                            $aptDate = strtotime($apt['assigned_date']);
                            $isToday = date('Y-m-d', $aptDate) === date('Y-m-d');
                            $isSingleDay = !empty($apt['ended_date']);
                            $statusColor = $apt['status'] === 'active' ? '#22c55e' : '#f59e0b';
                            $statusLabel = $apt['status'] === 'active' ? 'Confirmed' : 'Pending';
                            ?>
                            <div style="display: flex; align-items: center; gap: 14px; padding: 14px 0; border-bottom: 1px solid rgba(255,255,255,0.04);">
                                <div style="width: 48px; text-align: center; flex-shrink: 0;">
                                    <div style="font-size: 11px; text-transform: uppercase; color: <?= $isToday ? 'var(--lime)' : 'var(--muted)' ?>; letter-spacing: 0.5px; font-weight: 600;"><?= $isToday ? 'TODAY' : date('M', $aptDate) ?></div>
                                    <div style="font-size: 22px; font-weight: 700; color: var(--ink);"><?= date('j', $aptDate) ?></div>
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div style="font-weight: 600; color: var(--ink); font-size: 14px;"><?= h($apt['first_name'] . ' ' . $apt['last_name']) ?></div>
                                    <div style="font-size: 12px; color: var(--muted); margin-top: 2px;">
                                        <?= date('g:i A', $aptDate) ?>
                                        <?php if ($isSingleDay): ?>
                                            <span style="margin-left: 6px; font-size: 10px; background: rgba(245,158,11,0.15); color: #f59e0b; padding: 1px 6px; border-radius: 6px;">1-Day</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span style="font-size: 11px; background: rgba(<?= $apt['status'] === 'active' ? '34,197,94' : '245,158,11' ?>, 0.15); color: <?= $statusColor ?>; padding: 3px 8px; border-radius: 6px; font-weight: 600;"><?= $statusLabel ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 0; color: var(--muted);">
                            <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="none" stroke="var(--line)" stroke-width="1.5" viewBox="0 0 24 24" style="margin-bottom: 12px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <p style="font-size: 14px; margin: 0;">No upcoming appointments</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Recent Clients -->
            <section class="panel" style="padding: 0; overflow: hidden;">
                <div style="padding: 20px 24px 16px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center;">
                    <h2 style="margin: 0; font-size: 18px; color: var(--ink);">Active Clients</h2>
                    <a href="index.php?page=trainer_members" style="font-size: 13px; color: var(--lime); text-decoration: none;">Manage →</a>
                </div>
                <div style="padding: 0 24px 20px;">
                    <?php if ($recentClients): ?>
                        <?php foreach ($recentClients as $client): ?>
                            <div style="display: flex; align-items: center; gap: 14px; padding: 14px 0; border-bottom: 1px solid rgba(255,255,255,0.04);">
                                <?= render_avatar($client, 'small') ?>
                                <div style="flex: 1; min-width: 0;">
                                    <div style="font-weight: 600; color: var(--ink); font-size: 14px;"><?= h($client['first_name'] . ' ' . $client['last_name']) ?></div>
                                    <div style="font-size: 12px; color: var(--muted); margin-top: 2px;"><?= h($client['email']) ?></div>
                                </div>
                                <span style="font-size: 11px; background: rgba(124,92,252,0.12); color: #7c5cfc; padding: 3px 8px; border-radius: 6px; font-weight: 500; white-space: nowrap;"><?= h(ucwords(str_replace('_', ' ', $client['primary_goal'] ?? 'General'))) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 0; color: var(--muted);">
                            <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="none" stroke="var(--line)" stroke-width="1.5" viewBox="0 0 24 24" style="margin-bottom: 12px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <p style="font-size: 14px; margin: 0;">No active clients yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <style>
            @media (max-width: 768px) {
                .skeleton-content[style*="grid-template-columns: 1fr 1fr"] {
                    grid-template-columns: 1fr !important;
                }
            }
        </style>
        <?php
    } else {
        $score    = calculate_engagement_score((int) $user['user_id']);
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
        echo '<div><div style="display:flex; align-items:center; gap: 12px; margin-bottom: 4px;"><h2 style="margin: 0; font-size: 24px; color: var(--ink);">Welcome back, ' . h($user['first_name']) . '!</h2><span style="background: var(--accent, #7c5cfc); color: #fff; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; box-shadow: 0 2px 10px rgba(124,92,252,0.3); display: flex; align-items: center; gap: 4px;">' . h($tierName) . ' Tier <a href="#" onclick="event.preventDefault(); document.getElementById(\'guide-modal\').showModal();" style="color:rgba(255,255,255,0.8); text-decoration: none;" title="How it works">&#9468;</a></span></div>';
        echo '<p style="margin: 0; color: var(--muted); font-size: 14px;">Let\'s crush today\'s fitness goals. Here\'s your progress at a glance.</p></div>';
        
        // Hero Engagement Score
        $catColor = 'var(--lime)';
        $catBg = 'rgba(199,255,34,0.15)';
        if ($category === 'Moderately Engaged') {
            $catColor = '#f59e0b';
            $catBg = 'rgba(245, 158, 11, 0.15)';
        } elseif ($category === 'At-Risk') {
            $catColor = '#ef4444';
            $catBg = 'rgba(239, 68, 68, 0.15)';
        }
        
        echo '<div onclick="document.getElementById(\'missions-section\').scrollIntoView({behavior: \'smooth\'})" style="background: var(--panel-soft); padding: 12px 20px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.05); text-align: center; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.transform=\'scale(1.03)\'; this.style.boxShadow=\'0 4px 16px rgba(0,0,0,0.2)\'" onmouseout="this.style.transform=\'scale(1)\'; this.style.boxShadow=\'none\'" title="Click to view your missions">';
        echo '<span style="display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em; color: var(--muted); margin-bottom: 4px;">Engagement Score <a href="#" onclick="event.stopPropagation(); event.preventDefault(); document.getElementById(\'guide-modal\').showModal();" style="color:' . $catColor . '; margin-left: 4px; text-decoration: none;" title="How it works">&#9468;</a></span>';
        echo '<strong style="display: block; font-size: 32px; color: ' . $catColor . '; line-height: 1;">' . $score . '<span style="font-size: 16px; color: var(--muted);">/100</span></strong>';
        echo '<span style="display: inline-block; margin-top: 6px; font-size: 11px; background: ' . $catBg . '; color: ' . $catColor . '; padding: 2px 8px; border-radius: 12px; font-weight: bold;">' . h(ucfirst(str_replace('_', ' ', $category))) . '</span>';
        echo '<div style="margin-top: 6px; font-size: 10px; color: var(--muted); opacity: 0.7;">Click to view missions ↓</div>';
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

        // Engagement Missions
        $missions = get_engagement_missions((int) $user['user_id']);
        $completedCount = count(array_filter($missions, fn($m) => $m['completed']));
        $totalMissions = count($missions);
        ?>
        <div id="missions-section" class="skeleton-content animate-fade-in delay-2" style="margin-bottom: 24px;">
            <section class="panel" style="padding: 0; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                <div style="padding: 20px 24px 16px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <h2 style="margin: 0; font-size: 18px; color: var(--ink);">Monthly Missions</h2>
                        <span style="font-size: 12px; background: rgba(199,255,34,0.15); color: var(--lime); padding: 3px 10px; border-radius: 12px; font-weight: 600;"><?= $completedCount ?>/<?= $totalMissions ?> Complete</span>
                    </div>
                    <span style="font-size: 13px; color: var(--muted);">Complete missions to boost your Engagement Score!</span>
                </div>
                <div style="padding: 8px 24px 20px;">
                    <?php 
                    $missionIndex = 0;
                    foreach ($missions as $mission): 
                        $missionIndex++;
                        $isHidden = $missionIndex > 2;
                        
                        $pct = $mission['target'] > 0 ? min(100, round(($mission['current'] / $mission['target']) * 100)) : 0;
                        $barColor = $mission['completed'] ? '#22c55e' : 'var(--lime)';
                        $checkColor = $mission['completed'] ? '#22c55e' : 'var(--line)';
                        $checkBg = $mission['completed'] ? 'rgba(34, 197, 94, 0.15)' : 'rgba(255,255,255,0.03)';
                        ?>
                        <div class="mission-item <?= $isHidden ? 'hidden-mission' : '' ?>" style="display: <?= $isHidden ? 'none' : 'flex' ?>; align-items: center; gap: 16px; padding: 16px 0; border-bottom: 1px solid rgba(255,255,255,0.04); <?= $mission['completed'] ? 'opacity: 0.75;' : '' ?>">
                            <!-- Check Circle -->
                            <div style="width: 38px; height: 38px; border-radius: 50%; border: 2px solid <?= $checkColor ?>; background: <?= $checkBg ?>; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.3s;">
                                <?php if ($mission['completed']): ?>
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                <?php else: ?>
                                    <span style="font-size: 16px;"><?= $mission['icon'] ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Mission Info -->
                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                    <span style="font-weight: 600; color: var(--ink); font-size: 14px; <?= $mission['completed'] ? 'text-decoration: line-through; color: var(--muted);' : '' ?>"><?= h($mission['title']) ?></span>
                                    <span style="font-size: 12px; font-weight: 600; color: <?= $mission['completed'] ? '#22c55e' : 'var(--muted)' ?>; white-space: nowrap; margin-left: 8px;">
                                        +<?= $mission['earnedPoints'] ?>/<?= $mission['maxPoints'] ?> pts
                                    </span>
                                </div>
                                <div style="font-size: 12px; color: var(--muted); margin-bottom: 8px;"><?= h($mission['description']) ?></div>
                                <!-- Progress Bar -->
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="flex: 1; height: 6px; background: rgba(255,255,255,0.06); border-radius: 3px; overflow: hidden;">
                                        <div style="width: <?= $pct ?>%; height: 100%; background: <?= $barColor ?>; border-radius: 3px; transition: width 0.5s ease;"></div>
                                    </div>
                                    <span style="font-size: 11px; color: var(--muted); font-weight: 500; white-space: nowrap;"><?= $mission['current'] ?>/<?= $mission['target'] ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if (count($missions) > 2): ?>
                        <div style="text-align: center; margin-top: 16px;">
                            <button id="toggle-missions-btn" onclick="toggleMissions()" style="background: rgba(199,255,34,0.1); border: 1px solid rgba(199,255,34,0.3); color: var(--lime); font-size: 13px; font-weight: 600; cursor: pointer; padding: 6px 16px; border-radius: 20px; transition: all 0.2s;" onmouseover="this.style.background='rgba(199,255,34,0.2)'" onmouseout="this.style.background='rgba(199,255,34,0.1)'">
                                Show All Missions ↓
                            </button>
                        </div>
                        <script>
                            function toggleMissions() {
                                const hiddenMissions = document.querySelectorAll('.hidden-mission');
                                const btn = document.getElementById('toggle-missions-btn');
                                if (!hiddenMissions.length) return;
                                
                                const isCurrentlyHidden = hiddenMissions[0].style.display === 'none';
                                
                                hiddenMissions.forEach(m => {
                                    m.style.display = isCurrentlyHidden ? 'flex' : 'none';
                                });
                                
                                btn.innerHTML = isCurrentlyHidden ? 'Show Less' : 'Show All Missions';
                            }
                        </script>
                    <?php endif; ?>
                </div>
            </section>
        </div>
        <?php

        echo '<div class="skeleton-content animate-fade-in delay-2">';
        
        $profile = db()->query('SELECT height_cm, weight_kg FROM member_profiles WHERE user_id = ' . (int)$user['user_id'])->fetch();
        if ($profile && ((float)$profile['height_cm'] == 0 || (float)$profile['weight_kg'] == 0)) {
            echo '<div style="background: rgba(255,165,0,0.1); border: 1px solid orange; border-radius: 12px; padding: 24px; text-align: center; margin-bottom: 24px;">';
            echo '<h3 style="color: orange; margin: 0 0 12px 0;">We need a little more info!</h3>';
            echo '<p style="color: var(--muted); margin: 0 0 16px 0;">To build your personalized workout plan, we need your accurate height and weight.</p>';
            echo '<a href="index.php?page=profile" class="btn" style="background: orange; color: #111; font-weight: bold; padding: 10px 20px; text-decoration: none; border-radius: 6px; display: inline-block;">Complete Profile</a>';
            echo '</div>';
        } else {
            render_current_workout($user['user_id'], true);
        }
        
        echo '</div>';
        
        echo '<div class="skeleton-content animate-fade-in delay-3">';
        render_exercise_recommendations((int) $user['user_id'], true);
        echo '</div>';
        
        // Engagement & Tiers Guide Modal
        echo <<<HTML
        <dialog id="guide-modal" class="panel animate-fade-in" style="border:1px solid rgba(255,255,255,0.1); border-radius:16px; background:var(--panel); color:var(--ink); padding:0; max-width:600px; width:100%; box-shadow:0 20px 40px rgba(0,0,0,0.5); margin:auto;">
            <div style="padding:24px 24px 16px; border-bottom:1px solid rgba(255,255,255,0.05); display:flex; justify-content:space-between; align-items:center;">
                <h2 style="margin:0; font-size:20px; color:var(--lime);">How it Works</h2>
                <button type="button" onclick="document.getElementById('guide-modal').close()" style="background:none; border:none; color:var(--muted); font-size:24px; cursor:pointer; line-height:1;">&times;</button>
            </div>
            <div style="padding:24px; max-height:60vh; overflow-y:auto;">
                <h3 style="color:var(--ink); margin:0 0 12px; display:flex; align-items:center; gap:8px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2" style="width:20px;height:20px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    Engagement Score (0 - 100)
                </h3>
                <p style="color:var(--muted); font-size:14px; margin-bottom:16px; line-height:1.6;">
                    Your Engagement Score measures how actively you use FITTRACKS over the last 30-60 days. It updates automatically based on your habits!
                </p>
                <ul style="color:var(--muted); font-size:14px; line-height:1.6; margin-bottom:24px; padding-left:20px;">
                    <li><strong>40% Attendance:</strong> Check into the gym regularly (up to 7 visits / 30 days).</li>
                    <li><strong>20% Classes:</strong> Participate in group fitness classes (up to 4 classes / 30 days).</li>
                    <li><strong>20% Consistency:</strong> Stay active every week. We look at your weekly streaks!</li>
                    <li><strong>10% Daily Completed Workout:</strong> Complete your scheduled exercises (up to 8 days / 30 days).</li>
                    <li><strong>10% Progress:</strong> Log your workout progress at least once every 60 days.</li>
                </ul>
                
                <p style="color:var(--muted); font-size:14px; margin-bottom:12px; line-height:1.6;">
                    <strong>Engagement Categories:</strong>
                </p>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px; font-size:13px; margin-bottom: 24px;">
                    <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px; border-left: 3px solid var(--lime);"><strong>75 - 100:</strong> Highly Engaged</div>
                    <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px; border-left: 3px solid #f59e0b;"><strong>40 - 74:</strong> Moderately Engaged</div>
                    <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px; border-left: 3px solid #ef4444;"><strong>0 - 39:</strong> At-Risk</div>
                </div>
                
                <h3 style="color:var(--ink); margin:0 0 12px; display:flex; align-items:center; gap:8px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="var(--accent, #7c5cfc)" stroke-width="2" style="width:20px;height:20px;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                    Fitness Tiers
                </h3>
                <p style="color:var(--muted); font-size:14px; margin-bottom:16px; line-height:1.6;">
                    Tiers represent your lifetime workout experience! They are completely based on completing your assigned workout plans. Every time you log all exercises in a week's plan, you earn a "Completed Week".
                </p>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-size:13px;">
                    <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px;"><strong>Tier 1:</strong> Newbie <em>(&lt; 1 week)</em></div>
                    <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px;"><strong>Tier 2:</strong> Iron Recruit <em>(1+ weeks)</em></div>
                    <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px;"><strong>Tier 3:</strong> Bronze Beast <em>(4+ weeks)</em></div>
                    <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px;"><strong>Tier 4:</strong> Silver Spartan <em>(12+ weeks)</em></div>
                    <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px; border:1px solid rgba(199,255,34,0.3);"><strong>Tier 5:</strong> Gold Gladiator <em>(24+ weeks)</em></div>
                    <div style="background:rgba(124,92,252,0.1); padding:10px; border-radius:8px; border:1px solid rgba(124,92,252,0.3); color:var(--accent, #7c5cfc); font-weight:bold;"><strong>Tier 6:</strong> Apex Legend</div>
                </div>
            </div>
            <div style="padding:16px 24px; border-top:1px solid rgba(255,255,255,0.05); text-align:right;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('guide-modal').close()">Got it</button>
            </div>
        </dialog>
HTML;
    }
    render_footer();
}
