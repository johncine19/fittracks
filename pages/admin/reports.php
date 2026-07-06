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
    $user = require_roles(['admin']);
    $revenue = [
        'daily' => query_all('
            SELECT day, SUM(revenue) AS revenue FROM (
                SELECT DATE(payment_date) AS day, amount AS revenue 
                FROM payments WHERE status = "paid"
                UNION ALL
                SELECT DATE(visit_date) AS day, amount_paid AS revenue 
                FROM walk_in_transactions
            ) AS combined_revenue
            GROUP BY day ORDER BY day DESC LIMIT 14
        '),
        'monthly' => query_all('
            SELECT month, SUM(revenue) AS revenue FROM (
                SELECT DATE_FORMAT(payment_date, "%Y-%m") AS month, amount AS revenue 
                FROM payments WHERE status = "paid"
                UNION ALL
                SELECT DATE_FORMAT(visit_date, "%Y-%m") AS month, amount_paid AS revenue 
                FROM walk_in_transactions
            ) AS combined_revenue
            GROUP BY month ORDER BY month DESC LIMIT 12
        '),
        'yearly' => query_all('
            SELECT year, SUM(revenue) AS revenue FROM (
                SELECT YEAR(payment_date) AS year, amount AS revenue 
                FROM payments WHERE status = "paid"
                UNION ALL
                SELECT YEAR(visit_date) AS year, amount_paid AS revenue 
                FROM walk_in_transactions
            ) AS combined_revenue
            GROUP BY year ORDER BY year DESC LIMIT 5
        ')
    ];
    $attendance = [
        'daily' => query_all('SELECT DATE(check_in_time) AS day, COUNT(*) AS visits FROM attendance GROUP BY day ORDER BY day DESC LIMIT 14'),
        'monthly' => query_all('SELECT DATE_FORMAT(check_in_time, "%Y-%m") AS month, COUNT(*) AS visits FROM attendance GROUP BY month ORDER BY month DESC LIMIT 12'),
        'yearly' => query_all('SELECT YEAR(check_in_time) AS year, COUNT(*) AS visits FROM attendance GROUP BY year ORDER BY year DESC LIMIT 5')
    ];
    
    // Engagement Analytics
    $members = query_all('SELECT user_id, first_name, last_name, email, profile_picture FROM users WHERE role = "member" AND status = "active"');
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
            elseif ($tf === 'monthly') $row[$key] = date('m-Y', strtotime($row[$key] . '-01'));
        }
        unset($row);
        foreach ($attendance[$tf] as &$row) {
            if ($tf === 'daily') $row[$key] = date('d-m-Y', strtotime($row[$key]));
            elseif ($tf === 'monthly') $row[$key] = date('m-Y', strtotime($row[$key] . '-01'));
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
    <div class="skeleton-wrapper">
        <section class="panel">
            <div class="sk sk-title" style="width:140px;margin-bottom:24px"></div>
            <div style="display:flex;gap:16px;margin-bottom:32px;border-bottom:1px solid var(--line);padding-bottom:16px">
                <div class="sk sk-rect" style="width:100px;height:36px;border-radius:4px"></div>
                <div class="sk sk-text" style="width:80px;height:24px;margin-top:6px"></div>
                <div class="sk sk-text" style="width:80px;height:24px;margin-top:6px"></div>
            </div>
            <div style="display:flex;justify-content:space-between;margin-bottom:24px">
                <div class="sk sk-title" style="width:200px"></div>
                <div style="display:flex;gap:8px">
                    <div class="sk sk-rect" style="width:80px;height:28px;border-radius:4px"></div>
                    <div class="sk sk-rect" style="width:80px;height:28px;border-radius:4px"></div>
                </div>
            </div>
            <?php render_skeleton_chart(); ?>
            <div style="margin-top:24px">
                <?php render_skeleton_table(2, 4); ?>
            </div>
        </section>
    </div>
    <?php
    echo '<section class="panel skeleton-content sk-display-block"><h1>Reports</h1>';
    echo '<div class="report-tabs" style="margin-bottom: 2rem; display: flex; gap: 1rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 1rem;">
        <button class="tab-btn active" onclick="showTab(\'engagement-tab\')" style="padding: 0.5rem 1rem; cursor: pointer; background: #3b82f6; color: white; border: none; border-radius: 4px;">Engagement</button>
        <button class="tab-btn" onclick="showTab(\'revenue-tab\')" style="padding: 0.5rem 1rem; cursor: pointer; background: transparent; color: #64748b; border: 1px solid transparent;">Revenue</button>
        <button class="tab-btn" onclick="showTab(\'attendance-tab\')" style="padding: 0.5rem 1rem; cursor: pointer; background: transparent; color: #64748b; border: 1px solid transparent;">Attendance</button>
    </div>';

    // Engagement Tab
    echo '<div id="engagement-tab" class="tab-content">';
    echo '<div style="display:flex; justify-content:space-between; align-items:flex-start;">';
    echo '<h2>Member Engagement Analytics</h2>';
    echo '<div>
            <a href="index.php?page=reports&type=engagement&export=csv" class="btn-sm btn-ghost">Export CSV</a>
            <a href="index.php?page=reports&type=engagement&export=print" target="_blank" class="btn-sm btn-ghost">Print / PDF</a>
          </div>';
    echo '</div>';
    echo '<div style="display: flex; flex-wrap: wrap; gap: 4rem; align-items: flex-start; margin-top: 1.5rem;">';
    
    echo '<div style="flex: 1; min-width: 300px; max-width: 550px; margin-bottom: 2rem;"><canvas id="engagementChart"></canvas></div>';
    
    echo '<div style="flex: 1; min-width: 300px;">';
    foreach ($categories as $catName => $count) {
        echo '<div style="margin-bottom: 1.25rem; background: var(--surface); padding: 1.25rem; border-radius: 8px; border: 1px solid var(--line);">';
        echo '<h3 style="margin-top:0; margin-bottom: 0.75rem; color: var(--ink); border-bottom: 1px solid var(--line); padding-bottom: 0.5rem; font-size: 1.1rem;">' . h($catName) . ' <span style="background:var(--panel-soft); padding:2px 8px; border-radius:12px; font-size:11px; font-weight:normal; margin-left:8px; color:var(--muted);">' . $count . ' Members</span></h3>';
        if ($count > 0) {
            echo '<div class="table-wrap"><table><thead><tr><th>Name</th><th>Email</th></tr></thead><tbody>';
            foreach ($memberLists[$catName] as $m) {
                echo '<tr>';
                echo '<td style="display:flex;align-items:center;gap:12px;">';
                if (!empty($m['profile_picture'])) {
                    echo '<img src="assets/uploads/' . h($m['profile_picture']) . '" alt="Avatar" class="avatar" style="width:32px;height:32px;">';
                } else {
                    echo '<div class="avatar avatar-initials" style="width:32px;height:32px;font-size:12px;">' . h(initials($m)) . '</div>';
                }
                echo '<strong>' . h($m['first_name'] . ' ' . $m['last_name']) . '</strong></td>';
                echo '<td style="color:var(--muted)">' . h($m['email']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<p style="color:var(--muted); font-size: 14px; margin: 0;">No members in this category.</p>';
        }
        echo '</div>';
    }
    echo '</div>';
    
    echo '</div>'; // closes the flex layout
    echo '</div>'; // closes the engagement-tab
    
    // Revenue Tab
    echo '<div id="revenue-tab" class="tab-content" style="display: none;">';
    echo '<div style="display:flex; justify-content:space-between; align-items:flex-start;">';
    echo '<h2>Revenue</h2>';
    echo '<div>
            <a href="index.php?page=reports&type=revenue&timeframe=monthly&export=csv" id="btn-export-revenue-csv" class="btn-sm btn-ghost">Export CSV</a>
            <a href="index.php?page=reports&type=revenue&timeframe=monthly&export=print" id="btn-export-revenue-print" target="_blank" class="btn-sm btn-ghost">Print / PDF</a>
          </div>';
    echo '</div>';
    echo '<div style="margin-bottom: 1rem; display: flex; gap: 0.5rem;">
            <button class="btn-sm btn-secondary tf-btn-revenue" onclick="setTimeframe(\'revenue\', \'daily\')" id="tf-revenue-daily">Daily</button>
            <button class="btn-sm btn-primary tf-btn-revenue" onclick="setTimeframe(\'revenue\', \'monthly\')" id="tf-revenue-monthly">Monthly</button>
            <button class="btn-sm btn-secondary tf-btn-revenue" onclick="setTimeframe(\'revenue\', \'yearly\')" id="tf-revenue-yearly">Yearly</button>
          </div>';
    echo '<div style="display: flex; flex-wrap: wrap; gap: 4rem; align-items: flex-start; margin-top: 1.5rem;">';
    echo '<div style="flex: 1.5; min-width: 300px; margin-bottom: 2rem;"><canvas id="revenueChart"></canvas></div>';
    echo '<div style="flex: 1; min-width: 300px; max-height: 400px; overflow-y: auto; padding-right: 8px;">';
    echo '<div id="revenue-table-daily" style="display:none;">' . render_simple_table($revenue['daily'], ['day', 'revenue']) . '</div>';
    echo '<div id="revenue-table-monthly" style="display:block;">' . render_simple_table($revenue['monthly'], ['month', 'revenue']) . '</div>';
    echo '<div id="revenue-table-yearly" style="display:none;">' . render_simple_table($revenue['yearly'], ['year', 'revenue']) . '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    // Attendance Tab
    echo '<div id="attendance-tab" class="tab-content" style="display: none;">';
    echo '<div style="display:flex; justify-content:space-between; align-items:flex-start;">';
    echo '<h2>Attendance</h2>';
    echo '<div>
            <a href="index.php?page=reports&type=attendance&timeframe=daily&export=csv" id="btn-export-attendance-csv" class="btn-sm btn-ghost">Export CSV</a>
            <a href="index.php?page=reports&type=attendance&timeframe=daily&export=print" id="btn-export-attendance-print" target="_blank" class="btn-sm btn-ghost">Print / PDF</a>
          </div>';
    echo '</div>';
    echo '<div style="margin-bottom: 1rem; display: flex; gap: 0.5rem;">
            <button class="btn-sm btn-primary tf-btn-attendance" onclick="setTimeframe(\'attendance\', \'daily\')" id="tf-attendance-daily">Daily</button>
            <button class="btn-sm btn-secondary tf-btn-attendance" onclick="setTimeframe(\'attendance\', \'monthly\')" id="tf-attendance-monthly">Monthly</button>
            <button class="btn-sm btn-secondary tf-btn-attendance" onclick="setTimeframe(\'attendance\', \'yearly\')" id="tf-attendance-yearly">Yearly</button>
          </div>';
    echo '<div style="display: flex; flex-wrap: wrap; gap: 4rem; align-items: flex-start; margin-top: 1.5rem;">';
    echo '<div style="flex: 1.5; min-width: 300px; margin-bottom: 2rem;"><canvas id="attendanceChart"></canvas></div>';
    echo '<div style="flex: 1; min-width: 300px; max-height: 400px; overflow-y: auto; padding-right: 8px;">';
    echo '<div id="attendance-table-daily" style="display:block;">' . render_simple_table($attendance['daily'], ['day', 'visits']) . '</div>';
    echo '<div id="attendance-table-monthly" style="display:none;">' . render_simple_table($attendance['monthly'], ['month', 'visits']) . '</div>';
    echo '<div id="attendance-table-yearly" style="display:none;">' . render_simple_table($attendance['yearly'], ['year', 'visits']) . '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    echo <<<HTML
    <script>
    window.showTab = function(tabId) {
        document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
        document.querySelectorAll('.tab-btn').forEach(el => {
            el.classList.remove('active');
            el.style.backgroundColor = 'transparent';
            el.style.color = '#64748b';
            el.style.border = '1px solid transparent';
        });
        
        document.getElementById(tabId).style.display = 'block';
        
        const btn = document.querySelector(`button[onclick="showTab('\${tabId}')"]`);
        if (btn) {
            btn.classList.add('active');
            btn.style.backgroundColor = '#3b82f6';
            btn.style.color = 'white';
            btn.style.border = 'none';
            btn.style.borderRadius = '4px';
        }
    };
    
    const chartsData = {$chartsDataJson};
    let revenueChartInstance = null;
    let attendanceChartInstance = null;

    window.setTimeframe = function(type, timeframe) {
        document.querySelectorAll('.tf-btn-' + type).forEach(el => {
            el.classList.remove('btn-primary');
            el.classList.add('btn-secondary');
        });
        const btn = document.getElementById('tf-' + type + '-' + timeframe);
        if (btn) {
            btn.classList.remove('btn-secondary');
            btn.classList.add('btn-primary');
        }

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
            new Chart(document.getElementById('engagementChart'), {
                type: 'doughnut',
                data: {
                    labels: $engagementLabels,
                    datasets: [{
                        data: $engagementJson,
                        backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                        borderWidth: 0
                    }]
                },
                options: {
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true
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
                        backgroundColor: '#3b82f6',
                        borderRadius: 4
                    }]
                },
                options: { scales: { y: { beginAtZero: true } }, plugins: { legend: { display: false } } }
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
                        tension: 0.3
                    }]
                },
                options: { scales: { y: { beginAtZero: true } }, plugins: { legend: { display: false } } }
            });
        }
    });
    </script>
    HTML;

    echo '</section>';
    render_footer();
}
