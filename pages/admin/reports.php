<?php
declare(strict_types=1);

function handle_export(string $type, string $format, array $data, ?array $gym = null): void
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
            <title><?= h(ucfirst(str_replace('_', ' ', $type))) ?> Report <?= !empty($gym['name']) ? '- ' . h($gym['name']) : '' ?></title>
            <style>
                @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
                body { font-family: 'Inter', sans-serif; padding: 40px; color: #111827; background: #fff; }
                table { border-collapse: collapse; width: 100%; margin-top: 20px; font-size: 13px; }
                th, td { border: 1px solid #e5e7eb; padding: 12px 16px; text-align: left; }
                th { background: #f9fafb; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.05em; font-size: 12px; }
                h2 { color: #111827; margin-bottom: 5px; font-size: 24px; }
                .report-date { color: #6b7280; font-size: 14px; margin-bottom: 24px; }
                button { background: #111827; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 500; margin-right: 10px; }
                button.secondary { background: #f3f4f6; color: #374151; }
                .receipt-branding-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #e5e7eb; padding-bottom: 16px; margin-bottom: 20px; }
                .receipt-branding-footer { margin-top: 30px; padding-top: 14px; border-top: 1px dashed #cbd5e1; font-size: 11.5px; color: #64748b; text-align: center; }
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

            <?php if (!empty($gym)): ?>
                <div class="receipt-branding-header">
                    <div style="display: flex; align-items: center; gap: 14px;">
                        <?php if (!empty($gym['logo_url'])): ?>
                            <img src="<?= h(upload_url($gym['logo_url'])) ?>" alt="Logo" style="height: 48px; max-width: 120px; object-fit: contain;">
                        <?php endif; ?>
                        <div>
                            <h1 style="margin: 0; font-size: 22px; font-weight: 800; color: #0f172a;"><?= h($gym['name']) ?></h1>
                        </div>
                    </div>
                    <div style="text-align: right; font-size: 12px; color: #64748b;">
                        <div style="font-weight: 700; color: #0f172a;">Official Business Report</div>
                        <div>Generated: <?= h(date('F j, Y g:i A')) ?></div>
                    </div>
                </div>
            <?php endif; ?>

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
    $isStaffRestricted = is_staff_financials_restricted($user);
    
    // AJAX Hybrid Live Search Endpoint for Gym Partners
    if (($_GET['action'] ?? post('action')) === 'search_gyms') {
        if (ob_get_level()) ob_clean();
        header('Content-Type: application/json');

        if (!$isPlatformAdmin) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }

        $q = trim((string)($_GET['q'] ?? post('q') ?? ''));

        // System scope options
        $systemItems = [
            ['id' => 'platform', 'label' => 'FitTrack Platform (SaaS Revenue)'],
            ['id' => 'all', 'label' => 'All Gyms (Global Ecosystem)']
        ];

        $matchedSystem = [];
        if ($q === '') {
            $matchedSystem = $systemItems;
        } else {
            foreach ($systemItems as $sys) {
                if (stripos($sys['label'], $q) !== false) {
                    $matchedSystem[] = $sys;
                }
            }
        }

        // Search approved partner gyms
        $sql = "SELECT gym_id, name, location FROM gyms WHERE status = 'approved'";
        $params = [];
        if ($q !== '') {
            $sql .= " AND (name LIKE ? OR location LIKE ?)";
            $pattern = '%' . $q . '%';
            $params[] = $pattern;
            $params[] = $pattern;
        }
        $sql .= " ORDER BY name ASC LIMIT 100";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $gymRows = $stmt->fetchAll();

        $results = [];
        foreach ($matchedSystem as $sys) {
            $results[] = $sys;
        }

        if (!empty($gymRows)) {
            $headerLabel = ($q !== '') ? 'Matching Gym Facilities (' . count($gymRows) . ')' : 'Partner Gym Facilities';
            $results[] = [
                'id' => '',
                'label' => $headerLabel,
                'isHeader' => true
            ];
            foreach ($gymRows as $g) {
                $results[] = [
                    'id' => (string)$g['gym_id'],
                    'label' => (string)$g['name'],
                    'subtitle' => !empty($g['location']) ? (string)$g['location'] : ''
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'count' => count($gymRows),
            'results' => $results
        ]);
        exit;
    }

    $gymId = null;
    $currentGymName = 'FitTrack Platform';
    $gymBrandColor = null;
    if (!$isPlatformAdmin) {
        $gymRow = $pdo->prepare('SELECT gym_id, name, brand_color FROM gyms WHERE owner_user_id = ?');
        $gymRow->execute([$user['user_id']]);
        $gData = $gymRow->fetch(PDO::FETCH_ASSOC);
        if ($gData) {
            $gymId = (int)$gData['gym_id'];
            $currentGymName = (string)$gData['name'] ?: 'FitTrack Gym';
            $gymBrandColor = !empty($gData['brand_color']) ? (string)$gData['brand_color'] : null;
        }
    }

    $selectedGymId = 'platform';
    $approvedGyms = [];
    if ($isPlatformAdmin) {
        $approvedGyms = query_all("SELECT gym_id, name FROM gyms WHERE status = 'approved' ORDER BY name");
        if (isset($_GET['gym_id'])) {
            $rawScope = trim((string)$_GET['gym_id']);
            if ($rawScope === 'all') {
                $selectedGymId = 'all';
                $currentGymName = 'All Partner Gyms';
            } elseif ($rawScope === 'platform') {
                $selectedGymId = 'platform';
                $currentGymName = 'FitTrack Platform';
            } else {
                $selectedGymId = (int)$rawScope;
                foreach ($approvedGyms as $g) {
                    if ((int)$g['gym_id'] === $selectedGymId) {
                        $currentGymName = $g['name'];
                        break;
                    }
                }
                $gymBrandColor = (string) scalar('SELECT brand_color FROM gyms WHERE gym_id = ?', [$selectedGymId]) ?: null;
            }
        } else {
            $selectedGymId = 'platform';
            $currentGymName = 'FitTrack Platform';
        }
    }

    $isPlatformScope = $isPlatformAdmin && ($selectedGymId === 'platform');
    $isGlobalScope   = $isPlatformAdmin && ($selectedGymId === 'all');
    $isGymSpecific   = !$isPlatformScope && !$isGlobalScope;

    // Prepare Gym Scope items for FitDropdown
    $gymScopeDropdownItems = [];
    if ($isPlatformAdmin) {
        $gymScopeDropdownItems[] = [
            'id' => 'platform',
            'label' => 'FitTrack Platform (SaaS Revenue)'
        ];
        $gymScopeDropdownItems[] = [
            'id' => 'all',
            'label' => 'All Gyms (Global Ecosystem)'
        ];
        if (!empty($approvedGyms)) {
            $gymScopeDropdownItems[] = [
                'id' => '',
                'label' => 'Partner Gym Facilities',
                'isHeader' => true
            ];
            foreach ($approvedGyms as $g) {
                $gymScopeDropdownItems[] = [
                    'id' => (string)$g['gym_id'],
                    'label' => $g['name']
                ];
            }
        }
    }

    // Build SQL condition filters
    if ($isPlatformAdmin) {
        if ($isGymSpecific) {
            $subRevCondition = 'SELECT DATE(p.payment_date) AS day, DATE_FORMAT(p.payment_date, "%Y-%m") AS month, YEAR(p.payment_date) AS year, p.amount AS revenue 
                FROM payments p
                JOIN memberships m ON m.membership_id = p.membership_id
                JOIN membership_plans mp ON mp.plan_id = m.plan_id
                WHERE p.status = "paid" AND mp.gym_id = ' . (int)$selectedGymId;
            $walkRevCondition = 'SELECT DATE(visit_date) AS day, DATE_FORMAT(visit_date, "%Y-%m") AS month, YEAR(visit_date) AS year, amount_paid AS revenue 
                FROM walk_in_transactions WHERE gym_id = ' . (int)$selectedGymId;
            $attendanceCondition = 'SELECT check_in_time, DATE(check_in_time) AS day, DATE_FORMAT(check_in_time, "%Y-%m") AS month, YEAR(check_in_time) AS year FROM attendance WHERE gym_id = ' . (int)$selectedGymId;
            $walkinCondition = 'SELECT DATE(visit_date) AS day, DATE_FORMAT(visit_date, "%Y-%m") AS month, YEAR(visit_date) AS year, amount_paid FROM walk_in_transactions WHERE gym_id = ' . (int)$selectedGymId;
            $members = query_all('SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.email, u.profile_picture,
                COALESCE((SELECT g.name FROM gyms g WHERE g.gym_id = ' . (int)$selectedGymId . '), "FitTrack") AS gym_name
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
            $members = query_all('SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.email, u.profile_picture,
                COALESCE((SELECT g.name FROM gym_members gm JOIN gyms g ON g.gym_id = gm.gym_id WHERE gm.user_id = u.user_id LIMIT 1), "Independent") AS gym_name
                FROM users u WHERE u.role = "member" AND u.status = "active"');
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
        $members = query_all('SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.email, u.profile_picture,
            COALESCE((SELECT g.name FROM gyms g WHERE g.gym_id = ' . (int)$gymId . '), "FitTrack") AS gym_name
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

    // Global SaaS Metrics & Partner Gym Leaderboard
    $platformSaaSRevenueCurMonth = 0.0;
    $platformSaaSRevenuePrevMonth = 0.0;
    $platformSaaSAllTime = 0.0;
    $platformSaaSMomGrowth = null;
    $totalApprovedGymsCount = 0;
    $activeSubscribedGymsCount = 0;
    $arpg = 0.0;
    $gymsLeaderboard = [];
    $saasRawMonthly = [];
    $saasPlanMix = [];
    $saasLedger = [];

    if ($isPlatformAdmin) {
        $saasGymFilter = $isGymSpecific ? 'AND sp.gym_id = ' . (int)$selectedGymId : '';

        $platformSaaSRevenueCurMonth = (float) scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM gym_subscription_payments sp WHERE sp.status = 'paid' AND sp.payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') $saasGymFilter"
        );
        $platformSaaSRevenuePrevMonth = (float) scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM gym_subscription_payments sp WHERE sp.status = 'paid' AND DATE_FORMAT(sp.payment_date, '%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m') $saasGymFilter"
        );
        $platformSaaSAllTime = (float) scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM gym_subscription_payments sp WHERE sp.status = 'paid' $saasGymFilter"
        );
        if ($platformSaaSRevenuePrevMonth > 0) {
            $platformSaaSMomGrowth = round((($platformSaaSRevenueCurMonth - $platformSaaSRevenuePrevMonth) / $platformSaaSRevenuePrevMonth) * 100, 1);
        } elseif ($platformSaaSRevenueCurMonth > 0) {
            $platformSaaSMomGrowth = 100.0;
        }

        $activeSubscribedGymsCount = (int) scalar("SELECT COUNT(DISTINCT sp.gym_id) FROM gym_subscription_payments sp WHERE sp.status = 'paid' AND sp.end_date >= CURDATE() $saasGymFilter");
        if ($activeSubscribedGymsCount === 0 && !$isGymSpecific) {
            $activeSubscribedGymsCount = (int) scalar("SELECT COUNT(*) FROM gyms WHERE status = 'approved' AND subscription_plan IS NOT NULL AND subscription_plan != ''");
        }
        $arpg = $activeSubscribedGymsCount > 0 ? round($platformSaaSRevenueCurMonth / $activeSubscribedGymsCount, 2) : 0.0;

        $totalApprovedGymsCount = (int) scalar("SELECT COUNT(*) FROM gyms WHERE status = 'approved'");

        $saasRawMonthly = query_all("SELECT DATE_FORMAT(sp.payment_date, '%Y-%m') AS month, SUM(sp.amount) AS revenue, COUNT(*) AS transactions FROM gym_subscription_payments sp WHERE sp.status = 'paid' $saasGymFilter GROUP BY month ORDER BY month DESC LIMIT 12");
        $saasPlanMix = query_all("SELECT sp.plan_name, COUNT(*) AS count, COALESCE(SUM(sp.amount), 0) AS revenue FROM gym_subscription_payments sp WHERE sp.status = 'paid' $saasGymFilter GROUP BY sp.plan_name ORDER BY revenue DESC");
        
        $saasLedger = query_all("
            SELECT sp.id, sp.gym_id, sp.owner_user_id, sp.plan_name, sp.amount, sp.billing_cycle, sp.payment_method, sp.status, sp.receipt_number, sp.payment_date, sp.start_date, sp.end_date,
                   g.name AS gym_name, g.logo_url, u.first_name, u.last_name, u.email
            FROM gym_subscription_payments sp
            JOIN gyms g ON g.gym_id = sp.gym_id
            JOIN users u ON u.user_id = sp.owner_user_id
            WHERE sp.status = 'paid' $saasGymFilter
            ORDER BY sp.payment_date DESC LIMIT 50
        ");

        if ($isGlobalScope || $isPlatformScope) {
            $gymsLeaderboard = query_all("
                SELECT g.gym_id, g.name, g.address, g.logo_url, g.brand_color, g.subscription_plan, g.subscription_status,
                       u.first_name AS owner_first_name, u.last_name AS owner_last_name, u.email AS owner_email,
                       (SELECT COUNT(DISTINCT gm.user_id) 
                        FROM gym_members gm 
                        JOIN users mu ON mu.user_id = gm.user_id 
                        WHERE gm.gym_id = g.gym_id AND mu.status = 'active') AS active_members,
                       (SELECT COUNT(*) 
                        FROM attendance a 
                        WHERE a.gym_id = g.gym_id AND DATE_FORMAT(a.check_in_time, '%Y-%m') = ?) AS monthly_checkins,
                       (
                           COALESCE((SELECT SUM(p.amount) 
                                     FROM payments p 
                                     JOIN memberships m ON m.membership_id = p.membership_id 
                                     JOIN membership_plans mp ON mp.plan_id = m.plan_id 
                                     WHERE p.status = 'paid' AND mp.gym_id = g.gym_id AND DATE_FORMAT(p.payment_date, '%Y-%m') = ?), 0)
                           +
                           COALESCE((SELECT SUM(w.amount_paid) 
                                     FROM walk_in_transactions w 
                                     WHERE w.gym_id = g.gym_id AND DATE_FORMAT(w.visit_date, '%Y-%m') = ?), 0)
                       ) AS monthly_gmv
                FROM gyms g
                LEFT JOIN users u ON u.user_id = g.owner_user_id
                WHERE g.status = 'approved'
                ORDER BY monthly_gmv DESC, active_members DESC
            ", [$curMonth, $curMonth, $curMonth]);
        }
    }

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

    // Trainer Performance & Commission Leaderboard (Gym Owner Pro / Business feature only)
    $canViewTrainers = !$isPlatformAdmin && gym_has_feature('trainer_commissions');
    $trainersList = [];
    $trainerStats = ['total_trainers' => 0, 'total_active_clients' => 0, 'total_paid_commissions' => 0.0];
    if ($canViewTrainers) {
        $trainersList = query_all("
            SELECT tp.trainer_id, tp.specialization, tp.bio,
                   u.user_id, u.first_name, u.last_name, u.email, u.profile_picture,
                   COALESCE(g.name, 'Independent') AS gym_name,
                   (SELECT COUNT(*) FROM trainer_assignments ta WHERE ta.trainer_id = tp.trainer_id AND ta.status = 'active') as active_clients,
                   (SELECT COALESCE(SUM(tc.amount), 0) FROM trainer_commissions tc WHERE tc.trainer_id = tp.trainer_id AND tc.status = 'paid') as total_commissions
            FROM trainer_profiles tp
            JOIN users u ON u.user_id = tp.user_id
            LEFT JOIN gyms g ON g.gym_id = tp.gym_id
            $trainerGymFilter
            ORDER BY total_commissions DESC, active_clients DESC
        ");
        $trainerStats['total_trainers'] = count($trainersList);
        foreach ($trainersList as $t) {
            $trainerStats['total_active_clients'] += (int)$t['active_clients'];
            $trainerStats['total_paid_commissions'] += (float)$t['total_commissions'];
        }
    }

    // Engagement Analytics (Gym Owner Pro / Business feature only)
    $categories = ['Highly Engaged' => 0, 'Moderately Engaged' => 0, 'At-Risk' => 0];
    $memberLists = ['Highly Engaged' => [], 'Moderately Engaged' => [], 'At-Risk' => []];
    if (!$isPlatformAdmin) {
        foreach ($members as $m) {
            $score = get_cached_engagement_score((int) $m['user_id']);
            $cat = get_engagement_category($score);
            $categories[$cat]++;
            $m['score'] = $score;
            $memberLists[$cat][] = $m;
        }
        // Put the lowest engagement scores first so the most urgent members are visible in the preview.
        usort($memberLists['At-Risk'], static fn($a, $b) => (int)($a['score'] ?? 0) <=> (int)($b['score'] ?? 0));
    }
    
    $engagementData = [
        ['category' => 'Highly Engaged', 'count' => $categories['Highly Engaged']],
        ['category' => 'Moderately Engaged', 'count' => $categories['Moderately Engaged']],
        ['category' => 'At-Risk', 'count' => $categories['At-Risk']],
    ];

    // Handle AJAX / Form Nudge Action for Inactive / At-Risk Members
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && post('action') === 'send_nudge') {
        verify_csrf();
        $targetUserId = (int) post('user_id');
        $isAll = post('all_at_risk') === '1';
        $customMsg = trim((string) post('custom_message'));

        require_once __DIR__ . '/../../core/engagement_engine.php';

        $sentCount = 0;
        if ($isAll) {
            foreach ($memberLists['At-Risk'] as $m) {
                $uid = (int) $m['user_id'];
                if ($uid > 0) {
                    send_at_risk_notification_job([
                        'user_id' => $uid,
                        'custom_message' => $customMsg,
                    ]);
                    $sentCount++;
                }
            }
            $msg = "Friendly check-in nudges (in-app & email) sent to {$sentCount} members!";
        } elseif ($targetUserId > 0) {
            send_at_risk_notification_job([
                'user_id' => $targetUserId,
                'custom_message' => $customMsg,
            ]);
            $userName = scalar('SELECT first_name FROM users WHERE user_id = ?', [$targetUserId]) ?: 'Member';
            $msg = "Friendly check-in nudge (in-app & email) sent to {$userName}!";
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            echo json_encode(['success' => true, 'message' => $msg ?? 'Nudge sent successfully!']);
            exit;
        }
        flash($msg ?? 'Nudge sent successfully.', 'success');
        redirect('reports');
    }

    // Handle AJAX: End-of-Day (Z-Reading) Settlement Data
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && post('action') === 'get_eod_data') {
        header('Content-Type: application/json');
        verify_csrf();
        try {
            $targetGymId = $isPlatformAdmin ? (int)(post('gym_id') ?: ($selectedGymId !== 'all' ? $selectedGymId : 0)) : (int)$gymId;
            if ($targetGymId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Please select a specific gym to generate EOD summary.']);
                exit;
            }
            $eodData = gym_get_eod_summary($targetGymId);
            $gymRow = $pdo->query('SELECT name, logo_url, brand_color FROM gyms WHERE gym_id = ' . (int)$targetGymId)->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $eodData, 'gym' => $gymRow]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Unable to compute settlement summary: ' . $e->getMessage()]);
        }
        exit;
    }

    // Handle AJAX: Send End-of-Day Email to Owner
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && post('action') === 'send_eod_email') {
        header('Content-Type: application/json');
        verify_csrf();
        try {
            $targetGymId = $isPlatformAdmin ? (int)(post('gym_id') ?: ($selectedGymId !== 'all' ? $selectedGymId : 0)) : (int)$gymId;
            if ($targetGymId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Please select a specific gym.']);
                exit;
            }
            $wStatus = gym_get_eod_window_status($targetGymId);
            if (!$wStatus['is_window_open']) {
                $openTimeStr = $wStatus['window_open_time'] ?? '5:00 PM';
                echo json_encode([
                    'success' => false,
                    'error' => "The End-of-Day settlement email unlocks at {$openTimeStr} when daily shift closing begins."
                ]);
                exit;
            }
            $sent = gym_send_eod_summary_email($targetGymId, null, true, (int)($user['user_id'] ?? 0));
            $msg = $sent
                ? ($isPlatformAdmin ? 'Daily Settlement summary dispatched to gym owner email!' : 'Daily Settlement summary sent to your registered email!')
                : 'Unable to dispatch email. Please check your SMTP settings.';
            echo json_encode(['success' => $sent, 'message' => $msg]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to dispatch email: ' . $e->getMessage()]);
        }
        exit;
    }

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
        
        $gymForPrint = null;
        if (!$isPlatformAdmin && $gymId) {
            $gymForPrint = $pdo->query('SELECT * FROM gyms WHERE gym_id = ' . (int)$gymId)->fetch(PDO::FETCH_ASSOC);
        } elseif ($isPlatformAdmin && $selectedGymId !== 'all') {
            $gymForPrint = $pdo->query('SELECT * FROM gyms WHERE gym_id = ' . (int)$selectedGymId)->fetch(PDO::FETCH_ASSOC);
        }

        if ($type === 'gyms' && $isGlobalScope) {
            $exportRows = [];
            foreach ($gymsLeaderboard as $idx => $g) {
                $exportRows[] = [
                    'Rank' => $idx + 1,
                    'Gym Name' => $g['name'],
                    'Owner' => $g['owner_first_name'] . ' ' . $g['owner_last_name'],
                    'Email' => $g['owner_email'],
                    'Plan Tier' => $g['subscription_plan'] ?: 'Starter',
                    'Active Members' => (int)$g['active_members'],
                    'Monthly Check-Ins' => (int)$g['monthly_checkins'],
                    'Monthly GMV (PHP)' => number_format((float)$g['monthly_gmv'], 2, '.', '')
                ];
            }
            handle_export('gym_network_leaderboard', $format, $exportRows, null);
        }
        if ($type === 'platform_saas' && $isPlatformAdmin) {
            $exportRows = [];
            foreach ($saasLedger as $row) {
                $exportRows[] = [
                    'Receipt #' => $row['receipt_number'] ?: ('SUB-' . $row['id']),
                    'Payment Date' => date('Y-m-d H:i', strtotime($row['payment_date'])),
                    'Gym Name' => $row['gym_name'],
                    'Owner' => $row['first_name'] . ' ' . $row['last_name'],
                    'Owner Email' => $row['email'],
                    'Plan Tier' => $row['plan_name'],
                    'Billing Cycle' => ucfirst((string)$row['billing_cycle']),
                    'Payment Method' => strtoupper((string)$row['payment_method']),
                    'Amount (PHP)' => number_format((float)$row['amount'], 2, '.', ''),
                    'Active Period' => $row['start_date'] . ' to ' . $row['end_date'],
                    'Status' => ucfirst((string)$row['status'])
                ];
            }
            handle_export('platform_saas_revenue', $format, $exportRows, $gymForPrint);
        }
        if ($type === 'engagement' && !$isPlatformAdmin) handle_export($type, $format, $engagementData, $gymForPrint);
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
            handle_export($type . '_' . $timeframe, $format, $exportRows, $gymForPrint);
        }
        if ($type === 'attendance') handle_export($type . '_' . $timeframe, $format, $attendance[$timeframe] ?? $attendance['daily'], $gymForPrint);
        if ($type === 'walkin') handle_export($type . '_' . $timeframe, $format, $walkin[$timeframe] ?? $walkin['daily'], $gymForPrint);
        if ($type === 'trainers' && $canViewTrainers) {
            $exportRows = [];
            foreach ($trainersList as $t) {
                $row = [
                    'Trainer' => $t['first_name'] . ' ' . $t['last_name'],
                    'Email' => $t['email'],
                ];
                if ($isGlobalScope) {
                    $row['Gym Facility'] = $t['gym_name'] ?? 'Independent';
                }
                $row['Specialization'] = $t['specialization'] ?: 'General';
                $row['Active Trainees'] = (int)$t['active_clients'];
                $row['Paid Commissions (PHP)'] = number_format((float)$t['total_commissions'], 2, '.', '');
                $exportRows[] = $row;
            }
            handle_export('trainers_commissions', $format, $exportRows, $gymForPrint);
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

    if ($isPlatformAdmin) {
        $monthStart = (new DateTime('first day of this month'))->modify('-5 months');
        $mMap = [];
        foreach ($saasRawMonthly as $r) {
            $mMap[$r['month']] = (float)$r['revenue'];
        }
        $saasLabels = [];
        $saasValues = [];
        for ($i = 0; $i < 6; $i++) {
            $m = (clone $monthStart)->modify('+' . $i . ' months');
            $k = $m->format('Y-m');
            $saasLabels[] = $m->format('M Y');
            $saasValues[] = $mMap[$k] ?? 0.0;
        }

        $saasPlanLabels = [];
        $saasPlanValues = [];
        foreach ($saasPlanMix as $p) {
            $saasPlanLabels[] = $p['plan_name'];
            $saasPlanValues[] = (float)$p['revenue'];
        }
        if (empty($saasPlanLabels)) {
            $saasPlanLabels = ['Starter', 'Professional', 'Business'];
            $saasPlanValues = [0, 0, 0];
        }

        $chartsData['platform_saas'] = [
            'labels' => $saasLabels,
            'values' => $saasValues,
            'plan_labels' => $saasPlanLabels,
            'plan_values' => $saasPlanValues,
            'total' => array_sum($saasValues)
        ];
    }
    $chartsDataJson = json_encode($chartsData);

    render_header('Reports & Analytics', $user);
    ?>
    <link rel="stylesheet" href="<?= h(asset_url('css/pages/reports.css')) ?>">

    <!-- Header / Banner -->
    <div class="animate-fade-in reports-hero-banner">
        <div>
            <?php if ($isPlatformScope || $isGlobalScope): ?>
            <div class="reports-hero-badges">
                <?php if ($isPlatformScope): ?>
                    <span class="pill-badge lime" style="color: var(--lime); border-color: rgba(132,204,22,0.3);">FitTrack Platform (SaaS Revenue)</span>
                <?php else: ?>
                    <span class="pill-badge blue" style="color: #38bdf8; border-color: rgba(56,189,248,0.3);">All Gyms (Global Ecosystem)</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <h1 class="reports-hero-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path></svg>
                <?php if ($isPlatformScope): ?>
                    Platform Software Subscriptions & SaaS Financials
                <?php elseif ($isGlobalScope): ?>
                    Global Gym Network GMV & Ecosystem Analytics
                <?php else: ?>
                    <?= $isPlatformAdmin ? 'Gym Facility Audit: ' . h($currentGymName) : 'Gym Financials & Attendance Intelligence' ?>
                <?php endif; ?>
            </h1>
            <p class="reports-hero-desc">
                <?php if ($isPlatformScope): ?>
                    Software subscription licensing revenue collected directly from gym owners, tier breakdown, and partner recurring plans.
                <?php elseif ($isGlobalScope): ?>
                    Consolidated multi-stream revenues, walk-in day passes, check-in rush distribution, and member retention benchmarks across all partner gyms.
                <?php else: ?>
                    Multi-stream revenue tracking, peak rush foot-traffic intelligence, and member attendance patterns for <?= h($currentGymName) ?>.
                <?php endif; ?>
            </p>
        </div>
        
        <?php if ($isPlatformAdmin): ?>
            <div class="no-print reports-hero-scope">
                <label for="gymScopeSelect" style="color: var(--muted); font-size: 13px; white-space: nowrap; font-weight: 600;">Gym Scope:</label>
                <div id="gymScopeDropdownContainer" class="reports-gym-scope-dropdown-wrap">
                    <noscript>
                        <select onchange="window.location.href='index.php?page=reports&gym_id='+this.value;" style="background: var(--surface); color: var(--ink); border: 1px solid var(--line); padding: 8px 14px; border-radius: 8px; font-size: 13.5px; min-width: 230px; cursor: pointer; font-weight: 500;">
                            <option value="platform" <?= $selectedGymId === 'platform' ? 'selected' : '' ?>>FitTrack Platform (SaaS Revenue)</option>
                            <option value="all" <?= $selectedGymId === 'all' ? 'selected' : '' ?>>All Gyms (Global Ecosystem)</option>
                            <?php if (!empty($approvedGyms)): ?>
                                <optgroup label="Partner Gym Facilities">
                                    <?php foreach ($approvedGyms as $g): ?>
                                        <option value="<?= $g['gym_id'] ?>" <?= (is_numeric($selectedGymId) && (int)$selectedGymId === (int)$g['gym_id']) ? 'selected' : '' ?>><?= h($g['name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                    </noscript>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($isPlatformAdmin && $isGymSpecific): ?>
        <!-- Platform Admin Audit Mode Inspection Banner -->
        <div class="audit-banner animate-fade-in no-print">
            <div class="audit-banner-content">
                <span class="pill-badge purple" style="font-weight: 800; letter-spacing: 0.05em;">AUDIT MODE</span>
                <div class="audit-banner-text">
                    Currently inspecting facility reports for <strong><?= h($currentGymName) ?></strong>
                </div>
            </div>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <a href="index.php?page=reports&gym_id=platform" class="audit-banner-exit-btn">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                    Platform SaaS View
                </a>
                <a href="index.php?page=reports&gym_id=all" class="audit-banner-exit-btn" style="background: rgba(255,255,255,0.06); border-color: rgba(255,255,255,0.15);">
                    All Gyms View
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Executive Financial KPI Summary Cards (Unified, Non-Arbitrary Glassmorphism) -->
    <div class="kpi-grid">
        <?php if ($isPlatformScope): ?>
            <!-- PLATFORM SAAS SCOPE CARDS -->
            <!-- 1. SaaS Platform Revenue -->
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-title">FitTracks SaaS Revenue</span>
                    <?php if ($platformSaaSRevenueCurMonth > 0 && $platformSaaSMomGrowth !== null): ?>
                        <?php if ($platformSaaSMomGrowth > 0): ?>
                            <span class="pill-badge green">▲ +<?= number_format($platformSaaSMomGrowth, 1) ?>% MoM</span>
                        <?php elseif ($platformSaaSMomGrowth < 0): ?>
                            <span class="pill-badge red">▼ <?= number_format($platformSaaSMomGrowth, 1) ?>% MoM</span>
                        <?php else: ?>
                            <span class="pill-badge neutral">0.0% MoM</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="pill-badge neutral">This Month</span>
                    <?php endif; ?>
                </div>
                <div class="kpi-value">₱<?= number_format($platformSaaSRevenueCurMonth, 2) ?></div>
                <div class="kpi-sub">
                    <?php if ($platformSaaSRevenueCurMonth > 0 && $platformSaaSRevenuePrevMonth > 0): ?>
                        <span>vs. ₱<?= number_format($platformSaaSRevenuePrevMonth, 2) ?> in <?= date('M Y', strtotime('-1 month')) ?></span>
                    <?php else: ?>
                        <span>Monthly platform license fees collected from gym owners</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 2. All-Time SaaS Revenue Collected -->
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-title">All-Time SaaS Collected</span>
                    <span class="pill-badge green">Cumulative</span>
                </div>
                <div class="kpi-value">₱<?= number_format($platformSaaSAllTime, 2) ?></div>
                <div class="kpi-sub">
                    <span>Total historical software subscription licensing revenue</span>
                </div>
            </div>

            <!-- 3. Subscribed Partner Gyms -->
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-title">Subscribed Partner Gyms</span>
                    <span class="pill-badge blue"><?= $activeSubscribedGymsCount ?> / <?= $totalApprovedGymsCount ?> Active</span>
                </div>
                <div class="kpi-value"><?= number_format($activeSubscribedGymsCount) ?> Gyms</div>
                <div class="kpi-sub">
                    <span>Partner fitness facilities on active paid subscription plans</span>
                </div>
            </div>

            <!-- 4. Avg SaaS Yield Per Gym (ARPG) -->
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-title">Avg Revenue Per Gym (ARPG)</span>
                    <span class="pill-badge neutral">Yield</span>
                </div>
                <div class="kpi-value">₱<?= number_format($arpg, 2) ?></div>
                <div class="kpi-sub">
                    <span>Average monthly software billing rate per subscribed gym</span>
                </div>
            </div>

        <?php elseif ($isGlobalScope): ?>
            <!-- GLOBAL GYM NETWORK ECOSYSTEM CARDS (GMV & Operations across all gyms) -->
            <!-- 1. Network GMV Volume -->
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-title">Network GMV Volume</span>
                    <span class="pill-badge blue">All Gyms</span>
                </div>
                <div class="kpi-value">₱<?= number_format($curMonthGross, 2) ?></div>
                <div class="split-bar-wrap" title="Green: Subscriptions, Blue: Walk-Ins">
                    <?php if ($curMonthGross > 0): ?>
                        <div class="split-bar-sub" style="width: <?= max(5, $subSharePct) ?>%;"></div>
                        <div class="split-bar-walk" style="width: <?= max(5, $walkinSharePct) ?>%;"></div>
                    <?php else: ?>
                        <div style="width: 0%;"></div>
                    <?php endif; ?>
                </div>
                <div class="kpi-sub" style="justify-content: space-between;">
                    <span style="color: var(--lime);">Subs: ₱<?= number_format($curMonthSub, 0) ?></span>
                    <span style="color: #38bdf8;">Passes: ₱<?= number_format($curMonthWalk, 0) ?></span>
                </div>
            </div>

            <!-- 2. Active Partner Gyms -->
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-title">Active Partner Gyms</span>
                    <span class="pill-badge green">Network</span>
                </div>
                <div class="kpi-value"><?= $totalApprovedGymsCount ?> Gyms</div>
                <div class="kpi-sub">
                    <span>Verified fitness centers operating on FitTracks</span>
                </div>
            </div>

            <!-- 3. Network Active Members -->
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-title">Network Active Members</span>
                    <span class="pill-badge green"><?= number_format($activeMembersCount) ?> Active</span>
                </div>
                <div class="kpi-value"><?= number_format($activeMembersCount) ?></div>
                <div class="kpi-sub">
                    <span>Athletes across gyms</span>
                </div>
            </div>

            <!-- 4. Network Average Yield (ARPU) -->
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-title">Network Avg Yield (ARPU)</span>
                    <span class="pill-badge neutral">Ecosystem</span>
                </div>
                <div class="kpi-value">₱<?= number_format($arpu, 2) ?></div>
                <div class="kpi-sub">
                    <span>Consolidated monthly yield across active members</span>
                </div>
            </div>
        <?php else: ?>
            <!-- GYM SPECIFIC OPERATIONAL CARDS (Gym Owner or Platform Admin Inspecting a Gym) -->
            <!-- 1. Gross Revenue -->
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-title">Gross Revenue (This Month)</span>
                    <?php if ($isStaffRestricted): ?>
                        <span class="pill-badge red">🔒 Restricted</span>
                    <?php elseif ($curMonthGross > 0 && $momGrowth !== null): ?>
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
                <?php if ($isStaffRestricted): ?>
                    <div class="kpi-value" style="color: var(--muted); letter-spacing: 2px;">••••••</div>
                    <div class="kpi-sub"><span>Staff Privacy active</span></div>
                <?php else: ?>
                    <div class="kpi-value">₱<?= number_format($curMonthGross, 2) ?></div>
                    <div class="kpi-sub">
                        <?php if ($curMonthGross > 0 && $prevMonthGross > 0): ?>
                            <span>vs. ₱<?= number_format($prevMonthGross, 2) ?> in <?= date('M Y', strtotime('-1 month')) ?></span>
                        <?php else: ?>
                            <span>No transactions logged for <?= date('M Y') ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- 2. MRR (Active Subscriptions) -->
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-title">Monthly Recurring (MRR)</span>
                    <?php if ($isStaffRestricted): ?>
                        <span class="pill-badge red">🔒 Restricted</span>
                    <?php elseif ($mrr > 0): ?>
                        <span class="pill-badge green">Contracted</span>
                    <?php else: ?>
                        <span class="pill-badge neutral">0 plans</span>
                    <?php endif; ?>
                </div>
                <?php if ($isStaffRestricted): ?>
                    <div class="kpi-value" style="color: var(--muted); letter-spacing: 2px;">••••••</div>
                    <div class="kpi-sub"><span>Staff Privacy active</span></div>
                <?php else: ?>
                    <div class="kpi-value">₱<?= number_format($mrr, 2) ?></div>
                    <div class="kpi-sub">
                        <?php if ($mrr > 0): ?>
                            <span>Contracted active plan subscription value</span>
                        <?php else: ?>
                            <span>Activate plans to build recurring subscription revenue</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
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
                        <span style="color: var(--lime);">Subs: ₱<?= number_format($curMonthSub, 0) ?></span>
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
        <?php endif; ?>
    </div>

    <!-- Navigation Tabs with URL Hash Support -->
    <div class="report-tabs no-print" role="tablist">
        <?php if ($isPlatformScope): ?>
            <button class="report-tab-btn active" data-tab="platform-saas-tab" onclick="showTab('platform-saas-tab')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"></path><path d="M2 17l10 5 10-5"></path><path d="M2 12l10 5 10-5"></path></svg>
                Platform SaaS Revenue
            </button>
            <button class="report-tab-btn" data-tab="gyms-tab" onclick="showTab('gyms-tab')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"></path><path d="M5 21V7l8-4v18"></path><path d="M19 21V11l-6-3"></path><path d="M9 9v.01"></path><path d="M9 12v.01"></path><path d="M9 15v.01"></path><path d="M9 18v.01"></path></svg>
                Gym Network Leaderboard
            </button>
        <?php elseif ($isGlobalScope): ?>
            <button class="report-tab-btn active" data-tab="revenue-tab" onclick="showTab('revenue-tab')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 19v-14h3.5a4.5 4.5 0 1 1 0 9h-3.5"></path><path d="M6 8h12"></path><path d="M6 11h12"></path></svg>
                Gym Network Revenue
            </button>
            <button class="report-tab-btn" data-tab="gyms-tab" onclick="showTab('gyms-tab')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"></path><path d="M5 21V7l8-4v18"></path><path d="M19 21V11l-6-3"></path><path d="M9 9v.01"></path><path d="M9 12v.01"></path><path d="M9 15v.01"></path><path d="M9 18v.01"></path></svg>
                Gym Network Leaderboard
            </button>
            <button class="report-tab-btn" data-tab="attendance-tab" onclick="showTab('attendance-tab')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                Attendance & Peak Rush
            </button>
            <button class="report-tab-btn" data-tab="walkin-tab" onclick="showTab('walkin-tab')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline></svg>
                Walk-In Traffic
            </button>
        <?php else: ?>
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
            <?php if (!$isPlatformAdmin): ?>
            <button class="report-tab-btn" data-tab="trainers-tab" onclick="showTab('trainers-tab')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                Trainers & Commissions <?= !gym_has_feature('trainer_commissions') ? '<span style="font-size:10px; color:#f87171; font-weight:700;">🔒 Pro</span>' : '' ?>
            </button>
            <button class="report-tab-btn" data-tab="engagement-tab" onclick="showTab('engagement-tab')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                Member Engagement <?= !gym_has_feature('engagement_tracking') ? '<span style="font-size:10px; color:#f87171; font-weight:700;">🔒 Pro</span>' : '' ?>
            </button>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Mobile Tab Dropdown Selector (Visible on Mobile Only, using FitDropdown) -->
    <div class="report-tabs-mobile-wrap no-print">
        <label class="report-tabs-mobile-label">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line></svg>
            <span>Report Category</span>
        </label>
        <div id="mobileReportTabDropdown"></div>
    </div>

    <?php if ($isPlatformScope): ?>
    <!-- ==================== TAB 0: PLATFORM SAAS REVENUE ==================== -->
    <div id="platform-saas-tab" class="tab-content animate-fade-in" style="display:block;">
        <div class="panel report-panel" style="border-radius: 16px; padding: 26px; margin-bottom: 24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 22px; flex-wrap: wrap; gap: 14px;">
                <div>
                    <h2 style="margin:0 0 4px 0; font-size: 20px;">FitTrack Platform Subscription Revenue</h2>
                    <p style="margin:0; color:var(--muted); font-size:13.5px;">
                        Recurring SaaS software licenses collected directly from gym owners across the platform.
                    </p>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <a href="index.php?page=reports&type=platform_saas<?= $isGymSpecific ? '&gym_id=' . (int)$selectedGymId : '' ?>&export=csv" class="export-btn" style="background: var(--surface); color: var(--ink); border-color: var(--line);">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        Export CSV
                    </a>
                    <a href="index.php?page=reports&type=platform_saas<?= $isGymSpecific ? '&gym_id=' . (int)$selectedGymId : '' ?>&export=print" target="_blank" class="export-btn" style="background: var(--surface); color: var(--ink); border-color: var(--line);">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                        Print / PDF
                    </a>
                </div>
            </div>

            <!-- SaaS Charts Grid -->
            <div class="dash-grid" style="grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 24px;" id="platform-saas-charts-grid">
                <!-- SaaS Revenue Trend -->
                <div class="report-card-box" style="padding: 20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 16px;">
                        <div>
                            <h3 style="margin:0 0 2px 0; font-size: 15px; font-weight: 800;">Platform SaaS Revenue Trend</h3>
                            <p style="margin:0; font-size: 12px; color:var(--muted);">Monthly subscription fees collected from gym owners (last 6 months)</p>
                        </div>
                    </div>
                    <div class="chart-canvas" style="height: 280px; position: relative;">
                        <canvas id="platformSaaSChart"></canvas>
                    </div>
                </div>

                <!-- Plan Tier Mix -->
                <div class="report-card-box" style="padding: 20px;">
                    <h3 style="margin:0 0 2px 0; font-size: 15px; font-weight: 800;">SaaS Tier Distribution</h3>
                    <p style="margin:0 0 16px 0; font-size: 12px; color:var(--muted);">Revenue share by subscription tier</p>
                    <div class="chart-canvas" style="height: 200px; position: relative;">
                        <canvas id="platformSaaSMixChart"></canvas>
                    </div>
                    <div style="margin-top: 14px; display: flex; flex-direction: column; gap: 6px; font-size: 12px;">
                        <?php if (empty($saasPlanMix)): ?>
                            <div style="color: var(--muted); padding: 8px 0; text-align: center;">No tier revenue recorded.</div>
                        <?php else: ?>
                            <?php foreach ($saasPlanMix as $idx => $p): 
                                $badgeColor = match(strtolower((string)$p['plan_name'])) {
                                    'starter' => '#38bdf8',
                                    'professional', 'pro' => '#a855f7',
                                    'business', 'enterprise' => '#f59e0b',
                                    default => '#84cc16'
                                };
                            ?>
                                <div style="display:flex; justify-content:space-between; align-items:center; padding: 4px 0; border-bottom: 1px solid var(--line);">
                                    <span style="display:inline-flex; align-items:center; gap: 6px;">
                                        <span style="width: 8px; height: 8px; border-radius: 50%; background: <?= $badgeColor ?>;"></span>
                                        <strong><?= h($p['plan_name']) ?></strong> (<?= (int)$p['count'] ?>)
                                    </span>
                                    <span style="font-weight: 700; color: var(--ink);">₱<?= number_format((float)$p['revenue'], 2) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- SaaS Payment Transactions Ledger Table -->
            <div style="margin-top: 28px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <h3 style="margin: 0 0 2px 0; font-size: 16px; font-weight: 800;">Gym Subscription Payments Ledger</h3>
                        <p style="margin: 0; font-size: 12.5px; color: var(--muted);">Direct audit trail of software plan collections from gym owners.</p>
                    </div>
                    <span class="pill-badge neutral" style="font-size: 11px; font-weight: 700;">
                        <?= count($saasLedger) ?> logged transactions
                    </span>
                </div>

                <!-- Desktop Ledger Table (>= 768px) -->
                <div class="ledger-desktop-table" style="overflow-x: auto; border: 1px solid var(--line); border-radius: 10px; background: var(--surface);">
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--line); background: rgba(255,255,255,0.02);">
                                <th style="padding: 12px 14px; font-weight: 700; color: var(--muted); font-size: 11.5px; text-transform: uppercase;">Receipt / Ref</th>
                                <th style="padding: 12px 14px; font-weight: 700; color: var(--muted); font-size: 11.5px; text-transform: uppercase;">Gym & Owner</th>
                                <th style="padding: 12px 14px; font-weight: 700; color: var(--muted); font-size: 11.5px; text-transform: uppercase;">SaaS Plan Tier</th>
                                <th style="padding: 12px 14px; font-weight: 700; color: var(--muted); font-size: 11.5px; text-transform: uppercase;">Cycle</th>
                                <th style="padding: 12px 14px; font-weight: 700; color: var(--muted); font-size: 11.5px; text-transform: uppercase; text-align: right;">Amount</th>
                                <th style="padding: 12px 14px; font-weight: 700; color: var(--muted); font-size: 11.5px; text-transform: uppercase;">Method</th>
                                <th style="padding: 12px 14px; font-weight: 700; color: var(--muted); font-size: 11.5px; text-transform: uppercase;">Payment Date</th>
                                <th style="padding: 12px 14px; font-weight: 700; color: var(--muted); font-size: 11.5px; text-transform: uppercase; text-align: center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($saasLedger)): ?>
                                <tr>
                                    <td colspan="8" style="padding: 32px; text-align: center; color: var(--muted);">
                                        No subscription payments recorded for this scope.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($saasLedger as $tx): 
                                    $planBadgeColor = match(strtolower((string)$tx['plan_name'])) {
                                        'starter' => 'background: rgba(56, 189, 248, 0.12); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3);',
                                        'professional', 'pro' => 'background: rgba(168, 85, 247, 0.12); color: #a855f7; border: 1px solid rgba(168, 85, 247, 0.3);',
                                        'business', 'enterprise' => 'background: rgba(245, 158, 11, 0.12); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3);',
                                        default => 'background: rgba(132, 204, 22, 0.12); color: var(--lime); border: 1px solid rgba(132, 204, 22, 0.3);'
                                    };
                                ?>
                                    <tr style="border-bottom: 1px solid var(--line);">
                                        <td style="padding: 12px 14px; font-family: monospace; font-size: 12px; color: var(--muted);">
                                            <?= h($tx['receipt_number'] ?: ('SUB-' . $tx['id'])) ?>
                                        </td>
                                        <td style="padding: 12px 14px;">
                                            <div style="font-weight: 700; color: var(--ink);"><?= h($tx['gym_name']) ?></div>
                                            <div style="font-size: 11.5px; color: var(--muted);">
                                                <?= h($tx['first_name'] . ' ' . $tx['last_name']) ?> &bull; <?= h($tx['email']) ?>
                                            </div>
                                        </td>
                                        <td style="padding: 12px 14px;">
                                            <span style="display:inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 800; <?= $planBadgeColor ?>">
                                                <?= h($tx['plan_name']) ?>
                                            </span>
                                        </td>
                                        <td style="padding: 12px 14px; color: var(--muted); text-transform: capitalize;">
                                            <?= h($tx['billing_cycle']) ?>
                                        </td>
                                        <td style="padding: 12px 14px; text-align: right; font-weight: 800; color: var(--lime);">
                                            ₱<?= number_format((float)$tx['amount'], 2) ?>
                                        </td>
                                        <td style="padding: 12px 14px; color: var(--muted); text-transform: uppercase; font-size: 11.5px; font-weight: 700;">
                                            <?= h($tx['payment_method']) ?>
                                        </td>
                                        <td style="padding: 12px 14px; color: var(--muted); font-size: 12px;">
                                            <div><?= date('M j, Y g:i A', strtotime($tx['payment_date'])) ?></div>
                                            <div style="font-size: 11px; color: var(--muted); opacity: 0.8;">Covered until <?= date('M j, Y', strtotime($tx['end_date'])) ?></div>
                                        </td>
                                        <td style="padding: 12px 14px; text-align: center;">
                                            <span class="pill-badge green" style="font-size: 11px; font-weight: 800;">
                                                Paid
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Ledger Card List (< 768px: Zero Horizontal Scroll) -->
                <div class="ledger-mobile-cards">
                    <?php if (empty($saasLedger)): ?>
                        <div style="padding: 28px; text-align: center; color: var(--muted); background: var(--surface); border: 1px solid var(--line); border-radius: 12px; font-size: 13px;">
                            No subscription payments recorded for this scope.
                        </div>
                    <?php else: ?>
                        <?php foreach ($saasLedger as $tx): 
                            $planBadgeColor = match(strtolower((string)$tx['plan_name'])) {
                                'starter' => 'background: rgba(56, 189, 248, 0.12); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3);',
                                'professional', 'pro' => 'background: rgba(168, 85, 247, 0.12); color: #a855f7; border: 1px solid rgba(168, 85, 247, 0.3);',
                                'business', 'enterprise' => 'background: rgba(245, 158, 11, 0.12); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3);',
                                default => 'background: rgba(132, 204, 22, 0.12); color: var(--lime); border: 1px solid rgba(132, 204, 22, 0.3);'
                            };
                        ?>
                            <div class="ledger-mobile-card">
                                <div class="ledger-card-header">
                                    <div style="min-width: 0;">
                                        <div class="ledger-card-gym"><?= h($tx['gym_name']) ?></div>
                                        <div class="ledger-card-owner">
                                            <?= h($tx['first_name'] . ' ' . $tx['last_name']) ?> &bull; <?= h($tx['email']) ?>
                                        </div>
                                    </div>
                                    <div style="text-align: right; flex-shrink: 0;">
                                        <div class="ledger-card-amount">₱<?= number_format((float)$tx['amount'], 2) ?></div>
                                        <span class="pill-badge" style="<?= $planBadgeColor ?> font-size: 10.5px; padding: 2px 7px; font-weight: 800;">
                                            <?= h($tx['plan_name']) ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="ledger-card-body">
                                    <div class="ledger-detail-item">
                                        <span class="ledger-detail-label">Receipt / Ref</span>
                                        <span class="ledger-detail-val" style="font-family: monospace; font-size: 11.5px;"><?= h($tx['receipt_number'] ?: ('SUB-' . $tx['id'])) ?></span>
                                    </div>
                                    <div class="ledger-detail-item">
                                        <span class="ledger-detail-label">Billing Cycle</span>
                                        <span class="ledger-detail-val" style="text-transform: capitalize;"><?= h($tx['billing_cycle']) ?></span>
                                    </div>
                                    <div class="ledger-detail-item">
                                        <span class="ledger-detail-label">Payment Method</span>
                                        <span class="ledger-detail-val" style="text-transform: uppercase; font-weight: 700;"><?= h($tx['payment_method']) ?></span>
                                    </div>
                                    <div class="ledger-detail-item">
                                        <span class="ledger-detail-label">Status</span>
                                        <span class="ledger-detail-val"><span class="pill-badge green" style="font-size: 10.5px; padding: 2px 7px;">Paid</span></span>
                                    </div>
                                    <div class="ledger-detail-item">
                                        <span class="ledger-detail-label">Payment Date</span>
                                        <span class="ledger-detail-val"><?= date('M j, Y g:i A', strtotime($tx['payment_date'])) ?></span>
                                    </div>
                                    <div class="ledger-detail-item">
                                        <span class="ledger-detail-label">Coverage Period</span>
                                        <span class="ledger-detail-val" style="color: var(--muted); font-size: 11.5px;">Covered until <?= date('M j, Y', strtotime($tx['end_date'])) ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$isPlatformScope): ?>
    <!-- ==================== TAB 1: REVENUE & FINANCIALS ==================== -->
    <div id="revenue-tab" class="tab-content animate-fade-in">
        <div class="panel report-panel" style="border-radius: 16px; padding: 26px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 22px; flex-wrap: wrap; gap: 14px;">
                <div>
                    <h2 style="margin:0 0 4px 0; font-size: 20px;">Multi-Stream Revenue Breakdown</h2>
                    <p style="margin:0; color:var(--muted); font-size:13.5px;">Stacked comparison of recurring membership subscriptions vs. one-time walk-in passes.</p>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <?php if ($isGymSpecific): ?>
                        <button type="button" onclick="openEODModal()" class="export-btn" style="background: linear-gradient(135deg, #262a31, #171b22); color: #e2e8f0; border-color: rgba(148, 163, 184, 0.28); font-weight: 700; cursor: pointer;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            Daily Settlement (Z-Reading)
                        </button>
                    <?php endif; ?>
                    <a href="index.php?page=reports&type=revenue&timeframe=monthly&export=csv" id="btn-export-revenue-csv" class="export-btn" style="background: linear-gradient(135deg, #262a31, #171b22); color: #e2e8f0; border-color: rgba(148, 163, 184, 0.28);">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        Export CSV
                    </a>
                    <a href="index.php?page=reports&type=revenue&timeframe=monthly&export=print" id="btn-export-revenue-print" target="_blank" class="export-btn" style="background: linear-gradient(135deg, #262a31, #171b22); color: #e2e8f0; border-color: rgba(148, 163, 184, 0.28);">
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
                                <span style="width: 10px; height: 10px; border-radius: 3px; background: var(--lime); flex-shrink: 0;"></span> Subscriptions
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

                <!-- Revenue Mix Donut -->
                <div>
                    <!-- Donut Card with Empty State Placeholder -->
                    <div class="report-card-box">
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
                            <div class="mix-legend-row mix-legend-sub" style="display: flex; justify-content: space-between; align-items: center; padding: 7px 12px; background: color-mix(in srgb, var(--lime) 12%, transparent); border-radius: 6px;">
                                <span class="mix-legend-label" style="color: var(--lime); font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                                    <span class="mix-legend-dot" style="width:8px; height:8px; border-radius:50%; background: var(--lime);"></span> Subscriptions
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
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isGlobalScope || $isPlatformScope): ?>
    <!-- ==================== TAB: GYM NETWORK LEADERBOARD ==================== -->
    <div id="gyms-tab" class="tab-content animate-fade-in" style="display: none;">
        <div class="panel report-panel" style="border-radius: 16px; padding: 26px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 22px; flex-wrap: wrap; gap: 14px;">
                <div>
                    <h2 style="margin:0 0 4px 0; font-size: 20px;">Partner Gym Network Leaderboard</h2>
                    <p style="margin:0; color:var(--muted); font-size:13.5px;">Comparative performance rankings, operational foot traffic, and gross transacted revenue across all partner gyms.</p>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <a href="index.php?page=reports&type=gyms&export=csv" class="export-btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        Export CSV
                    </a>
                    <a href="index.php?page=reports&type=gyms&export=print" target="_blank" class="export-btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                        Print / PDF
                    </a>
                </div>
            </div>

            <!-- Quick Metrics Overview -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
                <div style="background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 18px;">
                    <span style="color: var(--muted); font-size: 12px; font-weight: 600; text-transform: uppercase;">Partner Gyms</span>
                    <div style="font-size: 26px; font-weight: 800; color: var(--ink); margin-top: 4px;"><?= count($gymsLeaderboard) ?></div>
                </div>
                <div style="background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 18px;">
                    <span style="color: var(--muted); font-size: 12px; font-weight: 600; text-transform: uppercase;">Network Enrolled Members</span>
                    <div style="font-size: 26px; font-weight: 800; color: #84cc16; margin-top: 4px;">
                        <?= number_format(array_sum(array_column($gymsLeaderboard, 'active_members'))) ?>
                    </div>
                </div>
                <div style="background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 18px;">
                    <span style="color: var(--muted); font-size: 12px; font-weight: 600; text-transform: uppercase;">Network Monthly Check-Ins</span>
                    <div style="font-size: 26px; font-weight: 800; color: #38bdf8; margin-top: 4px;">
                        <?= number_format(array_sum(array_column($gymsLeaderboard, 'monthly_checkins'))) ?>
                    </div>
                </div>
                <div style="background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 18px;">
                    <span style="color: var(--muted); font-size: 12px; font-weight: 600; text-transform: uppercase;">Total Transacted GMV (<?= date('M Y') ?>)</span>
                    <div style="font-size: 26px; font-weight: 800; color: #fbbf24; margin-top: 4px;">
                        ₱<?= number_format(array_sum(array_column($gymsLeaderboard, 'monthly_gmv')), 2) ?>
                    </div>
                </div>
            </div>

            <!-- Leaderboard Table (Desktop) -->
            <div class="trainers-desktop-table table-wrap" style="border: 1px solid var(--line); border-radius: 12px; overflow-x: auto; -webkit-overflow-scrolling: touch; background: var(--surface);">
                <table class="trainer-leaderboard-table">
                    <thead>
                        <tr class="table-head-row" style="border-bottom: 1px solid var(--line);">
                            <th style="padding: 12px 18px; text-align: left; font-size: 12px; min-width: 230px;">Rank & Gym Facility</th>
                            <th style="padding: 12px 18px; text-align: left; font-size: 12px; min-width: 170px;">Owner</th>
                            <th style="padding: 12px 18px; text-align: center; font-size: 12px; min-width: 120px;">Plan Tier</th>
                            <th style="padding: 12px 18px; text-align: center; font-size: 12px; min-width: 120px;">Active Members</th>
                            <th style="padding: 12px 18px; text-align: center; font-size: 12px; min-width: 120px;">Monthly Check-Ins</th>
                            <th style="padding: 12px 18px; text-align: right; font-size: 12px; min-width: 160px;">Monthly GMV</th>
                            <th style="padding: 12px 18px; text-align: center; font-size: 12px; min-width: 130px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($gymsLeaderboard)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 36px 20px; color: var(--muted);">
                                    <div style="font-weight: 600; color: var(--ink); margin-bottom: 4px;">No Partner Gyms Found</div>
                                    <span style="font-size: 12.5px;">Approved gyms will populate the comparative platform network leaderboard.</span>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($gymsLeaderboard as $idx => $g): ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.03); transition: background 0.15s;">
                                    <td style="padding: 14px 18px;">
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <span style="font-weight: 800; font-size: 14px; width: 22px; color: <?= $idx === 0 ? '#fbbf24' : ($idx === 1 ? '#94a3b8' : ($idx === 2 ? '#b45309' : 'var(--muted)')) ?>;">
                                                #<?= $idx + 1 ?>
                                            </span>
                                            <?php if (!empty($g['logo_url'])): ?>
                                                <img src="<?= h(upload_url($g['logo_url'])) ?>" alt="Logo" style="width: 36px; height: 36px; border-radius: 8px; object-fit: cover; border: 1px solid var(--line);">
                                            <?php else: ?>
                                                <div style="width: 36px; height: 36px; border-radius: 8px; background: rgba(132, 204, 22, 0.15); color: var(--lime); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; border: 1px solid rgba(132, 204, 22, 0.3);">
                                                    <?= strtoupper(substr($g['name'] ?? 'G', 0, 1)) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <strong style="color: var(--ink); font-size: 14px; display: block;"><?= h($g['name']) ?></strong>
                                                <span style="color: var(--muted); font-size: 12px;"><?= h($g['address'] ?: 'No address specified') ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="padding: 14px 18px;">
                                        <div style="font-size: 13px; font-weight: 600; color: var(--ink);">
                                            <?= h($g['owner_first_name'] . ' ' . $g['owner_last_name']) ?>
                                        </div>
                                        <div style="font-size: 12px; color: var(--muted);">
                                            <?= h($g['owner_email']) ?>
                                        </div>
                                    </td>
                                    <td style="padding: 14px 18px; text-align: center;">
                                        <span class="pill-badge <?= strtolower($g['subscription_plan'] ?? '') === 'professional' ? 'purple' : 'neutral' ?>" style="font-size: 11px;">
                                            <?= h($g['subscription_plan'] ?: 'Starter') ?>
                                        </span>
                                    </td>
                                    <td style="padding: 14px 18px; text-align: center;">
                                        <span class="pill-badge green" style="font-size: 12px; padding: 4px 10px;">
                                            <?= (int)$g['active_members'] ?> Active
                                        </span>
                                    </td>
                                    <td style="padding: 14px 18px; text-align: center; font-weight: 600; color: var(--ink); font-size: 13px;">
                                        <?= (int)$g['monthly_checkins'] ?>
                                    </td>
                                    <td style="padding: 14px 18px; text-align: right; font-weight: 700; color: #84cc16; font-size: 14.5px;">
                                        ₱<?= number_format((float)$g['monthly_gmv'], 2) ?>
                                    </td>
                                    <td style="padding: 14px 18px; text-align: center;">
                                        <a href="index.php?page=reports&gym_id=<?= (int)$g['gym_id'] ?>" class="btn" style="padding: 6px 14px; font-size: 12px; background: rgba(132, 204, 22, 0.12); border: 1px solid rgba(132, 204, 22, 0.3); color: var(--lime); border-radius: 6px; text-decoration: none; font-weight: 600; white-space: nowrap; display: inline-flex; align-items: center; gap: 4px;">
                                            Inspect Reports &rarr;
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Leaderboard Mobile Cards (< 768px: Zero Horizontal Scroll) -->
            <div class="trainers-mobile-cards">
                <?php if (empty($gymsLeaderboard)): ?>
                    <div class="trainer-card-empty">
                        <div style="font-weight: 600; color: var(--ink); margin-bottom: 4px;">No Partner Gyms Found</div>
                        <span style="font-size: 12.5px; color: var(--muted);">Approved partner gyms will appear here.</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($gymsLeaderboard as $idx => $g): ?>
                        <div class="trainer-mobile-card">
                            <div class="trainer-card-header">
                                <div class="trainer-card-profile">
                                    <span class="trainer-card-rank" style="color: <?= $idx === 0 ? '#fbbf24' : ($idx === 1 ? '#94a3b8' : ($idx === 2 ? '#b45309' : 'var(--muted)')) ?>;">
                                        #<?= $idx + 1 ?>
                                    </span>
                                    <?php if (!empty($g['logo_url'])): ?>
                                        <img src="<?= h(upload_url($g['logo_url'])) ?>" alt="Logo" style="width: 36px; height: 36px; border-radius: 8px; object-fit: cover; border: 1px solid var(--line);">
                                    <?php else: ?>
                                        <div style="width: 36px; height: 36px; border-radius: 8px; background: rgba(132, 204, 22, 0.15); color: var(--lime); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; border: 1px solid rgba(132, 204, 22, 0.3);">
                                            <?= strtoupper(substr($g['name'] ?? 'G', 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="trainer-card-identity">
                                        <strong class="trainer-card-name"><?= h($g['name']) ?></strong>
                                        <span class="trainer-card-email"><?= h($g['owner_first_name'] . ' ' . $g['owner_last_name']) ?></span>
                                    </div>
                                </div>
                                <span class="pill-badge <?= strtolower($g['subscription_plan'] ?? '') === 'professional' ? 'purple' : 'neutral' ?>" style="font-size: 11px; padding: 3px 9px; font-weight: 700; flex-shrink: 0;">
                                    <?= h($g['subscription_plan'] ?: 'Starter') ?>
                                </span>
                            </div>

                            <div class="trainer-card-details">
                                <div class="trainer-card-detail-item">
                                    <span class="detail-label">Active Members</span>
                                    <span class="detail-val" style="color: #84cc16; font-weight: 700;"><?= (int)$g['active_members'] ?></span>
                                </div>
                                <div class="trainer-card-detail-item">
                                    <span class="detail-label">Monthly Check-Ins</span>
                                    <span class="detail-val"><?= (int)$g['monthly_checkins'] ?></span>
                                </div>
                                <div class="trainer-card-detail-item" style="grid-column: span 2;">
                                    <span class="detail-label">Monthly Transacted GMV</span>
                                    <span class="detail-val commission-amount">₱<?= number_format((float)$g['monthly_gmv'], 2) ?></span>
                                </div>
                            </div>

                            <div class="trainer-card-footer">
                                <a href="index.php?page=reports&gym_id=<?= (int)$g['gym_id'] ?>" class="btn-trainer-details" style="background: rgba(132, 204, 22, 0.12); color: var(--lime); border-color: rgba(132, 204, 22, 0.3);">
                                    <span>Inspect Gym Reports</span>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$isPlatformScope): ?>
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
                            <span class="pill-badge green" style="flex-shrink: 0;"> Peak: <?= date('g A', strtotime("$peakHour:00")) ?></span>
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
                            <span class="pill-badge purple" style="flex-shrink: 0;"> Busiest: <?= h($busiestDayName) ?></span>
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
    <?php endif; ?>

    <?php if (!$isPlatformAdmin): ?>
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
                                <?php if ($isGlobalScope): ?>
                                    <th style="padding: 12px 18px; text-align: left; font-size: 12px; min-width: 150px;">Gym Facility</th>
                                <?php endif; ?>
                                <th style="padding: 12px 18px; text-align: left; font-size: 12px; min-width: 170px;">Specialization</th>
                                <th style="padding: 12px 18px; text-align: center; font-size: 12px; min-width: 120px;">Active Clients</th>
                                <th style="padding: 12px 18px; text-align: right; font-size: 12px; min-width: 160px;">Total Paid Commissions</th>
                                <th style="padding: 12px 18px; text-align: center; font-size: 12px; min-width: 110px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($trainersList)): ?>
                                <tr>
                                    <td colspan="<?= $isGlobalScope ? 6 : 5 ?>" style="text-align: center; padding: 36px 20px; color: var(--muted);">
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
                                        <?php if ($isGlobalScope): ?>
                                            <td style="padding: 14px 18px;">
                                                <span class="pill-badge neutral" style="font-size: 11px;">
                                                    <?= h($t['gym_name'] ?: 'Independent') ?>
                                                </span>
                                            </td>
                                        <?php endif; ?>
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
                                    <?php if ($isGlobalScope): ?>
                                        <div class="trainer-card-detail-item" style="grid-column: span 2;">
                                            <span class="detail-label">Gym Facility</span>
                                            <span class="detail-val" style="color: var(--lime); font-weight: 600;"><?= h($t['gym_name'] ?: 'Independent') ?></span>
                                        </div>
                                    <?php endif; ?>
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
                    <h2 style="margin:0 0 4px 0; font-size: 20px;">Member Engagement & Inactivity Alerts</h2>
                    <p style="margin:0; color:var(--muted); font-size:13.5px;">Rule-based scores summarize recent member activity; inactivity alerts help staff follow up.</p>
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
                        Upgrade to the <strong>Professional Plan (₱999/mo)</strong> to unlock rule-based member engagement scores, inactivity alerts, and attendance consistency tracking.
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
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <?php if ($catName === 'At-Risk' && $count > 0): ?>
                                        <button type="button" onclick="sendNudgeAll(<?= $count ?>)" class="btn-sm" style="font-size: 11.5px; padding: 3px 9px; background: rgba(245, 158, 11, 0.12); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.28); border-radius: 6px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;" title="Send an encouraging check-in reminder to all at-risk members">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                                            Nudge All
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($catName === 'At-Risk' && $count > 4): ?>
                                        <button type="button" id="atRiskToggle" aria-expanded="false" aria-controls="atRiskMemberList" onclick="toggleAtRiskMembers()" class="btn-sm" style="font-size: 11.5px; padding: 4px 9px; background: transparent; color: var(--muted); border: 1px solid var(--line); border-radius: 6px; font-weight: 600; cursor: pointer;">
                                            View all (<?= $count ?>)
                                        </button>
                                    <?php endif; ?>
                                    <span style="background: rgba(255,255,255,0.05); padding: 3px 10px; border-radius: 999px; font-size: 12px; color: var(--muted); font-weight: bold; flex-shrink: 0;">
                                        <?= $count ?> Members
                                    </span>
                                </div>
                            </div>
                            
                            <?php if ($count > 0): ?>
                                <div class="engagement-member-list" <?= $catName === 'At-Risk' ? 'id="atRiskMemberList"' : '' ?>>
                                    <?php foreach ($memberLists[$catName] as $memberIndex => $m): ?>
                                        <div class="engagement-member-item" <?= $catName === 'At-Risk' && $memberIndex >= 4 ? 'data-at-risk-extra hidden' : '' ?>>
                                            <div class="engagement-member-profile">
                                                <?= render_avatar($m) ?>
                                                <div class="engagement-member-info">
                                                    <strong class="engagement-member-name"><?= h($m['first_name'] . ' ' . $m['last_name']) ?></strong>
                                                    <span class="engagement-member-email">
                                                        <?= h($m['email']) ?>
                                                        <?php if ($isGlobalScope && !empty($m['gym_name'])): ?>
                                                            • <span style="color: var(--lime); font-weight: 600;"><?= h($m['gym_name']) ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="engagement-member-meta">
                                                <span class="pill-badge <?= $catName === 'Highly Engaged' ? 'green' : ($catName === 'Moderately Engaged' ? 'blue' : 'red') ?>" style="font-size: 11px; padding: 3px 9px; font-weight: 700;">
                                                    Score: <?= (int)($m['score'] ?? 0) ?>
                                                </span>
                                                <?php if ($catName === 'At-Risk'): ?>
                                                    <button type="button" onclick="sendMemberNudge(<?= (int)$m['user_id'] ?>, '<?= h(addslashes($m['first_name'] . ' ' . $m['last_name'])) ?>')" class="btn-sm" style="font-size: 11.5px; padding: 4px 9px; background: rgba(245, 158, 11, 0.12); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.25); border-radius: 6px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;" title="Send an encouraging check-in reminder">
                                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                                                        Nudge
                                                    </button>
                                                <?php endif; ?>
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
    <?php endif; ?>

    <script>
    function toggleAtRiskMembers() {
        const list = document.getElementById('atRiskMemberList');
        const button = document.getElementById('atRiskToggle');
        if (!list || !button) return;
        const expanded = button.getAttribute('aria-expanded') === 'true';
        list.querySelectorAll('[data-at-risk-extra]').forEach(row => { row.hidden = expanded; });
        button.setAttribute('aria-expanded', String(!expanded));
        button.textContent = expanded ? 'View all (<?= (int)$categories['At-Risk'] ?>)' : 'Show less';
    }
    </script>

    <!-- Chart.js & Tab Controller Logic -->
    <script>
    window.REPORTS_CONFIG = {
        csrfToken: <?= json_encode(csrf_token()) ?>,
        isPlatformAdmin: <?= json_encode($isPlatformAdmin) ?>,
        userRole: <?= json_encode($user['role'] ?? '') ?>,
        userEmail: <?= json_encode($user['email'] ?? '') ?>,
        selectedGymId: <?= ($isPlatformAdmin && $selectedGymId !== 'all' && $selectedGymId !== 'platform') ? (int)$selectedGymId : 'null' ?>,
        gymScopeId: <?= json_encode((string)$selectedGymId) ?>,
        gymScopeItems: <?= json_encode($gymScopeDropdownItems ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        brandColor: <?= json_encode($gymBrandColor) ?>,
        chartsData: <?= $chartsDataJson ?>,
        engagement: {
            labels: <?= $engagementLabels ?>,
            data: <?= $engagementJson ?>
        },
        tabs: {
            trainersLabel: <?= json_encode('Trainers & Commissions' . ((!$isPlatformAdmin && !gym_has_feature('trainer_commissions')) ? ' (🔒 Pro)' : '')) ?>,
            engagementLabel: <?= json_encode('Member Engagement' . ((!$isPlatformAdmin && !gym_has_feature('engagement_tracking')) ? ' (🔒 Pro)' : '')) ?>
        }
    };
    </script>
    <script src="<?= h(asset_url('js/pages/reports.js')) ?>"></script>
    <?php
    render_footer();
}
