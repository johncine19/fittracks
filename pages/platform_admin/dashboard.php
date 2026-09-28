<?php
declare(strict_types=1);

function platform_admin_dashboard(PDO $pdo, array $user): void
{
    $members = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "member" AND status = "active"')->fetchColumn();
    $gyms = (int) $pdo->query('SELECT COUNT(*) FROM gyms WHERE status = "approved"')->fetchColumn();
    $pendingGyms = (int) $pdo->query('SELECT COUNT(*) FROM gyms WHERE status = "pending"')->fetchColumn();
    // Platform Revenue = Total gym subscriptions collected this month
    $revenue = (float) $pdo->query(
        'SELECT COALESCE(SUM(amount), 0) FROM gym_subscription_payments WHERE status = "paid" AND payment_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01")'
    )->fetchColumn();

    $revenueTrend = calc_revenue_trend($pdo);
    $memberTrend  = calc_member_trend($pdo);

    $monthStart = (new DateTime('first day of this month'))->modify('-5 months');
    $monthlyRows = query_all(
        'SELECT DATE_FORMAT(payment_date, "%Y-%m") AS month_key, COALESCE(SUM(amount), 0) AS total 
         FROM gym_subscription_payments 
         WHERE status = "paid" AND payment_date >= ?
         GROUP BY month_key',
        [$monthStart->format('Y-m-01')]
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

    $lastMonthRevenue = (float) $pdo->query(
        'SELECT COALESCE(SUM(amount), 0) FROM gym_subscription_payments WHERE status = "paid" AND DATE_FORMAT(payment_date, "%Y-%m") = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), "%Y-%m")'
    )->fetchColumn();
    $revPct = $lastMonthRevenue > 0
        ? round((($revenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1)
        : null;
    $revPctLabel = $revPct !== null ? ($revPct >= 0 ? '+' : '') . $revPct . '%' : '—';

    $chartPayload = [
        'revenue'  => ['labels' => $monthLabels, 'values' => $monthlyValues],
        'checkins' => ['labels' => [], 'values' => []], // not used for platform
    ];

    $recentGyms = $pdo->query('SELECT name, created_at, status FROM gyms ORDER BY created_at DESC LIMIT 5')->fetchAll();
    $recentPayments = $pdo->query('
        SELECT sp.amount, sp.payment_date, sp.plan_name, sp.payment_method, g.name AS gym_name, u.first_name, u.last_name
        FROM gym_subscription_payments sp
        JOIN gyms g ON g.gym_id = sp.gym_id
        JOIN users u ON u.user_id = sp.owner_user_id
        WHERE sp.status = "paid"
        ORDER BY sp.payment_date DESC
        LIMIT 5
    ')->fetchAll();

    $platformRatingStats = get_platform_rating_stats();
    $recentPlatformFeedback = get_platform_reviews(6);
?>
    <?php render_skeleton_stats(5); ?>
    <section class="dash-grid stats-row skeleton-content sk-display-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
        <?php 
        $iconMembers = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>';
        $iconRevenue = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 21V3h7.5a4.5 4.5 0 1 1 0 9H7" /><line x1="4" y1="8" x2="17" y2="8" /><line x1="4" y1="12" x2="17" y2="12" /></svg>';
        $iconGyms = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16M9 21v-4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v4"></path></svg>';
        $iconPending = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>';
        $iconRating = '<svg viewBox="0 0 24 24" fill="currentColor" style="color:#f59e0b"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
        
        dashboard_stat('Platform Revenue', money($revenue), 'Total this month', $revenueTrend, $iconRevenue, true);
        dashboard_stat('Platform Rating', number_format($platformRatingStats['avg_rating'], 1) . ' ★', $platformRatingStats['total_reviews'] . ' Owner Reviews', 'Featured on Landing', $iconRating);
        dashboard_stat('Total Members', (string) $members, 'Across all gyms', $memberTrend, $iconMembers);
        dashboard_stat('Approved Gyms', (string) $gyms, 'Active on platform', '', $iconGyms);
        dashboard_stat('Pending Gyms', (string) $pendingGyms, 'Require approval', '', $iconPending);
        ?>
    </section>

    <div class="skeleton-wrapper"><section class="dash-grid chart-row" style="margin-top:24px">
        <div class="sk-card" style="min-height:308px"><div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:18px"><div><div class="sk sk-title" style="width:140px;margin-bottom:6px"></div><div class="sk sk-text short" style="height:11px"></div></div></div><div class="sk sk-rect chart"></div></div>
    </section></div>
    
    <section class="dash-grid skeleton-content sk-display-grid" style="grid-template-columns: 1fr;">
        <article class="panel chart-panel revenue-panel">
            <div class="panel-title">
                <div>
                    <h2>Platform Revenue Trend</h2>
                    <p>Last 6 months</p>
                </div><span<?= ($revPct !== null && $revPct < 0) ? ' class="trend-down-bg"' : '' ?>><?= h($revPctLabel) ?></span>
            </div>
            <div class="chart-canvas"><canvas id="revenueTrendChart"></canvas></div>
        </article>
    </section>
    <script>
        window.apexDashboardCharts = <?= json_encode($chartPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    </script>
    <script src="assets/dashboard-charts.js" defer></script>

    <div class="skeleton-wrapper"><section class="dash-grid lower-row" style="margin-top:24px">
        <div class="sk-card"><div class="sk sk-title" style="width:130px;margin-bottom:14px"></div><div class="list-stack" style="gap:10px"></div></div>
        <div class="sk-card"><div class="sk sk-title" style="width:130px;margin-bottom:14px"></div><div class="list-stack" style="gap:10px"></div></div>
    </section></div>

    <section class="dash-grid lower-row skeleton-content sk-display-grid">
        <article class="panel">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h2>Recent Gym Registrations</h2>
                <a href="index.php?page=gym_applications" style="font-size: 13px; color: var(--lime); text-decoration: none;">View all →</a>
            </div>
            <div class="list-stack">
                <?php foreach ($recentGyms as $gym): ?>
                    <div class="checkin-row" style="display:flex; align-items:center; gap:14px; padding:14px 0; border-bottom:1px solid rgba(255,255,255,0.04);">
                        <div style="width: 40px; height: 40px; background: rgba(199,255,34,0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--lime);">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16M9 21v-4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v4"></path></svg>
                        </div>
                        <div style="flex:1"><strong><?= h($gym['name']) ?></strong><small style="display:block; font-size:12px; color:var(--muted);"><?= h(ucfirst($gym['status'])) ?></small></div>
                        <time style="font-size:12px; color:var(--muted);"><?= h(date('M d, Y', strtotime($gym['created_at']))) ?></time>
                    </div>
                <?php endforeach;
                if (!$recentGyms): ?><p class="muted">No gyms registered yet.</p><?php endif; ?>
            </div>
        </article>

        <article class="panel">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h2>Recent Subscriptions</h2>
                <a href="index.php?page=payments" style="font-size: 13px; color: var(--lime); text-decoration: none;">View all →</a>
            </div>
            <div class="list-stack">
                <?php foreach ($recentPayments as $payment): ?>
                    <div class="checkin-row" style="display:flex; align-items:center; gap:14px; padding:14px 0; border-bottom:1px solid rgba(255,255,255,0.04);">
                        <div style="width: 40px; height: 40px; background: rgba(34, 197, 94, 0.15); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: bold; color: #22c55e;">₱</div>
                        <div style="flex:1">
                            <strong><?= h($payment['gym_name']) ?></strong>
                            <small style="display:block; font-size:12px; color:var(--muted);">
                                <?= h($payment['first_name'] . ' ' . $payment['last_name']) ?> &bull; <?= h($payment['plan_name']) ?> Plan (<?= strtoupper(h($payment['payment_method'])) ?>)
                            </small>
                        </div>
                        <time style="color:var(--lime); font-size:13px; font-weight:bold;"><?= money((float)$payment['amount']) ?></time>
                    </div>
                <?php endforeach;
                if (!$recentPayments): ?><p class="muted">No subscription payments recorded yet.</p><?php endif; ?>
            </div>
        </article>
    </section>

    <!-- Platform Owner Ratings & Feedback Section -->
    <section class="dash-grid skeleton-content sk-display-grid" style="grid-template-columns: 1fr; margin-top:24px;">
        <article class="panel">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
                <div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; color:var(--lime); background:rgba(199,255,34,0.1); padding:2px 8px; border-radius:4px;">
                            Gym Owners &rarr; FitTrack Platform
                        </span>
                        <span style="font-size:11px; color:#94a3b8; background:rgba(255,255,255,0.06); padding:2px 8px; border-radius:999px;">
                            Featured on Public Landing Page
                        </span>
                    </div>
                    <h2 style="font-size:1.15rem; font-weight:700; margin:6px 0 0 0;">Commercial Gym Owner Feedback</h2>
                    <p style="font-size:12px; color:var(--muted); margin:3px 0 0 0;">
                        Live ratings submitted by gym operators regarding system usability, features, and platform services.
                    </p>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <a href="index.php?page=landing#testimonials" target="_blank" class="btn btn-secondary" style="font-size:12px; padding:5px 12px; text-decoration:none;">
                        View Live on Landing Page &rarr;
                    </a>
                </div>
            </div>

            <!-- Score Summary & Breakdown Bar -->
            <div style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.06); border-radius:12px; padding:18px 24px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:20px;">
                <div style="display:flex; align-items:center; gap:18px;">
                    <div style="font-size:42px; font-weight:900; line-height:1; color:#fff;">
                        <?= number_format($platformRatingStats['avg_rating'], 1) ?>
                    </div>
                    <div>
                        <div style="display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                            <?= render_star_rating((float)$platformRatingStats['avg_rating'], 16, false) ?>
                            <strong style="color:var(--lime); font-size:13px;">Overall Platform Score</strong>
                        </div>
                        <span style="font-size:12px; color:var(--muted);">
                            Based on <?= $platformRatingStats['total_reviews'] ?> verified reviews from approved gym owners
                        </span>
                    </div>
                </div>

                <div style="display:flex; align-items:center; gap:20px; flex-wrap:wrap;">
                    <div style="text-align:center; padding:0 10px;">
                        <div style="font-size:17px; font-weight:800; color:var(--lime);"><?= number_format($platformRatingStats['avg_system'], 1) ?>★</div>
                        <div style="font-size:11px; color:var(--muted); text-transform:uppercase;">System UX</div>
                    </div>
                    <div style="height:24px; width:1px; background:rgba(255,255,255,0.1);"></div>
                    <div style="text-align:center; padding:0 10px;">
                        <div style="font-size:17px; font-weight:800; color:var(--lime);"><?= number_format($platformRatingStats['avg_features'], 1) ?>★</div>
                        <div style="font-size:11px; color:var(--muted); text-transform:uppercase;">Features</div>
                    </div>
                    <div style="height:24px; width:1px; background:rgba(255,255,255,0.1);"></div>
                    <div style="text-align:center; padding:0 10px;">
                        <div style="font-size:17px; font-weight:800; color:var(--lime);"><?= number_format($platformRatingStats['avg_service'], 1) ?>★</div>
                        <div style="font-size:11px; color:var(--muted); text-transform:uppercase;">Service Quality</div>
                    </div>
                </div>
            </div>

            <!-- Recent Gym Owner Reviews Grid -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:14px;">
                <?php foreach ($recentPlatformFeedback as $pf): ?>
                    <div style="background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.04); border-radius:10px; padding:14px; display:flex; flex-direction:column; justify-content:space-between;">
                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                                <div>
                                    <strong style="font-size:13.5px; display:block; color:#fff;"><?= h($pf['gym_name'] ?? 'Commercial Gym') ?></strong>
                                    <small style="font-size:11.5px; color:var(--muted);"><?= h(($pf['first_name'] ?? '') . ' ' . ($pf['last_name'] ?? '')) ?> (Owner)</small>
                                </div>
                                <div>
                                    <?= render_star_rating((float)$pf['rating'], 14, false) ?>
                                </div>
                            </div>
                            <?php if (!empty($pf['review'])): ?>
                                <p style="font-size:12.5px; line-height:1.45; color:rgba(255,255,255,0.85); margin:0 0 10px 0; font-style:italic;">
                                    &ldquo;<?= h($pf['review']) ?>&rdquo;
                                </p>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center; font-size:11px; color:var(--muted); border-top:1px solid rgba(255,255,255,0.04); padding-top:8px; margin-top:6px;">
                            <div style="display:flex; gap:8px;">
                                <span>UX: <b style="color:var(--lime);"><?= (int)($pf['system_experience'] ?? 5) ?>★</b></span>
                                <span>Feat: <b style="color:var(--lime);"><?= (int)($pf['features_rating'] ?? 5) ?>★</b></span>
                                <span>Serv: <b style="color:var(--lime);"><?= (int)($pf['service_rating'] ?? 5) ?>★</b></span>
                            </div>
                            <time><?= h(date('M d, Y', strtotime($pf['created_at']))) ?></time>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$recentPlatformFeedback): ?>
                    <p class="muted" style="grid-column: 1 / -1; text-align:center; padding:20px;">No platform reviews submitted yet.</p>
                <?php endif; ?>
            </div>
        </article>
    </section>
<?php
}
