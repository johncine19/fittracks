<?php
declare(strict_types=1);

function reports_page(): void
{
    $user = require_roles(['admin']);
    $revenue = query_all('SELECT DATE_FORMAT(payment_date, "%Y-%m") AS month, SUM(amount) AS revenue FROM payments WHERE status = "paid" GROUP BY month ORDER BY month DESC LIMIT 12');
    $attendance = query_all('SELECT DATE(check_in_time) AS day, COUNT(*) AS visits FROM attendance GROUP BY day ORDER BY day DESC LIMIT 14');
    
    // Engagement Analytics
    $members = query_all('SELECT user_id FROM users WHERE role = "member" AND status = "active"');
    $categories = ['Highly Engaged' => 0, 'Moderately Engaged' => 0, 'At-Risk' => 0];
    foreach ($members as $m) {
        $score = calculate_engagement_score((int) $m['user_id']);
        $cat = get_engagement_category($score);
        $categories[$cat]++;
    }
    
    $engagementData = [
        ['category' => 'Highly Engaged', 'count' => $categories['Highly Engaged']],
        ['category' => 'Moderately Engaged', 'count' => $categories['Moderately Engaged']],
        ['category' => 'At-Risk', 'count' => $categories['At-Risk']],
    ];

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

    echo '<div id="engagement-tab" class="tab-content">';
    echo '<h2>Member Engagement Analytics</h2>';
    echo '<div style="max-width: 400px; margin-bottom: 2rem;"><canvas id="engagementChart"></canvas></div>';
    echo render_simple_table($engagementData, ['category', 'count']);
    echo '</div>';
    
    echo '<div id="revenue-tab" class="tab-content" style="display: none;">';
    echo '<h2>Revenue</h2>';
    echo '<div style="max-width: 800px; margin-bottom: 2rem;"><canvas id="revenueChart"></canvas></div>';
    echo render_simple_table($revenue, ['month', 'revenue']);
    echo '</div>';
    
    echo '<div id="attendance-tab" class="tab-content" style="display: none;">';
    echo '<h2>Attendance</h2>';
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

