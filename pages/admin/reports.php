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
    $revenue = query_all('SELECT DATE_FORMAT(payment_date, "%Y-%m") AS month, SUM(amount) AS revenue FROM payments WHERE status = "paid" GROUP BY month ORDER BY month DESC LIMIT 12');
    $attendance = query_all('SELECT DATE(check_in_time) AS day, COUNT(*) AS visits FROM attendance GROUP BY day ORDER BY day DESC LIMIT 14');
    
    // Engagement Analytics
    $members = query_all('SELECT user_id FROM users WHERE role = "member" AND status = "active"');
    $categories = ['Highly Engaged' => 0, 'Moderately Engaged' => 0, 'At-Risk' => 0];
    foreach ($members as $m) {
        $score = get_cached_engagement_score((int) $m['user_id']);
        $cat = get_engagement_category($score);
        $categories[$cat]++;
    }
    
    $engagementData = [
        ['category' => 'Highly Engaged', 'count' => $categories['Highly Engaged']],
        ['category' => 'Moderately Engaged', 'count' => $categories['Moderately Engaged']],
        ['category' => 'At-Risk', 'count' => $categories['At-Risk']],
    ];

    // Handle exports
    if (isset($_GET['export']) && isset($_GET['type'])) {
        $format = $_GET['export'];
        $type = $_GET['type'];
        if ($type === 'engagement') handle_export($type, $format, $engagementData);
        if ($type === 'revenue') handle_export($type, $format, $revenue);
        if ($type === 'attendance') handle_export($type, $format, $attendance);
    }

    $engagementJson = json_encode(array_column($engagementData, 'count'));
    $engagementLabels = json_encode(array_column($engagementData, 'category'));
    
    $revReversed = array_reverse($revenue);
    $revenueJson = json_encode(array_map(fn($r) => (float)$r['revenue'], $revReversed));
    $revenueLabels = json_encode(array_column($revReversed, 'month'));

    $attReversed = array_reverse($attendance);
    $attendanceJson = json_encode(array_map(fn($a) => (int)$a['visits'], $attReversed));
    $attendanceLabels = json_encode(array_column($attReversed, 'day'));

    render_header('Reports', $user);
    echo '<section class="panel"><h1>Reports</h1>';
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
    echo '<div style="max-width: 400px; margin-bottom: 2rem;"><canvas id="engagementChart"></canvas></div>';
    echo render_simple_table($engagementData, ['category', 'count']);
    echo '</div>';
    
    // Revenue Tab
    echo '<div id="revenue-tab" class="tab-content" style="display: none;">';
    echo '<div style="display:flex; justify-content:space-between; align-items:flex-start;">';
    echo '<h2>Revenue</h2>';
    echo '<div>
            <a href="index.php?page=reports&type=revenue&export=csv" class="btn-sm btn-ghost">Export CSV</a>
            <a href="index.php?page=reports&type=revenue&export=print" target="_blank" class="btn-sm btn-ghost">Print / PDF</a>
          </div>';
    echo '</div>';
    echo '<div style="max-width: 800px; margin-bottom: 2rem;"><canvas id="revenueChart"></canvas></div>';
    echo render_simple_table($revenue, ['month', 'revenue']);
    echo '</div>';
    
    // Attendance Tab
    echo '<div id="attendance-tab" class="tab-content" style="display: none;">';
    echo '<div style="display:flex; justify-content:space-between; align-items:flex-start;">';
    echo '<h2>Attendance</h2>';
    echo '<div>
            <a href="index.php?page=reports&type=attendance&export=csv" class="btn-sm btn-ghost">Export CSV</a>
            <a href="index.php?page=reports&type=attendance&export=print" target="_blank" class="btn-sm btn-ghost">Print / PDF</a>
          </div>';
    echo '</div>';
    echo '<div style="max-width: 800px; margin-bottom: 2rem;"><canvas id="attendanceChart"></canvas></div>';
    echo render_simple_table($attendance, ['day', 'visits']);
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
                }
            });

            new Chart(document.getElementById('revenueChart'), {
                type: 'bar',
                data: {
                    labels: $revenueLabels,
                    datasets: [{
                        label: 'Revenue',
                        data: $revenueJson,
                        backgroundColor: '#3b82f6',
                        borderRadius: 4
                    }]
                },
                options: { scales: { y: { beginAtZero: true } }, plugins: { legend: { display: false } } }
            });

            new Chart(document.getElementById('attendanceChart'), {
                type: 'line',
                data: {
                    labels: $attendanceLabels,
                    datasets: [{
                        label: 'Visits',
                        data: $attendanceJson,
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
