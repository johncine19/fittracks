<?php
declare(strict_types=1);

function admin_dashboard(PDO $pdo, array $user): void
{
    $isPlatformAdmin = $user['role'] === 'platform_admin';
    $gymId = null;
    $subPlan = 'No Active Plan';
    $subStatus = 'inactive';
    $subRenewal = 'N/A';
    if (!$isPlatformAdmin) {
        $gymRow = $pdo->query('SELECT gym_id, subscription_plan, subscription_status, subscription_renewal_date FROM gyms WHERE owner_user_id = ' . (int)$user['user_id'])->fetch(PDO::FETCH_ASSOC);
        if ($gymRow) {
            $gymId = (int) $gymRow['gym_id'];
            $subPlan = $gymRow['subscription_plan'] ?: 'No Active Plan';
            $subStatus = $gymRow['subscription_status'] ?: 'inactive';
            $subRenewal = !empty($gymRow['subscription_renewal_date']) ? date('M j, Y', strtotime($gymRow['subscription_renewal_date'])) : 'N/A';
        }
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
        $classesToday = (int) $pdo->query('SELECT COUNT(*) FROM class_schedules WHERE start_datetime >= CURDATE() AND start_datetime < CURDATE() + INTERVAL 1 DAY')->fetchColumn();
        $checkinsToday = (int) $pdo->query('SELECT COUNT(*) FROM attendance WHERE check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY')->fetchColumn();
    } else {
        // Gym Owner stats
        $revenue = (float) $pdo->query(
            'SELECT SUM(revenue) FROM (
                SELECT p.amount AS revenue FROM payments p JOIN memberships m ON m.membership_id = p.membership_id JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE mp.gym_id = ' . $gymId . ' AND p.status = "paid" AND p.payment_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01")
                UNION ALL
                SELECT amount_paid AS revenue FROM walk_in_transactions WHERE gym_id = ' . $gymId . ' AND visit_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01")
            ) AS combined'
        )->fetchColumn();
        $classesToday = (int) $pdo->query('SELECT COUNT(*) FROM class_schedules cs JOIN classes c ON c.class_id = cs.class_id WHERE c.gym_id = ' . $gymId . ' AND cs.start_datetime >= CURDATE() AND cs.start_datetime < CURDATE() + INTERVAL 1 DAY')->fetchColumn();
        $checkinsToday = (int) $pdo->query('SELECT COUNT(*) FROM attendance WHERE gym_id = ' . $gymId . ' AND check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY')->fetchColumn();
    }

    $revenueTrend = calc_revenue_trend($pdo, $gymId);
    $memberTrend  = calc_member_trend($pdo, $gymId);
    $checkinTrend = calc_checkin_trend($pdo, $gymId);

    $monthStart = (new DateTime('first day of this month'))->modify('-5 months');
    
    if (!$gymId) {
        $monthlyRows = query_all(
            'SELECT month_key, COALESCE(SUM(total), 0) AS total FROM (
                SELECT DATE_FORMAT(payment_date, "%Y-%m") AS month_key, amount AS total FROM payments WHERE status = "paid" AND payment_date >= ?
                UNION ALL
                SELECT DATE_FORMAT(visit_date, "%Y-%m") AS month_key, amount_paid AS total FROM walk_in_transactions WHERE visit_date >= ?
            ) AS combined GROUP BY month_key',
            [$monthStart->format('Y-m-01'), $monthStart->format('Y-m-01')]
        );
    } else {
        $monthlyRows = query_all(
            'SELECT month_key, COALESCE(SUM(total), 0) AS total FROM (
                SELECT DATE_FORMAT(p.payment_date, "%Y-%m") AS month_key, p.amount AS total FROM payments p JOIN memberships m ON m.membership_id = p.membership_id JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE mp.gym_id = ? AND p.status = "paid" AND p.payment_date >= ?
                UNION ALL
                SELECT DATE_FORMAT(visit_date, "%Y-%m") AS month_key, amount_paid AS total FROM walk_in_transactions WHERE gym_id = ? AND visit_date >= ?
            ) AS combined GROUP BY month_key',
            [$gymId, $monthStart->format('Y-m-01'), $gymId, $monthStart->format('Y-m-01')]
        );
    }
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
    $todayClasses = $pdo->query('SELECT c.class_name, c.capacity, s.start_datetime, COALESCE(CONCAT(u.first_name, " ", u.last_name), "Open trainer") AS trainer, (SELECT COUNT(*) FROM class_bookings b WHERE b.schedule_id = s.schedule_id AND b.booking_status = "booked") AS booked FROM class_schedules s JOIN classes c ON c.class_id = s.class_id LEFT JOIN users u ON u.user_id = c.instructor_id WHERE ' . ($isPlatformAdmin ? '' : 'c.gym_id = ' . $gymId . ' AND ') . 's.start_datetime >= CURDATE() AND s.start_datetime < CURDATE() + INTERVAL 1 DAY ORDER BY s.start_datetime LIMIT 15')->fetchAll();
    $recent = $pdo->query('SELECT a.check_in_time, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name, u.profile_picture FROM attendance a JOIN users u ON u.user_id = a.user_id ' . ($isPlatformAdmin ? '' : 'WHERE a.gym_id = ' . $gymId . ' ') . 'ORDER BY a.check_in_time DESC LIMIT 15')->fetchAll();

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
    <?php if (!$isPlatformAdmin): ?>
    <div class="gym-tour-welcome-banner" id="gym-tour-welcome-banner">
        <div class="tour-banner-content">
            <div class="tour-banner-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon>
                </svg>
            </div>
            <div class="tour-banner-text">
                <h3>Welcome to your FitTrack Dashboard! 👋</h3>
                <p>New to the platform? Take a quick guided tour to explore your live operations, plans, classes, equipment, and staff tools.</p>
            </div>
        </div>
        <div class="tour-banner-actions">
            <button type="button" class="btn-start-tour" onclick="if(window.startGymOwnerTour){window.startGymOwnerTour(true);}">
                <span>Start 2-Min Tour</span> &rarr;
            </button>
            <button type="button" class="btn-dismiss-tour" onclick="if(window.dismissGymOwnerTourBanner){window.dismissGymOwnerTourBanner();}" title="Dismiss banner">&times;</button>
        </div>
    </div>
    <?php endif; ?>
    <?php render_announcement_carousel(get_active_announcements('gym_owners')); ?>
    <?php render_skeleton_stats(4); ?>
    <section class="dash-grid stats-row skeleton-content sk-display-grid">
        <?php 
        $iconMembers = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>';
        $iconRevenue = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 21V3h7.5a4.5 4.5 0 1 1 0 9H7" /><line x1="4" y1="8" x2="17" y2="8" /><line x1="4" y1="12" x2="17" y2="12" /></svg>';
        $iconClasses = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>';
        $iconCheckins = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>';
        
        dashboard_stat('Active Members',    (string) $members,    'With active plans',        $memberTrend,   $iconMembers, false, 'members');
        dashboard_stat('Monthly revenue',  money($revenue),      'Billed this month',           $revenueTrend,  $iconRevenue, false, 'revenue');
        dashboard_stat('Classes today',    (string) $classesToday, 'With open schedules',       'Calendar updated', $iconClasses, false, 'classes');
        dashboard_stat('Check-ins today',  (string) $checkinsToday, 'Updated from attendance logs', $checkinTrend, $iconCheckins, false, 'checkins');
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
                    <div style="display:flex; align-items:center;">
                        <h2>Revenue Trend</h2>
                        <button type="button" class="ft-sparkle-badge" data-ft-feature="revenue_analytics" data-ft-title="Automated Revenue Tracking" data-ft-desc="FitTrack separates recurring membership fees from cash walk-in daily passes for clear cash flow visibility." data-ft-action="Open Reports ↗" data-ft-url="index.php?page=reports" title="Discover Revenue Feature">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        </button>
                    </div>
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
<?php
$expiring = $pdo->query('SELECT m.end_date, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name, u.profile_picture FROM memberships m JOIN users u ON u.user_id = m.user_id JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.status = "active" AND m.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)' . ($isPlatformAdmin ? '' : ' AND mp.gym_id = ' . (int)$gymId) . ' ORDER BY m.end_date ASC LIMIT 15')->fetchAll();
$inactive = get_inactive_members(15, $isPlatformAdmin ? null : $gymId);
?>

<style>
/* ── LOWER ROW DASHBOARD CARDS ELEVATION ──────────────────────── */
.dash-panel-enhanced {
    display: flex;
    flex-direction: column;
    min-height: 290px;
    background: var(--bg-surface, rgba(15, 23, 42, 0.65));
    border: 1px solid var(--line, rgba(255, 255, 255, 0.08));
    border-radius: 16px;
    padding: 20px 22px;
    box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.25);
    transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s ease, border-color 0.2s ease;
}
.dash-panel-enhanced:hover {
    border-color: rgba(255, 255, 255, 0.16);
    box-shadow: 0 10px 28px -4px rgba(0, 0, 0, 0.35);
}
[data-theme="light"] .dash-panel-enhanced {
    background: #ffffff;
    border-color: #e2e8f0;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.05);
}
[data-theme="light"] .dash-panel-enhanced:hover {
    border-color: #cbd5e1;
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.08);
}

.dash-panel-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--line, rgba(255, 255, 255, 0.06));
    margin-bottom: 12px;
    flex-wrap: wrap;
}
[data-theme="light"] .dash-panel-head {
    border-bottom-color: #f1f5f9;
}

.dash-panel-title-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.dash-panel-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: transform 0.2s ease;
}
.dash-panel-enhanced:hover .dash-panel-icon {
    transform: scale(1.05);
}

.dash-panel-icon.theme-lime {
    background: rgba(132, 204, 22, 0.12);
    color: #84cc16;
    border: 1px solid rgba(132, 204, 22, 0.25);
}
.dash-panel-icon.theme-cyan {
    background: rgba(6, 182, 212, 0.12);
    color: #06b6d4;
    border: 1px solid rgba(6, 182, 212, 0.25);
}
.dash-panel-icon.theme-amber {
    background: rgba(245, 158, 11, 0.12);
    color: #f59e0b;
    border: 1px solid rgba(245, 158, 11, 0.25);
}
.dash-panel-icon.theme-violet {
    background: rgba(168, 85, 247, 0.12);
    color: #a855f7;
    border: 1px solid rgba(168, 85, 247, 0.25);
}

.dash-panel-title-group {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.dash-panel-title {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    letter-spacing: -0.015em;
    color: var(--ink, #ffffff);
    line-height: 1.25;
}

.dash-panel-sub {
    font-size: 11.5px;
    color: var(--muted, #8792ad);
    margin-top: 2px;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.dash-panel-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}

.dash-badge-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.02em;
    white-space: nowrap;
}
.dash-badge-chip.lime {
    background: rgba(132, 204, 22, 0.14);
    color: #84cc16;
    border: 1px solid rgba(132, 204, 22, 0.28);
}
.dash-badge-chip.cyan {
    background: rgba(6, 182, 212, 0.14);
    color: #06b6d4;
    border: 1px solid rgba(6, 182, 212, 0.28);
}
.dash-badge-chip.amber {
    background: rgba(245, 158, 11, 0.14);
    color: #f59e0b;
    border: 1px solid rgba(245, 158, 11, 0.28);
}
.dash-badge-chip.rose {
    background: rgba(244, 63, 94, 0.14);
    color: #f43f5e;
    border: 1px solid rgba(244, 63, 94, 0.28);
}
.dash-badge-chip.emerald,
.dash-badge-chip.success {
    background: rgba(34, 197, 94, 0.14);
    color: #22c55e;
    border: 1px solid rgba(34, 197, 94, 0.28);
}
.dash-badge-chip.muted {
    background: rgba(255, 255, 255, 0.05);
    color: var(--muted, #8792ad);
    border: 1px solid var(--line, rgba(255, 255, 255, 0.08));
}
[data-theme="light"] .dash-badge-chip.muted {
    background: rgba(0, 0, 0, 0.04);
    border-color: rgba(0, 0, 0, 0.08);
}

.pulse-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #06b6d4;
    box-shadow: 0 0 8px #06b6d4;
    animation: pulseDot 2s infinite ease-in-out;
}
.pulse-dot.idle {
    background: #64748b;
    box-shadow: none;
    animation: none;
}
@keyframes pulseDot {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.35; transform: scale(0.85); }
}

.dash-carousel-nav {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid var(--line, rgba(255, 255, 255, 0.08));
    border-radius: 8px;
    padding: 2px 4px;
}
[data-theme="light"] .dash-carousel-nav {
    background: #f8fafc;
    border-color: #e2e8f0;
}
.dash-carousel-nav button {
    background: transparent;
    border: none;
    color: var(--ink, #fff);
    cursor: pointer;
    width: 22px;
    height: 22px;
    border-radius: 4px;
    display: grid;
    place-items: center;
    font-size: 13px;
    padding: 0;
    line-height: 1;
    transition: background 0.15s ease;
}
.dash-carousel-nav button:hover {
    background: rgba(255, 255, 255, 0.12);
}
[data-theme="light"] .dash-carousel-nav button:hover {
    background: #e2e8f0;
}
.dash-carousel-nav span {
    font-size: 11px;
    font-weight: 600;
    color: var(--muted, #8792ad);
    padding: 0 4px;
    font-variant-numeric: tabular-nums;
}

/* ── EMPTY STATE STYLING ───────────────────────── */
.dash-empty-state {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 28px 16px 20px;
    text-align: center;
    min-height: 190px;
}

.dash-empty-halo {
    position: relative;
    width: 58px;
    height: 58px;
    display: grid;
    place-items: center;
    margin-bottom: 12px;
}
.dash-empty-halo::before {
    content: '';
    position: absolute;
    inset: -8px;
    border-radius: 50%;
    background: radial-gradient(circle, var(--halo-color, rgba(132, 204, 22, 0.15)) 0%, transparent 70%);
    pointer-events: none;
}
.dash-empty-icon-circle {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: var(--empty-bg, rgba(255, 255, 255, 0.03));
    border: 1px solid var(--empty-border, rgba(255, 255, 255, 0.1));
    color: var(--empty-color, #84cc16);
    position: relative;
    z-index: 1;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2);
    transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
.dash-panel-enhanced:hover .dash-empty-icon-circle {
    transform: scale(1.08);
}

.dash-empty-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--ink, #ffffff);
    margin: 0 0 6px 0;
    letter-spacing: -0.01em;
}

.dash-empty-desc {
    font-size: 12px;
    color: var(--muted, #8792ad);
    line-height: 1.5;
    max-width: 290px;
    margin: 0 auto 16px;
}

.dash-empty-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 8px;
    background: var(--btn-bg, rgba(132, 204, 22, 0.12));
    border: 1px solid var(--btn-border, rgba(132, 204, 22, 0.25));
    color: var(--btn-color, #84cc16);
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    transition: all 0.2s ease;
}
.dash-empty-action:hover {
    background: var(--btn-color, #84cc16);
    color: #090b10;
    border-color: var(--btn-color, #84cc16);
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(132, 204, 22, 0.28);
}

.dash-empty-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 13px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 700;
    background: rgba(34, 197, 94, 0.1);
    border: 1px solid rgba(34, 197, 94, 0.25);
    color: #22c55e;
}

/* ── ROW HOVER & INTERACTION ──────────────────── */
.dash-panel-enhanced .list-stack {
    margin-top: 4px;
    gap: 8px;
}
.dash-panel-enhanced .class-row,
.dash-panel-enhanced .checkin-row {
    border-radius: 10px;
    background: var(--panel-soft, rgba(26, 30, 40, 0.65));
    border: 1px solid var(--line, rgba(255, 255, 255, 0.05));
    transition: transform 0.15s ease, background 0.15s ease, border-color 0.15s ease;
}
.dash-panel-enhanced .class-row:hover,
.dash-panel-enhanced .checkin-row:hover {
    background: rgba(255, 255, 255, 0.05);
    border-color: rgba(255, 255, 255, 0.12);
    transform: translateX(2px);
}
[data-theme="light"] .dash-panel-enhanced .class-row,
[data-theme="light"] .dash-panel-enhanced .checkin-row {
    background: #f8fafc;
    border-color: #e2e8f0;
}
[data-theme="light"] .dash-panel-enhanced .class-row:hover,
[data-theme="light"] .dash-panel-enhanced .checkin-row:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
}

.dash-panel-enhanced .class-row i {
    height: 5px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 999px;
    overflow: hidden;
    display: block;
}
.dash-panel-enhanced .class-row i span {
    display: block;
    height: 100%;
    background: linear-gradient(90deg, #84cc16, #06b6d4);
    border-radius: 999px;
}
</style>

    <section class="dash-grid lower-row skeleton-content sk-display-grid">
        <!-- 1. TODAY'S CLASSES -->
        <article class="panel dash-panel-enhanced">
            <div class="dash-panel-head">
                <div class="dash-panel-title-wrap">
                    <div class="dash-panel-icon theme-lime">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                    </div>
                    <div class="dash-panel-title-group">
                        <h2 class="dash-panel-title">Today's Classes</h2>
                        <span class="dash-panel-sub">Live schedule & room capacity</span>
                    </div>
                </div>
                <div class="dash-panel-actions">
                    <?php if ($todayClasses): ?>
                        <span class="dash-badge-chip lime"><?= count($todayClasses) ?> Scheduled</span>
                        <div id="class-nav" class="dash-carousel-nav" style="display:none;">
                            <button type="button" onclick="prevClassSlide()" title="Previous">&#8592;</button>
                            <span id="class-page-indicator"></span>
                            <button type="button" onclick="nextClassSlide()" title="Next">&#8594;</button>
                        </div>
                    <?php else: ?>
                        <span class="dash-badge-chip muted">0 Scheduled</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($todayClasses): ?>
                <div class="list-stack" id="class-carousel">
                    <?php foreach ($todayClasses as $index => $class): $pct = min(100, ((int) $class['booked'] / max(1, (int) $class['capacity'])) * 100); ?>
                        <div class="class-row class-slide" style="display: <?= $index < 3 ? 'grid' : 'none' ?>;">
                            <span class="dot"></span>
                            <div><strong><?= h($class['class_name']) ?></strong><small><?= h($class['trainer']) ?> &bull; <?= h(date('h:i A', strtotime($class['start_datetime']))) ?></small></div>
                            <b><?= (int) $class['booked'] ?>/<?= (int) $class['capacity'] ?><small>booked</small></b>
                            <i><span style="width: <?= (int) $pct ?>%"></span></i>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($todayClasses) > 3): ?>
                <script>
                    document.getElementById('class-nav').style.display = 'inline-flex';
                    let classPage = 0;
                    const classRows = document.querySelectorAll('.class-slide');
                    const classMaxPage = Math.ceil(classRows.length / 3) - 1;
                    const classIndicator = document.getElementById('class-page-indicator');
                    
                    function updateClassCarousel() {
                        classRows.forEach((row, i) => {
                            row.style.display = (i >= classPage * 3 && i < (classPage + 1) * 3) ? 'grid' : 'none';
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
            <?php else: ?>
                <div class="dash-empty-state" style="--halo-color: rgba(132, 204, 22, 0.18); --empty-bg: rgba(132, 204, 22, 0.08); --empty-border: rgba(132, 204, 22, 0.22); --empty-color: #84cc16; --btn-bg: rgba(132, 204, 22, 0.12); --btn-border: rgba(132, 204, 22, 0.25); --btn-color: #84cc16;">
                    <div class="dash-empty-halo">
                        <div class="dash-empty-icon-circle">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                                <circle cx="8" cy="14" r="1"></circle>
                                <circle cx="12" cy="14" r="1"></circle>
                                <circle cx="16" cy="14" r="1"></circle>
                            </svg>
                        </div>
                    </div>
                    <h3 class="dash-empty-title">No Classes Scheduled Today</h3>
                    <p class="dash-empty-desc">Keep your members motivated and engaged by creating workout sessions and fitness classes.</p>
                    <a href="index.php?page=classes" class="dash-empty-action">
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        <span>Schedule Class</span>
                    </a>
                </div>
            <?php endif; ?>
        </article>

        <!-- 2. RECENT CHECK-INS -->
        <article class="panel dash-panel-enhanced">
            <div class="dash-panel-head">
                <div class="dash-panel-title-wrap">
                    <div class="dash-panel-icon theme-cyan">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 7V5a2 2 0 0 1 2-2h2"></path>
                            <path d="M17 3h2a2 2 0 0 1 2 2v2"></path>
                            <path d="M21 17v2a2 2 0 0 1-2 2h-2"></path>
                            <path d="M7 21H5a2 2 0 0 1-2-2v-2"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                            <line x1="12" y1="9" x2="12" y2="9.01"></line>
                        </svg>
                    </div>
                    <div class="dash-panel-title-group">
                        <h2 class="dash-panel-title">Recent Check-ins</h2>
                        <span class="dash-panel-sub">Real-time attendance & gate log</span>
                    </div>
                </div>
                <div class="dash-panel-actions">
                    <?php if ($recent): ?>
                        <span class="dash-badge-chip cyan"><span class="pulse-dot"></span> <?= count($recent) ?> Recent</span>
                        <div id="checkin-nav" class="dash-carousel-nav" style="display:none;">
                            <button type="button" onclick="prevCheckinSlide()" title="Previous">&#8592;</button>
                            <span id="checkin-page-indicator"></span>
                            <button type="button" onclick="nextCheckinSlide()" title="Next">&#8594;</button>
                        </div>
                    <?php else: ?>
                        <span class="dash-badge-chip muted"><span class="pulse-dot idle"></span> Live Sync</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($recent): ?>
                <div class="list-stack" id="checkin-carousel">
                    <?php foreach ($recent as $index => $row): ?>
                        <div class="checkin-row checkin-slide" style="display: <?= $index < 3 ? 'grid' : 'none' ?>;">
                            <?= render_avatar($row) ?>
                            <div><strong><?= h($row['member']) ?></strong><small>Check-in</small></div>
                            <time><?= h(date('M d, h:i A', strtotime($row['check_in_time']))) ?></time>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($recent) > 3): ?>
                <script>
                    document.getElementById('checkin-nav').style.display = 'inline-flex';
                    let checkinPage = 0;
                    const checkinRows = document.querySelectorAll('.checkin-slide');
                    const checkinMaxPage = Math.ceil(checkinRows.length / 3) - 1;
                    const checkinIndicator = document.getElementById('checkin-page-indicator');
                    
                    function updateCheckinCarousel() {
                        checkinRows.forEach((row, i) => {
                            row.style.display = (i >= checkinPage * 3 && i < (checkinPage + 1) * 3) ? 'grid' : 'none';
                        });
                        if (checkinIndicator) {
                            checkinIndicator.textContent = (checkinPage + 1) + ' / ' + (checkinMaxPage + 1);
                        }
                    }
                    
                    updateCheckinCarousel();

                    function nextCheckinSlide() {
                        if (checkinPage < checkinMaxPage) { checkinPage++; updateCheckinCarousel(); }
                    }
                    function prevCheckinSlide() {
                        if (checkinPage > 0) { checkinPage--; updateCheckinCarousel(); }
                    }
                </script>
                <?php endif; ?>
            <?php else: ?>
                <div class="dash-empty-state" style="--halo-color: rgba(6, 182, 212, 0.18); --empty-bg: rgba(6, 182, 212, 0.08); --empty-border: rgba(6, 182, 212, 0.22); --empty-color: #06b6d4; --btn-bg: rgba(6, 182, 212, 0.12); --btn-border: rgba(6, 182, 212, 0.25); --btn-color: #06b6d4;">
                    <div class="dash-empty-halo">
                        <div class="dash-empty-icon-circle">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 7V5a2 2 0 0 1 2-2h2"></path>
                                <path d="M17 3h2a2 2 0 0 1 2 2v2"></path>
                                <path d="M21 17v2a2 2 0 0 1-2 2h-2"></path>
                                <path d="M7 21H5a2 2 0 0 1-2-2v-2"></path>
                                <rect x="7" y="7" width="10" height="10" rx="1"></rect>
                            </svg>
                        </div>
                    </div>
                    <h3 class="dash-empty-title">No Check-ins Yet Today</h3>
                    <p class="dash-empty-desc">Live attendance entries logged via member QR codes or front desk scan will stream here automatically.</p>
                    <a href="index.php?page=scanner" class="dash-empty-action">
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="23 7 23 1 17 1"></polyline>
                            <line x1="16" y1="8" x2="23" y2="1"></line>
                            <path d="M22 13v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h6"></path>
                        </svg>
                        <span>Open QR Scanner</span>
                    </a>
                </div>
            <?php endif; ?>
        </article>

        <!-- 3. EXPIRING MEMBERSHIPS -->
        <article class="panel dash-panel-enhanced">
            <div class="dash-panel-head">
                <div class="dash-panel-title-wrap">
                    <div class="dash-panel-icon theme-amber">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                    </div>
                    <div class="dash-panel-title-group">
                        <h2 class="dash-panel-title">Expiring Memberships</h2>
                        <span class="dash-panel-sub">Renewals due within 3 days</span>
                    </div>
                </div>
                <div class="dash-panel-actions">
                    <?php if ($expiring): ?>
                        <span class="dash-badge-chip amber"><?= count($expiring) ?> Due</span>
                        <div id="expiring-nav" class="dash-carousel-nav" style="display:none;">
                            <button type="button" onclick="prevExpiringSlide()" title="Previous">&#8592;</button>
                            <span id="expiring-page-indicator"></span>
                            <button type="button" onclick="nextExpiringSlide()" title="Next">&#8594;</button>
                        </div>
                    <?php else: ?>
                        <span class="dash-badge-chip emerald">✓ All Current</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($expiring): ?>
                <div class="list-stack" id="expiring-carousel">
                    <?php foreach ($expiring as $index => $row): 
                        $daysLeft = (int) (new DateTime('today'))->diff(new DateTime($row['end_date']))->format('%r%a');
                        $dueText = $daysLeft === 0 ? 'Today' : ($daysLeft === 1 ? 'Tomorrow' : "In {$daysLeft}d");
                    ?>
                        <div class="checkin-row expiring-slide" style="display: <?= $index < 3 ? 'grid' : 'none' ?>;">
                            <?= render_avatar($row) ?>
                            <div>
                                <strong><?= h($row['member']) ?></strong>
                                <small>Expires <?= h($dueText) ?> &bull; <?= h(date('M d', strtotime($row['end_date']))) ?></small>
                            </div>
                            <time style="color:var(--danger); font-weight:700; font-size:12px;"><?= h($dueText) ?></time>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($expiring) > 3): ?>
                <script>
                    document.getElementById('expiring-nav').style.display = 'inline-flex';
                    let expiringPage = 0;
                    const expiringRows = document.querySelectorAll('.expiring-slide');
                    const expiringMaxPage = Math.ceil(expiringRows.length / 3) - 1;
                    const expiringIndicator = document.getElementById('expiring-page-indicator');
                    
                    function updateExpiringCarousel() {
                        expiringRows.forEach((row, i) => {
                            row.style.display = (i >= expiringPage * 3 && i < (expiringPage + 1) * 3) ? 'grid' : 'none';
                        });
                        if (expiringIndicator) {
                            expiringIndicator.textContent = (expiringPage + 1) + ' / ' + (expiringMaxPage + 1);
                        }
                    }
                    
                    updateExpiringCarousel();

                    function nextExpiringSlide() {
                        if (expiringPage < expiringMaxPage) { expiringPage++; updateExpiringCarousel(); }
                    }
                    function prevExpiringSlide() {
                        if (expiringPage > 0) { expiringPage--; updateExpiringCarousel(); }
                    }
                </script>
                <?php endif; ?>
            <?php else: ?>
                <div class="dash-empty-state" style="--halo-color: rgba(34, 197, 94, 0.18); --empty-bg: rgba(34, 197, 94, 0.08); --empty-border: rgba(34, 197, 94, 0.22); --empty-color: #22c55e;">
                    <div class="dash-empty-halo">
                        <div class="dash-empty-icon-circle">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                                <polyline points="9 12 11 14 15 10"></polyline>
                            </svg>
                        </div>
                    </div>
                    <h3 class="dash-empty-title">All Memberships Active & Current</h3>
                    <p class="dash-empty-desc">No member plans are expiring within the next 3 days. Your community retention is currently in great standing!</p>
                    <div class="dash-empty-pill">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        <span>Zero Expirations Due</span>
                    </div>
                </div>
            <?php endif; ?>
        </article>

        <!-- 4. INACTIVE MEMBERS (AT-RISK) -->
        <article class="panel dash-panel-enhanced">
            <div class="dash-panel-head">
                <div class="dash-panel-title-wrap">
                    <div class="dash-panel-icon theme-violet">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <line x1="19" y1="8" x2="19" y2="14"></line>
                            <line x1="19" y1="17" x2="19.01" y2="17"></line>
                        </svg>
                    </div>
                    <div class="dash-panel-title-group">
                        <h2 class="dash-panel-title">Inactive Members (At-Risk)</h2>
                        <span class="dash-panel-sub">Absent for 15+ days</span>
                    </div>
                </div>
                <div class="dash-panel-actions">
                    <?php if ($inactive): ?>
                        <span class="dash-badge-chip rose"><?= count($inactive) ?> At Risk</span>
                        <div id="inactive-nav" class="dash-carousel-nav" style="display:none;">
                            <button type="button" onclick="prevInactiveSlide()" title="Previous">&#8592;</button>
                            <span id="inactive-page-indicator"></span>
                            <button type="button" onclick="nextInactiveSlide()" title="Next">&#8594;</button>
                        </div>
                    <?php else: ?>
                        <span class="dash-badge-chip emerald">✓ Healthy</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($inactive): ?>
                <div class="list-stack" id="inactive-carousel">
                    <?php foreach ($inactive as $index => $row): ?>
                        <div class="checkin-row inactive-slide" style="display: <?= $index < 3 ? 'grid' : 'none' ?>;">
                            <?= render_avatar($row) ?>
                            <div><strong><?= h($row['first_name'] . ' ' . $row['last_name']) ?></strong><small>Last visit: <?= $row['last_checkin'] ? date('M j, Y', strtotime($row['last_checkin'])) : 'Never' ?></small></div>
                            <time style="color:var(--danger); font-weight:700; font-size:12px;"><?= (int)$row['days_inactive'] ?>d absent</time>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($inactive) > 3): ?>
                <script>
                    document.getElementById('inactive-nav').style.display = 'inline-flex';
                    let inactivePage = 0;
                    const inactiveRows = document.querySelectorAll('.inactive-slide');
                    const inactiveMaxPage = Math.ceil(inactiveRows.length / 3) - 1;
                    const inactiveIndicator = document.getElementById('inactive-page-indicator');
                    
                    function updateInactiveCarousel() {
                        inactiveRows.forEach((row, i) => {
                            row.style.display = (i >= inactivePage * 3 && i < (inactivePage + 1) * 3) ? 'grid' : 'none';
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
            <?php else: ?>
                <div class="dash-empty-state" style="--halo-color: rgba(168, 85, 247, 0.18); --empty-bg: rgba(168, 85, 247, 0.08); --empty-border: rgba(168, 85, 247, 0.22); --empty-color: #a855f7;">
                    <div class="dash-empty-halo">
                        <div class="dash-empty-icon-circle">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <polyline points="16 11 18 13 22 9"></polyline>
                            </svg>
                        </div>
                    </div>
                    <h3 class="dash-empty-title">Great Community Engagement!</h3>
                    <p class="dash-empty-desc">All active members have visited your gym within the last 15 days. Zero members currently at churn risk.</p>
                    <div class="dash-empty-pill" style="background:rgba(168, 85, 247, 0.1); border-color:rgba(168, 85, 247, 0.25); color:#a855f7;">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                        <span>100% Active Attendance</span>
                    </div>
                </div>
            <?php endif; ?>
        </article>
    </section>
<?php

}
