<?php
declare(strict_types=1);

function handle_export(string $type, string $format, array $data): void
{
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $type . '_report_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        if (!empty($data)) {
            // Write headers
            fputcsv($out, array_keys($data[0]));
            // Write rows
            foreach ($data as $row) {
                fputcsv($out, array_values($row));
            }
        }
        fclose($out);
        exit;
    }

    if ($format === 'print') {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title><?= h(ucfirst($type)) ?> Report</title>
            <style>
                body { font-family: sans-serif; padding: 20px; }
                table { border-collapse: collapse; width: 100%; margin-top: 20px; }
                th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
                th { background: #f4f4f4; }
                @media print {
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="no-print" style="margin-bottom: 20px;">
                <button onclick="window.print()">Print / Save as PDF</button>
                <button onclick="window.close()">Close</button>
            </div>
            <h2><?= h(ucfirst($type)) ?> Report - <?= h(date('Y-m-d')) ?></h2>
            <table>
                <?php if (!empty($data)): ?>
                    <thead>
                        <tr>
                            <?php foreach (array_keys($data[0]) as $col): ?>
                                <th><?= h(ucfirst((string)$col)) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data as $row): ?>
                            <tr>
                                <?php foreach ($row as $val): ?>
                                    <td><?= h((string)$val) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                <?php else: ?>
                    <tr><td>No data available.</td></tr>
                <?php endif; ?>
            </table>
            <script>
                window.onload = function() { window.print(); }
            </script>
        </body>
        </html>
        <?php
        exit;
    }
}

function reports_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner']);
    $pdo = db();
    $isPlatformAdmin = $user['role'] === 'platform_admin';
    
    $gymId = null;
    if (!$isPlatformAdmin) {
        $gymId = (int) scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
    }

    $selectedGymId = 'all';
    $approvedGyms = [];
    if ($isPlatformAdmin) {
        $approvedGyms = query_all("SELECT gym_id, name FROM gyms WHERE status = 'approved' ORDER BY name");
        if (isset($_GET['gym_id']) && $_GET['gym_id'] !== 'all') {
            $selectedGymId = (int)$_GET['gym_id'];
        }
    }

    if ($isPlatformAdmin) {
        if ($selectedGymId !== 'all') {
            $revenueCondition = '
                SELECT DATE(p.payment_date) AS day, DATE_FORMAT(p.payment_date, "%Y-%m") AS month, YEAR(p.payment_date) AS year, p.amount AS revenue 
                FROM payments p
                JOIN memberships m ON m.membership_id = p.membership_id
                JOIN membership_plans mp ON mp.plan_id = m.plan_id
                WHERE p.status = "paid" AND mp.gym_id = ' . $selectedGymId;
            $attendanceCondition = 'SELECT DATE(check_in_time) AS day, DATE_FORMAT(check_in_time, "%Y-%m") AS month, YEAR(check_in_time) AS year FROM attendance WHERE gym_id = ' . $selectedGymId;
            $members = query_all('SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.email, u.profile_picture FROM users u JOIN memberships m ON m.user_id = u.user_id JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE u.role = "member" AND u.status = "active" AND m.status = "active" AND mp.gym_id = ' . $selectedGymId);
        } else {
            $revenueCondition = '
                SELECT DATE(payment_date) AS day, DATE_FORMAT(payment_date, "%Y-%m") AS month, YEAR(payment_date) AS year, amount AS revenue 
                FROM payments WHERE status = "paid"
                UNION ALL
                SELECT DATE(visit_date) AS day, DATE_FORMAT(visit_date, "%Y-%m") AS month, YEAR(visit_date) AS year, amount_paid AS revenue 
                FROM walk_in_transactions
            ';
            $attendanceCondition = 'SELECT DATE(check_in_time) AS day, DATE_FORMAT(check_in_time, "%Y-%m") AS month, YEAR(check_in_time) AS year FROM attendance';
            $members = query_all('SELECT user_id, first_name, last_name, email, profile_picture FROM users WHERE role = "member" AND status = "active"');
        }
    } else {
        $revenueCondition = '
            SELECT DATE(p.payment_date) AS day, DATE_FORMAT(p.payment_date, "%Y-%m") AS month, YEAR(p.payment_date) AS year, p.amount AS revenue 
            FROM payments p
            JOIN memberships m ON m.membership_id = p.membership_id
            JOIN membership_plans mp ON mp.plan_id = m.plan_id
            WHERE p.status = "paid" AND mp.gym_id = ' . $gymId;
        $attendanceCondition = 'SELECT DATE(check_in_time) AS day, DATE_FORMAT(check_in_time, "%Y-%m") AS month, YEAR(check_in_time) AS year FROM attendance WHERE gym_id = ' . $gymId;
        $members = query_all('SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.email, u.profile_picture FROM users u JOIN memberships m ON m.user_id = u.user_id JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE u.role = "member" AND u.status = "active" AND m.status = "active" AND mp.gym_id = ' . $gymId);
    }

    $revenue = [
        'daily' => query_all('SELECT day, SUM(revenue) AS revenue FROM (' . $revenueCondition . ') AS combined_revenue GROUP BY day ORDER BY day DESC LIMIT 14'),
        'monthly' => query_all('SELECT month, SUM(revenue) AS revenue FROM (' . $revenueCondition . ') AS combined_revenue GROUP BY month ORDER BY month DESC LIMIT 12'),
        'yearly' => query_all('SELECT year, SUM(revenue) AS revenue FROM (' . $revenueCondition . ') AS combined_revenue GROUP BY year ORDER BY year DESC LIMIT 5')
    ];
    $attendance = [
        'daily' => query_all('SELECT day, COUNT(*) AS visits FROM (' . $attendanceCondition . ') AS a GROUP BY day ORDER BY day DESC LIMIT 14'),
        'monthly' => query_all('SELECT month, COUNT(*) AS visits FROM (' . $attendanceCondition . ') AS a GROUP BY month ORDER BY month DESC LIMIT 12'),
        'yearly' => query_all('SELECT year, COUNT(*) AS visits FROM (' . $attendanceCondition . ') AS a GROUP BY year ORDER BY year DESC LIMIT 5')
    ];
    
    // Engagement Analytics
    $categories = ['Highly Engaged' => 0, 'Moderately Engaged' => 0, 'At-Risk' => 0];
    $memberLists = ['Highly Engaged' => [], 'Moderately Engaged' => [], 'At-Risk' => []];
    foreach ($members as $m) {
        $score = get_cached_engagement_score((int) $m['user_id']);
        $cat = get_engagement_category($score);
        $categories[$cat]++;
        $memberLists[$cat][] = $m;
    }
    
    $engagementData = [
        ['category' => 'Highly Engaged', 'count' => $categories['Highly Engaged']],
        ['category' => 'Moderately Engaged', 'count' => $categories['Moderately Engaged']],
        ['category' => 'At-Risk', 'count' => $categories['At-Risk']],
    ];

    // Helper to pad time series data
    $pad_time_series = function(array $data, string $key, string $valKey, string $tf): array {
        $padded = [];
        $today = new DateTime();
        $limit = ($tf === 'daily') ? 14 : (($tf === 'monthly') ? 12 : 5);
        $modifier = ($tf === 'daily') ? '-1 day' : (($tf === 'monthly') ? '-1 month' : '-1 year');
        $format = ($tf === 'daily') ? 'Y-m-d' : (($tf === 'monthly') ? 'Y-m' : 'Y');

        $dataMap = [];
        foreach ($data as $row) {
            $dataMap[$row[$key]] = $row[$valKey];
        }

        $current = clone $today;
        for ($i = 0; $i < $limit; $i++) {
            $dateStr = $current->format($format);
            $padded[] = [
                $key => $dateStr,
                $valKey => $dataMap[$dateStr] ?? 0
            ];
            $current->modify($modifier);
        }
        return $padded;
    };

    // Format dates according to user preference
    foreach (['daily', 'monthly', 'yearly'] as $tf) {
        $key = ($tf === 'daily') ? 'day' : (($tf === 'monthly') ? 'month' : 'year');
        $revenue[$tf] = $pad_time_series($revenue[$tf], $key, 'revenue', $tf);
        $attendance[$tf] = $pad_time_series($attendance[$tf], $key, 'visits', $tf);

        foreach ($revenue[$tf] as &$row) {
            if ($tf === 'daily') $row[$key] = date('d-m-Y', strtotime($row[$key]));
            elseif ($tf === 'monthly') $row[$key] = date('M Y', strtotime($row[$key] . '-01'));
        }
        unset($row);
        foreach ($attendance[$tf] as &$row) {
            if ($tf === 'daily') $row[$key] = date('d-m-Y', strtotime($row[$key]));
            elseif ($tf === 'monthly') $row[$key] = date('M Y', strtotime($row[$key] . '-01'));
        }
        unset($row);
    }

    // Handle exports
    if (isset($_GET['export']) && isset($_GET['type'])) {
        $format = $_GET['export'];
        $type = $_GET['type'];
        $timeframe = $_GET['timeframe'] ?? 'monthly';
        
        if ($type === 'engagement') handle_export($type, $format, $engagementData);
        if ($type === 'revenue') handle_export($type . '_' . $timeframe, $format, $revenue[$timeframe] ?? $revenue['monthly']);
        if ($type === 'attendance') handle_export($type . '_' . $timeframe, $format, $attendance[$timeframe] ?? $attendance['daily']);
    }

    $engagementJson = json_encode(array_column($engagementData, 'count'));
    $engagementLabels = json_encode(array_column($engagementData, 'category'));
    
    $chartsData = [
        'revenue' => [],
        'attendance' => []
    ];
    foreach (['daily', 'monthly', 'yearly'] as $tf) {
        $key = ($tf === 'daily') ? 'day' : (($tf === 'monthly') ? 'month' : 'year');
        $rRev = array_reverse($revenue[$tf]);
        $chartsData['revenue'][$tf] = [
            'labels' => array_column($rRev, $key),
            'data' => array_map(fn($r) => (float)$r['revenue'], $rRev)
        ];
        
        $aRev = array_reverse($attendance[$tf]);
        $chartsData['attendance'][$tf] = [
            'labels' => array_column($aRev, $key),
            'data' => array_map(fn($a) => (int)$a['visits'], $aRev)
        ];
    }
    $chartsDataJson = json_encode($chartsData);

    render_header('Reports', $user);
    ?>
    <style>
        .report-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
        }
        .report-tab-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--line);
            color: var(--muted);
            padding: 10px 20px;
            border-radius: 999px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .report-tab-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--ink);
        }
        .report-tab-btn.active {
            background: var(--lime);
            color: var(--bg);
            border-color: var(--lime);
            font-weight: bold;
            box-shadow: 0 4px 12px rgba(204, 255, 0, 0.2);
        }
        .tf-btn {
            background: transparent;
            border: 1px solid var(--line);
            color: var(--muted);
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .tf-btn.active {
            background: var(--panel-soft);
            color: var(--ink);
            border-color: var(--ink);
        }
        select option {
            background: #1e293b; /* Dark background to ensure white text is visible */
            color: #f8fafc; /* Light text color */
        }
    </style>

    <!-- Glassmorphic Banner -->
    <div class="animate-fade-in" style="background: linear-gradient(135deg, rgba(199,255,34,0.1) 0%, rgba(66,219,165,0.05) 100%); border: 1px solid rgba(199,255,34,0.2); border-radius: 16px; padding: 28px 32px; margin-bottom: 24px; box-shadow: 0 4px 24px rgba(0,0,0,0.1); backdrop-filter: blur(16px); display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
        <div>
            <h1 style="margin: 0; font-size: 26px; color: var(--ink); display: flex; align-items: center; gap: 12px;">
                <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="var(--lime)" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path></svg>
                <?= $isPlatformAdmin ? 'Platform Reports' : 'Gym Reports' ?>
            </h1>
            <p style="margin: 8px 0 0 0; color: var(--muted); font-size: 15px; max-width: 600px;">
                <?= $isPlatformAdmin ? 'Overview of global platform revenue, attendance, and member engagement.' : 'Detailed breakdown of your gym\'s revenue, attendance, and member retention metrics.' ?>
            </p>
        </div>
        
        <?php if ($isPlatformAdmin && !empty($approvedGyms)): ?>
            <div style="background: rgba(255,255,255,0.05); padding: 12px 16px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 12px;">
                <label for="gym-filter" style="color: var(--muted); font-size: 14px; white-space: nowrap;">Filter by Gym:</label>
                <select id="gym-filter" onchange="window.location.href='index.php?page=reports&gym_id='+this.value;" style="background: var(--surface); color: var(--ink); border: 1px solid var(--line); padding: 8px 12px; border-radius: 6px; font-size: 14px; min-width: 200px;">
                    <option value="all" <?= $selectedGymId === 'all' ? 'selected' : '' ?>>All Gyms (Global)</option>
                    <?php foreach ($approvedGyms as $g): ?>
                        <option value="<?= $g['gym_id'] ?>" <?= $selectedGymId === (int)$g['gym_id'] ? 'selected' : '' ?>><?= h($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </div>

    <!-- Navigation Tabs -->
    <?php if (!$isPlatformAdmin): ?>
    <div class="report-tabs">
        <button class="report-tab-btn active" onclick="showTab('revenue-tab')">Revenue</button>
        <button class="report-tab-btn" onclick="showTab('attendance-tab')">Attendance</button>
        <button class="report-tab-btn" onclick="showTab('engagement-tab')">Engagement</button>
    </div>
    <?php endif; ?>

    <!-- Revenue Tab -->
    <div id="revenue-tab" class="tab-content animate-fade-in">
        <div class="panel" style="border-radius: 16px; padding: 24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <h2 style="margin:0;">Revenue Analytics</h2>
                <div style="display: flex; gap: 8px;">
                    <a href="index.php?page=reports&type=revenue&timeframe=monthly&export=csv" id="btn-export-revenue-csv" class="btn" style="background: transparent; border: 1px solid var(--line); color: var(--ink);">Export CSV</a>
                    <a href="index.php?page=reports&type=revenue&timeframe=monthly&export=print" id="btn-export-revenue-print" target="_blank" class="btn" style="background: transparent; border: 1px solid var(--line); color: var(--ink);">Print / PDF</a>
                </div>
            </div>
            
            <div style="margin-bottom: 24px; display: flex; gap: 8px; background: var(--surface); padding: 6px; border-radius: 8px; width: fit-content;">
                <button class="tf-btn tf-btn-revenue" onclick="setTimeframe('revenue', 'daily')" id="tf-revenue-daily">Daily</button>
                <button class="tf-btn tf-btn-revenue active" onclick="setTimeframe('revenue', 'monthly')" id="tf-revenue-monthly">Monthly</button>
                <button class="tf-btn tf-btn-revenue" onclick="setTimeframe('revenue', 'yearly')" id="tf-revenue-yearly">Yearly</button>
            </div>

            <div class="dash-grid" style="grid-template-columns: 2fr 1fr; gap: 24px; align-items: start;">
                <div class="chart-canvas" style="min-height: 350px;">
                    <canvas id="revenueChart"></canvas>
                </div>
                <div class="table-wrap" style="max-height: 400px; overflow-y: auto; border: 1px solid var(--line); border-radius: 8px;">
                    <div id="revenue-table-daily" style="display:none;"><?= render_simple_table($revenue['daily'], ['day', 'revenue']) ?></div>
                    <div id="revenue-table-monthly" style="display:block;"><?= render_simple_table($revenue['monthly'], ['month', 'revenue']) ?></div>
                    <div id="revenue-table-yearly" style="display:none;"><?= render_simple_table($revenue['yearly'], ['year', 'revenue']) ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (!$isPlatformAdmin): ?>
    <!-- Attendance Tab -->
    <div id="attendance-tab" class="tab-content animate-fade-in" style="display: none;">
        <div class="panel" style="border-radius: 16px; padding: 24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <h2 style="margin:0;">Attendance Analytics</h2>
                <div style="display: flex; gap: 8px;">
                    <a href="index.php?page=reports&type=attendance&timeframe=daily&export=csv" id="btn-export-attendance-csv" class="btn" style="background: transparent; border: 1px solid var(--line); color: var(--ink);">Export CSV</a>
                    <a href="index.php?page=reports&type=attendance&timeframe=daily&export=print" id="btn-export-attendance-print" target="_blank" class="btn" style="background: transparent; border: 1px solid var(--line); color: var(--ink);">Print / PDF</a>
                </div>
            </div>

            <div style="margin-bottom: 24px; display: flex; gap: 8px; background: var(--surface); padding: 6px; border-radius: 8px; width: fit-content;">
                <button class="tf-btn tf-btn-attendance active" onclick="setTimeframe('attendance', 'daily')" id="tf-attendance-daily">Daily</button>
                <button class="tf-btn tf-btn-attendance" onclick="setTimeframe('attendance', 'monthly')" id="tf-attendance-monthly">Monthly</button>
                <button class="tf-btn tf-btn-attendance" onclick="setTimeframe('attendance', 'yearly')" id="tf-attendance-yearly">Yearly</button>
            </div>

            <div class="dash-grid" style="grid-template-columns: 2fr 1fr; gap: 24px; align-items: start;">
                <div class="chart-canvas" style="min-height: 350px;">
                    <canvas id="attendanceChart"></canvas>
                </div>
                <div class="table-wrap" style="max-height: 400px; overflow-y: auto; border: 1px solid var(--line); border-radius: 8px;">
                    <div id="attendance-table-daily" style="display:block;"><?= render_simple_table($attendance['daily'], ['day', 'visits']) ?></div>
                    <div id="attendance-table-monthly" style="display:none;"><?= render_simple_table($attendance['monthly'], ['month', 'visits']) ?></div>
                    <div id="attendance-table-yearly" style="display:none;"><?= render_simple_table($attendance['yearly'], ['year', 'visits']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Engagement Tab -->
    <div id="engagement-tab" class="tab-content animate-fade-in" style="display: none;">
        <div class="panel" style="border-radius: 16px; padding: 24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 24px;">
                <h2 style="margin:0;">Member Engagement</h2>
                <div style="display: flex; gap: 8px;">
                    <a href="index.php?page=reports&type=engagement&export=csv" class="btn" style="background: transparent; border: 1px solid var(--line); color: var(--ink);">Export CSV</a>
                    <a href="index.php?page=reports&type=engagement&export=print" target="_blank" class="btn" style="background: transparent; border: 1px solid var(--line); color: var(--ink);">Print / PDF</a>
                </div>
            </div>

            <div class="dash-grid" style="grid-template-columns: 1.2fr 2fr; gap: 32px; align-items: start;">
                <div style="position: sticky; top: 20px;">
                    <div class="chart-canvas" style="min-height: 300px; padding: 20px;">
                        <canvas id="engagementChart"></canvas>
                    </div>
                </div>
                
                <div>
                    <?php foreach ($categories as $catName => $count): 
                        $catColor = $catName === 'Highly Engaged' ? '#10b981' : ($catName === 'Moderately Engaged' ? '#f59e0b' : '#ef4444');
                    ?>
                        <div style="margin-bottom: 24px; background: var(--surface); border: 1px solid var(--line); border-radius: 12px; overflow: hidden;">
                            <div style="padding: 16px 20px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center;">
                                <h3 style="margin:0; font-size: 16px; color: var(--ink); display: flex; align-items: center; gap: 8px;">
                                    <span style="width: 12px; height: 12px; border-radius: 50%; background: <?= $catColor ?>;"></span>
                                    <?= h($catName) ?>
                                </h3>
                                <span style="background: rgba(255,255,255,0.05); padding: 4px 12px; border-radius: 999px; font-size: 13px; color: var(--muted); font-weight: bold;">
                                    <?= $count ?> Members
                                </span>
                            </div>
                            
                            <?php if ($count > 0): ?>
                                <div class="table-wrap" style="max-height: 300px; overflow-y: auto;">
                                    <table style="margin: 0; width: 100%;">
                                        <tbody>
                                        <?php foreach ($memberLists[$catName] as $m): ?>
                                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.02);">
                                                <td style="display:flex; align-items:center; gap:12px; padding: 12px 20px;">
                                                    <?= render_avatar($m) ?>
                                                    <div>
                                                        <strong style="color: var(--ink); font-size: 14px; display: block;"><?= h($m['first_name'] . ' ' . $m['last_name']) ?></strong>
                                                        <span style="color: var(--muted); font-size: 13px;"><?= h($m['email']) ?></span>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div style="padding: 20px; text-align: center; color: var(--muted); font-size: 14px;">
                                    No members in this category.
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    window.showTab = function(tabId) {
        document.querySelectorAll('.tab-content').forEach(el => {
            el.style.display = 'none';
        });
        document.querySelectorAll('.report-tab-btn').forEach(el => el.classList.remove('active'));
        
        document.getElementById(tabId).style.display = 'block';
        document.querySelector(`button[onclick="showTab('\${tabId}')"]`).classList.add('active');
    };
    
    const chartsData = <?= $chartsDataJson ?>;
    let revenueChartInstance = null;
    let attendanceChartInstance = null;

    window.setTimeframe = function(type, timeframe) {
        document.querySelectorAll('.tf-btn-' + type).forEach(el => el.classList.remove('active'));
        const btn = document.getElementById('tf-' + type + '-' + timeframe);
        if (btn) btn.classList.add('active');

        ['daily', 'monthly', 'yearly'].forEach(tf => {
            const table = document.getElementById(type + '-table-' + tf);
            if (table) table.style.display = (tf === timeframe) ? 'block' : 'none';
        });

        const csvBtn = document.getElementById('btn-export-' + type + '-csv');
        const printBtn = document.getElementById('btn-export-' + type + '-print');
        if (csvBtn) csvBtn.href = 'index.php?page=reports&type=' + type + '&timeframe=' + timeframe + '&export=csv';
        if (printBtn) printBtn.href = 'index.php?page=reports&type=' + type + '&timeframe=' + timeframe + '&export=print';

        if (type === 'revenue' && revenueChartInstance) {
            revenueChartInstance.data.labels = chartsData.revenue[timeframe].labels;
            revenueChartInstance.data.datasets[0].data = chartsData.revenue[timeframe].data;
            revenueChartInstance.update();
        }
        if (type === 'attendance' && attendanceChartInstance) {
            attendanceChartInstance.data.labels = chartsData.attendance[timeframe].labels;
            attendanceChartInstance.data.datasets[0].data = chartsData.attendance[timeframe].data;
            attendanceChartInstance.update();
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        if (typeof Chart !== 'undefined') {
            Chart.defaults.color = '#8892b0';
            Chart.defaults.borderColor = 'rgba(255, 255, 255, 0.05)';
            
            new Chart(document.getElementById('engagementChart'), {
                type: 'doughnut',
                data: {
                    labels: <?= $engagementLabels ?>,
                    datasets: [{
                        data: <?= $engagementJson ?>,
                        backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    cutout: '75%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 24,
                                usePointStyle: true,
                                font: { size: 13, family: "'Inter', sans-serif" }
                            }
                        }
                    }
                }
            });

            revenueChartInstance = new Chart(document.getElementById('revenueChart'), {
                type: 'bar',
                data: {
                    labels: chartsData.revenue.monthly.labels,
                    datasets: [{
                        label: 'Revenue',
                        data: chartsData.revenue.monthly.data,
                        backgroundColor: '#ccff00',
                        borderRadius: 6,
                        barPercentage: 0.6
                    }]
                },
                options: { 
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { 
                        y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' } },
                        x: { grid: { display: false } }
                    }, 
                    plugins: { 
                        legend: { display: false },
                        tooltip: { backgroundColor: '#16181d', titleColor: '#fff', bodyColor: '#ccff00', padding: 12, borderColor: 'rgba(204,255,0,0.2)', borderWidth: 1 }
                    } 
                }
            });

            attendanceChartInstance = new Chart(document.getElementById('attendanceChart'), {
                type: 'line',
                data: {
                    labels: chartsData.attendance.daily.labels,
                    datasets: [{
                        label: 'Visits',
                        data: chartsData.attendance.daily.data,
                        borderColor: '#8b5cf6',
                        backgroundColor: 'rgba(139, 92, 246, 0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 3,
                        pointBackgroundColor: '#16181d',
                        pointBorderColor: '#8b5cf6',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: { 
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { 
                        y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' } },
                        x: { grid: { display: false } }
                    }, 
                    plugins: { 
                        legend: { display: false },
                        tooltip: { backgroundColor: '#16181d', titleColor: '#fff', bodyColor: '#8b5cf6', padding: 12, borderColor: 'rgba(139,92,246,0.2)', borderWidth: 1 }
                    } 
                }
            });
        }
    });
    </script>
    <?php
    render_footer();
}
