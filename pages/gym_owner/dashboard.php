<?php
declare(strict_types=1);

function admin_dashboard(PDO $pdo, array $user): void
{
    $isPlatformAdmin = $user['role'] === 'platform_admin';
    $gymId = null;
    if (!$isPlatformAdmin) {
        $gymId = (int) scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
    }

    if ($isPlatformAdmin) {
        $members      = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "member" AND status = "active"')->fetchColumn();
    } else {
        $members      = (int) $pdo->query('SELECT COUNT(DISTINCT m.user_id) FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE mp.gym_id = ' . $gymId . ' AND m.status = "active"')->fetchColumn();
    }
    if ($isPlatformAdmin) {
        $revenue = (float) $pdo->query(
            'SELECT SUM(revenue) FROM (
                SELECT amount AS revenue FROM payments WHERE status = "paid" AND payment_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01")
                UNION ALL
                SELECT amount_paid AS revenue FROM walk_in_transactions WHERE visit_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01")
            ) AS combined'
        )->fetchColumn();
        $classesToday = (int) $pdo->query('SELECT COUNT(*) FROM class_schedules WHERE DATE(start_datetime) = CURDATE()')->fetchColumn();
        $checkinsToday = (int) $pdo->query('SELECT COUNT(*) FROM attendance WHERE DATE(check_in_time) = CURDATE()')->fetchColumn();
    } else {
        // Gym Owner stats
        $revenue = (float) $pdo->query(
            'SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN memberships m ON m.membership_id = p.membership_id JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE mp.gym_id = ' . $gymId . ' AND p.status = "paid" AND p.payment_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01")'
        )->fetchColumn();
        $classesToday = (int) $pdo->query('SELECT COUNT(*) FROM class_schedules cs JOIN classes c ON c.class_id = cs.class_id WHERE c.gym_id = ' . $gymId . ' AND DATE(cs.start_datetime) = CURDATE()')->fetchColumn();
        $checkinsToday = (int) $pdo->query('SELECT COUNT(*) FROM attendance WHERE gym_id = ' . $gymId . ' AND DATE(check_in_time) = CURDATE()')->fetchColumn();
    }

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
    <?php render_announcement_carousel(get_active_announcements('gym_owners')); ?>
    <?php render_skeleton_stats(4); ?>
    <section class="dash-grid stats-row skeleton-content sk-display-grid">
        <?php 
        $iconMembers = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>';
        $iconRevenue = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 21V3h7.5a4.5 4.5 0 1 1 0 9H7" /><line x1="4" y1="8" x2="17" y2="8" /><line x1="4" y1="12" x2="17" y2="12" /></svg>';
        $iconClasses = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>';
        $iconCheckins = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>';
        
        dashboard_stat('Active Members',    (string) $members,    'With active plans',        $memberTrend,   $iconMembers, true);
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
