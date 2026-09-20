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
            <title><?= h(ucfirst(str_replace('_', ' ', $type))) ?> Report</title>
            <style>
                @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
                body { font-family: 'Inter', sans-serif; padding: 40px; color: #111827; background: #fff; }
                table { border-collapse: collapse; width: 100%; margin-top: 20px; font-size: 13px; }
                th, td { border: 1px solid #e5e7eb; padding: 12px 16px; text-align: left; }
                th { background: #f9fafb; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.05em; font-size: 12px; }
                h2 { color: #111827; margin-bottom: 5px; font-size: 24px; }
                .report-date { color: #6b7280; font-size: 14px; margin-bottom: 30px; }
                button { background: #111827; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 500; margin-right: 10px; }
                button.secondary { background: #f3f4f6; color: #374151; }
                @media print {
                    .no-print { display: none !important; }
                    body { padding: 0; }
                    th { background: #f9fafb !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                }
            </style>
        </head>
        <body>
            <div class="no-print" style="margin-bottom: 30px; border-bottom: 1px solid #e5e7eb; padding-bottom: 20px;">
                <button onclick="window.print()">Print / Save as PDF</button>
                <button onclick="window.close()" class="secondary">Close</button>
            </div>
            <h2><?= h(ucfirst(str_replace('_', ' ', $type))) ?> Report</h2>
            <div class="report-date">Generated on <?= h(date('F j, Y g:i A')) ?></div>
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
    if ($user['role'] === 'gym_owner') {
        require_gym_feature('reports');
    }
    $pdo = db();
    $isPlatformAdmin = $user['role'] === 'platform_admin';
    
    $gymId = null;
    $currentGymName = 'FitTrack Platform';
    if (!$isPlatformAdmin) {
        $gymId = (int) scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
        if ($gymId > 0) {
            $currentGymName = (string) scalar('SELECT name FROM gyms WHERE gym_id = ?', [$gymId]) ?: 'FitTrack Gym';
        }
    }

    $selectedGymId = 'all';
    $approvedGyms = [];
    if ($isPlatformAdmin) {
        $approvedGyms = query_all("SELECT gym_id, name FROM gyms WHERE status = 'approved' ORDER BY name");
        if (isset($_GET['gym_id']) && $_GET['gym_id'] !== 'all') {
            $selectedGymId = (int)$_GET['gym_id'];
            foreach ($approvedGyms as $g) {
                if ((int)$g['gym_id'] === $selectedGymId) {
                    $currentGymName = $g['name'];
                    break;
                }
            }
        }
    }

    // Build SQL condition filters
    if ($isPlatformAdmin) {
        if ($selectedGymId !== 'all') {
            $subRevCondition = 'SELECT DATE(p.payment_date) AS day, DATE_FORMAT(p.payment_date, "%Y-%m") AS month, YEAR(p.payment_date) AS year, p.amount AS revenue 
                FROM payments p
                JOIN memberships m ON m.membership_id = p.membership_id
                JOIN membership_plans mp ON mp.plan_id = m.plan_id
                WHERE p.status = "paid" AND mp.gym_id = ' . (int)$selectedGymId;
            $walkRevCondition = 'SELECT DATE(visit_date) AS day, DATE_FORMAT(visit_date, "%Y-%m") AS month, YEAR(visit_date) AS year, amount_paid AS revenue 
                FROM walk_in_transactions WHERE gym_id = ' . (int)$selectedGymId;
            $attendanceCondition = 'SELECT check_in_time, DATE(check_in_time) AS day, DATE_FORMAT(check_in_time, "%Y-%m") AS month, YEAR(check_in_time) AS year FROM attendance WHERE gym_id = ' . (int)$selectedGymId;
            $walkinCondition = 'SELECT DATE(visit_date) AS day, DATE_FORMAT(visit_date, "%Y-%m") AS month, YEAR(visit_date) AS year, amount_paid FROM walk_in_transactions WHERE gym_id = ' . (int)$selectedGymId;
            $members = query_all('SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.email, u.profile_picture 
                FROM users u 
                LEFT JOIN gym_members gm ON gm.user_id = u.user_id 
                LEFT JOIN memberships m ON m.user_id = u.user_id AND m.status = "active" 
                LEFT JOIN membership_plans mp ON mp.plan_id = m.plan_id 
                WHERE u.role = "member" AND u.status = "active" 
                  AND (gm.gym_id = ' . (int)$selectedGymId . ' OR mp.gym_id = ' . (int)$selectedGymId . ')');
            $activeMembersCount = count($members);
            $mrr = (float) scalar('SELECT COALESCE(SUM(mp.price), 0) FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.status = "active" AND mp.gym_id = ' . (int)$selectedGymId);
            $attGymFilter = 'gym_id = ' . (int)$selectedGymId;
            $trainerGymFilter = 'WHERE tp.gym_id = ' . (int)$selectedGymId;
        } else {
            $subRevCondition = 'SELECT DATE(p.payment_date) AS day, DATE_FORMAT(p.payment_date, "%Y-%m") AS month, YEAR(p.payment_date) AS year, p.amount AS revenue 
                FROM payments p WHERE p.status = "paid"';
            $walkRevCondition = 'SELECT DATE(visit_date) AS day, DATE_FORMAT(visit_date, "%Y-%m") AS month, YEAR(visit_date) AS year, amount_paid AS revenue 
                FROM walk_in_transactions';
            $attendanceCondition = 'SELECT check_in_time, DATE(check_in_time) AS day, DATE_FORMAT(check_in_time, "%Y-%m") AS month, YEAR(check_in_time) AS year FROM attendance';
            $walkinCondition = 'SELECT DATE(visit_date) AS day, DATE_FORMAT(visit_date, "%Y-%m") AS month, YEAR(visit_date) AS year, amount_paid FROM walk_in_transactions';
            $members = query_all('SELECT user_id, first_name, last_name, email, profile_picture FROM users WHERE role = "member" AND status = "active"');
            $activeMembersCount = count($members);
            $mrr = (float) scalar('SELECT COALESCE(SUM(mp.price), 0) FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.status = "active"');
            $attGymFilter = '1=1';
            $trainerGymFilter = '';
        }
    } else {
        $subRevCondition = 'SELECT DATE(p.payment_date) AS day, DATE_FORMAT(p.payment_date, "%Y-%m") AS month, YEAR(p.payment_date) AS year, p.amount AS revenue 
            FROM payments p
            JOIN memberships m ON m.membership_id = p.membership_id
            JOIN membership_plans mp ON mp.plan_id = m.plan_id
            WHERE p.status = "paid" AND mp.gym_id = ' . (int)$gymId;
        $walkRevCondition = 'SELECT DATE(visit_date) AS day, DATE_FORMAT(visit_date, "%Y-%m") AS month, YEAR(visit_date) AS year, amount_paid AS revenue 
            FROM walk_in_transactions WHERE gym_id = ' . (int)$gymId;
        $attendanceCondition = 'SELECT check_in_time, DATE(check_in_time) AS day, DATE_FORMAT(check_in_time, "%Y-%m") AS month, YEAR(check_in_time) AS year FROM attendance WHERE (gym_id = ' . (int)$gymId . ' OR (gym_id IS NULL AND recorded_by = ' . (int)$user['user_id'] . '))';
        $walkinCondition = 'SELECT DATE(visit_date) AS day, DATE_FORMAT(visit_date, "%Y-%m") AS month, YEAR(visit_date) AS year, amount_paid FROM walk_in_transactions WHERE gym_id = ' . (int)$gymId;
        $members = query_all('SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.email, u.profile_picture 
            FROM users u 
            LEFT JOIN gym_members gm ON gm.user_id = u.user_id 
            LEFT JOIN memberships m ON m.user_id = u.user_id AND m.status = "active" 
            LEFT JOIN membership_plans mp ON mp.plan_id = m.plan_id 
            WHERE u.role = "member" AND u.status = "active" 
              AND (gm.gym_id = ' . (int)$gymId . ' OR mp.gym_id = ' . (int)$gymId . ')');
        $activeMembersCount = count($members);
        $mrr = (float) scalar('SELECT COALESCE(SUM(mp.price), 0) FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.status = "active" AND mp.gym_id = ' . (int)$gymId);
        $attGymFilter = '(gym_id = ' . (int)$gymId . ' OR (gym_id IS NULL AND recorded_by = ' . (int)$user['user_id'] . '))';
        $trainerGymFilter = 'WHERE tp.gym_id = ' . (int)$gymId;
    }

    // Executive Financial KPIs (Current Month vs Previous Month)
    $curMonth = date('Y-m');
    $prevMonth = date('Y-m', strtotime('-1 month'));

    $curMonthSub = (float) scalar("SELECT COALESCE(SUM(revenue), 0) FROM ($subRevCondition) AS s WHERE s.month = ?", [$curMonth]);
    $curMonthWalk = (float) scalar("SELECT COALESCE(SUM(revenue), 0) FROM ($walkRevCondition) AS w WHERE w.month = ?", [$curMonth]);
    $curMonthGross = $curMonthSub + $curMonthWalk;

    $prevMonthSub = (float) scalar("SELECT COALESCE(SUM(revenue), 0) FROM ($subRevCondition) AS s WHERE s.month = ?", [$prevMonth]);
    $prevMonthWalk = (float) scalar("SELECT COALESCE(SUM(revenue), 0) FROM ($walkRevCondition) AS w WHERE w.month = ?", [$prevMonth]);
    $prevMonthGross = $prevMonthSub + $prevMonthWalk;

    $hasRevenueHistory = ($prevMonthGross > 0 || $curMonthGross > 0);
    $momGrowth = null;
    if ($prevMonthGross > 0) {
        $momGrowth = round((($curMonthGross - $prevMonthGross) / $prevMonthGross) * 100, 1);
    } elseif ($curMonthGross > 0) {
        $momGrowth = 100.0;
    }

    $walkinSharePct = $curMonthGross > 0 ? round(($curMonthWalk / $curMonthGross) * 100, 1) : 0.0;
    $subSharePct = $curMonthGross > 0 ? round(($curMonthSub / $curMonthGross) * 100, 1) : 0.0;
    $arpu = $activeMembersCount > 0 ? round($curMonthGross / $activeMembersCount, 2) : 0.0;

    // Multi-Stream Revenue Time Series
    $rawSubRevenue = [
        'daily' => query_all('SELECT day, SUM(revenue) AS revenue FROM (' . $subRevCondition . ') AS s GROUP BY day ORDER BY day DESC LIMIT 14'),
        'monthly' => query_all('SELECT month, SUM(revenue) AS revenue FROM (' . $subRevCondition . ') AS s GROUP BY month ORDER BY month DESC LIMIT 12'),
        'yearly' => query_all('SELECT year, SUM(revenue) AS revenue FROM (' . $subRevCondition . ') AS s GROUP BY year ORDER BY year DESC LIMIT 5')
    ];
    $rawWalkRevenue = [
        'daily' => query_all('SELECT day, SUM(revenue) AS revenue FROM (' . $walkRevCondition . ') AS w GROUP BY day ORDER BY day DESC LIMIT 14'),
        'monthly' => query_all('SELECT month, SUM(revenue) AS revenue FROM (' . $walkRevCondition . ') AS w GROUP BY month ORDER BY month DESC LIMIT 12'),
        'yearly' => query_all('SELECT year, SUM(revenue) AS revenue FROM (' . $walkRevCondition . ') AS w GROUP BY year ORDER BY year DESC LIMIT 5')
    ];

    // Attendance Time Series
    $attendance = [
        'daily' => query_all('SELECT day, COUNT(*) AS visits FROM (' . $attendanceCondition . ') AS a GROUP BY day ORDER BY day DESC LIMIT 14'),
        'monthly' => query_all('SELECT month, COUNT(*) AS visits FROM (' . $attendanceCondition . ') AS a GROUP BY month ORDER BY month DESC LIMIT 12'),
        'yearly' => query_all('SELECT year, COUNT(*) AS visits FROM (' . $attendanceCondition . ') AS a GROUP BY year ORDER BY year DESC LIMIT 5')
    ];

    // Walk-In Visits Time Series
    $walkin = [
        'daily' => query_all('SELECT day, COUNT(*) AS visits, COALESCE(SUM(amount_paid), 0) AS revenue FROM (' . $walkinCondition . ') AS w GROUP BY day ORDER BY day DESC LIMIT 14'),
        'monthly' => query_all('SELECT month, COUNT(*) AS visits, COALESCE(SUM(amount_paid), 0) AS revenue FROM (' . $walkinCondition . ') AS w GROUP BY month ORDER BY month DESC LIMIT 12'),
        'yearly' => query_all('SELECT year, COUNT(*) AS visits, COALESCE(SUM(amount_paid), 0) AS revenue FROM (' . $walkinCondition . ') AS w GROUP BY year ORDER BY year DESC LIMIT 5')
    ];

    // Hourly Attendance Peak Analysis (5 AM - 11 PM)
    $hoursMap = [];
    for ($h = 5; $h <= 23; $h++) {
        $timeLabel = date('g A', strtotime("$h:00"));
        $hoursMap[$h] = ['hour' => $h, 'label' => $timeLabel, 'count' => 0];
    }
    $hourlyRows = query_all("SELECT HOUR(check_in_time) as hr, COUNT(*) as cnt FROM attendance WHERE $attGymFilter GROUP BY HOUR(check_in_time)");
    $peakHour = null;
    $peakHourCount = 0;
    $totalHourlyVisits = 0;
    foreach ($hourlyRows as $hrRow) {
        $h = (int)$hrRow['hr'];
        $c = (int)$hrRow['cnt'];
        $totalHourlyVisits += $c;
        if (isset($hoursMap[$h])) {
            $hoursMap[$h]['count'] = $c;
        }
        if ($c > $peakHourCount) {
            $peakHourCount = $c;
            $peakHour = $h;
        }
    }

    // Day of Week Attendance Distribution (Mon - Sun)
    $dayOrder = [
        2 => 'Mon',
        3 => 'Tue',
        4 => 'Wed',
        5 => 'Thu',
        6 => 'Fri',
        7 => 'Sat',
        1 => 'Sun'
    ];
    $dowMap = [];
    foreach ($dayOrder as $num => $name) {
        $dowMap[$num] = ['day' => $name, 'count' => 0];
    }
    $dowRows = query_all("SELECT DAYOFWEEK(check_in_time) as dow, COUNT(*) as cnt FROM attendance WHERE $attGymFilter GROUP BY DAYOFWEEK(check_in_time)");
    $busiestDayName = null;
    $busiestDayCount = 0;
    foreach ($dowRows as $dRow) {
        $num = (int)$dRow['dow'];
        $c = (int)$dRow['cnt'];
        if (isset($dowMap[$num])) {
            $dowMap[$num]['count'] = $c;
            if ($c > $busiestDayCount) {
                $busiestDayCount = $c;
                $busiestDayName = $dowMap[$num]['day'];
            }
        }
    }

    // Trainer Performance & Commission Leaderboard (Pro / Business feature)
    $canViewTrainers = $isPlatformAdmin || gym_has_feature('trainer_commissions');
    $trainersList = [];
    $trainerStats = ['total_trainers' => 0, 'total_active_clients' => 0, 'total_paid_commissions' => 0.0];
    if ($canViewTrainers) {
        $trainersList = query_all("
            SELECT tp.trainer_id, tp.specialization, tp.bio,
                   u.user_id, u.first_name, u.last_name, u.email, u.profile_picture,
                   (SELECT COUNT(*) FROM trainer_assignments ta WHERE ta.trainer_id = tp.trainer_id AND ta.status = 'active') as active_clients,
                   (SELECT COALESCE(SUM(tc.amount), 0) FROM trainer_commissions tc WHERE tc.trainer_id = tp.trainer_id AND tc.status = 'paid') as total_commissions
            FROM trainer_profiles tp
            JOIN users u ON u.user_id = tp.user_id
            $trainerGymFilter
            ORDER BY total_commissions DESC, active_clients DESC
        ");
        $trainerStats['total_trainers'] = count($trainersList);
        foreach ($trainersList as $t) {
            $trainerStats['total_active_clients'] += (int)$t['active_clients'];
            $trainerStats['total_paid_commissions'] += (float)$t['total_commissions'];
        }
    }

    // Engagement Analytics
    $categories = ['Highly Engaged' => 0, 'Moderately Engaged' => 0, 'At-Risk' => 0];
    $memberLists = ['Highly Engaged' => [], 'Moderately Engaged' => [], 'At-Risk' => []];
    foreach ($members as $m) {
        $score = get_cached_engagement_score((int) $m['user_id']);
        $cat = get_engagement_category($score);
        $categories[$cat]++;
        $m['score'] = $score;
        $memberLists[$cat][] = $m;
    }
    
    $engagementData = [
        ['category' => 'Highly Engaged', 'count' => $categories['Highly Engaged']],
        ['category' => 'Moderately Engaged', 'count' => $categories['Moderately Engaged']],
        ['category' => 'At-Risk', 'count' => $categories['At-Risk']],
    ];

    // Pad time series data helper
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
                $valKey => (float)($dataMap[$dateStr] ?? 0)
            ];
            $current->modify($modifier);
        }
        return $padded;
    };

    // Construct multi-stream revenue tables
    $revenue = ['daily' => [], 'monthly' => [], 'yearly' => []];
    $revenueTotals = ['daily' => ['subs' => 0.0, 'walk' => 0.0, 'total' => 0.0, 'max' => 0.0], 'monthly' => ['subs' => 0.0, 'walk' => 0.0, 'total' => 0.0, 'max' => 0.0], 'yearly' => ['subs' => 0.0, 'walk' => 0.0, 'total' => 0.0, 'max' => 0.0]];
    $attendanceTotals = ['daily' => ['visits' => 0, 'max' => 0], 'monthly' => ['visits' => 0, 'max' => 0], 'yearly' => ['visits' => 0, 'max' => 0]];
    $walkinTotals = ['daily' => ['visits' => 0, 'revenue' => 0.0, 'max' => 0], 'monthly' => ['visits' => 0, 'revenue' => 0.0, 'max' => 0], 'yearly' => ['visits' => 0, 'revenue' => 0.0, 'max' => 0]];

    foreach (['daily', 'monthly', 'yearly'] as $tf) {
        $key = ($tf === 'daily') ? 'day' : (($tf === 'monthly') ? 'month' : 'year');
        $paddedSub = $pad_time_series($rawSubRevenue[$tf], $key, 'revenue', $tf);
        $paddedWalk = $pad_time_series($rawWalkRevenue[$tf], $key, 'revenue', $tf);

        $attendance[$tf] = $pad_time_series($attendance[$tf], $key, 'visits', $tf);
        $walkin[$tf] = $pad_time_series($walkin[$tf], $key, 'visits', $tf);

        $subMap = [];
        foreach ($paddedSub as $r) { $subMap[$r[$key]] = (float)$r['revenue']; }
        $walkMap = [];
        foreach ($paddedWalk as $r) { $walkMap[$r[$key]] = (float)$r['revenue']; }

        foreach ($paddedSub as $r) {
            $d = $r[$key];
            $s = (float)($subMap[$d] ?? 0);
            $w = (float)($walkMap[$d] ?? 0);
            $t = $s + $w;
            
            $formattedLabel = $d;
            if ($tf === 'daily') $formattedLabel = date('m/d/y', strtotime($d));
            elseif ($tf === 'monthly') $formattedLabel = date('M Y', strtotime($d . '-01'));

            $revenue[$tf][] = [
                'raw_date' => $d,
                $key => $formattedLabel,
                'subscriptions' => $s,
                'walkins' => $w,
                'revenue' => $t
            ];

            $revenueTotals[$tf]['subs'] += $s;
            $revenueTotals[$tf]['walk'] += $w;
            $revenueTotals[$tf]['total'] += $t;
            if ($t > $revenueTotals[$tf]['max']) {
                $revenueTotals[$tf]['max'] = $t;
            }
        }

        foreach ($attendance[$tf] as &$row) {
            $v = (int)$row['visits'];
            $attendanceTotals[$tf]['visits'] += $v;
            if ($v > $attendanceTotals[$tf]['max']) $attendanceTotals[$tf]['max'] = $v;

            if ($tf === 'daily') $row[$key] = date('m/d/y', strtotime($row[$key]));
            elseif ($tf === 'monthly') $row[$key] = date('M Y', strtotime($row[$key] . '-01'));
        }
        unset($row);

        foreach ($walkin[$tf] as &$row) {
            $v = (int)$row['visits'];
            $walkinTotals[$tf]['visits'] += $v;
            if ($v > $walkinTotals[$tf]['max']) $walkinTotals[$tf]['max'] = $v;

            if ($tf === 'daily') $row[$key] = date('m/d/y', strtotime($row[$key]));
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
        if ($type === 'revenue') {
            $exportRows = [];
            $key = ($timeframe === 'daily') ? 'day' : (($timeframe === 'monthly') ? 'month' : 'year');
            foreach ($revenue[$timeframe] ?? $revenue['monthly'] as $row) {
                $exportRows[] = [
                    'Period' => $row[$key],
                    'Subscriptions (PHP)' => number_format((float)$row['subscriptions'], 2, '.', ''),
                    'Walk-Ins (PHP)' => number_format((float)$row['walkins'], 2, '.', ''),
                    'Total Revenue (PHP)' => number_format((float)$row['revenue'], 2, '.', ''),
                ];
            }
            handle_export($type . '_' . $timeframe, $format, $exportRows);
        }
        if ($type === 'attendance') handle_export($type . '_' . $timeframe, $format, $attendance[$timeframe] ?? $attendance['daily']);
        if ($type === 'walkin') handle_export($type . '_' . $timeframe, $format, $walkin[$timeframe] ?? $walkin['daily']);
        if ($type === 'trainers' && $canViewTrainers) {
            $exportRows = [];
            foreach ($trainersList as $t) {
                $exportRows[] = [
                    'Trainer' => $t['first_name'] . ' ' . $t['last_name'],
                    'Email' => $t['email'],
                    'Specialization' => $t['specialization'] ?: 'General',
                    'Active Trainees' => (int)$t['active_clients'],
                    'Paid Commissions (PHP)' => number_format((float)$t['total_commissions'], 2, '.', '')
                ];
            }
            handle_export('trainers_commissions', $format, $exportRows);
        }
    }

    // Prepare JSON for Chart.js
    $engagementJson = json_encode(array_column($engagementData, 'count'));
    $engagementLabels = json_encode(array_column($engagementData, 'category'));
    
    $chartsData = [
        'revenue' => [],
        'attendance' => [],
        'walkin' => [],
        'hourly' => [
            'labels' => array_values(array_column($hoursMap, 'label')),
            'data' => array_values(array_column($hoursMap, 'count')),
            'total' => $totalHourlyVisits
        ],
        'day_of_week' => [
            'labels' => array_values(array_column($dowMap, 'day')),
            'data' => array_values(array_column($dowMap, 'count')),
            'total' => array_sum(array_column($dowMap, 'count'))
        ]
    ];

    foreach (['daily', 'monthly', 'yearly'] as $tf) {
        $key = ($tf === 'daily') ? 'day' : (($tf === 'monthly') ? 'month' : 'year');
        $rRev = array_reverse($revenue[$tf]);
        $subSum = array_sum(array_column($rRev, 'subscriptions'));
        $walkSum = array_sum(array_column($rRev, 'walkins'));

        $chartsData['revenue'][$tf] = [
            'labels' => array_column($rRev, $key),
            'subscriptions' => array_map(fn($r) => (float)$r['subscriptions'], $rRev),
            'walkins' => array_map(fn($r) => (float)$r['walkins'], $rRev),
            'total' => array_map(fn($r) => (float)$r['revenue'], $rRev),
            'subTotal' => (float)$subSum,
            'walkTotal' => (float)$walkSum
        ];
        
        $aRev = array_reverse($attendance[$tf]);
        $chartsData['attendance'][$tf] = [
            'labels' => array_column($aRev, $key),
            'data' => array_map(fn($a) => (int)$a['visits'], $aRev),
            'total' => array_sum(array_column($aRev, 'visits'))
        ];
        
        $wRev = array_reverse($walkin[$tf]);
        $chartsData['walkin'][$tf] = [
            'labels' => array_column($wRev, $key),
            'data' => array_map(fn($a) => (int)$a['visits'], $wRev),
            'total' => array_sum(array_column($wRev, 'visits'))
        ];
    }
    $chartsDataJson = json_encode($chartsData);

    render_header('Reports & Analytics', $user);
    ?>
    <style>
        .tab-content {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }
        .report-panel,
        .panel {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }
        .dash-grid {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }
        .dash-grid > * {
            min-width: 0 !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }
        .report-card-box {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 22px;
            position: relative;
            box-sizing: border-box !important;
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
        }
        .chart-canvas {
            position: relative !important;
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            box-sizing: border-box !important;
            overflow: hidden !important;
        }
        .chart-canvas canvas {
            max-width: 100% !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }
        .chart-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .report-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 24px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 8px;
            scrollbar-width: thin;
            scrollbar-color: rgba(255,255,255,0.12) transparent;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }
        .report-tabs::-webkit-scrollbar {
            height: 4px;
        }
        .report-tabs::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.15);
            border-radius: 4px;
        }
        .report-tab-btn {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--line);
            color: var(--muted);
            padding: 10px 20px;
            border-radius: 999px;
            font-size: 13.5px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            outline: none;
            user-select: none;
        }
        .report-tab-btn:hover {
            background: rgba(255, 255, 255, 0.09);
            color: var(--ink);
            transform: translateY(-1px);
        }
        .report-tab-btn.active {
            background: var(--lime);
            color: #0b110e;
            border-color: var(--lime);
            font-weight: 700;
            box-shadow: 0 4px 16px rgba(132, 204, 22, 0.32);
            transform: translateY(-1px);
        }
        .tf-group {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }
        .tf-controls {
            display: flex;
            gap: 6px;
            background: var(--surface);
            padding: 5px;
            border-radius: 8px;
            border: 1px solid var(--line);
        }
        .tf-btn {
            background: transparent;
            border: 1px solid transparent;
            color: var(--muted);
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.18s ease;
            font-weight: 500;
        }
        .tf-btn:hover {
            color: var(--ink);
            background: rgba(255, 255, 255, 0.05);
        }
        .tf-btn.active {
            background: var(--panel-soft);
            color: var(--ink);
            border-color: rgba(255,255,255,0.15);
            font-weight: 700;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }
        .tf-hint {
            font-size: 12.5px;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }
        .kpi-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 22px;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), border-color 0.2s, box-shadow 0.2s;
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            border-color: rgba(255, 255, 255, 0.18);
            box-shadow: 0 6px 20px rgba(0,0,0,0.25);
        }
        .kpi-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            gap: 10px;
        }
        .kpi-title {
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            min-width: 0;
        }
        .kpi-value {
            font-size: 27px;
            font-weight: 800;
            color: var(--ink);
            margin-bottom: 6px;
            letter-spacing: -0.02em;
        }
        .kpi-sub {
            font-size: 12.5px;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .pill-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            white-space: nowrap;
            flex-shrink: 0;
            line-height: 1.25;
            letter-spacing: 0.02em;
        }
        .pill-badge.green {
            background: rgba(132, 204, 22, 0.15);
            color: #84cc16;
            border: 1px solid rgba(132, 204, 22, 0.3);
        }
        .pill-badge.red {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        .pill-badge.blue {
            background: rgba(14, 165, 233, 0.15);
            color: #38bdf8;
            border: 1px solid rgba(14, 165, 233, 0.3);
        }
        .pill-badge.purple {
            background: rgba(168, 85, 247, 0.15);
            color: #c084fc;
            border: 1px solid rgba(168, 85, 247, 0.3);
        }
        .pill-badge.neutral {
            background: rgba(255, 255, 255, 0.06);
            color: var(--muted);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }
        .split-bar-wrap {
            width: 100%;
            height: 7px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 999px;
            overflow: hidden;
            display: flex;
            margin: 10px 0 8px 0;
        }
        .split-bar-sub {
            background: #84cc16;
            height: 100%;
            transition: width 0.35s ease;
        }
        .split-bar-walk {
            background: #0ea5e9;
            height: 100%;
            transition: width 0.35s ease;
        }
        .export-btn {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--line);
            color: var(--ink);
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none !important;
            white-space: nowrap;
            flex-shrink: 0;
            transition: all 0.18s ease;
        }
        .export-btn:hover {
            background: rgba(255, 255, 255, 0.09);
            border-color: rgba(255, 255, 255, 0.25);
            color: #fff;
            transform: translateY(-1px);
            text-decoration: none !important;
        }
        .btn-commissions-action {
            background: rgba(132, 204, 22, 0.12);
            border: 1px solid rgba(132, 204, 22, 0.4);
            color: #a3e635 !important;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none !important;
            white-space: nowrap;
            flex-shrink: 0;
            transition: all 0.18s ease;
        }
        .btn-commissions-action:hover {
            background: rgba(132, 204, 22, 0.22);
            border-color: var(--lime);
            color: #bef264 !important;
            transform: translateY(-1px);
            text-decoration: none !important;
        }
        .trainer-leaderboard-table {
            width: 100%;
            min-width: 680px;
            margin: 0;
            border-collapse: collapse;
        }
        .trainer-leaderboard-table th,
        .trainer-leaderboard-table td {
            white-space: nowrap;
        }
        .rich-table {
            width: 100% !important;
            min-width: 0 !important;
            margin: 0;
            border-collapse: collapse;
            font-size: 13px;
        }
        .report-dash-grid .table-wrap table,
        .report-panel .table-wrap table {
            min-width: 0 !important;
        }
        .compact-table-wrap {
            overflow-x: hidden !important;
        }
        .compact-side-table {
            width: 100% !important;
            min-width: 100% !important;
            table-layout: auto !important;
        }
        .compact-side-table th,
        .compact-side-table td {
            padding: 10px 14px !important;
            box-sizing: border-box;
        }
        .compact-side-table th:first-child:not(:last-child),
        .compact-side-table td:first-child:not(:last-child) {
            white-space: nowrap !important;
            width: auto !important;
        }
        .compact-side-table th:last-child:not(:first-child),
        .compact-side-table td:last-child:not(:first-child) {
            text-align: right !important;
            white-space: nowrap !important;
            width: 1% !important;
        }
        .compact-side-table td[colspan],
        .rich-table td[colspan] {
            text-align: center !important;
            white-space: normal !important;
            width: 100% !important;
        }
        .col-att-val {
            color: #c084fc;
        }
        [data-theme="light"] .col-att-val {
            color: #7c3aed !important;
        }
        .col-walk-val {
            color: #38bdf8;
        }
        [data-theme="light"] .col-walk-val {
            color: #0284c7 !important;
        }
        .rich-table thead th {
            position: sticky;
            top: 0;
            background: #151a21;
            padding: 11px 14px;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
            border-bottom: 1px solid var(--line);
            z-index: 2;
        }
        .rich-table tbody tr {
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            transition: background 0.15s ease;
        }
        .rich-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.04);
        }
        .rich-table tbody td {
            padding: 10px 14px;
        }
        .rich-table tfoot td {
            position: sticky;
            bottom: 0;
            background: #151a21;
            padding: 11px 14px;
            font-weight: 700;
            border-top: 1px solid var(--line);
            color: var(--ink);
            z-index: 2;
        }
        .peak-tag {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
            font-size: 10px;
            font-weight: 800;
            padding: 1px 5px;
            border-radius: 4px;
            margin-left: 6px;
            display: inline-block;
        }
        /* Intentional Empty State Card */
        .empty-state-box {
            text-align: center;
            padding: 48px 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 280px;
        }
        .empty-icon-circle {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            background: rgba(132, 204, 22, 0.08);
            border: 1px dashed rgba(132, 204, 22, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--lime);
            margin-bottom: 16px;
        }
        .empty-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--ink);
            margin: 0 0 6px 0;
        }
        .empty-desc {
            font-size: 13.5px;
            color: var(--muted);
            max-width: 440px;
            line-height: 1.5;
            margin: 0 0 20px 0;
        }
        .empty-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .empty-actions .btn-primary {
            background: var(--lime);
            color: #0b110e;
            font-weight: 700;
            font-size: 13px;
            padding: 9px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.18s;
        }
        .empty-actions .btn-primary:hover {
            box-shadow: 0 4px 14px rgba(132, 204, 22, 0.35);
            transform: translateY(-1px);
        }
        .empty-actions .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--line);
            color: var(--ink);
            font-weight: 500;
            font-size: 13px;
            padding: 9px 18px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.18s;
        }
        .empty-actions .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        select option {
            background: #1e293b;
            color: #f8fafc;
        }

        /* Responsive Reports Hero Banner */
        .reports-hero-banner {
            background: linear-gradient(135deg, rgba(132,204,22,0.12) 0%, rgba(14,165,233,0.08) 100%);
            border: 1px solid rgba(132,204,22,0.25);
            border-radius: 16px;
            padding: 24px 28px;
            margin-bottom: 24px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.18);
            backdrop-filter: blur(16px);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
        }
        .reports-hero-title {
            margin: 0;
            font-size: 24px;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 800;
            letter-spacing: -0.02em;
        }
        .reports-hero-title svg {
            width: 26px;
            height: 26px;
            flex-shrink: 0;
        }
        .reports-hero-desc {
            margin: 8px 0 0 0;
            color: var(--muted);
            font-size: 14px;
            max-width: 680px;
            line-height: 1.5;
        }
        .reports-hero-badges {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }
        .reports-hero-scope {
            background: rgba(255,255,255,0.05);
            padding: 10px 14px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Desktop vs Mobile Toggle for Trainer Leaderboard */
        .trainers-desktop-table {
            display: block;
        }
        .trainers-mobile-cards {
            display: none;
        }
        .trainer-mobile-card {
            background: color-mix(in srgb, var(--panel-soft) 45%, transparent);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: border-color 0.15s ease;
        }
        .trainer-mobile-card:hover {
            border-color: color-mix(in srgb, var(--lime) 35%, transparent);
        }
        .trainer-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding-bottom: 12px;
            border-bottom: 1px solid color-mix(in srgb, var(--line) 60%, transparent);
        }
        .trainer-card-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }
        .trainer-card-rank {
            font-weight: 800;
            font-size: 15px;
            min-width: 22px;
            line-height: 1;
            flex-shrink: 0;
        }
        .trainer-card-identity {
            min-width: 0;
            overflow: hidden;
        }
        .trainer-card-name {
            display: block;
            color: var(--ink);
            font-size: 14.5px;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .trainer-card-email {
            display: block;
            color: var(--muted);
            font-size: 12px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 2px;
        }
        .trainer-card-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            padding: 2px 0;
        }
        .trainer-card-detail-item {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .trainer-card-detail-item .detail-label {
            font-size: 10.5px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
        }
        .trainer-card-detail-item .detail-val {
            font-size: 13px;
            color: var(--ink);
            font-weight: 500;
            word-break: break-word;
        }
        .trainer-card-detail-item .commission-amount {
            color: #84cc16;
            font-weight: 700;
            font-size: 14.5px;
        }
        .trainer-card-footer {
            padding-top: 2px;
        }
        .btn-trainer-details {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 9px 14px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--line);
            border-radius: 8px;
            color: var(--ink);
            font-size: 12.5px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .btn-trainer-details:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: color-mix(in srgb, var(--lime) 40%, transparent);
            color: var(--lime);
        }
        .trainer-card-empty {
            text-align: center;
            padding: 32px 20px;
            background: rgba(255,255,255,0.02);
            border: 1px dashed var(--line);
            border-radius: 12px;
        }

        /* Member Engagement Tab Responsive Styles */
        .engagement-chart-box {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 24px;
        }
        .engagement-chart-wrap {
            position: relative;
            width: 100%;
            height: 260px;
            min-height: 260px;
            max-width: 320px;
            margin: 0 auto;
        }
        .engagement-category-card {
            margin-bottom: 20px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 12px;
            overflow: hidden;
        }
        .engagement-member-list {
            max-height: 320px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        .engagement-member-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
            transition: background 0.15s ease;
        }
        .engagement-member-item:last-child {
            border-bottom: none;
        }
        .engagement-member-item:hover {
            background: rgba(255, 255, 255, 0.02);
        }
        .engagement-member-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            flex: 1 1 auto;
        }
        .engagement-member-info {
            min-width: 0;
            overflow: hidden;
        }
        .engagement-member-name {
            color: var(--ink);
            font-size: 13.5px;
            font-weight: 600;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .engagement-member-email {
            color: var(--muted);
            font-size: 12px;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 2px;
        }
        .engagement-member-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        .btn-engagement-manage {
            padding: 4px 10px;
            font-size: 11.5px;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid var(--line);
            color: var(--ink);
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .btn-engagement-manage:hover {
            background: rgba(255, 255, 255, 0.12);
            border-color: color-mix(in srgb, var(--lime) 40%, transparent);
            color: var(--lime);
        }

        @media (max-width: 768px) {
            .reports-hero-banner {
                padding: 14px 16px;
                margin-bottom: 16px;
                border-radius: 12px;
                gap: 10px;
            }
            .reports-hero-badges {
                margin-bottom: 4px;
                gap: 6px;
            }
            .reports-hero-badges .pill-badge {
                font-size: 10px;
                padding: 2px 7px;
            }
            .reports-hero-title {
                font-size: 17px;
                gap: 8px;
                line-height: 1.25;
            }
            .reports-hero-title svg {
                width: 20px;
                height: 20px;
            }
            .reports-hero-desc {
                font-size: 12px;
                line-height: 1.35;
                margin-top: 5px;
            }
            .reports-hero-scope {
                width: 100%;
                justify-content: space-between;
                padding: 8px 12px;
            }
            .reports-hero-scope select {
                flex: 1;
                min-width: 0 !important;
                font-size: 12.5px;
            }
            .kpi-grid {
                gap: 12px;
                margin-bottom: 18px;
            }
            .kpi-card {
                padding: 14px 16px;
            }
            .kpi-value {
                font-size: 22px;
            }
            .report-tabs {
                gap: 8px;
                margin-bottom: 18px;
                padding-bottom: 6px;
            }
            .report-tab-btn {
                padding: 8px 14px;
                font-size: 12.5px;
                gap: 6px;
            }
            .report-tab-btn svg {
                width: 14px;
                height: 14px;
            }
            .btn-commissions-action,
            .export-btn {
                padding: 7px 11px;
                font-size: 12px;
                gap: 5px;
            }
            .table-wrap {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
            }
            .panel,
            .report-panel {
                padding: 14px 12px !important;
                border-radius: 12px !important;
            }
            .trainers-desktop-table {
                display: none !important;
            }
            .trainers-mobile-cards {
                display: flex !important;
                flex-direction: column;
                gap: 12px;
            }
            .dash-grid,
            .report-dash-grid,
            .report-bottom-grid {
                grid-template-columns: minmax(0, 1fr) !important;
                gap: 16px !important;
                width: 100% !important;
            }
            .dash-grid > * {
                min-width: 0 !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
            }
            .report-card-box {
                padding: 14px 12px !important;
                border-radius: 12px !important;
            }
            #revenue-chart-canvas-wrap,
            #attendance-chart-canvas-wrap {
                min-height: 250px !important;
                height: 250px !important;
            }
            .chart-header-row {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 8px !important;
            }
            .engagement-chart-col {
                position: static !important;
                width: 100% !important;
            }
            .engagement-chart-box {
                padding: 14px 12px !important;
            }
            .engagement-chart-wrap {
                min-height: 240px !important;
                height: 240px !important;
                max-width: 270px !important;
                margin: 0 auto !important;
            }
            .engagement-member-item {
                padding: 10px 14px !important;
                gap: 8px !important;
            }
            .engagement-member-profile {
                gap: 8px !important;
            }
            .engagement-member-name {
                font-size: 13px !important;
            }
            .engagement-member-email {
                font-size: 11.5px !important;
            }
            .engagement-member-meta {
                gap: 6px !important;
            }
            .btn-engagement-manage {
                padding: 3px 8px !important;
                font-size: 11px !important;
            }
        }

        @media (max-width: 520px) {
            .panel,
            .report-panel {
                padding: 10px 8px !important;
            }
            .report-card-box {
                padding: 12px 10px !important;
            }
            #revenue-chart-canvas-wrap,
            #attendance-chart-canvas-wrap {
                min-height: 220px !important;
                height: 220px !important;
            }
            .chart-canvas {
                min-height: 200px !important;
                height: 200px !important;
            }
            .rich-table th,
            .rich-table td {
                padding: 7px 6px !important;
                font-size: 11px !important;
            }
            .tf-controls {
                width: 100%;
                justify-content: space-between;
            }
            .tf-btn {
                flex: 1;
                text-align: center;
                padding: 6px 8px;
            }
        }

        /* ==================== Comprehensive Light Mode Theme Adaptations ==================== */
        [data-theme="light"] .btn-commissions-action {
            background: rgba(101, 163, 13, 0.12) !important;
            border: 1px solid rgba(101, 163, 13, 0.4) !important;
            color: #27560a !important; /* Deep, crystal-clear, high-contrast forest green */
            font-weight: 700 !important;
        }
        [data-theme="light"] .btn-commissions-action:hover {
            background: rgba(101, 163, 13, 0.22) !important;
            border-color: #65a30d !important;
            color: #143403 !important;
        }
        [data-theme="light"] .table-head-row,
        [data-theme="light"] .rich-table thead th,
        [data-theme="light"] .trainer-leaderboard-table thead th {
            background: #f1f5f9 !important;
            color: #475569 !important;
            border-bottom: 1px solid #e2e8f0 !important;
        }
        [data-theme="light"] .rich-table tbody tr,
        [data-theme="light"] .trainer-leaderboard-table tbody tr {
            border-bottom: 1px solid #f1f5f9 !important;
        }
        [data-theme="light"] .rich-table tbody tr:hover,
        [data-theme="light"] .trainer-leaderboard-table tbody tr:hover {
            background: #f8fafc !important;
        }
        [data-theme="light"] .rich-table tfoot td {
            background: #f8fafc !important;
            color: #0f172a !important;
            border-top: 1px solid #e2e8f0 !important;
        }
        [data-theme="light"] .kpi-card {
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04) !important;
        }
        [data-theme="light"] .kpi-card:hover {
            border-color: #cbd5e1 !important;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08) !important;
        }
        [data-theme="light"] .export-btn {
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            color: #1e293b !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04) !important;
        }
        [data-theme="light"] .export-btn:hover {
            background: #f8fafc !important;
            border-color: #94a3b8 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] .report-tab-btn {
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            color: #475569 !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03) !important;
        }
        [data-theme="light"] .report-tab-btn:hover {
            background: #f1f5f9 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] .report-tab-btn.active {
            background: #65a30d !important;
            color: #ffffff !important;
            border-color: #65a30d !important;
            box-shadow: 0 4px 14px rgba(101, 163, 13, 0.28) !important;
        }
        [data-theme="light"] .pill-badge.green {
            background: rgba(101, 163, 13, 0.12) !important;
            color: #27560a !important;
            border: 1px solid rgba(101, 163, 13, 0.3) !important;
        }
        [data-theme="light"] .pill-badge.neutral {
            background: #f1f5f9 !important;
            color: #475569 !important;
            border: 1px solid #cbd5e1 !important;
        }
        [data-theme="light"] .pill-badge.blue {
            background: rgba(14, 165, 233, 0.1) !important;
            color: #0284c7 !important;
            border: 1px solid rgba(14, 165, 233, 0.25) !important;
        }
        [data-theme="light"] .pill-badge.purple {
            background: rgba(168, 85, 247, 0.1) !important;
            color: #7e22ce !important;
            border: 1px solid rgba(168, 85, 247, 0.25) !important;
        }
        [data-theme="light"] .pill-badge.red {
            background: rgba(239, 68, 68, 0.1) !important;
            color: #b91c1c !important;
            border: 1px solid rgba(239, 68, 68, 0.25) !important;
        }
        [data-theme="light"] .split-bar-wrap {
            background: #e2e8f0 !important;
        }
        [data-theme="light"] .reports-hero-banner {
            background: linear-gradient(135deg, rgba(101,163,13,0.08) 0%, rgba(14,165,233,0.06) 100%) #ffffff !important;
            border: 1px solid rgba(101,163,13,0.2) !important;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.05) !important;
        }
        [data-theme="light"] .reports-hero-scope {
            background: #f8fafc !important;
            border: 1px solid #e2e8f0 !important;
        }
        [data-theme="light"] .peak-tag {
            background: rgba(245, 158, 11, 0.15) !important;
            color: #b45309 !important;
            border-color: rgba(245, 158, 11, 0.4) !important;
        }
        [data-theme="light"] .empty-icon-circle {
            background: rgba(101, 163, 13, 0.1) !important;
            border-color: rgba(101, 163, 13, 0.3) !important;
            color: #4d7c0f !important;
        }
        [data-theme="light"] .trainer-mobile-card {
            background: #ffffff !important;
            border-color: #e2e8f0 !important;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05) !important;
        }
        [data-theme="light"] .trainer-card-header {
            border-bottom-color: #f1f5f9 !important;
        }
        [data-theme="light"] .trainer-card-detail-item .commission-amount {
            color: #4d7c0f !important;
        }
        [data-theme="light"] .btn-trainer-details {
            background: #f8fafc !important;
            border-color: #e2e8f0 !important;
            color: #1e293b !important;
        }
        [data-theme="light"] .btn-trainer-details:hover {
            background: #f1f5f9 !important;
            border-color: #cbd5e1 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] .trainer-card-empty {
            background: #f8fafc !important;
            border-color: #e2e8f0 !important;
        }
        [data-theme="light"] .engagement-chart-box,
        [data-theme="light"] .engagement-category-card {
            background: #ffffff !important;
            border-color: #e2e8f0 !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04) !important;
        }
        [data-theme="light"] .engagement-member-item {
            border-bottom-color: #f1f5f9 !important;
        }
        [data-theme="light"] .engagement-member-item:hover {
            background: #f8fafc !important;
        }
        [data-theme="light"] .btn-engagement-manage {
            background: #f1f5f9 !important;
            border-color: #cbd5e1 !important;
            color: #1e293b !important;
        }
        [data-theme="light"] .btn-engagement-manage:hover {
            background: #e2e8f0 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] .report-card-box {
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.05) !important;
        }
        [data-theme="light"] .report-panel {
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04) !important;
        }
        [data-theme="light"] .tf-hint {
            color: #475569 !important;
        }
        [data-theme="light"] .tf-btn {
            background: #f8fafc;
            color: #334155;
            border: 1px solid #cbd5e1;
        }
        [data-theme="light"] .tf-btn.active {
            background: #0f172a !important;
            color: #ffffff !important;
            border-color: #0f172a !important;
        }
        [data-theme="light"] .table-wrap {
            background: #ffffff !important;
            border-color: #cbd5e1 !important;
        }
        [data-theme="light"] #revenue-mix-empty-state {
            border-color: #cbd5e1 !important;
        }

        @media print {
            .trainers-mobile-cards {
                display: none !important;
            }
            .trainers-desktop-table {
                display: block !important;
            }
            .no-print, .report-tabs, .tf-group, .export-btn, nav, header, aside, .btn {
                display: none !important;
            }
            body { background: #fff !important; color: #000 !important; padding: 0 !important; }
            .panel, .kpi-card { border: 1px solid #ddd !important; box-shadow: none !important; background: #fff !important; }
            .kpi-value, h1, h2, h3 { color: #000 !important; }
            .chart-canvas { min-height: 250px !important; }
        }
    </style>

    <!-- Header / Banner -->
    <div class="animate-fade-in reports-hero-banner">
        <div>
            <div class="reports-hero-badges">
                <span class="pill-badge green" style="text-transform: uppercase; letter-spacing: 0.06em;">Professional Analytics</span>
                <span class="pill-badge neutral" style="color: var(--ink); border-color: rgba(255,255,255,0.15);"><?= h($currentGymName) ?></span>
            </div>
            <h1 class="reports-hero-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path></svg>
                <?= $isPlatformAdmin ? 'Platform Financials & Global Analytics' : 'Gym Financials & Attendance Intelligence' ?>
            </h1>
            <p class="reports-hero-desc">
                <?= $isPlatformAdmin ? 'Consolidated multi-stream revenues, check-in rush distribution, and member retention benchmarks across all partner gyms.' : 'Multi-stream revenue tracking, peak rush foot-traffic intelligence, and trainer performance rankings.' ?>
            </p>
        </div>
        
        <?php if ($isPlatformAdmin && !empty($approvedGyms)): ?>
            <div class="no-print reports-hero-scope">
                <label for="gym-filter" style="color: var(--muted); font-size: 13px; white-space: nowrap;">Gym Scope:</label>
                <select id="gym-filter" onchange="window.location.href='index.php?page=reports&gym_id='+this.value;" style="background: var(--surface); color: var(--ink); border: 1px solid var(--line); padding: 7px 12px; border-radius: 6px; font-size: 13px; min-width: 180px;">
                    <option value="all" <?= $selectedGymId === 'all' ? 'selected' : '' ?>>All Gyms (Global Platform)</option>
                    <?php foreach ($approvedGyms as $g): ?>
                        <option value="<?= $g['gym_id'] ?>" <?= $selectedGymId === (int)$g['gym_id'] ? 'selected' : '' ?>><?= h($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </div>

    <!-- Executive Financial KPI Summary Cards (Unified, Non-Arbitrary Glassmorphism) -->
    <div class="kpi-grid">
        <!-- 1. Gross Revenue -->
        <div class="kpi-card">
            <div class="kpi-card-header">
                <span class="kpi-title">Gross Revenue (This Month)</span>
                <?php if ($curMonthGross > 0 && $momGrowth !== null): ?>
                    <?php if ($momGrowth > 0): ?>
                        <span class="pill-badge green">▲ +<?= number_format($momGrowth, 1) ?>% MoM</span>
                    <?php elseif ($momGrowth < 0): ?>
                        <span class="pill-badge red">▼ <?= number_format($momGrowth, 1) ?>% MoM</span>
                    <?php else: ?>
                        <span class="pill-badge neutral">0.0% MoM</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="pill-badge neutral">No data</span>
                <?php endif; ?>
            </div>
            <div class="kpi-value">₱<?= number_format($curMonthGross, 2) ?></div>
            <div class="kpi-sub">
                <?php if ($curMonthGross > 0 && $prevMonthGross > 0): ?>
                    <span>vs. ₱<?= number_format($prevMonthGross, 2) ?> in <?= date('M Y', strtotime('-1 month')) ?></span>
                <?php else: ?>
                    <span>No transactions logged for <?= date('M Y') ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- 2. MRR (Active Subscriptions) -->
        <div class="kpi-card">
            <div class="kpi-card-header">
                <span class="kpi-title">Monthly Recurring (MRR)</span>
                <?php if ($mrr > 0): ?>
                    <span class="pill-badge green">Contracted</span>
                <?php else: ?>
                    <span class="pill-badge neutral">0 plans</span>
                <?php endif; ?>
            </div>
            <div class="kpi-value">₱<?= number_format($mrr, 2) ?></div>
            <div class="kpi-sub">
                <?php if ($mrr > 0): ?>
                    <span>Contracted active plan subscription value</span>
                <?php else: ?>
                    <span>Activate plans to build recurring subscription revenue</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- 3. Walk-In Revenue Share -->
        <div class="kpi-card">
            <div class="kpi-card-header">
                <span class="kpi-title">Walk-In vs Subscriptions</span>
                <?php if ($curMonthGross > 0): ?>
                    <span class="pill-badge blue"><?= $walkinSharePct ?>% Walk-In</span>
                <?php else: ?>
                    <span class="pill-badge neutral">No passes</span>
                <?php endif; ?>
            </div>
            <div class="kpi-value">₱<?= number_format($curMonthWalk, 2) ?></div>
            <div class="split-bar-wrap" title="Green: Subscriptions, Blue: Walk-Ins">
                <?php if ($curMonthGross > 0): ?>
                    <div class="split-bar-sub" style="width: <?= max(5, $subSharePct) ?>%;"></div>
                    <div class="split-bar-walk" style="width: <?= max(5, $walkinSharePct) ?>%;"></div>
                <?php else: ?>
                    <div style="width: 0%;"></div>
                <?php endif; ?>
            </div>
            <div class="kpi-sub" style="justify-content: space-between;">
                <?php if ($curMonthGross > 0): ?>
                    <span style="color: #84cc16;">Subs: ₱<?= number_format($curMonthSub, 0) ?></span>
                    <span style="color: #38bdf8;">Passes: ₱<?= number_format($curMonthWalk, 0) ?></span>
                <?php else: ?>
                    <span>No subscriptions or day passes recorded yet</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- 4. Average Revenue Per Member (ARPU) -->
        <div class="kpi-card">
            <div class="kpi-card-header">
                <span class="kpi-title">Avg Revenue Per Member</span>
                <?php if ($activeMembersCount > 0): ?>
                    <span class="pill-badge green"><?= $activeMembersCount ?> Active</span>
                <?php else: ?>
                    <span class="pill-badge neutral">0 members</span>
                <?php endif; ?>
            </div>
            <div class="kpi-value"><?= $activeMembersCount > 0 ? '₱' . number_format($arpu, 2) : '—' ?></div>
            <div class="kpi-sub">
                <?php if ($activeMembersCount > 0): ?>
                    <span>Average monthly yield across active members</span>
                <?php else: ?>
                    <span>Enrolled members will calculate average yield</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs with URL Hash Support -->
    <div class="report-tabs no-print" role="tablist">
        <button class="report-tab-btn active" data-tab="revenue-tab" onclick="showTab('revenue-tab')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 19v-14h3.5a4.5 4.5 0 1 1 0 9h-3.5"></path><path d="M6 8h12"></path><path d="M6 11h12"></path></svg>
            Revenue & Financials
        </button>
        <button class="report-tab-btn" data-tab="attendance-tab" onclick="showTab('attendance-tab')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            Attendance & Peak Rush
        </button>
        <button class="report-tab-btn" data-tab="walkin-tab" onclick="showTab('walkin-tab')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline></svg>
            Walk-In Traffic
        </button>
        <button class="report-tab-btn" data-tab="trainers-tab" onclick="showTab('trainers-tab')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
            Trainers & Commissions <?= (!$isPlatformAdmin && !gym_has_feature('trainer_commissions')) ? '<span style="font-size:10px; color:#f87171; font-weight:700;">🔒 Pro</span>' : '' ?>
        </button>
        <button class="report-tab-btn" data-tab="engagement-tab" onclick="showTab('engagement-tab')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
            Member Engagement <?= (!$isPlatformAdmin && !gym_has_feature('engagement_tracking')) ? '<span style="font-size:10px; color:#f87171; font-weight:700;">🔒 Pro</span>' : '' ?>
        </button>
    </div>

    <!-- ==================== TAB 1: REVENUE & FINANCIALS ==================== -->
    <div id="revenue-tab" class="tab-content animate-fade-in">
        <div class="panel report-panel" style="border-radius: 16px; padding: 26px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 22px; flex-wrap: wrap; gap: 14px;">
                <div>
                    <h2 style="margin:0 0 4px 0; font-size: 20px;">Multi-Stream Revenue Breakdown</h2>
                    <p style="margin:0; color:var(--muted); font-size:13.5px;">Stacked comparison of recurring membership subscriptions vs. one-time walk-in passes.</p>
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="index.php?page=reports&type=revenue&timeframe=monthly&export=csv" id="btn-export-revenue-csv" class="export-btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        Export CSV
                    </a>
                    <a href="index.php?page=reports&type=revenue&timeframe=monthly&export=print" id="btn-export-revenue-print" target="_blank" class="export-btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                        Print / PDF
                    </a>
                </div>
            </div>
            
            <div class="tf-group">
                <div class="tf-controls">
                    <button class="tf-btn tf-btn-revenue" onclick="setTimeframe('revenue', 'daily')" id="tf-revenue-daily">Daily</button>
                    <button class="tf-btn tf-btn-revenue active" onclick="setTimeframe('revenue', 'monthly')" id="tf-revenue-monthly">Monthly</button>
                    <button class="tf-btn tf-btn-revenue" onclick="setTimeframe('revenue', 'yearly')" id="tf-revenue-yearly">Yearly</button>
                </div>
                <span class="tf-hint" id="tf-hint-revenue">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <span id="tf-hint-text-revenue">Viewing last 12 months rolling run-rate</span>
                </span>
            </div>

            <div class="dash-grid report-dash-grid" style="grid-template-columns: 2.2fr 1.1fr; gap: 24px; align-items: start;">
                <!-- Main Stacked Bar Chart with Empty State Handler -->
                <div class="report-card-box">
                    <div class="chart-header-row">
                        <span style="font-size: 13px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em;">Stacked Financial Streams</span>
                        <div style="display: flex; gap: 12px; font-size: 12px; flex-wrap: wrap;">
                            <span style="display: inline-flex; align-items: center; gap: 6px; color: var(--ink);">
                                <span style="width: 10px; height: 10px; border-radius: 3px; background: #84cc16; flex-shrink: 0;"></span> Subscriptions
                            </span>
                            <span style="display: inline-flex; align-items: center; gap: 6px; color: var(--ink);">
                                <span style="width: 10px; height: 10px; border-radius: 3px; background: #0ea5e9; flex-shrink: 0;"></span> Walk-In Passes
                            </span>
                        </div>
                    </div>

                    <!-- Canvas Area -->
                    <div class="chart-canvas" id="revenue-chart-canvas-wrap" style="min-height: 360px; height: 360px; display: <?= $revenueTotals['monthly']['total'] > 0 ? 'block' : 'none' ?>;">
                        <canvas id="revenueChart"></canvas>
                    </div>

                    <!-- Intentional Empty State for Chart -->
                    <div id="revenue-chart-empty-state" class="empty-state-box" style="display: <?= $revenueTotals['monthly']['total'] > 0 ? 'none' : 'flex' ?>; min-height: 360px;">
                        <div class="empty-icon-circle">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 19v-14h3.5a4.5 4.5 0 1 1 0 9h-3.5"></path><path d="M6 8h12"></path><path d="M6 11h12"></path></svg>
                        </div>
                        <h3 class="empty-title">No Revenue Data in this Window</h3>
                        <p class="empty-desc">
                            Transactions from recurring membership subscriptions and one-time walk-in day passes will populate your multi-stream stacked trends automatically.
                        </p>
                        <div class="empty-actions">
                            <a href="index.php?page=walk_in" class="btn-primary">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                Log Walk-In Pass
                            </a>
                            <a href="index.php?page=membership_plans" class="btn-secondary">
                                Manage Membership Plans
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Revenue Mix Donut & Sticky Table -->
                <div>
                    <!-- Donut Card with Empty State Placeholder -->
                    <div class="report-card-box" style="margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                            <h4 style="margin: 0; font-size: 13.5px; color: var(--ink); text-transform: uppercase; letter-spacing: 0.05em;">Revenue Mix</h4>
                            <span class="pill-badge neutral" id="mix-total-badge">
                                <?= $revenueTotals['monthly']['total'] > 0 ? '₱' . number_format($revenueTotals['monthly']['total'], 2) . ' Total' : 'No Data' ?>
                            </span>
                        </div>

                        <!-- Donut Canvas Wrap -->
                        <div id="revenue-mix-canvas-wrap" style="height: 180px; position: relative; display: <?= $revenueTotals['monthly']['total'] > 0 ? 'block' : 'none' ?>;">
                            <canvas id="revenueMixChart"></canvas>
                        </div>

                        <!-- Donut Empty State Placeholder (Visual Ring with Text) -->
                        <div id="revenue-mix-empty-state" style="height: 180px; display: <?= $revenueTotals['monthly']['total'] > 0 ? 'none' : 'flex' ?>; flex-direction: column; align-items: center; justify-content: center; text-align: center; border: 1px dashed rgba(255,255,255,0.08); border-radius: 12px; padding: 16px;">
                            <div style="width: 44px; height: 44px; border-radius: 50%; border: 2px dashed rgba(255,255,255,0.18); display: flex; align-items: center; justify-content: center; margin-bottom: 8px; color: var(--muted);">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M12 2a10 10 0 0 1 10 10"></path></svg>
                            </div>
                            <span style="font-size: 13px; font-weight: 600; color: var(--ink);">Awaiting Transactions</span>
                            <span style="font-size: 11.5px; color: var(--muted); max-width: 190px; margin-top: 3px;">Donut split will visualize proportions once revenue is logged.</span>
                        </div>

                        <div style="margin-top: 14px; display: flex; flex-direction: column; gap: 8px; font-size: 13px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 7px 12px; background: rgba(132,204,22,0.08); border-radius: 6px;">
                                <span style="color: #84cc16; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                                    <span style="width:8px; height:8px; border-radius:50%; background:#84cc16;"></span> Subscriptions
                                </span>
                                <strong id="mix-sub-label" style="color: var(--ink);">₱<?= number_format($chartsData['revenue']['monthly']['subTotal'], 2) ?></strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 7px 12px; background: rgba(14,165,233,0.08); border-radius: 6px;">
                                <span style="color: #38bdf8; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                                    <span style="width:8px; height:8px; border-radius:50%; background:#38bdf8;"></span> Walk-In Passes
                                </span>
                                <strong id="mix-walk-label" style="color: var(--ink);">₱<?= number_format($chartsData['revenue']['monthly']['walkTotal'], 2) ?></strong>
                            </div>
                        </div>
                    </div>

                    <!-- Detailed Sticky Table with Clean Empty State Fallback -->
                    <div class="table-wrap" style="max-height: 320px; overflow-y: auto; border: 1px solid var(--line); border-radius: 12px; background: var(--surface);">
                        <?php foreach (['daily', 'monthly', 'yearly'] as $tf): 
                            $key = ($tf === 'daily') ? 'day' : (($tf === 'monthly') ? 'month' : 'year');
                            $maxVal = $revenueTotals[$tf]['max'];
                            $hasTableData = $revenueTotals[$tf]['total'] > 0;
                        ?>
                            <div id="revenue-table-<?= $tf ?>" style="display: <?= $tf === 'monthly' ? 'block' : 'none' ?>;">
                                <table class="rich-table">
                                    <thead>
                                        <tr>
                                            <th><?= ucfirst($key) ?></th>
                                            <th style="text-align: right; color: #84cc16;">Subs</th>
                                            <th style="text-align: right; color: #38bdf8;">Walk-In</th>
                                            <th style="text-align: right;">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($hasTableData): ?>
                                            <?php foreach ($revenue[$tf] as $r): 
                                                $isRecord = ($maxVal > 0 && (float)$r['revenue'] === $maxVal);
                                            ?>
                                                <tr>
                                                    <td style="font-weight: 600; white-space: nowrap;">
                                                        <?= h($r[$key]) ?>
                                                        <?php if ($isRecord): ?>
                                                            <span class="peak-tag">★ Peak</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align: right; color: #84cc16;">₱<?= number_format((float)$r['subscriptions'], 2) ?></td>
                                                    <td style="text-align: right; color: #38bdf8;">₱<?= number_format((float)$r['walkins'], 2) ?></td>
                                                    <td style="text-align: right; font-weight: 700; color: var(--ink);">₱<?= number_format((float)$r['revenue'], 2) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="4" style="text-align: center; padding: 36px 16px; color: var(--muted);">
                                                    <div style="font-weight: 600; color: var(--ink); margin-bottom: 4px;">No Transactions in this Window</div>
                                                    <span>Sales by <?= $key ?> will be itemized here automatically.</span>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td>Totals:</td>
                                            <td style="text-align: right; color: #84cc16;">₱<?= number_format($revenueTotals[$tf]['subs'], 2) ?></td>
                                            <td style="text-align: right; color: #38bdf8;">₱<?= number_format($revenueTotals[$tf]['walk'], 2) ?></td>
                                            <td style="text-align: right; color: var(--ink);">₱<?= number_format($revenueTotals[$tf]['total'], 2) ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== TAB 2: ATTENDANCE & PEAK RUSH ==================== -->
    <div id="attendance-tab" class="tab-content animate-fade-in" style="display: none;">
        <div class="panel report-panel" style="border-radius: 16px; padding: 26px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 22px; flex-wrap: wrap; gap: 14px;">
                <div>
                    <h2 style="margin:0 0 4px 0; font-size: 20px;">Attendance & Peak Rush Intelligence</h2>
                    <p style="margin:0; color:var(--muted); font-size:13.5px;">Check-in consistency, rush hour distribution, and weekly capacity load.</p>
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="index.php?page=reports&type=attendance&timeframe=daily&export=csv" id="btn-export-attendance-csv" class="export-btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        Export CSV
                    </a>
                    <a href="index.php?page=reports&type=attendance&timeframe=daily&export=print" id="btn-export-attendance-print" target="_blank" class="export-btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                        Print / PDF
                    </a>
                </div>
            </div>

            <!-- Timeframe controls -->
            <div class="tf-group">
                <div class="tf-controls">
                    <button class="tf-btn tf-btn-attendance active" onclick="setTimeframe('attendance', 'daily')" id="tf-attendance-daily">Daily</button>
                    <button class="tf-btn tf-btn-attendance" onclick="setTimeframe('attendance', 'monthly')" id="tf-attendance-monthly">Monthly</button>
                    <button class="tf-btn tf-btn-attendance" onclick="setTimeframe('attendance', 'yearly')" id="tf-attendance-yearly">Yearly</button>
                </div>
                <span class="tf-hint" id="tf-hint-attendance">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <span id="tf-hint-text-attendance">Viewing last 14 days check-in trail</span>
                </span>
            </div>

            <div class="dash-grid report-dash-grid" style="grid-template-columns: 2fr 1.1fr; gap: 24px; align-items: start; margin-bottom: 30px;">
                <div class="report-card-box">
                    <div class="chart-canvas" id="attendance-chart-canvas-wrap" style="min-height: 330px; height: 330px; display: <?= $attendanceTotals['daily']['visits'] > 0 ? 'block' : 'none' ?>;">
                        <canvas id="attendanceChart"></canvas>
                    </div>

                    <!-- Attendance Empty State -->
                    <div id="attendance-chart-empty-state" class="empty-state-box" style="display: <?= $attendanceTotals['daily']['visits'] > 0 ? 'none' : 'flex' ?>; min-height: 330px;">
                        <div class="empty-icon-circle">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        </div>
                        <h3 class="empty-title">No Member Check-Ins Logged Yet</h3>
                        <p class="empty-desc">
                            Member visits scanned via the QR Turnstile or logged manually at the front desk will generate your check-in trends and capacity insights here.
                        </p>
                        <div class="empty-actions">
                            <a href="index.php?page=scanner" class="btn-primary">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="7" y1="12" x2="17" y2="12"></line></svg>
                                Open QR Turnstile Scanner
                            </a>
                        </div>
                    </div>
                </div>

                <div class="table-wrap compact-table-wrap" style="max-height: 370px; overflow-y: auto; overflow-x: hidden; border: 1px solid var(--line); border-radius: 12px; background: var(--surface);">
                    <?php foreach (['daily', 'monthly', 'yearly'] as $tf): 
                        $key = ($tf === 'daily') ? 'day' : (($tf === 'monthly') ? 'month' : 'year');
                        $maxV = $attendanceTotals[$tf]['max'];
                        $hasAttData = $attendanceTotals[$tf]['visits'] > 0;
                    ?>
                        <div id="attendance-table-<?= $tf ?>" style="display: <?= $tf === 'daily' ? 'block' : 'none' ?>;">
                            <table class="rich-table compact-side-table">
                                <thead>
                                    <tr>
                                        <th><?= ucfirst($key) ?></th>
                                        <th class="col-att-val" style="text-align: right;">Visits</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($hasAttData): ?>
                                        <?php foreach ($attendance[$tf] as $r): 
                                            $isRecord = ($maxV > 0 && (int)$r['visits'] === $maxV);
                                        ?>
                                            <tr>
                                                <td style="font-weight: 600;">
                                                    <?= h($r[$key]) ?>
                                                    <?php if ($isRecord): ?>
                                                        <span class="peak-tag">★ Peak</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="col-att-val" style="text-align: right; font-weight: 700;"><?= (int)$r['visits'] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="2" style="text-align: center; padding: 36px 16px; color: var(--muted);">
                                                <div style="font-weight: 600; color: var(--ink); margin-bottom: 4px;">No Check-Ins in this Window</div>
                                                <span>Attendance visits will be recorded here daily.</span>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td>Total Visits:</td>
                                        <td class="col-att-val" style="text-align: right; font-weight: 700;"><?= $attendanceTotals[$tf]['visits'] ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Bottom: 2 Advanced Charts (Hourly Peak Hours + Day of Week) -->
            <div class="dash-grid report-bottom-grid" style="grid-template-columns: 1fr 1fr; gap: 24px;">
                <!-- Peak Hours Rush Analysis -->
                <div class="report-card-box">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                        <div style="min-width: 0;">
                            <h3 style="margin: 0; font-size: 16px; color: var(--ink);">Hourly Rush Analysis</h3>
                            <span style="font-size: 12px; color: var(--muted);">Check-in frequency by hour of day (5:00 AM – 11:00 PM)</span>
                        </div>
                        <?php if ($peakHour !== null && $peakHourCount > 0): ?>
                            <span class="pill-badge green" style="flex-shrink: 0;">⚡ Peak: <?= date('g A', strtotime("$peakHour:00")) ?></span>
                        <?php else: ?>
                            <span class="pill-badge neutral" style="flex-shrink: 0;">Awaiting Data</span>
                        <?php endif; ?>
                    </div>

                    <div style="background: rgba(132,204,22,0.06); border: 1px solid rgba(132,204,22,0.2); border-radius: 8px; padding: 10px 14px; font-size: 12.5px; color: var(--muted); margin-bottom: 16px; display: flex; align-items: flex-start; gap: 8px;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2" style="flex-shrink: 0; margin-top: 2px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span style="min-width: 0;"><strong>Staffing Optimization:</strong> Ensure high floor trainer presence during typical morning rush (6–8 AM) & evening surge (5–8 PM).</span>
                    </div>

                    <div class="chart-canvas" style="min-height: 260px; height: 260px;">
                        <canvas id="hourlyChart"></canvas>
                    </div>
                </div>

                <!-- Day of Week Distribution -->
                <div class="report-card-box">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                        <div style="min-width: 0;">
                            <h3 style="margin: 0; font-size: 16px; color: var(--ink);">Day-of-Week Distribution</h3>
                            <span style="font-size: 12px; color: var(--muted);">Total check-in volume distributed from Monday to Sunday</span>
                        </div>
                        <?php if ($busiestDayName !== null && $busiestDayCount > 0): ?>
                            <span class="pill-badge purple" style="flex-shrink: 0;">📅 Busiest: <?= h($busiestDayName) ?></span>
                        <?php else: ?>
                            <span class="pill-badge neutral" style="flex-shrink: 0;">Awaiting Data</span>
                        <?php endif; ?>
                    </div>

                    <div style="background: rgba(168,85,247,0.06); border: 1px solid rgba(168,85,247,0.2); border-radius: 8px; padding: 10px 14px; font-size: 12.5px; color: var(--muted); margin-bottom: 16px; display: flex; align-items: flex-start; gap: 8px;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#c084fc" stroke-width="2" style="flex-shrink: 0; margin-top: 2px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        <span style="min-width: 0;"><strong>Capacity Planning:</strong> Schedule facility maintenance and group classes around off-peak mid-week windows.</span>
                    </div>

                    <div class="chart-canvas" style="min-height: 260px; height: 260px;">
                        <canvas id="dayOfWeekChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== TAB 3: WALK-IN TRAFFIC ==================== -->
    <div id="walkin-tab" class="tab-content animate-fade-in" style="display: none;">
        <div class="panel report-panel" style="border-radius: 16px; padding: 26px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 22px; flex-wrap: wrap; gap: 14px;">
                <div>
                    <h2 style="margin:0 0 4px 0; font-size: 20px;">Walk-In Visitor Analytics</h2>
                    <p style="margin:0; color:var(--muted); font-size:13.5px;">Monitor one-time daily pass foot traffic and member conversion opportunities.</p>
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="index.php?page=reports&type=walkin&timeframe=daily&export=csv" id="btn-export-walkin-csv" class="export-btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        Export CSV
                    </a>
                    <a href="index.php?page=reports&type=walkin&timeframe=daily&export=print" id="btn-export-walkin-print" target="_blank" class="export-btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                        Print / PDF
                    </a>
                </div>
            </div>

            <div class="tf-group">
                <div class="tf-controls">
                    <button class="tf-btn tf-btn-walkin active" onclick="setTimeframe('walkin', 'daily')" id="tf-walkin-daily">Daily</button>
                    <button class="tf-btn tf-btn-walkin" onclick="setTimeframe('walkin', 'monthly')" id="tf-walkin-monthly">Monthly</button>
                    <button class="tf-btn tf-btn-walkin" onclick="setTimeframe('walkin', 'yearly')" id="tf-walkin-yearly">Yearly</button>
                </div>
                <span class="tf-hint" id="tf-hint-walkin">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <span id="tf-hint-text-walkin">Viewing last 14 days walk-in trail</span>
                </span>
            </div>

            <div class="dash-grid report-dash-grid" style="grid-template-columns: 2fr 1.1fr; gap: 24px; align-items: start;">
                <div class="report-card-box">
                    <div class="chart-canvas" id="walkin-chart-canvas-wrap" style="min-height: 360px; height: 360px; display: <?= $walkinTotals['daily']['visits'] > 0 ? 'block' : 'none' ?>;">
                        <canvas id="walkinChart"></canvas>
                    </div>

                    <!-- Walkin Empty State -->
                    <div id="walkin-chart-empty-state" class="empty-state-box" style="display: <?= $walkinTotals['daily']['visits'] > 0 ? 'none' : 'flex' ?>; min-height: 360px;">
                        <div class="empty-icon-circle">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline></svg>
                        </div>
                        <h3 class="empty-title">No Walk-In Passes Recorded</h3>
                        <p class="empty-desc">
                            Register walk-in clients who purchase single-day workout passes to track foot traffic, collections, and member conversion potential.
                        </p>
                        <div class="empty-actions">
                            <a href="index.php?page=walk_in" class="btn-primary">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                Log Walk-In Entry
                            </a>
                        </div>
                    </div>
                </div>

                <div class="table-wrap compact-table-wrap" style="max-height: 400px; overflow-y: auto; overflow-x: hidden; border: 1px solid var(--line); border-radius: 12px; background: var(--surface);">
                    <?php foreach (['daily', 'monthly', 'yearly'] as $tf): 
                        $key = ($tf === 'daily') ? 'day' : (($tf === 'monthly') ? 'month' : 'year');
                        $maxV = $walkinTotals[$tf]['max'];
                        $hasWalkData = $walkinTotals[$tf]['visits'] > 0;
                    ?>
                        <div id="walkin-table-<?= $tf ?>" style="display: <?= $tf === 'daily' ? 'block' : 'none' ?>;">
                            <table class="rich-table compact-side-table">
                                <thead>
                                    <tr>
                                        <th><?= ucfirst($key) ?></th>
                                        <th class="col-walk-val" style="text-align: right;">Visits</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($hasWalkData): ?>
                                        <?php foreach ($walkin[$tf] as $r): 
                                            $isRecord = ($maxV > 0 && (int)$r['visits'] === $maxV);
                                        ?>
                                            <tr>
                                                <td style="font-weight: 600;">
                                                    <?= h($r[$key]) ?>
                                                    <?php if ($isRecord): ?>
                                                        <span class="peak-tag">★ Peak</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="col-walk-val" style="text-align: right; font-weight: 700;"><?= (int)$r['visits'] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="2" style="text-align: center; padding: 36px 16px; color: var(--muted);">
                                                <div style="font-weight: 600; color: var(--ink); margin-bottom: 4px;">No Walk-In Passes Sold</div>
                                                <span>Walk-in sales will appear here.</span>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td>Total Passes:</td>
                                        <td class="col-walk-val" style="text-align: right; font-weight: 700;"><?= $walkinTotals[$tf]['visits'] ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== TAB 4: TRAINERS & COMMISSIONS ==================== -->
    <div id="trainers-tab" class="tab-content animate-fade-in" style="display: none;">
        <div class="panel" style="border-radius: 16px; padding: 26px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 22px; flex-wrap: wrap; gap: 14px;">
                <div>
                    <h2 style="margin:0 0 4px 0; font-size: 20px;">Trainer Performance & Commissions</h2>
                    <p style="margin:0; color:var(--muted); font-size:13.5px;">Live performance rankings by active client caseload and paid commissions.</p>
                </div>
                <?php if ($canViewTrainers): ?>
                <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                    <a href="index.php?page=commissions" class="btn-commissions-action">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                        Manage Commissions
                    </a>
                    <a href="index.php?page=reports&type=trainers&export=csv" class="export-btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        Export CSV
                    </a>
                    <a href="index.php?page=reports&type=trainers&export=print" target="_blank" class="export-btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                        Print / PDF
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!$canViewTrainers): ?>
                <!-- Locked Teaser Banner for Starter Gyms -->
                <div style="text-align: center; padding: 50px 24px; background: rgba(255,255,255,0.02); border: 1px dashed var(--line); border-radius: 16px;">
                    <div style="width: 58px; height: 58px; margin: 0 auto 16px; background: rgba(132,204,22,0.12); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--lime);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                    </div>
                    <h3 style="font-size: 20px; font-weight: 700; color: var(--ink); margin: 0 0 8px;">Trainer Commissions & Leaderboard is a Professional Feature</h3>
                    <p style="color: var(--muted); font-size: 14px; max-width: 540px; margin: 0 auto 24px; line-height: 1.5;">
                        Upgrade to the <strong>Professional Plan (₱999/mo)</strong> to manage custom trainer commission splits, track active client caseloads, and incentivize your training staff with live earnings leaderboards.
                    </p>
                    <a href="index.php?page=gym_subscription" class="btn" style="background: var(--lime); color: #0b110e; font-weight: 800; padding: 12px 28px; border-radius: 8px; text-decoration: none; display: inline-block;">
                        Upgrade to Professional (₱999/mo)
                    </a>
                </div>
            <?php else: ?>
                <!-- Trainer Counters -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
                    <div style="background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 18px;">
                        <span style="color: var(--muted); font-size: 12px; font-weight: 600; text-transform: uppercase;">Active Trainers</span>
                        <div style="font-size: 26px; font-weight: 800; color: var(--ink); margin-top: 4px;"><?= $trainerStats['total_trainers'] ?></div>
                    </div>
                    <div style="background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 18px;">
                        <span style="color: var(--muted); font-size: 12px; font-weight: 600; text-transform: uppercase;">Active Trainees Assigned</span>
                        <div style="font-size: 26px; font-weight: 800; color: #84cc16; margin-top: 4px;"><?= $trainerStats['total_active_clients'] ?></div>
                    </div>
                    <div style="background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 18px;">
                        <span style="color: var(--muted); font-size: 12px; font-weight: 600; text-transform: uppercase;">Total Paid Commissions</span>
                        <div style="font-size: 26px; font-weight: 800; color: #38bdf8; margin-top: 4px;">₱<?= number_format($trainerStats['total_paid_commissions'], 2) ?></div>
                    </div>
                </div>

                <!-- Leaderboard Table with Mobile Horizontal Scroll -->
                <div class="trainers-desktop-table table-wrap" style="border: 1px solid var(--line); border-radius: 12px; overflow-x: auto; -webkit-overflow-scrolling: touch; background: var(--surface);">
                    <table class="trainer-leaderboard-table">
                        <thead>
                            <tr class="table-head-row" style="border-bottom: 1px solid var(--line);">
                                <th style="padding: 12px 18px; text-align: left; font-size: 12px; min-width: 220px;">Rank & Trainer</th>
                                <th style="padding: 12px 18px; text-align: left; font-size: 12px; min-width: 170px;">Specialization</th>
                                <th style="padding: 12px 18px; text-align: center; font-size: 12px; min-width: 120px;">Active Clients</th>
                                <th style="padding: 12px 18px; text-align: right; font-size: 12px; min-width: 160px;">Total Paid Commissions</th>
                                <th style="padding: 12px 18px; text-align: center; font-size: 12px; min-width: 110px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($trainersList)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 36px 20px; color: var(--muted);">
                                        <div style="font-weight: 600; color: var(--ink); margin-bottom: 4px;">No Trainers Registered Yet</div>
                                        <span style="font-size: 12.5px;">Add personal trainers to your staff to begin tracking client assignments and automated commission payouts.</span>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($trainersList as $idx => $t): ?>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.03); transition: background 0.15s;">
                                        <td style="padding: 14px 18px;">
                                            <div style="display: flex; align-items: center; gap: 12px;">
                                                <span style="font-weight: 800; font-size: 14px; width: 22px; color: <?= $idx === 0 ? '#fbbf24' : ($idx === 1 ? '#94a3b8' : ($idx === 2 ? '#b45309' : 'var(--muted)')) ?>;">
                                                    #<?= $idx + 1 ?>
                                                </span>
                                                <?= render_avatar($t) ?>
                                                <div>
                                                    <strong style="color: var(--ink); font-size: 14px; display: block;"><?= h($t['first_name'] . ' ' . $t['last_name']) ?></strong>
                                                    <span style="color: var(--muted); font-size: 12.5px;"><?= h($t['email']) ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="padding: 14px 18px; color: var(--muted); font-size: 13.5px;">
                                            <?= h($t['specialization'] ?: 'Strength & Conditioning') ?>
                                        </td>
                                        <td style="padding: 14px 18px; text-align: center;">
                                            <span class="pill-badge green" style="font-size: 12px; padding: 4px 12px;">
                                                <?= (int)$t['active_clients'] ?> Trainees
                                            </span>
                                        </td>
                                        <td style="padding: 14px 18px; text-align: right; font-weight: 700; color: #84cc16; font-size: 14.5px;">
                                            ₱<?= number_format((float)$t['total_commissions'], 2) ?>
                                        </td>
                                        <td style="padding: 14px 18px; text-align: center;">
                                            <a href="index.php?page=commissions&trainer_id=<?= (int)$t['trainer_id'] ?>" class="btn" style="padding: 6px 14px; font-size: 12px; background: rgba(255,255,255,0.06); border: 1px solid var(--line); color: var(--ink); border-radius: 6px; text-decoration: none;">
                                                View Details
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card-List View (< 768px: Zero Horizontal Scroll) -->
                <div class="trainers-mobile-cards">
                    <?php if (empty($trainersList)): ?>
                        <div class="trainer-card-empty">
                            <div style="font-weight: 600; color: var(--ink); margin-bottom: 4px;">No Trainers Registered Yet</div>
                            <span style="font-size: 12.5px; color: var(--muted);">Add personal trainers to your staff to begin tracking client assignments and automated commission payouts.</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($trainersList as $idx => $t): ?>
                            <div class="trainer-mobile-card">
                                <div class="trainer-card-header">
                                    <div class="trainer-card-profile">
                                        <span class="trainer-card-rank" style="color: <?= $idx === 0 ? '#fbbf24' : ($idx === 1 ? '#94a3b8' : ($idx === 2 ? '#b45309' : 'var(--muted)')) ?>;">
                                            #<?= $idx + 1 ?>
                                        </span>
                                        <?= render_avatar($t) ?>
                                        <div class="trainer-card-identity">
                                            <strong class="trainer-card-name"><?= h($t['first_name'] . ' ' . $t['last_name']) ?></strong>
                                            <span class="trainer-card-email"><?= h($t['email']) ?></span>
                                        </div>
                                    </div>
                                    <span class="pill-badge green" style="font-size: 11px; padding: 3px 9px; font-weight: 700; flex-shrink: 0;">
                                        <?= (int)$t['active_clients'] ?> Trainees
                                    </span>
                                </div>

                                <div class="trainer-card-details">
                                    <div class="trainer-card-detail-item">
                                        <span class="detail-label">Specialization</span>
                                        <span class="detail-val"><?= h($t['specialization'] ?: 'Strength & Conditioning') ?></span>
                                    </div>
                                    <div class="trainer-card-detail-item">
                                        <span class="detail-label">Total Paid Commissions</span>
                                        <span class="detail-val commission-amount">₱<?= number_format((float)$t['total_commissions'], 2) ?></span>
                                    </div>
                                </div>

                                <div class="trainer-card-footer">
                                    <a href="index.php?page=commissions&trainer_id=<?= (int)$t['trainer_id'] ?>" class="btn-trainer-details">
                                        <span>View Details</span>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==================== TAB 5: MEMBER ENGAGEMENT ==================== -->
    <div id="engagement-tab" class="tab-content animate-fade-in" style="display: none;">
        <div class="panel" style="border-radius: 16px; padding: 26px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 24px; flex-wrap: wrap; gap: 14px;">
                <div>
                    <h2 style="margin:0 0 4px 0; font-size: 20px;">Member Engagement & Churn Risk</h2>
                    <p style="margin:0; color:var(--muted); font-size:13.5px;">Predictive retention scoring identifying at-risk members before they churn.</p>
                </div>
                <?php if ($isPlatformAdmin || gym_has_feature('engagement_tracking')): ?>
                <div style="display: flex; gap: 8px;">
                    <a href="index.php?page=reports&type=engagement&export=csv" class="export-btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        Export CSV
                    </a>
                    <a href="index.php?page=reports&type=engagement&export=print" target="_blank" class="export-btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                        Print / PDF
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!$isPlatformAdmin && !gym_has_feature('engagement_tracking')): ?>
                <div style="text-align: center; padding: 60px 24px; background: rgba(255,255,255,0.02); border: 1px dashed var(--line); border-radius: 16px;">
                    <div style="width: 56px; height: 56px; margin: 0 auto 16px; background: rgba(132,204,22,0.12); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--lime);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h3 style="font-size: 20px; font-weight: 700; color: var(--ink); margin: 0 0 8px;">Member Engagement Scoring is a Professional Feature</h3>
                    <p style="color: var(--muted); font-size: 14px; max-width: 540px; margin: 0 auto 24px; line-height: 1.5;">
                        Upgrade to the <strong>Professional Plan (₱999/mo)</strong> to unlock automated member churn risk alerts, engagement score categories (High, Medium, At-Risk), and attendance consistency tracking.
                    </p>
                    <a href="index.php?page=gym_subscription" class="btn" style="background: var(--lime); color: #0b110e; font-weight: 800; padding: 12px 28px; border-radius: 8px; text-decoration: none; display: inline-block;">
                        Upgrade to Professional (₱999/mo)
                    </a>
                </div>
            <?php else: ?>
            <div class="dash-grid engagement-grid" style="grid-template-columns: 1.1fr 2fr; gap: 28px; align-items: start;">
                <div class="engagement-chart-col" style="position: sticky; top: 20px;">
                    <div class="engagement-chart-box">
                        <div style="margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between;">
                            <span style="font-size: 13px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em;">Risk Distribution</span>
                            <span class="pill-badge neutral" style="font-size: 11px;"><?= array_sum($categories) ?> Active</span>
                        </div>
                        <?php if (array_sum($categories) > 0): ?>
                            <div class="chart-canvas engagement-chart-wrap">
                                <canvas id="engagementChart"></canvas>
                            </div>
                        <?php else: ?>
                            <div style="min-height: 260px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; border: 1px dashed rgba(255,255,255,0.08); border-radius: 12px; padding: 24px;">
                                <div style="width: 48px; height: 48px; border-radius: 50%; border: 2px dashed rgba(255,255,255,0.18); display: flex; align-items: center; justify-content: center; margin-bottom: 12px; color: var(--muted);">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M12 2a10 10 0 0 1 10 10"></path></svg>
                                </div>
                                <strong style="color: var(--ink); font-size: 14px;">Awaiting Member Activity</strong>
                                <p style="color: var(--muted); font-size: 12.5px; margin: 6px 0 0; max-width: 220px; line-height: 1.4;">
                                    Engagement and retention proportions will automatically graph once members are enrolled.
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="engagement-list-col">
                    <?php foreach ($categories as $catName => $count): 
                        $catColor = $catName === 'Highly Engaged' ? '#10b981' : ($catName === 'Moderately Engaged' ? '#f59e0b' : '#ef4444');
                    ?>
                        <div class="engagement-category-card">
                            <div style="padding: 14px 18px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; gap: 10px;">
                                <h3 style="margin:0; font-size: 15px; color: var(--ink); display: flex; align-items: center; gap: 8px;">
                                    <span style="width: 10px; height: 10px; border-radius: 50%; background: <?= $catColor ?>; flex-shrink: 0;"></span>
                                    <?= h($catName) ?>
                                </h3>
                                <span style="background: rgba(255,255,255,0.05); padding: 3px 10px; border-radius: 999px; font-size: 12px; color: var(--muted); font-weight: bold; flex-shrink: 0;">
                                    <?= $count ?> Members
                                </span>
                            </div>
                            
                            <?php if ($count > 0): ?>
                                <div class="engagement-member-list">
                                    <?php foreach ($memberLists[$catName] as $m): ?>
                                        <div class="engagement-member-item">
                                            <div class="engagement-member-profile">
                                                <?= render_avatar($m) ?>
                                                <div class="engagement-member-info">
                                                    <strong class="engagement-member-name"><?= h($m['first_name'] . ' ' . $m['last_name']) ?></strong>
                                                    <span class="engagement-member-email"><?= h($m['email']) ?></span>
                                                </div>
                                            </div>
                                            <div class="engagement-member-meta">
                                                <span class="pill-badge <?= $catName === 'Highly Engaged' ? 'green' : ($catName === 'Moderately Engaged' ? 'blue' : 'red') ?>" style="font-size: 11px; padding: 3px 9px; font-weight: 700;">
                                                    Score: <?= (int)($m['score'] ?? 0) ?>
                                                </span>
                                                <a href="index.php?page=users&tab=member" class="btn-engagement-manage">
                                                    Manage
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div style="padding: 24px; text-align: center; color: var(--muted); font-size: 13.5px;">
                                    No members in this category.
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Chart.js & Tab Controller Logic -->
    <script>
    const chartsData = <?= $chartsDataJson ?>;
    let revenueChartInstance = null;
    let revenueMixChartInstance = null;
    let attendanceChartInstance = null;
    let hourlyChartInstance = null;
    let dayOfWeekChartInstance = null;
    let walkinChartInstance = null;
    let engagementChartInstance = null;

    // Timeframe hints mapping
    const tfHints = {
        daily: 'Viewing last 14 days rolling window',
        monthly: 'Viewing last 12 months rolling run-rate',
        yearly: 'Viewing last 5 fiscal years annual trend'
    };

    window.showTab = function(tabId, updateHistory = true) {
        document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
        document.querySelectorAll('.report-tab-btn').forEach(el => el.classList.remove('active'));
        
        const target = document.getElementById(tabId);
        if (target) target.style.display = 'block';
        
        const activeBtn = document.querySelector(`.report-tab-btn[data-tab="${tabId}"]`) || 
                          document.querySelector(`.report-tab-btn[onclick*="${tabId}"]`);
        if (activeBtn) activeBtn.classList.add('active');

        if (updateHistory) {
            try {
                history.replaceState(null, '', '#' + tabId);
                sessionStorage.setItem('fittrack_reports_tab', tabId);
            } catch (e) {}
        }

        // Trigger chart resizes for smooth un-hidden rendering
        setTimeout(() => {
            if (tabId === 'revenue-tab') {
                if (revenueChartInstance) revenueChartInstance.resize();
                if (revenueMixChartInstance) revenueMixChartInstance.resize();
            } else if (tabId === 'attendance-tab') {
                if (attendanceChartInstance) attendanceChartInstance.resize();
                if (hourlyChartInstance) hourlyChartInstance.resize();
                if (dayOfWeekChartInstance) dayOfWeekChartInstance.resize();
            } else if (tabId === 'walkin-tab') {
                if (walkinChartInstance) walkinChartInstance.resize();
            } else if (tabId === 'engagement-tab') {
                if (engagementChartInstance) {
                    engagementChartInstance.resize();
                    engagementChartInstance.update();
                }
            }
        }, 60);
    };

    window.setTimeframe = function(type, timeframe) {
        document.querySelectorAll('.tf-btn-' + type).forEach(el => el.classList.remove('active'));
        const btn = document.getElementById('tf-' + type + '-' + timeframe);
        if (btn) btn.classList.add('active');

        const hintText = document.getElementById('tf-hint-text-' + type);
        if (hintText && tfHints[timeframe]) {
            hintText.textContent = tfHints[timeframe];
        }

        ['daily', 'monthly', 'yearly'].forEach(tf => {
            const table = document.getElementById(type + '-table-' + tf);
            if (table) table.style.display = (tf === timeframe) ? 'block' : 'none';
        });

        const csvBtn = document.getElementById('btn-export-' + type + '-csv');
        const printBtn = document.getElementById('btn-export-' + type + '-print');
        if (csvBtn) csvBtn.href = 'index.php?page=reports&type=' + type + '&timeframe=' + timeframe + '&export=csv';
        if (printBtn) printBtn.href = 'index.php?page=reports&type=' + type + '&timeframe=' + timeframe + '&export=print';

        if (chartsData[type] && chartsData[type][timeframe]) {
            if (type === 'revenue') {
                const subT = chartsData.revenue[timeframe].subTotal;
                const walkT = chartsData.revenue[timeframe].walkTotal;
                const tot = subT + walkT;

                const chartWrap = document.getElementById('revenue-chart-canvas-wrap');
                const chartEmpty = document.getElementById('revenue-chart-empty-state');
                const mixWrap = document.getElementById('revenue-mix-canvas-wrap');
                const mixEmpty = document.getElementById('revenue-mix-empty-state');

                if (tot > 0) {
                    if (chartWrap) chartWrap.style.display = 'block';
                    if (chartEmpty) chartEmpty.style.display = 'none';
                    if (mixWrap) mixWrap.style.display = 'block';
                    if (mixEmpty) mixEmpty.style.display = 'none';
                } else {
                    if (chartWrap) chartWrap.style.display = 'none';
                    if (chartEmpty) chartEmpty.style.display = 'flex';
                    if (mixWrap) mixWrap.style.display = 'none';
                    if (mixEmpty) mixEmpty.style.display = 'flex';
                }

                if (revenueChartInstance) {
                    revenueChartInstance.data.labels = chartsData.revenue[timeframe].labels;
                    revenueChartInstance.data.datasets[0].data = chartsData.revenue[timeframe].subscriptions;
                    revenueChartInstance.data.datasets[1].data = chartsData.revenue[timeframe].walkins;
                    revenueChartInstance.update();
                }

                if (revenueMixChartInstance) {
                    revenueMixChartInstance.data.datasets[0].data = [subT, walkT];
                    revenueMixChartInstance.update();
                }

                const subLbl = document.getElementById('mix-sub-label');
                const walkLbl = document.getElementById('mix-walk-label');
                const totBadge = document.getElementById('mix-total-badge');
                if (subLbl) subLbl.textContent = '₱' + subT.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                if (walkLbl) walkLbl.textContent = '₱' + walkT.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                if (totBadge) {
                    totBadge.textContent = tot > 0 ? ('₱' + tot.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' Total') : 'No Data';
                }
            } else if (type === 'attendance') {
                const tot = chartsData.attendance[timeframe].total || 0;
                const attWrap = document.getElementById('attendance-chart-canvas-wrap');
                const attEmpty = document.getElementById('attendance-chart-empty-state');

                if (tot > 0) {
                    if (attWrap) attWrap.style.display = 'block';
                    if (attEmpty) attEmpty.style.display = 'none';
                } else {
                    if (attWrap) attWrap.style.display = 'none';
                    if (attEmpty) attEmpty.style.display = 'flex';
                }

                if (attendanceChartInstance) {
                    attendanceChartInstance.data.labels = chartsData.attendance[timeframe].labels;
                    attendanceChartInstance.data.datasets[0].data = chartsData.attendance[timeframe].data;
                    attendanceChartInstance.update();
                }
            } else if (type === 'walkin') {
                const tot = chartsData.walkin[timeframe].total || 0;
                const walkWrap = document.getElementById('walkin-chart-canvas-wrap');
                const walkEmpty = document.getElementById('walkin-chart-empty-state');

                if (tot > 0) {
                    if (walkWrap) walkWrap.style.display = 'block';
                    if (walkEmpty) walkEmpty.style.display = 'none';
                } else {
                    if (walkWrap) walkWrap.style.display = 'none';
                    if (walkEmpty) walkEmpty.style.display = 'flex';
                }

                if (walkinChartInstance) {
                    walkinChartInstance.data.labels = chartsData.walkin[timeframe].labels;
                    walkinChartInstance.data.datasets[0].data = chartsData.walkin[timeframe].data;
                    walkinChartInstance.update();
                }
            }
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        // Initial tab check from URL hash or storage
        let initialTab = window.location.hash ? window.location.hash.substring(1) : (sessionStorage.getItem('fittrack_reports_tab') || 'revenue-tab');
        if (!document.getElementById(initialTab)) initialTab = 'revenue-tab';
        window.showTab(initialTab, false);

        window.addEventListener('hashchange', function() {
            if (window.location.hash) {
                const hTab = window.location.hash.substring(1);
                if (document.getElementById(hTab)) window.showTab(hTab, false);
            }
        });

        if (typeof Chart !== 'undefined') {
            function getThemeColors() {
                const isLight = document.documentElement.getAttribute('data-theme') === 'light';
                return {
                    isLight: isLight,
                    textColor: isLight ? '#0f172a' : '#cbd5e1',
                    tickFont: { family: "'Inter', system-ui, -apple-system, sans-serif", size: 11, weight: '600' },
                    gridColor: isLight ? '#e2e8f0' : 'rgba(255, 255, 255, 0.08)',
                    axisLineColor: isLight ? '#94a3b8' : 'rgba(255, 255, 255, 0.20)',
                    tooltipBg: '#0f172a',
                    tooltipTitle: '#ffffff',
                    tooltipBorder: isLight ? '#334155' : 'rgba(255, 255, 255, 0.2)',
                    
                    revenue: {
                        subs: isLight ? '#4d7c0f' : '#84cc16',
                        subsHover: isLight ? '#3f6212' : '#a3e635',
                        walk: isLight ? '#0284c7' : '#0ea5e9',
                        walkHover: isLight ? '#0369a1' : '#38bdf8'
                    },
                    attendance: {
                        line: isLight ? '#7c3aed' : '#a78bfa',
                        fill: isLight ? 'rgba(124, 58, 237, 0.22)' : 'rgba(167, 139, 250, 0.15)',
                        pointBg: isLight ? '#ffffff' : '#0f172a',
                        pointBorder: isLight ? '#7c3aed' : '#a78bfa',
                        pointHoverBg: isLight ? '#7c3aed' : '#ffffff'
                    },
                    hourly: {
                        peak: isLight ? '#4d7c0f' : 'rgba(132, 204, 22, 0.95)',
                        peakHover: isLight ? '#3f6212' : '#a3e635',
                        normal: isLight ? 'rgba(77, 124, 15, 0.32)' : 'rgba(132, 204, 22, 0.35)',
                        normalHover: isLight ? 'rgba(77, 124, 15, 0.55)' : 'rgba(132, 204, 22, 0.60)'
                    },
                    dayOfWeek: {
                        peak: isLight ? '#7e22ce' : 'rgba(168, 85, 247, 0.95)',
                        peakHover: isLight ? '#6b21a8' : '#c084fc',
                        normal: isLight ? 'rgba(126, 34, 206, 0.30)' : 'rgba(168, 85, 247, 0.38)',
                        normalHover: isLight ? 'rgba(126, 34, 206, 0.55)' : 'rgba(168, 85, 247, 0.60)'
                    },
                    walkin: {
                        line: isLight ? '#0284c7' : '#38bdf8',
                        fill: isLight ? 'rgba(2, 132, 199, 0.22)' : 'rgba(56, 189, 248, 0.15)',
                        pointBg: isLight ? '#ffffff' : '#0f172a',
                        pointBorder: isLight ? '#0284c7' : '#38bdf8',
                        pointHoverBg: isLight ? '#0284c7' : '#ffffff'
                    },
                    engagement: {
                        colors: isLight ? ['#059669', '#d97706', '#dc2626'] : ['#10b981', '#f59e0b', '#ef4444']
                    }
                };
            }

            const initialColors = getThemeColors();
            Chart.defaults.color = initialColors.textColor;
            Chart.defaults.borderColor = initialColors.gridColor;
            
            // 1. Revenue Stacked Bar Chart
            const revCanvas = document.getElementById('revenueChart');
            if (revCanvas) {
                revenueChartInstance = new Chart(revCanvas, {
                    type: 'bar',
                    data: {
                        labels: chartsData.revenue.monthly.labels,
                        datasets: [
                            {
                                label: 'Subscriptions',
                                data: chartsData.revenue.monthly.subscriptions,
                                backgroundColor: initialColors.revenue.subs,
                                hoverBackgroundColor: initialColors.revenue.subsHover,
                                borderRadius: 5,
                                barPercentage: 0.65
                            },
                            {
                                label: 'Walk-In Passes',
                                data: chartsData.revenue.monthly.walkins,
                                backgroundColor: initialColors.revenue.walk,
                                hoverBackgroundColor: initialColors.revenue.walkHover,
                                borderRadius: 5,
                                barPercentage: 0.65
                            }
                        ]
                    },
                    options: { 
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        scales: { 
                            x: { 
                                stacked: true,
                                grid: { display: false },
                                border: { color: initialColors.axisLineColor, width: 1.5 },
                                ticks: {
                                    color: initialColors.textColor,
                                    font: initialColors.tickFont
                                }
                            },
                            y: { 
                                stacked: true,
                                beginAtZero: true,
                                suggestedMin: 0,
                                suggestedMax: 1000,
                                grid: { color: initialColors.gridColor },
                                border: { color: initialColors.axisLineColor, width: 1.5 },
                                ticks: {
                                    color: initialColors.textColor,
                                    font: initialColors.tickFont,
                                    precision: 0,
                                    callback: function(val) {
                                        if (val % 1 !== 0) return '';
                                        return '₱' + Number(val).toLocaleString();
                                    }
                                }
                            }
                        }, 
                        plugins: { 
                            legend: { display: false },
                            tooltip: { 
                                backgroundColor: initialColors.tooltipBg, 
                                titleColor: initialColors.tooltipTitle, 
                                padding: 14, 
                                borderColor: initialColors.tooltipBorder, 
                                borderWidth: 1,
                                cornerRadius: 8,
                                callbacks: {
                                    label: function(context) {
                                        let label = context.dataset.label || '';
                                        let val = context.parsed.y || 0;
                                        return ' ' + label + ': ₱' + val.toLocaleString(undefined, {minimumFractionDigits: 2});
                                    },
                                    footer: function(items) {
                                        let total = 0;
                                        items.forEach(function(item) { total += item.parsed.y; });
                                        return 'Total: ₱' + total.toLocaleString(undefined, {minimumFractionDigits: 2});
                                    }
                                }
                            }
                        } 
                    }
                });
            }

            // 2. Revenue Mix Donut Chart
            const mixCanvas = document.getElementById('revenueMixChart');
            if (mixCanvas) {
                const subT = chartsData.revenue.monthly.subTotal;
                const walkT = chartsData.revenue.monthly.walkTotal;
                revenueMixChartInstance = new Chart(mixCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: ['Subscriptions', 'Walk-Ins'],
                        datasets: [{
                            data: [subT, walkT],
                            backgroundColor: [initialColors.revenue.subs, initialColors.revenue.walk],
                            borderWidth: 0,
                            hoverOffset: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '72%',
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: initialColors.tooltipBg,
                                titleColor: initialColors.tooltipTitle,
                                borderColor: initialColors.tooltipBorder,
                                borderWidth: 1,
                                padding: 12,
                                callbacks: {
                                    label: function(ctx) {
                                        const tot = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                        const pct = tot > 0 ? ((ctx.parsed / tot) * 100).toFixed(1) : '0.0';
                                        return ' ' + ctx.label + ': ₱' + ctx.parsed.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' (' + pct + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // 3. Attendance Trend Chart (High Contrast in Light & Dark Mode)
            const attCanvas = document.getElementById('attendanceChart');
            if (attCanvas) {
                attendanceChartInstance = new Chart(attCanvas, {
                    type: 'line',
                    data: {
                        labels: chartsData.attendance.daily.labels,
                        datasets: [{
                            label: 'Check-Ins',
                            data: chartsData.attendance.daily.data,
                            borderColor: initialColors.attendance.line,
                            backgroundColor: initialColors.attendance.fill,
                            fill: true,
                            tension: 0.35,
                            borderWidth: 3.5,
                            pointBackgroundColor: initialColors.attendance.pointBg,
                            pointBorderColor: initialColors.attendance.pointBorder,
                            pointBorderWidth: 2.5,
                            pointRadius: 4.5,
                            pointHoverRadius: 7,
                            pointHoverBackgroundColor: initialColors.attendance.pointHoverBg,
                            pointHoverBorderColor: '#ffffff'
                        }]
                    },
                    options: { 
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: { 
                            y: { 
                                beginAtZero: true,
                                suggestedMin: 0,
                                suggestedMax: 10,
                                grid: { color: initialColors.gridColor },
                                border: { color: initialColors.axisLineColor, width: 1.5 },
                                ticks: { 
                                    color: initialColors.textColor,
                                    font: initialColors.tickFont,
                                    precision: 0 
                                }
                            }, 
                            x: { 
                                grid: { display: false },
                                border: { color: initialColors.axisLineColor, width: 1.5 },
                                ticks: { 
                                    color: initialColors.textColor,
                                    font: initialColors.tickFont 
                                }
                            }
                        }, 
                        plugins: { 
                            legend: { display: false },
                            tooltip: { 
                                backgroundColor: initialColors.tooltipBg, 
                                titleColor: initialColors.tooltipTitle, 
                                bodyColor: '#a78bfa', 
                                padding: 12, 
                                borderColor: initialColors.tooltipBorder, 
                                borderWidth: 1 
                            }
                        } 
                    }
                });
            }

            // 4. Hourly Rush Bar Chart with Dynamic Rush Highlight
            const hrCanvas = document.getElementById('hourlyChart');
            if (hrCanvas) {
                const hourlyColors = chartsData.hourly.labels.map(lbl => {
                    const isMorning = ['6 AM', '7 AM', '8 AM'].includes(lbl);
                    const isEvening = ['5 PM', '6 PM', '7 PM', '8 PM'].includes(lbl);
                    return (isMorning || isEvening) ? initialColors.hourly.peak : initialColors.hourly.normal;
                });

                hourlyChartInstance = new Chart(hrCanvas, {
                    type: 'bar',
                    data: {
                        labels: chartsData.hourly.labels,
                        datasets: [{
                            label: 'Visits',
                            data: chartsData.hourly.data,
                            backgroundColor: hourlyColors,
                            hoverBackgroundColor: initialColors.hourly.peakHover,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: { 
                                beginAtZero: true, 
                                suggestedMin: 0,
                                suggestedMax: 5,
                                grid: { color: initialColors.gridColor },
                                border: { color: initialColors.axisLineColor, width: 1.5 }, 
                                ticks: { 
                                    color: initialColors.textColor,
                                    font: initialColors.tickFont,
                                    precision: 0, 
                                    stepSize: 1 
                                } 
                            },
                            x: { 
                                grid: { display: false },
                                border: { color: initialColors.axisLineColor, width: 1.5 }, 
                                ticks: { 
                                    color: initialColors.textColor,
                                    font: { family: "'Inter', system-ui, sans-serif", size: 10.5, weight: '600' } 
                                } 
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: { 
                                backgroundColor: initialColors.tooltipBg, 
                                titleColor: initialColors.tooltipTitle, 
                                bodyColor: '#84cc16', 
                                borderColor: initialColors.tooltipBorder,
                                borderWidth: 1,
                                padding: 10,
                                callbacks: {
                                    afterLabel: function(ctx) {
                                        const lbl = ctx.label;
                                        if (['6 AM', '7 AM', '8 AM', '5 PM', '6 PM', '7 PM', '8 PM'].includes(lbl)) {
                                            return '⚡ Peak Rush Window';
                                        }
                                        return '';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // 5. Day-of-Week Distribution Bar Chart with Peak Day Highlighting
            const dowCanvas = document.getElementById('dayOfWeekChart');
            if (dowCanvas) {
                const maxVal = Math.max(...chartsData.day_of_week.data);
                const dowColors = chartsData.day_of_week.data.map(val => {
                    return (val === maxVal && maxVal > 0) ? initialColors.dayOfWeek.peak : initialColors.dayOfWeek.normal;
                });

                dayOfWeekChartInstance = new Chart(dowCanvas, {
                    type: 'bar',
                    data: {
                        labels: chartsData.day_of_week.labels,
                        datasets: [{
                            label: 'Visits',
                            data: chartsData.day_of_week.data,
                            backgroundColor: dowColors,
                            hoverBackgroundColor: initialColors.dayOfWeek.peakHover,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: { 
                                beginAtZero: true, 
                                suggestedMin: 0,
                                suggestedMax: 5,
                                grid: { color: initialColors.gridColor },
                                border: { color: initialColors.axisLineColor, width: 1.5 }, 
                                ticks: { 
                                    color: initialColors.textColor,
                                    font: initialColors.tickFont,
                                    precision: 0, 
                                    stepSize: 1 
                                } 
                            },
                            x: { 
                                grid: { display: false },
                                border: { color: initialColors.axisLineColor, width: 1.5 },
                                ticks: {
                                    color: initialColors.textColor,
                                    font: initialColors.tickFont
                                }
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: { backgroundColor: initialColors.tooltipBg, titleColor: initialColors.tooltipTitle, bodyColor: '#c084fc', borderColor: initialColors.tooltipBorder, borderWidth: 1, padding: 10 }
                        }
                    }
                });
            }

            // 6. Walk-In Trend Chart
            const walkCanvas = document.getElementById('walkinChart');
            if (walkCanvas) {
                walkinChartInstance = new Chart(walkCanvas, {
                    type: 'line',
                    data: {
                        labels: chartsData.walkin.daily.labels,
                        datasets: [{
                            label: 'Walk-In Visitors',
                            data: chartsData.walkin.daily.data,
                            borderColor: initialColors.walkin.line,
                            backgroundColor: initialColors.walkin.fill,
                            fill: true,
                            tension: 0.35,
                            borderWidth: 3.5,
                            pointBackgroundColor: initialColors.walkin.pointBg,
                            pointBorderColor: initialColors.walkin.pointBorder,
                            pointBorderWidth: 2.5,
                            pointRadius: 4.5,
                            pointHoverRadius: 7,
                            pointHoverBackgroundColor: initialColors.walkin.pointHoverBg,
                            pointHoverBorderColor: '#ffffff'
                        }]
                    },
                    options: { 
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: { 
                            y: { 
                                beginAtZero: true, 
                                suggestedMin: 0,
                                suggestedMax: 5,
                                grid: { color: initialColors.gridColor },
                                border: { color: initialColors.axisLineColor, width: 1.5 },
                                ticks: { 
                                    color: initialColors.textColor,
                                    font: initialColors.tickFont,
                                    precision: 0 
                                }
                            }, 
                            x: { 
                                grid: { display: false },
                                border: { color: initialColors.axisLineColor, width: 1.5 },
                                ticks: { 
                                    color: initialColors.textColor,
                                    font: initialColors.tickFont 
                                }
                            }
                        }, 
                        plugins: { 
                            legend: { display: false },
                            tooltip: { backgroundColor: initialColors.tooltipBg, titleColor: initialColors.tooltipTitle, bodyColor: '#38bdf8', padding: 12, borderColor: initialColors.tooltipBorder, borderWidth: 1 }
                        } 
                    }
                });
            }

            // 7. Engagement Churn Doughnut Chart
            const engCanvas = document.getElementById('engagementChart');
            if (engCanvas) {
                engagementChartInstance = new Chart(engCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: <?= $engagementLabels ?>,
                        datasets: [{
                            data: <?= $engagementJson ?>,
                            backgroundColor: initialColors.engagement.colors,
                            borderWidth: 0,
                            hoverOffset: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '70%',
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    color: initialColors.textColor,
                                    padding: 14,
                                    boxWidth: 12,
                                    usePointStyle: true,
                                    font: { size: 12, weight: '600', family: "'Inter', sans-serif" }
                                }
                            },
                            tooltip: {
                                backgroundColor: initialColors.tooltipBg,
                                titleColor: initialColors.tooltipTitle,
                                borderColor: initialColors.tooltipBorder,
                                borderWidth: 1,
                                padding: 10
                            }
                        }
                    }
                });
            }

            // Dynamic theme observer for live dark/light mode switching
            function applyThemeToCharts() {
                if (typeof Chart === 'undefined') return;
                const tc = getThemeColors();
                Chart.defaults.color = tc.textColor;
                Chart.defaults.borderColor = tc.gridColor;

                // Update Revenue Bar
                if (revenueChartInstance && revenueChartInstance.data.datasets.length >= 2) {
                    revenueChartInstance.data.datasets[0].backgroundColor = tc.revenue.subs;
                    revenueChartInstance.data.datasets[0].hoverBackgroundColor = tc.revenue.subsHover;
                    revenueChartInstance.data.datasets[1].backgroundColor = tc.revenue.walk;
                    revenueChartInstance.data.datasets[1].hoverBackgroundColor = tc.revenue.walkHover;
                }

                // Update Revenue Mix Donut
                if (revenueMixChartInstance && revenueMixChartInstance.data.datasets.length) {
                    revenueMixChartInstance.data.datasets[0].backgroundColor = [tc.revenue.subs, tc.revenue.walk];
                }

                // Update Attendance Line
                if (attendanceChartInstance && attendanceChartInstance.data.datasets.length) {
                    attendanceChartInstance.data.datasets[0].borderColor = tc.attendance.line;
                    attendanceChartInstance.data.datasets[0].backgroundColor = tc.attendance.fill;
                    attendanceChartInstance.data.datasets[0].pointBackgroundColor = tc.attendance.pointBg;
                    attendanceChartInstance.data.datasets[0].pointBorderColor = tc.attendance.pointBorder;
                    attendanceChartInstance.data.datasets[0].pointHoverBackgroundColor = tc.attendance.pointHoverBg;
                }

                // Update Hourly Bar
                if (hourlyChartInstance && hourlyChartInstance.data.datasets.length) {
                    hourlyChartInstance.data.datasets[0].backgroundColor = chartsData.hourly.labels.map(lbl => {
                        const isRush = ['6 AM', '7 AM', '8 AM', '5 PM', '6 PM', '7 PM', '8 PM'].includes(lbl);
                        return isRush ? tc.hourly.peak : tc.hourly.normal;
                    });
                    hourlyChartInstance.data.datasets[0].hoverBackgroundColor = tc.hourly.peakHover;
                }

                // Update Day of Week Bar
                if (dayOfWeekChartInstance && dayOfWeekChartInstance.data.datasets.length) {
                    const maxVal = Math.max(...chartsData.day_of_week.data);
                    dayOfWeekChartInstance.data.datasets[0].backgroundColor = chartsData.day_of_week.data.map(val => {
                        return (val === maxVal && maxVal > 0) ? tc.dayOfWeek.peak : tc.dayOfWeek.normal;
                    });
                    dayOfWeekChartInstance.data.datasets[0].hoverBackgroundColor = tc.dayOfWeek.peakHover;
                }

                // Update Walk-In Line
                if (walkinChartInstance && walkinChartInstance.data.datasets.length) {
                    walkinChartInstance.data.datasets[0].borderColor = tc.walkin.line;
                    walkinChartInstance.data.datasets[0].backgroundColor = tc.walkin.fill;
                    walkinChartInstance.data.datasets[0].pointBackgroundColor = tc.walkin.pointBg;
                    walkinChartInstance.data.datasets[0].pointBorderColor = tc.walkin.pointBorder;
                    walkinChartInstance.data.datasets[0].pointHoverBackgroundColor = tc.walkin.pointHoverBg;
                }

                // Update Engagement Doughnut
                if (engagementChartInstance && engagementChartInstance.data.datasets.length) {
                    engagementChartInstance.data.datasets[0].backgroundColor = tc.engagement.colors;
                    if (engagementChartInstance.options.plugins && engagementChartInstance.options.plugins.legend) {
                        engagementChartInstance.options.plugins.legend.labels.color = tc.textColor;
                    }
                }

                const list = [
                    revenueChartInstance,
                    revenueMixChartInstance,
                    attendanceChartInstance,
                    hourlyChartInstance,
                    dayOfWeekChartInstance,
                    walkinChartInstance,
                    engagementChartInstance
                ];

                list.forEach(c => {
                    if (!c) return;
                    if (c.options && c.options.scales) {
                        if (c.options.scales.y) {
                            if (c.options.scales.y.ticks) {
                                c.options.scales.y.ticks.color = tc.textColor;
                                c.options.scales.y.ticks.font = tc.tickFont;
                            }
                            if (c.options.scales.y.grid) {
                                c.options.scales.y.grid.color = tc.gridColor;
                            }
                            if (!c.options.scales.y.border) c.options.scales.y.border = {};
                            c.options.scales.y.border.color = tc.axisLineColor;
                            c.options.scales.y.border.width = 1.5;
                        }
                        if (c.options.scales.x) {
                            if (c.options.scales.x.ticks) {
                                c.options.scales.x.ticks.color = tc.textColor;
                                c.options.scales.x.ticks.font = (c === hourlyChartInstance) 
                                    ? { family: "'Inter', system-ui, sans-serif", size: 10.5, weight: '600' }
                                    : tc.tickFont;
                            }
                            if (c.options.scales.x.grid && c.options.scales.x.grid.display) {
                                c.options.scales.x.grid.color = tc.gridColor;
                            }
                            if (!c.options.scales.x.border) c.options.scales.x.border = {};
                            c.options.scales.x.border.color = tc.axisLineColor;
                            c.options.scales.x.border.width = 1.5;
                        }
                    }
                    if (c.options && c.options.plugins && c.options.plugins.tooltip) {
                        c.options.plugins.tooltip.backgroundColor = tc.tooltipBg;
                        c.options.plugins.tooltip.titleColor = tc.tooltipTitle;
                        c.options.plugins.tooltip.borderColor = tc.tooltipBorder;
                    }
                    c.update('none');
                });
            }

            try {
                const themeObserver = new MutationObserver(function(mutations) {
                    mutations.forEach(function(m) {
                        if (m.attributeName === 'data-theme') {
                            applyThemeToCharts();
                        }
                    });
                });
                themeObserver.observe(document.documentElement, { attributes: true });
            } catch (e) {}

            const themeBtn = document.getElementById('theme-toggle-btn');
            if (themeBtn) {
                themeBtn.addEventListener('click', function() {
                    setTimeout(applyThemeToCharts, 50);
                });
            }
        }
    });
    </script>
    <?php
    render_footer();
}
